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
}

export type BchatInboundErrorCategory =
  | 'TRANSIENT_ERROR'
  | 'PAYLOAD_INTEGRITY_ERROR'
  | 'MLS_CRYPTO_ERROR';

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
  batchLimit?: number;
  processedSequences?: Set<number>;
  onMessageDecrypted?: (message: DecryptedMessageItem) => void;
}

export interface ReconcileInboundMessagesResult {
  reconciledCount: number;
  latestSequence: number;
  hasErrors: boolean;
  failedSequence?: number;
  error?: string;
  errorCategory?: BchatInboundErrorCategory;
  requiresRecovery?: boolean;
  processedItems: DecryptedMessageItem[];
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

/**
 * Klasifikasi kategori error pesan masuk ke dalam 3 taksonomi ketat:
 * 1. PAYLOAD_INTEGRITY_ERROR (Base64 rusak, format corrupt, hash mismatch)
 * 2. MLS_CRYPTO_ERROR (Epoch mismatch, Desired gen in the past, signature invalid)
 * 3. TRANSIENT_ERROR (IndexedDB lock/busy sementara, kegagalan jaringan sementara)
 */
export function classifyInboundError(error: unknown): BchatInboundErrorCategory {
  const msg = error instanceof Error ? error.message : String(error || '');
  const lower = msg.toLowerCase();

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

  // 2. MLS Cryptographic / State Error
  if (
    lower.includes('epoch') ||
    lower.includes('generation') ||
    lower.includes('desired gen in the past') ||
    lower.includes('desired generation too far in the future') ||
    lower.includes('signature invalid') ||
    lower.includes('cryptoverificationerror') ||
    lower.includes('state grup belum aktif') ||
    lower.includes('sesi worker terkunci') ||
    lower.includes('bukan applicationmessage') ||
    lower.includes('cannot process message, epoch too old') ||
    lower.includes('fencing') ||
    lower.includes('stale') ||
    lower.includes('unrecoverable')
  ) {
    return 'MLS_CRYPTO_ERROR';
  }

  // 3. Transient Error (Default for IDB busy/locks, temporary network blips, or timeouts)
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
    throw new BchatMessageValidationError('workerRpc instance wajib disertakan.');
  }
  if (!fencing || !fencing.ownerToken || !fencing.generation) {
    throw new BchatMessageValidationError('Kredensial fencing wajib disertakan secara lengkap.');
  }

  const clientMessageUuid =
    params.clientMessageUuid ||
    (typeof crypto !== 'undefined' && crypto.randomUUID
      ? crypto.randomUUID()
      : 'msg_' + Date.now());

