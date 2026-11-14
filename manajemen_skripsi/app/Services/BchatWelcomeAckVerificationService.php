<?php

declare(strict_types=1);

namespace App\Services;

use App\ValueObjects\BchatWelcomeAckVerificationResult;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 * Service perakitan dan verifikasi Canonical Welcome ACK Payload 158-byte.
 *
 * Mengimplementasikan kontrak definitif B-CHAT-13D, B-CHAT-13 Subbab 4.2,
 * dan arahan B-CHAT-22B Tahap 1B:
 * 1. Menjunjung Prinsip Zero Trust: verify() merakit sendiri payload kanonikal
 *    dari konteks tepercaya server, tidak pernah mempercayai klaim payload/hash klien.
 * 2. Membatasi target Epoch tepat bernilai 1 (selain 1 ditolak).
 * 3. Menjamin perakitan 9 komponen baku tepat 158 byte bit-demi-bit.
 * 4. Verifikasi kriptografis detached Ed25519 menggunakan native ext-sodium.
 */
class BchatWelcomeAckVerificationService
{
    /**
     * Konstanta Domain Separator Kanonis Welcome ACK 26-byte ASCII baku.
     * Sumber: B-CHAT-13D Bab 4 & B-CHAT-13 Subbab 4.2.
     */
    public const DOMAIN_SEPARATOR = 'UNUHA-BCHAT-WELCOME-ACK-V1';

    /**
     * Panjang payload Welcome ACK kanonis tepat 158 byte.
     */
    public const PAYLOAD_LENGTH = 158;

    /**
     * Panjang detached signature Ed25519 tepat 64 byte.
     */
    public const SIGNATURE_LENGTH = 64;

    /**
     * Panjang public key Ed25519 tepat 32 byte.
     */
    public const PUBLIC_KEY_LENGTH = 32;

    /**
     * Target Epoch tunggal yang diizinkan untuk Welcome pertama (RFC 9420).
     */
    public const TARGET_EPOCH = 1;

