/**
 * B-CHAT E2EE — Canonical AAD (Additional Authenticated Data) Serializer & Parser
 * Sesuai B-CHAT-06C.1.1 §4.5, B-CHAT-08A.5, B-CHAT-08D.2
 *
 * Struktur Persis 83 Byte:
 * Field 1: Magic Header (4 B ASCII 'UNH1')
 * Field 2: Protocol Version (2 B uint16be = 0x0001)
 * Field 3: Domain Separator (1 B uint8 = 0x02)
 * Field 4: Room UUID (16 B raw octets)
 * Field 5: Message UUID (16 B raw octets)
 * Field 6: Attachment UUID (16 B raw octets)
 * Field 7: Upload Session ID (16 B raw octets)
 * Field 8: Chunk Index (4 B uint32be, 0-based: 0 <= idx < N)
 * Field 9: Total Chunks (4 B uint32be, 1 <= N <= 10)
 * Field 10: Plaintext Length (4 B uint32be, 1 <= Li <= 1.048.576)
 */

import {
  ATTACHMENT_CANONICAL,
  TypeRangeValidationError,
  ChunkIndexOutOfRangeError,
} from './bchat-attachment-constants';

/**
 * Konversi string UUID (RFC 4122) ke Uint8Array 16 byte mentah (Big-Endian).
 */
export function uuidToBytes(uuidInput: string | Uint8Array): Uint8Array {
  if (uuidInput instanceof Uint8Array) {
    if (uuidInput.length !== 16) {
      throw new Error(`Panjang buffer UUID tidak valid: ${uuidInput.length} (wajib tepat 16 byte)`);
    }
    return uuidInput;
  }
  if (typeof uuidInput !== 'string') {
    throw new TypeError('UUID harus berupa string atau Uint8Array 16 byte');
  }
  const clean = uuidInput.replace(/-/g, '').toLowerCase();
  if (clean.length !== 32 || !/^[0-9a-f]{32}$/.test(clean)) {
    throw new Error(`Format string UUID tidak valid: '${uuidInput}' (wajib 32 karakter hex)`);
  }
  const bytes = new Uint8Array(16);
  for (let i = 0; i < 16; i++) {
    bytes[i] = parseInt(clean.substring(i * 2, i * 2 + 2), 16);
  }
  return bytes;
}

/**
 * Konversi buffer 16 byte mentah ke string UUID kanonikal (RFC 4122).
 */
export function bytesToUuid(bytes: Uint8Array): string {
  if (!(bytes instanceof Uint8Array) || bytes.length !== 16) {
    throw new Error(`Buffer UUID wajib Uint8Array 16 byte, diterima: ${bytes ? bytes.length : 0}`);
  }
  const hex = Array.from(bytes)
    .map((b) => b.toString(16).padStart(2, '0'))
    .join('');
  return [
    hex.substring(0, 8),
    hex.substring(8, 12),
    hex.substring(12, 16),
    hex.substring(16, 20),
    hex.substring(20, 32),
  ].join('-');
}

export interface SerializeAadParams {
  roomUuid: string | Uint8Array;
  messageUuid: string | Uint8Array;
  attachmentUuid: string | Uint8Array;
  uploadSessionId: string | Uint8Array;
  chunkIndex: number;
  totalChunks: number;
  plaintextLength: number;
}

export interface ParsedAad {
  magicHeader: string;
  protocolVersion: number;
  domainSeparator: number;
  roomUuid: string;
  messageUuid: string;
  attachmentUuid: string;
  uploadSessionId: string;
  chunkIndex: number;
  totalChunks: number;
  plaintextLength: number;
}

/**
 * Serialisasi parameter metadata menjadi buffer biner AAD kanonikal tepat 83 byte.
 */
export function serializeAad(params: SerializeAadParams): Uint8Array {
  const {
    roomUuid,
    messageUuid,
    attachmentUuid,
    uploadSessionId,
    chunkIndex,
    totalChunks,
    plaintextLength,
  } = params;

  // 1. Validasi Batas & Indeks Input
  if (typeof chunkIndex !== 'number' || Number.isNaN(chunkIndex)) {
    throw new TypeError('chunkIndex harus berupa angka integer');
  }
  if (chunkIndex < 0) {
    throw new TypeRangeValidationError(`Indeks chunk negatif tidak sah: ${chunkIndex} < 0`);
  }
  if (typeof totalChunks !== 'number' || totalChunks < 1 || totalChunks > ATTACHMENT_CANONICAL.MAX_CHUNKS_PER_FILE) {
    throw new Error(`Total chunks di luar rentang sah [1..10]: ${totalChunks}`);
  }
  if (chunkIndex >= totalChunks) {
    throw new ChunkIndexOutOfRangeError(
      `Indeks chunk melampaui batas total chunks: ${chunkIndex} >= ${totalChunks}`
    );
  }
  if (
    typeof plaintextLength !== 'number' ||
    plaintextLength < 1 ||
    plaintextLength > ATTACHMENT_CANONICAL.CHUNK_SIZE_BYTES
  ) {
    throw new Error(`Plaintext length di luar rentang sah [1..1.048.576]: ${plaintextLength}`);
  }

  // 2. Normalisasi 4 field UUID ke 16-byte raw octets
  const roomBytes = uuidToBytes(roomUuid);
  const msgBytes = uuidToBytes(messageUuid);
  const attBytes = uuidToBytes(attachmentUuid);
  const sessBytes = uuidToBytes(uploadSessionId);

  // 3. Alokasi buffer persis 83 byte
  const buffer = new Uint8Array(ATTACHMENT_CANONICAL.AAD_LENGTH_BYTES);
  const view = new DataView(buffer.buffer, buffer.byteOffset, buffer.byteLength);

  let offset = 0;

  // Field 1: Magic Header (4 B ASCII 'UNH1')
  buffer.set(ATTACHMENT_CANONICAL.MAGIC_HEADER_BYTES, offset);
  offset += 4;

  // Field 2: Protocol Version (2 B uint16be = 0x0001)
  view.setUint16(offset, ATTACHMENT_CANONICAL.PROTOCOL_VERSION, false);
  offset += 2;

  // Field 3: Domain Separator (1 B uint8 = 0x02)
  view.setUint8(offset, ATTACHMENT_CANONICAL.DOMAIN_SEPARATOR);
  offset += 1;

  // Field 4: Room UUID (16 B)
  buffer.set(roomBytes, offset);
  offset += 16;

  // Field 5: Message UUID (16 B)
  buffer.set(msgBytes, offset);
  offset += 16;

  // Field 6: Attachment UUID (16 B)
  buffer.set(attBytes, offset);
  offset += 16;

  // Field 7: Upload Session ID (16 B)
  buffer.set(sessBytes, offset);
  offset += 16;

  // Field 8: Chunk Index (4 B uint32be)
  view.setUint32(offset, chunkIndex, false);
  offset += 4;

  // Field 9: Total Chunks (4 B uint32be)
  view.setUint32(offset, totalChunks, false);
  offset += 4;

  // Field 10: Plaintext Length (4 B uint32be)
  view.setUint32(offset, plaintextLength, false);
  offset += 4;

  if (offset !== ATTACHMENT_CANONICAL.AAD_LENGTH_BYTES) {
    throw new Error(`Internal error: panjang serialisasi AAD (${offset}) != 83`);
  }

  return buffer;
}

