/**
 * B-CHAT E2EE — Attachment Transfer Coordinator & REST API Transport Integration
 * Sesuai B-CHAT-08A.5, B-CHAT-08C.4, B-CHAT-08D.2, dan Kontrak API Tahap 2
 *
 * Mengintegrasikan:
 * 1. Derivasi CEK melalui Worker RPC DERIVE_ATTACHMENT_KEY (atau kunci yang disuplai).
 * 2. Validasi kuota berkas dan partisi chunking (1 MiB, max 10 chunk per berkas).
 * 3. Enkripsi AES-256-GCM dengan framing Format A (28 B overhead per chunk).
 * 4. Panggilan 4 endpoint REST API backend:
 *    - POST /api/bchat/rooms/{roomUuid}/attachments/upload-session
 *    - PUT /api/bchat/rooms/{roomUuid}/attachments/upload-session/{sessionUuid}/chunks/{chunkIndex}
 *    - POST /api/bchat/rooms/{roomUuid}/attachments/upload-session/{sessionUuid}/complete
 *    - GET /api/bchat/rooms/{roomUuid}/attachments/{attachmentUuid}/chunks/{chunkIndex}
 * 5. Perakitan dan dekripsi di sisi penerima tanpa pernah mengekspos plaintext ke server.
 */

import axios from '@/lib/axios';
import {
  ATTACHMENT_CANONICAL,
  AttachmentUploadError,
} from './bchat-attachment-constants';
import { serializeAad, uuidToBytes } from './bchat-attachment-aad';
import {
  constructNonce,
  generateSessionSalt,
  importCek,
  encryptChunk,
  computeSha256,
} from './bchat-attachment-crypto';
import {
  validateAttachmentQuotas,
  calculateChunkMetadata,
  slicePlaintextIntoChunks,
} from './bchat-attachment-chunker';
import { AttachmentAssembler } from './bchat-attachment-assembler';
import type { BchatWorkerClient } from '../bchat-poc-rpc';

// ---------------------------------------------------------------------------
// Tipe Data REST API Transport
// ---------------------------------------------------------------------------

export interface CreateUploadSessionResponseData {
  session_uuid: string;
  attachment_uuid: string;
  room_uuid: string;
  total_chunks: number;
  ciphertext_bytes: number;
  plaintext_bytes: number;
  status: string;
  expires_at: string;
}

export interface UploadChunkResponseData {
  session_uuid: string;
  attachment_uuid: string;
  chunk_index: number;
  payload_length: number;
  storage_hash_sha256: string;
  is_replayed: boolean;
}

export interface CompleteUploadSessionResponseData {
  session_uuid: string;
  attachment_uuid: string;
  room_uuid: string;
  status: string;
  total_chunks: number;
  ciphertext_bytes: number;
  plaintext_bytes: number;
  file_hash_sha256: string;
}

export interface UploadAttachmentParams {
  roomUuid: string;
  messageUuid: string;
  attachmentUuid: string;
  fileBytes: Uint8Array;
  fileName?: string;
  workerClient?: BchatWorkerClient;
  preDerivedCekBytes?: Uint8Array;
  onProgress?: (progress: { chunkIndex: number; totalChunks: number; percent: number }) => void;
  maxRetriesPerChunk?: number;
}

export interface UploadAttachmentResult {
  sessionUuid: string;
  attachmentUuid: string;
  roomUuid: string;
  saltHex: string;
  totalChunks: number;
  plaintextBytes: number;
  ciphertextBytes: number;
  fileHashSha256: string;
  cekBytes: Uint8Array;
}

export interface DownloadAttachmentParams {
  roomUuid: string;
  messageUuid: string;
  attachmentUuid: string;
  uploadSessionId: string;
  totalChunks: number;
  expectedTotalSize: number;
  expectedFileSha256?: string;
  workerClient?: BchatWorkerClient;
  preDerivedCekBytes?: Uint8Array;
  onProgress?: (progress: { chunkIndex: number; totalChunks: number; percent: number }) => void;
}

// ---------------------------------------------------------------------------
// Low-Level API Calls
// ---------------------------------------------------------------------------

