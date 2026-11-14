/**
 * B-CHAT E2EE — Binary Additional Authenticated Data (AAD) Engine
 *
 * Mengimplementasikan pembentukan dan validasi AAD biner deterministik (RFC 5116 / H-B-01):
 * - Mengikat ciphertext terhadap 10 komponen konteks penyimpanan untuk mencegah ciphertext transplantation.
 * - Menggunakan format biner TLV (Big-Endian uint16) murni tanpa serialisasi JSON.
 * - Memvalidasi format kanonis UUID, batas ukuran uint16, dan kelengkapan field wajib.
 */

import { BchatStorageError } from './bchat-idb-core';
import { BCAT_DB_NAME, BCAT_DB_VERSION, type BchatStoreName } from './bchat-idb-schema';

// Magic Header ASCII "BCAT"
export const AAD_MAGIC_BYTES = new Uint8Array([0x42, 0x43, 0x41, 0x54]);

// Versi Skema dan Serializer Kanonis
export const DEFAULT_STORAGE_SCHEMA_VERSION = 1;
export const DEFAULT_SERIALIZER_VERSION = 1;

/**
 * Tipe Record yang diizinkan untuk penyimpanan terenkripsi
 */
export type BchatStorageRecordType =
  | 'client_state'
  | 'private_key_package'
  | 'outbox_message'
  | 'generic_secret'
  | 'chat_message';

/**
 * Konteks parameter untuk pembentukan AAD
 */
export interface BchatAadContext {
  storageSchemaVersion?: number;
  serializerVersion?: number;
  databaseName?: string;
  storeName: BchatStoreName;
  recordType: BchatStorageRecordType | string;
  deviceUuid: string;
  roomUuid?: string | null;
  recordKey: string | string[];
  keyId: string;
}

/**
 * Kelas Error khusus untuk kegagalan validasi parameter AAD
 */
export class BchatAadValidationError extends BchatStorageError {
  constructor(message: string, originalError?: unknown) {
    super(message, 'AAD_VALIDATION_ERROR', originalError);
    this.name = 'BchatAadValidationError';
  }
}

// Regex validasi UUID kanonis (RFC 4122 / RFC 9562)
const UUID_REGEX = /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i;

/**
 * Memvalidasi dan menormalkan format UUID ke representasi huruf kecil kanonis
 */
export function normalizeCanonicalUuid(uuid: unknown, fieldName: string): string {
  if (typeof uuid !== 'string' || !uuid.trim()) {
    throw new BchatAadValidationError(`Field '${fieldName}' wajib berupa string UUID non-kosong.`);
  }
  const clean = uuid.trim().toLowerCase();
  if (!UUID_REGEX.test(clean)) {
    throw new BchatAadValidationError(
      `Field '${fieldName}' bernilai '${uuid}' bukan merupakan format UUID kanonis yang valid.`
    );
  }
  return clean;
}

/**
 * Memvalidasi dan membentuk representasi kanonis recordKey yang bebas ambiguitas
 */
export function canonicalizeRecordKey(key: unknown): string {
  if (typeof key === 'string') {
    const trimmed = key.trim();
    if (!trimmed) {
      throw new BchatAadValidationError("Field 'recordKey' string tidak boleh kosong.");
    }
    return trimmed;
  }

  if (Array.isArray(key)) {
    if (key.length === 0) {
      throw new BchatAadValidationError("Field composite 'recordKey' array tidak boleh kosong.");
    }
    // Encode komponen array menggunakan length prefixing: len:value/len:value
    return key
      .map((part, idx) => {
        if (typeof part !== 'string' || !part.trim()) {
          throw new BchatAadValidationError(
            `Komponen ke-${idx} pada composite 'recordKey' harus berupa string non-kosong.`
          );
        }
        const str = part.trim();
        return `${str.length}:${str}`;
      })
      .join('/');
  }

  throw new BchatAadValidationError("Field 'recordKey' harus bertipe string atau string[].");
}

/**
 * Helper menulis uint16 Big-Endian ke array byte
 */
function appendUint16BE(chunks: Uint8Array[], value: number, fieldName: string): void {
  if (!Number.isInteger(value) || value < 0 || value > 0xffff) {
    throw new BchatAadValidationError(
      `Nilai numerik '${fieldName}' (${value}) berada di luar batas uint16 (0 - 65535).`
    );
  }
  const buf = new Uint8Array(2);
  buf[0] = (value >> 8) & 0xff;
  buf[1] = value & 0xff;
  chunks.push(buf);
}

/**
 * Helper menulis string UTF-8 dengan panjang uint16 Big-Endian
 */
function appendLengthPrefixedUtf8(chunks: Uint8Array[], str: string, fieldName: string): void {
  const bytes = new TextEncoder().encode(str);
  if (bytes.length > 0xffff) {
    throw new BchatAadValidationError(
      `Panjang UTF-8 field '${fieldName}' (${bytes.length} bytes) melebihi batas uint16 (65535).`
    );
  }
  appendUint16BE(chunks, bytes.length, `${fieldName}_length`);
  chunks.push(bytes);
}

