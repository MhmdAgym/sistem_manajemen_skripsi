/**
 * B-CHAT E2EE Protocol Layer — Message Coordinator (I-04B.4R)
 *
 * Mengorkestrasikan alur pengiriman dan penerimaan pesan aplikasi E2EE antara
 * mahasiswa dan dosen pembimbing pada MLS Group yang telah berstatus ACTIVE.
 *
 * Mengintegrasikan:
 * 1. API Client I-01 (POST /api/bchat/rooms/{roomUuid}/messages & GET /api/bchat/rooms/{roomUuid}/messages).
 * 2. Dedicated MLS Worker I-02B (ENCRYPT_MESSAGE, DECRYPT_MESSAGE, RESTORE_GROUP_STATE, UPDATE_OUTBOX_STATUS, RETRY_OUTBOX_MESSAGE).
 * 3. Multi-store IndexedDB Persistence (Group States, Protocol Outbox).
 *
 * Invarian Protokol & Keamanan:
 * - Persist-Before-Adopt: GroupState dan outbox WAJIB tersimpan secara atomik di IndexedDB sebelum ciphertext dikirim.
 * - Monotonic Sequence & Idempotence: Pengiriman ulang ciphertext yang sama dengan message_uuid yang sama menghasilkan 200 OK idempoten.
 * - Integrity Check: Payload hash SHA-256 64-karakter hex dihitung atas raw binary ciphertext dan divalidasi ketat pada backend dan frontend.
 * - Zero Leakage: Plaintext, secret tree, private keys, dan KEK TIDAK PERNAH keluar dari Worker atau terekspos ke log/backend.
 * - Inbound Decryption: Hanya pesan dari peer yang diproses via DECRYPT_MESSAGE. Pesan diri sendiri (self-sent) tidak memajukan inbound ratchet.
 */

import {
  sendMessage,
  fetchMessages,
  BchatApiError,
} from '@/lib/bchat/api/bchat-api-client';
import type {
  BchatSendMessageRequest,
  BchatMessageData,
  BchatFetchMessagesQuery,
} from '@/lib/bchat/api/bchat-api-types';
import type {
  BchatWorkerClient,
  EncryptMessageResult,
  DecryptMessageResult,
  FencingRpcInput,
} from '@/lib/bchat/bchat-poc-rpc';
import {
  getGroupStateCursor,
  updateGroupStateCursor,
} from '@/lib/bchat/storage/bchat-mls-persistence';
import {
  saveEncryptedHistoryItem,
  loadEncryptedHistory,
  getEncryptedHistoryItem,
} from '@/lib/bchat/storage/bchat-encrypted-history';

export {
  getGroupStateCursor,
  updateGroupStateCursor,
  saveEncryptedHistoryItem,
  loadEncryptedHistory,
  getEncryptedHistoryItem,
};

// -----------------------------------------------------------------------------
// ERROR DEFINITIONS
// -----------------------------------------------------------------------------

export class BchatMessageError extends Error {
  readonly isBchatMessageError = true;
  constructor(message: string, cause?: unknown) {
    super(message);
    this.name = 'BchatMessageError';
    if (cause) {
      (this as any).cause = cause;
    }
  }
}

export class BchatMessageValidationError extends BchatMessageError {
  constructor(message: string) {
    super(message);
    this.name = 'BchatMessageValidationError';
  }
}

export class BchatMessageIntegrityError extends BchatMessageError {
  constructor(message: string) {
    super(message);
    this.name = 'BchatMessageIntegrityError';
  }
}

export class BchatMessageSendError extends BchatMessageError {
  readonly httpStatus?: number;
  readonly errorCode?: string;

  constructor(message: string, httpStatus?: number, errorCode?: string, cause?: unknown) {
    super(message, cause);
    this.name = 'BchatMessageSendError';
    this.httpStatus = httpStatus;
    this.errorCode = errorCode;
  }
}

export class BchatReadOnlyHistoryError extends BchatMessageSendError {
  constructor(
    message = 'Perangkat ini berada dalam status Read-Only History dan belum memiliki MLS GroupState aktif untuk mengirim pesan.',
    cause?: unknown
  ) {
    super(message, undefined, 'PERANGKAT_READ_ONLY_HISTORY', cause);
    this.name = 'BchatReadOnlyHistoryError';
  }
}

export class BchatMessageDecryptError extends BchatMessageError {
  constructor(message: string, cause?: unknown) {
    super(message, cause);
    this.name = 'BchatMessageDecryptError';
  }
}

// -----------------------------------------------------------------------------
// TYPES & CONTRACTS
// -----------------------------------------------------------------------------

export interface SendApplicationMessageParams {
  roomUuid: string;
  plaintext: string;
  deviceUuid: string;
  workerRpc: BchatWorkerClient;
  fencing: FencingRpcInput;
  clientMessageUuid?: string;
  contentType?: 1 | 2 | 3;
  attachmentUuids?: string[];
}

export interface SendApplicationMessageResult {
  messageData: BchatMessageData;
  clientMessageUuid: string;
  stateRevision?: string;
  epoch: string;
  payloadHashSha256: string;
  isReplayed: boolean;
}

export interface ReceiveApplicationMessagesParams {
  roomUuid: string;
  deviceUuid: string;
  workerRpc: BchatWorkerClient;
  fencing: FencingRpcInput;
  currentUserId: number;
  query?: BchatFetchMessagesQuery;
}

export interface DecryptedMessageItem {
  server_sequence: number;
  message_uuid: string;
  sender_id: number;
  epoch: number;
  generation: number;
  content_type: number;
  is_self: boolean;
  plaintext: string;
  client_sent_at: string | null;
  created_at: string | null;
  error?: string;
}

export interface ReceiveApplicationMessagesResult {
  messages: DecryptedMessageItem[];
  latestSequence: number;
  count: number;
}

export interface ProcessInboundMessageParams {
  item: BchatMessageData;
  roomUuid: string;
  deviceUuid: string;
  currentUserId: number;
  workerRpc: BchatWorkerClient;
  fencing: FencingRpcInput;
  /**
   * Jika false (misal saat terdapat sequence gap / pesan gagal di urutan lebih rendah),
   * persistent cursor GroupState tidak dimajukan melewati celah tersebut.
   * Default: true.
   */
  allowCursorAdvance?: boolean;
}

export type BchatInboundErrorCategory =
  | 'TRANSIENT_ERROR'
  | 'PAYLOAD_INTEGRITY_ERROR'
  | 'MLS_CRYPTO_ERROR';

export interface UnresolvedSequenceDetail {
  server_sequence: number;
  message_uuid?: string;
  errorCategory: BchatInboundErrorCategory;
  error: string;
  requiresRecovery: boolean;
}

export interface ProcessInboundMessageResult {
  success: boolean;
  decryptedItem: DecryptedMessageItem;
  advancedSequence?: number;
  error?: string;
  errorCategory?: BchatInboundErrorCategory;
  requiresRecovery?: boolean;
}

