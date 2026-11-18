<?php

namespace App\Services;

use App\Models\BchatDevicePairing;
use App\Models\BchatUserDevice;
use App\Models\User;
use Exception;
use Throwable;

class BchatPairingVerificationService
{
    /**
     * Magic Header kanonis persetujuan pairing 31-byte ASCII.
     */
    public const MAGIC_HEADER = 'UNUHA-BCHAT-PAIRING-APPROVAL-V1';

    /**
     * Panjang payload biner kanonis tepat 159 byte.
     */
    public const PAYLOAD_LENGTH = 159;

    /**
     * Panjang signature Ed25519 detached tepat 64 byte.
     */
    public const SIGNATURE_LENGTH = 64;

    /**
     * Verifikasi integritas, parameter binding, dan signature Ed25519 dari approver device.
     *
     * @param string $payloadBinary Buffer biner payload 159-byte
     * @param string $signatureBinary Buffer biner signature 64-byte
     * @param User $user Pengguna pemilik akun
     * @param BchatDevicePairing $pairing Sesi pairing yang hendak disetujui
     * @param BchatUserDevice $approverDevice Perangkat tepercaya yang menyetujui
     * @return array<string, mixed> Data hasil verifikasi sukses
     *
     * @throws Exception Jika validasi gagal
     */
    public function verifyApproval(
        string $payloadBinary,
        string $signatureBinary,
        User $user,
        BchatDevicePairing $pairing,
        BchatUserDevice $approverDevice
    ): array {
        // 1. Validasi panjang buffer payload tepat 159 byte
        if (strlen($payloadBinary) !== self::PAYLOAD_LENGTH) {
            throw new Exception('INVALID_PAYLOAD_LENGTH');
        }

        // 2. Validasi panjang buffer signature tepat 64 byte
        if (strlen($signatureBinary) !== self::SIGNATURE_LENGTH) {
            throw new Exception('INVALID_SIGNATURE_LENGTH');
        }

        // 3. Ekstraksi komponen biner sesuai offset kanonis
        $extractedMagic       = substr($payloadBinary, 0, 31);
        $userIdBinary         = substr($payloadBinary, 31, 8);
        $pairingUuidBinary    = substr($payloadBinary, 39, 16);
        $approverUuidBinary   = substr($payloadBinary, 55, 16);
        $targetUuidBinary     = substr($payloadBinary, 71, 16);
        $nonceBinary          = substr($payloadBinary, 87, 32);
        $fingerprintBinary    = substr($payloadBinary, 119, 32);
        $timestampBinary      = substr($payloadBinary, 151, 8);

        // 4. Validasi Magic Header secara timing-attack resistant
        if (!hash_equals(self::MAGIC_HEADER, $extractedMagic)) {
            throw new Exception('INVALID_MAGIC_HEADER');
        }

        // 5. Validasi User ID (uint64 Big-Endian)
        $unpackedUser = unpack('JuserId', $userIdBinary);
        if ($unpackedUser === false || $unpackedUser['userId'] < 0) {
            throw new Exception('USER_ID_OUT_OF_RANGE');
        }
        if ((int) $unpackedUser['userId'] !== (int) $user->id) {
            throw new Exception('USER_ID_MISMATCH');
        }

        // 6. Validasi Pairing UUID
        $cleanPairingUuid = strtolower(str_replace('-', '', $pairing->pairing_uuid));
        if (strlen($cleanPairingUuid) !== 32 || !ctype_xdigit($cleanPairingUuid)) {
            throw new Exception('PAIRING_UUID_INVALID');
        }
        if (!hash_equals(hex2bin($cleanPairingUuid), $pairingUuidBinary)) {
            throw new Exception('PAIRING_UUID_MISMATCH');
        }

        // 7. Validasi Approver Device UUID
        $cleanApproverUuid = strtolower(str_replace('-', '', $approverDevice->device_uuid));
        if (strlen($cleanApproverUuid) !== 32 || !ctype_xdigit($cleanApproverUuid)) {
            throw new Exception('APPROVER_DEVICE_UUID_INVALID');
        }
        if (!hash_equals(hex2bin($cleanApproverUuid), $approverUuidBinary)) {
            throw new Exception('APPROVER_DEVICE_UUID_MISMATCH');
        }

        // 8. Validasi Target Device UUID
        $cleanTargetUuid = strtolower(str_replace('-', '', $pairing->target_device_uuid));
        if (strlen($cleanTargetUuid) !== 32 || !ctype_xdigit($cleanTargetUuid)) {
            throw new Exception('TARGET_DEVICE_UUID_INVALID');
        }
        if (!hash_equals(hex2bin($cleanTargetUuid), $targetUuidBinary)) {
            throw new Exception('TARGET_DEVICE_UUID_MISMATCH');
        }

        // 9. Validasi Challenge Nonce
        $cleanNonce = strtolower(trim($pairing->challenge_nonce));
        if (strlen($cleanNonce) !== 64 || !ctype_xdigit($cleanNonce)) {
            throw new Exception('CHALLENGE_NONCE_INVALID');
        }
        if (!hash_equals(hex2bin($cleanNonce), $nonceBinary)) {
            throw new Exception('CHALLENGE_NONCE_MISMATCH');
        }

        // 10. Validasi Fingerprint Kunci Publik Perangkat Target
        $cleanFingerprint = strtolower(trim($pairing->target_public_key_fingerprint));
        if (strlen($cleanFingerprint) !== 64 || !ctype_xdigit($cleanFingerprint)) {
            throw new Exception('TARGET_FINGERPRINT_INVALID');
        }
        if (!hash_equals(hex2bin($cleanFingerprint), $fingerprintBinary)) {
            throw new Exception('TARGET_FINGERPRINT_MISMATCH');
        }

        // 11. Validasi Timestamp Persetujuan (uint64 Big-Endian)
        $unpackedTs = unpack('Jtimestamp', $timestampBinary);
        if ($unpackedTs === false || $unpackedTs['timestamp'] < 0) {
            throw new Exception('TIMESTAMP_OUT_OF_RANGE');
        }
        $approvalTimestamp = (int) $unpackedTs['timestamp'];
        $currentTimestamp = time();
        // Toleransi clock skew maksimal 300 detik (5 menit) ke masa depan atau masa lalu
        if (abs($currentTimestamp - $approvalTimestamp) > 300) {
            throw new Exception('APPROVAL_TIMESTAMP_SKEW');
        }

        // 12. Validasi Status dan Kunci Publik Approver Device
        if (!$approverDevice->isActive()) {
            throw new Exception('APPROVER_DEVICE_NOT_ACTIVE');
        }
        if (!$approverDevice->isTrusted()) {
            throw new Exception('APPROVER_DEVICE_NOT_TRUSTED');
        }

        $approverPubKeyHex = strtolower(trim($approverDevice->public_key));
        if (strlen($approverPubKeyHex) !== 64 || !ctype_xdigit($approverPubKeyHex)) {
            throw new Exception('APPROVER_PUBLIC_KEY_INVALID');
        }
        $approverPubKeyBin = hex2bin($approverPubKeyHex);

        // 13. Verifikasi Tanda Tangan Kriptografis Ed25519 (Sodium Native)
        if (!function_exists('sodium_crypto_sign_verify_detached')) {
            throw new Exception('SODIUM_EXTENSION_NOT_AVAILABLE');
        }

        $isValidSignature = sodium_crypto_sign_verify_detached(
            $signatureBinary,
            $payloadBinary,
            $approverPubKeyBin
        );

        if (!$isValidSignature) {
            throw new Exception('SIGNATURE_VERIFICATION_FAILED');
        }

        return [
            'user_id'              => (int) $user->id,
            'pairing_uuid'         => $pairing->pairing_uuid,
            'approver_device_uuid' => $approverDevice->device_uuid,
            'target_device_uuid'   => $pairing->target_device_uuid,
            'approved_at'          => $approvalTimestamp,
        ];
    }

    /**
     * Membangun payload kanonis biner 159-byte (digunakan untuk pengujian atau referensi server).
     */
    public static function buildCanonicalPayload(
        int $userId,
        string $pairingUuid,
        string $approverDeviceUuid,
        string $targetDeviceUuid,
        string $challengeNonceHex,
        string $targetFingerprintHex,
        int $timestamp
    ): string {
        $magic = self::MAGIC_HEADER;
        $userIdBin = pack('J', $userId);
        $pairingUuidBin = hex2bin(str_replace('-', '', $pairingUuid));
        $approverUuidBin = hex2bin(str_replace('-', '', $approverDeviceUuid));
        $targetUuidBin = hex2bin(str_replace('-', '', $targetDeviceUuid));
        $nonceBin = hex2bin($challengeNonceHex);
        $fingerprintBin = hex2bin($targetFingerprintHex);
        $timestampBin = pack('J', $timestamp);

        return $magic . $userIdBin . $pairingUuidBin . $approverUuidBin . $targetUuidBin . $nonceBin . $fingerprintBin . $timestampBin;
    }
}

