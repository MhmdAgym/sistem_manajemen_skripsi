/**
 * B-CHAT E2EE Protocol Layer — KeyPackage Generation & Publication Orchestrator (I-02B)
 *
 * Mengorkestrasi pembangkitan batch MLS KeyPackage oleh Dedicated Worker,
 * persistensi terenkripsi durabel di IndexedDB, pengunggahan ke backend Laravel,
 * serta pembaruan status siklus hidup publikasi secara atomik dan aman.
 *
 * Invarian Keamanan:
 * 1. Seluruh operasi pembangkitan kunci privat dieksekusi di dalam Dedicated Web Worker.
 * 2. PrivateKeyPackage TIDAK PERNAH terekspos ke Main Thread dalam bentuk plaintext.
 * 3. Persistensi durabel di IndexedDB (PENDING_KEY_PACKAGES) WAJIB sukses sebelum KeyPackage publik dikembalikan atau diunggah.
 * 4. Status kriptografis (pending / consumed) sepenuhnya terpisah dari status publikasi (local_only, uploading, published, unknown, rejected).
 * 5. Status kriptografis 'consumed' HANYA dapat diubah melalui mekanisme handshake JOIN_WELCOME.
 * 6. Tidak ada retry otomatis pada kondisi timeout, respons ambigu, atau HTTP 409 (status ditetapkan sebagai 'unknown').
 * 7. Tidak ada penghapusan PrivateKeyPackage lokal apabila unggahan gagal.
 * 8. Zero Math.random() dan zero sensitive logging.
 */

import {
  uploadKeyPackages,
  BchatApiError,
} from '@/lib/bchat/api/bchat-api-client';
import type {
  BchatKeyPackageUploadRequest,
  BchatKeyPackageUploadResponseData,
} from '@/lib/bchat/api/bchat-api-types';
import type { BchatWorkerClient } from '@/lib/bchat/bchat-poc-rpc';
import type {
  GenerateKeyPackagePayload,
  GenerateKeyPackageResult,
  FencingRpcInput,
} from '@/lib/bchat/bchat-poc-types';
import {
  updateKeyPackagePublicationStatus,
  type FencingCredentials,
  type KeyPackagePublicationStatus,
} from '@/lib/bchat/storage/bchat-mls-persistence';

// -----------------------------------------------------------------------------
// KONSTANTA & BATASAN KONTRAK
// -----------------------------------------------------------------------------

export const MIN_KEYPACKAGE_BATCH_SIZE = 1;
export const MAX_KEYPACKAGE_BATCH_SIZE = 10;
export const CANONICAL_UUID_REGEX = /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/;
export const SHA256_HEX_REGEX = /^[0-9a-f]{64}$/;
export const DECIMAL_STRING_REGEX = /^(0|[1-9][0-9]*)$/;

// -----------------------------------------------------------------------------
// KELAS ERROR DOMAIN
// -----------------------------------------------------------------------------

export class BchatKeyPackageServiceError extends Error {
  readonly isBchatKeyPackageServiceError = true;
  readonly errorCode: string;
  readonly originalError?: unknown;
  readonly partialPackages?: GeneratedKeyPackageItem[];
  readonly secondaryError?: unknown;

  constructor(
    message: string,
    errorCode: string,
    originalError?: unknown,
    partialPackages?: GeneratedKeyPackageItem[],
    secondaryError?: unknown
  ) {
    super(message);
    this.name = 'BchatKeyPackageServiceError';
    this.errorCode = errorCode;
    this.originalError = originalError;
    this.partialPackages = partialPackages;
    this.secondaryError = secondaryError;
    Object.setPrototypeOf(this, BchatKeyPackageServiceError.prototype);
  }
}

// -----------------------------------------------------------------------------
// STRUKTUR DATA & HASIL
// -----------------------------------------------------------------------------

export interface GeneratedKeyPackageItem {
  keyPackageHash: string;
  keyPackageWireBase64: string;
}

export interface PublishKeyPackagesOptions {
  fencing: FencingRpcInput;
  deviceUuid?: string;
  timeoutMs?: number;
}

export interface PublishKeyPackagesResult {
  deviceUuid: string;
  uploadedCount: number;
  activePoolCount: number;
  remainingActivePackages: number;
  packages: GeneratedKeyPackageItem[];
  publishedAt: number;
}