/**
 * Membangun buffer biner AAD deterministik dari konteks penyimpanan
 *
 * Urutan 10 Field Baku (H-B-01):
 * 1. Magic ASCII "BCAT" (4 byte)
 * 2. Storage schema version (uint16BE)
 * 3. Serializer version (uint16BE)
 * 4. Database name (uint16BE length + UTF-8)
 * 5. Object store name (uint16BE length + UTF-8)
 * 6. Record type (uint16BE length + UTF-8)
 * 7. Device UUID (uint16BE length + UTF-8)
 * 8. Room UUID (uint16BE length + UTF-8; 0 byte jika tidak terikat room)
 * 9. Record key (uint16BE length + UTF-8)
 * 10. KEK ID (uint16BE length + UTF-8)
 */
export function buildStorageAad(ctx: BchatAadContext): Uint8Array {
  if (!ctx || typeof ctx !== 'object') {
    throw new BchatAadValidationError('Konteks AAD tidak boleh null atau bukan objek.');
  }

  const chunks: Uint8Array[] = [];

  // 1. Magic Header (4 byte)
  chunks.push(AAD_MAGIC_BYTES);

  // 2. Storage Schema Version (uint16BE)
  const schemaVersion = ctx.storageSchemaVersion ?? DEFAULT_STORAGE_SCHEMA_VERSION;
  appendUint16BE(chunks, schemaVersion, 'storageSchemaVersion');

  // 3. Serializer Version (uint16BE)
  const serializerVersion = ctx.serializerVersion ?? DEFAULT_SERIALIZER_VERSION;
  appendUint16BE(chunks, serializerVersion, 'serializerVersion');

  // 4. Database Name (uint16BE length + UTF-8)
  const dbName = ctx.databaseName ?? BCAT_DB_NAME;
  if (!dbName || typeof dbName !== 'string') {
    throw new BchatAadValidationError("Field 'databaseName' wajib berupa string.");
  }
  appendLengthPrefixedUtf8(chunks, dbName, 'databaseName');

  // 5. Object Store Name (uint16BE length + UTF-8)
  if (!ctx.storeName || typeof ctx.storeName !== 'string' || !ctx.storeName.trim()) {
    throw new BchatAadValidationError("Field 'storeName' wajib berupa string non-kosong.");
  }
  appendLengthPrefixedUtf8(chunks, ctx.storeName.trim(), 'storeName');

  // 6. Record Type (uint16BE length + UTF-8)
  if (!ctx.recordType || typeof ctx.recordType !== 'string' || !ctx.recordType.trim()) {
    throw new BchatAadValidationError("Field 'recordType' wajib berupa string non-kosong.");
  }
  appendLengthPrefixedUtf8(chunks, ctx.recordType.trim(), 'recordType');

  // 7. Device UUID (uint16BE length + UTF-8 kanonis)
  const canonicalDeviceUuid = normalizeCanonicalUuid(ctx.deviceUuid, 'deviceUuid');
  appendLengthPrefixedUtf8(chunks, canonicalDeviceUuid, 'deviceUuid');

  // 8. Room UUID (uint16BE length + UTF-8; length 0 jika tidak ada)
  if (ctx.roomUuid !== undefined && ctx.roomUuid !== null && ctx.roomUuid !== '') {
    const canonicalRoomUuid = normalizeCanonicalUuid(ctx.roomUuid, 'roomUuid');
    appendLengthPrefixedUtf8(chunks, canonicalRoomUuid, 'roomUuid');
  } else {
    // Panjang nol untuk record yang tidak terikat room
    appendUint16BE(chunks, 0, 'roomUuid_length');
  }

  // 9. Record Key (uint16BE length + UTF-8 kanonis)
  const canonicalKey = canonicalizeRecordKey(ctx.recordKey);
  appendLengthPrefixedUtf8(chunks, canonicalKey, 'recordKey');

  // 10. KEK ID (uint16BE length + UTF-8)
  if (!ctx.keyId || typeof ctx.keyId !== 'string' || !ctx.keyId.trim()) {
    throw new BchatAadValidationError("Field 'keyId' wajib berupa string non-kosong.");
  }
  appendLengthPrefixedUtf8(chunks, ctx.keyId.trim(), 'keyId');

  // Hitung total panjang buffer
  let totalLength = 0;
  for (const chunk of chunks) {
    totalLength += chunk.length;
  }

  // Gabungkan seluruh chunk biner menjadi satu Uint8Array deterministik
  const finalAad = new Uint8Array(totalLength);
  let offset = 0;
  for (const chunk of chunks) {
    finalAad.set(chunk, offset);
    offset += chunk.length;
  }

  return finalAad;
}

