/**
 * B-CHAT E2EE — Dedicated Browser Web Worker PoC Types & RPC Contract
 *
 * Mendefinisikan tipe data, payload, dan allowlist hasil RPC untuk komunikasi
 * antara Main Thread (UI/Harness) dan Dedicated MLS Worker.
 *
 * ATURAN KEAMANAN MUTLAK:
 * - Data yang keluar dari worker HANYA boleh berupa wireformat publik, hash, epoch string, atau plaintext.
 * - ClientState, PrivateKeyPackage, secretTree, privatePath, dsb DILARANG KERAS keluar melalui RPC.
 */

export type WorkerPeerRole = 'mahasiswa' | 'dosen';

export type WorkerRpcAction =
  | 'INIT_PEER'
  | 'GENERATE_KEY_PACKAGE'
  | 'CREATE_GROUP'
  | 'COMMIT_ADD_MEMBER'
  | 'JOIN_WELCOME'
  | 'ENCRYPT_MESSAGE'
  | 'DECRYPT_MESSAGE'
  | 'RESTORE_GROUP_STATE'
  | 'RETRY_OUTBOX_MESSAGE'
  | 'UPDATE_OUTBOX_STATUS'
  | 'CLAIM_OUTBOX_MESSAGE'
  | 'GET_ROOM_OUTBOX'
  | 'DERIVE_ATTACHMENT_KEY';

// ---------------------------------------------------------------------------
// Request Payloads
// ---------------------------------------------------------------------------

export interface FencingRpcInput {
  ownerToken: string;
  generation: string;
}

export interface InitPeerPayload {
  role: WorkerPeerRole;
  identity: string;
  deviceUuid?: string;
  fencing?: FencingRpcInput;
}

export interface GenerateKeyPackagePayload {
  deviceUuid?: string;
  fencing?: FencingRpcInput;
}

export interface CreateGroupPayload {
  roomUuid: string;
  deviceUuid?: string;
  fencing?: FencingRpcInput;
}

export interface CommitAddMemberPayload {
  partnerKeyPackageBase64: string;
  clientMessageUuidCommit?: string;
  clientMessageUuidWelcome?: string;
  operationId?: string;
  deviceUuid?: string;
  roomUuid?: string;
  fencing?: FencingRpcInput;
}

export interface JoinWelcomePayload {
  welcomeWireBase64: string;
  keyPackageHash?: string;
  roomUuid?: string;
  deviceUuid?: string;
  fencing?: FencingRpcInput;
}

export interface EncryptMessagePayload {
  plaintext: string;
  clientMessageUuid?: string;
  deviceUuid?: string;
  roomUuid?: string;
  fencing?: FencingRpcInput;
}

export interface DecryptMessagePayload {
  ciphertextWireBase64: string;
  deviceUuid?: string;
  roomUuid?: string;
  fencing?: FencingRpcInput;
  serverSequence?: number;
}

export interface RetryOutboxMessagePayload {
  clientMessageUuid: string;
  deviceUuid?: string;
  fencing?: FencingRpcInput;
}

export interface UpdateOutboxStatusPayload {
  clientMessageUuid: string;
  status: 'pending' | 'in_transit' | 'acked' | 'failed';
  sentAt?: number;
  errorMessage?: string | null;
  deviceUuid?: string;
  fencing?: FencingRpcInput;
  claimToken?: string;
}

export interface ClaimOutboxMessagePayload {
  clientMessageUuid: string;
  deviceUuid?: string;
  roomUuid?: string;
  fencing?: FencingRpcInput;
  claimTimeoutMs?: number;
}

export interface GetRoomOutboxPayload {
  roomUuid: string;
  status?: 'pending' | 'in_transit' | 'acked' | 'failed';
}

export interface RestoreGroupStatePayload {
  deviceUuid: string;
  roomUuid: string;
  fencing: {
    ownerToken: string;
    generation: string;
  };
}

export interface DeriveAttachmentKeyPayload {
  roomUuid: string;
  messageUuid: string;
  attachmentUuid: string;
  deviceUuid?: string;
  fencing?: FencingRpcInput;
}

// ---------------------------------------------------------------------------
// Response Data Allowlist (Hanya field ini yang sah keluar dari Worker)
// ---------------------------------------------------------------------------

export interface InitPeerResult {
  initialized: true;
  role: WorkerPeerRole;
  ciphersuite: string;
}

export interface GenerateKeyPackageResult {
  keyPackageWireBase64: string;
  keyPackageHash: string;
}

export interface CreateGroupResult {
  epoch: string;
  groupIdHash: string;
  stateRevision?: string;
}

