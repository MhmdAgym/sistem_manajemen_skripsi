/**
 * B-CHAT E2EE Protocol Layer — Welcome ACK Builder (I-03)
 *
 * Mengimplementasikan pembentukan dan penandatanganan Canonical Welcome ACK Payload 158-byte
 * bit-demi-bit sesuai spesifikasi B-CHAT-13D, B-CHAT-13 Subbab 4.2, dan kontrak verifikasi backend:
 *
 * Struktur Byte Welcome ACK Kanonis (158 byte):
 * 1. Domain Separator: ASCII 'UNUHA-BCHAT-WELCOME-ACK-V1' (26 byte, offset 0..26)
 * 2. Room UUID: 16 byte biner (offset 26..42)
 * 3. Recipient User ID: 8 byte uint64 Big-Endian (offset 42..50)
 * 4. Welcome Operation UUID: 16 byte biner (offset 50..66)
 * 5. Challenge UUID: 16 byte biner (offset 66..82)
 * 6. Challenge Generation: 4 byte uint32 Big-Endian (offset 82..86)
 * 7. Group ID Hash: 32 byte biner SHA-256 (offset 86..118)
 * 8. Welcome Target Epoch: 8 byte uint64 Big-Endian (offset 118..126, wajib tepat 1)
 * 9. Challenge Nonce: 32 byte biner (offset 126..158)
 *
 * Total: 26 + 16 + 8 + 16 + 16 + 4 + 32 + 8 + 32 = 158 byte.
 *
 * Invarian Kriptografis:
 * - Penandatanganan HANYA menggunakan Ed25519 Identity Key perangkat penerima (dosen) yang tersimpan di Keystore.
 * - DILARANG KERAS menggunakan MLS credential signing key atau HPKE key sebagai Identity Key.
 * - Detached signature wajib tepat 64 byte biner, diekspor sebagai 88 karakter Base64 kanonikal (RFC 4648).
 * - Pemrosesan uint64 Big-Endian wajib menggunakan BigInt murni (mencegah kehilangan presisi IEEE 754 float).
 * - Zero Math.random() dan zero sensitive logging.
 */

import { getActiveDeviceIdentityKey } from '@/lib/bchat/device/bchat-device-registration';

// -----------------------------------------------------------------------------
// KONSTANTA SPESIFIKASI WELCOME ACK KANONIS (158 BYTE)
// -----------------------------------------------------------------------------

export const WELCOME_ACK_DOMAIN_SEPARATOR = 'UNUHA-BCHAT-WELCOME-ACK-V1';
export const WELCOME_ACK_DOMAIN_SEPARATOR_BYTES = 26;
export const WELCOME_ACK_PAYLOAD_LENGTH = 158;
export const WELCOME_ACK_SIGNATURE_LENGTH = 64;
export const WELCOME_ACK_SIGNATURE_BASE64_LENGTH = 88;
export const WELCOME_ACK_PUBLIC_KEY_LENGTH = 32;
export const WELCOME_ACK_TARGET_EPOCH = BigInt(1);

export const CANONICAL_UUID_REGEX = /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/;
export const SHA256_HEX_REGEX = /^[0-9a-f]{64}$/;
export const DECIMAL_STRING_REGEX = /^(0|[1-9][0-9]*)$/;

const MAX_UINT64 = BigInt('18446744073709551615');
const MAX_UINT32 = 4294967295;

// -----------------------------------------------------------------------------
// KELAS ERROR DOMAIN WELCOME ACK
// -----------------------------------------------------------------------------

export class BchatWelcomeAckBuilderError extends Error {
  readonly isBchatWelcomeAckBuilderError = true;
  readonly errorCode: string;
  readonly originalError?: unknown;

  constructor(message: string, errorCode: string, originalError?: unknown) {
    super(message);
    this.name = 'BchatWelcomeAckBuilderError';
    this.errorCode = errorCode;
    this.originalError = originalError;
    Object.setPrototypeOf(this, BchatWelcomeAckBuilderError.prototype);
  }
}

