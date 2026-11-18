/**
 * B-CHAT E2EE — Trusted Device Pairing Client Coordinator (Tahap 2A)
 *
 * Mengoordinasikan alur pairing kriptografis antar-perangkat tepercaya:
 * - Pembuatan permintaan pairing oleh perangkat baru (target device).
 * - Pemindaian / lookup kode pairing oleh perangkat tepercaya (approver device).
 * - Konstruksi buffer kanonis biner 159-byte persetujuan pairing.
 * - Penandatanganan biner menggunakan WebCrypto Ed25519 non-extractable private key.
 * - Pengiriman bukti persetujuan ke server & polling status hingga selesai.
 */

import axios from '@/lib/axios';
import {
  parseBchatApiError,
  BchatApiError,
} from '@/lib/bchat/api/bchat-api-client';
import type {
  BchatSuccessResponse,
  BchatPairingRequestData,
  BchatPairingStatusData,
  BchatPairingLookupData,
  BchatPairingApprovalResultData,
} from '@/lib/bchat/api/bchat-api-types';
import {
  getActiveDeviceIdentityKey,
  findActiveLocalDevice,
  BchatDeviceRegistrationError,
} from '@/lib/bchat/device/bchat-device-registration';

export const PAIRING_APPROVAL_MAGIC = 'UNUHA-BCHAT-PAIRING-APPROVAL-V1';
export const PAIRING_APPROVAL_PAYLOAD_BYTES = 159;
export const PAIRING_APPROVAL_SIGNATURE_BYTES = 64;

// -----------------------------------------------------------------------------
// HELPER SERIALISASI BINER
// -----------------------------------------------------------------------------

function hexToBytes(hex: string): Uint8Array {
  const clean = hex.replace(/[^0-9a-fA-F]/g, '');
  if (clean.length % 2 !== 0) {
    throw new Error('Hex string invalid (panjang ganjil)');
  }
  const bytes = new Uint8Array(clean.length / 2);
  for (let i = 0; i < clean.length; i += 2) {
    bytes[i / 2] = parseInt(clean.substring(i, i + 2), 16);
  }
  return bytes;
}

function bytesToHex(bytes: Uint8Array): string {
  let hex = '';
  for (let i = 0; i < bytes.length; i++) {
    hex += bytes[i].toString(16).padStart(2, '0');
  }
  return hex;
}

function uuidToBytes16(uuid: string): Uint8Array {
  const clean = uuid.replace(/-/g, '').toLowerCase();
  if (clean.length !== 32) {
    throw new Error(`Format UUID invalid: ${uuid}`);
  }
  return hexToBytes(clean);
}

function encodeUint64BE(num: number | bigint): Uint8Array {
  const buf = new ArrayBuffer(8);
  const view = new DataView(buf);
  const big = typeof num === 'bigint' ? num : BigInt(Math.floor(num));
  view.setBigUint64(0, big, false); // Big-Endian
  return new Uint8Array(buf);
}

function asciiToBytes(str: string): Uint8Array {
  const bytes = new Uint8Array(str.length);
  for (let i = 0; i < str.length; i++) {
    bytes[i] = str.charCodeAt(i);
  }
  return bytes;
}

/**
 * Membangun buffer kanonis biner 159-byte persetujuan pairing.
 */
