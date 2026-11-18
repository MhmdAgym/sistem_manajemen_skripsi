/**
 * B-CHAT E2EE Protocol Layer — Handshake Coordinator (I-03)
 *
 * Mengorkestrasikan Welcome Handshake E2EE antara mahasiswa (inisiator) dan dosen pembimbing
 * untuk mengaktifkan ruang obrolan bimbingan proposal skripsi.
 *
 * Mengintegrasikan:
 * 1. API Client I-01 (Discovery, Partner KeyPackage, Welcome Publish, Challenge, Welcome ACK).
 * 2. Dedicated MLS Worker I-02B (CREATE_GROUP, COMMIT_ADD_MEMBER, JOIN_WELCOME, UPDATE_OUTBOX_STATUS, GET_ROOM_OUTBOX, RESTORE_GROUP_STATE).
 * 3. Welcome ACK Builder I-03 (Payload kanonis 158 byte & Ed25519 Detached Signature 64 byte).
 * 4. Multi-store IndexedDB Persistence (Group States, Protocol Outbox, Pending KeyPackages).
 *
 * Invarian Protokol & Keamanan:
 * - Mahasiswa membuat MLS Group epoch 0 lalu menambahkan Dosen via Commit Add Member (epoch 1).
 * - Commit MLS TIDAK PERNAH dikirim ke backend; Commit tetap lokal di IndexedDB message_outbox.
 * - HANYA ciphertext pesan Welcome yang diunggah ke POST /api/bchat/rooms/{roomUuid}/welcome.
 * - GroupState dan ciphertext outbox WAJIB tersimpan secara atomik di IndexedDB sebelum publikasi.
 * - Dosen memvalidasi action hint, mengambil Welcome challenge, dan memproses JOIN_WELCOME.
 * - PrivateKeyPackage dosen dikonsumsi secara atomik bersama penyimpanan GroupState saat JOIN_WELCOME.
 * - Welcome ACK 158 byte ditandatangani HANYA dengan Ed25519 Identity Key perangkat dosen resmi.
 * - Respons backend adalah SATU-SATUNYA sumber kebenaran aktivasi room (bukan Reverb WebSocket).
 * - Tidak ada auto-recommit dari state lama apabila publikasi Welcome gagal.
 * - Kredensial fencing wajib disertakan secara eksplisit tanpa fallback disk yang melemahkan.
 * - Recovery Mahasiswa: Jika publikasi Welcome gagal ambigu (timeout, network, 408, 409, 5xx), outbox ditandai 'in_transit' dan direkonsiliasi.
 * - Recovery Dosen: Jika GroupState epoch 1 sudah tersimpan (Crash 2, 3, 4), JOIN_WELCOME dilewati agar anti-reuse PrivateKeyPackage tidak tertabrak.
 */

import {
  fetchRoomDetail,
  fetchPartnerKeyPackage,
  publishWelcome,
  fetchOrRotateWelcomeChallenge,
  submitWelcomeAck,
  createNextRoomSession,
  fetchPendingRoomSession,
  publishSessionWelcome,
  fetchSessionWelcomeChallenge,
  submitSessionWelcomeAck,
  BchatApiError,
} from '@/lib/bchat/api/bchat-api-client';
import type {
  BchatRoomDetail,
  BchatPartnerKeyPackageResponseData,
  BchatWelcomePublishRequest,
  BchatWelcomePublishResponseData,
  BchatWelcomeChallengeResponseData,
  BchatWelcomeAckRequest,
  BchatWelcomeAckResponseData,
  BchatRoomSessionData,
  BchatSessionWelcomePublishRequest,
  BchatSessionWelcomeChallengeResponseData,
  BchatSessionWelcomeAckRequest,
} from '@/lib/bchat/api/bchat-api-types';
import type { BchatWorkerClient } from '@/lib/bchat/bchat-poc-rpc';
import type {
  GenerateKeyPackagePayload,
  GenerateKeyPackageResult,
  CreateGroupPayload,
  CreateGroupResult,
  CommitAddMemberPayload,
  CommitAddMemberResult,
  JoinWelcomePayload,
  JoinWelcomeResult,
  UpdateOutboxStatusPayload,
  UpdateOutboxStatusResult,
  GetRoomOutboxPayload,
  GetRoomOutboxResult,
  OutboxItemSummary,
  RestoreGroupStatePayload,
  RestoreGroupStateResult,
  FencingRpcInput,
} from '@/lib/bchat/bchat-poc-types';
import { getActiveDeviceIdentityKey } from '@/lib/bchat/device/bchat-device-registration';
import { withReadonlyTransaction } from '@/lib/bchat/storage/bchat-idb-core';
import {
  BCAT_STORES,
  type PendingKeyPackageRecord,
} from '@/lib/bchat/storage/bchat-idb-schema';
import {
  buildWelcomeAckPayload,
  signWelcomeAckWithDeviceIdentity,
  CANONICAL_UUID_REGEX,
  DECIMAL_STRING_REGEX,
  SHA256_HEX_REGEX,
} from '@/lib/bchat/handshake/bchat-welcome-ack-builder';

// -----------------------------------------------------------------------------
// KELAS ERROR DOMAIN HANDSHAKE
// -----------------------------------------------------------------------------

export class BchatHandshakeCoordinatorError extends Error {
  readonly isBchatHandshakeCoordinatorError = true;
  readonly errorCode: string;
  readonly originalError?: unknown;
  readonly context?: Record<string, unknown>;

  constructor(
    message: string,
    errorCode: string,
    originalError?: unknown,
    context?: Record<string, unknown>
  ) {
    super(message);
    this.name = 'BchatHandshakeCoordinatorError';
    this.errorCode = errorCode;
    this.originalError = originalError;
    this.context = context;
    Object.setPrototypeOf(this, BchatHandshakeCoordinatorError.prototype);
  }
}

// -----------------------------------------------------------------------------
// STRUKTUR DATA & HASIL
// -----------------------------------------------------------------------------

export interface PublishWelcomeOptions {
  fencing: FencingRpcInput;
  timeoutMs?: number;
}

export interface PublishWelcomeResult {
  roomUuid: string;
  welcomeOperationUuid: string;
  recipientUserId: string;
  recipientDeviceUuid: string;
  epoch: string;
  challengeGeneration: number;
  publishedAt: string;
  welcomeWireBase64: string;
  clientMessageUuidCommit: string;
  clientMessageUuidWelcome: string;
  groupIdHash: string;
}

export interface FetchChallengeAndAckOptions {
  fencing: FencingRpcInput;
  userId?: string | number | bigint;
  timeoutMs?: number;
}

export interface FetchChallengeAndAckResult {
  roomUuid: string;
  roomStatus: string; // 'ACTIVE'
  currentEpoch: string; // '1'
  activatedAt: string;
  welcomeOperationUuid: string;
  challengeUuid: string;
  ackPayloadHash: string;
  signatureBase64: string;
  groupIdHash: string;
}

// -----------------------------------------------------------------------------
// FUNGSI BANTU VALIDASI & ERROR CLASSIFICATION
// -----------------------------------------------------------------------------

function validateCanonicalUuid(uuid: string, fieldName: string): string {
  if (typeof uuid !== 'string') {
    throw new BchatHandshakeCoordinatorError(
      `Parameter ${fieldName} harus berupa string.`,
      'INVALID_UUID_TYPE'
    );
  }
  const clean = uuid.trim().toLowerCase();
  if (!CANONICAL_UUID_REGEX.test(clean)) {
    throw new BchatHandshakeCoordinatorError(
      `Format ${fieldName} [${uuid}] tidak valid. Wajib berupa UUID v4 kanonis lowercase.`,
      'INVALID_UUID_FORMAT'
    );
  }
  return clean;
}

function validateFencing(
  fencing: FencingRpcInput,
  operationName: string
): { ownerToken: string; generation: string } {
  if (!fencing || typeof fencing !== 'object') {
    throw new BchatHandshakeCoordinatorError(
      `Kredensial fencing wajib disertakan secara eksplisit untuk operasi ${operationName}.`,
      'FENCING_REQUIRED'
    );
  }

  const rawOwnerToken = fencing.ownerToken;
  if (typeof rawOwnerToken !== 'string' || rawOwnerToken.trim().length === 0) {
    throw new BchatHandshakeCoordinatorError(
      `Fencing ownerToken pada ${operationName} tidak valid atau kosong.`,
      'INVALID_FENCING_CREDENTIALS'
    );
  }

  const rawGeneration = fencing.generation;
  if (typeof rawGeneration !== 'string' || !DECIMAL_STRING_REGEX.test(rawGeneration)) {
    throw new BchatHandshakeCoordinatorError(
      `Fencing generation pada ${operationName} [${String(rawGeneration)}] tidak valid. Wajib berupa decimal string kanonis tanpa spasi atau leading zeros.`,
      'INVALID_FENCING_CREDENTIALS'
    );
  }

  try {
    BigInt(rawGeneration);
  } catch (bigIntErr) {
    throw new BchatHandshakeCoordinatorError(
      `Fencing generation pada ${operationName} [${rawGeneration}] tidak dapat diproses menggunakan BigInt.`,
      'INVALID_FENCING_CREDENTIALS',
      bigIntErr
    );
  }

  return {
    ownerToken: rawOwnerToken.trim(),
    generation: rawGeneration,
  };
}

/**
 * Mengklasifikasikan error HTTP 429 (Rate Limit) secara eksplisit sebagai error transient
 * yang dapat dicoba ulang secara idempoten tanpa menganggap payload Welcome gagal permanen.
 */
export function isRateLimitPublishError(err: unknown): boolean {
  return err instanceof BchatApiError && err.status === 429;
}

/**
 * Mengklasifikasikan error jaringan / HTTP menjadi Ambigu (transient / indeterminate)
 * vs Definitif (permanent failure).
 */
export function isAmbiguousPublishError(err: unknown): boolean {
  if (err instanceof BchatApiError) {
    const s = err.status;
    return s === 0 || s === 408 || s === 409 || s >= 500;
  }
  if (err instanceof DOMException && (err.name === 'TimeoutError' || err.name === 'AbortError')) {
    return true;
  }
  const name = (err as Error)?.name;
  if (name === 'TimeoutError' || name === 'AbortError') {
    return true;
  }
  const msg = (err as Error)?.message?.toLowerCase() ?? '';
  return (
    msg.includes('timeout') ||
    msg.includes('network') ||
    msg.includes('aborterror') ||
    msg.includes('econnrefused')
  );
}

const BASE64_WIRE_REGEX = /^[A-Za-z0-9+/]+={0,2}$/;

/**
 * Memverifikasi dan memilih entri outbox Welcome lokal yang valid dan dapat dipublikasikan ulang
 * untuk room tertentu (menerima status 'pending', 'in_transit', maupun 'failed' akibat HTTP 429 sebelumnya).
 * Mengembalikan null (fail-closed) jika tidak ada Welcome valid atau payload korup/kosong.
 */
export function findRecoverablePendingWelcomeOutbox(
  messages: OutboxItemSummary[] | undefined | null,
  roomUuid: string
): OutboxItemSummary | null {
  if (!Array.isArray(messages) || messages.length === 0) {
    return null;
  }
  const normalizedRoomUuid = roomUuid.trim().toLowerCase();

  const validCandidates = messages.filter((m) => {
    if (!m || m.messageType !== 'welcome') return false;
    if (m.status !== 'pending' && m.status !== 'in_transit' && m.status !== 'failed') {
      return false;
    }
    if (typeof m.clientMessageUuid !== 'string' || m.clientMessageUuid.trim() === '') {
      return false;
    }
    if (typeof m.roomUuid !== 'string' || m.roomUuid.trim().toLowerCase() !== normalizedRoomUuid) {
      return false;
    }
    if (typeof m.ciphertextWireBase64 !== 'string') return false;
    const wire = m.ciphertextWireBase64.trim();
    if (wire.length < 44 || wire.length % 4 !== 0 || !BASE64_WIRE_REGEX.test(wire)) {
      return false;
    }
    return true;
  });

  if (validCandidates.length === 0) {
    return null;
  }

  validCandidates.sort((a, b) => (b.createdAt ?? 0) - (a.createdAt ?? 0));
  return validCandidates[0];
}

/**
 * Mendeteksi keberadaan entri outbox Welcome yang belum acked namun rusak/korup/tidak valid
 * (mis. payload kosong atau Base64 cacat), agar evaluasi tetap fail-closed bahkan bila
 * lastProcessedSequence > 0 atau riwayat lokal sudah tersedia.
 */
export function hasInvalidPendingWelcomeOutbox(
  messages: OutboxItemSummary[] | undefined | null,
  roomUuid: string
): boolean {
  if (!Array.isArray(messages) || messages.length === 0) {
    return false;
  }
  const normalizedRoomUuid = roomUuid.trim().toLowerCase();

  return messages.some((m) => {
    if (!m || m.messageType !== 'welcome') return false;
    if (m.status !== 'pending' && m.status !== 'in_transit' && m.status !== 'failed') {
      return false;
    }
    if (typeof m.roomUuid === 'string' && m.roomUuid.trim().toLowerCase() !== normalizedRoomUuid) {
      return false;
    }
    return findRecoverablePendingWelcomeOutbox([m], roomUuid) === null;
  });
}

function hasAnyWelcomeOutboxForRoom(
  messages: OutboxItemSummary[] | undefined | null,
  roomUuid: string
): boolean {
  if (!Array.isArray(messages) || messages.length === 0) {
    return false;
  }
  const normalizedRoomUuid = roomUuid.trim().toLowerCase();
  return messages.some(
    (m) =>
      Boolean(m) &&
      m.messageType === 'welcome' &&
      typeof m.roomUuid === 'string' &&
      m.roomUuid.trim().toLowerCase() === normalizedRoomUuid
  );
}

export type ActiveRoomFoundDecision =
  | { action: 'PROCEED_NORMAL_CHAT' }
  | {
      action: 'AWAIT_PARTNER_SESSION_ACK';
      sessionUuid: string;
      generationNumber: number;
    }
  | {
      action: 'RECOVER_PENDING_SESSION_WELCOME';
      sessionUuid: string;
      generationNumber: number;
      welcomeItem: OutboxItemSummary;
    }
  | {
      action: 'FAIL_CLOSED_MISSING_PENDING_WELCOME';
      sessionUuid: string;
      generationNumber: number;
      reason: string;
    };