export async function apiCreateUploadSession(
  roomUuid: string,
  payload: {
    attachment_uuid: string;
    total_chunks: number;
    plaintext_bytes: number;
    ciphertext_bytes: number;
    file_hash_sha256: string;
  }
): Promise<CreateUploadSessionResponseData> {
  try {
    const res = await axios.post<{ status: string; data: CreateUploadSessionResponseData }>(
      `/api/bchat/rooms/${roomUuid}/attachments/upload-session`,
      payload
    );
    return res.data.data;
  } catch (err: any) {
    const message = err.response?.data?.message || err.message || 'Gagal membuat sesi unggah lampiran.';
    const code = err.response?.data?.error_code || 'CREATE_SESSION_FAILED';
    throw new AttachmentUploadError(code, message);
  }
}

export async function apiUploadChunk(
  roomUuid: string,
  sessionUuid: string,
  chunkIndex: number,
  framedChunkBytes: Uint8Array,
  payloadHash?: string
): Promise<UploadChunkResponseData> {
  try {
    const headers: Record<string, string> = {
      'Content-Type': 'application/octet-stream',
    };
    if (payloadHash) {
      headers['X-Payload-Hash'] = payloadHash;
    }

    const res = await axios.put<{ status: string; data: UploadChunkResponseData }>(
      `/api/bchat/rooms/${roomUuid}/attachments/upload-session/${sessionUuid}/chunks/${chunkIndex}`,
      framedChunkBytes,
      {
        headers,
        transformRequest: [(data) => data], // Jangan ubah Uint8Array ke JSON atau string
      }
    );
    return res.data.data;
  } catch (err: any) {
    const message = err.response?.data?.message || err.message || `Gagal mengunggah chunk #${chunkIndex}.`;
    const code = err.response?.data?.error_code || 'UPLOAD_CHUNK_FAILED';
    throw new AttachmentUploadError(code, message);
  }
}

export async function apiCompleteUploadSession(
  roomUuid: string,
  sessionUuid: string,
  fileHashSha256?: string
): Promise<CompleteUploadSessionResponseData> {
  try {
    const payload = fileHashSha256 ? { file_hash_sha256: fileHashSha256 } : {};
    const res = await axios.post<{ status: string; data: CompleteUploadSessionResponseData }>(
      `/api/bchat/rooms/${roomUuid}/attachments/upload-session/${sessionUuid}/complete`,
      payload
    );
    return res.data.data;
  } catch (err: any) {
    const message = err.response?.data?.message || err.message || 'Gagal memfinalisasi sesi unggah.';
    const code = err.response?.data?.error_code || 'COMPLETE_SESSION_FAILED';
    throw new AttachmentUploadError(code, message);
  }
}

export async function apiDownloadChunk(
  roomUuid: string,
  attachmentUuid: string,
  chunkIndex: number
): Promise<Uint8Array> {
  try {
    const res = await axios.get(
      `/api/bchat/rooms/${roomUuid}/attachments/${attachmentUuid}/chunks/${chunkIndex}`,
      {
        responseType: 'arraybuffer',
      }
    );
    return new Uint8Array(res.data);
  } catch (err: any) {
    const message = err.response?.data?.message || err.message || `Gagal mengunduh chunk #${chunkIndex}.`;
    const code = err.response?.data?.error_code || 'DOWNLOAD_CHUNK_FAILED';
    throw new AttachmentUploadError(code, message);
  }
}

// ---------------------------------------------------------------------------
// Koordinator Tingkat Tinggi (High-Level Coordinator)
// ---------------------------------------------------------------------------