export function buildCanonicalApprovalPayload(params: {
  userId: number;
  pairingUuid: string;
  approverDeviceUuid: string;
  targetDeviceUuid: string;
  challengeNonceHex: string;
  targetFingerprintHex: string;
  timestamp: number;
}): Uint8Array {
  const magicBytes = asciiToBytes(PAIRING_APPROVAL_MAGIC); // 31 bytes
  const userIdBytes = encodeUint64BE(params.userId); // 8 bytes
  const pairingUuidBytes = uuidToBytes16(params.pairingUuid); // 16 bytes
  const approverUuidBytes = uuidToBytes16(params.approverDeviceUuid); // 16 bytes
  const targetUuidBytes = uuidToBytes16(params.targetDeviceUuid); // 16 bytes
  const nonceBytes = hexToBytes(params.challengeNonceHex); // 32 bytes
  const fingerprintBytes = hexToBytes(params.targetFingerprintHex); // 32 bytes
  const timestampBytes = encodeUint64BE(params.timestamp); // 8 bytes

  const totalLength =
    magicBytes.length +
    userIdBytes.length +
    pairingUuidBytes.length +
    approverUuidBytes.length +
    targetUuidBytes.length +
    nonceBytes.length +
    fingerprintBytes.length +
    timestampBytes.length;

  if (totalLength !== PAIRING_APPROVAL_PAYLOAD_BYTES) {
    throw new Error(
      `Panjang payload approval tidak valid: ${totalLength} byte (diharapkan ${PAIRING_APPROVAL_PAYLOAD_BYTES} byte).`
    );
  }

  const payload = new Uint8Array(totalLength);
  let offset = 0;

  payload.set(magicBytes, offset);
  offset += magicBytes.length;

  payload.set(userIdBytes, offset);
  offset += userIdBytes.length;

  payload.set(pairingUuidBytes, offset);
  offset += pairingUuidBytes.length;

  payload.set(approverUuidBytes, offset);
  offset += approverUuidBytes.length;

  payload.set(targetUuidBytes, offset);
  offset += targetUuidBytes.length;

  payload.set(nonceBytes, offset);
  offset += nonceBytes.length;

  payload.set(fingerprintBytes, offset);
  offset += fingerprintBytes.length;

  payload.set(timestampBytes, offset);
  offset += timestampBytes.length;

  return payload;
}

// -----------------------------------------------------------------------------
// PENANDATANGANAN KRIPTOGRAFIS WEBCRYPTO ED25519
// -----------------------------------------------------------------------------

function getCryptoSubtle(): SubtleCrypto {
  if (typeof window !== 'undefined' && window.crypto?.subtle) {
    return window.crypto.subtle;
  }
  if (typeof self !== 'undefined' && self.crypto?.subtle) {
    return self.crypto.subtle;
  }
  throw new BchatDeviceRegistrationError(
    'W3C WebCrypto API tidak tersedia pada peramban ini.',
    'WEBCRYPTO_UNAVAILABLE'
  );
}

export async function signPairingApprovalPayload(
  privateKey: CryptoKey,
  payloadBytes: Uint8Array
): Promise<{ signatureBytes: Uint8Array; signatureHex: string }> {
  const subtle = getCryptoSubtle();
  const sigBuf = await subtle.sign('Ed25519', privateKey, payloadBytes.buffer as ArrayBuffer);
  const signatureBytes = new Uint8Array(sigBuf);

  if (signatureBytes.length !== PAIRING_APPROVAL_SIGNATURE_BYTES) {
    throw new Error(
      `Panjang signature Ed25519 invalid: ${signatureBytes.length} byte (diharapkan 64 byte).`
    );
  }

  return {
    signatureBytes,
    signatureHex: bytesToHex(signatureBytes),
  };
}

// -----------------------------------------------------------------------------
// API CLIENT PAIRING ENDPOINTS
// -----------------------------------------------------------------------------

/**
 * 1. Membuat permintaan pairing untuk perangkat target.
 * POST /api/bchat/pairing/request
 */
export async function requestPairing(
  targetDeviceUuid: string
): Promise<BchatSuccessResponse<BchatPairingRequestData>> {
  try {
    const res = await axios.post<BchatSuccessResponse<BchatPairingRequestData>>(
      '/api/bchat/pairing/request',
      { target_device_uuid: targetDeviceUuid }
    );
    return res.data;
  } catch (err) {
    throw parseBchatApiError(err);
  }
}

/**
 * 2. Memeriksa status sesi pairing (polling/status endpoint).
 * GET /api/bchat/pairing/{pairingUuid}
 */
export async function getPairingStatus(
  pairingUuid: string
): Promise<BchatSuccessResponse<BchatPairingStatusData>> {
  try {
    const res = await axios.get<BchatSuccessResponse<BchatPairingStatusData>>(
      `/api/bchat/pairing/${encodeURIComponent(pairingUuid)}`
    );
    return res.data;
  } catch (err) {
    throw parseBchatApiError(err);
  }
}

/**
 * 3. Mencari detail pairing berdasarkan kode pairing (untuk approver device).
 * POST /api/bchat/pairing/lookup
 */
