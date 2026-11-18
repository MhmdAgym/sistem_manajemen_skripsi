<?php

namespace App\Services;

use App\Exceptions\BchatHistoryTransferException;
use App\Models\BchatUserDevice;
use Exception;
use Throwable;

class BchatHistoryTransferVerificationService
{
    public const MAGIC_REQUEST = 'UNUHA-BCHAT-TRANSFER-REQ-V1';
    public const MAGIC_SNAPSHOT = 'UNUHA-BCHAT-TRANSFER-SNAPSHOT-V1';
    public const MAGIC_COMPLETION = 'UNUHA-BCHAT-TRANSFER-ACK-V1';

    public const SIGNATURE_LENGTH = 64;

    /**
     * Membangun payload kanonikal biner untuk permintaan transfer riwayat oleh perangkat penerima.
     */
    public static function buildRequestPayload(
        int $userId,
        string $roomUuid,
        string $recipientDeviceUuid,
        string $sourceDeviceUuid,
        string $nonceHex,
        string $recipientEphemeralPublicKey
    ): string {
        $cleanRoomUuid      = strtolower(str_replace('-', '', $roomUuid));
        $cleanRecipientUuid = strtolower(str_replace('-', '', $recipientDeviceUuid));
        $cleanSourceUuid    = strtolower(str_replace('-', '', $sourceDeviceUuid));
        $cleanNonce         = strtolower(trim($nonceHex));

        if (strlen($cleanRoomUuid) !== 32 || strlen($cleanRecipientUuid) !== 32 || strlen($cleanSourceUuid) !== 32) {
            throw new BchatHistoryTransferException('Format UUID tidak valid untuk konstruksi payload transfer.', 'INVALID_UUID_FORMAT', 422);
        }

        if (strlen($cleanNonce) !== 64 || !ctype_xdigit($cleanNonce)) {
            throw new BchatHistoryTransferException('Transfer nonce wajib tepat 32-byte (64 hex characters).', 'INVALID_NONCE_FORMAT', 422);
        }

        $ephemeralBytes = (strlen($recipientEphemeralPublicKey) % 2 === 0 && ctype_xdigit($recipientEphemeralPublicKey))
            ? hex2bin($recipientEphemeralPublicKey)
            : $recipientEphemeralPublicKey;
        $pubKeyHash = hash('sha256', $ephemeralBytes, true);

        return self::MAGIC_REQUEST
            . pack('J', $userId)
            . hex2bin($cleanRoomUuid)
            . hex2bin($cleanRecipientUuid)
            . hex2bin($cleanSourceUuid)
            . hex2bin($cleanNonce)
            . $pubKeyHash;
    }

    /**
     * Memverifikasi tanda tangan digital Ed25519 pada permintaan transfer riwayat.
     */
    public function verifyRequestSignature(
        BchatUserDevice $recipientDevice,
        int $userId,
        string $roomUuid,
        string $recipientDeviceUuid,
        string $sourceDeviceUuid,
        string $nonceHex,
        string $recipientEphemeralPublicKey,
        string $signatureHexOrBase64
    ): bool {
        $payload = self::buildRequestPayload(
            $userId,
            $roomUuid,
            $recipientDeviceUuid,
            $sourceDeviceUuid,
            $nonceHex,
            $recipientEphemeralPublicKey
        );

        $sigBin = $this->decodeSignature($signatureHexOrBase64);
        $pubKeyBin = hex2bin($recipientDevice->public_key);

        if (strlen($pubKeyBin) !== 32) {
            throw new BchatHistoryTransferException('Kunci publik perangkat penerima tidak valid (wajib 32 byte).', 'INVALID_DEVICE_PUBLIC_KEY', 500);
        }

        if (!sodium_crypto_sign_verify_detached($sigBin, $payload, $pubKeyBin)) {
            throw new BchatHistoryTransferException('Tanda tangan digital Ed25519 permintaan transfer tidak valid.', 'SIGNATURE_VERIFICATION_FAILED', 422);
        }

        return true;
    }

