/**
 * B-CHAT E2EE — Crypto Storage Helper & KEK Lifecycle Engine
 *
 * Mengelola enkripsi at-rest berbasis WebCrypto AES-256-GCM untuk penyimpanan lokal:
 * - Pembangkitan, rotasi, dan pemulihan Key Encryption Key (KEK) non-extractable 256-bit.
 * - Enkripsi payload biner ke format amplop biner terotentikasi (BchatEncryptedEnvelope).
 * - Penegakan AAD biner deterministik anti-transplantation (H-B-01).
 * - Penanganan IV acak unik (zero nonce reuse) dan tag autentikasi 128-bit.
 * - Pencegahan race-condition inisialisasi/rotasi KEK menggunakan double-checked locking dalam transaksi IDB.
 */

import {
  BCAT_STORES,
  type KeystoreRecord,
} from './bchat-idb-schema';
import {
  withReadonlyTransaction,
  withReadwriteTransaction,
  idbGet,
  BchatStorageError,
} from './bchat-idb-core';
import {
  buildStorageAad,
  normalizeCanonicalUuid,
  DEFAULT_STORAGE_SCHEMA_VERSION,
  DEFAULT_SERIALIZER_VERSION,
  type BchatAadContext,
} from './bchat-storage-aad';
import {
  validateStorageEnvelope,
  GCM_IV_LENGTH_BYTES,
  GCM_TAG_LENGTH_BYTES,
  type BchatEncryptedEnvelope,
} from './bchat-storage-envelope';

// =============================================================================
// Hierarki Error Kriptografi Terstruktur
// =============================================================================

export class BchatKekUnavailableError extends BchatStorageError {
  constructor(message: string, originalError?: unknown) {
    super(message, 'KEK_UNAVAILABLE', originalError);
    this.name = 'BchatKekUnavailableError';
  }
}

export class BchatStorageDecryptionError extends BchatStorageError {
  constructor(message: string, originalError?: unknown) {
    super(message, 'STORAGE_DECRYPTION_ERROR', originalError);
    this.name = 'BchatStorageDecryptionError';
  }
}

// =============================================================================
// Utilitas Helper WebCrypto
// =============================================================================

function getSubtleCrypto(): SubtleCrypto {
  if (typeof window !== 'undefined' && window.crypto?.subtle) {
    return window.crypto.subtle;
  }
  if (typeof self !== 'undefined' && self.crypto?.subtle) {
    return self.crypto.subtle;
  }
  throw new BchatStorageError(
    'W3C WebCrypto API (crypto.subtle) tidak tersedia pada runtime peramban saat ini.',
    'WEBCRYPTO_UNAVAILABLE'
  );
}

function getRandomBytes(length: number): Uint8Array {
  const buf = new Uint8Array(length);
  if (typeof window !== 'undefined' && window.crypto) {
    return window.crypto.getRandomValues(buf);
  }
  if (typeof self !== 'undefined' && self.crypto) {
    return self.crypto.getRandomValues(buf);
  }
  throw new BchatStorageError('Sumber entropi CSPRNG (crypto.getRandomValues) tidak tersedia.', 'RNG_UNAVAILABLE');
}

/**
 * Mengonversi Uint8Array menjadi concrete ArrayBuffer murni tanpa type assertion.
 * Menjamin kompatibilitas dengan WebCrypto BufferSource dan tidak memutasi byte sumber.
 */
function toArrayBuffer(bytes: Uint8Array): ArrayBuffer {
  const copy = new Uint8Array(bytes.byteLength);
  copy.set(bytes);
  return copy.buffer;
}

/**
 * Membangkitkan ID KEK yang tahan benturan (collision-resistant)
 */
function generateKekId(deviceUuid: string, version: number): string {
  const randomHex = Array.from(getRandomBytes(6))
    .map((b) => b.toString(16).padStart(2, '0'))
    .join('');
  return `kek_${deviceUuid}_v${version}_${Date.now()}_${randomHex}`;
}

// =============================================================================
// Manajemen Lifecycle KEK (Key Encryption Key)
// =============================================================================

/**
 * Mencari KEK aktif per device dari daftar record keystore
 */