export interface ReconcileInboundMessagesParams {
  roomUuid: string;
  deviceUuid: string;
  currentUserId: number;
  workerRpc: BchatWorkerClient;
  fencing: FencingRpcInput;
  afterSequence?: number;
  /** Batas bawah sequence sesi aktif: Math.max(0, (active_session.start_sequence ?? 1) - 1). */
  sessionFloor?: number;
  /** UUID sesi aktif agar pengambilan REST hanya mengambil pesan milik sesi aktif (bukan ARCHIVED). */
  sessionUuid?: string | null;
  batchLimit?: number;
  processedSequences?: Set<number>;
  /**
   * Daftar sequence yang diketahui gagal pada percobaan sebelumnya (kriptografi / integritas)
   * agar reconciliation rutin tidak memanggil Worker DECRYPT_MESSAGE berulang kali pada pesan yang sama,
   * sambil tetap menjaga cursor kontigu tidak melompati sequence tersebut.
   */
  unresolvedSequences?: Map<number, UnresolvedSequenceDetail>;
  /**
   * Jika true (misal saat retry recovery eksplisit), sequence yang berada di unresolvedSequences
   * akan diperiksa kembali secara aman (termasuk memeriksa apakah riwayat lokal sudah dipulihkan).
   */
  retryUnresolved?: boolean;
  onMessageDecrypted?: (message: DecryptedMessageItem) => void;
}

export interface ReconcileInboundMessagesResult {
  reconciledCount: number;
  /** Contiguous verified cursor: seluruh sequence 1..latestSequence telah selesai secara sah tanpa celah. */
  latestSequence: number;
  /** Alias eksplisit untuk latestSequence (contiguous verified cursor). */
  contiguousSequence: number;
  /** Sequence tertinggi yang terlihat/ditangani dalam batch (termasuk setelah gap). */
  highestSeenSequence: number;
  hasErrors: boolean;
  failedSequence?: number;
  error?: string;
  errorCategory?: BchatInboundErrorCategory;
  requiresRecovery?: boolean;
  unresolvedSequences: UnresolvedSequenceDetail[];
  resolvedSequences: number[];
  processedItems: DecryptedMessageItem[];
}

export const HISTORY_TOMBSTONE_MARKERS = [
  '[Dekripsi Gagal]',
  '[Integritas Gagal]',
  '[Format Corrupt]',
  '[Gagal Memproses Pesan]',
] as const;

/**
 * Menentukan apakah teks pada riwayat lokal hanyalah tombstone kegagalan pemrosesan
 * dan BUKAN plaintext hasil dekripsi yang sah.
 */
export function isHistoryTombstonePlaintext(plaintext: string | null | undefined): boolean {
  if (!plaintext || typeof plaintext !== 'string') {
    return false;
  }
  const trimmed = plaintext.trim();
  return (
    trimmed.startsWith('[Dekripsi Gagal') ||
    trimmed.startsWith('[Integritas Gagal') ||
    trimmed.startsWith('[Format Corrupt') ||
    trimmed.startsWith('[Gagal Memproses Pesan')
  );
}

/**
 * Menghitung batas contiguous sequence tertinggi mulai dari baseContiguous,
 * di mana setiap sequence (baseContiguous + 1 .. result) benar-benar ada di resolvedSequences
 * tanpa satu pun celah (gap).
 */
export function computeContiguousResolvedSequence(
  resolvedSequences: Iterable<number>,
  baseContiguous = 0
): number {
  const resolvedSet =
    resolvedSequences instanceof Set ? resolvedSequences : new Set<number>(resolvedSequences);
  let cursor = Number.isInteger(baseContiguous) && baseContiguous > 0 ? baseContiguous : 0;
  while (resolvedSet.has(cursor + 1)) {
    cursor++;
  }
  return cursor;
}

/**
 * Menghitung batas bawah sequence (sessionFloor) untuk sesi aktif:
 * `Math.max(0, (activeSessionStartSequence ?? 1) - 1)`.
 * Contoh: Gen 3 dengan `start_sequence = 18` menghasilkan `sessionFloor = 17`,
 * sehingga sequence milik sesi ARCHIVED (1..17) tidak dianggap celah pada sesi aktif.
 */
export function computeSessionFloor(activeSessionStartSequence?: number | null): number {
  if (
    typeof activeSessionStartSequence !== 'number' ||
    !Number.isInteger(activeSessionStartSequence) ||
    activeSessionStartSequence <= 1
  ) {
    return 0;
  }
  return Math.max(0, activeSessionStartSequence - 1);
}

export interface ResolveActiveSessionCursorParams {
  activeSessionStartSequence?: number | null;
  persistentCursor: number;
  validHistorySequences: Iterable<number>;
  unresolvedSequences?: Iterable<number>;
}

export interface ResolveActiveSessionCursorResult {
  sessionFloor: number;
  contiguousHistorySeq: number;
  hasGapBelowPersistent: boolean;
  startingCursor: number;
  activeSessionUnresolvedSequences: number[];
}

/**
 * Menyelesaikan kursor awal rekonsiliasi secara konsisten terhadap batas bawah sesi aktif (`sessionFloor`):
 * - Perhitungan `computeContiguousResolvedSequence()` dimulai dari `sessionFloor` (bukan selalu 0).
 * - Pemeriksaan celah `hasGapBelowPersistent` HANYA mencakup rentang `(sessionFloor + 1)..persistentCursor`.
 * - Sequence milik sesi ARCHIVED (`<= sessionFloor`) tidak pernah dianggap gap atau tombstone pemblokir.
 * - Kursor persisten yang valid dipertahankan dan tidak dimundurkan secara tidak perlu.
 */
export function resolveActiveSessionCursor(
  params: ResolveActiveSessionCursorParams
): ResolveActiveSessionCursorResult {
  const sessionFloor = computeSessionFloor(params.activeSessionStartSequence);
  const safePersistent =
    typeof params.persistentCursor === 'number' &&
    Number.isInteger(params.persistentCursor) &&
    params.persistentCursor > 0
      ? params.persistentCursor
      : 0;

  const validHistorySet = new Set<number>();
  for (const seq of Array.from(params.validHistorySequences)) {
    if (typeof seq === 'number' && Number.isInteger(seq) && seq > 0) {
      validHistorySet.add(seq);
    }
  }

  const activeSessionUnresolvedSequences: number[] = [];
  if (params.unresolvedSequences) {
    for (const seq of Array.from(params.unresolvedSequences)) {
      if (typeof seq === 'number' && Number.isInteger(seq) && seq > sessionFloor) {
        activeSessionUnresolvedSequences.push(seq);
      }
    }
  }
  activeSessionUnresolvedSequences.sort((a, b) => a - b);

  const contiguousHistorySeq = computeContiguousResolvedSequence(validHistorySet, sessionFloor);

  const hasActiveHistoryItems =
    Array.from(validHistorySet).some((seq) => seq > sessionFloor) ||
    activeSessionUnresolvedSequences.length > 0;

  let hasGapBelowPersistent = false;
  if (safePersistent > sessionFloor) {
    const minActiveUnresolved =
      activeSessionUnresolvedSequences.length > 0
        ? activeSessionUnresolvedSequences[0]
        : Number.POSITIVE_INFINITY;

    if (minActiveUnresolved <= safePersistent) {
      hasGapBelowPersistent = true;
    } else if (hasActiveHistoryItems) {
      for (let seq = sessionFloor + 1; seq <= safePersistent; seq++) {
        if (!validHistorySet.has(seq)) {
          hasGapBelowPersistent = true;
          break;
        }
      }
    }
  }

  const startingCursor = hasGapBelowPersistent
    ? Math.max(sessionFloor, contiguousHistorySeq)
    : Math.max(sessionFloor, safePersistent, contiguousHistorySeq);

  return {
    sessionFloor,
    contiguousHistorySeq,
    hasGapBelowPersistent,
    startingCursor,
    activeSessionUnresolvedSequences,
  };
}

