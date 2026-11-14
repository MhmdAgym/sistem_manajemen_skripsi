/**
 * B-CHAT E2EE — GroupState Persistence Orchestrator Core
 *
 * Mengimplementasikan gerbang penyimpanan aman antara MLS ClientState dan IndexedDB
 * sesuai ketetapan H-A sampai H-G dan keputusan H-D:
 * - Operasi WebCrypto (AES-256-GCM) dan serialisasi TLS RFC 9420 (§13) 100% DI LUAR transaksi IndexedDB.
 * - Transaksi readwrite tunggal atomik melintasi multi-store [tab_leases, group_states].
 * - Validasi fencing token generasi eksak (BigInt decimal string) untuk menolak stale leader.
 * - Validasi kenaikan revisi monotonik ("1" -> "2" -> "3") dan pencegahan benturan revisi.
 * - Pencegahan regresi epoch (candidate epoch tidak boleh lebih rendah dari stored epoch).
 * - Kebijakan fail-closed: karantina (quarantine) record yang gagal integritas tanpa menghapus data di disk.
 * - Sanitasi alasan karantina tanpa mengekspos kunci, rahasia, atau ciphertext ke log.
 */

import {
  BCAT_STORES,
  type GroupStateRecord,
  type TabLeaseRecord,
  type MessageOutboxRecord,
  type PendingKeyPackageRecord,
  type KeyPackagePublicationStatus,
} from './bchat-idb-schema';

export type { KeyPackagePublicationStatus };
import {
  withReadwriteTransaction,
  idbGet,
  idbPut,
  BchatStorageError,
  BchatTransactionError,
} from './bchat-idb-core';
import {
  buildStorageAad,
  normalizeCanonicalUuid,
  DEFAULT_STORAGE_SCHEMA_VERSION,
} from './bchat-storage-aad';
import {
  encryptPayloadToEnvelope,
  decryptPayloadFromEnvelope,
  BchatStorageDecryptionError,
} from './bchat-crypto-helper';
import {
  validateStorageEnvelope,
  type BchatEncryptedEnvelope,
  BchatEnvelopeValidationError,
} from './bchat-storage-envelope';
import {
  encodeClientState,
  decodeClientStateStrict,
  BCAT_CLIENT_STATE_SERIALIZER_VERSION,
  BchatCodecError,
} from './bchat-mls-state-codec';
import type { ClientState } from '../../../../node_modules/ts-mls/dist/src/clientState.js';
import {
  privateKeyPackageEncoder,
  privateKeyPackageDecoder,
  keyPackageDecoder,
  type KeyPackage,
  type PrivateKeyPackage,
} from '../../../../node_modules/ts-mls/dist/src/keyPackage.js';
import { encode } from '../../../../node_modules/ts-mls/dist/src/codec/tlsEncoder.js';
import { decode } from '../../../../node_modules/ts-mls/dist/src/codec/tlsDecoder.js';

// =============================================================================
// Konstanta Baku Persistence Orchestrator
// =============================================================================

export const DEFAULT_CIPHERSUITE_ID = 1; // MLS_128_DHKEMX25519_AES128GCM_SHA256_Ed25519
export const DECIMAL_STRING_REGEX = /^(0|[1-9][0-9]*)$/;

// =============================================================================
// Utilitas CSPRNG Fail-Closed (H-I3B.2R.2R)
// =============================================================================

/**
 * Pembangkit UUID v4 kanonis berbasis Web Crypto CSPRNG (crypto.randomUUID atau crypto.getRandomValues).
 * Wajib gagal tertutup (fail-closed) jika primitif Web Crypto CSPRNG tidak tersedia.
 * DILARANG menggunakan Math.random(), timestamp, atau counter sebagai fallback entropi.
 */
export function generateCryptoRandomUuid(): string {
  const cryptoObj =
    typeof globalThis !== 'undefined' && globalThis.crypto
      ? globalThis.crypto
      : typeof self !== 'undefined' && self.crypto
      ? self.crypto
      : typeof window !== 'undefined' && window.crypto
      ? window.crypto
      : undefined;

  if (!cryptoObj) {
    throw new BchatPersistenceError(
      'Web Crypto API tidak tersedia pada runtime saat ini. Operasi ditolak secara tertutup (fail-closed).'
    );
  }

  if (typeof cryptoObj.randomUUID === 'function') {
    return cryptoObj.randomUUID();
  }

  if (typeof cryptoObj.getRandomValues === 'function') {
    const bytes = new Uint8Array(16);
    cryptoObj.getRandomValues(bytes);
    // RFC 4122 Section 4.4: set version 4 dan variant RFC 4122
    bytes[6] = (bytes[6] & 0x0f) | 0x40;
    bytes[8] = (bytes[8] & 0x3f) | 0x80;
    const hex = Array.from(bytes, (b) => b.toString(16).padStart(2, '0')).join('');
    return `${hex.slice(0, 8)}-${hex.slice(8, 12)}-${hex.slice(12, 16)}-${hex.slice(16, 20)}-${hex.slice(20, 32)}`;
  }

  throw new BchatPersistenceError(
    'Sumber entropi CSPRNG (crypto.randomUUID atau crypto.getRandomValues) tidak tersedia. Operasi ditolak secara tertutup (fail-closed).'
  );
}

// =============================================================================
// Hierarki Kelas Error Terstruktur
// =============================================================================

export class BchatPersistenceError extends BchatStorageError {
  constructor(message: string, cause?: unknown) {
    super(message, 'PERSISTENCE_ERROR', cause);
    this.name = 'BchatPersistenceError';
  }
}

export class BchatFencingTokenStaleError extends BchatPersistenceError {
  constructor(message: string, cause?: unknown) {
    super(message, cause);
    this.name = 'BchatFencingTokenStaleError';
  }
}

export class BchatRevisionConflictError extends BchatPersistenceError {
  constructor(message: string, cause?: unknown) {
    super(message, cause);
    this.name = 'BchatRevisionConflictError';
  }
}

export class BchatEpochRegressionError extends BchatPersistenceError {
  constructor(message: string, cause?: unknown) {
    super(message, cause);
    this.name = 'BchatEpochRegressionError';
  }
}

export class BchatGroupIdMismatchError extends BchatPersistenceError {
  constructor(message: string, cause?: unknown) {
    super(message, cause);
    this.name = 'BchatGroupIdMismatchError';
  }
}

export class BchatQuarantinedStateError extends BchatPersistenceError {
  constructor(message: string, cause?: unknown) {
    super(message, cause);
    this.name = 'BchatQuarantinedStateError';
  }
}

// =============================================================================
// Kontrak Tipe Data Persistence Orchestrator
// =============================================================================

export interface FencingCredentials {
  ownerToken: string;
  generation: string; // decimal string eksak (e.g., "1", "100")
}

export interface PersistGroupStateParams {
  deviceUuid: string;
  roomUuid: string;
  state: ClientState;
  expectedPreviousRevision: string | null;
  ciphersuiteId?: number;
  fencing: FencingCredentials;
  lastProcessedSequence?: number;
  metadata?: Record<string, unknown>;
}

export interface PersistGroupStateResult {
  stateRevision: string;
  previousRevision: string | null;
  epoch: string;
  groupIdHash: string;
  updatedAt: number;
}

export interface OutboxPayloadParams {
  clientMessageUuid: string;
  ciphertextBytes: Uint8Array;
  metadata?: Record<string, unknown>;
}

export interface PersistGroupStateAndOutboxParams extends PersistGroupStateParams {
  outbox: OutboxPayloadParams;
}

export interface PersistGroupStateAndOutboxResult extends PersistGroupStateResult {
  outboxRecord: MessageOutboxRecord;
}

export interface RestoreGroupStateResult {
  status: 'FOUND' | 'NOT_FOUND' | 'QUARANTINED';
  state?: ClientState;
  epoch?: string;
  stateRevision?: string;
  previousRevision?: string | null;
  groupIdHash?: string;
  quarantineReason?: string;
  error?: string;
  lastProcessedSequence?: number;
  metadata?: Record<string, unknown>;
}

export interface QuarantineGroupStateParams {
  deviceUuid: string;
  roomUuid: string;
  expectedRevision: string;
  reason: string;
  fencing: FencingCredentials;
}

export interface SavePendingKeyPackageParams {
  deviceUuid: string;
  keyPackageHash: string;
  privateKeyPackage: PrivateKeyPackage;
  publicKeyPackageBytes?: Uint8Array;
  metadata?: Record<string, unknown>;
  fencing?: FencingCredentials;
}

export interface LoadedPendingKeyPackage {
  privatePackage: PrivateKeyPackage;
  publicPackage?: KeyPackage;
  status: 'pending' | 'consumed';
  publicationStatus?: KeyPackagePublicationStatus;
}

export interface JoinGroupAndConsumeParams {
  deviceUuid: string;
  roomUuid: string;
  keyPackageHash: string;
  state: ClientState;
  expectedPreviousRevision: string | null;
  ciphersuiteId?: number;
  fencing: FencingCredentials;
}

export interface JoinGroupAndConsumeResult extends PersistGroupStateResult {
  keyPackageHash: string;
}

export interface ProtocolOutboxInput {
  clientMessageUuid: string;
  messageType: 'commit' | 'welcome';
  ciphertextBytes: Uint8Array;
  epoch: string;
  seq?: number;
  metadata?: Record<string, unknown>;
}

export interface PersistCommitProtocolParams {
  deviceUuid: string;
  roomUuid: string;
  state: ClientState;
  expectedPreviousRevision: string | null;
  ciphersuiteId?: number;
  fencing: FencingCredentials;
  commitOutbox: ProtocolOutboxInput;
  welcomeOutbox: ProtocolOutboxInput;
  operationId?: string;
}

export interface PersistCommitProtocolResult extends PersistGroupStateResult {
  commitMessageUuid: string;
  welcomeMessageUuid: string;
  operationId: string;
  commitOutboxRecord: MessageOutboxRecord;
  welcomeOutboxRecord: MessageOutboxRecord;
}

export interface ClaimOutboxMessageParams {
  deviceUuid: string;
  clientMessageUuid: string;
  fencing: FencingCredentials;
  roomUuid?: string;
  claimTimeoutMs?: number;
}

export interface ClaimOutboxMessageResult {
  success: boolean;
  claimToken: string;
  outboxRecord: MessageOutboxRecord;
}

export interface UpdateOutboxMessageStatusOptions {
  clientMessageUuid: string;
  status: MessageOutboxRecord['status'];
  sentAt?: number;
  errorMessage?: string | null;
  deviceUuid?: string;
  fencing?: FencingCredentials;
  claimToken?: string;
}

// =============================================================================
// Helper Internal Orchestrator
// =============================================================================

function toArrayBuffer(view: Uint8Array): ArrayBuffer {
  return view.buffer.slice(view.byteOffset, view.byteOffset + view.byteLength) as ArrayBuffer;
}

function areUint8ArraysEqual(a: Uint8Array, b: Uint8Array): boolean {
  if (a.byteLength !== b.byteLength) return false;
  for (let i = 0; i < a.byteLength; i++) {
    if (a[i] !== b[i]) return false;
  }
  return true;
}

export async function computeSha256Hex(bytes: Uint8Array): Promise<string> {
  const hashBuffer = await crypto.subtle.digest('SHA-256', toArrayBuffer(bytes));
  const hashArray = Array.from(new Uint8Array(hashBuffer));
  return hashArray.map((b) => b.toString(16).padStart(2, '0')).join('');
}

function idbRequestToPromise<T>(request: IDBRequest<T>): Promise<T> {
  return new Promise<T>((resolve, reject) => {
    request.onsuccess = () => resolve(request.result);
    request.onerror = () => reject(request.error || new Error('IDBRequest gagal tanpa detail error'));
  });
}

function sanitizeQuarantineReason(err: unknown): string {
  if (err instanceof BchatStorageDecryptionError) {
    return 'Decryption failed: integrity check or MAC authentication tag mismatch';
  }
  if (err instanceof BchatEnvelopeValidationError) {
    return `Envelope validation failed: ${err.message.replace(/([0-9a-f]{16,})/gi, '[REDACTED]')}`;
  }
  if (err instanceof BchatCodecError) {
    return `ClientState decoding failed: ${err.message.replace(/([0-9a-f]{16,})/gi, '[REDACTED]')}`;
  }
  if (err instanceof BchatGroupIdMismatchError) {
    return 'Group ID validation failed: mismatch between decoded group and canonical room UUID';
  }
  if (err instanceof BchatPersistenceError) {
    return `Integrity check failed: ${err.message.replace(/([0-9a-f]{16,})/gi, '[REDACTED]')}`;
  }
  const msg = (err as Error)?.message || 'Unknown integrity verification failure';
  return `Integrity check failed: ${msg.replace(/([0-9a-f]{16,})/gi, '[REDACTED]')}`.slice(0, 255);
}

// =============================================================================
// API Utama: persistGroupState
// =============================================================================

