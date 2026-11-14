<?php

namespace App\Services;

use App\ValueObjects\BchatPopVerificationResult;
use Throwable;

class BchatPopVerificationService
{
    /**
     * Konstanta Magic Header Kanonis PoP 25-byte ASCII.
     */
    public const MAGIC_HEADER = 'UNUHA-BCHAT-DEVICE-POP-V1';

    /**
     * Panjang payload PoP kanonis tepat 121 byte.
     */
    public const PAYLOAD_LENGTH = 121;

    /**
     * Panjang signature Ed25519 detached tepat 64 byte.
     */
    public const SIGNATURE_LENGTH = 64;

    /**
     * Verifikasi integritas, binding parameter, dan tanda tangan digital Registration PoP.
     *
     * @param string $payloadBinary      Buffer biner payload 121-byte
     * @param string $signatureBinary    Buffer biner signature Ed25519 64-byte
     * @param mixed  $expectedUserId     ID user yang diharapkan terotentikasi (unsigned 64-bit int/string)
     * @param string $expectedDeviceUuid UUID v4 perangkat target (36-char string)
     * @param string $expectedNonceHex   Nilai nonce server (64-char hex)
     * @param mixed  $expectedIssuedAt   Timestamp kanonis server (uint64 int/string)
     * @param string $expectedFingerprint SHA-256 fingerprint public key yang diikat (64-char hex)
     * @return BchatPopVerificationResult
     */
    public function verify(
        string $payloadBinary,
        string $signatureBinary,
        mixed $expectedUserId,
        string $expectedDeviceUuid,
        string $expectedNonceHex,
        mixed $expectedIssuedAt,
        string $expectedFingerprint
    ): BchatPopVerificationResult {
        try {
            // 1. Validasi panjang buffer payload tepat 121 byte
            if (strlen($payloadBinary) !== self::PAYLOAD_LENGTH) {
                return BchatPopVerificationResult::failure('INVALID_PAYLOAD_LENGTH');
            }

            // 2. Validasi panjang buffer signature tepat 64 byte
            if (strlen($signatureBinary) !== self::SIGNATURE_LENGTH) {
                return BchatPopVerificationResult::failure('INVALID_SIGNATURE_LENGTH');
            }

            // 3. Parsing komponen biner sesuai offset kanonis
            $extractedMagic      = substr($payloadBinary, 0, 25);
            $userIdBinary        = substr($payloadBinary, 25, 8);
            $deviceUuidBinary    = substr($payloadBinary, 33, 16);
            $nonceBinary         = substr($payloadBinary, 49, 32);
            $timestampBinary     = substr($payloadBinary, 81, 8);
            $publicKeyBinary     = substr($payloadBinary, 89, 32);

            // 4. Validasi Magic Header secara timing-attack resistant
            if (!hash_equals(self::MAGIC_HEADER, $extractedMagic)) {
                return BchatPopVerificationResult::failure('INVALID_MAGIC_HEADER');
            }

            // 5. Validasi User ID (Unsigned 64-bit Big-Endian, rentang 0..PHP_INT_MAX)
            $unpackedUser = unpack('JuserId', $userIdBinary);
            if ($unpackedUser === false || $unpackedUser['userId'] < 0) {
                return BchatPopVerificationResult::failure('USER_ID_OUT_OF_RANGE');
            }
            $extractedUserId = $unpackedUser['userId'];

            // Validasi format integer 64-bit kanonis sebelum konversi
            if (!self::isValidUnsigned64Int($expectedUserId)) {
                return BchatPopVerificationResult::failure('EXPECTED_USER_ID_INVALID');
            }
            if ($extractedUserId !== (int) $expectedUserId) {
                return BchatPopVerificationResult::failure('USER_ID_MISMATCH');
            }

            // 6. Validasi Device UUID
            $cleanExpectedUuid = strtolower(str_replace('-', '', $expectedDeviceUuid));
            if (strlen($cleanExpectedUuid) !== 32 || !ctype_xdigit($cleanExpectedUuid)) {
                return BchatPopVerificationResult::failure('EXPECTED_DEVICE_UUID_INVALID');
            }
            $expectedDeviceUuidBin = hex2bin($cleanExpectedUuid);
            if (!hash_equals($expectedDeviceUuidBin, $deviceUuidBinary)) {
                return BchatPopVerificationResult::failure('DEVICE_UUID_MISMATCH');
            }

            // 7. Validasi Nonce terhadap rekaman nonce server
            $cleanExpectedNonce = strtolower($expectedNonceHex);
            if (strlen($cleanExpectedNonce) !== 64 || !ctype_xdigit($cleanExpectedNonce)) {
                return BchatPopVerificationResult::failure('EXPECTED_NONCE_INVALID');
            }
            $expectedNonceBin = hex2bin($cleanExpectedNonce);
            if (!hash_equals($expectedNonceBin, $nonceBinary)) {
                return BchatPopVerificationResult::failure('NONCE_MISMATCH');
            }

            // 8. Validasi Timestamp Server (issued_at)
            $unpackedTs = unpack('Jtimestamp', $timestampBinary);
            if ($unpackedTs === false || $unpackedTs['timestamp'] < 0) {
                return BchatPopVerificationResult::failure('TIMESTAMP_OUT_OF_RANGE');
            }
            $extractedTimestamp = $unpackedTs['timestamp'];

            if (!self::isValidUnsigned64Int($expectedIssuedAt)) {
                return BchatPopVerificationResult::failure('EXPECTED_TIMESTAMP_INVALID');
            }
            if ($extractedTimestamp !== (int) $expectedIssuedAt) {
                return BchatPopVerificationResult::failure('TIMESTAMP_MISMATCH');
            }

            // 9. Validasi SHA-256 Fingerprint Public Key
            $calculatedFingerprint = hash('sha256', $publicKeyBinary);
            $cleanExpectedFingerprint = strtolower($expectedFingerprint);
            if (!hash_equals($cleanExpectedFingerprint, $calculatedFingerprint)) {
                return BchatPopVerificationResult::failure('FINGERPRINT_MISMATCH');
            }

            // 10. Verifikasi Kriptografis Ed25519 menggunakan Native Sodium
            if (!function_exists('sodium_crypto_sign_verify_detached')) {
                return BchatPopVerificationResult::failure('SODIUM_EXTENSION_NOT_AVAILABLE');
            }

            $isSignatureValid = sodium_crypto_sign_verify_detached(
                $signatureBinary,
                $payloadBinary,
                $publicKeyBinary
            );

            if (!$isSignatureValid) {
                return BchatPopVerificationResult::failure('SIGNATURE_VERIFICATION_FAILED');
            }

            // Format UUID hasil parsing menjadi canonical 36-char
            $canonicalDeviceUuid = vsprintf(
                '%s%s-%s-%s-%s-%s%s%s',
                str_split(bin2hex($deviceUuidBinary), 4)
            );

            // 11. Sukses: Kembalikan data aman hasil verifikasi
            return BchatPopVerificationResult::success([
                'user_id'                => $extractedUserId,
                'device_uuid'            => $canonicalDeviceUuid,
                'nonce_hex'              => bin2hex($nonceBinary),
                'issued_at'              => $extractedTimestamp,
                'public_key_hex'         => bin2hex($publicKeyBinary),
                'public_key_fingerprint' => $calculatedFingerprint,
            ]);
        } catch (Throwable $e) {
            // Tangani exception tak terduga secara aman tanpa membocorkan detail sensitif
            return BchatPopVerificationResult::failure('VERIFICATION_EXCEPTION');
        }
    }