export class BchatAttachmentCoordinator {
  /**
   * Alur Lengkap Pengunggahan Lampiran E2EE:
   * 1. Validasi kuota berkas
   * 2. Derivasi CEK via Worker RPC (atau kunci yang disediakan)
   * 3. Slicing plaintext ke chunk 1 MiB
   * 4. Inisiasi sesi upload di backend
   * 5. Enkripsi chunk (Format A) & Upload dengan dukungan retry idempotent
   * 6. Finalisasi sesi upload di backend
   */
  public static async uploadAttachment(params: UploadAttachmentParams): Promise<UploadAttachmentResult> {
    const {
      roomUuid,
      messageUuid,
      attachmentUuid,
      fileBytes,
      workerClient,
      preDerivedCekBytes,
      onProgress,
      maxRetriesPerChunk = 2,
    } = params;

    // 1. Validasi Kuota Berkas
    validateAttachmentQuotas([{ size: fileBytes.length }]);
    const meta = calculateChunkMetadata(fileBytes.length);

    // 2. Derivasi CEK 256-bit
    let cekRawBytes: Uint8Array;
    if (preDerivedCekBytes) {
      if (preDerivedCekBytes.length !== ATTACHMENT_CANONICAL.CEK_LENGTH_BYTES) {
        throw new Error(
          `preDerivedCekBytes wajib 32 byte, diterima: ${preDerivedCekBytes.length}`
        );
      }
      cekRawBytes = preDerivedCekBytes;
    } else if (workerClient) {
      const deriveRes = await workerClient.deriveAttachmentKey({
        roomUuid,
        messageUuid,
        attachmentUuid,
      });
      // Decode base64 CEK
      const binaryStr = atob(deriveRes.cekBase64);
      cekRawBytes = new Uint8Array(binaryStr.length);
      for (let i = 0; i < binaryStr.length; i++) {
        cekRawBytes[i] = binaryStr.charCodeAt(i);
      }
    } else {
      throw new Error(
        'Upload lampiran membutuhkan workerClient aktif atau preDerivedCekBytes untuk menurunkan kunci CEK.'
      );
    }

    const cryptoKey = await importCek(cekRawBytes);

    // 3. Bangkitkan UploadSessionSalt (8 byte CSPRNG) & Hitung Metadata Sesi
    const sessionSalt = generateSessionSalt();
    const saltHex = Array.from(sessionSalt)
      .map((b) => b.toString(16).padStart(2, '0'))
      .join('');
    const fileHashSha256 = await computeSha256(fileBytes);
    const plaintextBytes = fileBytes.length;
    const ciphertextBytes = plaintextBytes + meta.totalChunks * ATTACHMENT_CANONICAL.FRAMING_OVERHEAD_BYTES;

    // 4. Inisiasi Sesi Unggah di Backend Laravel
    const sessionData = await apiCreateUploadSession(roomUuid, {
      attachment_uuid: attachmentUuid,
      total_chunks: meta.totalChunks,
      plaintext_bytes: plaintextBytes,
      ciphertext_bytes: ciphertextBytes,
      file_hash_sha256: fileHashSha256,
    });

    const sessionUuid = sessionData.session_uuid;

    // 5. Pecah Plaintext Menjadi Chunk
    const plaintextChunks = slicePlaintextIntoChunks(fileBytes);

    // 6. Enkripsi dan Unggah Tiap Chunk
    for (let i = 0; i < meta.totalChunks; i++) {
      const chunkPt = plaintextChunks[i];
      const nonce = constructNonce(sessionSalt, i);
      const aad = serializeAad({
        roomUuid,
        messageUuid,
        attachmentUuid,
        uploadSessionId: sessionUuid,
        chunkIndex: i,
        totalChunks: meta.totalChunks,
        plaintextLength: chunkPt.length,
      });

      // Enkripsi chunk sekali ke Framing Format A
      const framedChunk = await encryptChunk(
        cryptoKey,
        nonce,
        chunkPt,
        aad,
        `${attachmentUuid}:${saltHex}`
      );
      const chunkHash = await computeSha256(framedChunk);

      // Upload dengan mekanisme retry pada ciphertext yang sama persis (tanpa re-enkripsi / tanpa ubah nonce)
      let uploaded = false;
      let lastErr: any = null;

      for (let attempt = 0; attempt <= maxRetriesPerChunk; attempt++) {
        try {
          await apiUploadChunk(roomUuid, sessionUuid, i, framedChunk, chunkHash);
          uploaded = true;
          break;
        } catch (err: any) {
          lastErr = err;
          // Jika terjadi error, coba lagi pada attempt berikutnya
        }
      }

      if (!uploaded) {
        throw new AttachmentUploadError(
          'CHUNK_UPLOAD_RETRY_EXHAUSTED',
          `Gagal mengunggah chunk #${i} setelah ${maxRetriesPerChunk + 1} percobaan: ${lastErr?.message}`
        );
      }

      if (onProgress) {
        onProgress({
          chunkIndex: i,
          totalChunks: meta.totalChunks,
          percent: Math.round(((i + 1) / meta.totalChunks) * 100),
        });
      }
    }

    // 7. Finalisasi Sesi Unggah di Backend
    await apiCompleteUploadSession(roomUuid, sessionUuid, fileHashSha256);

    return {
      sessionUuid,
      attachmentUuid,
      roomUuid,
      saltHex,
      totalChunks: meta.totalChunks,
      plaintextBytes,
      ciphertextBytes,
      fileHashSha256,
      cekBytes: cekRawBytes,
    };
  }