/**
 * Menyimpan ClientState MLS secara transaksional, terenkripsi, dan terikat fencing token.
 *
 * ATURAN MUTLAK:
 * 1. Serialisasi TLS dan enkripsi WebCrypto dieksekusi LENGKAP di luar transaksi IDB.
 * 2. Transaksi multi-store [tab_leases, group_states] hanya dibuka untuk verifikasi atomik
 *    dan penulisan durable ke IndexedDB.
 */
/**
 * Menyimpan ClientState MLS secara transaksional, terenkripsi, dan terikat fencing token.
 * Jika parameter outbox disertakan, membuka transaksi atomik 3-store [tab_leases, group_states, message_outbox].
 *
 * ATURAN MUTLAK:
 * 1. Serialisasi TLS dan enkripsi WebCrypto dieksekusi LENGKAP di luar transaksi IDB.
 * 2. Transaksi multi-store hanya dibuka untuk verifikasi atomik dan penulisan durable ke IndexedDB.
 */
async function persistGroupStateInternal(
  params: PersistGroupStateParams,
  outbox?: OutboxPayloadParams
): Promise<PersistGroupStateResult | PersistGroupStateAndOutboxResult> {
  // ---------------------------------------------------------------------------
  // FASE 1: Validasi Input dan Kriptografi di Luar Transaksi IDB
  // ---------------------------------------------------------------------------
  if (!params) {
    throw new BchatPersistenceError("Parameter 'params' wajib disertakan.");
  }

  const canonicalDeviceUuid = normalizeCanonicalUuid(params.deviceUuid, 'deviceUuid');
  const canonicalRoomUuid = normalizeCanonicalUuid(params.roomUuid, 'roomUuid');

  let canonicalClientMessageUuid: string | undefined;
  if (outbox) {
    if (!outbox.clientMessageUuid || typeof outbox.clientMessageUuid !== 'string') {
      throw new BchatPersistenceError("Parameter 'outbox.clientMessageUuid' wajib berupa string UUID.");
    }
    canonicalClientMessageUuid = normalizeCanonicalUuid(outbox.clientMessageUuid, 'clientMessageUuid');
    if (!(outbox.ciphertextBytes instanceof Uint8Array) || outbox.ciphertextBytes.byteLength === 0) {
      throw new BchatPersistenceError("Parameter 'outbox.ciphertextBytes' wajib berupa Uint8Array non-kosong.");
    }
  }

  if (!params.fencing || typeof params.fencing.ownerToken !== 'string' || params.fencing.ownerToken.trim() === '') {
    throw new BchatPersistenceError('Fencing owner token wajib berupa string non-kosong.');
  }

  if (typeof params.fencing.generation !== 'string' || !DECIMAL_STRING_REGEX.test(params.fencing.generation)) {
    throw new BchatPersistenceError(
      `Fencing generation '${params.fencing?.generation}' tidak valid. Wajib berupa decimal string non-negatif.`
    );
  }

  if (params.expectedPreviousRevision !== null) {
    if (
      typeof params.expectedPreviousRevision !== 'string' ||
      !DECIMAL_STRING_REGEX.test(params.expectedPreviousRevision)
    ) {
      throw new BchatPersistenceError(
        `expectedPreviousRevision '${params.expectedPreviousRevision}' tidak valid. Wajib bernilai null atau decimal string.`
      );
    }
  }

  if (!params.state || typeof params.state !== 'object' || !params.state.groupContext) {
    throw new BchatPersistenceError('ClientState tidak valid atau tidak memiliki GroupContext.');
  }

  if (!(params.state.groupContext.groupId instanceof Uint8Array)) {
    throw new BchatGroupIdMismatchError('GroupContext.groupId wajib berupa Uint8Array.');
  }

  // Validasi Group ID wajib 36 byte dan cocok dengan UTF-8 canonical room UUID
  const expectedGroupIdBytes = new TextEncoder().encode(canonicalRoomUuid);
  if (
    params.state.groupContext.groupId.byteLength !== 36 ||
    !areUint8ArraysEqual(params.state.groupContext.groupId, expectedGroupIdBytes)
  ) {
    throw new BchatGroupIdMismatchError(
      `GroupContext groupId (${params.state.groupContext.groupId.byteLength} byte) tidak cocok dengan canonical room UUID 36 byte.`
    );
  }

  // Hitung SHA-256 dari raw Group ID bytes untuk group_id_hash
  const groupIdHash = await computeSha256Hex(params.state.groupContext.groupId);

  // Ambil epoch sebagai BigInt dan serialisasikan menjadi decimal string
  const epochBigInt = BigInt(params.state.groupContext.epoch);
  const epochStr = epochBigInt.toString();
  if (!DECIMAL_STRING_REGEX.test(epochStr)) {
    throw new BchatPersistenceError('Epoch ClientState tidak menghasilkan decimal string valid.');
  }

  const ciphersuiteId = params.ciphersuiteId ?? DEFAULT_CIPHERSUITE_ID;

  // 1.2 Serialisasi ClientState ke biner TLS RFC 9420 (§13) di luar transaksi IDB
  const stateBytes = encodeClientState(params.state);

  // 1.3 Bangun konteks AAD dan enkripsi ke amplop terotentikasi di luar transaksi IDB
  const envelope = await encryptPayloadToEnvelope(stateBytes, {
    deviceUuid: canonicalDeviceUuid,
    roomUuid: canonicalRoomUuid,
    storeName: BCAT_STORES.GROUP_STATES,
    recordType: 'client_state',
    recordKey: [canonicalDeviceUuid, canonicalRoomUuid],
  });

  // ---------------------------------------------------------------------------
  // FASE 2: Transaksi Atomik Multi-Store
  // ---------------------------------------------------------------------------
  let newRevision: string = '1';
  let previousRevision: string | null = null;
  const now = Date.now();
  let explicitAbortError: BchatPersistenceError | null = null;
  let createdOutboxRecord: MessageOutboxRecord | undefined;

  const storesToOpen: (typeof BCAT_STORES)[keyof typeof BCAT_STORES][] = outbox
    ? [BCAT_STORES.TAB_LEASES, BCAT_STORES.GROUP_STATES, BCAT_STORES.MESSAGE_OUTBOX]
    : [BCAT_STORES.TAB_LEASES, BCAT_STORES.GROUP_STATES];

  try {
    await withReadwriteTransaction(
      storesToOpen,
      async (tx, getStore) => {
        const leaseStore = getStore(BCAT_STORES.TAB_LEASES);
        const groupStore = getStore(BCAT_STORES.GROUP_STATES);

        // A. Validasi Fencing Lease
        const leaseReq = leaseStore.get(canonicalDeviceUuid);
        const lease = await idbRequestToPromise<TabLeaseRecord | undefined>(leaseReq);

        if (!lease) {
          explicitAbortError = new BchatFencingTokenStaleError(
            `Tidak ditemukan tab lease aktif untuk perangkat [${canonicalDeviceUuid}]. Penulisan ditolak.`
          );
          tx.abort();
          throw explicitAbortError;
        }

        if (lease.owner_token !== params.fencing.ownerToken) {
          explicitAbortError = new BchatFencingTokenStaleError(
            `Fencing owner token mismatch: lease aktif milik '${lease.owner_token}', namun pemanggil adalah '${params.fencing.ownerToken}'. Penulisan ditolak.`
          );
          tx.abort();
          throw explicitAbortError;
        }

        const leaseGenStr = lease.generation;
        if (typeof leaseGenStr !== 'string' || !DECIMAL_STRING_REGEX.test(leaseGenStr)) {
          explicitAbortError = new BchatFencingTokenStaleError(
            `Generasi lease tersimpan '${leaseGenStr}' bukan decimal string valid.`
          );
          tx.abort();
          throw explicitAbortError;
        }

        const callerGenBigInt = BigInt(params.fencing.generation);
        const activeGenBigInt = BigInt(leaseGenStr);

        if (callerGenBigInt !== activeGenBigInt) {
          explicitAbortError = new BchatFencingTokenStaleError(
            `Fencing generation stale: generasi aktif pada disk adalah '${leaseGenStr}', namun pemanggil membawa '${params.fencing.generation}'. Penulisan ditolak.`
          );
          tx.abort();
          throw explicitAbortError;
        }

        // B. Validasi State yang Sedang Tersimpan
        const groupReq = groupStore.get([canonicalDeviceUuid, canonicalRoomUuid]);
        const existing = await idbRequestToPromise<GroupStateRecord | undefined>(groupReq);

        if (existing && existing.status === 'quarantined') {
          explicitAbortError = new BchatQuarantinedStateError(
            `Group state untuk room [${canonicalRoomUuid}] sedang berstatus QUARANTINED. Tidak boleh ditimpa melalui persistGroupState normal.`
          );
          tx.abort();
          throw explicitAbortError;
        }

        if (existing) {
          // Benturan Revisi
          if (existing.state_revision !== params.expectedPreviousRevision) {
            explicitAbortError = new BchatRevisionConflictError(
              `Benturan revisi: revisi saat ini pada disk adalah '${existing.state_revision}', namun pemanggil mengekspektasikan '${params.expectedPreviousRevision}'.`
            );
            tx.abort();
            throw explicitAbortError;
          }

          // Pencegahan Regresi Epoch
          const storedEpochBigInt = BigInt(existing.epoch);
          if (epochBigInt < storedEpochBigInt) {
            explicitAbortError = new BchatEpochRegressionError(
              `Regresi epoch ditolak: epoch kandidat '${epochStr}' lebih rendah dari epoch tersimpan '${existing.epoch}'.`
            );
            tx.abort();
            throw explicitAbortError;
          }

          // Kenaikan Revisi Monotonik BigInt
          const prevRevBigInt = BigInt(existing.state_revision);
          newRevision = (prevRevBigInt + BigInt(1)).toString();
          previousRevision = existing.state_revision;
        } else {
          // Record baru: wajib expectedPreviousRevision === null
          if (params.expectedPreviousRevision !== null) {
            explicitAbortError = new BchatRevisionConflictError(
              `Benturan revisi: record baru tidak memiliki revisi sebelumnya, namun pemanggil mengekspektasikan '${params.expectedPreviousRevision}'.`
            );
            tx.abort();
            throw explicitAbortError;
          }
          newRevision = '1';
          previousRevision = null;
        }

        // C. Validasi Outbox (Jika disertakan dalam transaksi atomik)
        if (outbox && canonicalClientMessageUuid) {
          const outboxStore = getStore(BCAT_STORES.MESSAGE_OUTBOX);
          const outboxReq = outboxStore.get(canonicalClientMessageUuid);
          const existingOutbox = await idbRequestToPromise<MessageOutboxRecord | undefined>(outboxReq);

          if (existingOutbox) {
            explicitAbortError = new BchatPersistenceError(
              `Pesan outbox dengan client_message_uuid [${canonicalClientMessageUuid}] sudah ada. Duplikasi ditolak.`
            );
            tx.abort();
            throw explicitAbortError;
          }

          createdOutboxRecord = {
            client_message_uuid: canonicalClientMessageUuid,
            room_uuid: canonicalRoomUuid,
            status: 'pending',
            ciphertext_bytes: outbox.ciphertextBytes,
            created_at: now,
            retry_count: 0,
            ...(outbox.metadata ? { metadata: outbox.metadata } : {}),
          };
          outboxStore.put(createdOutboxRecord);
        }

        // D. Tulis Record Baru GroupState (Produksi Ketat)
        const existingSeq =
          existing?.last_processed_sequence ??
          (existing?.metadata?.last_processed_sequence as number | undefined);
        const resolvedSeq =
          params.lastProcessedSequence !== undefined ? params.lastProcessedSequence : existingSeq;

        const newRecord: GroupStateRecord = {
          device_uuid: canonicalDeviceUuid,
          room_uuid: canonicalRoomUuid,
          storage_schema_version: DEFAULT_STORAGE_SCHEMA_VERSION,
          serializer_version: BCAT_CLIENT_STATE_SERIALIZER_VERSION,
          ciphersuite_id: ciphersuiteId,
          group_id_hash: groupIdHash,
          epoch: epochStr,
          state_revision: newRevision,
          previous_revision: previousRevision,
          key_id: envelope.key_id,
          envelope: envelope,
          status: 'active',
          created_at: existing && existing.created_at ? existing.created_at : now,
          updated_at: now,
          last_processed_sequence: resolvedSeq,
          metadata: {
            ...(existing?.metadata || {}),
            ...(params.metadata || {}),
            ...(resolvedSeq !== undefined ? { last_processed_sequence: resolvedSeq } : {}),
          },
        };

        groupStore.put(newRecord);
      }
    );
  } catch (err: unknown) {
    if (explicitAbortError) {
      throw explicitAbortError;
    }
    const orig = (err as any)?.originalError ?? (err as any)?.cause;
    if (orig instanceof BchatPersistenceError) {
      throw orig;
    }
    if (err instanceof BchatPersistenceError) {
      throw err;
    }
    throw new BchatPersistenceError(
      `Persistensi group state gagal: ${(err as Error)?.message || 'Unknown error'}`,
      err
    );
  }

  // Transaksi oncomplete berhasil: kembalikan metadata sukses
  if (outbox && createdOutboxRecord) {
    const result: PersistGroupStateAndOutboxResult = {
      stateRevision: newRevision,
      previousRevision: previousRevision,
      epoch: epochStr,
      groupIdHash: groupIdHash,
      updatedAt: now,
      outboxRecord: createdOutboxRecord,
    };
    return result;
  }

  return {
    stateRevision: newRevision,
    previousRevision: previousRevision,
    epoch: epochStr,
    groupIdHash: groupIdHash,
    updatedAt: now,
  };
}