// -----------------------------------------------------------------------------
// STRUKTUR DATA PARAMETER & HASIL
// -----------------------------------------------------------------------------

export interface WelcomeAckBuilderParams {
  roomUuid: string;
  recipientUserId: string | number | bigint;
  welcomeOperationUuid: string;
  challengeUuid: string;
  challengeGeneration: number | string;
  groupIdHash: string | Uint8Array;
  epoch: number | string | bigint;
  challengeNonce: string | Uint8Array;
}

export interface BuiltWelcomeAck {
  payloadBytes: Uint8Array;
  payloadLength: 158;
  payloadHex: string;
}

export interface SignedWelcomeAck {
  payload: BuiltWelcomeAck;
  signatureBytes: Uint8Array;
  signatureBase64: string; // 88 karakter Base64 kanonikal (RFC 4648)
}

// -----------------------------------------------------------------------------
// UTILITAS BINER, FORMAT, & VALIDASI
// -----------------------------------------------------------------------------

export function toArrayBuffer(bytes: Uint8Array): ArrayBuffer {
  const copy = new Uint8Array(bytes.byteLength);
  copy.set(bytes);
  return copy.buffer;
}

export function bytesToHex(bytes: Uint8Array): string {
  let hex = '';
  for (let i = 0; i < bytes.length; i++) {
    hex += bytes[i].toString(16).padStart(2, '0');
  }
  return hex;
}

export function hexToBytes(hex: string): Uint8Array {
  const cleanHex = hex.trim().toLowerCase();
  if (cleanHex.length % 2 !== 0) {
    throw new BchatWelcomeAckBuilderError(
      'Panjang string heksadesimal tidak genap.',
      'INVALID_HEX_LENGTH'
    );
  }
  const bytes = new Uint8Array(cleanHex.length / 2);
  for (let i = 0; i < cleanHex.length; i += 2) {
    const byteVal = parseInt(cleanHex.substring(i, i + 2), 16);
    if (isNaN(byteVal)) {
      throw new BchatWelcomeAckBuilderError(
        `Karakter heksadesimal tidak valid pada offset ${i}.`,
        'INVALID_HEX_CHAR'
      );
    }
    bytes[i / 2] = byteVal;
  }
  return bytes;
}

export function bytesToBase64(bytes: Uint8Array): string {
  if (typeof Buffer !== 'undefined') {
    return Buffer.from(bytes).toString('base64');
  }
  let binary = '';
  const len = bytes.byteLength;
  for (let i = 0; i < len; i++) {
    binary += String.fromCharCode(bytes[i]);
  }
  return btoa(binary);
}

export function base64ToBytes(base64: string): Uint8Array {
  if (typeof Buffer !== 'undefined') {
    return new Uint8Array(Buffer.from(base64, 'base64'));
  }
  const binary = atob(base64);
  const bytes = new Uint8Array(binary.length);
  for (let i = 0; i < binary.length; i++) {
    bytes[i] = binary.charCodeAt(i);
  }
  return bytes;
}

export function parseCanonicalUuidToBinary(uuid: string, fieldName: string): Uint8Array {
  if (typeof uuid !== 'string') {
    throw new BchatWelcomeAckBuilderError(
      `Parameter ${fieldName} wajib berupa string UUID.`,
      'INVALID_UUID_TYPE'
    );
  }
  const cleanUuid = uuid.trim().toLowerCase();
  if (!CANONICAL_UUID_REGEX.test(cleanUuid)) {
    throw new BchatWelcomeAckBuilderError(
      `Format ${fieldName} [${uuid}] tidak valid. Wajib berupa UUID v4 kanonis lowercase 36 karakter.`,
      'INVALID_UUID_FORMAT'
    );
  }
  const stripped = cleanUuid.replace(/-/g, '');
  return hexToBytes(stripped);
}

