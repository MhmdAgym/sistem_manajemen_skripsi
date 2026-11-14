/**
 * B-CHAT E2EE — Attachment Constants & Canonical Error Classes
 * Sesuai Dokumen B-CHAT EXP-01/EXP-02, B-CHAT-06C.1.1, B-CHAT-08A.5, B-CHAT-08D.2
 */

export const ATTACHMENT_CANONICAL = {
  // Batas Ukuran & Kuota Sistem
  CHUNK_SIZE_BYTES: 1_048_576,        // 1 MiB (2^20 bytes)
  MAX_FILE_SIZE_BYTES: 10_000_000,    // 10 MB desimal per berkas
  MAX_TOTAL_SIZE_BYTES: 25_000_000,   // 25 MB desimal per pesan
  MAX_ATTACHMENT_COUNT: 5,            // Maksimum 5 berkas per pesan
  MAX_CHUNKS_PER_FILE: 10,            // 1 <= N <= 10 chunk per berkas

  // Parameter Kriptografi & Framing Format A
  CEK_LENGTH_BYTES: 32,               // AES-256 (256-bit)
  NONCE_LENGTH_BYTES: 12,             // 96-bit (12 byte)
  SALT_LENGTH_BYTES: 8,               // UploadSessionSalt 64-bit (8 byte)
  AUTH_TAG_LENGTH_BYTES: 16,          // 128-bit auth tag (16 byte)
  AUTH_TAG_LENGTH_BITS: 128,          // 128 bit untuk Web Crypto API
  FRAMING_OVERHEAD_BYTES: 28,         // 12 B Nonce + 16 B Tag

  // Parameter AAD Kanonikal 83-Byte
  AAD_LENGTH_BYTES: 83,
  MAGIC_HEADER_BYTES: new Uint8Array([0x55, 0x4e, 0x48, 0x31]), // ASCII 'UNH1'
  PROTOCOL_VERSION: 0x0001,           // uint16be (2 byte)
  DOMAIN_SEPARATOR: 0x02,             // uint8 (1 byte): ATTACHMENT_CHUNK

  // MLS Exporter Kanonikal
  EXPORTER_LABEL: 'bchat_attachment_cek',
  EXPORTER_CONTEXT_LENGTH_BYTES: 48,  // RoomUUID (16 B) || MessageUUID (16 B) || AttachmentUUID (16 B)
} as const;

// ---------------------------------------------------------------------------
// Custom Typed Error Classes
// ---------------------------------------------------------------------------

export class EmptyFileError extends Error {
  constructor(message = 'Ukuran berkas 0 byte ditolak mutlak.') {
    super(message);
    this.name = 'EmptyFileError';
  }
}

export class FileQuotaExceededError extends Error {
  constructor(message = 'Ukuran berkas melebihi batas kuota tunggal 10.000.000 byte.') {
    super(message);
    this.name = 'FileQuotaExceededError';
  }
}

export class TotalQuotaExceededError extends Error {
  constructor(message = 'Total ukuran berkas gabungan melebihi kuota 25.000.000 byte.') {
    super(message);
    this.name = 'TotalQuotaExceededError';
  }
}

export class MaxAttachmentCountExceeded extends Error {
  constructor(message = 'Jumlah berkas melebihi batas maksimum 5 berkas per pesan.') {
    super(message);
    this.name = 'MaxAttachmentCountExceeded';
  }
}

export class TypeRangeValidationError extends Error {
  constructor(message = 'Nilai indeks chunk tidak boleh negatif (< 0).') {
    super(message);
    this.name = 'TypeRangeValidationError';
  }
}

export class ChunkIndexOutOfRangeError extends Error {
  constructor(message = 'Nilai indeks chunk melampaui batas total chunk (chunk_index >= N).') {
    super(message);
    this.name = 'ChunkIndexOutOfRangeError';
  }
}

export class AssemblyError extends Error {
  public readonly code: string;
  constructor(code: string, message: string) {
    super(`[${code}] ${message}`);
    this.name = 'AssemblyError';
    this.code = code;
  }
}

export class ManifestConsistencyError extends Error {
  constructor(message = 'Ketidakkonsistenan manifest perakitan terdeteksi.') {
    super(message);
    this.name = 'ManifestConsistencyError';
  }
}

export class NonceReuseViolation extends Error {
  constructor(message = 'Pelanggaran keamanan: Duplikasi pasangan (CEK, Nonce) terdeteksi!') {
    super(message);
    this.name = 'NonceReuseViolation';
  }
}

export class AttachmentAuthenticationError extends Error {
  constructor(message = 'Autentikasi kriptografis chunk gagal (tag atau AAD tidak valid).') {
    super(message);
    this.name = 'AttachmentAuthenticationError';
  }
}

export class AttachmentUploadError extends Error {
  public readonly code: string;
  constructor(code: string, message: string) {
    super(`[${code}] ${message}`);
    this.name = 'AttachmentUploadError';
    this.code = code;
  }
}