/**
 * Menyimpan ClientState MLS secara transaksional, terenkripsi, dan terikat fencing token.
 */
export async function persistGroupState(
  params: PersistGroupStateParams
): Promise<PersistGroupStateResult> {
  const result = await persistGroupStateInternal(params);
  return result as PersistGroupStateResult;
}

/**
 * Menyimpan GroupState baru dan rekaman MessageOutbox dalam SATU transaksi atomik IndexedDB.
 * Transaksi mencakup multi-store [tab_leases, group_states, message_outbox].
 * Jika salah satu gagal atau di-abort, seluruh perubahan di kedua store dibatalkan secara atomik.
 */
export async function persistGroupStateAndOutbox(
  params: PersistGroupStateAndOutboxParams
): Promise<PersistGroupStateAndOutboxResult> {
  if (!params || !params.outbox) {
    throw new BchatPersistenceError("Parameter 'params.outbox' wajib disertakan.");
  }
  const result = await persistGroupStateInternal(params, params.outbox);
  return result as PersistGroupStateAndOutboxResult;
}

/**
 * Mengambil record MessageOutbox berdasarkan client_message_uuid.
 */
export async function getOutboxMessage(
  clientMessageUuid: string
): Promise<MessageOutboxRecord | undefined> {
  const canonicalUuid = normalizeCanonicalUuid(clientMessageUuid, 'clientMessageUuid');
  return idbGet<MessageOutboxRecord>(BCAT_STORES.MESSAGE_OUTBOX, canonicalUuid);
}

/**
 * Memperbarui status rekaman outbox (misalnya saat transmisi in_transit, acked, atau failed).
 * Mencegah status terminal 'acked' mundur ke status lain (terminal monotonicity).
 * Jika record sedang in_transit atau memiliki claim aktif, memvalidasi kepemilikan claimToken aktif.
 * Jika fencing dan deviceUuid disertakan, validasi lease aktif dijalankan secara atomik.
 */
export async function updateOutboxMessageStatus(
  optionsOrUuid: string | UpdateOutboxMessageStatusOptions,
  status?: MessageOutboxRecord['status'],
  sentAt?: number,
  errorMessage?: string | null,
  fencing?: FencingCredentials,
  deviceUuid?: string,
  claimToken?: string
): Promise<void> {
  let clientMessageUuid: string;
  let targetStatus: MessageOutboxRecord['status'];
  let targetSentAt: number | undefined;
  let targetErrorMessage: string | null | undefined;
  let targetFencing: FencingCredentials | undefined;
  let targetDeviceUuid: string | undefined;
  let targetClaimToken: string | undefined;

  if (typeof optionsOrUuid === 'object') {
    clientMessageUuid = optionsOrUuid.clientMessageUuid;
    targetStatus = optionsOrUuid.status;
    targetSentAt = optionsOrUuid.sentAt;
    targetErrorMessage = optionsOrUuid.errorMessage;
    targetFencing = optionsOrUuid.fencing;
    targetDeviceUuid = optionsOrUuid.deviceUuid;
    targetClaimToken = optionsOrUuid.claimToken;
  } else {
    clientMessageUuid = optionsOrUuid;
    targetStatus = status!;
    targetSentAt = sentAt;
    targetErrorMessage = errorMessage;
    targetFencing = fencing;
    targetDeviceUuid = deviceUuid;
    targetClaimToken = claimToken;
  }

  const canonicalUuid = normalizeCanonicalUuid(clientMessageUuid, 'clientMessageUuid');

  // Jika fencing & deviceUuid tersedia, validasi di dalam transaksi multi-store [tab_leases, message_outbox]
  if (targetFencing && targetDeviceUuid) {
    const canonicalDeviceUuid = normalizeCanonicalUuid(targetDeviceUuid, 'deviceUuid');
    const f = targetFencing;
    const now = Date.now();
    let explicitAbortError: BchatPersistenceError | null = null;
    await withReadwriteTransaction(
      [BCAT_STORES.TAB_LEASES, BCAT_STORES.MESSAGE_OUTBOX],
      async (tx, getStore) => {
        const leaseStore = getStore(BCAT_STORES.TAB_LEASES);
        const outboxStore = getStore(BCAT_STORES.MESSAGE_OUTBOX);

        const leaseReq = leaseStore.get(canonicalDeviceUuid);
        const lease = await idbRequestToPromise<TabLeaseRecord | undefined>(leaseReq);

        if (!lease) {
          explicitAbortError = new BchatFencingTokenStaleError(
            `Tidak ditemukan tab lease aktif untuk perangkat [${canonicalDeviceUuid}]. Update outbox ditolak.`
          );
          tx.abort();
          throw explicitAbortError;
        }

        if (lease.owner_token !== f.ownerToken) {
          explicitAbortError = new BchatFencingTokenStaleError(
            `Fencing owner token mismatch: lease aktif milik '${lease.owner_token}', namun pemanggil adalah '${f.ownerToken}'. Update outbox ditolak.`
          );
          tx.abort();
          throw explicitAbortError;
        }

        if (BigInt(f.generation) !== BigInt(lease.generation)) {
          explicitAbortError = new BchatFencingTokenStaleError(
            `Fencing generation stale: generasi aktif pada disk adalah '${lease.generation}', namun pemanggil membawa '${f.generation}'. Update outbox ditolak.`
          );
          tx.abort();
          throw explicitAbortError;
        }

        if (typeof lease.expires_at === 'number' && now > lease.expires_at) {
          explicitAbortError = new BchatFencingTokenStaleError(
            `Tab lease untuk perangkat [${canonicalDeviceUuid}] telah kedaluwarsa. Operasi ditolak.`
          );
          tx.abort();
          throw explicitAbortError;
        }

        const req = outboxStore.get(canonicalUuid);
        const existing = await idbRequestToPromise<MessageOutboxRecord | undefined>(req);
        if (!existing) {
          explicitAbortError = new BchatPersistenceError(
            `MessageOutbox dengan UUID [${canonicalUuid}] tidak ditemukan.`
          );
          tx.abort();
          throw explicitAbortError;
        }

        const activeClaimToken = existing.metadata?.claim_token as string | undefined;

        // Validasi Claim Ownership (Koreksi B):
        // Jika record sedang in_transit atau pernah diklaim dengan claim_token:
        // claimToken WAJIB disertakan dan COCOK dengan klaim aktif pada disk!
        if (existing.status === 'in_transit' || activeClaimToken) {
          if (!targetClaimToken || typeof targetClaimToken !== 'string' || targetClaimToken.trim() === '') {
            explicitAbortError = new BchatPersistenceError(
              `Update status outbox [${canonicalUuid}] ditolak: record sedang berstatus '${existing.status}' dengan klaim aktif, 'claimToken' wajib disertakan.`
            );
            tx.abort();
            throw explicitAbortError;
          }

          if (activeClaimToken && targetClaimToken !== activeClaimToken) {
            explicitAbortError = new BchatPersistenceError(
              `Update status outbox [${canonicalUuid}] ditolak: claim token tidak cocok (aktif: '${activeClaimToken}', pemanggil: '${targetClaimToken}'). Klaim telah diambil alih atau kedaluwarsa.`
            );
            tx.abort();
            throw explicitAbortError;
          }
        }

        // Terminal ACK protection: status acked tidak boleh mundur ke status apa pun
        if (existing.status === 'acked' && targetStatus !== 'acked') {
          return;
        }

        existing.status = targetStatus;
        if (targetSentAt !== undefined) {
          existing.sent_at = targetSentAt;
        }
        if (targetStatus === 'in_transit') {
          existing.retry_count = (existing.retry_count ?? 0) + 1;
        }
        if (targetErrorMessage !== undefined) {
          existing.metadata = {
            ...(existing.metadata ?? {}),
            error_message: targetErrorMessage,
          };
        }
        if (targetStatus === 'acked') {
          existing.metadata = {
            ...(existing.metadata ?? {}),
            acked_at: now,
            ...(targetClaimToken ? { acked_by_token: targetClaimToken } : {}),
          };
        }
        outboxStore.put(existing);
      }
    );
    if (explicitAbortError) throw explicitAbortError;
    return;
  }

  // Jika tanpa fencing, transaksi single-store [message_outbox]
  await withReadwriteTransaction(BCAT_STORES.MESSAGE_OUTBOX, async (tx, getStore) => {
    const store = getStore(BCAT_STORES.MESSAGE_OUTBOX);
    const req = store.get(canonicalUuid);
    const existing = await idbRequestToPromise<MessageOutboxRecord | undefined>(req);
    if (!existing) {
      throw new BchatPersistenceError(
        `MessageOutbox dengan UUID [${canonicalUuid}] tidak ditemukan.`
      );
    }

    const activeClaimToken = existing.metadata?.claim_token as string | undefined;
    if (existing.status === 'in_transit' || activeClaimToken) {
      if (!targetClaimToken || typeof targetClaimToken !== 'string' || targetClaimToken.trim() === '') {
        throw new BchatPersistenceError(
          `Update status outbox [${canonicalUuid}] ditolak: record sedang berstatus '${existing.status}' dengan klaim aktif, 'claimToken' wajib disertakan.`
        );
      }

      if (activeClaimToken && targetClaimToken !== activeClaimToken) {
        throw new BchatPersistenceError(
          `Update status outbox [${canonicalUuid}] ditolak: claim token tidak cocok (aktif: '${activeClaimToken}', pemanggil: '${targetClaimToken}'). Klaim telah diambil alih atau kedaluwarsa.`
        );
      }
    }

    // Terminal ACK protection: status acked tidak boleh mundur ke status apa pun
    if (existing.status === 'acked' && targetStatus !== 'acked') {
      return;
    }
    existing.status = targetStatus;
    if (targetSentAt !== undefined) {
      existing.sent_at = targetSentAt;
    }
    if (targetStatus === 'in_transit') {
      existing.retry_count = (existing.retry_count ?? 0) + 1;
    }
    if (targetErrorMessage !== undefined) {
      existing.metadata = {
        ...(existing.metadata ?? {}),
        error_message: targetErrorMessage,
      };
    }
    if (targetStatus === 'acked') {
      existing.metadata = {
        ...(existing.metadata ?? {}),
        acked_at: Date.now(),
        ...(targetClaimToken ? { acked_by_token: targetClaimToken } : {}),
      };
    }
    store.put(existing);
  });
}

/**
 * Mengklaim pesan dari message_outbox secara atomik dengan validasi lease fencing dan dependensi Commit -> Welcome.
 * Transaksi mencakup multi-store [tab_leases, message_outbox].
 * Menegakkan:
 * 1. Fencing lease aktif caller.
 * 2. Dependensi state machine wajib (Welcome hanya boleh diklaim jika Commit pasangannya sudah acked dengan operation_id sama).
 * 3. CAS konkurensi: pesan sedang in_transit ditolak klaim keduanya (CLAIM_CONFLICT) sebelum timeout.
 * 4. Pembangkitan Claim Token acak kriptografis unik untuk setiap klaim baru.
 * 5. Terminal ACK: pesan acked tidak dapat diklaim kembali.
 */