    /**
     * Membangun payload kanonikal biner untuk snapshot ciphertext oleh perangkat sumber.
     */
    public static function buildSnapshotHeader(
        int $userId,
        string $transferUuid,
        string $roomUuid,
        string $recipientDeviceUuid,
        string $sourceDeviceUuid,
        string $snapshotHashSha256Hex,
        string $sourceEphemeralPublicKey
    ): string {
        $cleanTransferUuid  = strtolower(str_replace('-', '', $transferUuid));
        $cleanRoomUuid      = strtolower(str_replace('-', '', $roomUuid));
        $cleanRecipientUuid = strtolower(str_replace('-', '', $recipientDeviceUuid));
        $cleanSourceUuid    = strtolower(str_replace('-', '', $sourceDeviceUuid));
        $cleanHash          = strtolower(trim($snapshotHashSha256Hex));

        if (strlen($cleanTransferUuid) !== 32 || strlen($cleanRoomUuid) !== 32 || strlen($cleanRecipientUuid) !== 32 || strlen($cleanSourceUuid) !== 32) {
            throw new BchatHistoryTransferException('Format UUID tidak valid untuk konstruksi header snapshot.', 'INVALID_UUID_FORMAT', 422);
        }

        if (strlen($cleanHash) !== 64 || !ctype_xdigit($cleanHash)) {
            throw new BchatHistoryTransferException('Hash snapshot SHA-256 wajib tepat 64 hex characters.', 'INVALID_HASH_FORMAT', 422);
        }

        $ephemeralBytes = (strlen($sourceEphemeralPublicKey) % 2 === 0 && ctype_xdigit($sourceEphemeralPublicKey))
            ? hex2bin($sourceEphemeralPublicKey)
            : $sourceEphemeralPublicKey;
        $sourcePubKeyHash = hash('sha256', $ephemeralBytes, true);

        return self::MAGIC_SNAPSHOT
            . pack('J', $userId)
            . hex2bin($cleanTransferUuid)
            . hex2bin($cleanRoomUuid)
            . hex2bin($cleanRecipientUuid)
            . hex2bin($cleanSourceUuid)
            . hex2bin($cleanHash)
            . $sourcePubKeyHash;
    }

    /**
     * Memverifikasi tanda tangan digital Ed25519 perangkat sumber atas snapshot ciphertext.
     */
    public function verifySnapshotSignature(
        BchatUserDevice $sourceDevice,
        int $userId,
        string $transferUuid,
        string $roomUuid,
        string $recipientDeviceUuid,
        string $sourceDeviceUuid,
        string $snapshotHashSha256Hex,
        string $sourceEphemeralPublicKey,
        string $signatureHexOrBase64
    ): bool {
        $payload = self::buildSnapshotHeader(
            $userId,
            $transferUuid,
            $roomUuid,
            $recipientDeviceUuid,
            $sourceDeviceUuid,
            $snapshotHashSha256Hex,
            $sourceEphemeralPublicKey
        );

        $sigBin = $this->decodeSignature($signatureHexOrBase64);
        $pubKeyBin = hex2bin($sourceDevice->public_key);

        if (strlen($pubKeyBin) !== 32) {
            throw new BchatHistoryTransferException('Kunci publik perangkat sumber tidak valid (wajib 32 byte).', 'INVALID_DEVICE_PUBLIC_KEY', 500);
        }

        if (!sodium_crypto_sign_verify_detached($sigBin, $payload, $pubKeyBin)) {
            throw new BchatHistoryTransferException('Tanda tangan digital Ed25519 snapshot sumber tidak valid.', 'SIGNATURE_VERIFICATION_FAILED', 422);
        }

        return true;
    }

    /**
     * Membangun payload kanonikal biner untuk konfirmasi impor penyelesaian (ACK) oleh penerima.
     */
    public static function buildCompletionPayload(
        int $userId,
        string $transferUuid,
        string $recipientDeviceUuid,
        string $snapshotHashSha256Hex
    ): string {
        $cleanTransferUuid  = strtolower(str_replace('-', '', $transferUuid));
        $cleanRecipientUuid = strtolower(str_replace('-', '', $recipientDeviceUuid));
        $cleanHash          = strtolower(trim($snapshotHashSha256Hex));

        return self::MAGIC_COMPLETION
            . pack('J', $userId)
            . hex2bin($cleanTransferUuid)
            . hex2bin($cleanRecipientUuid)
            . hex2bin($cleanHash);
    }

    /**
     * Memverifikasi tanda tangan digital penyelesaian oleh penerima.
     */
    public function verifyCompletionSignature(
        BchatUserDevice $recipientDevice,
        int $userId,
        string $transferUuid,
        string $recipientDeviceUuid,
        string $snapshotHashSha256Hex,
        string $signatureHexOrBase64
    ): bool {
        $payload = self::buildCompletionPayload(
            $userId,
            $transferUuid,
            $recipientDeviceUuid,
            $snapshotHashSha256Hex
        );

        $sigBin = $this->decodeSignature($signatureHexOrBase64);
        $pubKeyBin = hex2bin($recipientDevice->public_key);

        if (!sodium_crypto_sign_verify_detached($sigBin, $payload, $pubKeyBin)) {
            throw new BchatHistoryTransferException('Tanda tangan digital penyelesaian transfer tidak valid.', 'SIGNATURE_VERIFICATION_FAILED', 422);
        }

        return true;
    }

    /**
     * Dekode signature dari representasi Hex (128 char) atau Base64.
     */
    private function decodeSignature(string $sig): string
    {
        $clean = trim($sig);
        if (strlen($clean) === 128 && ctype_xdigit($clean)) {
            return hex2bin($clean);
        }

        $decoded = base64_decode($clean, true);
        if ($decoded !== false && strlen($decoded) === self::SIGNATURE_LENGTH) {
            return $decoded;
        }

        throw new BchatHistoryTransferException('Format signature digital tidak valid (wajib 64-byte Ed25519 hex atau base64).', 'INVALID_SIGNATURE_FORMAT', 422);
    }
}

