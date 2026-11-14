<?php

namespace App\ValueObjects;

final class BchatPopVerificationResult
{
    private function __construct(
        private readonly bool $isValid,
        private readonly ?string $errorCode,
        private readonly ?array $parsedData
    ) {
    }

    /**
     * Pabrik hasil verifikasi sukses.
     */
    public static function success(array $parsedData): self
    {
        return new self(
            isValid: true,
            errorCode: null,
            parsedData: $parsedData
        );
    }

    /**
     * Pabrik hasil verifikasi gagal.
     */
    public static function failure(string $errorCode): self
    {
        return new self(
            isValid: false,
            errorCode: $errorCode,
            parsedData: null
        );
    }

    public function isValid(): bool
    {
        return $this->isValid;
    }

    public function getErrorCode(): ?string
    {
        return $this->errorCode;
    }

    public function getParsedData(): ?array
    {
        return $this->parsedData;
    }
}