export async function lookupPairingByCode(
  pairingCode: string
): Promise<BchatSuccessResponse<BchatPairingLookupData>> {
  try {
    const res = await axios.post<BchatSuccessResponse<BchatPairingLookupData>>(
      '/api/bchat/pairing/lookup',
      { pairing_code: pairingCode.trim() }
    );
    return res.data;
  } catch (err) {
    throw parseBchatApiError(err);
  }
}

/**
 * 4. Alur persetujuan pairing oleh perangkat tepercaya:
 *    - Ambil private key Ed25519 dari Keystore IndexedDB.
 *    - Bangkitkan canonical payload 159-byte.
 *    - Tandatangani dengan Ed25519 private key.
 *    - Kirim bukti persetujuan ke server.
 */
export async function approvePairingFlow(params: {
  userId: number;
  pairing: BchatPairingLookupData;
  approverDeviceUuid: string;
}): Promise<BchatSuccessResponse<BchatPairingApprovalResultData>> {
  const { userId, pairing, approverDeviceUuid } = params;

  // 1. Ambil rekaman private key identitas perangkat approver dari Keystore IndexedDB
  let keystoreRecord = await getActiveDeviceIdentityKey(approverDeviceUuid);
  if (!keystoreRecord) {
    const local = await findActiveLocalDevice();
    if (local && local.deviceUuid === approverDeviceUuid) {
      keystoreRecord = local.record;
    }
  }

  if (!keystoreRecord || !keystoreRecord.crypto_key) {
    throw new BchatDeviceRegistrationError(
      'Kunci identitas privat perangkat tepercaya tidak ditemukan di penyimpanan lokal IndexedDB.',
      'PRIVATE_KEY_NOT_FOUND'
    );
  }

  // 2. Bangkitkan canonical 159-byte binary payload
  const timestamp = Math.floor(Date.now() / 1000);
  const payloadBytes = buildCanonicalApprovalPayload({
    userId,
    pairingUuid: pairing.pairing_uuid,
    approverDeviceUuid,
    targetDeviceUuid: pairing.target_device_uuid,
    challengeNonceHex: pairing.challenge_nonce,
    targetFingerprintHex: pairing.target_public_key_fingerprint,
    timestamp,
  });

  // 3. Tanda tangani biner menggunakan Ed25519 WebCrypto
  const { signatureHex } = await signPairingApprovalPayload(
    keystoreRecord.crypto_key,
    payloadBytes
  );

  const payloadHex = bytesToHex(payloadBytes);

  // 4. Kirim persetujuan kriptografis ke backend
  try {
    const res = await axios.post<BchatSuccessResponse<BchatPairingApprovalResultData>>(
      `/api/bchat/pairing/${encodeURIComponent(pairing.pairing_uuid)}/approve`,
      {
        approver_device_uuid: approverDeviceUuid,
        approval_payload: payloadHex,
        approval_signature: signatureHex,
      }
    );
    return res.data;
  } catch (err) {
    throw parseBchatApiError(err);
  }
}

/**
 * 5. Menolak permintaan pairing.
 * POST /api/bchat/pairing/{pairingUuid}/reject
 */
export async function rejectPairing(
  pairingUuid: string,
  approverDeviceUuid: string,
  reason?: string
): Promise<BchatSuccessResponse<{ pairing_uuid: string; status: 'REJECTED' }>> {
  try {
    const res = await axios.post<
      BchatSuccessResponse<{ pairing_uuid: string; status: 'REJECTED' }>
    >(`/api/bchat/pairing/${encodeURIComponent(pairingUuid)}/reject`, {
      approver_device_uuid: approverDeviceUuid,
      reason,
    });
    return res.data;
  } catch (err) {
    throw parseBchatApiError(err);
  }
}

/**
 * 6. Membatalkan permintaan pairing oleh perangkat target.
 * POST /api/bchat/pairing/{pairingUuid}/cancel
 */
export async function cancelPairing(
  pairingUuid: string,
  targetDeviceUuid: string
): Promise<BchatSuccessResponse<{ pairing_uuid: string; status: 'CANCELED' }>> {
  try {
    const res = await axios.post<
      BchatSuccessResponse<{ pairing_uuid: string; status: 'CANCELED' }>
    >(`/api/bchat/pairing/${encodeURIComponent(pairingUuid)}/cancel`, {
      target_device_uuid: targetDeviceUuid,
    });
    return res.data;
  } catch (err) {
    throw parseBchatApiError(err);
  }
}
