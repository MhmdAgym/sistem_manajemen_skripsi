/**
 * B-CHAT E2EE — Cryptographic History Transfer Primitives (Tahap 2B)
 *
 * Mengatur:
 * 1. Pembangkitan pasangan kunci ephemeral ECDH (P-256) untuk transfer data antarperangkat.
 * 2. Derivasi shared secret dan transfer key (AES-256-GCM 256-bit) via HKDF-SHA256.
 * 3. Enkripsi dan dekripsi snapshot riwayat chat dengan framing AAD terotentikasi.
 * 4. Tanda tangan digital Ed25519 untuk permintaan transfer, snapshot header, dan completion ACK.
 *
 * Prinsip Keamanan:
 * - Ed25519 signing key TIDAK PERNAH digunakan untuk enkripsi (pemisahan peran kriptografi tegas).
 * - Tidak ada konversi kunci ad-hoc antar-kurva (zero Ed25519-to-Curve25519 conversion).
 * - Plaintext, KEK, CEK, GroupState, dan private key TIDAK PERNAH dikirim ke server.
 */

import {
  bytesToCanonicalHex,
  canonicalHexToBytes,
  canonicalUuidToBytes,
} from '../device/bchat-device-registration';

export const HISTORY_TRANSFER_MAGIC_REQUEST = 'UNUHA-BCHAT-TRANSFER-REQ-V1';
export const HISTORY_TRANSFER_MAGIC_SNAPSHOT = 'UNUHA-BCHAT-TRANSFER-SNAPSHOT-V1';
export const HISTORY_TRANSFER_MAGIC_ACK = 'UNUHA-BCHAT-TRANSFER-ACK-V1';

export interface EphemeralTransferKeypair {
  privateKey: CryptoKey;
  publicKey: CryptoKey;
  publicKeyRawBytes: Uint8Array;
  publicKeyHex: string;
}

export interface EncryptedSnapshotResult {
  ciphertextBase64: string;
  ciphertextHashSha256Hex: string;
  ivHex: string;
  authTagHex: string;
  sourceEphemeralPublicKeyHex: string;
}

function getSubtle(): SubtleCrypto {
  if (typeof window !== 'undefined' && window.crypto?.subtle) {
    return window.crypto.subtle;
  }
  if (typeof self !== 'undefined' && self.crypto?.subtle) {
    return self.crypto.subtle;
  }
  throw new Error('WebCrypto subtle tidak tersedia.');
}

/**
 * Membangkitkan pasangan kunci Ephemeral ECDH (P-256) untuk sesi transfer.
 */
export async function generateEphemeralTransferKeypair(): Promise<EphemeralTransferKeypair> {
  const subtle = getSubtle();
  const keypair = await subtle.generateKey(
    { name: 'ECDH', namedCurve: 'P-256' },
    true,
    ['deriveKey', 'deriveBits']
  );

  const rawPubBuf = await subtle.exportKey('raw', keypair.publicKey);
  const publicKeyRawBytes = new Uint8Array(rawPubBuf);
  const publicKeyHex = bytesToCanonicalHex(publicKeyRawBytes);

  return {
    privateKey: keypair.privateKey,
    publicKey: keypair.publicKey,
    publicKeyRawBytes,
    publicKeyHex,
  };
}

/**
 * Mengimpor kunci publik ephemeral ECDH (P-256) dari representasi heksadesimal.
 */
export async function importEphemeralPublicKey(hex: string): Promise<CryptoKey> {
  const subtle = getSubtle();
  const bytes = canonicalHexToBytes(hex);
  return subtle.importKey(
    'raw',
    bytes,
    { name: 'ECDH', namedCurve: 'P-256' },
    true,
    []
  );
}

/**
 * Menurunkan kunci enkripsi transfer AES-256-GCM dari shared secret ECDH via HKDF-SHA256.
 */
export async function deriveTransferAesKey(
  privateKey: CryptoKey,
  peerPublicKey: CryptoKey,
  saltBytes: Uint8Array,
  infoString: string
): Promise<CryptoKey> {
  const subtle = getSubtle();

  // 1. Hitung shared secret ECDH (256 bits)
  const sharedBits = await subtle.deriveBits(
    { name: 'ECDH', public: peerPublicKey },
    privateKey,
    256
  );

  // 2. Import shared bits sebagai HKDF master key
  const hkdfKey = await subtle.importKey(
    'raw',
    sharedBits,
    { name: 'HKDF' },
    false,
    ['deriveKey']
  );

  const infoBytes = new TextEncoder().encode(infoString);

  // 3. Derivasi AES-256-GCM
  return subtle.deriveKey(
    {
      name: 'HKDF',
      hash: 'SHA-256',
      salt: saltBytes,
      info: infoBytes,
    },
    hkdfKey,
    { name: 'AES-GCM', length: 256 },
    false,
    ['encrypt', 'decrypt']
  );
}

