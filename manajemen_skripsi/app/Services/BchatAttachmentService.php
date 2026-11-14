<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\BchatAttachmentException;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Service fondasi penyimpanan ciphertext lampiran E2EE (B-CHAT EXP-02 / Tahap 1 Backend).
 *
 * Tanggung Jawab:
 * - Menentukan lokasi penyimpanan privat Laravel (storage/app/private/bchat_attachments/...).
 * - Menyimpan byte ciphertext biner secara aman ke disk privat terisolasi.
 * - Memverifikasi hash SHA-256 fisik ciphertext at-rest (Lapisan L-3 Storage Byte Integrity).
 * - Membaca ciphertext untuk kebutuhan pengunduhan/backend berikutnya.
 * - Menghindari eksposisi direktori atau URL publik langsung.
 * - ZERO PLAINTEXT KNOWLEDGE: Tidak mengakses CEK, tidak mengenkripsi/mendekripsi,
 *   dan tidak memproses nama berkas/MIME plaintext.
 */
class BchatAttachmentService
{
    public function __construct(
        protected readonly string $diskName = 'local'
    ) {
    }

    /**
     * Menghasilkan path penyimpanan kanonikal berbasis hash content-addressable:
     * bchat_attachments/{hash_prefix_2}/{storage_hash_sha256}.bin
     * sesuai spesifikasi migration 2026_11_07_000012_create_bchat_attachment_chunks_table.
     */
    public function generateStoragePath(string $storageHashSha256): string
    {
        $cleanHash = strtolower(trim($storageHashSha256));
        if (!preg_match('/^[0-9a-f]{64}$/', $cleanHash)) {
            throw new BchatAttachmentException(
                'Hash SHA-256 tidak valid untuk penentuan storage path.',
                'INVALID_HASH_FORMAT',
                422
            );
        }

        $prefix = substr($cleanHash, 0, 2);
        return "bchat_attachments/{$prefix}/{$cleanHash}.bin";
    }

    /**
     * Menyimpan potongan chunk ciphertext biner ke disk privat Laravel.
     *
     * @param string $rawCiphertextBytes Byte biner mentah chunk (Format A: Nonce || Cipher || Tag)
     * @param string|null $expectedHashSha256 Hash ekspektasi (jika dikirim oleh pengunggah untuk verifikasi)
     * @return array{storage_path: string, storage_hash_sha256: string, payload_length: int}
     *
     * @throws BchatAttachmentException Jika payload kosong, hash tidak cocok, atau gagal menulis disk
     */
    public function storeCiphertextChunk(string $rawCiphertextBytes, ?string $expectedHashSha256 = null): array
    {
        $payloadLength = strlen($rawCiphertextBytes);
        if ($payloadLength === 0) {
            throw new BchatAttachmentException(
                'Payload ciphertext biner kosong (0 byte ditolak mutlak).',
                'EMPTY_CIPHERTEXT_PAYLOAD',
                422
            );
        }

        // Kalkulasi hash SHA-256 fisik dari raw byte
        $calculatedHash = hash('sha256', $rawCiphertextBytes);

        if ($expectedHashSha256 !== null) {
            $cleanExpected = strtolower(trim($expectedHashSha256));
            if (!hash_equals($cleanExpected, $calculatedHash)) {
                throw new BchatAttachmentException(
                    'Hash SHA-256 fisik payload tidak cocok dengan hash yang diekspektasikan.',
                    'STORAGE_HASH_MISMATCH',
                    422
                );
            }
        }

        $storagePath = $this->generateStoragePath($calculatedHash);

        try {
            $disk = Storage::disk($this->diskName);
            $written = $disk->put($storagePath, $rawCiphertextBytes);

            if (!$written) {
                throw new BchatAttachmentException(
                    'Gagal menuliskan file ciphertext ke storage privat.',
                    'STORAGE_WRITE_FAILED',
                    500
                );
            }

            return [
                'storage_path'        => $storagePath,
                'storage_hash_sha256' => $calculatedHash,
                'payload_length'      => $payloadLength,
            ];
        } catch (BchatAttachmentException $e) {
            throw $e;
        } catch (Throwable $e) {
            throw new BchatAttachmentException(
                'Kesalahan sistem penyimpanan saat menyimpan ciphertext chunk: ' . $e->getMessage(),
                'STORAGE_WRITE_EXCEPTION',
                500,
                $e
            );
        }
    }

