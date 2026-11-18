<?php

namespace App\Services;

use Exception;
use InvalidArgumentException;
use Ramsey\Uuid\Uuid;

class BchatWsAuthVerificationService
{
    public const DOMAIN_SEPARATOR = "UNUHA-BCHAT-WS-AUTH-V1\0";
    public const PAYLOAD_LENGTH = 151;
    public const SIGNATURE_LENGTH = 64;

    /**
     * Membangun payload kanonikal biner 151-byte untuk penandatanganan autentikasi WebSocket Reverb.
     *
     * Format Buffer Kanonis (151 byte):
     * - Offset 0..22   : Domain Separator "UNUHA-BCHAT-WS-AUTH-V1" (23 byte ASCII)
     * - Offset 23..30  : user_id uint64 big-endian (8 byte)
     * - Offset 31..46  : device_uuid biner (16 byte)
     * - Offset 47..78  : SHA-256(socket_id) biner (32 byte)
     * - Offset 79..110 : SHA-256(channel_name) biner (32 byte)
     * - Offset 111..142: challenge_nonce biner (32 byte)
     * - Offset 143..150: expires_at_timestamp uint64 big-endian (8 byte)
     */
    public static function buildCanonicalPayload(
        int $userId,
        string $deviceUuid,
        string $socketId,
        string $channelName,
        string $nonceHex,
        int $expiresAtTimestamp
    ): string {
        if ($userId <= 0) {
            throw new InvalidArgumentException('User ID harus bilangan bulat positif.');
        }

        $cleanDeviceUuid = strtolower(trim($deviceUuid));
        if (!Uuid::isValid($cleanDeviceUuid)) {
            throw new InvalidArgumentException('Device UUID tidak valid.');
        }
        $deviceUuidBytes = hex2bin(str_replace('-', '', $cleanDeviceUuid));

        $cleanSocketId = trim($socketId);
        if ($cleanSocketId === '') {
            throw new InvalidArgumentException('Socket ID tidak boleh kosong.');
        }
        $socketIdHash = hash('sha256', $cleanSocketId, true);

        $cleanChannelName = trim($channelName);
        if ($cleanChannelName === '') {
            throw new InvalidArgumentException('Channel Name tidak boleh kosong.');
        }
        $channelNameHash = hash('sha256', $cleanChannelName, true);

        $cleanNonce = strtolower(trim($nonceHex));
        if (strlen($cleanNonce) !== 64 || !ctype_xdigit($cleanNonce)) {
            throw new InvalidArgumentException('Challenge nonce harus 64 karakter hex (32 byte).');
        }
        $nonceBytes = hex2bin($cleanNonce);

        if ($expiresAtTimestamp <= 0) {
            throw new InvalidArgumentException('Timestamp kedaluwarsa tidak valid.');
        }

        $userIdBytes = pack('J', $userId);
        $timestampBytes = pack('J', $expiresAtTimestamp);

        $payload = self::DOMAIN_SEPARATOR
            . $userIdBytes
            . $deviceUuidBytes
            . $socketIdHash
            . $channelNameHash
            . $nonceBytes
            . $timestampBytes;

        if (strlen($payload) !== self::PAYLOAD_LENGTH) {
            throw new InvalidArgumentException(
                sprintf('Panjang payload kanonis invalid: %d byte (diharapkan %d byte).', strlen($payload), self::PAYLOAD_LENGTH)
            );
        }

        return $payload;
    }

    /**
     * Memverifikasi tanda tangan detached Ed25519 terhadap payload kanonikal 151-byte.
     */
    public static function verifySignature(
        string $payloadBinary,
        string $signatureBinary,
        string $publicKeyHex
    ): bool {
        if (strlen($payloadBinary) !== self::PAYLOAD_LENGTH) {
            return false;
        }

        if (strlen($signatureBinary) !== self::SIGNATURE_LENGTH) {
            return false;
        }

        $cleanPublicKey = strtolower(trim($publicKeyHex));
        if (strlen($cleanPublicKey) !== 64 || !ctype_xdigit($cleanPublicKey)) {
            return false;
        }

        $publicKeyBinary = hex2bin($cleanPublicKey);

        if (!function_exists('sodium_crypto_sign_verify_detached')) {
            throw new Exception('Ekstensi libsodium tidak tersedia pada lingkungan PHP runtime.');
        }

        return sodium_crypto_sign_verify_detached($signatureBinary, $payloadBinary, $publicKeyBinary);
    }
}