export function parseUnsigned64Int(value: string | number | bigint, fieldName: string): bigint {
  let biVal: bigint;
  if (typeof value === 'bigint') {
    biVal = value;
  } else if (typeof value === 'number') {
    if (!Number.isInteger(value)) {
      throw new BchatWelcomeAckBuilderError(
        `Parameter ${fieldName} [${value}] bukan integer yang valid.`,
        'INVALID_UINT64_VALUE'
      );
    }
    if (value < 0) {
      throw new BchatWelcomeAckBuilderError(
        `Parameter ${fieldName} [${value}] tidak boleh bernilai negatif.`,
        'NEGATIVE_UINT64_VALUE'
      );
    }
    biVal = BigInt(value);
  } else if (typeof value === 'string') {
    const cleanStr = value.trim();
    if (!DECIMAL_STRING_REGEX.test(cleanStr)) {
      throw new BchatWelcomeAckBuilderError(
        `Parameter ${fieldName} [${value}] bukan representasi desimal integer uint64 yang valid.`,
        'INVALID_UINT64_FORMAT'
      );
    }
    try {
      biVal = BigInt(cleanStr);
    } catch (err) {
      throw new BchatWelcomeAckBuilderError(
        `Gagal mem-parse parameter ${fieldName} [${value}] sebagai BigInt.`,
        'PARSE_UINT64_FAILED',
        err
      );
    }
  } else {
    throw new BchatWelcomeAckBuilderError(
      `Tipe data parameter ${fieldName} tidak didukung (wajib string | number | bigint).`,
      'INVALID_UINT64_TYPE'
    );
  }

  if (biVal < BigInt(0) || biVal > MAX_UINT64) {
    throw new BchatWelcomeAckBuilderError(
      `Nilai parameter ${fieldName} [${biVal.toString()}] berada di luar rentang uint64 (0 s.d. 18446744073709551615).`,
      'UINT64_OUT_OF_RANGE'
    );
  }
  return biVal;
}

export function parseUnsigned32Int(value: number | string, fieldName: string): number {
  let numVal: number;
  if (typeof value === 'number') {
    numVal = value;
  } else if (typeof value === 'string') {
    const cleanStr = value.trim();
    if (!DECIMAL_STRING_REGEX.test(cleanStr)) {
      throw new BchatWelcomeAckBuilderError(
        `Parameter ${fieldName} [${value}] bukan integer desimal uint32 yang valid.`,
        'INVALID_UINT32_FORMAT'
      );
    }
    numVal = parseInt(cleanStr, 10);
  } else {
    throw new BchatWelcomeAckBuilderError(
      `Tipe data parameter ${fieldName} tidak didukung (wajib number | string).`,
      'INVALID_UINT32_TYPE'
    );
  }

  if (!Number.isInteger(numVal) || numVal < 1 || numVal > MAX_UINT32) {
    throw new BchatWelcomeAckBuilderError(
      `Nilai parameter ${fieldName} [${numVal}] di luar batas uint32 valid (wajib 1 s.d. 4294967295).`,
      'UINT32_OUT_OF_RANGE'
    );
  }
  return numVal;
}

export function parseBinary32(value: string | Uint8Array, fieldName: string): Uint8Array {
  if (value instanceof Uint8Array) {
    if (value.byteLength !== 32) {
      throw new BchatWelcomeAckBuilderError(
        `Buffer ${fieldName} memiliki panjang ${value.byteLength} byte (wajib tepat 32 byte).`,
        'INVALID_BINARY_LENGTH'
      );
    }
    return value;
  }
  if (typeof value === 'string') {
    const cleanHex = value.trim().toLowerCase();
    if (!SHA256_HEX_REGEX.test(cleanHex)) {
      throw new BchatWelcomeAckBuilderError(
        `Parameter heksadesimal ${fieldName} [${value}] tidak valid (wajib 64 karakter lowercase hex).`,
        'INVALID_HEX_FORMAT'
      );
    }
    return hexToBytes(cleanHex);
  }
  throw new BchatWelcomeAckBuilderError(
    `Tipe data parameter ${fieldName} tidak didukung (wajib string hex 64 karakter atau Uint8Array 32 byte).`,
    'INVALID_BINARY_TYPE'
  );
}