/**
 * Membangun buffer biner payload permintaan transfer untuk ditandatangani oleh penerima.
 */
export async function buildTransferRequestBuffer(
  userId: number,
  roomUuid: string,
  recipientDeviceUuid: string,
  sourceDeviceUuid: string,
  nonceHex: string,
  recipientEphemeralPublicKeyHex: string
): Promise<Uint8Array> {
  const magicBytes = new TextEncoder().encode(HISTORY_TRANSFER_MAGIC_REQUEST);
  const userBuf = new ArrayBuffer(8);
  new DataView(userBuf).setBigUint64(0, BigInt(userId), false);
  const userBytes = new Uint8Array(userBuf);

  const roomBytes = canonicalUuidToBytes(roomUuid);
  const recBytes = canonicalUuidToBytes(recipientDeviceUuid);
  const srcBytes = canonicalUuidToBytes(sourceDeviceUuid);
  const nonceBytes = canonicalHexToBytes(nonceHex, 32);

  const subtle = getSubtle();
  const pubKeyBytes = canonicalHexToBytes(recipientEphemeralPublicKeyHex);
  const pubHashBuf = await subtle.digest('SHA-256', pubKeyBytes);
  const pubHashBytes = new Uint8Array(pubHashBuf);

  const totalLen = magicBytes.length + 8 + 16 + 16 + 16 + 32 + 32;
  const out = new Uint8Array(totalLen);
  let offset = 0;

  out.set(magicBytes, offset); offset += magicBytes.length;
  out.set(userBytes, offset); offset += 8;
  out.set(roomBytes, offset); offset += 16;
  out.set(recBytes, offset); offset += 16;
  out.set(srcBytes, offset); offset += 16;
  out.set(nonceBytes, offset); offset += 32;
  out.set(pubHashBytes, offset);

  return out;
}

/**
 * Membangun buffer biner snapshot header untuk ditandatangani oleh sumber.
 */
export async function buildSnapshotHeaderBuffer(
  userId: number,
  transferUuid: string,
  roomUuid: string,
  recipientDeviceUuid: string,
  sourceDeviceUuid: string,
  snapshotHashSha256Hex: string,
  sourceEphemeralPublicKeyHex: string
): Promise<Uint8Array> {
  const magicBytes = new TextEncoder().encode(HISTORY_TRANSFER_MAGIC_SNAPSHOT);
  const userBuf = new ArrayBuffer(8);
  new DataView(userBuf).setBigUint64(0, BigInt(userId), false);
  const userBytes = new Uint8Array(userBuf);

  const transferBytes = canonicalUuidToBytes(transferUuid);
  const roomBytes = canonicalUuidToBytes(roomUuid);
  const recBytes = canonicalUuidToBytes(recipientDeviceUuid);
  const srcBytes = canonicalUuidToBytes(sourceDeviceUuid);
  const hashBytes = canonicalHexToBytes(snapshotHashSha256Hex, 32);

  const subtle = getSubtle();
  const pubKeyBytes = canonicalHexToBytes(sourceEphemeralPublicKeyHex);
  const pubHashBuf = await subtle.digest('SHA-256', pubKeyBytes);
  const pubHashBytes = new Uint8Array(pubHashBuf);

  const totalLen = magicBytes.length + 8 + 16 + 16 + 16 + 16 + 32 + 32;
  const out = new Uint8Array(totalLen);
  let offset = 0;

  out.set(magicBytes, offset); offset += magicBytes.length;
  out.set(userBytes, offset); offset += 8;
  out.set(transferBytes, offset); offset += 16;
  out.set(roomBytes, offset); offset += 16;
  out.set(recBytes, offset); offset += 16;
  out.set(srcBytes, offset); offset += 16;
  out.set(hashBytes, offset); offset += 32;
  out.set(pubHashBytes, offset);

  return out;
}

/**
 * Membangun buffer biner penyelesaian ACK untuk ditandatangani oleh penerima.
 */
