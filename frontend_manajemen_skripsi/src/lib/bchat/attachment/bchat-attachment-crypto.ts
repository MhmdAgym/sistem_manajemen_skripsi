/**
 * B-CHAT E2EE — Attachment Cryptography Engine: AES-256-GCM, Nonce Management & Framing Format A
 * Sesuai B-CHAT-08A.5, B-CHAT-08C.3, B-CHAT-08D.2
 *
 * Framing Format A:
 * Nonce (12 B) || Ciphertext (Li B) || AuthTag (16 B) = Total (Li + 28 B)
 */

import {
  ATTACHMENT_CANONICAL,
  NonceReuseViolation,
  AttachmentAuthenticationError,
} from './bchat-attachment-constants';

// In-memory Nonce Ledger untuk mendeteksi dan mencegah penggunaan ulang Nonce dengan CEK yang sama
const nonceLedger = new Set<string>();

/**
 * Reset ledger pencegahan nonce reuse (digunakan saat isolasi pengujian independen).
 */
export function resetNonceLedger(): void {
  nonceLedger.clear();
}

/**
 * Pembangkitan UploadSessionSalt 64-bit (8 byte) acak via browser CSPRNG.
 */
export function generateSessionSalt(csprngMock?: (buf: Uint8Array) => void): Uint8Array {
  const salt = new Uint8Array(ATTACHMENT_CANONICAL.SALT_LENGTH_BYTES);
  if (typeof csprngMock === 'function') {
    csprngMock(salt);
  } else {
    globalThis.crypto.getRandomValues(salt);
  }
  return salt;
}

/**
 * Pembentukan Nonce 96-bit (12 byte):
 * UploadSessionSalt (8 B) || uint32be(chunk_index) (4 B)
 */
export function constructNonce(uploadSessionSalt: Uint8Array, chunkIndex: number): Uint8Array {
  if (
    !(uploadSessionSalt instanceof Uint8Array) ||
    uploadSessionSalt.length !== ATTACHMENT_CANONICAL.SALT_LENGTH_BYTES
  ) {
    throw new Error(
      `UploadSessionSalt wajib Uint8Array 8 byte, diterima: ${uploadSessionSalt ? uploadSessionSalt.length : 0}`
    );
  }
  if (typeof chunkIndex !== 'number' || chunkIndex < 0 || chunkIndex > 0xffffffff) {
    throw new Error(`chunkIndex (${chunkIndex}) di luar rentang uint32 yang sah [0..4294967295]`);
  }

  const nonce = new Uint8Array(ATTACHMENT_CANONICAL.NONCE_LENGTH_BYTES);
  nonce.set(uploadSessionSalt, 0);

  const view = new DataView(nonce.buffer, nonce.byteOffset, nonce.byteLength);
  view.setUint32(ATTACHMENT_CANONICAL.SALT_LENGTH_BYTES, chunkIndex, false); // Big-Endian 4 byte

  return nonce;
}

/**
 * Impor raw 32-byte CEK material ke W3C CryptoKey AES-GCM.
 */
export async function importCek(rawBytes: Uint8Array): Promise<CryptoKey> {
  if (!(rawBytes instanceof Uint8Array) || rawBytes.length !== ATTACHMENT_CANONICAL.CEK_LENGTH_BYTES) {
    throw new Error(
      `Panjang CEK wajib 32 byte (256-bit), diterima: ${rawBytes ? rawBytes.length : 0}`
    );
  }
  return await globalThis.crypto.subtle.importKey(
    'raw',
    rawBytes,
    { name: 'AES-GCM' },
    false,
    ['encrypt', 'decrypt']
  );
}

/**
 * Pembangkitan CEK 256-bit acak lokal via CSPRNG.
 */
export async function generateCek(
  csprngMock?: (buf: Uint8Array) => void
): Promise<{ rawBytes: Uint8Array; cryptoKey: CryptoKey }> {
  const rawBytes = new Uint8Array(ATTACHMENT_CANONICAL.CEK_LENGTH_BYTES);
  if (typeof csprngMock === 'function') {
    csprngMock(rawBytes);
  } else {
    globalThis.crypto.getRandomValues(rawBytes);
  }

  const cryptoKey = await globalThis.crypto.subtle.importKey(
    'raw',
    rawBytes,
    { name: 'AES-GCM' },
    true,
    ['encrypt', 'decrypt']
  );

  return { rawBytes, cryptoKey };
}

/**
 * Enkripsi chunk plaintext menggunakan AES-256-GCM dan dibungkus dalam Framing Format A:
 * Format A: Nonce (12 B) || Ciphertext (Li B) || AuthTag (16 B) = Total (Li + 28 B).
 */