export async function claimOutboxMessage(
  params: ClaimOutboxMessageParams
): Promise<ClaimOutboxMessageResult> {
  if (!params) {
    throw new BchatPersistenceError("Parameter 'params' wajib disertakan.");
  }
  const canonicalDeviceUuid = normalizeCanonicalUuid(params.deviceUuid, 'deviceUuid');
  const canonicalClientMessageUuid = normalizeCanonicalUuid(params.clientMessageUuid, 'clientMessageUuid');

  if (!params.fencing || typeof params.fencing.ownerToken !== 'string' || params.fencing.ownerToken.trim() === '') {
    throw new BchatPersistenceError('Fencing owner token wajib berupa string non-kosong.');
  }

  if (typeof params.fencing.generation !== 'string' || !DECIMAL_STRING_REGEX.test(params.fencing.generation)) {
    throw new BchatPersistenceError(
      `Fencing generation '${params.fencing?.generation}' tidak valid. Wajib berupa decimal string non-negatif.`
    );
  }

  const now = Date.now();
  let explicitAbortError: BchatPersistenceError | null = null;
  let claimedRecord: MessageOutboxRecord | null = null;
  let generatedClaimToken = '';

  try {
    await withReadwriteTransaction(
      [BCAT_STORES.TAB_LEASES, BCAT_STORES.MESSAGE_OUTBOX],
      async (tx, getStore) => {
        const leaseStore = getStore(BCAT_STORES.TAB_LEASES);
        const outboxStore = getStore(BCAT_STORES.MESSAGE_OUTBOX);

        // A. Validasi Fencing Lease Caller
        const leaseReq = leaseStore.get(canonicalDeviceUuid);
        const lease = await idbRequestToPromise<TabLeaseRecord | undefined>(leaseReq);

        if (!lease) {
          explicitAbortError = new BchatFencingTokenStaleError(
            `Tidak ditemukan tab lease aktif untuk perangkat [${canonicalDeviceUuid}]. Klaim ditolak.`
          );
          tx.abort();
          throw explicitAbortError;
        }

        if (lease.owner_token !== params.fencing.ownerToken) {
          explicitAbortError = new BchatFencingTokenStaleError(
            `Fencing owner token mismatch: lease aktif milik '${lease.owner_token}', namun pemanggil adalah '${params.fencing.ownerToken}'. Klaim ditolak.`
          );
          tx.abort();
          throw explicitAbortError;
        }

        const leaseGenStr = lease.generation;
        if (typeof leaseGenStr !== 'string' || !DECIMAL_STRING_REGEX.test(leaseGenStr)) {
          explicitAbortError = new BchatFencingTokenStaleError(
            `Generasi lease tersimpan '${leaseGenStr}' bukan decimal string valid.`
          );
          tx.abort();
          throw explicitAbortError;
        }

        const callerGenBigInt = BigInt(params.fencing.generation);
        const activeGenBigInt = BigInt(leaseGenStr);

        if (callerGenBigInt !== activeGenBigInt) {
          explicitAbortError = new BchatFencingTokenStaleError(
            `Fencing generation stale: generasi aktif pada disk adalah '${leaseGenStr}', namun pemanggil membawa '${params.fencing.generation}'. Klaim ditolak.`
          );
          tx.abort();
          throw explicitAbortError;
        }

        if (typeof lease.expires_at === 'number' && now > lease.expires_at) {
          explicitAbortError = new BchatFencingTokenStaleError(
            `Tab lease untuk perangkat [${canonicalDeviceUuid}] telah kedaluwarsa. Operasi ditolak.`
          );
          tx.abort();
          throw explicitAbortError;
        }

        // B. Ambil Rekaman Outbox
        const outboxReq = outboxStore.get(canonicalClientMessageUuid);
        const record = await idbRequestToPromise<MessageOutboxRecord | undefined>(outboxReq);

        if (!record) {
          explicitAbortError = new BchatPersistenceError(
            `MessageOutbox dengan UUID [${canonicalClientMessageUuid}] tidak ditemukan.`
          );
          tx.abort();
          throw explicitAbortError;
        }

        // Validasi Room UUID jika disediakan
        if (params.roomUuid) {
          const canonicalRoomUuid = normalizeCanonicalUuid(params.roomUuid, 'roomUuid');
          if (record.room_uuid !== canonicalRoomUuid) {
            explicitAbortError = new BchatPersistenceError(
              `Room UUID mismatch: pesan milik room [${record.room_uuid}], pemanggil mengekspektasikan [${canonicalRoomUuid}].`
            );
            tx.abort();
            throw explicitAbortError;
          }
        }

        // C. Validasi Terminal ACK
        if (record.status === 'acked') {
          explicitAbortError = new BchatPersistenceError(
            `Pesan outbox [${canonicalClientMessageUuid}] sudah berstatus 'acked' (terminal). Klaim ditolak.`
          );
          tx.abort();
          throw explicitAbortError;
        }

        // D. Validasi Dependensi State Machine Commit -> Welcome Wajib (Koreksi C)
        const msgType = record.metadata?.message_type as string | undefined;
        if (msgType === 'welcome') {
          const dependsOnUuid = record.metadata?.depends_on_uuid;
          if (!dependsOnUuid || typeof dependsOnUuid !== 'string' || dependsOnUuid.trim() === '') {
            explicitAbortError = new BchatPersistenceError(
              `Klaim Welcome [${canonicalClientMessageUuid}] ditolak: metadata 'depends_on_uuid' hilang atau kosong.`
            );
            tx.abort();
            throw explicitAbortError;
          }

          let canonicalDependsOnUuid: string;
          try {
            canonicalDependsOnUuid = normalizeCanonicalUuid(dependsOnUuid, 'depends_on_uuid');
          } catch (e: any) {
            explicitAbortError = new BchatPersistenceError(
              `Klaim Welcome [${canonicalClientMessageUuid}] ditolak: format depends_on_uuid tidak valid: ${e?.message}`
            );
            tx.abort();
            throw explicitAbortError;
          }

          const depReq = outboxStore.get(canonicalDependsOnUuid);
          const depRecord = await idbRequestToPromise<MessageOutboxRecord | undefined>(depReq);

          if (!depRecord) {
            explicitAbortError = new BchatPersistenceError(
              `Klaim Welcome [${canonicalClientMessageUuid}] ditolak: Record dependensi Commit [${canonicalDependsOnUuid}] tidak ditemukan.`
            );
            tx.abort();
            throw explicitAbortError;
          }

          if (depRecord.metadata?.message_type !== 'commit') {
            explicitAbortError = new BchatPersistenceError(
              `Klaim Welcome [${canonicalClientMessageUuid}] ditolak: Record dependensi [${canonicalDependsOnUuid}] bukan bertipe 'commit' (tipe saat ini: '${depRecord.metadata?.message_type}').`
            );
            tx.abort();
            throw explicitAbortError;
          }

          const welcomeOpId = record.metadata?.operation_id;
          const commitOpId = depRecord.metadata?.operation_id;
          if (
            !welcomeOpId ||
            !commitOpId ||
            typeof welcomeOpId !== 'string' ||
            typeof commitOpId !== 'string' ||
            welcomeOpId.trim() === '' ||
            welcomeOpId !== commitOpId
          ) {
            explicitAbortError = new BchatPersistenceError(
              `Klaim Welcome [${canonicalClientMessageUuid}] ditolak: operation_id tidak cocok atau tidak valid (Welcome: '${welcomeOpId}', Commit: '${commitOpId}').`
            );
            tx.abort();
            throw explicitAbortError;
          }

          if (depRecord.room_uuid !== record.room_uuid) {
            explicitAbortError = new BchatPersistenceError(
              `Klaim Welcome [${canonicalClientMessageUuid}] ditolak: room_uuid Commit [${depRecord.room_uuid}] tidak cocok dengan Welcome [${record.room_uuid}].`
            );
            tx.abort();
            throw explicitAbortError;
          }

          if (params.roomUuid) {
            const canonicalParamRoom = normalizeCanonicalUuid(params.roomUuid, 'roomUuid');
            if (record.room_uuid !== canonicalParamRoom) {
              explicitAbortError = new BchatPersistenceError(
                `Klaim Welcome [${canonicalClientMessageUuid}] ditolak: room_uuid Welcome [${record.room_uuid}] tidak cocok dengan roomUuid pemanggil [${canonicalParamRoom}].`
              );
              tx.abort();
              throw explicitAbortError;
            }
          }

          if (depRecord.status !== 'acked') {
            explicitAbortError = new BchatPersistenceError(
              `Klaim Welcome [${canonicalClientMessageUuid}] ditolak: Commit dependensi [${canonicalDependsOnUuid}] belum berstatus 'acked' (status saat ini: '${depRecord.status}').`
            );
            tx.abort();
            throw explicitAbortError;
          }
        }

        // E. Pemeriksaan Status Sebelumnya (CAS) & Pencegahan Klaim Duplikat
        if (record.status === 'in_transit') {
          const claimedBy = record.metadata?.claimed_by as {
            owner_token?: string;
            generation?: string;
            claim_token?: string;
            claimed_at?: number;
          } | undefined;

          const timeoutMs = params.claimTimeoutMs ?? 30000;
          const isExpired = claimedBy?.claimed_at ? (now - claimedBy.claimed_at > timeoutMs) : false;

          if (!isExpired) {
            // Klaim aktif masih berjalan, tolak klaim kedua!
            explicitAbortError = new BchatPersistenceError(
              `Pesan outbox [${canonicalClientMessageUuid}] sedang dalam status in_transit oleh ${
                claimedBy?.owner_token === params.fencing.ownerToken ? 'tab ini' : 'tab lain'
              } (CLAIM_CONFLICT).`
            );
            tx.abort();
            throw explicitAbortError;
          }
          // Jika sudah expired, izinkan takeover claim dengan claim token baru oleh lease owner yang sah
        }

        // F. Bangkitkan Claim Token Kriptografis Unik & Mutasi Atomik ke in_transit (Koreksi A & H-I3B.2R.2R)
        let claimToken: string;
        try {
          claimToken = `clm_${generateCryptoRandomUuid()}`;
        } catch (csprngErr: any) {
          explicitAbortError = new BchatPersistenceError(
            `Klaim outbox [${canonicalClientMessageUuid}] gagal: CSPRNG Web Crypto tidak tersedia untuk claim token (fail-closed): ${
              csprngErr?.message || csprngErr
            }`,
            csprngErr
          );
          tx.abort();
          throw explicitAbortError;
        }

        record.status = 'in_transit';
        record.sent_at = now;
        record.retry_count = (record.retry_count ?? 0) + 1; // HANYA bertambah saat claim berhasil!
        record.metadata = {
          ...(record.metadata ?? {}),
          claim_token: claimToken,
          claimed_by: {
            claim_token: claimToken,
            owner_token: params.fencing.ownerToken,
            generation: params.fencing.generation,
            claimed_at: now,
          },
        };

        outboxStore.put(record);
        claimedRecord = record;
        generatedClaimToken = claimToken;
      }
    );
  } catch (err: unknown) {
    if (explicitAbortError) {
      throw explicitAbortError;
    }
    const orig = (err as any)?.originalError ?? (err as any)?.cause;
    if (orig instanceof BchatPersistenceError) {
      throw orig;
    }
    if (err instanceof BchatPersistenceError) {
      throw err;
    }
    throw new BchatPersistenceError(
      `claimOutboxMessage gagal: ${(err as Error)?.message || 'Unknown error'}`,
      err
    );
  }

  return {
    success: true,
    claimToken: generatedClaimToken,
    outboxRecord: claimedRecord!,
  };
}

/**
 * Mengambil seluruh rekaman outbox milik room tertentu (opsional berdasarkan status).
 */
export async function getOutboxMessagesByRoom(
  roomUuid: string,
  status?: MessageOutboxRecord['status']
): Promise<MessageOutboxRecord[]> {
  const canonicalRoomUuid = normalizeCanonicalUuid(roomUuid, 'roomUuid');
  return withReadwriteTransaction(BCAT_STORES.MESSAGE_OUTBOX, async (_tx, getStore) => {
    const store = getStore(BCAT_STORES.MESSAGE_OUTBOX);
    const allRecordsReq = store.getAll();
    const allRecords = await idbRequestToPromise<MessageOutboxRecord[]>(allRecordsReq);
    return allRecords.filter((rec) => {
      if (rec.room_uuid !== canonicalRoomUuid) return false;
      if (status && rec.status !== status) return false;
      return true;
    });
  });
}

/**
 * Menyimpan PrivateKeyPackage terenkripsi secara durable ke store pending_key_packages.
 * Serialisasi TLS RFC 9420 (§13) dan enkripsi WebCrypto dieksekusi LENGKAP di luar transaksi IDB.
 */