    /**
     * Verifikasi integritas tanda tangan digital Ed25519 atas Canonical Welcome ACK Payload.
     *
     * Prinsip Zero Trust:
     * - Parameter payload TIDAK diterima dari klien.
     * - Backend merakit sendiri buffer kanonikal 158-byte dari parameter konteks server.
     * - Signature klien diverifikasi secara langsung terhadap buffer hasil rakitan backend.
     * - Payload hash SHA-256 dihitung dari buffer rakitan yang sama.
     *
     * @param string $signatureBinary            Buffer biner detached signature Ed25519 (64 byte)
     * @param string $publicKeyBinary            Buffer biner Ed25519 public key perangkat penerima (32 byte)
     * @param string $roomUuid                   UUID v4 room bimbingan (36-char string)
     * @param mixed  $recipientUserId            ID user penerima (uint64 non-negatif int/string)
     * @param string $welcomeOperationUuid       UUID v4 operasi Welcome induk (36-char string)
     * @param string $challengeUuid              UUID v4 tantangan aktif generasi saat ini (36-char string)
     * @param mixed  $challengeGeneration        Nomor generasi tantangan (uint32 >= 1 int/string)
     * @param string $groupIdHashHexOrBin        Hash SHA-256 group_id MLS (64-char hex atau 32-byte bin)
     * @param mixed  $epoch                      Target epoch Welcome (wajib tepat 1)
     * @param string $challengeNonceHexOrBin     Nilai acak nonce tantangan (64-char hex atau 32-byte bin)
     * @return BchatWelcomeAckVerificationResult
     */
    public function verify(
        string $signatureBinary,
        string $publicKeyBinary,
        string $roomUuid,
        mixed $recipientUserId,
        string $welcomeOperationUuid,
        string $challengeUuid,
        mixed $challengeGeneration,
        string $groupIdHashHexOrBin,
        mixed $epoch,
        string $challengeNonceHexOrBin
    ): BchatWelcomeAckVerificationResult {
        try {
            // 1. Validasi panjang buffer signature (wajib tepat 64 byte)
            if (strlen($signatureBinary) !== self::SIGNATURE_LENGTH) {
                return BchatWelcomeAckVerificationResult::failure('INVALID_SIGNATURE_LENGTH');
            }

            // 2. Validasi panjang buffer public key (wajib tepat 32 byte)
            if (strlen($publicKeyBinary) !== self::PUBLIC_KEY_LENGTH) {
                return BchatWelcomeAckVerificationResult::failure('INVALID_PUBLIC_KEY_LENGTH');
            }

            // 3. Validasi ketat parameter konteks server sebelum perakitan
            if (!self::isValidUuid($roomUuid)) {
                return BchatWelcomeAckVerificationResult::failure('EXPECTED_ROOM_UUID_INVALID');
            }

            if (!self::isValidUnsigned64Int($recipientUserId)) {
                return BchatWelcomeAckVerificationResult::failure('EXPECTED_USER_ID_INVALID');
            }

            if (!self::isValidUuid($welcomeOperationUuid)) {
                return BchatWelcomeAckVerificationResult::failure('EXPECTED_WELCOME_OP_UUID_INVALID');
            }

            if (!self::isValidUuid($challengeUuid)) {
                return BchatWelcomeAckVerificationResult::failure('EXPECTED_CHALLENGE_UUID_INVALID');
            }

            if (!self::isValidUnsigned32Int($challengeGeneration)) {
                return BchatWelcomeAckVerificationResult::failure('EXPECTED_GENERATION_INVALID');
            }

            $groupIdHashBin = self::hexOrBinToBinary($groupIdHashHexOrBin, 32);
            if ($groupIdHashBin === null) {
                return BchatWelcomeAckVerificationResult::failure('EXPECTED_GROUP_ID_HASH_INVALID');
            }

            if (!self::isValidEpoch($epoch)) {
                return BchatWelcomeAckVerificationResult::failure('EPOCH_MISMATCH');
            }

            $nonceBin = self::hexOrBinToBinary($challengeNonceHexOrBin, 32);
            if ($nonceBin === null) {
                return BchatWelcomeAckVerificationResult::failure('EXPECTED_NONCE_INVALID');
            }

            // 4. Backend merakit sendiri buffer kanonikal 158-byte (Zero Trust)
            $assembledPayload = self::buildPayload(
                roomUuid: $roomUuid,
                recipientUserId: $recipientUserId,
                welcomeOperationUuid: $welcomeOperationUuid,
                challengeUuid: $challengeUuid,
                challengeGeneration: $challengeGeneration,
                groupIdHashHexOrBin: $groupIdHashBin,
                epoch: $epoch,
                challengeNonceHexOrBin: $nonceBin
            );

            // 5. Cek ketersediaan ekstensi native Sodium
            if (!$this->isSodiumAvailable()) {
                return BchatWelcomeAckVerificationResult::failure('SODIUM_EXTENSION_NOT_AVAILABLE');
            }

            // 6. Verifikasi detached Ed25519 signature terhadap payload rakitan backend
            $isSignatureValid = @sodium_crypto_sign_verify_detached(
                $signatureBinary,
                $assembledPayload,
                $publicKeyBinary
            );

            if (!$isSignatureValid) {
                return BchatWelcomeAckVerificationResult::failure('SIGNATURE_VERIFICATION_FAILED');
            }

            // 7. Hitung hash SHA-256 dari payload rakitan yang telah terverifikasi
            $payloadHash = hash('sha256', $assembledPayload);

            // 8. Susun data parsed aman (hanya 8 komponen selain Domain Separator, tanpa public key)
            $parsedData = [
                'room_uuid'              => strtolower($roomUuid),
                'recipient_user_id'      => (int) $recipientUserId,
                'welcome_operation_uuid' => strtolower($welcomeOperationUuid),
                'challenge_uuid'         => strtolower($challengeUuid),
                'challenge_generation'   => (int) $challengeGeneration,
                'group_id_hash_hex'      => bin2hex($groupIdHashBin),
                'epoch'                  => (int) $epoch,
                'challenge_nonce_hex'    => bin2hex($nonceBin),
            ];

            return BchatWelcomeAckVerificationResult::success($parsedData, $payloadHash);
        } catch (Throwable) {
            return BchatWelcomeAckVerificationResult::failure('VERIFICATION_EXCEPTION');
        }
    }