function getCryptoSubtle(): SubtleCrypto {
  const cryptoObj =
    typeof globalThis !== 'undefined' && globalThis.crypto
      ? globalThis.crypto
      : typeof window !== 'undefined' && window.crypto
      ? window.crypto
      : undefined;

  if (!cryptoObj || !cryptoObj.subtle) {
    throw new BchatWelcomeAckBuilderError(
      'Web Crypto API (crypto.subtle) tidak tersedia pada runtime saat ini.',
      'WEB_CRYPTO_UNAVAILABLE'
    );
  }
  return cryptoObj.subtle;
}

// -----------------------------------------------------------------------------
// PERAKITAN CANONICAL WELCOME ACK PAYLOAD (158 BYTE)
// -----------------------------------------------------------------------------

/**
 * Membangun buffer biner Canonical Welcome ACK 158-byte deterministik.
 *
 * Validasi dan pengurutan bidang bit-demi-bit tanpa celah padding:
 * - Domain Separator (26 byte)
 * - Room UUID (16 byte)
 * - Recipient User ID (8 byte Big-Endian)
 * - Welcome Operation UUID (16 byte)
 * - Challenge UUID (16 byte)
 * - Challenge Generation (4 byte Big-Endian)
 * - Group ID Hash (32 byte)
 * - Welcome Target Epoch (8 byte Big-Endian, wajib tepat 1)
 * - Challenge Nonce (32 byte)
 */
export function buildWelcomeAckPayload(params: WelcomeAckBuilderParams): BuiltWelcomeAck {
  if (!params || typeof params !== 'object') {
    throw new BchatWelcomeAckBuilderError(
      'Parameter perakitan Welcome ACK wajib berupa objek valid.',
      'INVALID_BUILDER_PARAMS'
    );
  }

  // 1. Domain Separator 26 byte ASCII
  const domainBytes = new TextEncoder().encode(WELCOME_ACK_DOMAIN_SEPARATOR);
  if (domainBytes.byteLength !== WELCOME_ACK_DOMAIN_SEPARATOR_BYTES) {
    throw new BchatWelcomeAckBuilderError(
      `Panjang domain separator [${domainBytes.byteLength}] tidak sesuai (wajib 26 byte).`,
      'INVALID_DOMAIN_SEPARATOR'
    );
  }

  // 2. Room UUID 16 byte biner
  const roomUuidBytes = parseCanonicalUuidToBinary(params.roomUuid, 'roomUuid');

  // 3. Recipient User ID 8 byte (uint64 Big-Endian)
  const recipientUserIdBi = parseUnsigned64Int(params.recipientUserId, 'recipientUserId');

  // 4. Welcome Operation UUID 16 byte biner
  const welcomeOpUuidBytes = parseCanonicalUuidToBinary(params.welcomeOperationUuid, 'welcomeOperationUuid');

  // 5. Challenge UUID 16 byte biner
  const challengeUuidBytes = parseCanonicalUuidToBinary(params.challengeUuid, 'challengeUuid');

  // 6. Challenge Generation 4 byte (uint32 Big-Endian >= 1)
  const generationNum = parseUnsigned32Int(params.challengeGeneration, 'challengeGeneration');

  // 7. Group ID Hash 32 byte biner
  const groupIdHashBytes = parseBinary32(params.groupIdHash, 'groupIdHash');

  // 8. Welcome Target Epoch 8 byte (uint64 Big-Endian, wajib tepat 1)
  const epochBi = parseUnsigned64Int(params.epoch, 'epoch');
  if (epochBi !== WELCOME_ACK_TARGET_EPOCH) {
    throw new BchatWelcomeAckBuilderError(
      `Epoch target Welcome ACK [${epochBi.toString()}] tidak valid (hanya menerima tepat epoch 1).`,
      'INVALID_TARGET_EPOCH'
    );
  }

  // 9. Challenge Nonce 32 byte biner
  const nonceBytes = parseBinary32(params.challengeNonce, 'challengeNonce');

  // Alokasi buffer tepat 158 byte
  const buffer = new ArrayBuffer(WELCOME_ACK_PAYLOAD_LENGTH);
  const view = new DataView(buffer);
  const payloadArray = new Uint8Array(buffer);

  let offset = 0;

  // 1. Domain Separator (offset 0..26)
  payloadArray.set(domainBytes, offset);
  offset += 26;

  // 2. Room UUID (offset 26..42)
  payloadArray.set(roomUuidBytes, offset);
  offset += 16;

  // 3. Recipient User ID (offset 42..50, Big-Endian)
  view.setBigUint64(offset, recipientUserIdBi, false);
  offset += 8;

  // 4. Welcome Operation UUID (offset 50..66)
  payloadArray.set(welcomeOpUuidBytes, offset);
  offset += 16;

  // 5. Challenge UUID (offset 66..82)
  payloadArray.set(challengeUuidBytes, offset);
  offset += 16;

  // 6. Challenge Generation (offset 82..86, Big-Endian)
  view.setUint32(offset, generationNum, false);
  offset += 4;

  // 7. Group ID Hash (offset 86..118)
  payloadArray.set(groupIdHashBytes, offset);
  offset += 32;

  // 8. Welcome Target Epoch (offset 118..126, Big-Endian)
  view.setBigUint64(offset, epochBi, false);
  offset += 8;

  // 9. Challenge Nonce (offset 126..158)
  payloadArray.set(nonceBytes, offset);
  offset += 32;

  if (offset !== WELCOME_ACK_PAYLOAD_LENGTH) {
    throw new BchatWelcomeAckBuilderError(
      `Kegagalan internal perakitan payload Welcome ACK: total buffer [${offset}] bukan 158 byte.`,
      'PAYLOAD_LENGTH_MISMATCH'
    );
  }

  return {
    payloadBytes: payloadArray,
    payloadLength: WELCOME_ACK_PAYLOAD_LENGTH,
    payloadHex: bytesToHex(payloadArray),
  };
}

