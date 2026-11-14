/**
 * B-CHAT E2EE — Storage Envelope Specification and Validator
 *
 * Mendefinisikan struktur amplop penyimpanan terenkripsi (BchatEncryptedEnvelope):
 * - Memvalidasi format, tipe data, dan integritas amplop sebelum operasi dekripsi.
 * - Menjamin parameter kriptografi baku: IV 96-bit (12 bytes) dan tag autentikasi minimal 128-bit (16 bytes).
 * - Menjamin tidak ada metadata plaintext sensitif yang bocor ke dalam amplop.
 */

import { BchatStorageError } from './bchat-idb-core';
import { DEFAULT_STORAGE_SCHEMA_VERSION, DEFAULT_SERIALIZER_VERSION } from './bchat-storage-aad';

export { DEFAULT_STORAGE_SCHEMA_VERSION, DEFAULT_SERIALIZER_VERSION };

export const GCM_IV_LENGTH_BYTES = 12; // 96-bit IV baku AES-GCM
export const GCM_TAG_LENGTH_BYTES = 16; // 128-bit Authentication Tag

/**
 * Struktur amplop terenkripsi yang disimpan ke IndexedDB
 */
export interface BchatEncryptedEnvelope {
  storage_schema_version: number;
  serializer_version: number;
  key_id: string;
  iv: Uint8Array;
  ciphertext: Uint8Array;
  created_at: number;
}

/**
 * Kelas Error khusus untuk kegagalan validasi struktur amplop
 */
export class BchatEnvelopeValidationError extends BchatStorageError {
  constructor(message: string, originalError?: unknown) {
    super(message, 'ENVELOPE_VALIDATION_ERROR', originalError);
    this.name = 'BchatEnvelopeValidationError';
  }
}

/**
 * Memvalidasi integritas dan kelayakan amplop penyimpanan terenkripsi secara ketat
 * sebelum diserahkan ke WebCrypto API (subtle.decrypt)
 */
export function validateStorageEnvelope(raw: unknown): BchatEncryptedEnvelope {
  if (!raw || typeof raw !== 'object') {
    throw new BchatEnvelopeValidationError('Amplop penyimpanan terenkripsi tidak boleh null atau bukan objek.');
  }

  const env = raw as Record<string, unknown>;

  // 1. Validasi Storage Schema Version
  if (
    typeof env.storage_schema_version !== 'number' ||
    !Number.isInteger(env.storage_schema_version) ||
    env.storage_schema_version <= 0
  ) {
    throw new BchatEnvelopeValidationError(
      `Field 'storage_schema_version' tidak valid: ${String(env.storage_schema_version)}.`
    );
  }
  if (env.storage_schema_version !== DEFAULT_STORAGE_SCHEMA_VERSION) {
    throw new BchatEnvelopeValidationError(
      `Versi skema amplop ${env.storage_schema_version} tidak didukung (didukung: ${DEFAULT_STORAGE_SCHEMA_VERSION}).`
    );
  }

  // 2. Validasi Serializer Version
  if (
    typeof env.serializer_version !== 'number' ||
    !Number.isInteger(env.serializer_version) ||
    env.serializer_version <= 0
  ) {
    throw new BchatEnvelopeValidationError(
      `Field 'serializer_version' tidak valid: ${String(env.serializer_version)}.`
    );
  }
  if (env.serializer_version !== DEFAULT_SERIALIZER_VERSION) {
    throw new BchatEnvelopeValidationError(
      `Versi serializer amplop ${env.serializer_version} tidak didukung (didukung: ${DEFAULT_SERIALIZER_VERSION}).`
    );
  }

  // 3. Validasi key_id
  if (typeof env.key_id !== 'string' || !env.key_id.trim()) {
    throw new BchatEnvelopeValidationError("Field 'key_id' pada amplop harus berupa string non-kosong.");
  }

  // 4. Validasi IV (Wajib tepat 12 byte)
  if (!(env.iv instanceof Uint8Array)) {
    throw new BchatEnvelopeValidationError("Field 'iv' pada amplop wajib bertipe Uint8Array.");
  }
  if (env.iv.length !== GCM_IV_LENGTH_BYTES) {
    throw new BchatEnvelopeValidationError(
      `Panjang IV tidak valid: ${env.iv.length} bytes (wajib tepat ${GCM_IV_LENGTH_BYTES} bytes untuk AES-GCM).`
    );
  }

  // 5. Validasi Ciphertext (Wajib minimal 16 byte mencakup GCM Authentication Tag)
  if (!(env.ciphertext instanceof Uint8Array)) {
    throw new BchatEnvelopeValidationError("Field 'ciphertext' pada amplop wajib bertipe Uint8Array.");
  }
  if (env.ciphertext.length < GCM_TAG_LENGTH_BYTES) {
    throw new BchatEnvelopeValidationError(
      `Panjang ciphertext (${env.ciphertext.length} bytes) tidak mencukupi untuk memuat authentication tag 128-bit (${GCM_TAG_LENGTH_BYTES} bytes).`
    );
  }

  // 6. Validasi created_at timestamp
  if (typeof env.created_at !== 'number' || !Number.isFinite(env.created_at) || env.created_at <= 0) {
    throw new BchatEnvelopeValidationError(
      `Timestamp 'created_at' tidak valid atau tidak finite: ${String(env.created_at)}.`
    );
  }

  return {
    storage_schema_version: env.storage_schema_version,
    serializer_version: env.serializer_version,
    key_id: env.key_id.trim(),
    iv: env.iv,
    ciphertext: env.ciphertext,
    created_at: env.created_at,
  };
}