export async function savePendingKeyPackage(
  params: SavePendingKeyPackageParams
): Promise<void> {
  if (!params) {
    throw new BchatPersistenceError("Parameter 'params' wajib disertakan.");
  }
  const canonicalDeviceUuid = normalizeCanonicalUuid(params.deviceUuid, 'deviceUuid');
  if (!params.keyPackageHash || typeof params.keyPackageHash !== 'string') {
    throw new BchatPersistenceError("Parameter 'keyPackageHash' wajib berupa string.");
  }
  if (!params.privateKeyPackage || typeof params.privateKeyPackage !== 'object') {
    throw new BchatPersistenceError("Parameter 'privateKeyPackage' wajib disertakan.");
  }

  // 1. Serialisasi TLS PrivateKeyPackage di luar transaksi IDB
  const encodedPrivateKeyBytes = encode(privateKeyPackageEncoder, params.privateKeyPackage);

  // 2. Bangun konteks AAD dan enkripsi ke amplop terotentikasi di luar transaksi IDB
  const envelope = await encryptPayloadToEnvelope(encodedPrivateKeyBytes, {
    deviceUuid: canonicalDeviceUuid,
    roomUuid: null,
    storeName: BCAT_STORES.PENDING_KEY_PACKAGES,
    recordType: 'private_key_package',
    recordKey: [canonicalDeviceUuid, params.keyPackageHash],
  });

  // 3. Simpan record durable ke pending_key_packages (dengan validasi fencing atomik jika disertakan)
  const record: PendingKeyPackageRecord = {
    device_uuid: canonicalDeviceUuid,
    key_package_hash: params.keyPackageHash,
    private_key_package_bytes: envelope.ciphertext,
    public_key_package_bytes: params.publicKeyPackageBytes,
    created_at: Date.now(),
    metadata: {
      ...(params.metadata ?? {}),
      status: 'pending',
      publication_status: 'local_only',
      envelope,
    },
  };

  if (params.fencing) {
    const fencing = params.fencing;
    if (typeof fencing.ownerToken !== 'string' || fencing.ownerToken.trim() === '') {
      throw new BchatPersistenceError('Fencing owner token wajib berupa string non-kosong.');
    }
    if (typeof fencing.generation !== 'string' || !DECIMAL_STRING_REGEX.test(fencing.generation)) {
      throw new BchatPersistenceError(
        `Fencing generation '${fencing.generation}' tidak valid. Wajib berupa decimal string non-negatif.`
      );
    }

    const now = Date.now();
    let explicitAbortError: BchatPersistenceError | null = null;
    try {
      await withReadwriteTransaction(
        [BCAT_STORES.TAB_LEASES, BCAT_STORES.PENDING_KEY_PACKAGES],
        async (tx, getStore) => {
          const leaseStore = getStore(BCAT_STORES.TAB_LEASES);
          const pendingStore = getStore(BCAT_STORES.PENDING_KEY_PACKAGES);

          const leaseReq = leaseStore.get(canonicalDeviceUuid);
          const lease = await idbRequestToPromise<TabLeaseRecord | undefined>(leaseReq);

          if (!lease) {
            explicitAbortError = new BchatFencingTokenStaleError(
              `Tidak ditemukan tab lease aktif untuk perangkat [${canonicalDeviceUuid}]. Penyimpanan KeyPackage ditolak.`
            );
            tx.abort();
            throw explicitAbortError;
          }

          if (lease.owner_token !== fencing.ownerToken) {
            explicitAbortError = new BchatFencingTokenStaleError(
              `Fencing owner token mismatch: lease aktif milik '${lease.owner_token}', namun pemanggil adalah '${fencing.ownerToken}'. Penyimpanan KeyPackage ditolak.`
            );
            tx.abort();
            throw explicitAbortError;
          }

          if (typeof lease.generation !== 'string' || !DECIMAL_STRING_REGEX.test(lease.generation)) {
            explicitAbortError = new BchatFencingTokenStaleError(
              `Generasi lease tersimpan '${lease.generation}' bukan decimal string valid.`
            );
            tx.abort();
            throw explicitAbortError;
          }

          if (BigInt(fencing.generation) !== BigInt(lease.generation)) {
            explicitAbortError = new BchatFencingTokenStaleError(
              `Fencing generation stale: generasi aktif pada disk adalah '${lease.generation}', namun pemanggil membawa '${fencing.generation}'. Penyimpanan KeyPackage ditolak.`
            );
            tx.abort();
            throw explicitAbortError;
          }

          if (typeof lease.expires_at === 'number' && now > lease.expires_at) {
            explicitAbortError = new BchatFencingTokenStaleError(
              `Tab lease untuk perangkat [${canonicalDeviceUuid}] telah kedaluwarsa. Operasi ditolak.`
            );
            tx.abort();
            throw explicitAbortError;
          }

          pendingStore.put(record);
        }
      );
    } catch (err: unknown) {
      if (explicitAbortError) throw explicitAbortError;
      throw err;
    }
  } else {
    await idbPut(BCAT_STORES.PENDING_KEY_PACKAGES, record);
  }
}

/**
 * Membaca dan mendekripsi PrivateKeyPackage tersimpan dari store pending_key_packages.
 * Mengembalikan objek privatePackage & publicPackage (jika ada) serta status record.
 */
export async function getPendingPrivateKeyPackage(
  deviceUuid: string,
  keyPackageHash: string
): Promise<LoadedPendingKeyPackage | undefined> {
  const canonicalDeviceUuid = normalizeCanonicalUuid(deviceUuid, 'deviceUuid');
  const record = await idbGet<PendingKeyPackageRecord>(
    BCAT_STORES.PENDING_KEY_PACKAGES,
    [canonicalDeviceUuid, keyPackageHash]
  );
  if (!record) {
    return undefined;
  }

  const status = (record.metadata?.status as 'pending' | 'consumed') ?? 'pending';
  const publicationStatus = (record.metadata?.publication_status as KeyPackagePublicationStatus) ?? 'local_only';

  // Jika record tidak memiliki envelope terotentikasi, tidak dapat didekripsi
  const envelope = record.metadata?.envelope as BchatEncryptedEnvelope | undefined;
  if (!envelope) {
    throw new BchatPersistenceError(
      `Pending KeyPackage [${keyPackageHash}] tidak memiliki envelope terotentikasi.`
    );
  }

  // Dekripsi amplop di luar transaksi IDB
  const decryptedBytes = await decryptPayloadFromEnvelope(envelope, {
    deviceUuid: canonicalDeviceUuid,
    roomUuid: null,
    storeName: BCAT_STORES.PENDING_KEY_PACKAGES,
    recordType: 'private_key_package',
    recordKey: [canonicalDeviceUuid, keyPackageHash],
  });

  const privatePackage = decode(privateKeyPackageDecoder, decryptedBytes);
  if (!privatePackage) {
    throw new BchatCodecError(
      `Gagal mendekode PrivateKeyPackage dari byte terdekripsi untuk hash [${keyPackageHash}].`
    );
  }

  let publicPackage: KeyPackage | undefined;
  if (record.public_key_package_bytes && record.public_key_package_bytes.byteLength > 0) {
    publicPackage = decode(keyPackageDecoder, record.public_key_package_bytes);
  }

  return {
    privatePackage,
    publicPackage,
    status,
    publicationStatus,
  };
}

/**
 * Membaca tab lease aktif untuk perangkat tertentu dari IndexedDB.
 */
export async function getActiveTabLease(
  deviceUuid: string
): Promise<TabLeaseRecord | undefined> {
  const canonicalDeviceUuid = normalizeCanonicalUuid(deviceUuid, 'deviceUuid');
  return idbGet<TabLeaseRecord>(BCAT_STORES.TAB_LEASES, canonicalDeviceUuid);
}

export interface UpdateKeyPackagePublicationParams {
  deviceUuid: string;
  keyPackageHashes: string[];
  publicationStatus: KeyPackagePublicationStatus;
  fencing: FencingCredentials;
  timestamp?: number;
  activePoolCount?: number;
  errorMessage?: string | null;
}

/**
 * Memperbarui metadata status publikasi KeyPackage secara durabel dan atomik di IndexedDB.
 *
 * Invarian Keamanan:
 * 1. Menjaga integritas lease fencing: generasi, owner_token, dan waktu kedaluwarsa divalidasi
 *    secara atomik di dalam transaksi IndexedDB readwrite multi-store [tab_leases, pending_key_packages].
 * 2. Hanya memperbarui record yang cocok dengan device_uuid dan key_package_hash.
 * 3. Status kriptografis (pending / consumed) TIDAK PERNAH diubah oleh fungsi ini.
 * 4. Kunci privat tidak didekripsi dan envelope ciphertext tidak dimodifikasi.
 * 5. Record tidak dihapus dalam skenario kegagalan apa pun.
 */
export async function updateKeyPackagePublicationStatus(
  params: UpdateKeyPackagePublicationParams
): Promise<void> {
  if (!params) {
    throw new BchatPersistenceError("Parameter 'params' wajib disertakan.");
  }
  const canonicalDeviceUuid = normalizeCanonicalUuid(params.deviceUuid, 'deviceUuid');

  if (!Array.isArray(params.keyPackageHashes) || params.keyPackageHashes.length === 0) {
    throw new BchatPersistenceError("Parameter 'keyPackageHashes' wajib berupa array non-kosong.");
  }

  const validStatuses: KeyPackagePublicationStatus[] = [
    'local_only',
    'uploading',
    'published',
    'unknown',
    'rejected',
  ];
  if (!validStatuses.includes(params.publicationStatus)) {
    throw new BchatPersistenceError(
      `Status publikasi '${params.publicationStatus}' tidak valid. Wajib salah satu dari: ${validStatuses.join(', ')}.`
    );
  }

  if (!params.fencing) {
    throw new BchatPersistenceError("Parameter 'fencing' wajib disertakan untuk pembaruan status publikasi.");
  }
  const fencing = params.fencing;
  if (typeof fencing.ownerToken !== 'string' || fencing.ownerToken.trim() === '') {
    throw new BchatPersistenceError('Fencing owner token wajib berupa string non-kosong.');
  }
  if (typeof fencing.generation !== 'string' || !DECIMAL_STRING_REGEX.test(fencing.generation)) {
    throw new BchatPersistenceError(
      `Fencing generation '${fencing.generation}' tidak valid. Wajib berupa decimal string non-negatif.`
    );
  }

  const now = params.timestamp ?? Date.now();
  let explicitAbortError: BchatPersistenceError | null = null;

  try {
    await withReadwriteTransaction(
      [BCAT_STORES.TAB_LEASES, BCAT_STORES.PENDING_KEY_PACKAGES],
      async (tx, getStore) => {
        const leaseStore = getStore(BCAT_STORES.TAB_LEASES);
        const pendingStore = getStore(BCAT_STORES.PENDING_KEY_PACKAGES);

        // 1. Verifikasi tab lease fencing
        const leaseReq = leaseStore.get(canonicalDeviceUuid);
        const lease = await idbRequestToPromise<TabLeaseRecord | undefined>(leaseReq);

        if (!lease) {
          explicitAbortError = new BchatFencingTokenStaleError(
            `Tidak ditemukan tab lease aktif untuk perangkat [${canonicalDeviceUuid}]. Pembaruan status publikasi ditolak.`
          );
          tx.abort();
          throw explicitAbortError;
        }

        if (lease.owner_token !== fencing.ownerToken) {
          explicitAbortError = new BchatFencingTokenStaleError(
            `Fencing owner token mismatch: lease aktif milik '${lease.owner_token}', namun pemanggil adalah '${fencing.ownerToken}'. Pembaruan status publikasi ditolak.`
          );
          tx.abort();
          throw explicitAbortError;
        }

        if (typeof lease.generation !== 'string' || !DECIMAL_STRING_REGEX.test(lease.generation)) {
          explicitAbortError = new BchatFencingTokenStaleError(
            `Generasi lease tersimpan '${lease.generation}' bukan decimal string valid.`
          );
          tx.abort();
          throw explicitAbortError;
        }

        if (BigInt(fencing.generation) !== BigInt(lease.generation)) {
          explicitAbortError = new BchatFencingTokenStaleError(
            `Fencing generation stale: generasi aktif pada disk adalah '${lease.generation}', namun pemanggil membawa '${fencing.generation}'. Pembaruan status publikasi ditolak.`
          );
          tx.abort();
          throw explicitAbortError;
        }

        if (typeof lease.expires_at === 'number' && now > lease.expires_at) {
          explicitAbortError = new BchatFencingTokenStaleError(
            `Tab lease untuk perangkat [${canonicalDeviceUuid}] telah kedaluwarsa. Operasi ditolak.`
          );
          tx.abort();
          throw explicitAbortError;
        }

        // 2. Perbarui seluruh paket dalam batch
        for (const rawHash of params.keyPackageHashes) {
          if (!rawHash || typeof rawHash !== 'string' || rawHash.trim() === '') {
            explicitAbortError = new BchatPersistenceError('Hash KeyPackage tidak valid dalam daftar pembaruan.');
            tx.abort();
            throw explicitAbortError;
          }
          const cleanHash = rawHash.trim().toLowerCase();

          const pendingReq = pendingStore.get([canonicalDeviceUuid, cleanHash]);
          const record = await idbRequestToPromise<PendingKeyPackageRecord | undefined>(pendingReq);

          if (!record) {
            explicitAbortError = new BchatPersistenceError(
              `Pending KeyPackage dengan hash [${cleanHash}] tidak ditemukan untuk perangkat [${canonicalDeviceUuid}].`
            );
            tx.abort();
            throw explicitAbortError;
          }

          if (record.device_uuid !== canonicalDeviceUuid) {
            explicitAbortError = new BchatPersistenceError(
              `Device UUID pada record [${record.device_uuid}] tidak cocok dengan target pembaruan [${canonicalDeviceUuid}].`
            );
            tx.abort();
            throw explicitAbortError;
          }

          // INVARIAN KETAT: Status kriptografis (pending / consumed) TIDAK BOLEH BERUBAH!
          const currentCryptoStatus = record.metadata?.status ?? 'pending';

          record.metadata = {
            ...(record.metadata ?? {}),
            status: currentCryptoStatus,
            publication_status: params.publicationStatus,
            updated_at: now,
            ...(params.publicationStatus === 'published' ? { published_at: now } : {}),
            ...(params.activePoolCount !== undefined ? { active_pool_count: params.activePoolCount } : {}),
            ...(params.errorMessage !== undefined ? { publication_error: params.errorMessage } : {}),
          };

          pendingStore.put(record);
        }
      }
    );
  } catch (err: unknown) {
    if (explicitAbortError) throw explicitAbortError;
    throw err;
  }
}