/**
 * Mengevaluasi secara deterministik langkah aman ketika room berstatus ACTIVE
 * dan GroupState lokal ditemukan (FOUND).
 *
 * Invarian keamanan:
 * - Sesi PENDING dengan action_type = PUBLISH_SESSION_WELCOME dan has_active_challenge = false
 *   pada perangkat mahasiswa WAJIB memeriksa outbox Welcome lokal meskipun lastProcessedSequence > 0
 *   atau riwayat lokal sudah tersedia:
 *   - Jika Welcome lokal valid tersedia dan belum acked -> RECOVER_PENDING_SESSION_WELCOME (blokir chat normal).
 *   - Jika Welcome lokal rusak/korup atau GroupState tidak valid (atau fresh initiator tanpa Welcome) -> FAIL_CLOSED_MISSING_PENDING_WELCOME.
 *   - Hanya perangkat operasional normal tanpa pending/corrupt Welcome di outbox (seq > 0 / riwayat > 0) yang masuk PROCEED_NORMAL_CHAT.
 */
export function evaluateActiveRoomFoundState(params: {
  roomUuid: string;
  myRole?: string | null;
  pendingSession: BchatRoomDetail['pending_session'] | null | undefined;
  restoreRes: Pick<RestoreGroupStateResult, 'status' | 'epoch' | 'groupIdHash' | 'lastProcessedSequence'>;
  localHistoryCount: number;
  outboxMessages?: OutboxItemSummary[] | null;
}): ActiveRoomFoundDecision {
  const { roomUuid, myRole, pendingSession, restoreRes, localHistoryCount, outboxMessages } = params;

  if (
    restoreRes.status !== 'FOUND' ||
    !pendingSession ||
    pendingSession.status !== 'PENDING'
  ) {
    return { action: 'PROCEED_NORMAL_CHAT' };
  }

  const isFreshInitiatorGroupState =
    restoreRes.epoch === '1' &&
    (restoreRes.lastProcessedSequence ?? 0) === 0 &&
    localHistoryCount === 0;

  if (
    pendingSession.action_type === 'AWAIT_PARTNER_SESSION_ACK' &&
    pendingSession.has_active_challenge === true &&
    (isFreshInitiatorGroupState || hasAnyWelcomeOutboxForRoom(outboxMessages, roomUuid))
  ) {
    return {
      action: 'AWAIT_PARTNER_SESSION_ACK',
      sessionUuid: pendingSession.session_uuid,
      generationNumber: pendingSession.generation_number,
    };
  }

  if (
    pendingSession.action_type === 'PUBLISH_SESSION_WELCOME' &&
    pendingSession.has_active_challenge === false &&
    myRole === 'mahasiswa'
  ) {
    const hasValidGroupIdHash =
      typeof restoreRes.groupIdHash === 'string' &&
      SHA256_HEX_REGEX.test(restoreRes.groupIdHash.trim().toLowerCase());
    const recoverableWelcome = findRecoverablePendingWelcomeOutbox(outboxMessages, roomUuid);
    const hasCorruptWelcome = hasInvalidPendingWelcomeOutbox(outboxMessages, roomUuid);

    if (recoverableWelcome) {
      if (hasValidGroupIdHash && restoreRes.epoch === '1') {
        return {
          action: 'RECOVER_PENDING_SESSION_WELCOME',
          sessionUuid: pendingSession.session_uuid,
          generationNumber: pendingSession.generation_number,
          welcomeItem: recoverableWelcome,
        };
      }

      return {
        action: 'FAIL_CLOSED_MISSING_PENDING_WELCOME',
        sessionUuid: pendingSession.session_uuid,
        generationNumber: pendingSession.generation_number,
        reason: `GroupState lokal untuk sesi tertunda Generasi #${pendingSession.generation_number} tidak valid (epoch: ${restoreRes.epoch ?? 'kosong'}) atau tidak memiliki group_id_hash yang valid.`,
      };
    }

    if (hasCorruptWelcome || isFreshInitiatorGroupState) {
      return {
        action: 'FAIL_CLOSED_MISSING_PENDING_WELCOME',
        sessionUuid: pendingSession.session_uuid,
        generationNumber: pendingSession.generation_number,
        reason: hasCorruptWelcome
          ? `Payload Welcome lokal pada outbox untuk sesi tertunda Generasi #${pendingSession.generation_number} rusak atau tidak dapat diverifikasi.`
          : !hasValidGroupIdHash
            ? `GroupState lokal untuk sesi tertunda Generasi #${pendingSession.generation_number} tidak memiliki group_id_hash yang valid.`
            : `Sesi tertunda Generasi #${pendingSession.generation_number} belum menyelesaikan publikasi Welcome di server dan payload Welcome lokal yang valid tidak ditemukan di outbox perangkat ini.`,
      };
    }
  }

  return { action: 'PROCEED_NORMAL_CHAT' };
}

export interface HistoryTransferPollingEligibility {
  deviceUuid: string | null;
  userId?: number | null;
  onboardingGate: 'PENDING' | 'CHECKING' | 'ONBOARDING' | 'PAIRING' | 'PASSED';
  connectionStatus: 'INITIALIZING' | 'CONNECTED' | 'RECONNECTING' | 'OFFLINE' | 'ERROR';
  hasTransferSourceDevices: boolean;
  hasLocalGroupState: boolean;
  isUnrecoverableActiveRoom: boolean;
  hasWaitingState: boolean;
  isHistoryTransferModalOpen: boolean;
  hasIncomingTransferRequest: boolean;
}

/**
 * Menentukan secara ketat apakah polling GET /api/bchat/history-transfers/pending boleh berjalan.
 * Polling HANYA aktif untuk perangkat sumber yang sudah terhubung dengan GroupState valid
 * dan benar-benar memiliki perangkat tepercaya lain (eligible peer) untuk History Transfer.
 */
export function shouldPollIncomingHistoryTransfers(
  ctx: HistoryTransferPollingEligibility
): boolean {
  return (
    Boolean(ctx.deviceUuid && ctx.deviceUuid.trim() !== '') &&
    typeof ctx.userId === 'number' &&
    ctx.userId > 0 &&
    ctx.onboardingGate === 'PASSED' &&
    ctx.connectionStatus === 'CONNECTED' &&
    ctx.hasTransferSourceDevices === true &&
    ctx.hasLocalGroupState === true &&
    ctx.isUnrecoverableActiveRoom === false &&
    ctx.hasWaitingState === false &&
    ctx.isHistoryTransferModalOpen === false &&
    ctx.hasIncomingTransferRequest === false
  );
}

// -----------------------------------------------------------------------------
// ALUR MAHASISWA — PUBLISH_WELCOME
// -----------------------------------------------------------------------------

/**
 * Mengorkestrasikan alur inisiasi Welcome oleh mahasiswa:
 * 1. Validasi parameter input (roomUuid, deviceUuid, fencing).
 * 2. Ambil detail room dari backend; periksa rekonsiliasi awal (ACTIVE / PENDING_WELCOME_ACK / PENDING_WELCOME).
 * 3. Ambil partner KeyPackage dosen melalui GET /api/bchat/rooms/{roomUuid}/partner-key-package.
 * 4. Periksa apakah outbox Welcome sebelumnya tersedia:
 *    - Jika tersedia: gunakan ciphertext dan metadata record tersebut tanpa membuat Group/Commit baru.
 *    - Jika belum tersedia: buat MLS Group epoch 0 (CREATE_GROUP) dan Commit Add Member epoch 1 (COMMIT_ADD_MEMBER).
 * 5. Commit MLS lokal langsung di-ack di IndexedDB outbox (Commit TIDAK PERNAH dikirim ke backend).
 * 6. Publikasikan Welcome melalui POST /api/bchat/rooms/{roomUuid}/welcome.
 * 7. Tangani error publikasi:
 *    - Ambigu (timeout, 408, 409, 5xx, network): tandai outbox 'in_transit', rekonsiliasi via fetchRoomDetail().
 *    - Definitif (4xx selain 408/409): tandai outbox 'failed' tanpa auto-recommit.
 */