    /**
     * Memvalidasi dan mem-parse buffer biner Canonical Welcome ACK 158-byte mentah.
     *
     * Digunakan oleh pengujian format biner, simulasi round-trip, dan validasi payload buffer.
     *
     * @param string $payloadBinary Buffer biner yang akan di-parse
     * @return BchatWelcomeAckVerificationResult
     */
    public function parsePayload(string $payloadBinary): BchatWelcomeAckVerificationResult
    {
        try {
            // 1. Validasi panjang buffer tepat 158 byte
            if (strlen($payloadBinary) !== self::PAYLOAD_LENGTH) {
                return BchatWelcomeAckVerificationResult::failure('INVALID_PAYLOAD_LENGTH');
            }

            // 2. Validasi Domain Separator (26 byte)
            $extractedDomain = substr($payloadBinary, 0, 26);
            if (!hash_equals(self::DOMAIN_SEPARATOR, $extractedDomain)) {
                return BchatWelcomeAckVerificationResult::failure('INVALID_DOMAIN_SEPARATOR');
            }

            // 3. Ekstraksi komponen biner sesuai offset kanonis
            $roomUuidBin      = substr($payloadBinary, 26, 16);
            $userIdBin        = substr($payloadBinary, 42, 8);
            $welcomeOpUuidBin = substr($payloadBinary, 50, 16);
            $challengeUuidBin = substr($payloadBinary, 66, 16);
            $generationBin    = substr($payloadBinary, 82, 4);
            $groupIdHashBin   = substr($payloadBinary, 86, 32);
            $epochBin         = substr($payloadBinary, 118, 8);
            $nonceBin         = substr($payloadBinary, 126, 32);

            // 4. Validasi Recipient User ID (uint64 Big-Endian non-negatif)
            $unpackedUser = unpack('JuserId', $userIdBin);
            if ($unpackedUser === false || $unpackedUser['userId'] < 0) {
                return BchatWelcomeAckVerificationResult::failure('RECIPIENT_USER_ID_OUT_OF_RANGE');
            }
            $extractedUserId = $unpackedUser['userId'];

            // 5. Validasi Challenge Generation (uint32 Big-Endian >= 1)
            $unpackedGen = unpack('Ngeneration', $generationBin);
            if ($unpackedGen === false || $unpackedGen['generation'] < 1) {
                return BchatWelcomeAckVerificationResult::failure('CHALLENGE_GENERATION_OUT_OF_RANGE');
            }
            $extractedGeneration = $unpackedGen['generation'];

            // 6. Validasi Epoch (uint64 Big-Endian, wajib tepat 1)
            $unpackedEpoch = unpack('Jepoch', $epochBin);
            if ($unpackedEpoch === false || $unpackedEpoch['epoch'] !== self::TARGET_EPOCH) {
                return BchatWelcomeAckVerificationResult::failure('EPOCH_MISMATCH');
            }
            $extractedEpoch = $unpackedEpoch['epoch'];

            $parsedData = [
                'room_uuid'              => self::binaryToUuid($roomUuidBin),
                'recipient_user_id'      => $extractedUserId,
                'welcome_operation_uuid' => self::binaryToUuid($welcomeOpUuidBin),
                'challenge_uuid'         => self::binaryToUuid($challengeUuidBin),
                'challenge_generation'   => $extractedGeneration,
                'group_id_hash_hex'      => bin2hex($groupIdHashBin),
                'epoch'                  => $extractedEpoch,
                'challenge_nonce_hex'    => bin2hex($nonceBin),
            ];

            $payloadHash = hash('sha256', $payloadBinary);

            return BchatWelcomeAckVerificationResult::success($parsedData, $payloadHash);
        } catch (Throwable) {
            return BchatWelcomeAckVerificationResult::failure('VERIFICATION_EXCEPTION');
        }
    }