/**
 * Menyimpan GroupState hasil JOIN_WELCOME dan menandai status PrivateKeyPackage menjadi 'consumed'
 * dalam SATU transaksi atomik IndexedDB multi-store [tab_leases, group_states, pending_key_packages].
 */
export async function joinGroupAndConsumePackage(
  params: JoinGroupAndConsumeParams
): Promise<JoinGroupAndConsumeResult> {
  if (!params) {
    throw new BchatPersistenceError("Parameter 'params' wajib disertakan.");
  }
  const canonicalDeviceUuid = normalizeCanonicalUuid(params.deviceUuid, 'deviceUuid');
  const canonicalRoomUuid = normalizeCanonicalUuid(params.roomUuid, 'roomUuid');

  if (!params.keyPackageHash || typeof params.keyPackageHash !== 'string') {
    throw new BchatPersistenceError("Parameter 'keyPackageHash' wajib berupa string.");
  }

  if (!params.fencing || typeof params.fencing.ownerToken !== 'string' || params.fencing.ownerToken.trim() === '') {
    throw new BchatPersistenceError('Fencing owner token wajib berupa string non-kosong.');
  }

  if (typeof params.fencing.generation !== 'string' || !DECIMAL_STRING_REGEX.test(params.fencing.generation)) {
    throw new BchatPersistenceError(
      `Fencing generation '${params.fencing?.generation}' tidak valid. Wajib berupa decimal string non-negatif.`
    );
  }

  if (params.expectedPreviousRevision !== null) {
    if (
      typeof params.expectedPreviousRevision !== 'string' ||
      !DECIMAL_STRING_REGEX.test(params.expectedPreviousRevision)
    ) {
      throw new BchatPersistenceError(
        `expectedPreviousRevision '${params.expectedPreviousRevision}' tidak valid. Wajib bernilai null atau decimal string.`
      );
    }
  }

  if (!params.state || typeof params.state !== 'object' || !params.state.groupContext) {
    throw new BchatPersistenceError('ClientState tidak valid atau tidak memiliki GroupContext.');
  }

  if (!(params.state.groupContext.groupId instanceof Uint8Array)) {
    throw new BchatPersistenceError('GroupContext groupId bukan instance Uint8Array valid.');
  }

  const groupIdHash = await computeSha256Hex(params.state.groupContext.groupId);
  const epochBigInt = BigInt(params.state.groupContext.epoch);
  const epochStr = epochBigInt.toString();
  if (!DECIMAL_STRING_REGEX.test(epochStr)) {
    throw new BchatPersistenceError('Epoch ClientState tidak menghasilkan decimal string valid.');
  }

  const ciphersuiteId = params.ciphersuiteId ?? DEFAULT_CIPHERSUITE_ID;

  // 1. Serialisasi ClientState ke TLS RFC 9420 (§13) & Enkripsi ke amplop di luar transaksi IDB
  const stateBytes = encodeClientState(params.state);
  const envelope = await encryptPayloadToEnvelope(stateBytes, {
    deviceUuid: canonicalDeviceUuid,
    roomUuid: canonicalRoomUuid,
    storeName: BCAT_STORES.GROUP_STATES,
    recordType: 'client_state',
    recordKey: [canonicalDeviceUuid, canonicalRoomUuid],
  });

  // 2. Transaksi Atomik Multi-Store [tab_leases, group_states, pending_key_packages]
  let newRevision = '1';
  let previousRevision: string | null = null;
  const now = Date.now();
  let explicitAbortError: BchatPersistenceError | null = null;

  try {
    await withReadwriteTransaction(
      [BCAT_STORES.TAB_LEASES, BCAT_STORES.GROUP_STATES, BCAT_STORES.PENDING_KEY_PACKAGES],
      async (tx, getStore) => {
        const leaseStore = getStore(BCAT_STORES.TAB_LEASES);
        const groupStore = getStore(BCAT_STORES.GROUP_STATES);
        const pendingStore = getStore(BCAT_STORES.PENDING_KEY_PACKAGES);

        // A. Validasi Fencing Lease
        const leaseReq = leaseStore.get(canonicalDeviceUuid);
        const lease = await idbRequestToPromise<TabLeaseRecord | undefined>(leaseReq);

        if (!lease) {
          explicitAbortError = new BchatFencingTokenStaleError(
            `Tidak ditemukan tab lease aktif untuk perangkat [${canonicalDeviceUuid}]. Penulisan ditolak.`
          );
          tx.abort();
          throw explicitAbortError;
        }

        if (lease.owner_token !== params.fencing.ownerToken) {
          explicitAbortError = new BchatFencingTokenStaleError(
            `Fencing owner token mismatch: lease aktif milik '${lease.owner_token}', namun pemanggil adalah '${params.fencing.ownerToken}'. Penulisan ditolak.`
          );
          tx.abort();
          throw explicitAbortError;
        }

        const leaseGenStr = lease.generation;
        if (typeof leaseGenStr !== 'string' || !DECIMAL_STRING_REGEX.test(leaseGenStr)) {
          explicitAbortError = new BchatFencingTokenStaleError(
            `Generasi lease tersimpan '${leaseGenStr}' bukan decimal string valid.`
          );
          tx.abort();
          throw explicitAbortError;
        }

        const callerGenBigInt = BigInt(params.fencing.generation);
        const activeGenBigInt = BigInt(leaseGenStr);

        if (callerGenBigInt !== activeGenBigInt) {
          explicitAbortError = new BchatFencingTokenStaleError(
            `Fencing generation stale: generasi aktif pada disk adalah '${leaseGenStr}', namun pemanggil membawa '${params.fencing.generation}'. Penulisan ditolak.`
          );
          tx.abort();
          throw explicitAbortError;
        }

        if (typeof lease.expires_at === 'number' && now > lease.expires_at) {
          explicitAbortError = new BchatFencingTokenStaleError(
            `Tab lease untuk perangkat [${canonicalDeviceUuid}] telah kedaluwarsa. Operasi ditolak.`
          );
          tx.abort();
          throw explicitAbortError;
        }

        // B. Validasi Pending Key Package (Must exist and not consumed)
        const pendingReq = pendingStore.get([canonicalDeviceUuid, params.keyPackageHash]);
        const pendingRec = await idbRequestToPromise<PendingKeyPackageRecord | undefined>(pendingReq);

        if (!pendingRec) {
          explicitAbortError = new BchatPersistenceError(
            `Pending KeyPackage dengan hash [${params.keyPackageHash}] tidak ditemukan pada disk. Join ditolak.`
          );
          tx.abort();
          throw explicitAbortError;
        }

        if (pendingRec.metadata?.status === 'consumed') {
          explicitAbortError = new BchatPersistenceError(
            `Pending KeyPackage dengan hash [${params.keyPackageHash}] sudah pernah dikonsumsi (CONSUMED). Anti-reuse triggered.`
          );
          tx.abort();
          throw explicitAbortError;
        }

        // C. Validasi GroupState
        const groupReq = groupStore.get([canonicalDeviceUuid, canonicalRoomUuid]);
        const existing = await idbRequestToPromise<GroupStateRecord | undefined>(groupReq);

        if (existing && existing.status === 'quarantined') {
          explicitAbortError = new BchatQuarantinedStateError(
            `Group state untuk room [${canonicalRoomUuid}] sedang berstatus QUARANTINED. Tidak boleh ditimpa.`
          );
          tx.abort();
          throw explicitAbortError;
        }

        if (existing) {
          if (existing.state_revision !== params.expectedPreviousRevision) {
            explicitAbortError = new BchatRevisionConflictError(
              `Benturan revisi: revisi saat ini pada disk adalah '${existing.state_revision}', namun pemanggil mengekspektasikan '${params.expectedPreviousRevision}'.`
            );
            tx.abort();
            throw explicitAbortError;
          }

          const storedEpochBigInt = BigInt(existing.epoch);
          if (epochBigInt < storedEpochBigInt) {
            explicitAbortError = new BchatEpochRegressionError(
              `Regresi epoch ditolak: epoch kandidat '${epochStr}' lebih rendah dari epoch tersimpan '${existing.epoch}'.`
            );
            tx.abort();
            throw explicitAbortError;
          }

          const prevRevBigInt = BigInt(existing.state_revision);
          newRevision = (prevRevBigInt + BigInt(1)).toString();
          previousRevision = existing.state_revision;
        } else {
          if (params.expectedPreviousRevision !== null) {
            explicitAbortError = new BchatRevisionConflictError(
              `Benturan revisi: record baru tidak memiliki revisi sebelumnya, namun pemanggil mengekspektasikan '${params.expectedPreviousRevision}'.`
            );
            tx.abort();
            throw explicitAbortError;
          }
          newRevision = '1';
          previousRevision = null;
        }

        // D. Tulis GroupState baru
        const newGroupRecord: GroupStateRecord = {
          device_uuid: canonicalDeviceUuid,
          room_uuid: canonicalRoomUuid,
          storage_schema_version: DEFAULT_STORAGE_SCHEMA_VERSION,
          serializer_version: BCAT_CLIENT_STATE_SERIALIZER_VERSION,
          ciphersuite_id: ciphersuiteId,
          group_id_hash: groupIdHash,
          epoch: epochStr,
          state_revision: newRevision,
          previous_revision: previousRevision,
          key_id: envelope.key_id,
          envelope: envelope,
          status: 'active',
          created_at: existing && existing.created_at ? existing.created_at : now,
          updated_at: now,
        };
        groupStore.put(newGroupRecord);

        // E. Tandai Pending KeyPackage sebagai CONSUMED
        pendingRec.metadata = {
          ...(pendingRec.metadata ?? {}),
          status: 'consumed',
          consumed_at: now,
          room_uuid: canonicalRoomUuid,
        };
        pendingStore.put(pendingRec);
      }
    );
  } catch (err: unknown) {
    if (explicitAbortError) {
      throw explicitAbortError;
    }
    const orig = (err as any)?.originalError ?? (err as any)?.cause;
    if (orig instanceof BchatPersistenceError) {
      throw orig;
    }
    if (err instanceof BchatPersistenceError) {
      throw err;
    }
    throw new BchatPersistenceError(
      `joinGroupAndConsumePackage gagal disimpan: ${(err as Error)?.message || 'Unknown error'}`,
      err
    );
  }

  return {
    stateRevision: newRevision,
    previousRevision,
    epoch: epochStr,
    groupIdHash,
    updatedAt: now,
    keyPackageHash: params.keyPackageHash,
  };
}

/**
 * Menyimpan GroupState baru dan antrean publikasi Commit + Welcome dalam SATU transaksi atomik
 * IndexedDB multi-store [tab_leases, group_states, message_outbox].
 */