export async function publishWelcomeHandshakeFlow(
  workerRpc: BchatWorkerClient,
  roomUuid: string,
  deviceUuid: string,
  options: PublishWelcomeOptions
): Promise<PublishWelcomeResult> {
  // 1. Validasi input dasar & fencing eksplisit
  const canonicalRoomUuid = validateCanonicalUuid(roomUuid, 'roomUuid');
  const canonicalDeviceUuid = validateCanonicalUuid(deviceUuid, 'deviceUuid');
  const cleanFencing = validateFencing(options?.fencing, 'publishWelcomeHandshakeFlow');

  if (!workerRpc || typeof workerRpc.sendRequest !== 'function') {
    throw new BchatHandshakeCoordinatorError(
      'Worker RPC client tidak valid atau belum diinisialisasi.',
      'INVALID_WORKER_CLIENT'
    );
  }

  const timeoutMs = options.timeoutMs ?? 20000;

  // 2. Ambil detail room dan pastikan metadata room valid
  let roomDetail: BchatRoomDetail;
  try {
    const roomRes = await fetchRoomDetail(canonicalRoomUuid);
    roomDetail = roomRes.data;
  } catch (apiErr) {
    throw new BchatHandshakeCoordinatorError(
      `Gagal mengambil metadata room [${canonicalRoomUuid}] dari backend: ${(apiErr as Error)?.message}`,
      'FETCH_ROOM_DETAIL_FAILED',
      apiErr
    );
  }

  if (!roomDetail || typeof roomDetail !== 'object') {
    throw new BchatHandshakeCoordinatorError(
      `Respons detail room [${canonicalRoomUuid}] tidak valid atau kosong.`,
      'INVALID_ROOM_DETAIL_RESPONSE'
    );
  }

  // REKONSILIASI A.1: Jika room sudah ACTIVE, handshake sudah selesai sempurna
  if (roomDetail.status === 'ACTIVE') {
    let welcomeUuid = '';
    let commitUuid = '';
    let welcomeWire = '';
    try {
      const outboxRes = await workerRpc.sendRequest<GetRoomOutboxPayload, GetRoomOutboxResult>(
        'GET_ROOM_OUTBOX',
        { roomUuid: canonicalRoomUuid }
      );
      const welcomeItem = outboxRes?.messages?.find((m) => m.messageType === 'welcome');
      const commitItem = outboxRes?.messages?.find((m) => m.messageType === 'commit');
      if (welcomeItem) {
        welcomeUuid = welcomeItem.clientMessageUuid;
        welcomeWire = welcomeItem.ciphertextWireBase64;
        if (welcomeItem.status !== 'acked') {
          await workerRpc.sendRequest<UpdateOutboxStatusPayload, UpdateOutboxStatusResult>(
            'UPDATE_OUTBOX_STATUS',
            {
              clientMessageUuid: welcomeItem.clientMessageUuid,
              status: 'acked',
              deviceUuid: canonicalDeviceUuid,
              fencing: cleanFencing,
            },
            5000
          );
        }
      }
      if (commitItem) {
        commitUuid = commitItem.clientMessageUuid;
      }
    } catch {
      // Abaikan kegagalan outbox lookup sekunder
    }

    let recipientUserId = '';
    let recipientDeviceUuid = '';
    try {
      const partnerRes = await fetchPartnerKeyPackage(canonicalRoomUuid, canonicalDeviceUuid);
      recipientUserId = partnerRes?.data?.recipient_user_id ?? '';
      recipientDeviceUuid = partnerRes?.data?.recipient_device_uuid ?? '';
    } catch {
      // KeyPackage mungkin sudah dikonsumsi
    }

    return {
      roomUuid: canonicalRoomUuid,
      welcomeOperationUuid: roomDetail.handshake_context?.welcome_operation_uuid ?? '',
      recipientUserId,
      recipientDeviceUuid,
      epoch: String(roomDetail.current_epoch ?? '1'),
      challengeGeneration: 1,
      publishedAt: roomDetail.updated_at ?? roomDetail.created_at ?? new Date().toISOString(),
      welcomeWireBase64: welcomeWire,
      clientMessageUuidCommit: commitUuid,
      clientMessageUuidWelcome: welcomeUuid,
      groupIdHash: roomDetail.group_id_hash ?? '',
    };
  }

  // REKONSILIASI A.2: Backend sudah menerima Welcome jika room PENDING_WELCOME_ACK,
  // challenge aktif sudah ada, dan action_hint mahasiswa adalah AWAIT_PARTNER_ACK.
  const isWelcomeAlreadyPublished =
    roomDetail.status === 'PENDING_WELCOME_ACK' &&
    roomDetail.handshake_context?.has_active_challenge === true &&
    roomDetail.action_hint?.action_type === 'AWAIT_PARTNER_ACK' &&
    roomDetail.action_hint?.action_required === false;

  if (isWelcomeAlreadyPublished) {
    let welcomeUuid = '';
    let commitUuid = '';
    let welcomeWire = '';
    try {
      const outboxRes = await workerRpc.sendRequest<GetRoomOutboxPayload, GetRoomOutboxResult>(
        'GET_ROOM_OUTBOX',
        { roomUuid: canonicalRoomUuid }
      );
      const welcomeItem = outboxRes?.messages?.find((m) => m.messageType === 'welcome');
      const commitItem = outboxRes?.messages?.find((m) => m.messageType === 'commit');
      if (welcomeItem) {
        welcomeUuid = welcomeItem.clientMessageUuid;
        welcomeWire = welcomeItem.ciphertextWireBase64;
        if (welcomeItem.status !== 'acked') {
          await workerRpc.sendRequest<UpdateOutboxStatusPayload, UpdateOutboxStatusResult>(
            'UPDATE_OUTBOX_STATUS',
            {
              clientMessageUuid: welcomeItem.clientMessageUuid,
              status: 'acked',
              deviceUuid: canonicalDeviceUuid,
              fencing: cleanFencing,
            },
            5000
          );
        }
      }
      if (commitItem) {
        commitUuid = commitItem.clientMessageUuid;
      }
    } catch {
      // Abaikan kegagalan outbox lookup sekunder
    }

    let recipientUserId = '';
    let recipientDeviceUuid = '';
    try {
      const partnerRes = await fetchPartnerKeyPackage(canonicalRoomUuid, canonicalDeviceUuid);
      recipientUserId = partnerRes?.data?.recipient_user_id ?? '';
      recipientDeviceUuid = partnerRes?.data?.recipient_device_uuid ?? '';
    } catch {
      // KeyPackage mungkin sudah dikonsumsi
    }

    return {
      roomUuid: canonicalRoomUuid,
      welcomeOperationUuid: roomDetail.handshake_context?.welcome_operation_uuid ?? '',
      recipientUserId,
      recipientDeviceUuid,
      epoch: String(roomDetail.current_epoch ?? '1'),
      challengeGeneration: 1,
      publishedAt: roomDetail.created_at ?? new Date().toISOString(),
      welcomeWireBase64: welcomeWire,
      clientMessageUuidCommit: commitUuid,
      clientMessageUuidWelcome: welcomeUuid,
      groupIdHash: roomDetail.group_id_hash ?? '',
    };
  }

  // REKONSILIASI A.3: Room baru siap publikasi jika PENDING_WELCOME_ACK tanpa challenge aktif
  // dan action_hint mahasiswa adalah PUBLISH_WELCOME serta capability mengizinkan.
  const isReadyForWelcomePublication =
    roomDetail.status === 'PENDING_WELCOME_ACK' &&
    roomDetail.handshake_context?.has_active_challenge === false &&
    roomDetail.action_hint?.action_type === 'PUBLISH_WELCOME' &&
    roomDetail.action_hint?.action_required === true &&
    roomDetail.capabilities?.can_handshake === true;

  if (!isReadyForWelcomePublication) {
    throw new BchatHandshakeCoordinatorError(
      `Aksi publikasi Welcome tidak diizinkan untuk room [${canonicalRoomUuid}]. Status room: [${roomDetail.status}], action_type: [${roomDetail.action_hint?.action_type ?? 'NONE'}], has_active_challenge: [${roomDetail.handshake_context?.has_active_challenge ?? false}].`,
      'ACTION_NOT_PERMITTED',
      undefined,
      {
        roomStatus: roomDetail.status,
        actionType: roomDetail.action_hint?.action_type,
        actionRequired: roomDetail.action_hint?.action_required,
        hasActiveChallenge: roomDetail.handshake_context?.has_active_challenge,
      }
    );
  }

  // Validasi peran: inisiator wajib mahasiswa
  const isMahasiswa = roomDetail.my_membership?.role === 'mahasiswa';
  const isRecordedInitiator = roomDetail.my_membership?.is_initiator === true;
  if (!isMahasiswa && !isRecordedInitiator) {
    throw new BchatHandshakeCoordinatorError(
      `Pengguna pada perangkat ini bukan inisiator dari room [${canonicalRoomUuid}].`,
      'MEMBERSHIP_ROLE_MISMATCH'
    );
  }

  // 3. Ambil partner KeyPackage dosen
  let partnerPkg: BchatPartnerKeyPackageResponseData;
  try {
    const pkgRes = await fetchPartnerKeyPackage(canonicalRoomUuid, canonicalDeviceUuid);
    partnerPkg = pkgRes.data;
  } catch (apiErr) {
    throw new BchatHandshakeCoordinatorError(
      `Gagal mengambil KeyPackage dosen pembimbing untuk room [${canonicalRoomUuid}]: ${(apiErr as Error)?.message}`,
      'FETCH_PARTNER_KEY_PACKAGE_FAILED',
      apiErr
    );
  }

  if (!partnerPkg || !partnerPkg.key_package_data || !partnerPkg.recipient_device_uuid) {
    throw new BchatHandshakeCoordinatorError(
      `Data KeyPackage partner untuk room [${canonicalRoomUuid}] tidak lengkap atau kosong.`,
      'INVALID_PARTNER_KEY_PACKAGE'
    );
  }

  const partnerDeviceUuid = validateCanonicalUuid(
    partnerPkg.recipient_device_uuid,
    'recipient_device_uuid'
  );
  const partnerPkgHash = partnerPkg.key_package_hash?.trim().toLowerCase();
  if (!SHA256_HEX_REGEX.test(partnerPkgHash)) {
    throw new BchatHandshakeCoordinatorError(
      `Hash KeyPackage partner [${partnerPkg.key_package_hash}] tidak valid (wajib 64 hex char SHA-256).`,
      'INVALID_PARTNER_KEY_PACKAGE_HASH'
    );
  }

  // 4. Periksa apakah outbox Welcome dari percobaan sebelumnya tersedia di IndexedDB
  let existingWelcomeItem: OutboxItemSummary | undefined;
  let existingCommitUuid = '';

  try {
    const outboxRes = await workerRpc.sendRequest<GetRoomOutboxPayload, GetRoomOutboxResult>(
      'GET_ROOM_OUTBOX',
      { roomUuid: canonicalRoomUuid }
    );
    if (outboxRes?.messages) {
      existingWelcomeItem = outboxRes.messages.find(
        (m) =>
          m.messageType === 'welcome' &&
          (m.status === 'pending' || m.status === 'in_transit' || m.status === 'failed')
      );
      const commitItem = outboxRes.messages.find((m) => m.messageType === 'commit');
      if (commitItem) {
        existingCommitUuid = commitItem.clientMessageUuid;
      }
    }
  } catch {
    // Abaikan kegagalan baca outbox awal
  }

  let targetGroupIdHash: string;
  let targetWelcomeWire: string;
  let targetClientMessageUuidWelcome: string;
  let targetClientMessageUuidCommit: string;

  if (existingWelcomeItem) {
    // RECOVERY PATH: Gunakan ciphertext dan outbox record yang sudah ada
    // DILARANG membuat Group atau Commit baru (anti-fork MLS)!
    let restoreRes: RestoreGroupStateResult;
    try {
      restoreRes = await workerRpc.sendRequest<RestoreGroupStatePayload, RestoreGroupStateResult>(
        'RESTORE_GROUP_STATE',
        {
          roomUuid: canonicalRoomUuid,
          deviceUuid: canonicalDeviceUuid,
          fencing: cleanFencing,
        },
        timeoutMs
      );
    } catch (workerErr) {
      throw new BchatHandshakeCoordinatorError(
        `Worker gagal memulihkan GroupState mahasiswa untuk publikasi ulang: ${(workerErr as Error)?.message}`,
        'WORKER_RESTORE_GROUP_STATE_FAILED',
        workerErr
      );
    }

    if (restoreRes.status !== 'FOUND' || !restoreRes.groupIdHash || restoreRes.epoch !== '1') {
      throw new BchatHandshakeCoordinatorError(
        `GroupState mahasiswa tidak valid untuk publikasi ulang (status: ${restoreRes?.status}, epoch: ${restoreRes?.epoch}).`,
        'INVALID_RESTORED_GROUP_STATE',
        undefined,
        { status: restoreRes?.status, epoch: restoreRes?.epoch }
      );
    }

    targetGroupIdHash = restoreRes.groupIdHash;
    targetWelcomeWire = existingWelcomeItem.ciphertextWireBase64;
    targetClientMessageUuidWelcome = existingWelcomeItem.clientMessageUuid;
    targetClientMessageUuidCommit = existingCommitUuid;
  } else {
    // HAPPY PATH: Inisiasi baru
    // 4.a Siapkan leaf KeyPackage lokal mahasiswa di worker agar CREATE_GROUP dapat membentuk MLS tree awal (epoch 0)
    try {
      await workerRpc.sendRequest<GenerateKeyPackagePayload, GenerateKeyPackageResult>(
        'GENERATE_KEY_PACKAGE',
        {
          deviceUuid: canonicalDeviceUuid,
          fencing: cleanFencing,
        },
        timeoutMs
      );
    } catch (genErr) {
      throw new BchatHandshakeCoordinatorError(
        `Worker gagal menyiapkan KeyPackage lokal mahasiswa: ${(genErr as Error)?.message}`,
        'WORKER_GENERATE_KEY_PACKAGE_FAILED',
        genErr
      );
    }

    // 4.b Minta Worker membuat Group mahasiswa pada epoch 0
    let createGroupRes: CreateGroupResult;
    try {
      createGroupRes = await workerRpc.sendRequest<CreateGroupPayload, CreateGroupResult>(
        'CREATE_GROUP',
        {
          roomUuid: canonicalRoomUuid,
          deviceUuid: canonicalDeviceUuid,
          fencing: cleanFencing,
        },
        timeoutMs
      );
    } catch (workerErr) {
      throw new BchatHandshakeCoordinatorError(
        `Worker gagal membuat grup MLS awal (epoch 0): ${(workerErr as Error)?.message}`,
        'WORKER_CREATE_GROUP_FAILED',
        workerErr
      );
    }

    if (!createGroupRes || createGroupRes.epoch !== '0' || !createGroupRes.groupIdHash) {
      throw new BchatHandshakeCoordinatorError(
        `Hasil pembuatan grup MLS oleh Worker tidak valid (epoch: ${createGroupRes?.epoch}).`,
        'INVALID_CREATE_GROUP_RESULT'
      );
    }

    // 4.b Minta Worker membuat Commit Add Member dan Welcome (tersimpan atomik di IDB oleh Worker)
    let commitRes: CommitAddMemberResult;
    try {
      commitRes = await workerRpc.sendRequest<CommitAddMemberPayload, CommitAddMemberResult>(
        'COMMIT_ADD_MEMBER',
        {
          partnerKeyPackageBase64: partnerPkg.key_package_data,
          roomUuid: canonicalRoomUuid,
          deviceUuid: canonicalDeviceUuid,
          fencing: cleanFencing,
        },
        timeoutMs
      );
    } catch (workerErr) {
      throw new BchatHandshakeCoordinatorError(
        `Worker gagal membuat Commit Add Member dan Welcome message: ${(workerErr as Error)?.message}`,
        'WORKER_COMMIT_ADD_MEMBER_FAILED',
        workerErr
      );
    }

    if (
      !commitRes ||
      !commitRes.welcomeWireBase64 ||
      !commitRes.clientMessageUuidCommit ||
      !commitRes.clientMessageUuidWelcome
    ) {
      throw new BchatHandshakeCoordinatorError(
        'Worker mengembalikan hasil COMMIT_ADD_MEMBER yang tidak lengkap.',
        'INVALID_COMMIT_RESULT'
      );
    }

    if (commitRes.epoch !== '1') {
      throw new BchatHandshakeCoordinatorError(
        `Epoch setelah commit tidak valid: [${commitRes.epoch}] (wajib tepat epoch 1).`,
        'INVALID_COMMIT_EPOCH'
      );
    }

    // 5. Tandai Commit lokal sebagai acked di outbox (Commit TIDAK DIUNGGAH ke backend)
    try {
      await workerRpc.sendRequest<UpdateOutboxStatusPayload, UpdateOutboxStatusResult>(
        'UPDATE_OUTBOX_STATUS',
        {
          clientMessageUuid: commitRes.clientMessageUuidCommit,
          status: 'acked',
          deviceUuid: canonicalDeviceUuid,
          fencing: cleanFencing,
        },
        timeoutMs
      );
    } catch {
      // Catat log internal, jangan batalkan proses karena persistensi GroupState sudah sukses
    }

    targetGroupIdHash = createGroupRes.groupIdHash;
    targetWelcomeWire = commitRes.welcomeWireBase64;
    targetClientMessageUuidWelcome = commitRes.clientMessageUuidWelcome;
    targetClientMessageUuidCommit = commitRes.clientMessageUuidCommit;
  }

  // 6. Publikasikan Welcome melalui endpoint POST /api/bchat/rooms/{roomUuid}/welcome
  // INVARIAN MUTLAK: commitWireBase64 TIDAK PERNAH dikirim ke backend!
  const publishPayload: BchatWelcomePublishRequest = {
    recipient_device_uuid: partnerDeviceUuid,
    key_package_hash: partnerPkgHash,
    group_id_hash: targetGroupIdHash,
    epoch: 1,
    welcome_message: targetWelcomeWire,
  };

  let publishRes: BchatWelcomePublishResponseData;
  try {
    const res = await publishWelcome(canonicalRoomUuid, publishPayload, canonicalDeviceUuid);
    publishRes = res.data;
  } catch (publishErr) {
    // 7. KLASIFIKASI ERROR PUBLIKASI: AMBIGU vs DEFINITIF
    if (isAmbiguousPublishError(publishErr)) {
      // Tindakan Ambigu: tandai outbox Welcome sebagai 'in_transit', bukan 'failed'
      try {
        await workerRpc.sendRequest<UpdateOutboxStatusPayload, UpdateOutboxStatusResult>(
          'UPDATE_OUTBOX_STATUS',
          {
            clientMessageUuid: targetClientMessageUuidWelcome,
            status: 'in_transit',
            errorMessage: (publishErr as Error)?.message ?? 'Welcome publish indeterminate',
            deviceUuid: canonicalDeviceUuid,
            fencing: cleanFencing,
          },
          5000
        );
      } catch {
        // Abaikan error sekunder outbox
      }

      // Lakukan rekonsiliasi melalui fetchRoomDetail()
      let reconciledRoom: BchatRoomDetail | null = null;
      try {
        const reconRes = await fetchRoomDetail(canonicalRoomUuid);
        reconciledRoom = reconRes?.data ?? null;
      } catch (reconErr) {
        // Jika rekonsiliasi backend gagal, jangan mengubah kondisi ambigu menjadi kegagalan definitif.
        // Pertahankan in_transit dan propagasikan error yang jelas.
        throw new BchatHandshakeCoordinatorError(
          `Publikasi Welcome ambigu (jaringan/5xx) dan rekonsiliasi state backend gagal: ${
            (reconErr as Error)?.message ?? (publishErr as Error)?.message
          }`,
          'WELCOME_PUBLISH_AMBIGUOUS_RECON_FAILED',
          publishErr,
          { originalError: publishErr, reconciliationError: reconErr }
        );
      }

      // Evaluasi rekonsiliasi backend: backend hanya dianggap telah menerima Welcome
      // jika room sudah ACTIVE atau challenge aktif telah tercatat pada PENDING_WELCOME_ACK.
      const isRecordedAtBackend =
        reconciledRoom &&
        (reconciledRoom.status === 'ACTIVE' ||
          (reconciledRoom.status === 'PENDING_WELCOME_ACK' &&
            reconciledRoom.handshake_context?.has_active_challenge === true));

      if (isRecordedAtBackend) {
        // Backend ternyata sudah menerima Welcome! Tandai outbox Welcome 'acked'
        try {
          await workerRpc.sendRequest<UpdateOutboxStatusPayload, UpdateOutboxStatusResult>(
            'UPDATE_OUTBOX_STATUS',
            {
              clientMessageUuid: targetClientMessageUuidWelcome,
              status: 'acked',
              deviceUuid: canonicalDeviceUuid,
              fencing: cleanFencing,
            },
            5000
          );
        } catch {
          // Abaikan error sekunder outbox
        }

        return {
          roomUuid: canonicalRoomUuid,
          welcomeOperationUuid: reconciledRoom.handshake_context?.welcome_operation_uuid ?? '',
          recipientUserId: partnerPkg.recipient_user_id,
          recipientDeviceUuid: partnerDeviceUuid,
          epoch: String(reconciledRoom.current_epoch ?? '1'),
          challengeGeneration: 1,
          publishedAt: reconciledRoom.created_at ?? new Date().toISOString(),
          welcomeWireBase64: targetWelcomeWire,
          clientMessageUuidCommit: targetClientMessageUuidCommit,
          clientMessageUuidWelcome: targetClientMessageUuidWelcome,
          groupIdHash: targetGroupIdHash,
        };
      }

      // Jika backend belum mencatat challenge aktif: pertahankan record in_transit, jangan generic retry otomatis
      throw new BchatHandshakeCoordinatorError(
        `Publikasi Welcome menghasilkan status ambigu (${
          (publishErr as Error)?.message
        }), namun backend belum mencatat challenge aktif. Record outbox dipertahankan sebagai 'in_transit'.`,
        'WELCOME_PUBLISH_IN_TRANSIT',
        publishErr,
        {
          roomStatus: reconciledRoom?.status,
          hasActiveChallenge: reconciledRoom?.handshake_context?.has_active_challenge ?? false,
          clientMessageUuid: targetClientMessageUuidWelcome,
        }
      );
    }

    // Tindakan Definitif (HTTP 4xx selain 408 dan 409): tandai outbox Welcome 'failed'
    // DILARANG membuat Commit atau Group baru secara otomatis (fail-closed)
    try {
      await workerRpc.sendRequest<UpdateOutboxStatusPayload, UpdateOutboxStatusResult>(
        'UPDATE_OUTBOX_STATUS',
        {
          clientMessageUuid: targetClientMessageUuidWelcome,
          status: 'failed',
          errorMessage: (publishErr as Error)?.message ?? 'Welcome publish rejected',
          deviceUuid: canonicalDeviceUuid,
          fencing: cleanFencing,
        },
        5000
      );
    } catch {
      // Abaikan error sekunder outbox
    }

    throw new BchatHandshakeCoordinatorError(
      `Publikasi Welcome message ke backend ditolak (definitif): ${(publishErr as Error)?.message}`,
      'WELCOME_PUBLISH_FAILED',
      publishErr
    );
  }

  // 8. Konfirmasi berhasil: tandai outbox welcome lokal sebagai acked
  try {
    await workerRpc.sendRequest<UpdateOutboxStatusPayload, UpdateOutboxStatusResult>(
      'UPDATE_OUTBOX_STATUS',
      {
        clientMessageUuid: targetClientMessageUuidWelcome,
        status: 'acked',
        deviceUuid: canonicalDeviceUuid,
        fencing: cleanFencing,
      },
      5000
    );
  } catch {
    // Abaikan kegagalan pembaruan outbox sekunder
  }

  return {
    roomUuid: canonicalRoomUuid,
    welcomeOperationUuid: publishRes.welcome_operation_uuid,
    recipientUserId: String(publishRes.recipient_user_id),
    recipientDeviceUuid: publishRes.recipient_device_uuid,
    epoch: String(publishRes.epoch),
    challengeGeneration: publishRes.challenge_generation,
    publishedAt: publishRes.created_at,
    welcomeWireBase64: targetWelcomeWire,
    clientMessageUuidCommit: targetClientMessageUuidCommit,
    clientMessageUuidWelcome: targetClientMessageUuidWelcome,
    groupIdHash: targetGroupIdHash,
  };
}