export function buildCompletionBuffer(
  userId: number,
  transferUuid: string,
  recipientDeviceUuid: string,
  snapshotHashSha256Hex: string
): Uint8Array {
  const magicBytes = new TextEncoder().encode(HISTORY_TRANSFER_MAGIC_ACK);
  const userBuf = new ArrayBuffer(8);
  new DataView(userBuf).setBigUint64(0, BigInt(userId), false);
  const userBytes = new Uint8Array(userBuf);

  const transferBytes = canonicalUuidToBytes(transferUuid);
  const recBytes = canonicalUuidToBytes(recipientDeviceUuid);
  const hashBytes = canonicalHexToBytes(snapshotHashSha256Hex, 32);

  const totalLen = magicBytes.length + 8 + 16 + 16 + 32;
  const out = new Uint8Array(totalLen);
  let offset = 0;

  out.set(magicBytes, offset); offset += magicBytes.length;
  out.set(userBytes, offset); offset += 8;
  out.set(transferBytes, offset); offset += 16;
  out.set(recBytes, offset); offset += 16;
  out.set(hashBytes, offset);

  return out;
}

/**
 * Menandatangani buffer menggunakan kunci privat Ed25519 perangkat.
 */
export async function signBufferWithDeviceKey(
  devicePrivateKey: CryptoKey,
  buffer: Uint8Array
): Promise<string> {
  const subtle = getSubtle();
  const sigBuf = await subtle.sign('Ed25519', devicePrivateKey, buffer);
  return bytesToCanonicalHex(new Uint8Array(sigBuf));
}

/**
 * Memverifikasi signature Ed25519 menggunakan kunci publik perangkat (hex).
 */
export async function verifyBufferWithDevicePublicKey(
  devicePublicKeyHex: string,
  signatureHex: string,
  buffer: Uint8Array
): Promise<boolean> {
  const subtle = getSubtle();
  const pubKeyBytes = canonicalHexToBytes(devicePublicKeyHex, 32);
  const sigBytes = canonicalHexToBytes(signatureHex, 64);

  const importedPubKey = await subtle.importKey(
    'raw',
    pubKeyBytes,
    { name: 'Ed25519' },
    false,
    ['verify']
  );

  return subtle.verify('Ed25519', importedPubKey, sigBytes, buffer);
}

/**
 * Mengenkripsi snapshot riwayat ke ciphertext AES-256-GCM dengan AAD konteks.
 */
export async function encryptSnapshotPayload(
  snapshotPlaintextJson: string,
  transferAesKey: CryptoKey,
  aadContextString: string
): Promise<{ ciphertextBase64: string; hashSha256Hex: string; ivHex: string }> {
  const subtle = getSubtle();
  const plaintextBytes = new TextEncoder().encode(snapshotPlaintextJson);
  const aadBytes = new TextEncoder().encode(aadContextString);

  // 12-byte acak IV
  const iv = new Uint8Array(12);
  crypto.getRandomValues(iv);

  const encryptedBuf = await subtle.encrypt(
    {
      name: 'AES-GCM',
      iv,
      additionalData: aadBytes,
      tagLength: 128,
    },
    transferAesKey,
    plaintextBytes
  );

  const ciphertextBytes = new Uint8Array(encryptedBuf);
  const hashBuf = await subtle.digest('SHA-256', ciphertextBytes);
  const hashSha256Hex = bytesToCanonicalHex(new Uint8Array(hashBuf));

  // Konversi ciphertext ke Base64
  let binary = '';
  for (let i = 0; i < ciphertextBytes.length; i++) {
    binary += String.fromCharCode(ciphertextBytes[i]);
  }
  const ciphertextBase64 = btoa(binary);

  return {
    ciphertextBase64,
    hashSha256Hex,
    ivHex: bytesToCanonicalHex(iv),
  };
}

/**
 * Mendekripsi snapshot riwayat dari ciphertext AES-256-GCM dengan verifikasi AAD.
 */
export async function decryptSnapshotPayload(
  ciphertextBase64: string,
  ivHex: string,
  transferAesKey: CryptoKey,
  aadContextString: string
): Promise<string> {
  const subtle = getSubtle();
  const iv = canonicalHexToBytes(ivHex, 12);
  const aadBytes = new TextEncoder().encode(aadContextString);

  const binary = atob(ciphertextBase64);
  const ciphertextBytes = new Uint8Array(binary.length);
  for (let i = 0; i < binary.length; i++) {
    ciphertextBytes[i] = binary.charCodeAt(i);
  }

  const decryptedBuf = await subtle.decrypt(
    {
      name: 'AES-GCM',
      iv,
      additionalData: aadBytes,
      tagLength: 128,
    },
    transferAesKey,
    ciphertextBytes
  );

  return new TextDecoder().decode(decryptedBuf);
}

