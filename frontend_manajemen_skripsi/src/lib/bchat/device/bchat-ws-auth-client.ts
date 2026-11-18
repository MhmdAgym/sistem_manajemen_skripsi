/**
 * B-CHAT E2EE: CLIENT AUTENTIKASI KRIPTOGRAFIS WEBSOCKET (REVERB)
 *
 * Mengimplementasikan Signed WebSocket Auth Challenge menggunakan Ed25519:
 * 1. Mengajukan permintaan challenge ke backend (/api/bchat/broadcasting/challenge).
 * 2. Mengambil CryptoKey private key identitas perangkat lokal dari IndexedDB Keystore.
 * 3. Menandatangani canonical payload biner secara detached (Ed25519) tanpa mengekspor private key.
 * 4. Mengirimkan tanda tangan dan UUID challenge ke endpoint /broadcasting/auth Reverb.
 */

import axios from '@/lib/axios';
import {
  getActiveDeviceIdentityKey,
  findActiveLocalDevice,
  BchatDeviceRegistrationError,
} from '@/lib/bchat/device/bchat-device-registration';

export interface BchatWsChallengeData {
  challenge_uuid: string;
  challenge_nonce: string;
  device_uuid: string;
  socket_id: string;
  channel_name: string;
  expires_at: string;
  expires_in_seconds: number;
  payload_hex: string;
}

export interface BchatWsChallengeResponse {
  status: 'success' | 'error';
  message: string;
  data: BchatWsChallengeData;
}

function hexToBytes(hex: string): Uint8Array {
  const clean = hex.trim();
  if (clean.length % 2 !== 0) {
    throw new Error('Hex string invalid (ganjil).');
  }
  const bytes = new Uint8Array(clean.length / 2);
  for (let i = 0; i < clean.length; i += 2) {
    bytes[i / 2] = parseInt(clean.substring(i, i + 2), 16);
  }
  return bytes;
}

function bytesToHex(bytes: Uint8Array): string {
  return Array.from(bytes)
    .map(b => b.toString(16).padStart(2, '0'))
    .join('');
}

/**
 * Meminta tantangan kriptografis dari server untuk otorisasi kanal WebSocket tertentu.
 */
export async function requestWsAuthChallenge(params: {
  deviceUuid: string;
  socketId: string;
  channelName: string;
}): Promise<BchatWsChallengeData> {
  const { deviceUuid, socketId, channelName } = params;

  const res = await axios.post<BchatWsChallengeResponse>(
    '/api/bchat/broadcasting/challenge',
    {
      device_uuid: deviceUuid,
      socket_id: socketId,
      channel_name: channelName,
    },
    {
      headers: {
        'X-Bchat-Device-Uuid': deviceUuid,
      },
    }
  );

  if (res.data.status !== 'success' || !res.data.data) {
    throw new BchatDeviceRegistrationError(
      res.data.message || 'Gagal menerbitkan WebSocket auth challenge.',
      'WS_CHALLENGE_REQUEST_FAILED'
    );
  }

  return res.data.data;
}

/**
 * Menandatangani buffer kanonis tantangan WebSocket menggunakan private key Ed25519 WebCrypto.
 * Kunci privat tidak pernah diekspor dari browser (non-extractable CryptoKey).
 */
export async function signWsAuthPayload(
  privateKey: CryptoKey,
  payloadBytes: Uint8Array
): Promise<string> {
  const signatureBuffer = await window.crypto.subtle.sign(
    'Ed25519',
    privateKey,
    payloadBytes as unknown as BufferSource
  );

  return bytesToHex(new Uint8Array(signatureBuffer));
}

/**
 * Menjalankan alur lengkap otorisasi kriptografis kanal WebSocket Reverb:
 * 1. Ambil private key perangkat lokal.
 * 2. Minta challenge ke server.
 * 3. Tanda tangani canonical payload.
 * 4. Panggil /broadcasting/auth dengan header X-Bchat-Signature & X-Bchat-Challenge-Uuid.
 */
export async function authorizeBchatWsChannel(
  socketId: string,
  channelName: string,
  deviceUuid: string
): Promise<any> {
  const cleanDeviceUuid = deviceUuid.toLowerCase().trim();

  // 1. Ambil identity key dari Keystore IndexedDB
  let keystoreRecord = await getActiveDeviceIdentityKey(cleanDeviceUuid);
  if (!keystoreRecord) {
    const local = await findActiveLocalDevice();
    if (local && local.deviceUuid === cleanDeviceUuid) {
      keystoreRecord = local.record;
    }
  }

  if (!keystoreRecord || !keystoreRecord.crypto_key) {
    throw new BchatDeviceRegistrationError(
      'Kunci privat identitas perangkat tidak ditemukan di IndexedDB lokal untuk otorisasi WebSocket.',
      'DEVICE_PRIVATE_KEY_MISSING'
    );
  }

  // 2. Minta challenge dari server
  const challenge = await requestWsAuthChallenge({
    deviceUuid: cleanDeviceUuid,
    socketId,
    channelName,
  });

  // 3. Tanda tangani payload kanonis
  const payloadBytes = hexToBytes(challenge.payload_hex);
  const signatureHex = await signWsAuthPayload(keystoreRecord.crypto_key, payloadBytes);

  // 4. Kirim permintaan otorisasi broadcasting Reverb
  const authResponse = await axios.post(
    '/broadcasting/auth',
    {
      socket_id: socketId,
      channel_name: channelName,
    },
    {
      headers: {
        'X-Bchat-Device-Uuid': cleanDeviceUuid,
        'X-Bchat-Challenge-Uuid': challenge.challenge_uuid,
        'X-Bchat-Signature': signatureHex,
      },
    }
  );

  return authResponse.data;
}