// -----------------------------------------------------------------------------
// FUNGSI BANTU PENCOCOKAN KEYPACKAGE DOSEN (JOIN_WELCOME)
// -----------------------------------------------------------------------------

/**
 * Ekstrak KeyPackageRef (32-byte hash reference RFC 9420) dari Welcome message wireformat.
 * Mengikuti implementasi verifikasi kanonis pada bchat-device-test/page.tsx:1289-1317.
 */
export function extractWelcomeKeyPackageRef(welcomeWireBase64: string): Uint8Array | null {
  try {
    let bytes: Uint8Array;
    if (typeof window !== 'undefined' && typeof window.atob === 'function') {
      const raw = window.atob(welcomeWireBase64);
      bytes = new Uint8Array(raw.length);
      for (let i = 0; i < raw.length; i++) {
        bytes[i] = raw.charCodeAt(i);
      }
    } else if (typeof Buffer !== 'undefined') {
      bytes = new Uint8Array(Buffer.from(welcomeWireBase64, 'base64'));
    } else {
      return null;
    }

    // MLSMessage: wireformat mls_welcome (0x0003)
    if (bytes.length >= 42 && bytes[2] === 0x00 && bytes[3] === 0x03) {
      let offset = 6;
      const b0 = bytes[offset++];
      const prefixType0 = (b0 & 0xc0) >> 6;
      if (prefixType0 === 1) offset += 1;
      else if (prefixType0 === 2) offset += 3;
      else if (prefixType0 === 3) offset += 7;

      const b1 = bytes[offset++];
      let newMemberLen = b1 & 0x3f;
      const prefixType1 = (b1 & 0xc0) >> 6;
      if (prefixType1 === 1) {
        newMemberLen = ((b1 & 0x3f) << 8) | bytes[offset++];
      }
      if (newMemberLen === 32 && offset + 32 <= bytes.length) {
        return bytes.slice(offset, offset + 32);
      }
    }
  } catch {
    return null;
  }
  return null;
}

/**
 * Mencari dan memvalidasi kecocokan tunggal PrivateKeyPackage lokal milik perangkat dosen aktif
 * terhadap KeyPackageRef yang terdapat pada pesan Welcome.
 * Mengikuti mekanisme perhitungan MLS 1.0 KeyPackage Reference hash pada bchat-device-test/page.tsx:1323-1385.
 */
export async function findMatchingLocalKeyPackageHash(
  canonicalDeviceUuid: string,
  expectedRef: Uint8Array
): Promise<string> {
  const encVarLen = (data: Uint8Array): Uint8Array => {
    const len = data.length;
    let lenBytes: Uint8Array;
    if (len < 64) {
      lenBytes = new Uint8Array([len & 0x3f]);
    } else if (len < 16384) {
      lenBytes = new Uint8Array([((len >> 8) & 0x3f) | 0x40, len & 0xff]);
    } else {
      lenBytes = new Uint8Array([
        ((len >> 24) & 0x3f) | 0x80,
        (len >> 16) & 0xff,
        (len >> 8) & 0xff,
        len & 0xff,
      ]);
    }
    const res = new Uint8Array(lenBytes.length + len);
    res.set(lenBytes, 0);
    res.set(data, lenBytes.length);
    return res;
  };

  const labelBytes = new TextEncoder().encode('MLS 1.0 KeyPackage Reference');
  const encLabel = encVarLen(labelBytes);
  const cryptoObj = typeof window !== 'undefined' ? window.crypto : globalThis.crypto;
  if (!cryptoObj?.subtle) {
    throw new BchatHandshakeCoordinatorError(
      'SubtleCrypto tidak tersedia pada lingkungan saat ini.',
      'CRYPTO_NOT_AVAILABLE'
    );
  }

  const matchingHashes: string[] = [];

  try {
    await withReadonlyTransaction([BCAT_STORES.PENDING_KEY_PACKAGES], async (_tx, getStore) => {
      const store = getStore(BCAT_STORES.PENDING_KEY_PACKAGES);
      const all = await new Promise<PendingKeyPackageRecord[]>((resolve, reject) => {
        const req = store.getAll();
        req.onsuccess = () => resolve(req.result as PendingKeyPackageRecord[]);
        req.onerror = () => reject(req.error || new Error('Gagal membaca record pending_key_packages'));
      });

      const available = all.filter(
        (k) =>
          k.device_uuid === canonicalDeviceUuid &&
          k.metadata?.status !== 'consumed' &&
          k.private_key_package_bytes &&
          k.private_key_package_bytes.length > 0
      );

      for (const candidate of available) {
        if (!candidate.public_key_package_bytes || candidate.public_key_package_bytes.length === 0) {
          continue;
        }

        const encVal = encVarLen(candidate.public_key_package_bytes);
        const total = new Uint8Array(encLabel.length + encVal.length);
        total.set(encLabel, 0);
        total.set(encVal, encLabel.length);
        const hashBuf = await cryptoObj.subtle.digest('SHA-256', total);
        const candidateRef = new Uint8Array(hashBuf);

        let matches = candidateRef.length === expectedRef.length;
        for (let i = 0; i < candidateRef.length && matches; i++) {
          if (candidateRef[i] !== expectedRef[i]) {
            matches = false;
          }
        }

        if (matches) {
          // Verifikasi integritas key_package_hash terhadap SHA-256 public_key_package_bytes sesuai mekanisme worker
          const pubHashBuf = await cryptoObj.subtle.digest('SHA-256', candidate.public_key_package_bytes);
          const computedHashHex = Array.from(new Uint8Array(pubHashBuf))
            .map((b) => b.toString(16).padStart(2, '0'))
            .join('');

          if (computedHashHex.toLowerCase() !== candidate.key_package_hash.toLowerCase()) {
            continue;
          }

          matchingHashes.push(candidate.key_package_hash);
        }
      }
    });
  } catch (idbErr) {
    if (idbErr instanceof BchatHandshakeCoordinatorError) {
      throw idbErr;
    }
    throw new BchatHandshakeCoordinatorError(
      `Gagal memeriksa pending_key_packages pada IndexedDB: ${(idbErr as Error)?.message}`,
      'IDB_KEY_PACKAGE_QUERY_FAILED',
      idbErr
    );
  }

  if (matchingHashes.length === 0) {
    throw new BchatHandshakeCoordinatorError(
      'SECURITY STOP: Tidak ditemukan PrivateKeyPackage lokal yang cocok dengan KeyPackageRef pada Welcome message.',
      'KEY_PACKAGE_NOT_FOUND',
      undefined,
      { deviceUuid: canonicalDeviceUuid }
    );
  }

  if (matchingHashes.length > 1) {
    throw new BchatHandshakeCoordinatorError(
      `SECURITY STOP: Ditemukan ${matchingHashes.length} PrivateKeyPackage lokal yang cocok dengan KeyPackageRef Welcome message (ambigu).`,
      'AMBIGUOUS_KEY_PACKAGE_MATCH',
      undefined,
      { deviceUuid: canonicalDeviceUuid, matchingCount: matchingHashes.length }
    );
  }

  return matchingHashes[0];
}

// -----------------------------------------------------------------------------
// ALUR DOSEN — FETCH_CHALLENGE_AND_ACK
// -----------------------------------------------------------------------------

/**
 * Mengorkestrasikan alur konfirmasi Welcome oleh dosen pembimbing:
 * 1. Ambil detail room dari backend.
 * 2. Jika room sudah ACTIVE, kembalikan status selesai tanpa mengambil challenge atau JOIN_WELCOME ulang.
 * 3. Pastikan room masih PENDING_WELCOME_ACK dan tindakan backend sesuai untuk penerima.
 * 4. Ambil challenge aktif atau lakukan rotasi melalui API yang sudah tersedia.
 * 5. Panggil RPC RESTORE_GROUP_STATE dengan roomUuid, deviceUuid, dan fencing eksplisit.
 * 6. Evaluasi hasil restore:
 *    - FOUND dan epoch '1': gunakan groupIdHash hasil restore dan LEWATI JOIN_WELCOME (anti-reuse defense).
 *    - NOT_FOUND: jalankan JOIN_WELCOME seperti biasa.
 *    - FOUND dengan epoch selain '1': fail-closed.
 *    - QUARANTINED, FENCING_STALE, RESTORE_ERROR: fail-closed.
 * 7. Validasi groupIdHash hasil restore/join terhadap challengeData.group_id_hash jika tersedia.
 * 8. Bangun payload ACK menggunakan ACK Builder kanonis 158 byte.
 * 9. Tanda tangani menggunakan Device Identity Key Ed25519 aktif perangkat dosen.
 * 10. Kirim ACK ke endpoint POST /api/bchat/rooms/{roomUuid}/welcome-ack.
 * 11. Verifikasi respons backend menyatakan room ACTIVE.
 */
