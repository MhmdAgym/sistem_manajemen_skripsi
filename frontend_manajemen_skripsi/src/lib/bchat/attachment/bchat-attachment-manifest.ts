/**
 * B-CHAT E2EE — Attachment Manifest Specification & Validation (I-05A / Stage 4)
 * Sesuai Dokumen B-CHAT-01 §8.3, B-CHAT-04 §3.2, B-CHAT-06C.1.1 §4.5, dan B-CHAT-08A.5
 *
 * Mengatur:
 * 1. Skema Manifest Lampiran Terenkripsi (Payload MLS Application Message content_type = 2).
 * 2. Validasi struktur manifest di sisi klien penerima (Zero Plaintext Leakage ke server).
 * 3. Parsing aman, penolakan anomali / modifikasi manifest, dan pemformatan ukuran berkas.
 */

import { ATTACHMENT_CANONICAL, ManifestConsistencyError } from './bchat-attachment-constants';

const CANONICAL_UUID_REGEX = /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i;
const SHA256_HEX_REGEX = /^[0-9a-f]{64}$/i;

export interface BchatAttachmentItemManifest {
  attachmentUuid: string;
  uploadSessionId: string;
  fileName: string;
  mimeType: string;
  fileSizeBytes: number;
  totalChunks: number;
  fileHashSha256: string;
  cekBase64?: string;
}

export interface BchatAttachmentManifest {
  version: '1.0.0';
  type: 'ATTACHMENT_MANIFEST';
  text?: string;
  attachments: BchatAttachmentItemManifest[];
}

/**
 * Memvalidasi apakah sebuah objek memenuhi spesifikasi resmi BchatAttachmentManifest.
 * Melempar ManifestConsistencyError jika terjadi pelanggaran invarian.
 */