// -----------------------------------------------------------------------------
// PENANDATANGANAN WELCOME ACK (Ed25519)
// -----------------------------------------------------------------------------

/**
 * Menandatangani buffer biner Canonical Welcome ACK 158-byte menggunakan CryptoKey Ed25519.
 *
 * Menghasilkan signature detached tepat 64 byte biner dan 88 karakter Base64 kanonikal (RFC 4648).
 */
export async function signWelcomeAckPayload(
  payloadBytes: Uint8Array,
  privateKey: CryptoKey
): Promise<SignedWelcomeAck> {
  if (!(payloadBytes instanceof Uint8Array) || payloadBytes.byteLength !== WELCOME_ACK_PAYLOAD_LENGTH) {
    throw new BchatWelcomeAckBuilderError(
      `Panjang buffer payload Welcome ACK [${payloadBytes?.byteLength}] tidak valid (wajib tepat ${WELCOME_ACK_PAYLOAD_LENGTH} byte).`,
      'INVALID_PAYLOAD_LENGTH'
    );
  }

  if (!privateKey || typeof privateKey !== 'object') {
    throw new BchatWelcomeAckBuilderError(
      'Kunci privat penandatanganan tidak valid atau belum diinisialisasi.',
      'INVALID_PRIVATE_KEY'
    );
  }

  if (privateKey.algorithm.name !== 'Ed25519' || privateKey.type !== 'private') {
    throw new BchatWelcomeAckBuilderError(
      `Algoritma kunci [${privateKey.algorithm.name}] atau tipe [${privateKey.type}] tidak valid (wajib Ed25519 private key).`,
      'INVALID_KEY_ALGORITHM'
    );
  }

  const subtle = getCryptoSubtle();
  let sigBuf: ArrayBuffer;
  try {
    sigBuf = await subtle.sign('Ed25519', privateKey, toArrayBuffer(payloadBytes));
  } catch (signErr) {
    throw new BchatWelcomeAckBuilderError(
      `Gagal menandatangani payload Welcome ACK dengan Web Crypto Ed25519: ${(signErr as Error)?.message || 'Unknown signing error'}`,
      'SIGNING_FAILED',
      signErr
    );
  }

  const signatureBytes = new Uint8Array(sigBuf);
  if (signatureBytes.byteLength !== WELCOME_ACK_SIGNATURE_LENGTH) {
    throw new BchatWelcomeAckBuilderError(
      `Panjang signature Ed25519 [${signatureBytes.byteLength}] tidak valid (wajib tepat ${WELCOME_ACK_SIGNATURE_LENGTH} byte).`,
      'INVALID_SIGNATURE_LENGTH'
    );
  }

  const signatureBase64 = bytesToBase64(signatureBytes);
  if (signatureBase64.length !== WELCOME_ACK_SIGNATURE_BASE64_LENGTH) {
    throw new BchatWelcomeAckBuilderError(
      `Panjang format Base64 signature [${signatureBase64.length}] tidak valid (wajib tepat ${WELCOME_ACK_SIGNATURE_BASE64_LENGTH} karakter).`,
      'INVALID_BASE64_SIGNATURE_LENGTH'
    );
  }

  return {
    payload: {
      payloadBytes,
      payloadLength: WELCOME_ACK_PAYLOAD_LENGTH,
      payloadHex: bytesToHex(payloadBytes),
    },
    signatureBytes,
    signatureBase64,
  };
}