function selectActiveKekForDevice(records: KeystoreRecord[], deviceUuid: string): KeystoreRecord | undefined {
  const activeRecords = records.filter(
    (r) =>
      r.status === 'active' &&
      r.algorithm === 'AES-GCM-256' &&
      (r.metadata?.device_uuid as string) === deviceUuid
  );

  if (activeRecords.length === 0) return undefined;
  if (activeRecords.length === 1) return activeRecords[0];

  // Jika terdapat anomali lebih dari 1 active KEK, pilih versi tertinggi
  activeRecords.sort((a, b) => b.key_version - a.key_version || b.created_at - a.created_at);
  return activeRecords[0];
}

/**
 * Mengambil KEK aktif yang sudah ada atau membangkitkan KEK baru secara aman dari race condition.
 *
 * Protokol Konkurensi:
 * 1. Periksa ketersediaan KEK aktif di luar transaksi.
 * 2. Jika belum ada, bangkitkan kandidat KEK (WebCrypto generateKey) di LUAR transaksi IndexedDB.
 * 3. Buka transaksi readwrite, periksa ulang apakah tab lain telah menyimpan KEK aktif (double-checked locking).
 * 4. Jika tab lain mendahului, gunakan KEK yang sudah ada dan batalkan kandidat.
 * 5. Jika tetap belum ada, simpan kandidat secara atomik.
 */
export async function getOrCreateActiveKek(deviceUuid: string): Promise<KeystoreRecord> {
  const canonicalDevice = normalizeCanonicalUuid(deviceUuid, 'deviceUuid');

  // 1. Pemeriksaan awal di luar transaksi
  const existingActive = await withReadonlyTransaction([BCAT_STORES.KEYSTORE], (_tx, getStore) => {
    return new Promise<KeystoreRecord | undefined>((resolve, reject) => {
      const store = getStore(BCAT_STORES.KEYSTORE);
      const req = store.getAll();
      req.onsuccess = () => {
        resolve(selectActiveKekForDevice(req.result as KeystoreRecord[], canonicalDevice));
      };
      req.onerror = () => reject(req.error || new Error('Gagal memeriksa keystore'));
    });
  });

  if (existingActive && existingActive.crypto_key) {
    return existingActive;
  }

  // 2. Bangkitkan kandidat KEK baru di LUAR transaksi IndexedDB
  const subtle = getSubtleCrypto();
  const candidateCryptoKey = await subtle.generateKey(
    { name: 'AES-GCM', length: 256 },
    false, // NON-EXTRACTABLE!
    ['encrypt', 'decrypt']
  );

  const candidateRecord: KeystoreRecord = {
    key_id: generateKekId(canonicalDevice, 1),
    key_version: 1,
    status: 'active',
    algorithm: 'AES-GCM-256',
    crypto_key: candidateCryptoKey,
    created_at: Date.now(),
    metadata: { device_uuid: canonicalDevice },
  };

  // 3 & 4 & 5. Double-check locking di dalam transaksi readwrite atomik
  const finalRecord = await withReadwriteTransaction([BCAT_STORES.KEYSTORE], (_tx, getStore) => {
    return new Promise<KeystoreRecord>((resolve, reject) => {
      const store = getStore(BCAT_STORES.KEYSTORE);
      const req = store.getAll();

      req.onsuccess = () => {
        const currentActive = selectActiveKekForDevice(req.result as KeystoreRecord[], canonicalDevice);
        if (currentActive && currentActive.crypto_key) {
          // Tab lain sudah mendahului membuat KEK aktif; gunakan yang ada
          resolve(currentActive);
        } else {
          // Belum ada; simpan kandidat
          const putReq = store.put(candidateRecord);
          putReq.onsuccess = () => resolve(candidateRecord);
          putReq.onerror = () => reject(putReq.error || new Error('Gagal menyimpan KEK baru ke keystore'));
        }
      };

      req.onerror = () => reject(req.error || new Error('Gagal membaca status keystore dalam transaksi'));
    });
  });

  return finalRecord;
}

/**
 * Mengambil instance CryptoKey dari keystore berdasarkan key_id
 */
export async function getKekById(keyId: string): Promise<CryptoKey> {
  if (!keyId || typeof keyId !== 'string' || !keyId.trim()) {
    throw new BchatKekUnavailableError("Parameter 'keyId' wajib berupa string non-kosong.");
  }

  const record = await idbGet<KeystoreRecord>(BCAT_STORES.KEYSTORE, keyId.trim());
  if (!record || !record.crypto_key) {
    throw new BchatKekUnavailableError(
      `KEK dengan key_id '${keyId}' tidak ditemukan pada keystore lokal atau tidak memiliki CryptoKey.`
    );
  }

  return record.crypto_key;
}

