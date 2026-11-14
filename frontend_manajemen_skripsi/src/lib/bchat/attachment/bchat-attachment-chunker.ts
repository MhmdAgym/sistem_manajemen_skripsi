/**
 * B-CHAT E2EE — Plaintext Chunking Engine & System Quota Validator
 * Sesuai B-CHAT-08A.5, B-CHAT-08C.4, B-CHAT-08D.2
 */

import {
  ATTACHMENT_CANONICAL,
  EmptyFileError,
  FileQuotaExceededError,
  TotalQuotaExceededError,
  MaxAttachmentCountExceeded,
} from './bchat-attachment-constants';

export interface AttachmentQuotaItem {
  size: number;
  name?: string;
}

export interface AttachmentQuotaValidationResult {
  valid: boolean;
  fileCount: number;
  totalSize: number;
}

/**
 * Validasi kuota lampiran pesan bimbingan secara komprehensif.
 */
export function validateAttachmentQuotas(
  attachments: Array<AttachmentQuotaItem | number>
): AttachmentQuotaValidationResult {
  if (!Array.isArray(attachments)) {
    throw new TypeError('Attachments harus berupa array');
  }

  // 1. Batas Jumlah Berkas Maksimum (Maks 5 berkas per pesan)
  if (attachments.length > ATTACHMENT_CANONICAL.MAX_ATTACHMENT_COUNT) {
    throw new MaxAttachmentCountExceeded(
      `Jumlah berkas (${attachments.length}) melebihi kuota maksimum (${ATTACHMENT_CANONICAL.MAX_ATTACHMENT_COUNT} berkas per pesan)`
    );
  }

  let totalSize = 0;

  for (let i = 0; i < attachments.length; i++) {
    const item = attachments[i];
    const size = typeof item === 'number' ? item : item.size;

    // 2. Validasi File Kosong (0 Byte Ditolak Mutlak)
    if (size === 0) {
      throw new EmptyFileError(`Berkas #${i + 1} berukuran 0 byte ditolak mutlak.`);
    }

    if (size < 0) {
      throw new Error(`Berkas #${i + 1} memiliki ukuran negatif (${size} byte) yang tidak sah.`);
    }

    // 3. Batas Kuota Berkas Tunggal (Maks 10.000.000 byte)
    if (size > ATTACHMENT_CANONICAL.MAX_FILE_SIZE_BYTES) {
      throw new FileQuotaExceededError(
        `Berkas #${i + 1} (${size} B) melebihi kuota berkas tunggal (${ATTACHMENT_CANONICAL.MAX_FILE_SIZE_BYTES} B)`
      );
    }

    totalSize += size;
  }

  // 4. Batas Kuota Gabungan Pesan (Maks 25.000.000 byte)
  if (totalSize > ATTACHMENT_CANONICAL.MAX_TOTAL_SIZE_BYTES) {
    throw new TotalQuotaExceededError(
      `Total ukuran berkas gabungan (${totalSize} B) melebihi kuota pesan (${ATTACHMENT_CANONICAL.MAX_TOTAL_SIZE_BYTES} B)`
    );
  }

  return {
    valid: true,
    fileCount: attachments.length,
    totalSize,
  };
}

export interface ChunkMetadataResult {
  totalSize: number;
  totalChunks: number;
  chunkSizeStandard: number;
  finalChunkSize: number;
  chunkLengths: number[];
}

/**
 * Kalkulasi metadata chunking kanonikal untuk ukuran berkas S.
 * Formula: N = ceil(S / C), 1 <= N <= 10.
 */
export function calculateChunkMetadata(totalSize: number): ChunkMetadataResult {
  if (totalSize === 0) {
    throw new EmptyFileError('Kalkulasi ditolak: ukuran berkas 0 byte.');
  }
  if (totalSize > ATTACHMENT_CANONICAL.MAX_FILE_SIZE_BYTES) {
    throw new FileQuotaExceededError(`Ukuran berkas (${totalSize} B) melampaui kuota tunggal 10 MB.`);
  }

  const C = ATTACHMENT_CANONICAL.CHUNK_SIZE_BYTES;
  const N = Math.ceil(totalSize / C);

  if (N < 1 || N > ATTACHMENT_CANONICAL.MAX_CHUNKS_PER_FILE) {
    throw new Error(`Jumlah total chunk (${N}) di luar batas operasional 1..10`);
  }

  const chunkLengths: number[] = [];
  let remaining = totalSize;

  for (let i = 0; i < N; i++) {
    const len = i === N - 1 ? remaining : C;
    chunkLengths.push(len);
    remaining -= len;
  }

  const finalChunkSize = chunkLengths[N - 1];

  return {
    totalSize,
    totalChunks: N,
    chunkSizeStandard: C,
    finalChunkSize,
    chunkLengths,
  };
}

/**
 * Memecah buffer biner berkas utuh menjadi potongan-potongan chunk berukuran standar (maks 1.048.576 B).
 */
export function slicePlaintextIntoChunks(fileBytes: Uint8Array): Uint8Array[] {
  if (!(fileBytes instanceof Uint8Array) || fileBytes.length === 0) {
    throw new EmptyFileError('Berkas kosong tidak dapat dipecah menjadi chunk.');
  }
  const meta = calculateChunkMetadata(fileBytes.length);
  const chunks: Uint8Array[] = [];

  let offset = 0;
  for (let i = 0; i < meta.totalChunks; i++) {
    const len = meta.chunkLengths[i];
    chunks.push(fileBytes.subarray(offset, offset + len));
    offset += len;
  }

  return chunks;
}