export async function persistGroupStateAndProtocolOutbox(
  params: PersistCommitProtocolParams
): Promise<PersistCommitProtocolResult> {
  if (!params) {
    throw new BchatPersistenceError("Parameter 'params' wajib disertakan.");
  }
  const canonicalDeviceUuid = normalizeCanonicalUuid(params.deviceUuid, 'deviceUuid');
  const canonicalRoomUuid = normalizeCanonicalUuid(params.roomUuid, 'roomUuid');

  if (!params.commitOutbox || !params.welcomeOutbox) {
    throw new BchatPersistenceError("Parameter 'commitOutbox' dan 'welcomeOutbox' wajib disertakan.");
  }

  const canonicalCommitUuid = normalizeCanonicalUuid(params.commitOutbox.clientMessageUuid, 'commitMessageUuid');
  const canonicalWelcomeUuid = normalizeCanonicalUuid(params.welcomeOutbox.clientMessageUuid, 'welcomeMessageUuid');

  if (canonicalCommitUuid === canonicalWelcomeUuid) {
    throw new BchatPersistenceError('Commit dan Welcome tidak boleh menggunakan client_message_uuid yang sama.');
  }

  if (!(params.commitOutbox.ciphertextBytes instanceof Uint8Array) || params.commitOutbox.ciphertextBytes.byteLength === 0) {
    throw new BchatPersistenceError('commitOutbox.ciphertextBytes wajib berupa Uint8Array non-kosong.');
  }

  if (!(params.welcomeOutbox.ciphertextBytes instanceof Uint8Array) || params.welcomeOutbox.ciphertextBytes.byteLength === 0) {
    throw new BchatPersistenceError('welcomeOutbox.ciphertextBytes wajib berupa Uint8Array non-kosong.');
  }

  if (!params.fencing || typeof params.fencing.ownerToken !== 'string' || params.fencing.ownerToken.trim() === '') {
    throw new BchatPersistenceError('Fencing owner token wajib berupa string non-kosong.');
  }

  if (typeof params.fencing.generation !== 'string' || !DECIMAL_STRING_REGEX.test(params.fencing.generation)) {
    throw new BchatPersistenceError(
      `Fencing generation '${params.fencing?.generation}' tidak valid. Wajib berupa decimal string non-negatif.`
    );
  }

  if (params.expectedPreviousRevision !== null) {
    if (
      typeof params.expectedPreviousRevision !== 'string' ||
      !DECIMAL_STRING_REGEX.test(params.expectedPreviousRevision)
    ) {
      throw new BchatPersistenceError(
        `expectedPreviousRevision '${params.expectedPreviousRevision}' tidak valid. Wajib bernilai null atau decimal string.`
      );
    }
  }

  if (!params.state || typeof params.state !== 'object' || !params.state.groupContext) {
    throw new BchatPersistenceError('ClientState tidak valid atau tidak memiliki GroupContext.');
  }

  if (!(params.state.groupContext.groupId instanceof Uint8Array)) {
    throw new BchatPersistenceError('GroupContext groupId bukan instance Uint8Array valid.');
  }

  const groupIdHash = await computeSha256Hex(params.state.groupContext.groupId);
  const epochBigInt = BigInt(params.state.groupContext.epoch);
  const epochStr = epochBigInt.toString();
  if (!DECIMAL_STRING_REGEX.test(epochStr)) {
    throw new BchatPersistenceError('Epoch ClientState tidak menghasilkan decimal string valid.');
  }

  const ciphersuiteId = params.ciphersuiteId ?? DEFAULT_CIPHERSUITE_ID;

  // 1. Serialisasi & Enkripsi ClientState di luar transaksi IDB
  const stateBytes = encodeClientState(params.state);
  const envelope = await encryptPayloadToEnvelope(stateBytes, {
    deviceUuid: canonicalDeviceUuid,
    roomUuid: canonicalRoomUuid,
    storeName: BCAT_STORES.GROUP_STATES,
    recordType: 'client_state',
    recordKey: [canonicalDeviceUuid, canonicalRoomUuid],
  });

  // 2. Transaksi Atomik Multi-Store [tab_leases, group_states, message_outbox]
  let newRevision = '1';
  let previousRevision: string | null = null;
  const now = Date.now();
  let explicitAbortError: BchatPersistenceError | null = null;

  let commitRecord: MessageOutboxRecord;
  let welcomeRecord: MessageOutboxRecord;

  try {
    await withReadwriteTransaction(
      [BCAT_STORES.TAB_LEASES, BCAT_STORES.GROUP_STATES, BCAT_STORES.MESSAGE_OUTBOX],
      async (tx, getStore) => {
        const leaseStore = getStore(BCAT_STORES.TAB_LEASES);
        const groupStore = getStore(BCAT_STORES.GROUP_STATES);
        const outboxStore = getStore(BCAT_STORES.MESSAGE_OUTBOX);

        // A. Validasi Fencing Lease
        const leaseReq = leaseStore.get(canonicalDeviceUuid);
        const lease = await idbRequestToPromise<TabLeaseRecord | undefined>(leaseReq);

        if (!lease) {
          explicitAbortError = new BchatFencingTokenStaleError(
            `Tidak ditemukan tab lease aktif untuk perangkat [${canonicalDeviceUuid}]. Penulisan ditolak.`
          );
          tx.abort();
          throw explicitAbortError;
        }

        if (lease.owner_token !== params.fencing.ownerToken) {
          explicitAbortError = new BchatFencingTokenStaleError(
            `Fencing owner token mismatch: lease aktif milik '${lease.owner_token}', namun pemanggil adalah '${params.fencing.ownerToken}'. Penulisan ditolak.`
          );
          tx.abort();
          throw explicitAbortError;
        }

        const leaseGenStr = lease.generation;
        if (typeof leaseGenStr !== 'string' || !DECIMAL_STRING_REGEX.test(leaseGenStr)) {
          explicitAbortError = new BchatFencingTokenStaleError(
            `Generasi lease tersimpan '${leaseGenStr}' bukan decimal string valid.`
          );
          tx.abort();
          throw explicitAbortError;
        }

        const callerGenBigInt = BigInt(params.fencing.generation);
        const activeGenBigInt = BigInt(leaseGenStr);

        if (callerGenBigInt !== activeGenBigInt) {
          explicitAbortError = new BchatFencingTokenStaleError(
            `Fencing generation stale: generasi aktif pada disk adalah '${leaseGenStr}', namun pemanggil membawa '${params.fencing.generation}'. Penulisan ditolak.`
          );
          tx.abort();
          throw explicitAbortError;
        }

        if (typeof lease.expires_at === 'number' && now > lease.expires_at) {
          explicitAbortError = new BchatFencingTokenStaleError(
            `Tab lease untuk perangkat [${canonicalDeviceUuid}] telah kedaluwarsa. Operasi ditolak.`
          );
          tx.abort();
          throw explicitAbortError;
        }

        // B. Validasi GroupState
        const groupReq = groupStore.get([canonicalDeviceUuid, canonicalRoomUuid]);
        const existing = await idbRequestToPromise<GroupStateRecord | undefined>(groupReq);

        if (existing && existing.status === 'quarantined') {
          explicitAbortError = new BchatQuarantinedStateError(
            `Group state untuk room [${canonicalRoomUuid}] sedang berstatus QUARANTINED. Tidak boleh ditimpa.`
          );
          tx.abort();
          throw explicitAbortError;
        }

        if (existing) {
          if (existing.state_revision !== params.expectedPreviousRevision) {
            explicitAbortError = new BchatRevisionConflictError(
              `Benturan revisi: revisi saat ini pada disk adalah '${existing.state_revision}', namun pemanggil mengekspektasikan '${params.expectedPreviousRevision}'.`
            );
            tx.abort();
            throw explicitAbortError;
          }

          const storedEpochBigInt = BigInt(existing.epoch);
          if (epochBigInt < storedEpochBigInt) {
            explicitAbortError = new BchatEpochRegressionError(
              `Regresi epoch ditolak: epoch kandidat '${epochStr}' lebih rendah dari epoch tersimpan '${existing.epoch}'.`
            );
            tx.abort();
            throw explicitAbortError;
          }

          const prevRevBigInt = BigInt(existing.state_revision);
          newRevision = (prevRevBigInt + BigInt(1)).toString();
          previousRevision = existing.state_revision;
        } else {
          if (params.expectedPreviousRevision !== null) {
            explicitAbortError = new BchatRevisionConflictError(
              `Benturan revisi: record baru tidak memiliki revisi sebelumnya, namun pemanggil mengekspektasikan '${params.expectedPreviousRevision}'.`
            );
            tx.abort();
            throw explicitAbortError;
          }
          newRevision = '1';
          previousRevision = null;
        }

        // C. Validasi Outbox (Commit dan Welcome tidak boleh sudah ada)
        const commitReq = outboxStore.get(canonicalCommitUuid);
        const existingCommit = await idbRequestToPromise<MessageOutboxRecord | undefined>(commitReq);
        if (existingCommit) {
          explicitAbortError = new BchatPersistenceError(
            `Commit outbox dengan UUID [${canonicalCommitUuid}] sudah ada. Duplikasi ditolak.`
          );
          tx.abort();
          throw explicitAbortError;
        }

        const welcomeReq = outboxStore.get(canonicalWelcomeUuid);
        const existingWelcome = await idbRequestToPromise<MessageOutboxRecord | undefined>(welcomeReq);
        if (existingWelcome) {
          explicitAbortError = new BchatPersistenceError(
            `Welcome outbox dengan UUID [${canonicalWelcomeUuid}] sudah ada. Duplikasi ditolak.`
          );
          tx.abort();
          throw explicitAbortError;
        }

        // D. Tentukan operationId aman menggunakan CSPRNG fail-closed (H-I3B.2R.2R)
        let operationId: string;
        if (params.operationId) {
          operationId = params.operationId;
        } else {
          try {
            operationId = generateCryptoRandomUuid();
          } catch (csprngErr: any) {
            explicitAbortError = new BchatPersistenceError(
              `Persistensi GroupState & Outbox gagal: CSPRNG Web Crypto tidak tersedia untuk operationId (fail-closed): ${
                csprngErr?.message || csprngErr
              }`,
              csprngErr
            );
            tx.abort();
            throw explicitAbortError;
          }
        }

        // Tulis GroupState baru
        const newGroupRecord: GroupStateRecord = {
          device_uuid: canonicalDeviceUuid,
          room_uuid: canonicalRoomUuid,
          storage_schema_version: DEFAULT_STORAGE_SCHEMA_VERSION,
          serializer_version: BCAT_CLIENT_STATE_SERIALIZER_VERSION,
          ciphersuite_id: ciphersuiteId,
          group_id_hash: groupIdHash,
          epoch: epochStr,
          state_revision: newRevision,
          previous_revision: previousRevision,
          key_id: envelope.key_id,
          envelope: envelope,
          status: 'active',
          created_at: existing && existing.created_at ? existing.created_at : now,
          updated_at: now,
        };
        groupStore.put(newGroupRecord);

        // E. Tulis Commit Outbox
        commitRecord = {
          client_message_uuid: canonicalCommitUuid,
          room_uuid: canonicalRoomUuid,
          status: 'pending',
          ciphertext_bytes: params.commitOutbox.ciphertextBytes,
          created_at: now,
          retry_count: 0,
          metadata: {
            ...(params.commitOutbox.metadata ?? {}),
            operation_id: operationId,
            message_type: 'commit',
            epoch: epochStr,
            seq: params.commitOutbox.seq ?? 1,
          },
        };
        outboxStore.put(commitRecord);

        // F. Tulis Welcome Outbox (Terikat pada Commit via depends_on_uuid dan operation_id)
        welcomeRecord = {
          client_message_uuid: canonicalWelcomeUuid,
          room_uuid: canonicalRoomUuid,
          status: 'pending',
          ciphertext_bytes: params.welcomeOutbox.ciphertextBytes,
          created_at: now,
          retry_count: 0,
          metadata: {
            ...(params.welcomeOutbox.metadata ?? {}),
            operation_id: operationId,
            depends_on_uuid: canonicalCommitUuid,
            message_type: 'welcome',
            epoch: epochStr,
            seq: params.welcomeOutbox.seq ?? 2,
          },
        };
        outboxStore.put(welcomeRecord);
      }
    );
  } catch (err: unknown) {
    if (explicitAbortError) {
      throw explicitAbortError;
    }
    const orig = (err as any)?.originalError ?? (err as any)?.cause;
    if (orig instanceof BchatPersistenceError) {
      throw orig;
    }
    if (err instanceof BchatPersistenceError) {
      throw err;
    }
    throw new BchatPersistenceError(
      `persistGroupStateAndProtocolOutbox gagal disimpan: ${(err as Error)?.message || 'Unknown error'}`,
      err
    );
  }

  return {
    stateRevision: newRevision,
    previousRevision,
    epoch: epochStr,
    groupIdHash,
    updatedAt: now,
    operationId: commitRecord!.metadata?.operation_id as string,
    commitMessageUuid: canonicalCommitUuid,
    welcomeMessageUuid: canonicalWelcomeUuid,
    commitOutboxRecord: commitRecord!,
    welcomeOutboxRecord: welcomeRecord!,
  };
}

// =============================================================================
// API Utama: restoreGroupState
// =============================================================================

/**
 * Membaca, memvalidasi integritas, mendekripsi, dan memulihkan ClientState MLS dari disk.
 *
 * Mengembalikan:
 * - FOUND beserta ClientState jika seluruh validasi integritas lulus.
 * - NOT_FOUND jika record belum pernah disimpan.
 * - QUARANTINED jika record rusak/tampered (sekaligus mengarantina secara kondisional di disk).
 */