export async function fetchChallengeAndAckHandshakeFlow(
  workerRpc: BchatWorkerClient,
  roomUuid: string,
  deviceUuid: string,
  options: FetchChallengeAndAckOptions
): Promise<FetchChallengeAndAckResult> {
  // 1. Validasi input dasar & fencing eksplisit
  const canonicalRoomUuid = validateCanonicalUuid(roomUuid, 'roomUuid');
  const canonicalDeviceUuid = validateCanonicalUuid(deviceUuid, 'deviceUuid');
  const cleanFencing = validateFencing(options?.fencing, 'fetchChallengeAndAckHandshakeFlow');

  if (!workerRpc || typeof workerRpc.sendRequest !== 'function') {
    throw new BchatHandshakeCoordinatorError(
      'Worker RPC client tidak valid atau belum diinisialisasi.',
      'INVALID_WORKER_CLIENT'
    );
  }

  const timeoutMs = options.timeoutMs ?? 25000;

  // 1 & 2. Ambil detail room dari backend; jika room sudah ACTIVE, kembalikan status selesai
  let roomDetail: BchatRoomDetail;
  try {
    const roomRes = await fetchRoomDetail(canonicalRoomUuid);
    roomDetail = roomRes.data;
  } catch (apiErr) {
    throw new BchatHandshakeCoordinatorError(
      `Gagal mengambil metadata room [${canonicalRoomUuid}] dari backend: ${(apiErr as Error)?.message}`,
      'FETCH_ROOM_DETAIL_FAILED',
      apiErr
    );
  }

  if (!roomDetail || typeof roomDetail !== 'object') {
    throw new BchatHandshakeCoordinatorError(
      `Respons detail room [${canonicalRoomUuid}] tidak valid atau kosong.`,
      'INVALID_ROOM_DETAIL_RESPONSE'
    );
  }

  if (roomDetail.status === 'ACTIVE') {
    // Room sudah ACTIVE: jangan mencoba mengambil challenge atau menjalankan JOIN_WELCOME
    return {
      roomUuid: canonicalRoomUuid,
      roomStatus: 'ACTIVE',
      currentEpoch: String(roomDetail.current_epoch ?? '1'),
      activatedAt: roomDetail.updated_at ?? roomDetail.created_at ?? new Date().toISOString(),
      welcomeOperationUuid: roomDetail.handshake_context?.welcome_operation_uuid ?? '',
      challengeUuid: '',
      ackPayloadHash: '',
      signatureBase64: '',
      groupIdHash: '',
    };
  }

  // 3. Pastikan room masih PENDING_WELCOME_ACK dan tindakan backend sesuai untuk penerima
  if (roomDetail.status !== 'PENDING_WELCOME_ACK') {
    throw new BchatHandshakeCoordinatorError(
      `Status room [${canonicalRoomUuid}] adalah [${roomDetail.status}] (wajib PENDING_WELCOME_ACK).`,
      'ROOM_STATUS_NOT_PENDING_WELCOME_ACK',
      undefined,
      { roomStatus: roomDetail.status }
    );
  }

  const actionHint = roomDetail.action_hint;
  if (
    !actionHint ||
    actionHint.action_type !== 'FETCH_CHALLENGE_AND_ACK' ||
    !actionHint.action_required
  ) {
    throw new BchatHandshakeCoordinatorError(
      `Aksi pengambilan challenge dan Welcome ACK tidak diizinkan untuk room [${canonicalRoomUuid}]. Status room: [${
        roomDetail.status
      }], action_type: [${actionHint?.action_type ?? 'NONE'}], action_required: [${
        actionHint?.action_required ?? false
      }].`,
      'ACTION_NOT_PERMITTED',
      undefined,
      {
        roomStatus: roomDetail.status,
        actionType: actionHint?.action_type,
        actionRequired: actionHint?.action_required,
      }
    );
  }

  // 4. Ambil Welcome challenge melalui endpoint POST /api/bchat/rooms/{roomUuid}/welcome-challenge
  let challengeData: BchatWelcomeChallengeResponseData;
  try {
    const challengeRes = await fetchOrRotateWelcomeChallenge(canonicalRoomUuid, canonicalDeviceUuid);
    challengeData = challengeRes.data;
  } catch (apiErr) {
    throw new BchatHandshakeCoordinatorError(
      `Gagal mengambil Welcome challenge untuk room [${canonicalRoomUuid}]: ${(apiErr as Error)?.message}`,
      'FETCH_WELCOME_CHALLENGE_FAILED',
      apiErr
    );
  }

  if (
    !challengeData ||
    !challengeData.welcome_message ||
    !challengeData.challenge_uuid ||
    !challengeData.challenge_nonce
  ) {
    throw new BchatHandshakeCoordinatorError(
      `Data Welcome challenge untuk room [${canonicalRoomUuid}] tidak lengkap atau kosong.`,
      'INVALID_WELCOME_CHALLENGE_DATA'
    );
  }

  // Validasi wajib keberadaan dan format challengeData.group_id_hash (SHA-256 64 hex characters)
  if (
    challengeData.group_id_hash === undefined ||
    challengeData.group_id_hash === null ||
    typeof challengeData.group_id_hash !== 'string'
  ) {
    throw new BchatHandshakeCoordinatorError(
      `Field group_id_hash pada Welcome challenge untuk room [${canonicalRoomUuid}] tidak tersedia atau bukan string.`,
      'INVALID_CHALLENGE_GROUP_ID_HASH'
    );
  }

  const rawChallengeGroupIdHash = challengeData.group_id_hash;
  const canonicalChallengeGroupIdHash = rawChallengeGroupIdHash.trim().toLowerCase();
  if (
    rawChallengeGroupIdHash.length !== 64 ||
    !SHA256_HEX_REGEX.test(canonicalChallengeGroupIdHash)
  ) {
    throw new BchatHandshakeCoordinatorError(
      `Hash group_id_hash pada Welcome challenge [${rawChallengeGroupIdHash}] tidak valid (wajib tepat 64 karakter hexadecimal).`,
      'INVALID_CHALLENGE_GROUP_ID_HASH'
    );
  }

  const canonicalChallengeUuid = validateCanonicalUuid(
    challengeData.challenge_uuid,
    'challenge_uuid'
  );
  const canonicalWelcomeOpUuid = validateCanonicalUuid(
    challengeData.welcome_operation_uuid,
    'welcome_operation_uuid'
  );

  // 5. Panggil RPC RESTORE_GROUP_STATE dengan roomUuid, deviceUuid, dan fencing eksplisit
  let restoreRes: RestoreGroupStateResult;
  try {
    restoreRes = await workerRpc.sendRequest<RestoreGroupStatePayload, RestoreGroupStateResult>(
      'RESTORE_GROUP_STATE',
      {
        roomUuid: canonicalRoomUuid,
        deviceUuid: canonicalDeviceUuid,
        fencing: cleanFencing,
      },
      timeoutMs
    );
  } catch (workerErr) {
    throw new BchatHandshakeCoordinatorError(
      `Worker gagal memeriksa GroupState untuk room [${canonicalRoomUuid}]: ${(workerErr as Error)?.message}`,
      'WORKER_RESTORE_GROUP_STATE_FAILED',
      workerErr
    );
  }

  // 6. Evaluasi hasil restore
  let targetGroupIdHash: string;

  if (restoreRes.status === 'FOUND') {
    if (restoreRes.epoch !== '1') {
      throw new BchatHandshakeCoordinatorError(
        `GroupState ditemukan pada epoch [${restoreRes.epoch}], bukan epoch 1 yang diharapkan untuk Welcome handshake.`,
        'INVALID_GROUP_STATE_EPOCH',
        undefined,
        { epoch: restoreRes.epoch }
      );
    }
    if (!restoreRes.groupIdHash) {
      throw new BchatHandshakeCoordinatorError(
        'GroupState hasil pemulihan tidak memiliki groupIdHash valid.',
        'INVALID_GROUP_ID_HASH'
      );
    }
    // LEWATI JOIN_WELCOME! Gunakan groupIdHash yang sudah dipulihkan
    targetGroupIdHash = restoreRes.groupIdHash;
  } else if (restoreRes.status === 'NOT_FOUND') {
    // Menjalankan JOIN_WELCOME normal (konsumsi PrivateKeyPackage & persistensi GroupState atomik)
    // 1. Ekstrak KeyPackageRef dari Welcome message wireformat
    const expectedRef = extractWelcomeKeyPackageRef(challengeData.welcome_message);
    if (!expectedRef) {
      throw new BchatHandshakeCoordinatorError(
        'SECURITY STOP: Gagal mengekstrak KeyPackageRef dari Welcome message wireformat.',
        'KEY_PACKAGE_REF_EXTRACTION_FAILED'
      );
    }

    // 2. Cari dan cocokkan KeyPackage lokal dosen pada pending_key_packages IndexedDB
    const matchedKeyPackageHash = await findMatchingLocalKeyPackageHash(
      canonicalDeviceUuid,
      expectedRef
    );

    let joinRes: JoinWelcomeResult;
    try {
      joinRes = await workerRpc.sendRequest<JoinWelcomePayload, JoinWelcomeResult>(
        'JOIN_WELCOME',
        {
          welcomeWireBase64: challengeData.welcome_message,
          keyPackageHash: matchedKeyPackageHash,
          roomUuid: canonicalRoomUuid,
          deviceUuid: canonicalDeviceUuid,
          fencing: cleanFencing,
        },
        timeoutMs
      );
    } catch (workerErr) {
      throw new BchatHandshakeCoordinatorError(
        `Worker gagal mengeksekusi JOIN_WELCOME untuk room [${canonicalRoomUuid}]: ${(workerErr as Error)?.message}`,
        'WORKER_JOIN_WELCOME_FAILED',
        workerErr
      );
    }

    if (!joinRes || joinRes.epoch !== '1' || !joinRes.groupIdHash) {
      throw new BchatHandshakeCoordinatorError(
        `Hasil JOIN_WELCOME dari Worker tidak valid (epoch: ${joinRes?.epoch}, group_id_hash: ${joinRes?.groupIdHash}).`,
        'INVALID_JOIN_WELCOME_RESULT'
      );
    }
    targetGroupIdHash = joinRes.groupIdHash;
  } else if (restoreRes.status === 'QUARANTINED') {
    throw new BchatHandshakeCoordinatorError(
      `GroupState untuk room [${canonicalRoomUuid}] berada dalam karantina: ${
        restoreRes.quarantineReason ?? 'Record is quarantined'
      }.`,
      'RESTORE_STATUS_QUARANTINED',
      undefined,
      { quarantineReason: restoreRes.quarantineReason }
    );
  } else if (restoreRes.status === 'FENCING_STALE') {
    throw new BchatHandshakeCoordinatorError(
      `Kredensial fencing kadaluwarsa atau tidak cocok saat memeriksa GroupState room [${canonicalRoomUuid}].`,
      'RESTORE_STATUS_FENCING_STALE',
      undefined,
      { message: restoreRes.message }
    );
  } else {
    // Fail-closed untuk status gagal lainnya (RESTORE_ERROR, dll)
    throw new BchatHandshakeCoordinatorError(
      `Pemulihan GroupState gagal dengan status [${restoreRes.status}]: ${
        restoreRes.message ?? 'Unknown error'
      }.`,
      `RESTORE_STATUS_${restoreRes.status}`,
      undefined,
      { status: restoreRes.status, message: restoreRes.message }
    );
  }

  // 7. Validasi format targetGroupIdHash dan pengikatan tepat terhadap canonicalChallengeGroupIdHash
  if (
    !targetGroupIdHash ||
    typeof targetGroupIdHash !== 'string' ||
    targetGroupIdHash.length !== 64 ||
    !SHA256_HEX_REGEX.test(targetGroupIdHash.toLowerCase())
  ) {
    throw new BchatHandshakeCoordinatorError(
      `groupIdHash dari GroupState [${String(targetGroupIdHash)}] tidak valid (wajib SHA-256 64 karakter hex).`,
      'INVALID_GROUP_ID_HASH',
      undefined,
      { stateGroupIdHash: targetGroupIdHash }
    );
  }

  const normalizedTargetGroupIdHash = targetGroupIdHash.toLowerCase();

  // Wajib bandingkan keduanya secara tepat (strict lowercase match)
  if (canonicalChallengeGroupIdHash !== normalizedTargetGroupIdHash) {
    throw new BchatHandshakeCoordinatorError(
      `Hash Group ID tidak cocok antara GroupState (${normalizedTargetGroupIdHash}) dan challenge backend (${canonicalChallengeGroupIdHash}).`,
      'GROUP_ID_HASH_MISMATCH',
      undefined,
      {
        stateGroupIdHash: normalizedTargetGroupIdHash,
        challengeGroupIdHash: canonicalChallengeGroupIdHash,
      }
    );
  }

  // 8. Resolusi recipient user ID (dosen penerima Welcome)
  let resolvedUserId: string | number | bigint;
  if (options.userId !== undefined && options.userId !== null) {
    resolvedUserId = options.userId;
  } else {
    const identityKey = await getActiveDeviceIdentityKey(canonicalDeviceUuid);
    const userIdFromMeta = identityKey?.metadata?.user_id;
    if (
      userIdFromMeta !== undefined &&
      userIdFromMeta !== null &&
      String(userIdFromMeta).trim() !== ''
    ) {
      resolvedUserId = String(userIdFromMeta);
    } else {
      throw new BchatHandshakeCoordinatorError(
        `Recipient user ID tidak dapat ditentukan untuk perangkat [${canonicalDeviceUuid}]. Pastikan sesi perangkat memiliki metadata user_id atau sertakan options.userId.`,
        'RECIPIENT_USER_ID_REQUIRED'
      );
    }
  }

  // 9. Bentuk payload Welcome ACK kanonis tepat 158 byte
  const ackPayload = buildWelcomeAckPayload({
    roomUuid: canonicalRoomUuid,
    recipientUserId: resolvedUserId,
    welcomeOperationUuid: canonicalWelcomeOpUuid,
    challengeUuid: canonicalChallengeUuid,
    challengeGeneration: challengeData.challenge_generation,
    groupIdHash: normalizedTargetGroupIdHash,
    epoch: BigInt(1),
    challengeNonce: challengeData.challenge_nonce,
  });

  if (ackPayload.payloadLength !== 158 || ackPayload.payloadBytes.byteLength !== 158) {
    throw new BchatHandshakeCoordinatorError(
      `Panjang payload Welcome ACK hasil rakitan tidak valid: ${ackPayload.payloadBytes.byteLength} byte (wajib 158 byte).`,
      'PAYLOAD_LENGTH_MISMATCH'
    );
  }

  // 10. Tanda tangani payload dengan Ed25519 Identity Key perangkat dosen resmi
  let signedAck;
  try {
    signedAck = await signWelcomeAckWithDeviceIdentity(
      ackPayload.payloadBytes,
      canonicalDeviceUuid
    );
  } catch (signErr) {
    throw new BchatHandshakeCoordinatorError(
      `Penandatanganan Welcome ACK dengan Identity Key perangkat gagal: ${(signErr as Error)?.message}`,
      'WELCOME_ACK_SIGNING_FAILED',
      signErr
    );
  }

  if (signedAck.signatureBytes.byteLength !== 64 || signedAck.signatureBase64.length !== 88) {
    throw new BchatHandshakeCoordinatorError(
      `Detached signature Ed25519 Welcome ACK tidak valid (panjang byte: ${signedAck.signatureBytes.byteLength}, base64: ${signedAck.signatureBase64.length}).`,
      'INVALID_SIGNATURE_OUTPUT'
    );
  }

  // 11. Kirim ACK ke endpoint POST /api/bchat/rooms/{roomUuid}/welcome-ack
  const ackPayloadReq: BchatWelcomeAckRequest = {
    challenge_uuid: canonicalChallengeUuid,
    signature: signedAck.signatureBase64,
  };

  let ackResponse: BchatWelcomeAckResponseData;
  try {
    const res = await submitWelcomeAck(canonicalRoomUuid, ackPayloadReq, canonicalDeviceUuid);
    ackResponse = res.data;
  } catch (ackErr) {
    // Jika pengiriman ACK menghasilkan error ambigu, periksa apakah room sudah aktif di backend
    if (isAmbiguousPublishError(ackErr)) {
      try {
        const recon = await fetchRoomDetail(canonicalRoomUuid);
        if (recon?.data?.status === 'ACTIVE') {
          return {
            roomUuid: canonicalRoomUuid,
            roomStatus: 'ACTIVE',
            currentEpoch: String(recon.data.current_epoch ?? '1'),
            activatedAt:
              recon.data.updated_at ?? recon.data.created_at ?? new Date().toISOString(),
            welcomeOperationUuid: canonicalWelcomeOpUuid,
            challengeUuid: canonicalChallengeUuid,
            ackPayloadHash: '',
            signatureBase64: signedAck.signatureBase64,
            groupIdHash: normalizedTargetGroupIdHash,
          };
        }
      } catch {
        // Abaikan error rekonsiliasi sekunder
      }
    }

    throw new BchatHandshakeCoordinatorError(
      `Pengiriman Welcome ACK ke backend gagal: ${(ackErr as Error)?.message}`,
      'WELCOME_ACK_SUBMIT_FAILED',
      ackErr
    );
  }

  // 12. Perlakukan respons backend sebagai sumber kebenaran mutlak aktivasi room
  if (!ackResponse || ackResponse.room_status !== 'ACTIVE') {
    throw new BchatHandshakeCoordinatorError(
      `Respons aktivasi room dari backend tidak valid: room_status=[${ackResponse?.room_status}] (wajib ACTIVE).`,
      'ROOM_ACTIVATION_FAILED',
      undefined,
      {
        roomStatus: ackResponse?.room_status,
        activatedAt: ackResponse?.activated_at,
      }
    );
  }

  return {
    roomUuid: canonicalRoomUuid,
    roomStatus: ackResponse.room_status,
    currentEpoch: String(ackResponse.current_epoch),
    activatedAt: ackResponse.activated_at,
    welcomeOperationUuid: ackResponse.welcome_operation_uuid,
    challengeUuid: ackResponse.challenge_uuid,
    ackPayloadHash: ackResponse.ack_payload_hash,
    signatureBase64: signedAck.signatureBase64,
    groupIdHash: normalizedTargetGroupIdHash,
  };
}

