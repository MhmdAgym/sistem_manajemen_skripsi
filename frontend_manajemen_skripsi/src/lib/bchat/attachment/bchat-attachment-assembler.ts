/**
 * B-CHAT E2EE — Attachment Assembler & Staging Security Manager
 * Sesuai B-CHAT-08A.1 §6, B-CHAT-08A.4 §4, B-CHAT-08C.4, B-CHAT-08D.2 §4
 *
 * Menangani Rekonstruksi Out-of-Order, Validasi Manifest, Deteksi Gap / Duplikasi,
 * dan Penyusunan Kembali Berkas Utuh.
 */

import {
  AssemblyError,
  ManifestConsistencyError,
} from './bchat-attachment-constants';
import { parseAad, type ParsedAad } from './bchat-attachment-aad';
import { decryptChunk } from './bchat-attachment-crypto';

export interface ChunkAssemblyItem {
  framedChunk: Uint8Array;
  aad: Uint8Array;
}

export interface ManifestVerificationData {
  expectedTotalSize: number;
  totalChunks: number;
  chunkLengths?: number[];
  expectedFileSha256?: string;
}

export class AttachmentAssembler {
  /**
   * Validasi konsistensi manifest berkas sebelum perakitan.
   */
  public static validateManifest(manifest: ManifestVerificationData): boolean {
    const { expectedTotalSize, totalChunks, chunkLengths } = manifest;

    if (chunkLengths) {
      if (!Array.isArray(chunkLengths) || chunkLengths.length !== totalChunks) {
        throw new ManifestConsistencyError(
          `Panjang daftar chunk (${chunkLengths ? chunkLengths.length : 0}) tidak sama dengan Total Chunks N (${totalChunks})`
        );
      }

      const sumL = chunkLengths.reduce((acc, curr) => acc + curr, 0);
      if (sumL !== expectedTotalSize) {
        throw new ManifestConsistencyError(
          `Manifest inkonsisten: jumlah seluruh panjang chunk (${sumL} B) != total ukuran berkas (${expectedTotalSize} B)`
        );
      }
    }

    return true;
  }

  /**
   * Perakitan dan Dekripsi Kumpulan Chunk Terenkripsi.
   * Mendukung chunk yang tiba acak / terbalik (Transport Reordering).
   */
  public static async assembleAndDecrypt(params: {
    cryptoKey: CryptoKey;
    incomingChunks: ChunkAssemblyItem[];
    manifest?: ManifestVerificationData;
  }): Promise<Uint8Array> {
    const { cryptoKey, incomingChunks, manifest } = params;

    if (!Array.isArray(incomingChunks) || incomingChunks.length === 0) {
      throw new AssemblyError('EmptyChunkStream', 'Aliran chunk kosong.');
    }

    if (manifest) {
      AttachmentAssembler.validateManifest(manifest);
    }

    // 1. Parsing AAD dan Deteksi Duplikasi serta Validasi Total Chunks
    const parsedItems: Array<{
      framedChunk: Uint8Array;
      aad: Uint8Array;
      parsedAad: ParsedAad;
      chunkIndex: number;
    }> = [];
    const seenIndices = new Set<number>();
    let totalChunksN: number | null = null;
    let expectedAttachmentUuid: string | null = null;

    for (let i = 0; i < incomingChunks.length; i++) {
      const item = incomingChunks[i];
      const parsed = parseAad(item.aad);

      if (totalChunksN === null) {
        totalChunksN = parsed.totalChunks;
        expectedAttachmentUuid = parsed.attachmentUuid;
      } else {
        if (parsed.totalChunks !== totalChunksN) {
          throw new AssemblyError('TotalChunksMismatch', 'Inkonsistensi Total Chunks N antar chunk dalam satu berkas.');
        }
        if (parsed.attachmentUuid !== expectedAttachmentUuid) {
          throw new AssemblyError('AttachmentUuidMismatch', 'Inkonsistensi attachmentUuid antar chunk.');
        }
      }

      // Deteksi Chunk Duplikat
      if (seenIndices.has(parsed.chunkIndex)) {
        throw new AssemblyError(
          'DuplicateChunkIndex',
          `Chunk dengan indeks ${parsed.chunkIndex} muncul lebih dari satu kali!`
        );
      }
      seenIndices.add(parsed.chunkIndex);

      parsedItems.push({
        framedChunk: item.framedChunk,
        aad: item.aad,
        parsedAad: parsed,
        chunkIndex: parsed.chunkIndex,
      });
    }

    if (totalChunksN === null) {
      throw new AssemblyError('EmptyChunkStream', 'Gagal menentukan jumlah total chunk.');
    }

    // 2. Pemeriksaan Kelengkapan Seluruh Chunk (0 s.d. N - 1)
    if (parsedItems.length !== totalChunksN) {
      // Periksa apakah chunk terakhir yang hilang (terpotong)
      if (!seenIndices.has(totalChunksN - 1)) {
        throw new AssemblyError(
          'TruncatedFileError',
          `Chunk terakhir (${totalChunksN - 1}) tidak ditemukan dalam aliran data (berkas terpotong).`
        );
      }
      // Chunk di tengah yang hilang
      for (let idx = 0; idx < totalChunksN; idx++) {
        if (!seenIndices.has(idx)) {
          throw new AssemblyError(
            'MissingChunkError',
            `Chunk dengan indeks ${idx} hilang dari urutan [0..${totalChunksN - 1}].`
          );
        }
      }
    }

    // 3. Urutkan item berdasarkan chunkIndex ascending (0, 1, 2, ..., N - 1)
    parsedItems.sort((a, b) => a.chunkIndex - b.chunkIndex);

    // 4. Dekripsi setiap chunk secara sekuensial dan validasi panjang plaintext
    const decryptedChunks: Uint8Array[] = [];
    let accumulatedPlaintextSize = 0;

    for (const item of parsedItems) {
      const decryptedPlaintext = await decryptChunk(cryptoKey, item.framedChunk, item.aad);

      // Verifikasi panjang plaintext chunk harus tepat sesuai deklarasi pada AAD
      if (decryptedPlaintext.length !== item.parsedAad.plaintextLength) {
        throw new AssemblyError(
          'PlaintextLengthMismatch',
          `Panjang hasil dekripsi chunk #${item.chunkIndex} (${decryptedPlaintext.length} B) != deklarasi AAD (${item.parsedAad.plaintextLength} B).`
        );
      }

      decryptedChunks.push(decryptedPlaintext);
      accumulatedPlaintextSize += decryptedPlaintext.length;
    }

    // 5. Validasi total ukuran akumulatif terhadap manifest jika ada
    if (manifest && accumulatedPlaintextSize !== manifest.expectedTotalSize) {
      throw new AssemblyError(
        'TotalSizeMismatch',
        `Total ukuran berkas setelah dirakit (${accumulatedPlaintextSize} B) != ekspektasi manifest (${manifest.expectedTotalSize} B).`
      );
    }

    // 6. Alokasi buffer tunggal dan gabungkan seluruh chunk menjadi berkas utuh
    const assembledFile = new Uint8Array(accumulatedPlaintextSize);
    let writeOffset = 0;
    for (const chunk of decryptedChunks) {
      assembledFile.set(chunk, writeOffset);
      writeOffset += chunk.length;
    }

    return assembledFile;
  }
}

