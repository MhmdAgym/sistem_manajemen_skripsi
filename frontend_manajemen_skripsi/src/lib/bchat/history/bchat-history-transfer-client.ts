/**
 * B-CHAT E2EE — History Transfer API Client (Tahap 2B)
 */

import axios from '@/lib/axios';

export interface BchatHistoryTransferData {
  transfer_uuid: string;
  room_uuid: string;
  recipient_device_uuid: string;
  source_device_uuid: string;
  key_exchange_algorithm: string;
  recipient_ephemeral_public_key: string;
  source_ephemeral_public_key?: string | null;
  transfer_nonce: string;
  status: 'REQUESTED' | 'READY' | 'COMPLETED' | 'CANCELLED' | 'EXPIRED' | 'FAILED';
  snapshot_hash_sha256?: string | null;
  snapshot_iv?: string | null;
  snapshot_auth_tag?: string | null;
  snapshot_metadata?: Record<string, unknown> | null;
  uploaded_at?: string | null;
  completed_at?: string | null;
  cancelled_at?: string | null;
  cancellation_reason?: string | null;
  expires_at: string;
  created_at: string;
}

export interface DownloadSnapshotResponseData {
  transfer_uuid: string;
  room_uuid: string;
  source_device_uuid: string;
  source_device_public_key?: string | null;
  recipient_device_uuid: string;
  key_exchange_algorithm: string;
  source_ephemeral_public_key: string;
  recipient_ephemeral_public_key: string;
  snapshot_ciphertext: string;
  snapshot_hash_sha256: string;
  snapshot_iv: string;
  snapshot_auth_tag?: string | null;
  snapshot_metadata?: Record<string, unknown> | null;
  source_signature: string;
  expires_at: string;
}

export async function requestHistoryTransfer(
  roomUuid: string,
  payload: {
    source_device_uuid: string;
    key_exchange_algorithm?: string;
    recipient_ephemeral_public_key: string;
    transfer_nonce: string;
    request_signature: string;
  },
  deviceUuid: string
): Promise<{ data: BchatHistoryTransferData }> {
  const res = await axios.post(`/api/bchat/rooms/${roomUuid}/history-transfers`, payload, {
    headers: { 'X-Bchat-Device-Uuid': deviceUuid },
  });
  return res.data;
}

export async function fetchPendingHistoryTransfers(
  deviceUuid: string
): Promise<{ data: BchatHistoryTransferData[] }> {
  const res = await axios.get('/api/bchat/history-transfers/pending', {
    headers: { 'X-Bchat-Device-Uuid': deviceUuid },
  });
  return res.data;
}

export async function fetchHistoryTransferDetail(
  transferUuid: string,
  deviceUuid: string
): Promise<{ data: BchatHistoryTransferData }> {
  const res = await axios.get(`/api/bchat/history-transfers/${transferUuid}`, {
    headers: { 'X-Bchat-Device-Uuid': deviceUuid },
  });
  return res.data;
}

export async function uploadHistoryTransferSnapshot(
  transferUuid: string,
  payload: {
    source_ephemeral_public_key: string;
    snapshot_ciphertext: string;
    snapshot_hash_sha256: string;
    snapshot_iv?: string | null;
    snapshot_auth_tag?: string | null;
    snapshot_metadata?: Record<string, unknown> | null;
    source_signature: string;
  },
  deviceUuid: string
): Promise<{ data: BchatHistoryTransferData }> {
  const res = await axios.post(`/api/bchat/history-transfers/${transferUuid}/upload`, payload, {
    headers: { 'X-Bchat-Device-Uuid': deviceUuid },
  });
  return res.data;
}

export async function downloadHistoryTransferSnapshot(
  transferUuid: string,
  deviceUuid: string
): Promise<{ data: DownloadSnapshotResponseData }> {
  const res = await axios.get(`/api/bchat/history-transfers/${transferUuid}/download`, {
    headers: { 'X-Bchat-Device-Uuid': deviceUuid },
  });
  return res.data;
}

export async function completeHistoryTransfer(
  transferUuid: string,
  payload: {
    completion_signature: string;
  },
  deviceUuid: string
): Promise<{ data: BchatHistoryTransferData }> {
  const res = await axios.post(`/api/bchat/history-transfers/${transferUuid}/complete`, payload, {
    headers: { 'X-Bchat-Device-Uuid': deviceUuid },
  });
  return res.data;
}

export async function cancelHistoryTransfer(
  transferUuid: string,
  reason: string,
  deviceUuid: string
): Promise<{ data: BchatHistoryTransferData }> {
  const res = await axios.post(
    `/api/bchat/history-transfers/${transferUuid}/cancel`,
    { reason },
    { headers: { 'X-Bchat-Device-Uuid': deviceUuid } }
  );
  return res.data;
}