    /**
     * Memvalidasi apakah suatu nilai merupakan integer 64-bit unsigned kanonis (0 s.d. PHP_INT_MAX).
     * Menerima tipe int >= 0 atau string digit desimal murni tanpa tanda, pecahan, spasi, atau notasi ilmiah.
     * Menggunakan perbandingan panjang dan leksikografis terhadap PHP_INT_MAX tanpa konversi float.
     */
    public static function isValidUnsigned64Int(mixed $val): bool
    {
        if (is_int($val)) {
            return $val >= 0;
        }

        if (!is_string($val) || $val === '' || !ctype_digit($val)) {
            return false;
        }

        $maxStr = (string) PHP_INT_MAX; // "9223372036854775807" (panjang 19 digit)
        $len = strlen($val);
        $maxLen = strlen($maxStr);

        if ($len < $maxLen) {
            return true;
        }

        if ($len > $maxLen) {
            return false;
        }

        return strcmp($val, $maxStr) <= 0;
    }

    /**
     * Helper kanonis untuk merekonstruksi/menyusun buffer biner PoP 121-byte.
     * Digunakan oleh pengujian, test vectors, dan simulasi klien.
     */
    public static function buildPayload(
        mixed $userId,
        string $deviceUuid,
        string $nonceHexOrBin,
        mixed $issuedAt,
        string $publicKeyHexOrBin
    ): string {
        if (!self::isValidUnsigned64Int($userId)) {
            throw new \InvalidArgumentException('User ID wajib berupa unsigned 64-bit integer valid (0 s.d. PHP_INT_MAX).');
        }

        if (!self::isValidUnsigned64Int($issuedAt)) {
            throw new \InvalidArgumentException('Timestamp wajib berupa unsigned 64-bit integer valid (0 s.d. PHP_INT_MAX).');
        }

        // 1. Magic Header 25 byte
        $magic = self::MAGIC_HEADER;

        // 2. User ID 8 byte (uint64 big-endian)
        $userIdBin = pack('J', (int) $userId);

        // 3. Device UUID 16 byte
        $cleanUuid = str_replace('-', '', $deviceUuid);
        $deviceUuidBin = strlen($deviceUuid) === 16 ? $deviceUuid : hex2bin($cleanUuid);

        // 4. Nonce 32 byte
        $nonceBin = strlen($nonceHexOrBin) === 32 ? $nonceHexOrBin : hex2bin($nonceHexOrBin);

        // 5. Timestamp 8 byte (uint64 big-endian)
        $timestampBin = pack('J', (int) $issuedAt);

        // 6. Public Key 32 byte
        $publicKeyBin = strlen($publicKeyHexOrBin) === 32 ? $publicKeyHexOrBin : hex2bin($publicKeyHexOrBin);

        $payload = $magic . $userIdBin . $deviceUuidBin . $nonceBin . $timestampBin . $publicKeyBin;

        if (strlen($payload) !== self::PAYLOAD_LENGTH) {
            throw new \InvalidArgumentException('Gagal menyusun payload: Panjang buffer bukan 121 byte (' . strlen($payload) . ' byte).');
        }

        return $payload;
    }
}