export function validateAttachmentManifest(data: unknown): BchatAttachmentManifest {
  if (!data || typeof data !== 'object') {
    throw new ManifestConsistencyError('Manifest lampiran wajib berupa objek valid.');
  }

  const m = data as Partial<BchatAttachmentManifest>;

  if (m.type !== 'ATTACHMENT_MANIFEST') {
    throw new ManifestConsistencyError(`Tipe manifest wajib 'ATTACHMENT_MANIFEST', diterima: '${String(m.type)}'.`);
  }

  if (m.version !== '1.0.0') {
    throw new ManifestConsistencyError(`Versi manifest wajib '1.0.0', diterima: '${String(m.version)}'.`);
  }

  if (!Array.isArray(m.attachments)) {
    throw new ManifestConsistencyError('Daftar lampiran (attachments) wajib berupa array.');
  }

  if (m.attachments.length === 0) {
    throw new ManifestConsistencyError('Daftar lampiran tidak boleh kosong.');
  }

  if (m.attachments.length > ATTACHMENT_CANONICAL.MAX_ATTACHMENT_COUNT) {
    throw new ManifestConsistencyError(
      `Jumlah lampiran (${m.attachments.length}) melebihi kuota maksimum (${ATTACHMENT_CANONICAL.MAX_ATTACHMENT_COUNT}).`
    );
  }

  let totalCombinedBytes = 0;

  for (let idx = 0; idx < m.attachments.length; idx++) {
    const item = m.attachments[idx];
    if (!item || typeof item !== 'object') {
      throw new ManifestConsistencyError(`Item lampiran #${idx} tidak valid.`);
    }

    // Validasi attachmentUuid
    if (!item.attachmentUuid || !CANONICAL_UUID_REGEX.test(item.attachmentUuid)) {
      throw new ManifestConsistencyError(`Item lampiran #${idx} memiliki attachmentUuid tidak valid: '${item.attachmentUuid}'.`);
    }

    // Validasi uploadSessionId
    if (!item.uploadSessionId || !CANONICAL_UUID_REGEX.test(item.uploadSessionId)) {
      throw new ManifestConsistencyError(`Item lampiran #${idx} memiliki uploadSessionId tidak valid: '${item.uploadSessionId}'.`);
    }

    // Validasi fileName
    if (typeof item.fileName !== 'string' || item.fileName.trim().length === 0) {
      throw new ManifestConsistencyError(`Item lampiran #${idx} wajib memiliki fileName non-kosong.`);
    }

    // Validasi mimeType
    if (typeof item.mimeType !== 'string' || item.mimeType.trim().length === 0) {
      throw new ManifestConsistencyError(`Item lampiran #${idx} wajib memiliki mimeType non-kosong.`);
    }

    // Validasi fileSizeBytes
    if (
      typeof item.fileSizeBytes !== 'number' ||
      !Number.isInteger(item.fileSizeBytes) ||
      item.fileSizeBytes <= 0
    ) {
      throw new ManifestConsistencyError(`Item lampiran #${idx} memiliki fileSizeBytes tidak valid (${item.fileSizeBytes}).`);
    }

    if (item.fileSizeBytes > ATTACHMENT_CANONICAL.MAX_FILE_SIZE_BYTES) {
      throw new ManifestConsistencyError(
        `Item lampiran #${idx} melebihi kuota per-berkas (${item.fileSizeBytes} B > ${ATTACHMENT_CANONICAL.MAX_FILE_SIZE_BYTES} B).`
      );
    }

    totalCombinedBytes += item.fileSizeBytes;

    // Validasi totalChunks
    const expectedChunks = Math.ceil(item.fileSizeBytes / ATTACHMENT_CANONICAL.CHUNK_SIZE_BYTES);
    if (
      typeof item.totalChunks !== 'number' ||
      item.totalChunks < 1 ||
      item.totalChunks > ATTACHMENT_CANONICAL.MAX_CHUNKS_PER_FILE ||
      item.totalChunks !== expectedChunks
    ) {
      throw new ManifestConsistencyError(
        `Item lampiran #${idx} memiliki totalChunks tidak konsisten (deklarasi: ${item.totalChunks}, kalkulasi: ${expectedChunks}).`
      );
    }

    // Validasi fileHashSha256
    if (!item.fileHashSha256 || !SHA256_HEX_REGEX.test(item.fileHashSha256)) {
      throw new ManifestConsistencyError(
        `Item lampiran #${idx} memiliki fileHashSha256 tidak valid: '${item.fileHashSha256}'.`
      );
    }

    // Validasi opsional cekBase64 (jika disertakan wajib valid Base64 32-byte CEK)
    if (item.cekBase64 !== undefined) {
      if (typeof item.cekBase64 !== 'string' || item.cekBase64.trim().length === 0) {
        throw new ManifestConsistencyError(
          `Item lampiran #${idx} memiliki cekBase64 kosong atau tidak valid.`
        );
      }
      try {
        const decoded = atob(item.cekBase64);
        if (decoded.length !== ATTACHMENT_CANONICAL.CEK_LENGTH_BYTES) {
          throw new ManifestConsistencyError(
            `Item lampiran #${idx} memiliki panjang CEK tidak valid (${decoded.length} B, wajib ${ATTACHMENT_CANONICAL.CEK_LENGTH_BYTES} B).`
          );
        }
      } catch (err) {
        if (err instanceof ManifestConsistencyError) throw err;
        throw new ManifestConsistencyError(
          `Item lampiran #${idx} memiliki encoding Base64 cekBase64 tidak valid.`
        );
      }
    }
  }

  if (totalCombinedBytes > ATTACHMENT_CANONICAL.MAX_TOTAL_SIZE_BYTES) {
    throw new ManifestConsistencyError(
      `Total akumulatif seluruh lampiran (${totalCombinedBytes} B) melebihi kuota gabungan (${ATTACHMENT_CANONICAL.MAX_TOTAL_SIZE_BYTES} B).`
    );
  }

  return {
    version: '1.0.0',
    type: 'ATTACHMENT_MANIFEST',
    text: typeof m.text === 'string' ? m.text : undefined,
    attachments: m.attachments,
  };
}

/**
 * Type guard untuk memeriksa apakah sebuah nilai merupakan manifest lampiran yang valid.
 */
export function isAttachmentManifest(obj: unknown): obj is BchatAttachmentManifest {
  try {
    validateAttachmentManifest(obj);
    return true;
  } catch {
    return false;
  }
}

/**
 * Melakukan parsing string plaintext hasil dekripsi MLS.
 * Mengembalikan objek BchatAttachmentManifest yang tervalidasi atau null jika bukan manifest valid.
 */
export function parseAttachmentManifest(rawPlaintext: string): BchatAttachmentManifest | null {
  if (!rawPlaintext || typeof rawPlaintext !== 'string') {
    return null;
  }

  const trimmed = rawPlaintext.trim();
  if (!trimmed.startsWith('{') || !trimmed.endsWith('}')) {
    return null;
  }

  try {
    const parsed = JSON.parse(trimmed);
    return validateAttachmentManifest(parsed);
  } catch {
    return null;
  }
}

/**
 * Menserialisasi objek BchatAttachmentManifest menjadi JSON string kanonikal.
 */
export function formatAttachmentManifest(manifest: BchatAttachmentManifest): string {
  validateAttachmentManifest(manifest);
  return JSON.stringify(manifest);
}

/**
 * Format ukuran byte menjadi representasi manusiawi yang ramah (B, KB, MB).
 */
export function formatAttachmentSize(bytes: number): string {
  if (bytes <= 0) return '0 B';
  if (bytes < 1024) return `${bytes} B`;
  if (bytes < 1024 * 1024) return `${(bytes / 1024).toFixed(1)} KB`;
  return `${(bytes / (1024 * 1024)).toFixed(2)} MB`;
}