    /**
     * Memverifikasi buffer biner payload terhadap expected context dan detached signature.
     *
     * Metode pembantu untuk pengujian mendalam yang mengevaluasi mismatch parameter biner.
     */
    public function verifyPayloadBuffer(
        string $payloadBinary,
        string $signatureBinary,
        string $publicKeyBinary,
        string $expectedRoomUuid,
        mixed $expectedRecipientUserId,
        string $expectedWelcomeOperationUuid,
        string $expectedChallengeUuid,
        mixed $expectedChallengeGeneration,
        string $expectedGroupIdHashHexOrBin,
        mixed $expectedEpoch,
        string $expectedChallengeNonceHexOrBin
    ): BchatWelcomeAckVerificationResult {
        try {
            // 1. Validasi panjang buffer signature (wajib tepat 64 byte)
            if (strlen($signatureBinary) !== self::SIGNATURE_LENGTH) {
                return BchatWelcomeAckVerificationResult::failure('INVALID_SIGNATURE_LENGTH');
            }

            // 2. Validasi panjang buffer public key (wajib tepat 32 byte)
            if (strlen($publicKeyBinary) !== self::PUBLIC_KEY_LENGTH) {
                return BchatWelcomeAckVerificationResult::failure('INVALID_PUBLIC_KEY_LENGTH');
            }

            // 3. Parse dan validasi format buffer payload
            $parseResult = $this->parsePayload($payloadBinary);
            if (!$parseResult->isValid()) {
                return $parseResult;
            }

            $parsed = $parseResult->getParsedData();
            if ($parsed === null) {
                return BchatWelcomeAckVerificationResult::failure('VERIFICATION_EXCEPTION');
            }

            // 4. Validasi kecocokan konteks server (mismatch checks)
            if (!self::isValidUuid($expectedRoomUuid)) {
                return BchatWelcomeAckVerificationResult::failure('EXPECTED_ROOM_UUID_INVALID');
            }
            if (!hash_equals(strtolower($expectedRoomUuid), $parsed['room_uuid'])) {
                return BchatWelcomeAckVerificationResult::failure('ROOM_UUID_MISMATCH');
            }

            if (!self::isValidUnsigned64Int($expectedRecipientUserId)) {
                return BchatWelcomeAckVerificationResult::failure('EXPECTED_USER_ID_INVALID');
            }
            if ($parsed['recipient_user_id'] !== (int) $expectedRecipientUserId) {
                return BchatWelcomeAckVerificationResult::failure('USER_ID_MISMATCH');
            }

            if (!self::isValidUuid($expectedWelcomeOperationUuid)) {
                return BchatWelcomeAckVerificationResult::failure('EXPECTED_WELCOME_OP_UUID_INVALID');
            }
            if (!hash_equals(strtolower($expectedWelcomeOperationUuid), $parsed['welcome_operation_uuid'])) {
                return BchatWelcomeAckVerificationResult::failure('WELCOME_OP_UUID_MISMATCH');
            }

            if (!self::isValidUuid($expectedChallengeUuid)) {
                return BchatWelcomeAckVerificationResult::failure('EXPECTED_CHALLENGE_UUID_INVALID');
            }
            if (!hash_equals(strtolower($expectedChallengeUuid), $parsed['challenge_uuid'])) {
                return BchatWelcomeAckVerificationResult::failure('CHALLENGE_UUID_MISMATCH');
            }

            if (!self::isValidUnsigned32Int($expectedChallengeGeneration)) {
                return BchatWelcomeAckVerificationResult::failure('EXPECTED_GENERATION_INVALID');
            }
            if ($parsed['challenge_generation'] !== (int) $expectedChallengeGeneration) {
                return BchatWelcomeAckVerificationResult::failure('GENERATION_MISMATCH');
            }

            $expectedGroupIdHashBin = self::hexOrBinToBinary($expectedGroupIdHashHexOrBin, 32);
            if ($expectedGroupIdHashBin === null) {
                return BchatWelcomeAckVerificationResult::failure('EXPECTED_GROUP_ID_HASH_INVALID');
            }
            if (!hash_equals(bin2hex($expectedGroupIdHashBin), $parsed['group_id_hash_hex'])) {
                return BchatWelcomeAckVerificationResult::failure('GROUP_ID_HASH_MISMATCH');
            }

            if (!self::isValidEpoch($expectedEpoch)) {
                return BchatWelcomeAckVerificationResult::failure('EXPECTED_EPOCH_INVALID');
            }
            if ($parsed['epoch'] !== (int) $expectedEpoch) {
                return BchatWelcomeAckVerificationResult::failure('EPOCH_MISMATCH');
            }

            $expectedNonceBin = self::hexOrBinToBinary($expectedChallengeNonceHexOrBin, 32);
            if ($expectedNonceBin === null) {
                return BchatWelcomeAckVerificationResult::failure('EXPECTED_NONCE_INVALID');
            }
            if (!hash_equals(bin2hex($expectedNonceBin), $parsed['challenge_nonce_hex'])) {
                return BchatWelcomeAckVerificationResult::failure('NONCE_MISMATCH');
            }

            // 5. Cek ketersediaan ekstensi native Sodium
            if (!$this->isSodiumAvailable()) {
                return BchatWelcomeAckVerificationResult::failure('SODIUM_EXTENSION_NOT_AVAILABLE');
            }

            // 6. Verifikasi detached signature
            $isSignatureValid = @sodium_crypto_sign_verify_detached(
                $signatureBinary,
                $payloadBinary,
                $publicKeyBinary
            );

            if (!$isSignatureValid) {
                return BchatWelcomeAckVerificationResult::failure('SIGNATURE_VERIFICATION_FAILED');
            }

            return BchatWelcomeAckVerificationResult::success($parsed, $parseResult->getPayloadHash() ?? '');
        } catch (Throwable) {
            return BchatWelcomeAckVerificationResult::failure('VERIFICATION_EXCEPTION');
        }
    }