export async function encryptChunk(
  cryptoKey: CryptoKey,
  nonce: Uint8Array,
  plaintext: Uint8Array,
  aad: Uint8Array,
  sessionKeyFingerprint = 'default'
): Promise<Uint8Array> {
  if (!(nonce instanceof Uint8Array) || nonce.length !== ATTACHMENT_CANONICAL.NONCE_LENGTH_BYTES) {
    throw new Error(`Nonce harus Uint8Array 12 byte, diterima: ${nonce ? nonce.length : 0}`);
  }
  if (!(aad instanceof Uint8Array) || aad.length !== ATTACHMENT_CANONICAL.AAD_LENGTH_BYTES) {
    throw new Error(`AAD harus Uint8Array 83 byte, diterima: ${aad ? aad.length : 0}`);
  }
  if (!(plaintext instanceof Uint8Array) || plaintext.length === 0) {
    throw new Error('Plaintext chunk tidak boleh kosong (0 byte ditolak mutlak).');
  }

  // Cek Ledger Pencegahan Nonce Reuse (Fail-Closed)
  const nonceHex = Array.from(nonce)
    .map((b) => b.toString(16).padStart(2, '0'))
    .join('');
  const ledgerKey = `${sessionKeyFingerprint}:${nonceHex}`;
  if (nonceLedger.has(ledgerKey)) {
    throw new NonceReuseViolation(
      `Deteksi pelanggaran Nonce Reuse pada pasangan (${sessionKeyFingerprint}, ${nonceHex})!`
    );
  }
  nonceLedger.add(ledgerKey);

  // Web Crypto API AES-GCM Encrypt
  // Keluaran native SubtleCrypto: Ciphertext (Li B) || AuthTag (16 B)
  const nativeCipherWithTagBuffer = await globalThis.crypto.subtle.encrypt(
    {
      name: 'AES-GCM',
      iv: nonce,
      additionalData: aad,
      tagLength: ATTACHMENT_CANONICAL.AUTH_TAG_LENGTH_BITS, // 128 bit
    },
    cryptoKey,
    plaintext
  );

  const nativeCipherWithTag = new Uint8Array(nativeCipherWithTagBuffer);
  const totalLength = ATTACHMENT_CANONICAL.NONCE_LENGTH_BYTES + nativeCipherWithTag.length;

  // Format A: Nonce (12 B) || Ciphertext (Li B) || Tag (16 B)
  const framed = new Uint8Array(totalLength);
  framed.set(nonce, 0);
  framed.set(nativeCipherWithTag, ATTACHMENT_CANONICAL.NONCE_LENGTH_BYTES);

  return framed;
}

export interface UnframedChunk {
  nonce: Uint8Array;
  ciphertextWithTag: Uint8Array;
  ciphertextOnly: Uint8Array;
  authTag: Uint8Array;
}

/**
 * Pembongkaran Framing Format A.
 */
export function unframeChunk(framedChunk: Uint8Array): UnframedChunk {
  if (
    !(framedChunk instanceof Uint8Array) ||
    framedChunk.length < ATTACHMENT_CANONICAL.FRAMING_OVERHEAD_BYTES + 1
  ) {
    throw new Error(
      `Panjang buffer framed tidak valid: ${framedChunk ? framedChunk.length : 0} (minimum 29 byte)`
    );
  }

  const nonce = framedChunk.subarray(0, ATTACHMENT_CANONICAL.NONCE_LENGTH_BYTES);
  const ciphertextWithTag = framedChunk.subarray(ATTACHMENT_CANONICAL.NONCE_LENGTH_BYTES);
  const tagStart = framedChunk.length - ATTACHMENT_CANONICAL.AUTH_TAG_LENGTH_BYTES;
  const ciphertextOnly = framedChunk.subarray(ATTACHMENT_CANONICAL.NONCE_LENGTH_BYTES, tagStart);
  const authTag = framedChunk.subarray(tagStart);

  return {
    nonce,
    ciphertextWithTag,
    ciphertextOnly,
    authTag,
  };
}

/**
 * Dekripsi chunk terenkripsi Format A menggunakan AES-256-GCM dengan verifikasi integritas AAD 83-byte.
 * Gagal tertutup (throws AttachmentAuthenticationError) jika tag atau AAD tidak cocok.
 */
export async function decryptChunk(
  cryptoKey: CryptoKey,
  framedChunk: Uint8Array,
  aad: Uint8Array
): Promise<Uint8Array> {
  if (!(aad instanceof Uint8Array) || aad.length !== ATTACHMENT_CANONICAL.AAD_LENGTH_BYTES) {
    throw new Error(`AAD harus Uint8Array 83 byte, diterima: ${aad ? aad.length : 0}`);
  }

  const { nonce, ciphertextWithTag } = unframeChunk(framedChunk);

  try {
    const decryptedBuffer = await globalThis.crypto.subtle.decrypt(
      {
        name: 'AES-GCM',
        iv: nonce,
        additionalData: aad,
        tagLength: ATTACHMENT_CANONICAL.AUTH_TAG_LENGTH_BITS,
      },
      cryptoKey,
      ciphertextWithTag
    );
    return new Uint8Array(decryptedBuffer);
  } catch (err: any) {
    throw new AttachmentAuthenticationError(
      `Verifikasi kriptografis chunk gagal: ${err?.message || 'Authentication tag atau AAD tidak valid'}`
    );
  }
}

/**
 * Utilitas komputasi SHA-256 digest hex (huruf kecil 64 karakter) via WebCrypto.
 */
export async function computeSha256(data: Uint8Array): Promise<string> {
  const hashBuffer = await globalThis.crypto.subtle.digest('SHA-256', data);
  const hashArray = Array.from(new Uint8Array(hashBuffer));
  return hashArray.map((b) => b.toString(16).padStart(2, '0')).join('');
}