// -----------------------------------------------------------------------------
// FUNGSI BANTU VALIDASI
// -----------------------------------------------------------------------------

export function validateCanonicalUuid(uuid: string, fieldName = 'deviceUuid'): string {
  if (typeof uuid !== 'string') {
    throw new BchatKeyPackageServiceError(
      `Parameter ${fieldName} harus berupa string.`,
      'INVALID_DEVICE_UUID'
    );
  }
  const clean = uuid.trim().toLowerCase();
  if (!CANONICAL_UUID_REGEX.test(clean)) {
    throw new BchatKeyPackageServiceError(
      `Format ${fieldName} [${uuid}] tidak valid. Wajib berupa UUID v4 kanonis lowercase.`,
      'INVALID_DEVICE_UUID'
    );
  }
  return clean;
}

function parseSafeErrorMessage(err: unknown): string {
  if (err instanceof BchatApiError) {
    return `[${err.errorCode || 'API_ERROR'}] ${err.message}`;
  }
  if (err instanceof Error) {
    return err.message;
  }
  return 'Unknown error';
}

// -----------------------------------------------------------------------------
// FUNGSI UTAMA ORKESTRASI: publishKeyPackagesFlow
// -----------------------------------------------------------------------------

/**
 * Mengorkestrasikan pembangkitan batch MLS KeyPackage dan mempublikasikannya ke backend:
 * 1. Validasi deviceUuid kanonis dan rentang batchSize (1..10).
 * 2. Validasi kredensial fencing eksplisit pemanggil (wajib disediakan; tanpa fallback disk).
 * 3. Membangkitkan KeyPackage secara sekuensial melalui Worker RPC (setiap PrivateKeyPackage
 *    disimpan durabel terenkripsi di IndexedDB dengan status publikasi 'local_only' sebelum dikembalikan).
 * 4. Menandai status publikasi batch menjadi 'uploading' di IndexedDB.
 * 5. Mengunggah batch paket publik ke Laravel (POST /api/bchat/key-packages).
 * 6. Memverifikasi seluruh field konfirmasi respons backend secara ketat.
 * 7. Memperbarui status publikasi batch menjadi 'published' di IndexedDB.
 * 8. Mengisolasi kegagalan jaringan: timeout (HTTP 408), HTTP 409, dan HTTP 5xx ditetapkan sebagai 'unknown'
 *    tanpa retry otomatis dan tanpa menghapus PrivateKeyPackage lokal.
 */