// -----------------------------------------------------------------------------
// MULTI-GENERATION ROOM SESSION HANDSHAKE (ROOM ACTIVE TANPA GROUPSTATE LOKAL)
// -----------------------------------------------------------------------------

export interface InitiateNextSessionOptions {
  fencing: FencingRpcInput;
  timeoutMs?: number;
}

export interface InitiateNextSessionResult {
  status: 'AWAITING_ACK' | 'ALREADY_ACTIVE';
  roomUuid: string;
  sessionUuid: string;
  generationNumber: number;
  welcomeOperationUuid: string;
  challengeUuid: string;
  expiresAt: string | null;
  groupIdHash: string;
  recipientDeviceUuid: string;
}

export interface CompletePendingSessionOptions {
  fencing: FencingRpcInput;
  userId?: string | number | bigint;
  expectedSessionUuid?: string;
  timeoutMs?: number;
}

export interface CompletePendingSessionResult {
  roomUuid: string;
  sessionUuid: string;
  generationNumber: number;
  sessionStatus: string; // 'ACTIVE'
  startSequence: number;
  activatedAt: string;
  welcomeOperationUuid: string;
  challengeUuid: string;
  signatureBase64: string;
  groupIdHash: string;
}

/**
 * Mengorkestrasikan inisiasi sesi generasi baru (BchatRoomSession) oleh mahasiswa
 * pada room berstatus ACTIVE ketika perangkat belum memiliki GroupState lokal.
 *
 * Invarian:
 * - TIDAK membuat sesi baru bila sudah ada pending_session pada room.
 * - TIDAK menimpa GroupState aktif yang sudah ada (fail-closed jika epoch != '0').
 * - Menggunakan operasi MLS Worker yang sudah ada (GENERATE_KEY_PACKAGE, CREATE_GROUP, COMMIT_ADD_MEMBER).
 * - Mengirim header X-Bchat-Device-Uuid pada seluruh request handshake.
 */