  /**
   * Alur Lengkap Pengunduhan dan Dekripsi Lampiran E2EE:
   * 1. Derivasi CEK via Worker RPC (atau kunci yang disediakan)
   * 2. Unduh seluruh ciphertext chunk dari backend
   * 3. Rekonstruksi dan verifikasi autentikasi AEAD klien
   * 4. Verifikasi hash SHA-256 berkas utuh
   */
  public static async downloadAttachment(params: DownloadAttachmentParams): Promise<Uint8Array> {
    const {
      roomUuid,
      messageUuid,
      attachmentUuid,
      uploadSessionId,
      totalChunks,
      expectedTotalSize,
      expectedFileSha256,
      workerClient,
      preDerivedCekBytes,
      onProgress,
    } = params;

    // 1. Derivasi CEK 256-bit
    let cekRawBytes: Uint8Array;
    if (preDerivedCekBytes) {
      if (preDerivedCekBytes.length !== ATTACHMENT_CANONICAL.CEK_LENGTH_BYTES) {
        throw new Error(
          `preDerivedCekBytes wajib 32 byte, diterima: ${preDerivedCekBytes.length}`
        );
      }
      cekRawBytes = preDerivedCekBytes;
    } else if (workerClient) {
      const deriveRes = await workerClient.deriveAttachmentKey({
        roomUuid,
        messageUuid,
        attachmentUuid,
      });
      const binaryStr = atob(deriveRes.cekBase64);
      cekRawBytes = new Uint8Array(binaryStr.length);
      for (let i = 0; i < binaryStr.length; i++) {
        cekRawBytes[i] = binaryStr.charCodeAt(i);
      }
    } else {
      throw new Error(
        'Download lampiran membutuhkan workerClient aktif atau preDerivedCekBytes untuk menurunkan kunci CEK.'
      );
    }

    const cryptoKey = await importCek(cekRawBytes);

    // 2. Unduh Seluruh Chunk Ciphertext dari Backend
    const incomingChunks: Array<{ framedChunk: Uint8Array; aad: Uint8Array }> = [];
    const meta = calculateChunkMetadata(expectedTotalSize);

    if (meta.totalChunks !== totalChunks) {
      throw new Error(
        `Inkonsistensi metadata: totalChunks deklarasi (${totalChunks}) != kalkulasi ukuran (${meta.totalChunks})`
      );
    }

    for (let i = 0; i < totalChunks; i++) {
      const framedChunk = await apiDownloadChunk(roomUuid, attachmentUuid, i);

      // Susun AAD untuk verifikasi
      const expectedPlaintextLength = meta.chunkLengths[i];
      const aad = serializeAad({
        roomUuid,
        messageUuid,
        attachmentUuid,
        uploadSessionId,
        chunkIndex: i,
        totalChunks,
        plaintextLength: expectedPlaintextLength,
      });

      incomingChunks.push({ framedChunk, aad });

      if (onProgress) {
        onProgress({
          chunkIndex: i,
          totalChunks,
          percent: Math.round(((i + 1) / totalChunks) * 100),
        });
      }
    }

    // 3. Perakitan dan Dekripsi Klien (Memvalidasi Tag AEAD dan Integritas Makro)
    const assembledFile = await AttachmentAssembler.assembleAndDecrypt({
      cryptoKey,
      incomingChunks,
      manifest: {
        expectedTotalSize,
        totalChunks,
        chunkLengths: meta.chunkLengths,
        expectedFileSha256,
      },
    });

    // 4. Verifikasi Hash SHA-256 Berkas Akhir Jika Tersedia
    if (expectedFileSha256) {
      const calculatedHash = await computeSha256(assembledFile);
      if (calculatedHash.toLowerCase() !== expectedFileSha256.toLowerCase()) {
        throw new Error(
          `Integritas berkas terganggu: hash SHA-256 hasil rekonsiliasi (${calculatedHash}) != ekspektasi (${expectedFileSha256})`
        );
      }
    }

    return assembledFile;
  }
}