// -----------------------------------------------------------------------------
// HELPER UTILITIES
// -----------------------------------------------------------------------------

function base64ToBytes(b64: string): Uint8Array {
  const binaryString = atob(b64);
  const bytes = new Uint8Array(binaryString.length);
  for (let i = 0; i < binaryString.length; i++) {
    bytes[i] = binaryString.charCodeAt(i);
  }
  return bytes;
}

function bytesToBase64(bytes: Uint8Array): string {
  let binary = '';
  for (let i = 0; i < bytes.length; i++) {
    binary += String.fromCharCode(bytes[i]);
  }
  return btoa(binary);
}

function bytesToHex(bytes: Uint8Array): string {
  let hex = '';
  for (let i = 0; i < bytes.length; i++) {
    hex += bytes[i].toString(16).padStart(2, '0');
  }
  return hex.toLowerCase();
}

/**
 * Menghitung hash SHA-256 kanonikal dari biner raw ciphertext.
 * Mengembalikan 64 karakter lowercase hex string.
 */
export async function computePayloadSha256(rawBytes: Uint8Array): Promise<string> {
  const subtle = typeof crypto !== 'undefined' && crypto.subtle;
  if (!subtle) {
    throw new BchatMessageError('WebCrypto subtle digest API tidak tersedia.');
  }
  const digestBuffer = await subtle.digest('SHA-256', rawBytes as unknown as ArrayBuffer);
  return bytesToHex(new Uint8Array(digestBuffer));
}

/**
 * Utilitas jeda asinkron (Promise-based sleep).
 */
export function sleep(ms: number): Promise<void> {
  return new Promise((resolve) => setTimeout(resolve, ms));
}

export interface ClassifyInboundErrorContext {
  source?: 'DECRYPT_MESSAGE' | 'GENERAL';
}

/**
 * Klasifikasi kategori error pesan masuk ke dalam 3 taksonomi ketat:
 * 1. TRANSIENT_ERROR (Timeout, AbortError, gangguan jaringan, IndexedDB busy/lock, kegagalan I/O)
 * 2. PAYLOAD_INTEGRITY_ERROR (Base64 rusak, format corrupt, hash mismatch)
 * 3. MLS_CRYPTO_ERROR (Epoch/generation mismatch, signature invalid, kegagalan AEAD/WebCrypto
 *    OperationError dari DECRYPT_MESSAGE, kegagalan autentikasi ciphertext/sender data/validasi MLS)
 */
export function classifyInboundError(
  error: unknown,
  context?: ClassifyInboundErrorContext
): BchatInboundErrorCategory {
  const errObj = error && typeof error === 'object' ? (error as Record<string, unknown>) : null;
  const errName =
    error instanceof Error
      ? error.name
      : typeof errObj?.name === 'string'
      ? errObj.name
      : '';
  const errCode = typeof errObj?.code === 'string' ? errObj.code : '';
  const msg =
    error instanceof Error
      ? error.message
      : typeof errObj?.message === 'string'
      ? errObj.message
      : String(error || '');

  const lower = msg.toLowerCase();
  const lowerName = errName.toLowerCase();
  const lowerCode = errCode.toLowerCase();

  // 0. Guard eksplisit untuk kegagalan transien (timeout, AbortError, jaringan, IDB busy/lock, I/O)
  // agar TIDAK PERNAH salah diklasifikasikan sebagai kesalahan kriptografi permanen.
  // Pengecualian: kesalahan fencing/stale/quarantined tetap dievaluasi sebagai MLS_CRYPTO_ERROR.
  const isFencingOrQuarantine =
    lower.includes('fencing') ||
    lower.includes('stale') ||
    lower.includes('quarantined') ||
    lower.includes('sesi worker terkunci');

  if (!isFencingOrQuarantine) {
    const isTransient =
      lowerName === 'aborterror' ||
      lowerName === 'timeouterror' ||
      lowerCode === 'aborterror' ||
      lowerCode === 'timeouterror' ||
      lower.includes('[aborterror]') ||
      lower.includes('[timeouterror]') ||
      lower.includes('aborterror') ||
      lower.includes('timeouterror') ||
      lower.includes('rpc timeout') ||
      lower.includes('timeout') ||
      lower.includes('network') ||
      lower.includes('econnrefused') ||
      lower.includes('failed to fetch') ||
      lower.includes('err_network') ||
      lower.includes('offline') ||
      lower.includes('indexeddb') ||
      lower.includes('idbtransaction') ||
      lower.includes('transactioninactiveerror') ||
      lower.includes('database busy') ||
      lower.includes('transaction busy') ||
      lower.includes('transaction locked') ||
      /\block(ed|s)?\b/.test(lower) ||
      lower.includes('busy') ||
      lower.includes('quotaexceeded') ||
      lower.includes('persistensi group state gagal') ||
      lower.includes('i/o error') ||
      lower.includes('io error') ||
      lower.includes('i/o failure') ||
      lower.includes('io failure') ||
      lower.includes('worker diterminasi') ||
      lower.includes('worker telah diterminasi');

    if (isTransient) {
      return 'TRANSIENT_ERROR';
    }
  }

  // 1. Payload / Integrity Error
  if (
    lower.includes('base64') ||
    lower.includes('sha-256') ||
    lower.includes('payload_hash_mismatch') ||
    lower.includes('integritas') ||
    lower.includes('wireformat') ||
    lower.includes('invalid base64') ||
    lower.includes('corrupt')
  ) {
    return 'PAYLOAD_INTEGRITY_ERROR';
  }

  // 2. MLS Cryptographic / State / AEAD Authentication Error
  const isWebCryptoOperationError =
    lowerName === 'operationerror' ||
    lowerCode === 'operationerror' ||
    lower.includes('[operationerror]') ||
    lower.includes('operationerror') ||
    lower.includes('operation-specific reason');

  if (
    (isWebCryptoOperationError && context?.source === 'DECRYPT_MESSAGE') ||
    lower.includes('epoch') ||
    lower.includes('generation') ||
    lower.includes('desired gen in the past') ||
    lower.includes('desired generation too far in the future') ||
    lower.includes('signature invalid') ||
    lower.includes('cryptoverificationerror') ||
    lower.includes('aead') ||
    lower.includes('authentication tag') ||
    lower.includes('auth tag') ||
    lower.includes('mac authentication') ||
    lower.includes('ciphertext authentication') ||
    lower.includes('could not decode senderdata') ||
    lower.includes('senderdata') ||
    lower.includes('sender data') ||
    lower.includes('could not decode privatemessagecontent') ||
    lower.includes('codecerror') ||
    lower.includes('validationerror') ||
    lower.includes('gagal mendekode ciphertext') ||
    lower.includes('state grup belum aktif') ||
    lower.includes('read-only') ||
    lower.includes('read_only') ||
    lower.includes('groupstate') ||
    lower.includes('sesi worker terkunci') ||
    lower.includes('bukan applicationmessage') ||
    lower.includes('cannot process message, epoch too old') ||
    lower.includes('fencing') ||
    lower.includes('stale') ||
    lower.includes('quarantined') ||
    lower.includes('unrecoverable')
  ) {
    return 'MLS_CRYPTO_ERROR';
  }

  // 3. Transient Error (Default untuk kegagalan I/O atau kendala sementara tak dikenal)
  return 'TRANSIENT_ERROR';
}