/**
 * Melakukan rotasi KEK secara atomik:
 * 1. Bangkitkan kandidat KEK baru di luar transaksi.
 * 2. Di dalam transaksi readwrite atomik:
 *    - Ambil seluruh KEK perangkat.
 *    - Tandai KEK aktif sebelumnya menjadi 'deprecated'.
 *    - Tetapkan versi KEK baru = versi tertinggi + 1 (monotonik).
 *    - Simpan kandidat KEK baru sebagai 'active'.
 * 3. Menjamin tepat ada 1 KEK aktif pasca-rotasi.
 */
export async function rotateActiveKek(deviceUuid: string): Promise<KeystoreRecord> {
  const canonicalDevice = normalizeCanonicalUuid(deviceUuid, 'deviceUuid');

  // 1. Bangkitkan kandidat KEK baru di LUAR transaksi
  const subtle = getSubtleCrypto();
  const candidateCryptoKey = await subtle.generateKey(
    { name: 'AES-GCM', length: 256 },
    false, // NON-EXTRACTABLE!
    ['encrypt', 'decrypt']
  );

  // 2. Eksekusi rotasi atomik di dalam satu transaksi readwrite
  return withReadwriteTransaction([BCAT_STORES.KEYSTORE], (_tx, getStore) => {
    return new Promise<KeystoreRecord>((resolve, reject) => {
      const store = getStore(BCAT_STORES.KEYSTORE);
      const req = store.getAll();

      req.onsuccess = () => {
        const allRecords = (req.result as KeystoreRecord[]).filter(
          (r) =>
            r.algorithm === 'AES-GCM-256' &&
            (r.metadata?.device_uuid as string) === canonicalDevice
        );

        // Cari KEK aktif saat ini dan versi tertinggi yang pernah dicatat
        let maxVersion = 0;
        for (const r of allRecords) {
          if (r.key_version > maxVersion) maxVersion = r.key_version;
        }

        const nextVersion = maxVersion + 1;
        const newRecord: KeystoreRecord = {
          key_id: generateKekId(canonicalDevice, nextVersion),
          key_version: nextVersion,
          status: 'active',
          algorithm: 'AES-GCM-256',
          crypto_key: candidateCryptoKey,
          created_at: Date.now(),
          metadata: { device_uuid: canonicalDevice },
        };

        // Deprekasikan seluruh KEK aktif sebelumnya (tidak boleh dihapus!)
        for (const r of allRecords) {
          if (r.status === 'active' && r.algorithm === 'AES-GCM-256') {
            r.status = 'deprecated';
            store.put(r);
          }
        }

        // Simpan KEK baru sebagai active
        const putReq = store.put(newRecord);
        putReq.onsuccess = () => resolve(newRecord);
        putReq.onerror = () => reject(putReq.error || new Error('Gagal menyimpan KEK baru hasil rotasi'));
      };

      req.onerror = () => reject(req.error || new Error('Gagal membaca keystore saat rotasi'));
    });
  });
}

// =============================================================================
// Operasi Enkripsi & Dekripsi Payload ke Amplop Penyimpanan
// =============================================================================

/**
 * Mengenkripsi payload biner mentah menjadi amplop penyimpanan terenkripsi (BchatEncryptedEnvelope)
 *
 * @param plaintextBytes Buffer data mentah (misal hasil serializer ClientState atau PrivateKeyPackage)
 * @param aadCtx Konteks AAD (tanpa field keyId, karena keyId diperoleh dari KEK aktif/eksplisit)
 * @param explicitKek Opsi menggunakan KEK spesifik (default: mengambil KEK aktif perangkat)
 */