export async function initiateNextSessionHandshakeFlow(
  workerRpc: BchatWorkerClient,
  roomUuid: string,
  deviceUuid: string,
  options: InitiateNextSessionOptions
): Promise<InitiateNextSessionResult> {
  const canonicalRoomUuid = validateCanonicalUuid(roomUuid, 'roomUuid');
  const canonicalDeviceUuid = validateCanonicalUuid(deviceUuid, 'deviceUuid');
  const cleanFencing = validateFencing(options?.fencing, 'initiateNextSessionHandshakeFlow');

  if (!workerRpc || typeof workerRpc.sendRequest !== 'function') {
    throw new BchatHandshakeCoordinatorError(
      'Worker RPC client tidak valid atau belum diinisialisasi.',
      'INVALID_WORKER_CLIENT'
    );
  }

  const timeoutMs = options.timeoutMs ?? 25000;

  // 1. Ambil detail room aktual
  let roomDetail: BchatRoomDetail;
  try {
    const roomRes = await fetchRoomDetail(canonicalRoomUuid);
    roomDetail = roomRes.data;
  } catch (apiErr) {
    throw new BchatHandshakeCoordinatorError(
      `Gagal mengambil detail room [${canonicalRoomUuid}]: ${(apiErr as Error)?.message}`,
      'FETCH_ROOM_DETAIL_FAILED',
      apiErr
    );
  }

  if (roomDetail.status !== 'ACTIVE') {
    throw new BchatHandshakeCoordinatorError(
      `Inisiasi sesi generasi baru hanya diizinkan pada room ACTIVE (status saat ini: ${roomDetail.status}).`,
      'INVALID_ROOM_STATUS',
      undefined,
      { roomStatus: roomDetail.status }
    );
  }

  if (roomDetail.my_membership?.role !== 'mahasiswa') {
    throw new BchatHandshakeCoordinatorError(
      'Hanya mahasiswa yang dapat menginisiasi sesi aman baru pada ruang bimbingan ini.',
      'MEMBERSHIP_ROLE_MISMATCH'
    );
  }

  // 2. Cek apakah sudah ada sesi PENDING pada room yang Welcome-nya SUDAH terpublikasi (idempoten)
  const existingPendingSession =
    roomDetail.pending_session && roomDetail.pending_session.status === 'PENDING'
      ? roomDetail.pending_session
      : null;

  if (existingPendingSession && existingPendingSession.has_active_challenge) {
    const existingSessionUuid = validateCanonicalUuid(
      existingPendingSession.session_uuid,
      'session_uuid'
    );
    const existingGenerationNumber = existingPendingSession.generation_number;
    try {
      const outboxRes = await workerRpc.sendRequest<GetRoomOutboxPayload, GetRoomOutboxResult>(
        'GET_ROOM_OUTBOX',
        { roomUuid: canonicalRoomUuid }
      );
      const welcomeItem = outboxRes?.messages?.find((m) => m.messageType === 'welcome');
      if (welcomeItem && welcomeItem.status !== 'acked') {
        await workerRpc.sendRequest<UpdateOutboxStatusPayload, UpdateOutboxStatusResult>(
          'UPDATE_OUTBOX_STATUS',
          {
            clientMessageUuid: welcomeItem.clientMessageUuid,
            status: 'acked',
            deviceUuid: canonicalDeviceUuid,
            fencing: cleanFencing,
          },
          5000
        );
      }
    } catch {
      // Abaikan kegagalan lookup outbox sekunder
    }

    return {
      status: 'AWAITING_ACK',
      roomUuid: canonicalRoomUuid,
      sessionUuid: existingSessionUuid,
      generationNumber: existingGenerationNumber,
      welcomeOperationUuid: existingPendingSession.welcome_operation_uuid ?? '',
      challengeUuid: '',
      expiresAt: existingPendingSession.expires_at ?? null,
      groupIdHash: roomDetail.group_id_hash ?? '',
      recipientDeviceUuid: existingPendingSession.recipient_device_uuid ?? '',
    };
  }

  // 3. Evaluasi state lokal (Outbox Welcome & GroupState) SEBELUM membuat sesi baru di server
  //    agar kondisi fail-closed tidak pernah menciptakan sesi PENDING liar maupun meregenerasi GroupState/Commit/Welcome.
  let existingWelcomeItem: OutboxItemSummary | null = null;
  let hasCorruptWelcomeItem = false;
  try {
    const outboxRes = await workerRpc.sendRequest<GetRoomOutboxPayload, GetRoomOutboxResult>(
      'GET_ROOM_OUTBOX',
      { roomUuid: canonicalRoomUuid }
    );
    existingWelcomeItem = findRecoverablePendingWelcomeOutbox(
      outboxRes?.messages,
      canonicalRoomUuid
    );
    hasCorruptWelcomeItem =
      !existingWelcomeItem &&
      hasInvalidPendingWelcomeOutbox(outboxRes?.messages, canonicalRoomUuid);
  } catch {
    existingWelcomeItem = null;
    hasCorruptWelcomeItem = false;
  }

  const preRestoreRes = await workerRpc.sendRequest<RestoreGroupStatePayload, RestoreGroupStateResult>(
    'RESTORE_GROUP_STATE',
    {
      roomUuid: canonicalRoomUuid,
      deviceUuid: canonicalDeviceUuid,
      fencing: cleanFencing,
    },
    timeoutMs
  );

  if (existingWelcomeItem) {
    const hasValidHash =
      typeof preRestoreRes.groupIdHash === 'string' &&
      SHA256_HEX_REGEX.test(preRestoreRes.groupIdHash.trim().toLowerCase());
    // Jika sesi PENDING yang sama sudah ada di backend (has_active_challenge === false),
    // izinkan publikasi ulang ciphertext Welcome asli dari outbox lokal meskipun lastProcessedSequence > 0,
    // tanpa membuat sesi baru maupun meregenerasi GroupState/Commit/Welcome.
    const isRecoveringExistingPendingSession =
      existingPendingSession !== null && existingPendingSession.has_active_challenge === false;
    const sequenceAllowed =
      isRecoveringExistingPendingSession || (preRestoreRes.lastProcessedSequence ?? 0) === 0;
    if (
      preRestoreRes.status !== 'FOUND' ||
      !hasValidHash ||
      preRestoreRes.epoch !== '1' ||
      !sequenceAllowed
    ) {
      throw new BchatHandshakeCoordinatorError(
        `GroupState lokal tidak valid untuk publikasi ulang Welcome sesi (status: ${preRestoreRes?.status}, epoch: ${preRestoreRes?.epoch}, seq: ${preRestoreRes?.lastProcessedSequence ?? 0}).`,
        'INVALID_RESTORED_GROUP_STATE'
      );
    }
  } else if (preRestoreRes.status === 'FOUND') {
    if (
      existingPendingSession &&
      existingPendingSession.has_active_challenge === false &&
      (hasCorruptWelcomeItem ||
        (preRestoreRes.epoch === '1' && (preRestoreRes.lastProcessedSequence ?? 0) === 0))
    ) {
      throw new BchatHandshakeCoordinatorError(
        `Sesi tertunda Generasi #${existingPendingSession.generation_number} belum menyelesaikan publikasi Welcome dan payload Welcome lokal yang valid tidak ditemukan atau rusak di outbox. Publikasi dibatalkan secara aman (fail-closed) tanpa membuat sesi pengganti.`,
        'PENDING_WELCOME_OUTBOX_MISSING',
        undefined,
        {
          sessionUuid: existingPendingSession.session_uuid,
          generationNumber: existingPendingSession.generation_number,
        }
      );
    }

    if (!(preRestoreRes.epoch === '0' && preRestoreRes.groupIdHash)) {
      throw new BchatHandshakeCoordinatorError(
        'Perangkat ini sudah memiliki GroupState MLS aktif. Inisiasi sesi baru dibatalkan secara aman (fail-closed).',
        'ACTIVE_GROUP_STATE_ALREADY_EXISTS'
      );
    }
  } else if (preRestoreRes.status !== 'NOT_FOUND') {
    throw new BchatHandshakeCoordinatorError(
      `Status GroupState lokal tidak mengizinkan inisiasi sesi baru (${preRestoreRes.status}).`,
      `RESTORE_STATUS_${preRestoreRes.status}`
    );
  }

  // 4. Resolusi target session UUID (gunakan ulang pending_session jika sudah ada — JANGAN buat sesi duplikat!)
  let targetSessionUuid: string;
  let targetGenerationNumber: number;

  if (existingPendingSession) {
    targetSessionUuid = validateCanonicalUuid(existingPendingSession.session_uuid, 'session_uuid');
    targetGenerationNumber = existingPendingSession.generation_number;
  } else {
    // Belum ada pending_session -> buat generasi sesi baru (PENDING)
    let createdSession: BchatRoomSessionData;
    try {
      const nextRes = await createNextRoomSession(canonicalRoomUuid, {}, canonicalDeviceUuid);
      createdSession = nextRes.data;
    } catch (createErr) {
      // Tangani konkurensi: jika sesi PENDING ternyata baru saja dibuat, gunakan sesi yang ada
      if (
        createErr instanceof BchatApiError &&
        createErr.status === 409 &&
        createErr.errorCode === 'SESSION_ALREADY_PENDING'
      ) {
        try {
          const pendingRes = await fetchPendingRoomSession(canonicalRoomUuid, canonicalDeviceUuid);
          createdSession = pendingRes.data;
        } catch (pendingErr) {
          throw new BchatHandshakeCoordinatorError(
            `Sesi PENDING sudah ada namun gagal diambil ulang: ${(pendingErr as Error)?.message}`,
            'FETCH_PENDING_SESSION_FAILED',
            pendingErr
          );
        }
      } else {
        throw new BchatHandshakeCoordinatorError(
          `Gagal membuat generasi sesi baru untuk room [${canonicalRoomUuid}]: ${(createErr as Error)?.message}`,
          'CREATE_NEXT_SESSION_FAILED',
          createErr
        );
      }
    }

    targetSessionUuid = validateCanonicalUuid(createdSession.session_uuid, 'session_uuid');
    targetGenerationNumber = createdSession.generation_number;
  }

  // 5. Ambil partner KeyPackage dosen (diizinkan backend saat ACTIVE + pendingSession)
  let partnerPkg: BchatPartnerKeyPackageResponseData;
  try {
    const pkgRes = await fetchPartnerKeyPackage(canonicalRoomUuid, canonicalDeviceUuid);
    partnerPkg = pkgRes.data;
  } catch (apiErr) {
    throw new BchatHandshakeCoordinatorError(
      `Gagal mengambil KeyPackage dosen pembimbing untuk sesi baru: ${(apiErr as Error)?.message}`,
      'FETCH_PARTNER_KEY_PACKAGE_FAILED',
      apiErr
    );
  }

  if (!partnerPkg?.key_package_data || !partnerPkg?.recipient_device_uuid) {
    throw new BchatHandshakeCoordinatorError(
      'Data KeyPackage partner untuk inisiasi sesi baru tidak lengkap.',
      'INVALID_PARTNER_KEY_PACKAGE'
    );
  }

  const partnerDeviceUuid = validateCanonicalUuid(
    partnerPkg.recipient_device_uuid,
    'recipient_device_uuid'
  );
  const partnerPkgHash = partnerPkg.key_package_hash?.trim().toLowerCase();
  if (!SHA256_HEX_REGEX.test(partnerPkgHash)) {
    throw new BchatHandshakeCoordinatorError(
      'Hash KeyPackage partner tidak valid (wajib SHA-256 64 karakter hex).',
      'INVALID_PARTNER_KEY_PACKAGE_HASH'
    );
  }

  // 6. Siapkan payload Welcome (recovery dari outbox lokal jika ada; JANGAN regenerasi GroupState/Commit/Welcome)
  let targetGroupIdHash: string;
  let targetWelcomeWire: string;
  let targetClientMessageUuidWelcome: string;

  if (existingWelcomeItem) {
    targetGroupIdHash = preRestoreRes.groupIdHash!.trim().toLowerCase();
    targetWelcomeWire = existingWelcomeItem.ciphertextWireBase64;
    targetClientMessageUuidWelcome = existingWelcomeItem.clientMessageUuid;
  } else {
    let createdGroupIdHash: string;

    if (preRestoreRes.status === 'FOUND' && preRestoreRes.epoch === '0' && preRestoreRes.groupIdHash) {
      // Sisa CREATE_GROUP epoch 0 yang belum sempat COMMIT_ADD_MEMBER -> lanjutkan commit
      createdGroupIdHash = preRestoreRes.groupIdHash;
    } else {
      // Bentuk MLS Group baru (epoch 0) menggunakan Worker MLS yang sudah ada
      try {
        await workerRpc.sendRequest<GenerateKeyPackagePayload, GenerateKeyPackageResult>(
          'GENERATE_KEY_PACKAGE',
          {
            deviceUuid: canonicalDeviceUuid,
            fencing: cleanFencing,
          },
          timeoutMs
        );
      } catch (genErr) {
        throw new BchatHandshakeCoordinatorError(
          `Worker gagal menyiapkan KeyPackage lokal mahasiswa: ${(genErr as Error)?.message}`,
          'WORKER_GENERATE_KEY_PACKAGE_FAILED',
          genErr
        );
      }

      let createGroupRes: CreateGroupResult;
      try {
        createGroupRes = await workerRpc.sendRequest<CreateGroupPayload, CreateGroupResult>(
          'CREATE_GROUP',
          {
            roomUuid: canonicalRoomUuid,
            deviceUuid: canonicalDeviceUuid,
            fencing: cleanFencing,
          },
          timeoutMs
        );
      } catch (workerErr) {
        throw new BchatHandshakeCoordinatorError(
          `Worker gagal membuat grup MLS awal untuk sesi baru: ${(workerErr as Error)?.message}`,
          'WORKER_CREATE_GROUP_FAILED',
          workerErr
        );
      }

      if (!createGroupRes || createGroupRes.epoch !== '0' || !createGroupRes.groupIdHash) {
        throw new BchatHandshakeCoordinatorError(
          `Hasil CREATE_GROUP tidak valid (epoch: ${createGroupRes?.epoch}).`,
          'INVALID_CREATE_GROUP_RESULT'
        );
      }
      createdGroupIdHash = createGroupRes.groupIdHash;
    }

    // Commit Add Member (epoch 1) dan hasilkan Welcome message
    let commitRes: CommitAddMemberResult;
    try {
      commitRes = await workerRpc.sendRequest<CommitAddMemberPayload, CommitAddMemberResult>(
        'COMMIT_ADD_MEMBER',
        {
          partnerKeyPackageBase64: partnerPkg.key_package_data,
          roomUuid: canonicalRoomUuid,
          deviceUuid: canonicalDeviceUuid,
          fencing: cleanFencing,
        },
        timeoutMs
      );
    } catch (workerErr) {
      throw new BchatHandshakeCoordinatorError(
        `Worker gagal membuat Commit Add Member untuk sesi baru: ${(workerErr as Error)?.message}`,
        'WORKER_COMMIT_ADD_MEMBER_FAILED',
        workerErr
      );
    }

    if (
      !commitRes?.welcomeWireBase64 ||
      !commitRes?.clientMessageUuidCommit ||
      !commitRes?.clientMessageUuidWelcome ||
      commitRes.epoch !== '1'
    ) {
      throw new BchatHandshakeCoordinatorError(
        'Worker mengembalikan hasil COMMIT_ADD_MEMBER yang tidak valid untuk sesi baru.',
        'INVALID_COMMIT_RESULT'
      );
    }

    // Tandai Commit lokal sebagai acked di outbox (Commit tidak dikirim ke backend)
    try {
      await workerRpc.sendRequest<UpdateOutboxStatusPayload, UpdateOutboxStatusResult>(
        'UPDATE_OUTBOX_STATUS',
        {
          clientMessageUuid: commitRes.clientMessageUuidCommit,
          status: 'acked',
          deviceUuid: canonicalDeviceUuid,
          fencing: cleanFencing,
        },
        timeoutMs
      );
    } catch {
      // Abaikan error sekunder outbox commit
    }

    targetGroupIdHash = createdGroupIdHash;
    targetWelcomeWire = commitRes.welcomeWireBase64;
    targetClientMessageUuidWelcome = commitRes.clientMessageUuidWelcome;
  }

  // 7. Publikasikan Welcome ke POST /api/bchat/rooms/{roomUuid}/sessions/{sessionUuid}/welcome
  const publishPayload: BchatSessionWelcomePublishRequest = {
    recipient_device_uuid: partnerDeviceUuid,
    key_package_hash: partnerPkgHash,
    group_id_hash: targetGroupIdHash,
    welcome_message: targetWelcomeWire,
  };

  try {
    const pubRes = await publishSessionWelcome(
      canonicalRoomUuid,
      targetSessionUuid,
      publishPayload,
      canonicalDeviceUuid
    );

    try {
      await workerRpc.sendRequest<UpdateOutboxStatusPayload, UpdateOutboxStatusResult>(
        'UPDATE_OUTBOX_STATUS',
        {
          clientMessageUuid: targetClientMessageUuidWelcome,
          status: 'acked',
          deviceUuid: canonicalDeviceUuid,
          fencing: cleanFencing,
        },
        5000
      );
    } catch {
      // Abaikan error sekunder outbox
    }

    return {
      status: 'AWAITING_ACK',
      roomUuid: canonicalRoomUuid,
      sessionUuid: pubRes.data.session_uuid || targetSessionUuid,
      generationNumber: targetGenerationNumber,
      welcomeOperationUuid: pubRes.data.welcome_operation_uuid,
      challengeUuid: pubRes.data.challenge_uuid,
      expiresAt: pubRes.data.expires_at ?? null,
      groupIdHash: targetGroupIdHash,
      recipientDeviceUuid: partnerDeviceUuid,
    };
  } catch (publishErr) {
    // Error transient HTTP 429 (Rate Limit): pertahankan payload Welcome tetap 'pending' (bukan permanent failed)
    if (isRateLimitPublishError(publishErr)) {
      const retryAfterSeconds =
        publishErr instanceof BchatApiError ? publishErr.retryAfterSeconds : undefined;
      try {
        await workerRpc.sendRequest<UpdateOutboxStatusPayload, UpdateOutboxStatusResult>(
          'UPDATE_OUTBOX_STATUS',
          {
            clientMessageUuid: targetClientMessageUuidWelcome,
            status: 'pending',
            errorMessage: (publishErr as Error)?.message ?? 'Rate limited (HTTP 429)',
            deviceUuid: canonicalDeviceUuid,
            fencing: cleanFencing,
          },
          5000
        );
      } catch {
        // Abaikan error sekunder outbox
      }

      const waitNote = retryAfterSeconds
        ? `Silakan tunggu sekitar ${retryAfterSeconds} detik lalu klik publikasi ulang.`
        : 'Silakan tunggu beberapa saat lalu klik publikasi ulang.';

      throw new BchatHandshakeCoordinatorError(
        `Publikasi Welcome sesi Generasi #${targetGenerationNumber} dibatasi sementara oleh server (HTTP 429: Too Many Attempts). GroupState dan Welcome lokal tetap aman tersimpan. ${waitNote}`,
        'SESSION_WELCOME_RATE_LIMITED',
        publishErr,
        {
          status: 429,
          retryAfterSeconds: retryAfterSeconds ?? null,
          sessionUuid: targetSessionUuid,
          generationNumber: targetGenerationNumber,
        }
      );
    }

    if (isAmbiguousPublishError(publishErr)) {
      try {
        await workerRpc.sendRequest<UpdateOutboxStatusPayload, UpdateOutboxStatusResult>(
          'UPDATE_OUTBOX_STATUS',
          {
            clientMessageUuid: targetClientMessageUuidWelcome,
            status: 'in_transit',
            errorMessage: (publishErr as Error)?.message ?? 'Session welcome publish indeterminate',
            deviceUuid: canonicalDeviceUuid,
            fencing: cleanFencing,
          },
          5000
        );
      } catch {
        // Abaikan error sekunder outbox
      }

      // Rekonsiliasi ke backend sebelum gagal
      try {
        const reconRes = await fetchRoomDetail(canonicalRoomUuid);
        const reconRoom = reconRes.data;
        if (reconRoom.active_session?.session_uuid === targetSessionUuid) {
          return {
            status: 'ALREADY_ACTIVE',
            roomUuid: canonicalRoomUuid,
            sessionUuid: targetSessionUuid,
            generationNumber: reconRoom.active_session.generation_number,
            welcomeOperationUuid: '',
            challengeUuid: '',
            expiresAt: null,
            groupIdHash: targetGroupIdHash,
            recipientDeviceUuid: partnerDeviceUuid,
          };
        }
        if (
          reconRoom.pending_session?.session_uuid === targetSessionUuid &&
          reconRoom.pending_session.has_active_challenge
        ) {
          try {
            await workerRpc.sendRequest<UpdateOutboxStatusPayload, UpdateOutboxStatusResult>(
              'UPDATE_OUTBOX_STATUS',
              {
                clientMessageUuid: targetClientMessageUuidWelcome,
                status: 'acked',
                deviceUuid: canonicalDeviceUuid,
                fencing: cleanFencing,
              },
              5000
            );
          } catch {
            // Abaikan
          }
          return {
            status: 'AWAITING_ACK',
            roomUuid: canonicalRoomUuid,
            sessionUuid: targetSessionUuid,
            generationNumber: reconRoom.pending_session.generation_number,
            welcomeOperationUuid: reconRoom.pending_session.welcome_operation_uuid ?? '',
            challengeUuid: '',
            expiresAt: reconRoom.pending_session.expires_at ?? null,
            groupIdHash: targetGroupIdHash,
            recipientDeviceUuid: partnerDeviceUuid,
          };
        }
      } catch {
        // Lanjut lempar error ambigu
      }

      throw new BchatHandshakeCoordinatorError(
        `Publikasi Welcome sesi menghasilkan status ambigu: ${(publishErr as Error)?.message}`,
        'WELCOME_PUBLISH_IN_TRANSIT',
        publishErr
      );
    }

    try {
      await workerRpc.sendRequest<UpdateOutboxStatusPayload, UpdateOutboxStatusResult>(
        'UPDATE_OUTBOX_STATUS',
        {
          clientMessageUuid: targetClientMessageUuidWelcome,
          status: 'failed',
          errorMessage: (publishErr as Error)?.message ?? 'Session welcome publish rejected',
          deviceUuid: canonicalDeviceUuid,
          fencing: cleanFencing,
        },
        5000
      );
    } catch {
      // Abaikan
    }

    throw new BchatHandshakeCoordinatorError(
      `Publikasi Welcome sesi baru ditolak oleh server: ${(publishErr as Error)?.message}`,
      'SESSION_WELCOME_PUBLISH_FAILED',
      publishErr
    );
  }
}

/**
 * Mengorkestrasikan penerimaan Welcome, JOIN_WELCOME, persistensi GroupState,
 * penandatanganan Welcome ACK 158-byte Ed25519, dan publikasi ACK oleh dosen
 * untuk mengaktifkan sesi generasi baru (BchatRoomSession).
 *
 * Menangani rotasi challenge otomatis jika challenge kedaluwarsa (CHALLENGE_EXPIRED)
 * tanpa mengonsumsi ulang PrivateKeyPackage (anti-reuse defense via RESTORE_GROUP_STATE).
 */