/**
 * Pengambilan pesan REST dengan retry eksponensial untuk transient network errors.
 */
async function fetchWithTransientRetry(
  roomUuid: string,
  query: BchatFetchMessagesQuery,
  maxAttempts = 3
) {
  let attempt = 0;
  let lastError: unknown;
  while (attempt < maxAttempts) {
    attempt++;
    try {
      return await fetchMessages(roomUuid, query);
    } catch (err: any) {
      lastError = err;
      if (attempt < maxAttempts) {
        const delay = Math.min(150 * Math.pow(2, attempt - 1), 1000);
        await sleep(delay);
      }
    }
  }
  throw lastError;
}

// -----------------------------------------------------------------------------
// WORKFLOW 1: SEND APPLICATION MESSAGE FLOW
// -----------------------------------------------------------------------------

/**
 * Mengirimkan pesan aplikasi E2EE ke backend dengan jaminan atomik:
 * 1. Validasi input dan kredensial fencing.
 * 2. Dedicated Worker mengenkripsi pesan via MLS RFC 9420.
 * 3. Worker menyimpan candidate GroupState + rekaman outbox ('pending') di IDB sebelum mengadopsi state.
 * 4. Hitung hash SHA-256 dari wireformat ciphertext biner.
 * 5. Kirim HTTP POST ke backend /api/bchat/rooms/{roomUuid}/messages.
 * 6. Jika berhasil (201 Created atau 200 Replayed), perbarui status outbox menjadi 'acked'.
 * 7. Jika gagal ambigu, perbarui status outbox menjadi 'in_transit' untuk rekonsiliasi.
 */
export async function sendApplicationMessageFlow(
  params: SendApplicationMessageParams
): Promise<SendApplicationMessageResult> {
  const { roomUuid, plaintext, deviceUuid, workerRpc, fencing } = params;

  if (!roomUuid || typeof roomUuid !== 'string') {
    throw new BchatMessageValidationError('roomUuid wajib berupa string yang valid.');
  }
  if (typeof plaintext !== 'string' || plaintext.length === 0) {
    throw new BchatMessageValidationError('Plaintext pesan tidak boleh kosong.');
  }
  if (!deviceUuid || typeof deviceUuid !== 'string') {
    throw new BchatMessageValidationError('deviceUuid wajib berupa string yang valid.');
  }
  if (!workerRpc) {
    throw new BchatReadOnlyHistoryError(
      'workerRpc instance wajib disertakan. Perangkat tidak dapat mengirim pesan tanpa MLS GroupState aktif.'
    );
  }
  if (!fencing || !fencing.ownerToken || !fencing.generation) {
    throw new BchatMessageValidationError('Kredensial fencing wajib disertakan secara lengkap.');
  }

  // Validasi referensi lampiran SEBELUM ENCRYPT_MESSAGE agar ratchet MLS tidak pernah maju
  // ketika pesan manifest lampiran (contentType === 2) tidak memiliki referensi UUID v4 yang sah.
  const contentType = params.contentType ?? 1;
  const UUID_V4_REGEX = /^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i;
  let normalizedAttachmentUuids: string[] = [];

  if (params.attachmentUuids !== undefined) {
    if (!Array.isArray(params.attachmentUuids)) {
      throw new BchatMessageValidationError(
        'Daftar attachmentUuids harus berupa array UUID lampiran yang valid.'
      );
    }
    normalizedAttachmentUuids = params.attachmentUuids.map((u) =>
      typeof u === 'string' ? u.trim().toLowerCase() : ''
    );
    for (const attUuid of normalizedAttachmentUuids) {
      if (!UUID_V4_REGEX.test(attUuid)) {
        throw new BchatMessageValidationError(
          `Format UUID lampiran tidak valid (${attUuid || 'kosong'}). Wajib UUID v4 kanonis.`
        );
      }
    }
    if (new Set(normalizedAttachmentUuids).size !== normalizedAttachmentUuids.length) {
      throw new BchatMessageValidationError(
        'Daftar referensi lampiran mengandung UUID duplikat.'
      );
    }
  }

  if (contentType === 2 && normalizedAttachmentUuids.length === 0) {
    throw new BchatMessageValidationError(
      'Pesan manifest lampiran wajib menyertakan referensi minimal satu UUID lampiran sebelum enkripsi.'
    );
  }

  const clientMessageUuid =
    params.clientMessageUuid ||
    (typeof crypto !== 'undefined' && crypto.randomUUID
      ? crypto.randomUUID()
      : 'msg_' + Date.now());

  // 1. Enkripsi via MLS Worker (Persist-Before-Adopt dilakukan di dalam Worker)
  if (!workerRpc) {
    throw new BchatReadOnlyHistoryError(
      'Worker kriptografi MLS tidak tersedia. Perangkat tidak dapat mengirim pesan tanpa MLS GroupState aktif.'
    );
  }

  let encResult: EncryptMessageResult;
  try {
    encResult = await workerRpc.sendRequest<any, EncryptMessageResult>('ENCRYPT_MESSAGE', {
      plaintext,
      clientMessageUuid,
      deviceUuid,
      roomUuid,
      fencing,
    });
  } catch (encErr: any) {
    const errMessage = encErr?.message || '';
    if (
      errMessage.includes('State grup belum aktif') ||
      errMessage.includes('GroupState') ||
      errMessage.includes('NOT_FOUND') ||
      encErr?.errorCode === 'PERANGKAT_READ_ONLY_HISTORY'
    ) {
      throw new BchatReadOnlyHistoryError(
        'Perangkat ini berada dalam status Read-Only History dan belum memiliki MLS GroupState aktif untuk mengirim pesan.',
        encErr
      );
    }
    throw encErr;
  }

  if (!encResult || !encResult.ciphertextWireBase64) {
    throw new BchatReadOnlyHistoryError(
      'Enkripsi pesan MLS gagal: ciphertext tidak dihasilkan. Perangkat tidak dapat mengirim pesan tanpa MLS GroupState aktif.'
    );
  }

  const wireBytes = base64ToBytes(encResult.ciphertextWireBase64);
  const epochNumber = parseInt(encResult.epoch, 10);

  const payloadBase64 = bytesToBase64(wireBytes);
  const payloadHashSha256 = await computePayloadSha256(wireBytes);

  // 3. Susun request payload pengiriman
  const clientSentAt = new Date().toISOString();

  const sendPayload: BchatSendMessageRequest = {
    message_uuid: clientMessageUuid,
    epoch: epochNumber,
    generation: 0,
    content_type: contentType,
    ciphertext_payload: payloadBase64,
    payload_hash_sha256: payloadHashSha256,
    client_sent_at: clientSentAt,
    ...(normalizedAttachmentUuids.length > 0
      ? { attachment_uuids: normalizedAttachmentUuids }
      : {}),
  };

  // 4. Kirim ciphertext ke Backend REST API
  try {
    const res = await sendMessage(roomUuid, sendPayload);

    // 5. Berhasil: Tandai outbox message lokal sebagai 'acked'
    try {
      if (workerRpc && encResult) {
        await workerRpc.sendRequest('UPDATE_OUTBOX_STATUS', {
          clientMessageUuid,
          status: 'acked',
          sentAt: Date.now(),
          deviceUuid,
          fencing,
        });
      }
    } catch {
      // Non-fatal: State outbox acked diabaikan jika worker sibuk, karena backend sudah persisten
    }

    // 5b. Persistensikan plaintext ke riwayat lokal terenkripsi (AES-256-GCM via KEK)
    try {
      await saveEncryptedHistoryItem({
        deviceUuid,
        roomUuid,
        sequenceNumber: res.data.server_sequence,
        messageUuid: res.data.message_uuid,
        senderUserId: res.data.sender_id,
        isSelf: true,
        plaintext,
        contentType,
        clientSentAt,
        createdAt: res.data.created_at || clientSentAt,
      });
    } catch {
      // Non-fatal jika penyimpanan riwayat lokal mengalami kendala sekunder
    }

    const isReplayed = res.message.includes('idempoten');

    return {
      messageData: res.data,
      clientMessageUuid,
      stateRevision: encResult.stateRevision,
      epoch: encResult.epoch || String(epochNumber),
      payloadHashSha256,
      isReplayed,
    };
  } catch (apiErr: any) {
    // 6. Gagal: bedakan penolakan deterministik 4xx (selain 408/409/429) sebagai 'failed'
    // vs kegagalan transient/jaringan sebagai 'in_transit' agar ciphertext yang ditolak
    // tidak pernah dikirim ulang secara otomatis tanpa pengawasan.
    const isDeterministicClientRejection =
      apiErr instanceof BchatApiError &&
      apiErr.status >= 400 &&
      apiErr.status < 500 &&
      apiErr.status !== 408 &&
      apiErr.status !== 409 &&
      apiErr.status !== 429;
    const failedOutboxStatus = isDeterministicClientRejection ? 'failed' : 'in_transit';

    try {
      await workerRpc.sendRequest('UPDATE_OUTBOX_STATUS', {
        clientMessageUuid,
        status: failedOutboxStatus,
        errorMessage: apiErr?.message || 'Network dispatch failed',
        deviceUuid,
        fencing,
      });
    } catch {
      // Ignore secondary update error
    }

    if (apiErr instanceof BchatApiError) {
      throw new BchatMessageSendError(
        `Pengiriman pesan gagal (${apiErr.status} ${apiErr.errorCode || ''}): ${apiErr.message}`,
        apiErr.status,
        apiErr.errorCode,
        apiErr
      );
    }

    throw new BchatMessageSendError(
      `Pengiriman pesan gagal karena kendala jaringan atau server: ${apiErr?.message || 'Unknown error'}`,
      undefined,
      undefined,
      apiErr
    );
  }
}