    /**
     * Membaca potongan chunk ciphertext biner dari disk privat Laravel.
     *
     * @param string $storagePath Path relatif storage yang tersimpan di database
     * @return string Raw ciphertext bytes
     *
     * @throws BchatAttachmentException Jika path tidak aman, file tidak ditemukan, atau gagal dibaca
     */
    public function getCiphertextChunk(string $storagePath): string
    {
        $cleanPath = $this->sanitizeStoragePath($storagePath);

        try {
            $disk = Storage::disk($this->diskName);
            if (!$disk->exists($cleanPath)) {
                throw new BchatAttachmentException(
                    'Berkas chunk ciphertext tidak ditemukan pada media penyimpanan.',
                    'STORAGE_FILE_NOT_FOUND',
                    404
                );
            }

            $bytes = $disk->get($cleanPath);
            if ($bytes === null) {
                throw new BchatAttachmentException(
                    'Gagal membaca aliran byte dari media penyimpanan.',
                    'STORAGE_READ_FAILED',
                    500
                );
            }

            return $bytes;
        } catch (BchatAttachmentException $e) {
            throw $e;
        } catch (Throwable $e) {
            throw new BchatAttachmentException(
                'Kesalahan sistem penyimpanan saat membaca ciphertext chunk: ' . $e->getMessage(),
                'STORAGE_READ_EXCEPTION',
                500,
                $e
            );
        }
    }

    /**
     * Memverifikasi keberadaan chunk di disk privat.
     */
    public function hasChunk(string $storagePath): bool
    {
        $cleanPath = $this->sanitizeStoragePath($storagePath);
        return Storage::disk($this->diskName)->exists($cleanPath);
    }

    /**
     * Memverifikasi integritas fisik chunk at-rest (L-3 Storage Byte Integrity).
     */
    public function verifyChunkIntegrity(string $storagePath, string $expectedHash): bool
    {
        $bytes = $this->getCiphertextChunk($storagePath);
        $actualHash = hash('sha256', $bytes);
        return hash_equals(strtolower(trim($expectedHash)), $actualHash);
    }

    /**
     * Menghapus chunk ciphertext secara fisik dari disk privat jika ada.
     */
    public function deleteChunk(string $storagePath): bool
    {
        $cleanPath = $this->sanitizeStoragePath($storagePath);
        $disk = Storage::disk($this->diskName);
        if ($disk->exists($cleanPath)) {
            return $disk->delete($cleanPath);
        }
        return false;
    }

    /**
     * Validasi mitigasi path traversal untuk mencegah akses di luar direktori kanonikal.
     */
    public function sanitizeStoragePath(string $path): string
    {
        $clean = trim(str_replace('\\', '/', $path));

        // Larang path traversal, path absolut Windows/Unix, atau null-byte injection
        if (
            str_contains($clean, '..') ||
            str_starts_with($clean, '/') ||
            str_contains($clean, ':') ||
            str_contains($clean, "\0")
        ) {
            throw new BchatAttachmentException(
                'Akses path tidak sah terdeteksi (path traversal prohibited).',
                'INVALID_STORAGE_PATH',
                400
            );
        }

        // Wajib berada di dalam direktori bchat_attachments/
        if (!str_starts_with($clean, 'bchat_attachments/')) {
            throw new BchatAttachmentException(
                'Storage path berada di luar direktori aman lampiran.',
                'INVALID_STORAGE_DIRECTORY',
                400
            );
        }

        return $clean;
    }
}