export async function publishKeyPackagesFlow(
  workerRpc: BchatWorkerClient,
  deviceUuid: string,
  batchSize: number = 5,
  options?: PublishKeyPackagesOptions
): Promise<PublishKeyPackagesResult> {
  // 1. Validasi input Device UUID target
  const canonicalDeviceUuid = validateCanonicalUuid(deviceUuid, 'deviceUuid');

  // 2. Validasi rentang batchSize (integer 1..10)
  if (!Number.isInteger(batchSize) || batchSize < MIN_KEYPACKAGE_BATCH_SIZE || batchSize > MAX_KEYPACKAGE_BATCH_SIZE) {
    throw new BchatKeyPackageServiceError(
      `Jumlah batch KeyPackage harus berupa integer antara ${MIN_KEYPACKAGE_BATCH_SIZE} dan ${MAX_KEYPACKAGE_BATCH_SIZE} (diterima: ${batchSize}).`,
      'INVALID_BATCH_SIZE'
    );
  }

  // 3. Validasi worker RPC client
  if (!workerRpc || typeof workerRpc.sendRequest !== 'function') {
    throw new BchatKeyPackageServiceError(
      'Worker RPC client tidak valid atau belum diinisialisasi.',
      'INVALID_WORKER_CLIENT'
    );
  }

  // 4. Resolusi & Validasi fencing credentials wajib (H-I02B.1)
  // Wajib diberikan secara eksplisit oleh pemanggil; fallback ke getActiveTabLease() DITIADAKAN.
  if (!options || typeof options !== 'object' || !options.fencing) {
    throw new BchatKeyPackageServiceError(
      'Fencing credentials (options.fencing) wajib disertakan secara eksplisit oleh pemanggil.',
      'FENCING_REQUIRED'
    );
  }

  // Validasi kesesuaian Device UUID jika options juga menyertakan deviceUuid
  if (options.deviceUuid !== undefined) {
    const optionsDeviceUuid = validateCanonicalUuid(options.deviceUuid, 'options.deviceUuid');
    if (optionsDeviceUuid !== canonicalDeviceUuid) {
      throw new BchatKeyPackageServiceError(
        `Device UUID pada options [${options.deviceUuid}] tidak cocok dengan target [${canonicalDeviceUuid}].`,
        'DEVICE_UUID_MISMATCH'
      );
    }
  }

  const fencingAny = options.fencing as { ownerToken?: unknown; generation?: unknown; deviceUuid?: unknown };
  if (fencingAny.deviceUuid !== undefined && typeof fencingAny.deviceUuid === 'string') {
    const fencingDeviceUuid = validateCanonicalUuid(fencingAny.deviceUuid, 'options.fencing.deviceUuid');
    if (fencingDeviceUuid !== canonicalDeviceUuid) {
      throw new BchatKeyPackageServiceError(
        `Device UUID pada options.fencing [${fencingAny.deviceUuid}] tidak cocok dengan target [${canonicalDeviceUuid}].`,
        'DEVICE_UUID_MISMATCH'
      );
    }
  }

  const rawOwnerToken = options.fencing.ownerToken;
  if (typeof rawOwnerToken !== 'string' || rawOwnerToken.trim().length === 0) {
    throw new BchatKeyPackageServiceError(
      'Fencing ownerToken pada options tidak valid atau kosong.',
      'INVALID_FENCING_CREDENTIALS'
    );
  }

  const rawGeneration = options.fencing.generation;
  if (typeof rawGeneration !== 'string' || !DECIMAL_STRING_REGEX.test(rawGeneration)) {
    throw new BchatKeyPackageServiceError(
      `Fencing generation pada options [${String(rawGeneration)}] tidak valid. Wajib berupa decimal string kanonis tanpa spasi, leading zero, atau format nonkanonis lainnya.`,
      'INVALID_FENCING_CREDENTIALS'
    );
  }

  try {
    BigInt(rawGeneration);
  } catch (bigIntErr) {
    throw new BchatKeyPackageServiceError(
      `Fencing generation [${rawGeneration}] tidak dapat diproses menggunakan BigInt.`,
      'INVALID_FENCING_CREDENTIALS',
      bigIntErr
    );
  }

  const fencing: FencingCredentials = {
    ownerToken: rawOwnerToken.trim(),
    generation: rawGeneration,
  };

  // 5. Pembangkitan paket sekuensial via Worker RPC (SATU PER SATU, BUKAN PARALEL)
  const generatedPackages: GeneratedKeyPackageItem[] = [];
  const seenHashes = new Set<string>();
  const timeoutMs = options?.timeoutMs ?? 15000;

  for (let i = 0; i < batchSize; i++) {
    let genResult: GenerateKeyPackageResult;
    try {
      genResult = await workerRpc.sendRequest<GenerateKeyPackagePayload, GenerateKeyPackageResult>(
        'GENERATE_KEY_PACKAGE',
        {
          deviceUuid: canonicalDeviceUuid,
          fencing: {
            ownerToken: fencing.ownerToken,
            generation: fencing.generation,
          },
        },
        timeoutMs
      );
    } catch (genErr) {
      // INVARIAN: Jika generation gagal di tengah jalan, paket yang telah tersimpan
      // di IndexedDB tetap dipertahankan durabel (status 'local_only'). TIDAK DIHAPUS.
      throw new BchatKeyPackageServiceError(
        `Gagal membangkitkan KeyPackage ke-${i + 1} dari ${batchSize}: ${(genErr as Error).message}`,
        'GENERATION_FAILED',
        genErr,
        generatedPackages
      );
    }

    if (!genResult || typeof genResult !== 'object') {
      throw new BchatKeyPackageServiceError(
        `Hasil pembangkitan KeyPackage ke-${i + 1} dari Worker tidak valid.`,
        'INVALID_WORKER_RESPONSE',
        undefined,
        generatedPackages
      );
    }

    // Validasi format hash SHA-256 (64 hex lowercase)
    const hash = (genResult.keyPackageHash ?? '').trim().toLowerCase();
    if (!SHA256_HEX_REGEX.test(hash)) {
      throw new BchatKeyPackageServiceError(
        `KeyPackage hash ke-${i + 1} dari Worker [${genResult.keyPackageHash}] tidak valid (wajib 64 karakter lowercase hex).`,
        'INVALID_GENERATED_HASH',
        undefined,
        generatedPackages
      );
    }

    // Deteksi duplikasi in-batch
    if (seenHashes.has(hash)) {
      throw new BchatKeyPackageServiceError(
        `Duplikasi hash KeyPackage terdeteksi dalam batch generation: ${hash}`,
        'DUPLICATE_KEY_PACKAGE_IN_BATCH',
        undefined,
        generatedPackages
      );
    }
    seenHashes.add(hash);

    // Validasi data Base64
    const base64Data = (genResult.keyPackageWireBase64 ?? '').trim();
    if (!base64Data || base64Data.length === 0) {
      throw new BchatKeyPackageServiceError(
        `Data wire Base64 KeyPackage ke-${i + 1} dari Worker kosong.`,
        'EMPTY_GENERATED_BASE64',
        undefined,
        generatedPackages
      );
    }

    generatedPackages.push({
      keyPackageHash: hash,
      keyPackageWireBase64: base64Data,
    });
  }

  const batchHashes = generatedPackages.map((p) => p.keyPackageHash);

  // 6. Tandai metadata publikasi seluruh paket menjadi 'uploading'
  try {
    await updateKeyPackagePublicationStatus({
      deviceUuid: canonicalDeviceUuid,
      keyPackageHashes: batchHashes,
      publicationStatus: 'uploading',
      fencing,
    });
  } catch (persistErr) {
    throw new BchatKeyPackageServiceError(
      `Gagal menandai status publikasi KeyPackage menjadi 'uploading' di IndexedDB: ${(persistErr as Error).message}`,
      'PERSISTENCE_UPDATE_FAILED',
      persistErr,
      generatedPackages
    );
  }

  // 7. Pengunggahan ke Backend Laravel via API Client
  const uploadPayload: BchatKeyPackageUploadRequest = {
    device_uuid: canonicalDeviceUuid,
    packages: generatedPackages.map((pkg) => ({
      key_package_hash: pkg.keyPackageHash,
      key_package_data: pkg.keyPackageWireBase64,
    })),
  };

  let uploadResponse;
  try {
    uploadResponse = await uploadKeyPackages(uploadPayload);
  } catch (uploadErr) {
    // -------------------------------------------------------------------------
    // 8. PENANGANAN KEGAGALAN JARINGAN & SERVER TERSTRUKTUR
    // -------------------------------------------------------------------------

    // A. HTTP 409 (Duplikasi / Hash sudah ada di backend):
    // Ditetapkan sebagai 'unknown' karena state server tidak dapat dipastikan tanpa rekonsiliasi.
    if (uploadErr instanceof BchatApiError && uploadErr.status === 409) {
      try {
        await updateKeyPackagePublicationStatus({
          deviceUuid: canonicalDeviceUuid,
          keyPackageHashes: batchHashes,
          publicationStatus: 'unknown',
          fencing,
          errorMessage: `HTTP 409 Conflict: ${uploadErr.message}`,
        });
      } catch (persistErr) {
        throw new BchatKeyPackageServiceError(
          `Unggahan KeyPackage ditolak dengan HTTP 409 (Konflik/Duplikat: ${uploadErr.message}). Hasil penyimpanan backend tidak dapat dipastikan (unknown) dan pembaruan status publikasi lokal di IndexedDB gagal: ${persistErr instanceof Error ? persistErr.message : 'Unknown persistence error'}.`,
          'PERSISTENCE_UNKNOWN_UPDATE_FAILED',
          uploadErr,
          generatedPackages,
          persistErr
        );
      }

      throw new BchatKeyPackageServiceError(
        `Unggahan KeyPackage ditolak dengan HTTP 409 (Konflik/Duplikat): ${uploadErr.message}. Status publikasi lokal ditetapkan sebagai unknown.`,
        'UPLOAD_CONFLICT_409',
        uploadErr,
        generatedPackages
      );
    }

    // B. HTTP 408 (Request Timeout):
    // Server atau gateway mengalami batas waktu pemrosesan request; state penyimpanan backend ambigu.
    // Wajib ditetapkan sebagai 'unknown' (BUKAN 'rejected'), DILARANG retry otomatis, kunci lokal tetap utuh.
    if (uploadErr instanceof BchatApiError && uploadErr.status === 408) {
      try {
        await updateKeyPackagePublicationStatus({
          deviceUuid: canonicalDeviceUuid,
          keyPackageHashes: batchHashes,
          publicationStatus: 'unknown',
          fencing,
          errorMessage: `HTTP 408 Request Timeout: ${uploadErr.message}`,
        });
      } catch (persistErr) {
        throw new BchatKeyPackageServiceError(
          `Unggahan KeyPackage mengalami HTTP 408 Request Timeout (${uploadErr.message}). Hasil penyimpanan backend tidak dapat dipastikan (unknown) dan pembaruan status publikasi lokal di IndexedDB gagal: ${persistErr instanceof Error ? persistErr.message : 'Unknown persistence error'}.`,
          'PERSISTENCE_UNKNOWN_UPDATE_FAILED',
          uploadErr,
          generatedPackages,
          persistErr
        );
      }

      throw new BchatKeyPackageServiceError(
        `Unggahan KeyPackage mengalami HTTP 408 Request Timeout: ${uploadErr.message}. Status publikasi lokal ditetapkan sebagai unknown.`,
        'UPLOAD_NETWORK_TIMEOUT_UNKNOWN',
        uploadErr,
        generatedPackages
      );
    }

    // C. Penolakan definitif HTTP 4xx (mis. 400, 401, 403, 422, 429) yang memastikan request ditolak SEBELUM penyimpanan
    // (mengecualikan 408 Request Timeout dan 409 Conflict):
    if (
      uploadErr instanceof BchatApiError &&
      uploadErr.status &&
      uploadErr.status >= 400 &&
      uploadErr.status < 500 &&
      uploadErr.status !== 409 &&
      uploadErr.status !== 408
    ) {
      try {
        await updateKeyPackagePublicationStatus({
          deviceUuid: canonicalDeviceUuid,
          keyPackageHashes: batchHashes,
          publicationStatus: 'rejected',
          fencing,
          errorMessage: parseSafeErrorMessage(uploadErr),
        });
      } catch (persistErr) {
        throw new BchatKeyPackageServiceError(
          `Unggahan KeyPackage ditolak secara definitif oleh server (HTTP ${uploadErr.status}: ${uploadErr.message}), namun pembaruan status publikasi lokal di IndexedDB gagal: ${persistErr instanceof Error ? persistErr.message : 'Unknown persistence error'}.`,
          'PERSISTENCE_REJECTED_UPDATE_FAILED',
          uploadErr,
          generatedPackages,
          persistErr
        );
      }

      throw new BchatKeyPackageServiceError(
        `Unggahan KeyPackage ditolak secara definitif oleh server (HTTP ${uploadErr.status}): ${uploadErr.message}. Status publikasi lokal ditetapkan sebagai rejected.`,
        'UPLOAD_REJECTED_4XX',
        uploadErr,
        generatedPackages
      );
    }

    // D. Timeout jaringan, koneksi terputus, HTTP 5xx, atau respons ambigu/tidak terklasifikasi pasti:
    // Status publikasi WAJIB 'unknown'. DILARANG retry otomatis. PrivateKeyPackage tetap utuh.
    const safeErrorMsg = uploadErr instanceof Error ? uploadErr.message : 'Network/Server Error';
    try {
      await updateKeyPackagePublicationStatus({
        deviceUuid: canonicalDeviceUuid,
        keyPackageHashes: batchHashes,
        publicationStatus: 'unknown',
        fencing,
        errorMessage: safeErrorMsg,
      });
    } catch (persistErr) {
      throw new BchatKeyPackageServiceError(
        `Unggahan KeyPackage mengalami kegagalan jaringan atau server (${safeErrorMsg}). Hasil penyimpanan backend tidak dapat dipastikan (unknown) dan pembaruan status publikasi lokal di IndexedDB gagal: ${persistErr instanceof Error ? persistErr.message : 'Unknown persistence error'}.`,
        'PERSISTENCE_UNKNOWN_UPDATE_FAILED',
        uploadErr,
        generatedPackages,
        persistErr
      );
    }

    throw new BchatKeyPackageServiceError(
      `Unggahan KeyPackage mengalami kegagalan jaringan atau server tidak dapat dipastikan: ${safeErrorMsg}. Status publikasi lokal ditetapkan sebagai unknown.`,
      'UPLOAD_NETWORK_TIMEOUT_UNKNOWN',
      uploadErr,
      generatedPackages
    );
  }

  // ---------------------------------------------------------------------------
  // 9. VALIDASI RESPONS BACKEND
  // ---------------------------------------------------------------------------
  if (!uploadResponse || !uploadResponse.data) {
    await markBatchUnknownSafe(canonicalDeviceUuid, batchHashes, fencing, 'Respons backend kosong');
    throw new BchatKeyPackageServiceError(
      'Respons backend upload KeyPackage kosong atau tidak valid.',
      'INVALID_SERVER_RESPONSE',
      undefined,
      generatedPackages
    );
  }

  const resData: BchatKeyPackageUploadResponseData = uploadResponse.data;

  // Verifikasi Device UUID
  if ((resData.device_uuid ?? '').toLowerCase().trim() !== canonicalDeviceUuid) {
    await markBatchUnknownSafe(canonicalDeviceUuid, batchHashes, fencing, 'Device UUID respons backend tidak cocok');
    throw new BchatKeyPackageServiceError(
      `Device UUID pada konfirmasi backend [${resData.device_uuid}] tidak cocok dengan target [${canonicalDeviceUuid}].`,
      'RESPONSE_DEVICE_MISMATCH',
      undefined,
      generatedPackages
    );
  }

  // Verifikasi uploaded_count
  if (resData.uploaded_count !== generatedPackages.length) {
    await markBatchUnknownSafe(
      canonicalDeviceUuid,
      batchHashes,
      fencing,
      `Mismatch uploaded_count: dikirim ${generatedPackages.length}, konfirmasi ${resData.uploaded_count}`
    );
    throw new BchatKeyPackageServiceError(
      `Jumlah paket yang dikonfirmasi server (${resData.uploaded_count}) tidak sesuai dengan yang dikirim (${generatedPackages.length}).`,
      'RESPONSE_COUNT_MISMATCH',
      undefined,
      generatedPackages
    );
  }

  // Verifikasi field active_pool_count
  if (typeof resData.active_pool_count !== 'number' || resData.active_pool_count < resData.uploaded_count) {
    await markBatchUnknownSafe(canonicalDeviceUuid, batchHashes, fencing, 'Field active_pool_count tidak valid');
    throw new BchatKeyPackageServiceError(
      'Field active_pool_count pada respons backend tidak valid.',
      'RESPONSE_POOL_COUNT_INVALID',
      undefined,
      generatedPackages
    );
  }

  // ---------------------------------------------------------------------------
  // 10. PEMBARUAN STATUS PUBLIKASI MENJADI 'published'
  // ---------------------------------------------------------------------------
  const publishedAt = Date.now();
  try {
    await updateKeyPackagePublicationStatus({
      deviceUuid: canonicalDeviceUuid,
      keyPackageHashes: batchHashes,
      publicationStatus: 'published',
      timestamp: publishedAt,
      activePoolCount: resData.active_pool_count,
      fencing,
    });
  } catch (persistErr) {
    // Data di backend sudah tersimpan sukses (201 Created), namun pembaruan status lokal IDB gagal
    throw new BchatKeyPackageServiceError(
      'KeyPackage berhasil diunggah ke backend, namun pembaruan status lokal di IndexedDB mengalami kegagalan.',
      'PERSISTENCE_PUBLISHED_UPDATE_FAILED',
      persistErr,
      generatedPackages
    );
  }

  return {
    deviceUuid: canonicalDeviceUuid,
    uploadedCount: resData.uploaded_count,
    activePoolCount: resData.active_pool_count,
    remainingActivePackages: resData.remaining_active_packages ?? resData.active_pool_count,
    packages: generatedPackages,
    publishedAt,
  };
}

/**
 * Helper internal untuk menandai status batch sebagai 'unknown' secara aman tanpa menginterupsi error utama.
 */
async function markBatchUnknownSafe(
  deviceUuid: string,
  hashes: string[],
  fencing: FencingCredentials,
  reason: string
): Promise<void> {
  try {
    await updateKeyPackagePublicationStatus({
      deviceUuid,
      keyPackageHashes: hashes,
      publicationStatus: 'unknown',
      fencing,
      errorMessage: reason,
    });
  } catch {
    // Abaikan kegagalan sekunder agar error validasi utama tetap dilemparkan
  }
}