    /**
     * Membangun buffer biner Canonical Welcome ACK 158-byte deterministik.
     *
     * @param string $roomUuid                   UUID v4 room (36-char string)
     * @param mixed  $recipientUserId            ID user penerima (uint64 non-negatif int/string)
     * @param string $welcomeOperationUuid       UUID v4 operasi Welcome induk (36-char string)
     * @param string $challengeUuid              UUID v4 tantangan aktif generasi saat ini (36-char string)
     * @param mixed  $challengeGeneration        Nomor generasi tantangan (uint32 >= 1 int/string)
     * @param string $groupIdHashHexOrBin        Hash SHA-256 group_id MLS (64-char hex atau 32-byte bin)
     * @param mixed  $epoch                      Target epoch Welcome (wajib tepat 1)
     * @param string $challengeNonceHexOrBin     Nilai acak nonce tantangan (64-char hex atau 32-byte bin)
     * @return string                            Buffer biner tepat 158 byte
     * @throws InvalidArgumentException          Jika parameter konteks tidak memenuhi spesifikasi
     */
    public static function buildPayload(
        string $roomUuid,
        mixed $recipientUserId,
        string $welcomeOperationUuid,
        string $challengeUuid,
        mixed $challengeGeneration,
        string $groupIdHashHexOrBin,
        mixed $epoch,
        string $challengeNonceHexOrBin
    ): string {
        // 1. Domain Separator 26 byte
        $domainSeparator = self::DOMAIN_SEPARATOR;

        // 2. Room UUID 16 byte
        $roomUuidBin = self::uuidToBinary($roomUuid);
        if ($roomUuidBin === null) {
            throw new InvalidArgumentException('Room UUID tidak valid (wajib berupa UUIDv4 36 karakter).');
        }

        // 3. Recipient User ID 8 byte (uint64 Big-Endian)
        if (!self::isValidUnsigned64Int($recipientUserId)) {
            throw new InvalidArgumentException('Recipient User ID tidak valid (wajib berupa uint64 non-negatif).');
        }
        $userIdBin = pack('J', (int) $recipientUserId);

        // 4. Welcome Operation UUID 16 byte
        $welcomeOpUuidBin = self::uuidToBinary($welcomeOperationUuid);
        if ($welcomeOpUuidBin === null) {
            throw new InvalidArgumentException('Welcome Operation UUID tidak valid (wajib berupa UUIDv4 36 karakter).');
        }

        // 5. Challenge UUID 16 byte
        $challengeUuidBin = self::uuidToBinary($challengeUuid);
        if ($challengeUuidBin === null) {
            throw new InvalidArgumentException('Challenge UUID tidak valid (wajib berupa UUIDv4 36 karakter).');
        }

        // 6. Challenge Generation 4 byte (uint32 Big-Endian >= 1)
        if (!self::isValidUnsigned32Int($challengeGeneration)) {
            throw new InvalidArgumentException('Challenge Generation tidak valid (wajib berupa uint32 antara 1 s.d. 4294967295).');
        }
        $generationBin = pack('N', (int) $challengeGeneration);

        // 7. Group ID Hash 32 byte (SHA-256 binary)
        $groupIdHashBin = self::hexOrBinToBinary($groupIdHashHexOrBin, 32);
        if ($groupIdHashBin === null) {
            throw new InvalidArgumentException('Group ID Hash tidak valid (wajib berupa SHA-256 32 byte biner atau 64 hex char).');
        }

        // 8. Welcome Target Epoch 8 byte (uint64 Big-Endian, wajib tepat 1)
        if (!self::isValidEpoch($epoch)) {
            throw new InvalidArgumentException('Epoch target Welcome tidak valid (hanya menerima tepat bernilai 1).');
        }
        $epochBin = pack('J', (int) $epoch);

        // 9. Challenge Nonce 32 byte
        $nonceBin = self::hexOrBinToBinary($challengeNonceHexOrBin, 32);
        if ($nonceBin === null) {
            throw new InvalidArgumentException('Challenge Nonce tidak valid (wajib berupa 32 byte biner atau 64 hex char).');
        }

        // Penggabungan bit-demi-bit tanpa padding byte
        $payload = $domainSeparator .
            $roomUuidBin .
            $userIdBin .
            $welcomeOpUuidBin .
            $challengeUuidBin .
            $generationBin .
            $groupIdHashBin .
            $epochBin .
            $nonceBin;

        if (strlen($payload) !== self::PAYLOAD_LENGTH) {
            throw new RuntimeException('Kegagalan internal perakitan payload: Panjang buffer bukan 158 byte (' . strlen($payload) . ' byte).');
        }

        return $payload;
    }