export async function restoreGroupState(
  deviceUuid: string,
  roomUuid: string,
  fencing?: FencingCredentials
): Promise<RestoreGroupStateResult> {
  const canonicalDeviceUuid = normalizeCanonicalUuid(deviceUuid, 'deviceUuid');
  const canonicalRoomUuid = normalizeCanonicalUuid(roomUuid, 'roomUuid');

  // 1. Baca record dari IndexedDB
  const record = await idbGet<GroupStateRecord>(BCAT_STORES.GROUP_STATES, [
    canonicalDeviceUuid,
    canonicalRoomUuid,
  ]);

  // 2. Jika tidak ada record, return NOT_FOUND
  if (!record) {
    return { status: 'NOT_FOUND' };
  }

  // 3. Jika status sudah quarantined, tolak pemulihan
  if (record.status === 'quarantined') {
    const reason = record.quarantine_reason || 'Record is quarantined';
    return {
      status: 'QUARANTINED',
      quarantineReason: reason,
      error: reason,
    };
  }

  // 4. Validasi metadata dan integritas amplop kriptografi di luar transaksi IDB
  try {
    if (!record.envelope) {
      throw new BchatPersistenceError('Record group_states tidak memuat encrypted envelope.');
    }

    validateStorageEnvelope(record.envelope);

    if (record.key_id !== record.envelope.key_id) {
      throw new BchatPersistenceError(
        `Ketidakcocokan key_id: record '${record.key_id}' vs envelope '${record.envelope.key_id}'.`
      );
    }

    const aadCtx = {
      deviceUuid: canonicalDeviceUuid,
      roomUuid: canonicalRoomUuid,
      storeName: BCAT_STORES.GROUP_STATES,
      recordType: 'client_state' as const,
      recordKey: [canonicalDeviceUuid, canonicalRoomUuid],
    };

    // 5. Dekripsi WebCrypto AES-GCM (di luar transaksi IDB)
    const decryptedBytes = await decryptPayloadFromEnvelope(record.envelope, aadCtx);

    // 6. Dekode ketat ClientState TLS syntax RFC 9420 (§13)
    const expectedSerializerVersion = record.serializer_version ?? BCAT_CLIENT_STATE_SERIALIZER_VERSION;
    const clientState = decodeClientStateStrict(decryptedBytes, expectedSerializerVersion);

    // 7. Validasi Group ID
    if (!clientState.groupContext || !clientState.groupContext.groupId) {
      throw new BchatGroupIdMismatchError('ClientState hasil decode tidak memuat GroupContext groupId valid.');
    }

    const expectedGroupIdBytes = new TextEncoder().encode(canonicalRoomUuid);
    if (
      clientState.groupContext.groupId.byteLength !== 36 ||
      !areUint8ArraysEqual(clientState.groupContext.groupId, expectedGroupIdBytes)
    ) {
      throw new BchatGroupIdMismatchError(
        `Group ID hasil decode (${clientState.groupContext.groupId.byteLength} byte) tidak cocok dengan room UUID 36 byte.`
      );
    }

    // 8. Validasi SHA-256 Group ID terhadap group_id_hash tersimpan
    const computedGroupIdHash = await computeSha256Hex(clientState.groupContext.groupId);
    if (record.group_id_hash && computedGroupIdHash !== record.group_id_hash) {
      throw new BchatPersistenceError(
        `Hash SHA-256 Group ID '${computedGroupIdHash}' tidak cocok dengan hash tersimpan '${record.group_id_hash}'.`
      );
    }

    // 9. Validasi epoch hasil decode cocok dengan metadata epoch
    const decodedEpochStr = clientState.groupContext.epoch.toString();
    if (decodedEpochStr !== record.epoch) {
      throw new BchatPersistenceError(
        `Epoch hasil decode '${decodedEpochStr}' tidak cocok dengan metadata epoch tersimpan '${record.epoch}'.`
      );
    }

    // 10. Seluruh validasi integritas lulus 100%
    return {
      status: 'FOUND',
      state: clientState,
      epoch: record.epoch,
      stateRevision: record.state_revision,
      previousRevision: record.previous_revision,
      groupIdHash: record.group_id_hash,
      lastProcessedSequence:
        record.last_processed_sequence ??
        (record.metadata?.last_processed_sequence as number | undefined) ??
        0,
      metadata: record.metadata,
    };
  } catch (integrityErr: unknown) {
    // Terjadi kegagalan integritas pada record yang sedang tersimpan di disk.
    const sanitizedReason = sanitizeQuarantineReason(integrityErr);

    // R3-03: Record yang gagal validasi hanya boleh dikarantina jika fencing disertakan & valid,
    // serta revision yang diperiksa masih sama dengan saat dibaca.
    if (fencing) {
      try {
        await quarantineGroupState({
          deviceUuid: canonicalDeviceUuid,
          roomUuid: canonicalRoomUuid,
          expectedRevision: record.state_revision,
          reason: sanitizedReason,
          fencing,
        });
      } catch {
        // Jika fencing stale atau revision conflict terjadi saat mencoba karantina,
        // jangan biarkan record baru termutasi. Tetap fail closed.
      }
    }

    return {
      status: 'QUARANTINED',
      quarantineReason: sanitizedReason,
      error: sanitizedReason,
    };
  }
}

// =============================================================================
// API Utama: quarantineGroupState
// =============================================================================

/**
 * Menandai record group_states berstatus 'quarantined' secara aman dengan proteksi fencing dan expectedRevision.
 * DILARANG KERAS menghapus record atau ciphertext.
 * Wajib memenuhi:
 * - Kredensial fencing yang valid (ownerToken, generation canonical decimal string, belum kedaluwarsa).
 * - expectedRevision yang persis cocok dengan record pada disk (atomic check dalam multi-store IDB transaction).
 */
export async function quarantineGroupState(
  params: QuarantineGroupStateParams
): Promise<void> {
  if (!params) {
    throw new BchatPersistenceError("Parameter 'params' wajib disertakan.");
  }

  const canonicalDeviceUuid = normalizeCanonicalUuid(params.deviceUuid, 'deviceUuid');
  const canonicalRoomUuid = normalizeCanonicalUuid(params.roomUuid, 'roomUuid');

  // Validasi fencing kredensial (R3-01)
  if (!params.fencing || typeof params.fencing.ownerToken !== 'string' || params.fencing.ownerToken.trim() === '') {
    throw new BchatFencingTokenStaleError('Fencing owner token wajib berupa string non-kosong.');
  }

  if (typeof params.fencing.generation !== 'string' || !DECIMAL_STRING_REGEX.test(params.fencing.generation)) {
    throw new BchatFencingTokenStaleError(
      `Fencing generation '${params.fencing?.generation}' tidak valid. Wajib berupa decimal string kanonikal.`
    );
  }

  // Validasi expectedRevision (R3-02)
  if (typeof params.expectedRevision !== 'string' || !DECIMAL_STRING_REGEX.test(params.expectedRevision)) {
    throw new BchatRevisionConflictError(
      `expectedRevision '${params.expectedRevision}' tidak valid. Wajib berupa decimal string kanonikal.`
    );
  }

  const sanitizedReason = sanitizeQuarantineReason(new Error(params.reason));
  const now = Date.now();
  let explicitAbortError: BchatPersistenceError | null = null;

  try {
    await withReadwriteTransaction(
      [BCAT_STORES.TAB_LEASES, BCAT_STORES.GROUP_STATES],
      async (tx, getStore) => {
        const leaseStore = getStore(BCAT_STORES.TAB_LEASES);
        const groupStore = getStore(BCAT_STORES.GROUP_STATES);

        // 1. Baca TabLease
        const leaseReq = leaseStore.get(canonicalDeviceUuid);
        const lease = await idbRequestToPromise<TabLeaseRecord | undefined>(leaseReq);

        if (!lease) {
          explicitAbortError = new BchatFencingTokenStaleError(
            `Tidak ditemukan tab lease aktif untuk perangkat [${canonicalDeviceUuid}]. Karantina ditolak.`
          );
          tx.abort();
          throw explicitAbortError;
        }

        // Validasi owner token
        if (lease.owner_token !== params.fencing.ownerToken) {
          explicitAbortError = new BchatFencingTokenStaleError(
            `Fencing owner token mismatch: lease aktif milik '${lease.owner_token}', namun pemanggil membawa '${params.fencing.ownerToken}'. Karantina ditolak.`
          );
          tx.abort();
          throw explicitAbortError;
        }

        // Validasi generation decimal string
        const leaseGenStr = lease.generation;
        if (typeof leaseGenStr !== 'string' || !DECIMAL_STRING_REGEX.test(leaseGenStr)) {
          explicitAbortError = new BchatFencingTokenStaleError(
            `Generasi lease tersimpan '${leaseGenStr}' bukan decimal string valid.`
          );
          tx.abort();
          throw explicitAbortError;
        }

        // Perbandingan BigInt tanpa konversi ke Number
        const callerGenBigInt = BigInt(params.fencing.generation);
        const activeGenBigInt = BigInt(leaseGenStr);

        if (callerGenBigInt !== activeGenBigInt) {
          explicitAbortError = new BchatFencingTokenStaleError(
            `Fencing generation stale: generasi aktif pada disk adalah '${leaseGenStr}', namun pemanggil membawa '${params.fencing.generation}'. Karantina ditolak.`
          );
          tx.abort();
          throw explicitAbortError;
        }

        // Validasi lease expiry (R3-01: Lease yang sudah kedaluwarsa tidak boleh digunakan untuk quarantine)
        if (typeof lease.expires_at === 'number' && now > lease.expires_at) {
          explicitAbortError = new BchatFencingTokenStaleError(
            `Tab lease untuk perangkat [${canonicalDeviceUuid}] telah kedaluwarsa (expired at ${lease.expires_at}, now ${now}). Karantina ditolak.`
          );
          tx.abort();
          throw explicitAbortError;
        }

        // 2. Baca GroupState
        const groupReq = groupStore.get([canonicalDeviceUuid, canonicalRoomUuid]);
        const current = await idbRequestToPromise<GroupStateRecord | undefined>(groupReq);

        if (!current) {
          // Record tidak ditemukan di disk
          return;
        }

        // 3. Pastikan record belum dikarantina (idempoten)
        if (current.status === 'quarantined') {
          return;
        }

        // 4. Pastikan state_revision === expectedRevision (R3-02)
        if (current.state_revision !== params.expectedRevision) {
          explicitAbortError = new BchatRevisionConflictError(
            `Benturan revisi karantina: revisi saat ini pada disk adalah '${current.state_revision}', namun pemanggil mengekspektasikan '${params.expectedRevision}'. Karantina ditolak demi melindungi record baru.`
          );
          tx.abort();
          throw explicitAbortError;
        }

        // 5. Ubah HANYA status dan metadata quarantine, PERTAHANKAN seluruh ciphertext dan metadata lainnya
        current.status = 'quarantined';
        current.quarantine_reason = sanitizedReason;
        current.updated_at = now;

        groupStore.put(current);
      }
    );
  } catch (err: unknown) {
    if (explicitAbortError) {
      throw explicitAbortError;
    }
    const orig = (err as any)?.originalError ?? (err as any)?.cause;
    if (orig instanceof BchatPersistenceError) {
      throw orig;
    }
    if (err instanceof BchatPersistenceError) {
      throw err;
    }
    throw new BchatPersistenceError(
      `Karantina group state gagal: ${(err as Error)?.message || 'Unknown error'}`,
      err
    );
  }
}

// =============================================================================
// API Manajemen Cursor Monotonik (I-04B.5S)
// =============================================================================

/**
 * Mengambil sequence cursor pemrosesan pesan terakhir untuk device dan room tertentu dari IndexedDB.
 */
export async function getGroupStateCursor(
  deviceUuid: string,
  roomUuid: string
): Promise<number> {
  const canonicalDeviceUuid = normalizeCanonicalUuid(deviceUuid, 'deviceUuid');
  const canonicalRoomUuid = normalizeCanonicalUuid(roomUuid, 'roomUuid');
  const record = await idbGet<GroupStateRecord>(BCAT_STORES.GROUP_STATES, [
    canonicalDeviceUuid,
    canonicalRoomUuid,
  ]);
  if (!record) return 0;
  return (
    record.last_processed_sequence ??
    (record.metadata?.last_processed_sequence as number | undefined) ??
    0
  );
}

/**
 * Memperbarui cursor pemrosesan sequence secara atomik pada GroupState yang tersimpan di IndexedDB.
 * Hanya memajukan kursor jika sequence baru lebih besar dari sequence tersimpan (strictly monotonic).
 */
export async function updateGroupStateCursor(params: {
  deviceUuid: string;
  roomUuid: string;
  sequence: number;
  fencing?: FencingCredentials;
}): Promise<void> {
  const canonicalDeviceUuid = normalizeCanonicalUuid(params.deviceUuid, 'deviceUuid');
  const canonicalRoomUuid = normalizeCanonicalUuid(params.roomUuid, 'roomUuid');
  const seq = params.sequence;

  await withReadwriteTransaction(
    [BCAT_STORES.TAB_LEASES, BCAT_STORES.GROUP_STATES],
    async (_tx, getStore) => {
      const leaseStore = getStore(BCAT_STORES.TAB_LEASES);
      const groupStore = getStore(BCAT_STORES.GROUP_STATES);

      if (params.fencing) {
        const leaseReq = leaseStore.get(canonicalDeviceUuid);
        const lease = await idbRequestToPromise<TabLeaseRecord | undefined>(leaseReq);
        if (!lease || lease.owner_token !== params.fencing.ownerToken) {
          throw new BchatFencingTokenStaleError('Fencing lease invalid or stale saat update cursor.');
        }
      }

      const groupReq = groupStore.get([canonicalDeviceUuid, canonicalRoomUuid]);
      const existing = await idbRequestToPromise<GroupStateRecord | undefined>(groupReq);
      if (!existing || existing.status === 'quarantined') {
        return;
      }

      const currentSeq =
        existing.last_processed_sequence ??
        (existing.metadata?.last_processed_sequence as number | undefined) ??
        0;
      if (seq > currentSeq) {
        existing.last_processed_sequence = seq;
        existing.metadata = {
          ...(existing.metadata || {}),
          last_processed_sequence: seq,
        };
        existing.updated_at = Date.now();
        groupStore.put(existing);
      }
    }
  );
}
