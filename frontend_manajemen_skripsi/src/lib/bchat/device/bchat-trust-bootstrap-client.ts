/**
 * B-CHAT E2EE — Trust Bootstrap Client (Tahap 2B)
 *
 * Mengoordinasikan alur bootstrap kepercayaan untuk perangkat aktif pertama:
 * - Pemeriksaan kelayakan (checkEligibility).
 * - Step-up authentication & penerbitan challenge sekali pakai (issueChallenge).
 * - Penandatanganan payload kanonis menggunakan private key Ed25519 dari IndexedDB (Keystore).
 * - Verifikasi tanda tangan pada server & penetapan status tepercaya perdana (verify).
 */

import axios from '@/lib/axios';
import {
  parseBchatApiError,
  BchatApiError,
} from '@/lib/bchat/api/bchat-api-client';
import {
  getActiveDeviceIdentityKey,
  findActiveLocalDevice,
} from '@/lib/bchat/device/bchat-device-registration';

export interface BchatTrustBootstrapEligibilityData {
  device_uuid: string;
  is_eligible: boolean;
  reason: string | null;
  error_code: string | null;
}

export interface BchatTrustBootstrapChallengeData {
  challenge_uuid: string;
  device_uuid: string;
  purpose: string;
  challenge_nonce: string;
  payload_to_sign: string;
  expires_at: string;
}

export interface BchatTrustBootstrapVerifyData {
  device_id: number;
  device_uuid: string;
  is_trusted: boolean;
  trusted_at: string;
  is_root_of_trust: boolean;
}

function bytesToHex(bytes: Uint8Array): string {
  return Array.from(bytes)
    .map((b) => b.toString(16).padStart(2, '0'))
    .join('');
}

/**
 * 1. Memeriksa kelayakan trust bootstrap untuk perangkat tertentu.
 */
export async function checkTrustBootstrapEligibility(
  deviceUuid: string
): Promise<BchatTrustBootstrapEligibilityData> {
  const cleanDeviceUuid = deviceUuid.toLowerCase().trim();
  try {
    const res = await axios.get<{
      status: string;
      data: BchatTrustBootstrapEligibilityData;
    }>(`/api/bchat/devices/${cleanDeviceUuid}/trust-bootstrap/eligibility`);
    return res.data.data;
  } catch (err) {
    throw parseBchatApiError(err);
  }
}

/**
 * 2. Menerbitkan challenge bootstrap sekali pakai dengan menyertakan kata sandi akun (step-up auth).
 */
export async function requestTrustBootstrapChallenge(
  deviceUuid: string,
  password: string
): Promise<BchatTrustBootstrapChallengeData> {
  const cleanDeviceUuid = deviceUuid.toLowerCase().trim();
  try {
    const res = await axios.post<{
      status: string;
      message: string;
      data: BchatTrustBootstrapChallengeData;
    }>(`/api/bchat/devices/${cleanDeviceUuid}/trust-bootstrap/challenge`, {
      password,
    });
    return res.data.data;
  } catch (err) {
    throw parseBchatApiError(err);
  }
}

/**
 * 3. Menandatangani payload kanonis UTF-8 menggunakan CryptoKey private key Ed25519 WebCrypto.
 */
export async function signTrustBootstrapPayload(
  privateKey: CryptoKey,
  payloadToSign: string
): Promise<string> {
  const encoder = new TextEncoder();
  const payloadBytes = encoder.encode(payloadToSign);
  const signatureBuffer = await window.crypto.subtle.sign(
    'Ed25519',
    privateKey,
    payloadBytes as unknown as BufferSource
  );
  return bytesToHex(new Uint8Array(signatureBuffer));
}

/**
 * 4. Mengirimkan challenge UUID dan signature Ed25519 untuk diverifikasi di backend.
 */
export async function submitTrustBootstrapVerification(
  deviceUuid: string,
  challengeUuid: string,
  signature: string
): Promise<BchatTrustBootstrapVerifyData> {
  const cleanDeviceUuid = deviceUuid.toLowerCase().trim();
  try {
    const res = await axios.post<{
      status: string;
      message: string;
      data: BchatTrustBootstrapVerifyData;
    }>(`/api/bchat/devices/${cleanDeviceUuid}/trust-bootstrap/verify`, {
      challenge_uuid: challengeUuid,
      signature,
    });
    return res.data.data;
  } catch (err) {
    throw parseBchatApiError(err);
  }
}

/**
 * 5. Eksekusi alur lengkap trust bootstrap:
 *    - Ambil private key lokal dari IndexedDB
 *    - Minta challenge dengan password
 *    - Tandatangani payload
 *    - Kirim bukti verifikasi ke server
 */
export async function executeTrustBootstrapFlow(
  deviceUuid: string,
  password: string
): Promise<BchatTrustBootstrapVerifyData> {
  const cleanDeviceUuid = deviceUuid.toLowerCase().trim();

  // Ambil private key lokal
  let keystoreRecord = await getActiveDeviceIdentityKey(cleanDeviceUuid);
  if (!keystoreRecord) {
    const local = await findActiveLocalDevice();
    if (local && local.deviceUuid === cleanDeviceUuid) {
      keystoreRecord = local.record;
    }
  }

  if (!keystoreRecord || !keystoreRecord.crypto_key) {
    throw new BchatApiError(
      'Kunci identitas privat perangkat tidak ditemukan pada IndexedDB lokal browser ini.',
      400,
      'DEVICE_PRIVATE_KEY_MISSING'
    );
  }

  // 1. Minta challenge dari server
  const challenge = await requestTrustBootstrapChallenge(cleanDeviceUuid, password);

  // 2. Tandatangani payload kanonis
  const signature = await signTrustBootstrapPayload(
    keystoreRecord.crypto_key,
    challenge.payload_to_sign
  );

  // 3. Verifikasi tanda tangan di backend
  return await submitTrustBootstrapVerification(
    cleanDeviceUuid,
    challenge.challenge_uuid,
    signature
  );
}