/**
 * Parsing dan validasi struktural biner AAD kanonikal tepat 83 byte.
 */
export function parseAad(aadBuffer: Uint8Array): ParsedAad {
  if (!(aadBuffer instanceof Uint8Array) || aadBuffer.length !== ATTACHMENT_CANONICAL.AAD_LENGTH_BYTES) {
    throw new Error(
      `Panjang buffer AAD tidak valid: ${aadBuffer ? aadBuffer.length : 0} (wajib tepat 83 byte)`
    );
  }

  const view = new DataView(aadBuffer.buffer, aadBuffer.byteOffset, aadBuffer.byteLength);
  let offset = 0;

  // Field 1: Magic Header ('UNH1')
  for (let i = 0; i < 4; i++) {
    if (aadBuffer[offset + i] !== ATTACHMENT_CANONICAL.MAGIC_HEADER_BYTES[i]) {
      throw new Error('Magic header AAD tidak valid (wajib ASCII UNH1)');
    }
  }
  const magicHeader = new TextDecoder('ascii').decode(aadBuffer.subarray(offset, offset + 4));
  offset += 4;

  // Field 2: Protocol Version (0x0001)
  const protocolVersion = view.getUint16(offset, false);
  if (protocolVersion !== ATTACHMENT_CANONICAL.PROTOCOL_VERSION) {
    throw new Error(`Protocol version AAD tidak valid: 0x${protocolVersion.toString(16)} (wajib 0x0001)`);
  }
  offset += 2;

  // Field 3: Domain Separator (0x02)
  const domainSeparator = view.getUint8(offset);
  if (domainSeparator !== ATTACHMENT_CANONICAL.DOMAIN_SEPARATOR) {
    throw new Error(`Domain separator AAD tidak valid: 0x${domainSeparator.toString(16)} (wajib 0x02)`);
  }
  offset += 1;

  // Field 4: Room UUID (16 B)
  const roomUuid = bytesToUuid(aadBuffer.subarray(offset, offset + 16));
  offset += 16;

  // Field 5: Message UUID (16 B)
  const messageUuid = bytesToUuid(aadBuffer.subarray(offset, offset + 16));
  offset += 16;

  // Field 6: Attachment UUID (16 B)
  const attachmentUuid = bytesToUuid(aadBuffer.subarray(offset, offset + 16));
  offset += 16;

  // Field 7: Upload Session ID (16 B)
  const uploadSessionId = bytesToUuid(aadBuffer.subarray(offset, offset + 16));
  offset += 16;

  // Field 8: Chunk Index (4 B)
  const chunkIndex = view.getUint32(offset, false);
  offset += 4;

  // Field 9: Total Chunks (4 B)
  const totalChunks = view.getUint32(offset, false);
  offset += 4;

  // Field 10: Plaintext Length (4 B)
  const plaintextLength = view.getUint32(offset, false);
  offset += 4;

  if (totalChunks < 1 || totalChunks > ATTACHMENT_CANONICAL.MAX_CHUNKS_PER_FILE) {
    throw new Error(`Total chunks pada AAD di luar batas operasional [1..10]: ${totalChunks}`);
  }
  if (chunkIndex >= totalChunks) {
    throw new ChunkIndexOutOfRangeError(`Indeks chunk pada AAD (${chunkIndex}) >= total chunks (${totalChunks})`);
  }
  if (plaintextLength < 1 || plaintextLength > ATTACHMENT_CANONICAL.CHUNK_SIZE_BYTES) {
    throw new Error(`Plaintext length pada AAD (${plaintextLength}) di luar rentang [1..1.048.576]`);
  }

  return {
    magicHeader,
    protocolVersion,
    domainSeparator,
    roomUuid,
    messageUuid,
    attachmentUuid,
    uploadSessionId,
    chunkIndex,
    totalChunks,
    plaintextLength,
  };
}