export interface CommitAddMemberResult {
  welcomeWireBase64: string;
  commitWireBase64?: string;
  clientMessageUuidCommit?: string;
  clientMessageUuidWelcome?: string;
  operationId?: string;
  epoch: string;
  stateRevision?: string;
}

export interface JoinWelcomeResult {
  epoch: string;
  groupIdHash: string;
  stateRevision?: string;
}

export interface EncryptMessageResult {
  ciphertextWireBase64: string;
  epoch: string;
  clientMessageUuid?: string;
  stateRevision?: string;
}

export interface DecryptMessageResult {
  plaintext: string;
  epoch: string;
  senderLeafIndex: number;
  stateRevision?: string;
}

export interface RetryOutboxMessageResult {
  clientMessageUuid: string;
  roomUuid: string;
  ciphertextWireBase64: string;
  status: 'pending' | 'in_transit' | 'acked' | 'failed';
}

export interface UpdateOutboxStatusResult {
  success: boolean;
  clientMessageUuid: string;
  status: string;
}

export interface ClaimOutboxMessageResult {
  success: boolean;
  clientMessageUuid: string;
  roomUuid: string;
  status: 'in_transit';
  ciphertextWireBase64: string;
  claimToken: string;
  retryCount: number;
  sentAt: number;
  metadata?: Record<string, unknown>;
}

export interface OutboxItemSummary {
  clientMessageUuid: string;
  roomUuid: string;
  status: 'pending' | 'in_transit' | 'acked' | 'failed';
  ciphertextWireBase64: string;
  createdAt: number;
  sentAt?: number;
  retryCount?: number;
  messageType?: string;
}

export interface GetRoomOutboxResult {
  messages: OutboxItemSummary[];
}

export type WorkerRestoreStatus =
  | 'FOUND'
  | 'NOT_FOUND'
  | 'QUARANTINED'
  | 'FENCING_STALE'
  | 'RESTORE_ERROR';

export interface RestoreGroupStateResult {
  status: WorkerRestoreStatus;
  epoch?: string;
  groupIdHash?: string;
  stateRevision?: string;
  quarantineReason?: string;
  message?: string;
  lastProcessedSequence?: number;
}

export interface DeriveAttachmentKeyResult {
  cekBase64: string;
  keyLengthBytes: number;
  epoch: string;
}

// ---------------------------------------------------------------------------
// General RPC Envelope
// ---------------------------------------------------------------------------

export interface WorkerRpcRequest<T = unknown> {
  requestId: string;
  action: WorkerRpcAction;
  payload: T;
}

export interface WorkerRpcError {
  code: string;
  message: string;
}

export interface WorkerRpcResponse<R = unknown> {
  requestId: string;
  action: WorkerRpcAction;
  success: boolean;
  data?: R;
  error?: WorkerRpcError;
}

// ---------------------------------------------------------------------------
// Runtime Security Auditor: Memverifikasi Tidak Ada Secret / ClientState Bocor
// ---------------------------------------------------------------------------

const FORBIDDEN_SECRET_KEYS = [
  'clientstate',
  'groupstate',
  'privatepackage',
  'initprivatekey',
  'hpkeprivatekey',
  'signatureprivatekey',
  'privatepath',
  'secrettree',
  'keyschedule',
  'confirmationtag',
  'ratchettree',
  'generationsecret',
  'senderdataentry',
  'encryptionsecret',
];

/**
 * Memeriksa seluruh hierarki objek respons RPC untuk menjamin tidak ada
 * potongan state rahasia internal MLS yang lolos ke Main Thread.
 */
export function auditRpcResponseSecurity(res: WorkerRpcResponse): { safe: boolean; reason?: string } {
  if (!res || typeof res !== 'object') {
    return { safe: true };
  }

  function scan(obj: unknown, path: string): { safe: boolean; reason?: string } {
    if (!obj || typeof obj !== 'object') {
      return { safe: true };
    }

    if (Array.isArray(obj)) {
      for (let i = 0; i < obj.length; i++) {
        const check = scan(obj[i], `${path}[${i}]`);
        if (!check.safe) return check;
      }
      return { safe: true };
    }

    const record = obj as Record<string, unknown>;
    for (const key of Object.keys(record)) {
      const lowerKey = key.toLowerCase();
      for (const forbidden of FORBIDDEN_SECRET_KEYS) {
        if (lowerKey.includes(forbidden)) {
          return {
            safe: false,
            reason: `DENYLIST VIOLATION: Kunci terlarang '${key}' terdeteksi pada path '${path}.${key}'`,
          };
        }
      }

      const val = record[key];
      if (val && typeof val === 'object') {
        const check = scan(val, `${path}.${key}`);
        if (!check.safe) return check;
      }
    }

    return { safe: true };
  }

  return scan(res.data, 'response.data');
}