// -----------------------------------------------------------------------------
// WORKFLOW 2: INBOUND APPLICATION MESSAGE PROCESSING & RECONCILIATION
// -----------------------------------------------------------------------------

/**
 * Memproses satu item pesan inbound secara aman dan terisolasi:
 * 1. Self-sent message: lewati Worker DECRYPT_MESSAGE, perbarui kursor monotonik secara atomik di IDB.
 * 2. Peer message:
 *    a. Validasi Base64 dan integritas SHA-256 payload ciphertext.
 *    b. Kirim RPC DECRYPT_MESSAGE ke MLS Worker (Inbound Persist-Before-Adopt).
 *    c. Worker menyimpan candidate GroupState + last_processed_sequence secara atomik sebelum mengadopsi state.
 * 3. Error Isolation: Kegagalan dekripsi atau integritas ditangkap, diisolasi, dan TIDAK memajukan kursor sequence.
 */
export async function processInboundMessageItem(
  params: ProcessInboundMessageParams
): Promise<ProcessInboundMessageResult> {
  const {
    item,
    roomUuid,
    deviceUuid,
    currentUserId,
    workerRpc,
    fencing,
    allowCursorAdvance = true,
  } = params;
  const seq = item.server_sequence;
  const isSelf = item.sender_id === currentUserId;

  if (isSelf) {
    if (allowCursorAdvance) {
      try {
        await updateGroupStateCursor({
          deviceUuid,
          roomUuid,
          sequence: seq,
          fencing: {
            ownerToken: fencing.ownerToken,
            generation: fencing.generation,
          },
        });
      } catch {
        // Non-fatal: State outbox acked diabaikan jika worker sibuk, karena backend sudah persisten
      }
    }

    // Periksa apakah pesan ini sudah tersimpan di riwayat lokal kita dengan plaintext aslinya
    let selfPlaintext = '(Pesan terkirim dari sesi Anda)';
    try {
      const existing = await getEncryptedHistoryItem(deviceUuid, roomUuid, seq);
      if (existing && existing.plaintext && !existing.plaintext.startsWith('(Pesan terkirim')) {
        selfPlaintext = existing.plaintext;
      }
    } catch {
      // fallback
    }

    if (selfPlaintext === '(Pesan terkirim dari sesi Anda)' && item.ciphertext_payload) {
      try {
        const decoded = new TextDecoder().decode(base64ToBytes(item.ciphertext_payload));
        if (decoded.startsWith('{') && decoded.includes('"text":')) {
          const parsed = JSON.parse(decoded);
          if (parsed && typeof parsed.text === 'string') {
            selfPlaintext = parsed.text;
          }
        }
      } catch {
        // safe ignore
      }
    }

    // Persistensikan ke riwayat lokal terenkripsi
    try {
      await saveEncryptedHistoryItem({
        deviceUuid,
        roomUuid,
        sequenceNumber: seq,
        messageUuid: item.message_uuid,
        senderUserId: item.sender_id,
        isSelf: true,
        plaintext: selfPlaintext,
        contentType: item.content_type,
        clientSentAt: item.client_sent_at,
        createdAt: item.created_at || item.client_sent_at,
      });
    } catch {
      // safe ignore
    }

    const decryptedItem: DecryptedMessageItem = {
      server_sequence: seq,
      message_uuid: item.message_uuid,
      sender_id: item.sender_id,
      epoch: item.epoch,
      generation: item.generation,
      content_type: item.content_type,
      is_self: true,
      plaintext: selfPlaintext,
      client_sent_at: item.client_sent_at,
      created_at: item.created_at,
    };

    return {
      success: true,
      decryptedItem,
      advancedSequence: seq,
    };
  }

  // Peer message: Validasi Base64 & integritas SHA-256
  let rawBytes: Uint8Array;
  try {
    rawBytes = base64ToBytes(item.ciphertext_payload);
  } catch (b64Err: any) {
    const errorMsg = `Format Base64 ciphertext corrupt pada sequence ${seq}: ${b64Err?.message || 'Invalid base64'}`;
    // Catat tombstone ke encrypted history lokal secara aman demi jejak riwayat lokal tanpa memajukan kursor
    try {
      await saveEncryptedHistoryItem({
        deviceUuid,
        roomUuid,
        sequenceNumber: seq,
        messageUuid: item.message_uuid,
        senderUserId: item.sender_id,
        isSelf: false,
        plaintext: '[Format Corrupt]',
        contentType: item.content_type,
        clientSentAt: item.client_sent_at,
        createdAt: item.created_at,
      });
    } catch {
      // safe ignore secondary IDB error
    }
    return {
      success: false,
      errorCategory: 'PAYLOAD_INTEGRITY_ERROR',
      requiresRecovery: true,
      error: errorMsg,
      decryptedItem: {
        server_sequence: seq,
        message_uuid: item.message_uuid,
        sender_id: item.sender_id,
        epoch: item.epoch,
        generation: item.generation,
        content_type: item.content_type,
        is_self: false,
        plaintext: '[Format Corrupt]',
        client_sent_at: item.client_sent_at,
        created_at: item.created_at,
        error: errorMsg,
      },
    };
  }

  const computedHash = await computePayloadSha256(rawBytes);
  if (computedHash.toLowerCase() !== item.payload_hash_sha256.toLowerCase()) {
    const errorMsg = `Integritas SHA-256 gagal pada sequence ${seq}! Hash tersimpan: ${item.payload_hash_sha256}, hash dihitung: ${computedHash}`;
    // Catat tombstone record ke encrypted history lokal secara aman
    try {
      await saveEncryptedHistoryItem({
        deviceUuid,
        roomUuid,
        sequenceNumber: seq,
        messageUuid: item.message_uuid,
        senderUserId: item.sender_id,
        isSelf: false,
        plaintext: '[Integritas Gagal]',
        contentType: item.content_type,
        clientSentAt: item.client_sent_at,
        createdAt: item.created_at,
      });
    } catch {
      // safe ignore secondary IDB error
    }
    return {
      success: false,
      errorCategory: 'PAYLOAD_INTEGRITY_ERROR',
      requiresRecovery: true,
      error: errorMsg,
      decryptedItem: {
        server_sequence: seq,
        message_uuid: item.message_uuid,
        sender_id: item.sender_id,
        epoch: item.epoch,
        generation: item.generation,
        content_type: item.content_type,
        is_self: false,
        plaintext: '[Integritas Gagal]',
        client_sent_at: item.client_sent_at,
        created_at: item.created_at,
        error: errorMsg,
      },
    };
  }

  // Cek apakah pesan peer ini sudah pernah didekripsi SECARA VALID dan tersimpan di riwayat lokal terenkripsi.
  // Tombstone kegagalan ([Dekripsi Gagal], [Integritas Gagal], [Format Corrupt]) TIDAK BOLEH dianggap sukses
  // dan tidak boleh memajukan cursor kontigu.
  try {
    const existingHistory = await getEncryptedHistoryItem(deviceUuid, roomUuid, seq);
    if (
      existingHistory &&
      existingHistory.plaintext &&
      !isHistoryTombstonePlaintext(existingHistory.plaintext)
    ) {
      if (allowCursorAdvance) {
        try {
          await updateGroupStateCursor({
            deviceUuid,
            roomUuid,
            sequence: seq,
            fencing: {
              ownerToken: fencing.ownerToken,
              generation: fencing.generation,
            },
          });
        } catch {
          // non-fatal
        }
      }
      return {
        success: true,
        decryptedItem: {
          server_sequence: seq,
          message_uuid: item.message_uuid,
          sender_id: item.sender_id,
          epoch: item.epoch,
          generation: item.generation,
          content_type: item.content_type,
          is_self: false,
          plaintext: existingHistory.plaintext,
          client_sent_at: item.client_sent_at,
          created_at: item.created_at,
        },
        advancedSequence: allowCursorAdvance ? seq : undefined,
      };
    }
  } catch {
    // Non-fatal: lanjutkan ke Worker DECRYPT_MESSAGE jika query history lokal gagal
  }

  // Panggil Worker MLS DECRYPT_MESSAGE
  // Jika allowCursorAdvance=false (sedang ada sequence gap di belakangnya), jangan kirim serverSequence
  // agar Worker tetap mem-persist GroupState ratchet MLS secara atomik TANPA memajukan last_processed_sequence melewati gap.
  try {
    if (!workerRpc) {
      throw new Error('Worker MLS tidak tersedia atau perangkat berada dalam mode Read-Only History.');
    }

    const decResult = await workerRpc.sendRequest<any, DecryptMessageResult>(
      'DECRYPT_MESSAGE',
      {
        ciphertextWireBase64: item.ciphertext_payload,
        deviceUuid,
        roomUuid,
        fencing,
        serverSequence: allowCursorAdvance ? seq : undefined,
      }
    );

    // Persistensikan pesan peer terdekripsi ke riwayat lokal terenkripsi (AES-256-GCM via KEK)
    try {
      await saveEncryptedHistoryItem({
        deviceUuid,
        roomUuid,
        sequenceNumber: seq,
        messageUuid: item.message_uuid,
        senderUserId: item.sender_id,
        isSelf: false,
        plaintext: decResult.plaintext,
        contentType: item.content_type,
        clientSentAt: item.client_sent_at,
        createdAt: item.created_at,
      });
    } catch {
      // Non-fatal jika penyimpanan riwayat lokal mengalami kendala sekunder
    }

    const decryptedItem: DecryptedMessageItem = {
      server_sequence: seq,
      message_uuid: item.message_uuid,
      sender_id: item.sender_id,
      epoch: item.epoch,
      generation: item.generation,
      content_type: item.content_type,
      is_self: false,
      plaintext: decResult.plaintext,
      client_sent_at: item.client_sent_at,
      created_at: item.created_at,
    };

    return {
      success: true,
      decryptedItem,
      advancedSequence: seq,
    };
  } catch (decErr: any) {
    const errorMsg = decErr?.message || 'Dekripsi MLS gagal';
    const category = classifyInboundError(decErr, { source: 'DECRYPT_MESSAGE' });
    const isCryptoFatal = category === 'MLS_CRYPTO_ERROR';

    // Catat kegagalan ke riwayat lokal jika bukan transient error
    if (category !== 'TRANSIENT_ERROR') {
      try {
        await saveEncryptedHistoryItem({
          deviceUuid,
          roomUuid,
          sequenceNumber: seq,
          messageUuid: item.message_uuid,
          senderUserId: item.sender_id,
          isSelf: false,
          plaintext: '[Dekripsi Gagal]',
          contentType: item.content_type,
          clientSentAt: item.client_sent_at,
          createdAt: item.created_at,
        });
      } catch {
        // safe ignore secondary IDB error
      }
    }

    return {
      success: false,
      errorCategory: category,
      requiresRecovery: isCryptoFatal,
      error: errorMsg,
      decryptedItem: {
        server_sequence: seq,
        message_uuid: item.message_uuid,
        sender_id: item.sender_id,
        epoch: item.epoch,
        generation: item.generation,
        content_type: item.content_type,
        is_self: false,
        plaintext: '[Dekripsi Gagal]',
        client_sent_at: item.client_sent_at,
        created_at: item.created_at,
        error: errorMsg,
      },
    };
  }
}

