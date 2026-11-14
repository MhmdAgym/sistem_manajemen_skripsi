<?php

declare(strict_types=1);

namespace App\ValueObjects;

/**
 * Immutable Value Object yang merepresentasikan hasil verifikasi Canonical Welcome ACK.
 *
 * Mengikuti pola factory konsisten dengan BchatPopVerificationResult:
 * - success(array $parsedData, string $payloadHash)
 * - failure(string $errorCode)
 *
 * Sesuai B-CHAT-22B Tahap 1B:
 * $parsedData HANYA berisi 8 komponen payload kanonikal selain Domain Separator.
 * Tidak memuat public_key_hex karena kunci publik bukan bagian dari payload 158 byte.
 */
final class BchatWelcomeAckVerificationResult
{
    /**
     * @param bool $isValid Status keberhasilan verifikasi
     * @param string|null $errorCode Kode error kanonis jika verifikasi gagal
     * @param array<string, mixed>|null $parsedData Delapan komponen kanonikal jika sukses
     * @param string|null $payloadHash SHA-256 hex 64-char dari buffer payload 158-byte jika sukses
     */
    private function __construct(
        private readonly bool $isValid,
        private readonly ?string $errorCode,
        private readonly ?array $parsedData,
        private readonly ?string $payloadHash
    ) {
    }

    /**
     * Pabrik hasil verifikasi sukses.
     *
     * @param array<string, mixed> $parsedData Delapan komponen kanonikal payload
     * @param string $payloadHash SHA-256 hex string dari buffer payload 158-byte
     */
    public static function success(array $parsedData, string $payloadHash): self
    {
        return new self(
            isValid: true,
            errorCode: null,
            parsedData: $parsedData,
            payloadHash: $payloadHash
        );
    }

    /**
     * Pabrik hasil verifikasi gagal.
     *
     * @param string $errorCode Kode error kanonis kegagalan
     */
    public static function failure(string $errorCode): self
    {
        return new self(
            isValid: false,
            errorCode: $errorCode,
            parsedData: null,
            payloadHash: null
        );
    }

    /**
     * Apakah verifikasi berhasil secara paripurna (kriptografis & integritas konteks).
     */
    public function isValid(): bool
    {
        return $this->isValid;
    }

    /**
     * Dapatkan kode error kanonis jika verifikasi gagal.
     */
    public function getErrorCode(): ?string
    {
        return $this->errorCode;
    }

    /**
     * Dapatkan 8 komponen kanonikal hasil parsing/verifikasi payload.
     *
     * @return array<string, mixed>|null
     */
    public function getParsedData(): ?array
    {
        return $this->parsedData;
    }

    /**
     * Dapatkan hash SHA-256 dari payload 158-byte yang telah diverifikasi.
     * Nilai ini siap disimpan ke kolom bchat_welcome_challenges.ack_payload_hash.
     */
    public function getPayloadHash(): ?string
    {
        return $this->payloadHash;
    }
}