  // 1. Enkripsi via MLS Worker (Persist-Before-Adopt dilakukan di dalam Worker)
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
    throw new BchatMessageError(
      `Gagal mengenkripsi pesan aplikasi pada Worker: ${encErr?.message || 'Unknown error'}`,
      encErr
    );
  }

  if (!encResult || !encResult.ciphertextWireBase64) {
    throw new BchatMessageError('Hasil enkripsi Worker tidak mengembalikan ciphertextBase64.');
  }

  // 2. Dekode raw bytes dan hitung SHA-256 integrity hash
  const wireBytes = base64ToBytes(encResult.ciphertextWireBase64);
  const payloadHashSha256 = await computePayloadSha256(wireBytes);

  // 3. Susun request payload pengiriman
  const contentType = params.contentType ?? 1;
  const epochNumber = parseInt(encResult.epoch, 10);
  const clientSentAt = new Date().toISOString();

  const sendPayload: BchatSendMessageRequest = {
    message_uuid: clientMessageUuid,
    epoch: epochNumber,
    generation: 0,
    content_type: contentType,
    ciphertext_payload: encResult.ciphertextWireBase64,
    payload_hash_sha256: payloadHashSha256,
    client_sent_at: clientSentAt,
  };

  // 4. Kirim ciphertext ke Backend REST API
  try {
    const res = await sendMessage(roomUuid, sendPayload);

    // 5. Berhasil: Tandai outbox message lokal sebagai 'acked'
    try {
      await workerRpc.sendRequest('UPDATE_OUTBOX_STATUS', {
        clientMessageUuid,
        status: 'acked',
        sentAt: Date.now(),
        deviceUuid,
        fencing,
      });
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
      epoch: encResult.epoch,
      payloadHashSha256,
      isReplayed,
    };
  } catch (apiErr: any) {
    // 6. Gagal: Jika ambigu/network error, tandai outbox sebagai 'in_transit' untuk safe retry
    try {
      await workerRpc.sendRequest('UPDATE_OUTBOX_STATUS', {
        clientMessageUuid,
        status: 'in_transit',
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
  const { item, roomUuid, deviceUuid, currentUserId, workerRpc, fencing } = params;
  const seq = item.server_sequence;
  const isSelf = item.sender_id === currentUserId;

  if (isSelf) {
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

  // Cek apakah pesan peer ini sudah pernah didekripsi dan tersimpan di riwayat lokal terenkripsi (AES-256-GCM via KEK)
  // Menghindari dekripsi ulang pada OpenMLS Worker yang dapat memicu error ratchet "Desired gen in the past"
  try {
    const existingHistory = await getEncryptedHistoryItem(deviceUuid, roomUuid, seq);
    if (existingHistory && existingHistory.plaintext) {
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
        advancedSequence: seq,
      };
    }
  } catch {
    // Non-fatal: lanjutkan ke Worker DECRYPT_MESSAGE jika query history lokal gagal
  }

  // Panggil Worker MLS DECRYPT_MESSAGE
  try {
    const decResult = await workerRpc.sendRequest<any, DecryptMessageResult>(
      'DECRYPT_MESSAGE',
      {
        ciphertextWireBase64: item.ciphertext_payload,
        deviceUuid,
        roomUuid,
        fencing,
        serverSequence: seq,
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
    const category = classifyInboundError(decErr);
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
 * - Mengambil pesan bertahap (batch) sampai seluruh pesan tertinggal diproses.
 * - Menerapkan Error Isolation & Persist-Before-Adopt: kursor TIDAK dimajukan melampaui pesan yang gagal.
 * - Menangani transient error via exponential backoff (maks 3x).
 * - Menandai error permanen (MLS / Integrity) secara fail-closed dan menghentikan pagination.
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
    batchLimit = 50,
    processedSequences,
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
  let currentFetchCursor = afterSequence !== undefined && afterSequence > 0 ? afterSequence : 0;
  let currentLatest = currentFetchCursor;
  let reconciledCount = 0;
  const processedItems: DecryptedMessageItem[] = [];

  let hasMore = true;
  let pageCount = 0;
  const MAX_PAGES = 100; // Perlindungan terhadap infinite loop (hingga 5.000 pesan)

  while (hasMore && pageCount < MAX_PAGES) {
    pageCount++;

    const query: BchatFetchMessagesQuery = {
      limit: batchLimit,
      ...(currentFetchCursor > 0 ? { after_sequence: currentFetchCursor } : {}),
    };

    let fetchRes;
    try {
      fetchRes = await fetchWithTransientRetry(roomUuid, query, 3);
    } catch (fetchErr: any) {
      return {
        reconciledCount,
        latestSequence: currentLatest,
        hasErrors: true,
        errorCategory: 'TRANSIENT_ERROR',
        error: `Gagal mengambil batch pesan dari REST server: ${fetchErr?.message || 'Network error'}`,
        processedItems,
      };
    }

    const rawList = fetchRes?.data || [];
    if (rawList.length === 0) {
      break;
    }

    // Sort ascending by server_sequence untuk menjamin urutan ratchet terjaga
    const sortedList = [...rawList].sort((a, b) => a.server_sequence - b.server_sequence);

    let batchMaxProcessedSeq = currentFetchCursor;

    for (const item of sortedList) {
      const seq = item.server_sequence;
      if (processedSet.has(seq)) {
        batchMaxProcessedSeq = Math.max(batchMaxProcessedSeq, seq);
        continue;
      }

      const res = await processInboundMessageItemWithRetry({
        item,
        roomUuid,
        deviceUuid,
        currentUserId,
        workerRpc,
        fencing,
      });

      processedItems.push(res.decryptedItem);
      onMessageDecrypted?.(res.decryptedItem);

      if (!res.success) {
        // Strict Fail-Closed: Kursor TIDAK dimajukan melewati pesan yang gagal diproses!
        // Berhenti segera dan kembalikan detail status failure
        return {
          reconciledCount,
          latestSequence: currentLatest,
          hasErrors: true,
          failedSequence: seq,
          errorCategory: res.errorCategory,
          requiresRecovery: res.requiresRecovery ?? (res.errorCategory === 'MLS_CRYPTO_ERROR' || res.errorCategory === 'PAYLOAD_INTEGRITY_ERROR'),
          error: res.error,
          processedItems,
        };
      }

      currentLatest = Math.max(currentLatest, seq);
      batchMaxProcessedSeq = Math.max(batchMaxProcessedSeq, seq);
      processedSet.add(seq);
      reconciledCount++;
    }

    // Jika jumlah item yang dikembalikan server kurang dari limit, seluruh pesan sudah diambil
    if (rawList.length < batchLimit) {
      hasMore = false;
    } else {
      // Majukan kursor fetch ke urutan tertinggi batch ini hanya jika kursor berhasil maju
      if (batchMaxProcessedSeq > currentFetchCursor) {
        currentFetchCursor = batchMaxProcessedSeq;
      } else {
        // Tidak ada kemajuan sequence pada halaman ini -> hentikan loop
        break;
      }
    }
  }

  return {
    reconciledCount,
    latestSequence: currentLatest,
    hasErrors: false,
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