    /**
     * Memeriksa ketersediaan fungsi native Sodium.
     * Dibuat protected agar dapat disimulasikan pada unit test terisolasi tanpa memodifikasi runtime PHP.
     */
    protected function isSodiumAvailable(): bool
    {
        return function_exists('sodium_crypto_sign_verify_detached');
    }

    /**
     * Memvalidasi apakah suatu nilai merupakan integer 64-bit unsigned kanonis (0 s.d. PHP_INT_MAX).
     *
     * Menerima tipe int >= 0 atau string digit desimal murni tanpa tanda, pecahan, spasi, atau notasi ilmiah.
     * Menggunakan perbandingan leksikografis terhadap string PHP_INT_MAX tanpa melewati floating-point.
     */
    public static function isValidUnsigned64Int(mixed $val): bool
    {
        if (is_int($val)) {
            return $val >= 0;
        }

        if (!is_string($val) || $val === '' || !ctype_digit($val)) {
            return false;
        }

        $maxStr = (string) PHP_INT_MAX; // "9223372036854775807" (19 digit)
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
     * Memvalidasi apakah suatu nilai merupakan integer 32-bit unsigned sah (1 s.d. 4.294.967.295).
     *
     * Challenge Generation dimulai dari nilai 1 dan tidak boleh 0 atau bernilai negatif.
     */
    public static function isValidUnsigned32Int(mixed $val): bool
    {
        if (is_int($val)) {
            return $val >= 1 && $val <= 4294967295;
        }

        if (!is_string($val) || $val === '' || !ctype_digit($val)) {
            return false;
        }

        // Tolak nilai 0 murni ("0", "00")
        if (ltrim($val, '0') === '') {
            return false;
        }

        $maxStr = '4294967295'; // 10 digit (0xFFFFFFFF)
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
     * Memvalidasi apakah nilai epoch memenuhi syarat Welcome pertama (wajib tepat bernilai 1).
     */
    public static function isValidEpoch(mixed $val): bool
    {
        if (is_int($val)) {
            return $val === self::TARGET_EPOCH;
        }

        if (is_string($val) && ctype_digit($val)) {
            return (int) $val === self::TARGET_EPOCH && $val === '1';
        }

        return false;
    }

    /**
     * Memvalidasi format string UUID v4 36-karakter kanonis.
     */
    public static function isValidUuid(string $uuid): bool
    {
        if (strlen($uuid) !== 36) {
            return false;
        }

        if ($uuid[8] !== '-' || $uuid[13] !== '-' || $uuid[18] !== '-' || $uuid[23] !== '-') {
            return false;
        }

        $clean = str_replace('-', '', $uuid);

        return strlen($clean) === 32 && ctype_xdigit($clean);
    }

    /**
     * Mengonversi string UUID 36-karakter atau buffer biner 16-byte menjadi raw binary 16-byte.
     */
    public static function uuidToBinary(string $uuid): ?string
    {
        if (strlen($uuid) === 16) {
            return $uuid;
        }

        if (!self::isValidUuid($uuid)) {
            return null;
        }

        return hex2bin(str_replace('-', '', $uuid));
    }

    /**
     * Mengonversi raw binary 16-byte menjadi string UUID 36-karakter huruf kecil berformat kanonis.
     */
    public static function binaryToUuid(string $binary): string
    {
        return vsprintf(
            '%s%s-%s-%s-%s-%s%s%s',
            str_split(bin2hex($binary), 4)
        );
    }

    /**
     * Mengonversi string heksadesimal atau buffer biner mentah menjadi string biner dengan panjang target.
     */
    public static function hexOrBinToBinary(string $val, int $expectedLength): ?string
    {
        if (strlen($val) === $expectedLength) {
            return $val;
        }

        if (strlen($val) === $expectedLength * 2 && ctype_xdigit($val)) {
            return hex2bin($val);
        }

        return null;
    }
}