/**
 * Memproses satu item pesan inbound dengan retry eksponensial HANYA untuk transient error.
 * Mencegah pengulangan operasi yang berpotensi merusak ratchet state.
 */
export async function processInboundMessageItemWithRetry(
  params: ProcessInboundMessageParams,
  maxRetries = 3
): Promise<ProcessInboundMessageResult> {
  let attempt = 0;
  while (attempt < maxRetries) {
    attempt++;
    const res = await processInboundMessageItem(params);
    if (res.success) {
      return res;
    }

    // Hanya retry jika error diklasifikasikan sebagai TRANSIENT_ERROR
    if (res.errorCategory !== 'TRANSIENT_ERROR') {
      return res;
    }

    if (attempt < maxRetries) {
      const delay = Math.min(100 * Math.pow(2, attempt - 1), 1000);
      await sleep(delay);

      // Sebelum re-attempt dekripsi worker, periksa apakah state sebenarnya sudah sempat commit
      try {
        const existing = await getEncryptedHistoryItem(
          params.deviceUuid,
          params.roomUuid,
          params.item.server_sequence
        );
        if (
          existing &&
          existing.plaintext &&
          !existing.plaintext.startsWith('[Dekripsi Gagal') &&
          !existing.plaintext.startsWith('[Integritas Gagal') &&
          !existing.plaintext.startsWith('[Format Corrupt')
        ) {
          return {
            success: true,
            decryptedItem: {
              server_sequence: params.item.server_sequence,
              message_uuid: params.item.message_uuid,
              sender_id: params.item.sender_id,
              epoch: params.item.epoch,
              generation: params.item.generation,
              content_type: params.item.content_type,
              is_self: false,
              plaintext: existing.plaintext,
              client_sent_at: params.item.client_sent_at,
              created_at: params.item.created_at,
            },
            advancedSequence: params.item.server_sequence,
          };
        }
      } catch {
        // safe ignore
      }

      // Jika worker sempat mengunci sesi karena kegagalan persistensi IDB, pulihkan sesi worker
      // dari GroupState konsisten yang tersimpan di IndexedDB
      try {
        await params.workerRpc.sendRequest('RESTORE_GROUP_STATE', {
          deviceUuid: params.deviceUuid,
          roomUuid: params.roomUuid,
          fencing: params.fencing,
        });
      } catch {
        // safe ignore restore failure during retry prep
      }
    }
  }

  return {
    success: false,
    errorCategory: 'TRANSIENT_ERROR',
    error: `Kegagalan transien persisten setelah ${maxRetries} kali percobaan.`,
    decryptedItem: {
      server_sequence: params.item.server_sequence,
      message_uuid: params.item.message_uuid,
      sender_id: params.item.sender_id,
      epoch: params.item.epoch,
      generation: params.item.generation,
      content_type: params.item.content_type,
      is_self: false,
      plaintext: '[Gagal Memproses Pesan]',
      client_sent_at: params.item.client_sent_at,
      created_at: params.item.created_at,
      error: `Kegagalan transien persisten setelah ${maxRetries} kali percobaan.`,
    },
  };
}