export async function completePendingSessionHandshakeFlow(
  workerRpc: BchatWorkerClient,
  roomUuid: string,
  deviceUuid: string,
  options: CompletePendingSessionOptions
): Promise<CompletePendingSessionResult> {
  const canonicalRoomUuid = validateCanonicalUuid(roomUuid, 'roomUuid');
  const canonicalDeviceUuid = validateCanonicalUuid(deviceUuid, 'deviceUuid');
  const cleanFencing = validateFencing(options?.fencing, 'completePendingSessionHandshakeFlow');

  if (!workerRpc || typeof workerRpc.sendRequest !== 'function') {
    throw new BchatHandshakeCoordinatorError(
      'Worker RPC client tidak valid atau belum diinisialisasi.',
      'INVALID_WORKER_CLIENT'
    );
  }

  const timeoutMs = options.timeoutMs ?? 25000;

  // 1. Ambil detail room untuk memverifikasi pending_session yang valid
  let roomDetail: BchatRoomDetail;
  try {
    const roomRes = await fetchRoomDetail(canonicalRoomUuid);
    roomDetail = roomRes.data;
  } catch (apiErr) {
    throw new BchatHandshakeCoordinatorError(
      `Gagal mengambil detail room [${canonicalRoomUuid}]: ${(apiErr as Error)?.message}`,
      'FETCH_ROOM_DETAIL_FAILED',
      apiErr
    );
  }

  if (roomDetail.status !== 'ACTIVE') {
    throw new BchatHandshakeCoordinatorError(
      `Penyelesaian sesi generasi baru hanya berlaku pada room ACTIVE (status: ${roomDetail.status}).`,
      'INVALID_ROOM_STATUS'
    );
  }

  const pendingSession = roomDetail.pending_session;
  if (!pendingSession) {
    if (
      options.expectedSessionUuid &&
      roomDetail.active_session?.session_uuid?.toLowerCase() ===
        options.expectedSessionUuid.trim().toLowerCase()
    ) {
      return {
        roomUuid: canonicalRoomUuid,
        sessionUuid: roomDetail.active_session.session_uuid,
        generationNumber: roomDetail.active_session.generation_number,
        sessionStatus: 'ACTIVE',
        startSequence: roomDetail.active_session.start_sequence,
        activatedAt: roomDetail.updated_at ?? new Date().toISOString(),
        welcomeOperationUuid: '',
        challengeUuid: '',
        signatureBase64: '',
        groupIdHash: roomDetail.group_id_hash ?? '',
      };
    }

    throw new BchatHandshakeCoordinatorError(
      'Tidak ditemukan sesi generasi baru berstatus PENDING pada ruang obrolan ini.',
      'NO_PENDING_SESSION'
    );
  }

  if (
    !pendingSession.has_active_challenge ||
    pendingSession.action_type !== 'FETCH_SESSION_CHALLENGE_AND_ACK'
  ) {
    throw new BchatHandshakeCoordinatorError(
      `Sesi PENDING belum siap dikonfirmasi (action_type: ${pendingSession.action_type}).`,
      'SESSION_ACTION_NOT_PERMITTED'
    );
  }

  if (
    pendingSession.recipient_device_uuid &&
    pendingSession.recipient_device_uuid.toLowerCase() !== canonicalDeviceUuid
  ) {
    throw new BchatHandshakeCoordinatorError(
      'Paket Welcome sesi ini ditujukan untuk perangkat lain pada akun Anda.',
      'RECIPIENT_DEVICE_MISMATCH'
    );
  }

  const targetSessionUuid = validateCanonicalUuid(pendingSession.session_uuid, 'session_uuid');

  // Resolusi user_id penerima (dosen)
  let resolvedUserId: string | number | bigint;
  if (options.userId !== undefined && options.userId !== null) {
    resolvedUserId = options.userId;
  } else {
    const identityKey = await getActiveDeviceIdentityKey(canonicalDeviceUuid);
    const userIdFromMeta = identityKey?.metadata?.user_id;
    if (
      userIdFromMeta !== undefined &&
      userIdFromMeta !== null &&
      String(userIdFromMeta).trim() !== ''
    ) {
      resolvedUserId = String(userIdFromMeta);
    } else {
      throw new BchatHandshakeCoordinatorError(
        `Recipient user ID tidak dapat ditentukan untuk perangkat [${canonicalDeviceUuid}].`,
        'RECIPIENT_USER_ID_REQUIRED'
      );
    }
  }

  // Loop maksimal 2 kali: percobaan ke-2 hanya jika ACK terkena CHALLENGE_EXPIRED (rotasi in-place)
  for (let attempt = 0; attempt < 2; attempt++) {
    // 2. Ambil Welcome challenge sesi (backend otomatis merotasi in-place jika expired)
    let challengeData: BchatSessionWelcomeChallengeResponseData;
    try {
      const challengeRes = await fetchSessionWelcomeChallenge(
        canonicalRoomUuid,
        targetSessionUuid,
        canonicalDeviceUuid
      );
      challengeData = challengeRes.data;
    } catch (apiErr) {
      throw new BchatHandshakeCoordinatorError(
        `Gagal mengambil tantangan Welcome sesi [${targetSessionUuid}]: ${(apiErr as Error)?.message}`,
        'FETCH_SESSION_CHALLENGE_FAILED',
        apiErr
      );
    }

    if (
      !challengeData?.welcome_message ||
      !challengeData?.challenge_uuid ||
      !challengeData?.challenge_nonce ||
      !challengeData?.group_id_hash
    ) {
      throw new BchatHandshakeCoordinatorError(
        'Data tantangan Welcome sesi dari backend tidak lengkap.',
        'INVALID_WELCOME_CHALLENGE_DATA'
      );
    }

    if (
      challengeData.recipient_device_uuid &&
      challengeData.recipient_device_uuid.toLowerCase() !== canonicalDeviceUuid
    ) {
      throw new BchatHandshakeCoordinatorError(
        'Tantangan Welcome sesi ditujukan untuk perangkat lain.',
        'RECIPIENT_DEVICE_MISMATCH'
      );
    }

    const canonicalChallengeGroupIdHash = challengeData.group_id_hash.trim().toLowerCase();
    if (!SHA256_HEX_REGEX.test(canonicalChallengeGroupIdHash)) {
      throw new BchatHandshakeCoordinatorError(
        'group_id_hash tantangan sesi tidak valid (wajib SHA-256 64 hex).',
        'INVALID_CHALLENGE_GROUP_ID_HASH'
      );
    }

    const canonicalChallengeUuid = validateCanonicalUuid(
      challengeData.challenge_uuid,
      'challenge_uuid'
    );
    const canonicalWelcomeOpUuid = validateCanonicalUuid(
      challengeData.welcome_operation_uuid,
      'welcome_operation_uuid'
    );

    // 3. Cek GroupState lokal terlebih dahulu (anti-reuse PrivateKeyPackage pada retry / rotasi challenge)
    let restoreRes: RestoreGroupStateResult;
    try {
      restoreRes = await workerRpc.sendRequest<RestoreGroupStatePayload, RestoreGroupStateResult>(
        'RESTORE_GROUP_STATE',
        {
          roomUuid: canonicalRoomUuid,
          deviceUuid: canonicalDeviceUuid,
          fencing: cleanFencing,
        },
        timeoutMs
      );
    } catch (workerErr) {
      throw new BchatHandshakeCoordinatorError(
        `Worker gagal memeriksa GroupState untuk sesi baru: ${(workerErr as Error)?.message}`,
        'WORKER_RESTORE_GROUP_STATE_FAILED',
        workerErr
      );
    }

    let targetGroupIdHash: string;

    if (restoreRes.status === 'FOUND') {
      if (restoreRes.epoch !== '1' || !restoreRes.groupIdHash) {
        throw new BchatHandshakeCoordinatorError(
          `GroupState lokal pada epoch [${restoreRes.epoch}] tidak cocok untuk Welcome ACK sesi.`,
          'INVALID_GROUP_STATE_EPOCH'
        );
      }
      targetGroupIdHash = restoreRes.groupIdHash;
    } else if (restoreRes.status === 'NOT_FOUND') {
      const expectedRef = extractWelcomeKeyPackageRef(challengeData.welcome_message);
      if (!expectedRef) {
        throw new BchatHandshakeCoordinatorError(
          'SECURITY STOP: Gagal mengekstrak KeyPackageRef dari Welcome message sesi.',
          'KEY_PACKAGE_REF_EXTRACTION_FAILED'
        );
      }

      const matchedKeyPackageHash = await findMatchingLocalKeyPackageHash(
        canonicalDeviceUuid,
        expectedRef
      );

      let joinRes: JoinWelcomeResult;
      try {
        joinRes = await workerRpc.sendRequest<JoinWelcomePayload, JoinWelcomeResult>(
          'JOIN_WELCOME',
          {
            welcomeWireBase64: challengeData.welcome_message,
            keyPackageHash: matchedKeyPackageHash,
            roomUuid: canonicalRoomUuid,
            deviceUuid: canonicalDeviceUuid,
            fencing: cleanFencing,
          },
          timeoutMs
        );
      } catch (workerErr) {
        throw new BchatHandshakeCoordinatorError(
          `Worker gagal mengeksekusi JOIN_WELCOME untuk sesi baru: ${(workerErr as Error)?.message}`,
          'WORKER_JOIN_WELCOME_FAILED',
          workerErr
        );
      }

      if (!joinRes || joinRes.epoch !== '1' || !joinRes.groupIdHash) {
        throw new BchatHandshakeCoordinatorError(
          `Hasil JOIN_WELCOME sesi tidak valid (epoch: ${joinRes?.epoch}).`,
          'INVALID_JOIN_WELCOME_RESULT'
        );
      }
      targetGroupIdHash = joinRes.groupIdHash;
    } else {
      throw new BchatHandshakeCoordinatorError(
        `Pemulihan GroupState gagal dengan status [${restoreRes.status}]: ${restoreRes.message ?? 'Unknown error'}.`,
        `RESTORE_STATUS_${restoreRes.status}`
      );
    }

    const normalizedTargetGroupIdHash = targetGroupIdHash.toLowerCase();
    if (canonicalChallengeGroupIdHash !== normalizedTargetGroupIdHash) {
      throw new BchatHandshakeCoordinatorError(
        'Hash Group ID tidak cocok antara GroupState lokal dan challenge sesi backend.',
        'GROUP_ID_HASH_MISMATCH'
      );
    }

    // 4. Bangun payload Welcome ACK kanonis 158 byte & tandatangani dengan Ed25519 Device Identity Key
    const ackPayload = buildWelcomeAckPayload({
      roomUuid: canonicalRoomUuid,
      recipientUserId: resolvedUserId,
      welcomeOperationUuid: canonicalWelcomeOpUuid,
      challengeUuid: canonicalChallengeUuid,
      challengeGeneration: challengeData.challenge_generation,
      groupIdHash: normalizedTargetGroupIdHash,
      epoch: BigInt(1),
      challengeNonce: challengeData.challenge_nonce,
    });

    const signedAck = await signWelcomeAckWithDeviceIdentity(
      ackPayload.payloadBytes,
      canonicalDeviceUuid
    );

    const ackReq: BchatSessionWelcomeAckRequest = {
      challenge_uuid: canonicalChallengeUuid,
      signature: signedAck.signatureBase64,
    };

    try {
      const ackRes = await submitSessionWelcomeAck(
        canonicalRoomUuid,
        targetSessionUuid,
        ackReq,
        canonicalDeviceUuid
      );
      const activated = ackRes.data;

      if (!activated || activated.status !== 'ACTIVE') {
        throw new BchatHandshakeCoordinatorError(
          `Aktivasi sesi gagal: status backend [${activated?.status}] bukan ACTIVE.`,
          'SESSION_ACTIVATION_FAILED'
        );
      }

      return {
        roomUuid: canonicalRoomUuid,
        sessionUuid: activated.session_uuid,
        generationNumber: activated.generation_number,
        sessionStatus: activated.status,
        startSequence: activated.start_sequence,
        activatedAt: activated.activated_at ?? new Date().toISOString(),
        welcomeOperationUuid: canonicalWelcomeOpUuid,
        challengeUuid: canonicalChallengeUuid,
        signatureBase64: signedAck.signatureBase64,
        groupIdHash: normalizedTargetGroupIdHash,
      };
    } catch (ackErr) {
      // Jika challenge kedaluwarsa di antara fetch dan submit, lakukan tepat 1x retry dengan rotasi in-place
      if (
        attempt === 0 &&
        ackErr instanceof BchatApiError &&
        ackErr.status === 409 &&
        ackErr.errorCode === 'CHALLENGE_EXPIRED'
      ) {
        continue;
      }

      // Jika error jaringan ambigu, rekonsiliasi apakah sesi ternyata sudah ACTIVE
      if (isAmbiguousPublishError(ackErr)) {
        try {
          const recon = await fetchRoomDetail(canonicalRoomUuid);
          if (recon.data?.active_session?.session_uuid === targetSessionUuid) {
            return {
              roomUuid: canonicalRoomUuid,
              sessionUuid: recon.data.active_session.session_uuid,
              generationNumber: recon.data.active_session.generation_number,
              sessionStatus: 'ACTIVE',
              startSequence: recon.data.active_session.start_sequence,
              activatedAt: recon.data.updated_at ?? new Date().toISOString(),
              welcomeOperationUuid: canonicalWelcomeOpUuid,
              challengeUuid: canonicalChallengeUuid,
              signatureBase64: signedAck.signatureBase64,
              groupIdHash: normalizedTargetGroupIdHash,
            };
          }
        } catch {
          // Abaikan error rekonsiliasi sekunder
        }
      }

      throw new BchatHandshakeCoordinatorError(
        `Pengiriman Welcome ACK sesi ke backend gagal: ${(ackErr as Error)?.message}`,
        'SESSION_WELCOME_ACK_FAILED',
        ackErr
      );
    }
  }

  throw new BchatHandshakeCoordinatorError(
    'Gagal menyelesaikan Welcome ACK sesi setelah rotasi challenge.',
    'SESSION_WELCOME_ACK_EXHAUSTED'
  );
}