export async function encryptPayloadToEnvelope(
  plaintextBytes: Uint8Array,
  aadCtx: Omit<BchatAadContext, 'keyId'>,
  explicitKek?: { keyId: string; key: CryptoKey }
): Promise<BchatEncryptedEnvelope> {
  if (!(plaintextBytes instanceof Uint8Array)) {
    throw new BchatStorageError("Parameter 'plaintextBytes' wajib berupa Uint8Array.", 'INVALID_PLAINTEXT');
  }

  // 1. Tentukan KEK yang digunakan
  let activeKeyId: string;
  let cryptoKey: CryptoKey;

  if (explicitKek) {
    activeKeyId = explicitKek.keyId;
    cryptoKey = explicitKek.key;
  } else {
    const activeKekRecord = await getOrCreateActiveKek(aadCtx.deviceUuid);
    if (!activeKekRecord.crypto_key) {
      throw new BchatKekUnavailableError('KEK aktif tidak memuat CryptoKey valid.');
    }
    activeKeyId = activeKekRecord.key_id;
    cryptoKey = activeKekRecord.crypto_key;
  }

  // 2. Bangun binary AAD deterministik
  const fullAadCtx: BchatAadContext = {
    ...aadCtx,
    keyId: activeKeyId,
    storageSchemaVersion: DEFAULT_STORAGE_SCHEMA_VERSION,
    serializerVersion: DEFAULT_SERIALIZER_VERSION,
  };
  const aadBytes = buildStorageAad(fullAadCtx);

  // 3. Bangkitkan fresh IV 96-bit (12 bytes) menggunakan CSPRNG
  const iv = getRandomBytes(GCM_IV_LENGTH_BYTES);

  // 4. Operasi enkripsi WebCrypto AES-GCM
  const subtle = getSubtleCrypto();
  let ciphertextBuffer: ArrayBuffer;
  try {
    ciphertextBuffer = await subtle.encrypt(
      {
        name: 'AES-GCM',
        iv: toArrayBuffer(iv),
        additionalData: toArrayBuffer(aadBytes),
        tagLength: 128, // 128-bit authentication tag
      },
      cryptoKey,
      toArrayBuffer(plaintextBytes)
    );
  } catch (err: any) {
    throw new BchatStorageError(
      `Kegagalan enkripsi WebCrypto AES-GCM: ${err?.message || 'Unknown error'}`,
      'ENCRYPTION_FAILED',
      err
    );
  }

  // 5. Bentuk amplop penyimpanan tervalidasi
  const envelope: BchatEncryptedEnvelope = {
    storage_schema_version: DEFAULT_STORAGE_SCHEMA_VERSION,
    serializer_version: DEFAULT_SERIALIZER_VERSION,
    key_id: activeKeyId,
    iv,
    ciphertext: new Uint8Array(ciphertextBuffer),
    created_at: Date.now(),
  };

  return validateStorageEnvelope(envelope);
}

/**
 * Mendekripsi amplop penyimpanan menjadi payload biner mentah
 *
 * @param envelope Amplop penyimpanan terenkripsi yang dibaca dari IndexedDB
 * @param aadCtx Konteks AAD yang dibangun ulang dari identitas record yang dibaca
 */
export async function decryptPayloadFromEnvelope(
  envelope: BchatEncryptedEnvelope,
  aadCtx: Omit<BchatAadContext, 'keyId'>
): Promise<Uint8Array> {
  // 1. Validasi struktur amplop secara ketat SEBELUM menyentuh WebCrypto
  const validEnv = validateStorageEnvelope(envelope);

  // 2. Ambil KEK dari keystore berdasarkan key_id yang tertera pada amplop
  const cryptoKey = await getKekById(validEnv.key_id);

  // 3. Bangun ulang binary AAD deterministik dari konteks record (JANGAN mempercayai AAD dari amplop)
  const fullAadCtx: BchatAadContext = {
    ...aadCtx,
    keyId: validEnv.key_id,
    storageSchemaVersion: validEnv.storage_schema_version,
    serializerVersion: validEnv.serializer_version,
  };
  const aadBytes = buildStorageAad(fullAadCtx);

  // 4. Operasi dekripsi WebCrypto AES-GCM
  const subtle = getSubtleCrypto();
  let decryptedBuffer: ArrayBuffer;
  try {
    decryptedBuffer = await subtle.decrypt(
      {
        name: 'AES-GCM',
        iv: toArrayBuffer(validEnv.iv),
        additionalData: toArrayBuffer(aadBytes),
        tagLength: 128,
      },
      cryptoKey,
      toArrayBuffer(validEnv.ciphertext)
    );
  } catch (err: any) {
    // Autentikasi GCM gagal atau MAC mismatch (misal: ciphertext rusak atau AAD tidak cocok)
    throw new BchatStorageDecryptionError(
      'Gagal mendekripsi amplop penyimpanan. Integritas ciphertext atau AAD authentication tag tidak valid.',
      err
    );
  }

  return new Uint8Array(decryptedBuffer);
}