/**
 * Mengambil Identity Key Ed25519 aktif perangkat dari Keystore dan menandatangani payload Welcome ACK.
 *
 * Menjamin hanya Identity Key resmi perangkat yang digunakan (bukan MLS credential/HPKE key).
 */
export async function signWelcomeAckWithDeviceIdentity(
  payloadBytes: Uint8Array,
  deviceUuid: string
): Promise<SignedWelcomeAck> {
  if (typeof deviceUuid !== 'string' || !CANONICAL_UUID_REGEX.test(deviceUuid.trim().toLowerCase())) {
    throw new BchatWelcomeAckBuilderError(
      `Device UUID [${deviceUuid}] tidak valid. Wajib berupa UUID v4 kanonis lowercase.`,
      'INVALID_DEVICE_UUID'
    );
  }

  const canonicalDeviceUuid = deviceUuid.trim().toLowerCase();
  const identityRecord = await getActiveDeviceIdentityKey(canonicalDeviceUuid);

  if (!identityRecord) {
    throw new BchatWelcomeAckBuilderError(
      `Kunci identitas aktif untuk perangkat [${canonicalDeviceUuid}] tidak ditemukan di Keystore.`,
      'IDENTITY_KEY_NOT_FOUND'
    );
  }

  if (identityRecord.status !== 'active') {
    throw new BchatWelcomeAckBuilderError(
      `Kunci identitas perangkat [${canonicalDeviceUuid}] berstatus [${identityRecord.status}] (wajib active).`,
      'IDENTITY_KEY_INACTIVE'
    );
  }

  if (!identityRecord.crypto_key) {
    throw new BchatWelcomeAckBuilderError(
      `CryptoKey native tidak tersedia pada record Keystore perangkat [${canonicalDeviceUuid}].`,
      'CRYPTO_KEY_MISSING'
    );
  }

  return signWelcomeAckPayload(payloadBytes, identityRecord.crypto_key);
}

/**
 * Menghitung hash SHA-256 dari buffer payload Welcome ACK kanonis.
 */
export async function computePayloadSha256Hex(payloadBytes: Uint8Array): Promise<string> {
  const subtle = getCryptoSubtle();
  const digest = await subtle.digest('SHA-256', toArrayBuffer(payloadBytes));
  return bytesToHex(new Uint8Array(digest));
}