/**
 * Merekonsiliasi pesan dari REST API (Single Source of Truth) dengan pagination loop:
 * - Memisahkan secara ketat Contiguous Verified Cursor (latestSequence/contiguousSequence)
 *   dari REST pagination cursor (currentFetchCursor) dan sequence gagal (unresolvedSequences).
 * - Kursor kontigu TIDAK PERNAH dimajukan melewati pesan yang gagal atau celah sequence.
 * - Kegagalan permanen pada satu pesan (MLS_CRYPTO_ERROR / PAYLOAD_INTEGRITY_ERROR) dicatat
 *   sebagai unresolved/recovery-required TANPA diam-diam menghentikan pemrosesan sequence
 *   berikutnya yang sah (seperti self-message atau pesan generation berikutnya).
 * - Kegagalan transien (TRANSIENT_ERROR) tetap menghentikan batch agar retry berjalan berurutan.
 * - Pesan yang sudah berhasil tersimpan (di processedSequences) dilewati tanpa dekripsi ulang
 *   dan tanpa duplikasi.
 */
export async function reconcileInboundMessages(
  params: ReconcileInboundMessagesParams
): Promise<ReconcileInboundMessagesResult> {
  const {
    roomUuid,
    deviceUuid,
    currentUserId,
    workerRpc,
    fencing,
    afterSequence,
    sessionFloor,
    sessionUuid,
    batchLimit = 50,
    processedSequences,
    unresolvedSequences,
    retryUnresolved = false,
    onMessageDecrypted,
  } = params;

  if (!roomUuid || typeof roomUuid !== 'string') {
    throw new BchatMessageValidationError('roomUuid wajib berupa string yang valid.');
  }
  if (!deviceUuid || typeof deviceUuid !== 'string') {
    throw new BchatMessageValidationError('deviceUuid wajib berupa string yang valid.');
  }
  if (!workerRpc) {
    throw new BchatMessageValidationError('workerRpc instance wajib disertakan.');
  }
  if (!fencing || !fencing.ownerToken || !fencing.generation) {
    throw new BchatMessageValidationError('Kredensial fencing wajib disertakan secara lengkap.');
  }

  const processedSet = processedSequences ?? new Set<number>();
  const unresolvedMap = unresolvedSequences ?? new Map<number, UnresolvedSequenceDetail>();

  const effectiveSessionFloor =
    typeof sessionFloor === 'number' && Number.isInteger(sessionFloor) && sessionFloor > 0
      ? sessionFloor
      : 0;
  const normalizedSessionUuid =
    typeof sessionUuid === 'string' && sessionUuid.trim() !== ''
      ? sessionUuid.trim().toLowerCase()
      : undefined;

  const baseAfterSequence = Math.max(
    effectiveSessionFloor,
    afterSequence !== undefined && afterSequence > 0 ? afterSequence : 0
  );

  let contiguousCursor = computeContiguousResolvedSequence(
    processedSet,
    baseAfterSequence
  );
  let currentFetchCursor = contiguousCursor;
  let highestSeenSequence = contiguousCursor;
  let reconciledCount = 0;
  const processedItems: DecryptedMessageItem[] = [];
  const resolvedSequences: number[] = [];

  const syncContiguousCursor = async () => {
    const nextContiguous = computeContiguousResolvedSequence(processedSet, contiguousCursor);
    if (nextContiguous > contiguousCursor) {
      contiguousCursor = nextContiguous;
      try {
        await updateGroupStateCursor({
          deviceUuid,
          roomUuid,
          sequence: contiguousCursor,
          fencing: {
            ownerToken: fencing.ownerToken,
            generation: fencing.generation,
          },
        });
      } catch {
        // Non-fatal: kegagalan sinkronisasi kursor sekunder tidak membatalkan hasil dekripsi valid
      }
    }
  };

  let hasMore = true;
  let pageCount = 0;
  const MAX_PAGES = 100; // Perlindungan terhadap infinite loop (hingga 5.000 pesan)

  while (hasMore && pageCount < MAX_PAGES) {
    pageCount++;

    const query: BchatFetchMessagesQuery = {
      limit: batchLimit,
      ...(currentFetchCursor > 0 ? { after_sequence: currentFetchCursor } : {}),
      ...(normalizedSessionUuid ? { session_uuid: normalizedSessionUuid } : {}),
    };

    let fetchRes;
    try {
      fetchRes = await fetchWithTransientRetry(roomUuid, query, 3);
    } catch (fetchErr: any) {
      const unresolvedList = Array.from(unresolvedMap.values()).sort(
        (a, b) => a.server_sequence - b.server_sequence
      );
      return {
        reconciledCount,
        latestSequence: contiguousCursor,
        contiguousSequence: contiguousCursor,
        highestSeenSequence,
        hasErrors: true,
        failedSequence: unresolvedList[0]?.server_sequence,
        errorCategory: 'TRANSIENT_ERROR',
        requiresRecovery: unresolvedList.some((u) => u.requiresRecovery),
        error: `Gagal mengambil batch pesan dari REST server: ${fetchErr?.message || 'Network error'}`,
        unresolvedSequences: unresolvedList,
        resolvedSequences,
        processedItems,
      };
    }

    const rawList = fetchRes?.data || [];
    if (rawList.length === 0) {
      break;
    }

    // Sort ascending by server_sequence untuk menjamin urutan ratchet terjaga
    const sortedList = [...rawList].sort((a, b) => a.server_sequence - b.server_sequence);

    let batchMaxSeenSeq = currentFetchCursor;

    for (const item of sortedList) {
      const seq = item.server_sequence;
      batchMaxSeenSeq = Math.max(batchMaxSeenSeq, seq);

      // 0. Perlindungan batas sesi aktif (defense-in-depth):
      //    Pesan dari sesi ARCHIVED (sequence <= sessionFloor atau session_uuid berbeda dari sesi aktif)
      //    DILARANG masuk ke dekripsi menggunakan GroupState sesi aktif. Riwayat lama tetap utuh.
      if (effectiveSessionFloor > 0 && seq <= effectiveSessionFloor) {
        continue;
      }
      if (
        normalizedSessionUuid &&
        typeof item.session_uuid === 'string' &&
        item.session_uuid.trim() !== '' &&
        item.session_uuid.trim().toLowerCase() !== normalizedSessionUuid
      ) {
        continue;
      }

      highestSeenSequence = Math.max(highestSeenSequence, seq);

      // 1. Jika sequence sudah pernah diproses & tersimpan secara valid -> skip (zero duplicate, zero re-decrypt)
      if (processedSet.has(seq)) {
        await syncContiguousCursor();
        continue;
      }

      // 2. Jika sequence sudah tercatat sebagai unresolved permanen dan bukan mode retry eksplisit:
      //    periksa apakah riwayat lokal kini sudah memiliki plaintext sah (mis. hasil History Transfer).
      //    Jika belum, jangan panggil Worker DECRYPT_MESSAGE berulang kali, tahan contiguousCursor,
      //    dan tetap lanjutkan memproses sequence berikutnya yang sah.
      if (!retryUnresolved && unresolvedMap.has(seq)) {
        let recoveredFromHistory: DecryptedMessageItem | null = null;
        try {
          const existing = await getEncryptedHistoryItem(deviceUuid, roomUuid, seq);
          if (existing && existing.plaintext && !isHistoryTombstonePlaintext(existing.plaintext)) {
            recoveredFromHistory = {
              server_sequence: seq,
              message_uuid: item.message_uuid,
              sender_id: item.sender_id,
              epoch: item.epoch,
              generation: item.generation,
              content_type: item.content_type,
              is_self: item.sender_id === currentUserId,
              plaintext: existing.plaintext,
              client_sent_at: item.client_sent_at,
              created_at: item.created_at,
            };
          }
        } catch {
          // safe ignore
        }

        if (recoveredFromHistory) {
          unresolvedMap.delete(seq);
          processedSet.add(seq);
          resolvedSequences.push(seq);
          processedItems.push(recoveredFromHistory);
          onMessageDecrypted?.(recoveredFromHistory);
          reconciledCount++;
          await syncContiguousCursor();
        }
        continue;
      }

      // 3. Tentukan apakah item ini boleh memajukan persistent cursor GroupState secara langsung:
      //    HANYA jika tepat berada di (contiguousCursor + 1) dan tidak ada unresolved sequence di bawahnya.
      const hasLowerUnresolved = Array.from(unresolvedMap.keys()).some((uSeq) => uSeq < seq);
      const canAdvanceCursor = seq === contiguousCursor + 1 && !hasLowerUnresolved;

      const res = await processInboundMessageItemWithRetry({
        item,
        roomUuid,
        deviceUuid,
        currentUserId,
        workerRpc,
        fencing,
        allowCursorAdvance: canAdvanceCursor,
      });

      processedItems.push(res.decryptedItem);
      onMessageDecrypted?.(res.decryptedItem);

      if (!res.success) {
        const category: BchatInboundErrorCategory = res.errorCategory ?? 'TRANSIENT_ERROR';

        // Transient error: hentikan batch segera agar retry tetap terurut tanpa melompati item
        if (category === 'TRANSIENT_ERROR') {
          const unresolvedList = Array.from(unresolvedMap.values()).sort(
            (a, b) => a.server_sequence - b.server_sequence
          );
          return {
            reconciledCount,
            latestSequence: contiguousCursor,
            contiguousSequence: contiguousCursor,
            highestSeenSequence,
            hasErrors: true,
            failedSequence: seq,
            errorCategory: category,
            requiresRecovery:
              res.requiresRecovery ?? unresolvedList.some((u) => u.requiresRecovery),
            error: res.error,
            unresolvedSequences: unresolvedList,
            resolvedSequences,
            processedItems,
          };
        }

        // Permanent crypto / integrity failure:
        // Catat sebagai unresolved & recovery-required, TAHAN contiguousCursor (JANGAN dimajukan!),
        // namun TETAP lanjutkan memproses item sequence berikutnya dalam batch.
        unresolvedMap.set(seq, {
          server_sequence: seq,
          message_uuid: item.message_uuid,
          errorCategory: category,
          error: res.error || `Gagal memproses pesan sequence #${seq}`,
          requiresRecovery:
            res.requiresRecovery ??
            (category === 'MLS_CRYPTO_ERROR' || category === 'PAYLOAD_INTEGRITY_ERROR'),
        });
        continue;
      }

      // Sukses: bersihkan dari unresolvedMap jika sebelumnya pernah gagal, tambahkan ke processedSet,
      // dan majukan contiguousCursor HANYA jika tidak ada celah di belakangnya.
      unresolvedMap.delete(seq);
      processedSet.add(seq);
      resolvedSequences.push(seq);
      reconciledCount++;
      await syncContiguousCursor();
    }

    // Jika jumlah item yang dikembalikan server kurang dari limit, seluruh pesan sudah diambil
    if (rawList.length < batchLimit) {
      hasMore = false;
    } else {
      // Majukan kursor pagination REST (BUKAN contiguousCursor) agar halaman berikutnya dapat diambil
      if (batchMaxSeenSeq > currentFetchCursor) {
        currentFetchCursor = batchMaxSeenSeq;
      } else {
        break;
      }
    }
  }

  const unresolvedList = Array.from(unresolvedMap.values()).sort(
    (a, b) => a.server_sequence - b.server_sequence
  );
  const firstUnresolved = unresolvedList[0];

  return {
    reconciledCount,
    latestSequence: contiguousCursor,
    contiguousSequence: contiguousCursor,
    highestSeenSequence,
    hasErrors: unresolvedList.length > 0,
    failedSequence: firstUnresolved?.server_sequence,
    errorCategory: firstUnresolved?.errorCategory,
    requiresRecovery: unresolvedList.some((u) => u.requiresRecovery),
    error: firstUnresolved?.error,
    unresolvedSequences: unresolvedList,
    resolvedSequences,
    processedItems,
  };
}

/**
 * Kompatibilitas alur receive batch (I-04B.4R).
 */
export async function receiveApplicationMessagesFlow(
  params: ReceiveApplicationMessagesParams
): Promise<ReceiveApplicationMessagesResult> {
  const reconcileRes = await reconcileInboundMessages({
    roomUuid: params.roomUuid,
    deviceUuid: params.deviceUuid,
    currentUserId: params.currentUserId,
    workerRpc: params.workerRpc,
    fencing: params.fencing,
    afterSequence: params.query?.after_sequence,
    batchLimit: params.query?.limit,
  });

  return {
    messages: reconcileRes.processedItems,
    latestSequence: reconcileRes.latestSequence,
    count: reconcileRes.reconciledCount,
  };
}

