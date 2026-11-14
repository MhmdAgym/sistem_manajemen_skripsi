<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\BchatWelcomeAckVerificationService;
use App\ValueObjects\BchatWelcomeAckVerificationResult;
use PHPUnit\Framework\TestCase;

/**
 * Unit Test komprehensif untuk BchatWelcomeAckVerificationService.
 *
 * Menguji seluruh kontrak teknis B-CHAT-13D, B-CHAT-13 Subbab 4.2, dan B-CHAT-22B Tahap 1B:
 * - Struktur tepat 158 byte dengan 9 komponen kanonikal.
 * - Prinsip Zero Trust (backend merakit sendiri payload dari konteks server).
 * - Epoch wajib tepat 1.
 * - Boundary integer, pencegahan float loss, big-endian bit-demi-bit.
 * - Detached signature Ed25519 native Sodium.
 * - Fail-closed error codes dan timing-attack resistance.
 */
class BchatWelcomeAckVerificationServiceTest extends TestCase
{
    private BchatWelcomeAckVerificationService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new BchatWelcomeAckVerificationService();
    }

    /**
     * Helper untuk membuat set data konteks uji yang valid beserta keypair Ed25519 nyata.
     *
     * @return array<string, mixed>
     */
    private function createValidContext(): array
    {
        $roomUuid = '11111111-2222-4333-8444-555555555555';
        $recipientUserId = 987654321;
        $welcomeOperationUuid = '22222222-3333-4444-8555-666666666666';
        $challengeUuid = '33333333-4444-4555-8666-777777777777';
        $challengeGeneration = 1;
        $groupIdHashHex = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
        $epoch = 1;
        $challengeNonceHex = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';

        // Bangkitkan keypair Ed25519 nyata untuk pengujian kriptografis
        $keypair = sodium_crypto_sign_keypair();
        $secretKey = sodium_crypto_sign_secretkey($keypair);
        $publicKey = sodium_crypto_sign_publickey($keypair);

        // Rakit payload kanonikal 158 byte
        $payload = BchatWelcomeAckVerificationService::buildPayload(
            roomUuid: $roomUuid,
            recipientUserId: $recipientUserId,
            welcomeOperationUuid: $welcomeOperationUuid,
            challengeUuid: $challengeUuid,
            challengeGeneration: $challengeGeneration,
            groupIdHashHexOrBin: $groupIdHashHex,
            epoch: $epoch,
            challengeNonceHexOrBin: $challengeNonceHex
        );

        // Buat detached signature Ed25519 sah 64 byte
        $signature = sodium_crypto_sign_detached($payload, $secretKey);

        return [
            'roomUuid'             => $roomUuid,
            'recipientUserId'      => $recipientUserId,
            'welcomeOperationUuid' => $welcomeOperationUuid,
            'challengeUuid'        => $challengeUuid,
            'challengeGeneration'  => $challengeGeneration,
            'groupIdHashHex'       => $groupIdHashHex,
            'epoch'                => $epoch,
            'challengeNonceHex'    => $challengeNonceHex,
            'keypair'              => $keypair,
            'secretKey'            => $secretKey,
            'publicKey'            => $publicKey,
            'payload'              => $payload,
            'signature'            => $signature,
        ];
    }

    /**
     * 1. Payload hasil rakitan harus tepat 158 byte dengan offset kanonis yang presisi.
     */
    public function test_payload_must_be_strictly_158_bytes(): void
    {
        $ctx = $this->createValidContext();
        $payload = $ctx['payload'];

        $this->assertSame(158, strlen($payload));

        // Offset 0..25: Domain Separator (26 byte)
        $this->assertSame('UNUHA-BCHAT-WELCOME-ACK-V1', substr($payload, 0, 26));

        // Offset 26..41: Room UUID (16 byte raw binary)
        $expectedRoomBin = hex2bin(str_replace('-', '', $ctx['roomUuid']));
        $this->assertSame($expectedRoomBin, substr($payload, 26, 16));

        // Offset 42..49: Recipient User ID (8 byte uint64be)
        $this->assertSame($ctx['recipientUserId'], unpack('J', substr($payload, 42, 8))[1]);

        // Offset 50..65: Welcome Operation UUID (16 byte raw binary)
        $expectedWelcomeBin = hex2bin(str_replace('-', '', $ctx['welcomeOperationUuid']));
        $this->assertSame($expectedWelcomeBin, substr($payload, 50, 16));

        // Offset 66..81: Challenge UUID (16 byte raw binary)
        $expectedChallengeBin = hex2bin(str_replace('-', '', $ctx['challengeUuid']));
        $this->assertSame($expectedChallengeBin, substr($payload, 66, 16));

        // Offset 82..85: Challenge Generation (4 byte uint32be)
        $this->assertSame($ctx['challengeGeneration'], unpack('N', substr($payload, 82, 4))[1]);

        // Offset 86..117: Group ID Hash (32 byte binary)
        $this->assertSame(hex2bin($ctx['groupIdHashHex']), substr($payload, 86, 32));

        // Offset 118..125: Epoch (8 byte uint64be)
        $this->assertSame(1, unpack('J', substr($payload, 118, 8))[1]);

        // Offset 126..157: Nonce (32 byte binary)
        $this->assertSame(hex2bin($ctx['challengeNonceHex']), substr($payload, 126, 32));
    }

    /**
     * 2. Signature valid memverifikasi sukses via verify() dan menghasilkan parsedData lengkap.
     */
    public function test_valid_signature_verifies_successfully(): void
    {
        $ctx = $this->createValidContext();

        $result = $this->service->verify(
            signatureBinary: $ctx['signature'],
            publicKeyBinary: $ctx['publicKey'],
            roomUuid: $ctx['roomUuid'],
            recipientUserId: $ctx['recipientUserId'],
            welcomeOperationUuid: $ctx['welcomeOperationUuid'],
            challengeUuid: $ctx['challengeUuid'],
            challengeGeneration: $ctx['challengeGeneration'],
            groupIdHashHexOrBin: $ctx['groupIdHashHex'],
            epoch: $ctx['epoch'],
            challengeNonceHexOrBin: $ctx['challengeNonceHex']
        );

        $this->assertTrue($result->isValid());
        $this->assertNull($result->getErrorCode());

        $parsed = $result->getParsedData();
        $this->assertIsArray($parsed);
        $this->assertSame($ctx['roomUuid'], $parsed['room_uuid']);
        $this->assertSame($ctx['recipientUserId'], $parsed['recipient_user_id']);
        $this->assertSame($ctx['welcomeOperationUuid'], $parsed['welcome_operation_uuid']);
        $this->assertSame($ctx['challengeUuid'], $parsed['challenge_uuid']);
        $this->assertSame($ctx['challengeGeneration'], $parsed['challenge_generation']);
        $this->assertSame($ctx['groupIdHashHex'], $parsed['group_id_hash_hex']);
        $this->assertSame(1, $parsed['epoch']);
        $this->assertSame($ctx['challengeNonceHex'], $parsed['challenge_nonce_hex']);

        // Verifikasi tidak ada public_key_hex di parsedData
        $this->assertArrayNotHasKey('public_key_hex', $parsed);

        // Payload hash harus cocok dengan hash payload 158-byte
        $this->assertSame(hash('sha256', $ctx['payload']), $result->getPayloadHash());
    }

    /**
     * 3. Signature salah / korup ditolak (SIGNATURE_VERIFICATION_FAILED).
     */
    public function test_invalid_signature_fails_verification(): void
    {
        $ctx = $this->createValidContext();

        // Ubah 1 byte pada signature
        $corruptSignature = $ctx['signature'];
        $corruptSignature[63] = chr(ord($corruptSignature[63]) ^ 0x01);

        $result = $this->service->verify(
            signatureBinary: $corruptSignature,
            publicKeyBinary: $ctx['publicKey'],
            roomUuid: $ctx['roomUuid'],
            recipientUserId: $ctx['recipientUserId'],
            welcomeOperationUuid: $ctx['welcomeOperationUuid'],
            challengeUuid: $ctx['challengeUuid'],
            challengeGeneration: $ctx['challengeGeneration'],
            groupIdHashHexOrBin: $ctx['groupIdHashHex'],
            epoch: $ctx['epoch'],
            challengeNonceHexOrBin: $ctx['challengeNonceHex']
        );

        $this->assertFalse($result->isValid());
        $this->assertSame('SIGNATURE_VERIFICATION_FAILED', $result->getErrorCode());
        $this->assertNull($result->getParsedData());
        $this->assertNull($result->getPayloadHash());
    }

    /**
     * 4. Konteks server termutasi setelah ditandatangani gagal verifikasi signature.
     */
    public function test_tampered_payload_context_fails_verification(): void
    {
        $ctx = $this->createValidContext();

        // Mutasi 1 byte pada nonce konteks server
        $tamperedNonceHex = substr($ctx['challengeNonceHex'], 0, 62) . 'cc';

        $result = $this->service->verify(
            signatureBinary: $ctx['signature'],
            publicKeyBinary: $ctx['publicKey'],
            roomUuid: $ctx['roomUuid'],
            recipientUserId: $ctx['recipientUserId'],
            welcomeOperationUuid: $ctx['welcomeOperationUuid'],
            challengeUuid: $ctx['challengeUuid'],
            challengeGeneration: $ctx['challengeGeneration'],
            groupIdHashHexOrBin: $ctx['groupIdHashHex'],
            epoch: $ctx['epoch'],
            challengeNonceHexOrBin: $tamperedNonceHex
        );

        $this->assertFalse($result->isValid());
        $this->assertSame('SIGNATURE_VERIFICATION_FAILED', $result->getErrorCode());
    }

    /**
     * 5. Payload lebih pendek dari 158 byte ditolak (INVALID_PAYLOAD_LENGTH).
     */
    public function test_payload_shorter_than_158_bytes_rejected(): void
    {
        $ctx = $this->createValidContext();

        $shortPayload = substr($ctx['payload'], 0, 157);
        $resParse = $this->service->parsePayload($shortPayload);
        $this->assertFalse($resParse->isValid());
        $this->assertSame('INVALID_PAYLOAD_LENGTH', $resParse->getErrorCode());

        $emptyPayload = '';
        $resEmpty = $this->service->parsePayload($emptyPayload);
        $this->assertFalse($resEmpty->isValid());
        $this->assertSame('INVALID_PAYLOAD_LENGTH', $resEmpty->getErrorCode());
    }

    /**
     * 6. Payload lebih panjang dari 158 byte ditolak (INVALID_PAYLOAD_LENGTH).
     */
    public function test_payload_longer_than_158_bytes_rejected(): void
    {
        $ctx = $this->createValidContext();

        $longPayload = $ctx['payload'] . "\x00";
        $resParse = $this->service->parsePayload($longPayload);
        $this->assertFalse($resParse->isValid());
        $this->assertSame('INVALID_PAYLOAD_LENGTH', $resParse->getErrorCode());
    }

    /**
     * 7. Domain Separator salah ditolak (INVALID_DOMAIN_SEPARATOR).
     */
    public function test_invalid_domain_separator_rejected(): void
    {
        $ctx = $this->createValidContext();

        // Ganti 26 byte pertama dengan header usang atau sembarang string
        $corruptDomain = 'UNUHA-BCHAT-ACK-V1' . str_repeat("\x00", 8); // 26 byte salah
        $badPayload = $corruptDomain . substr($ctx['payload'], 26);

        $resParse = $this->service->parsePayload($badPayload);
        $this->assertFalse($resParse->isValid());
        $this->assertSame('INVALID_DOMAIN_SEPARATOR', $resParse->getErrorCode());
    }

    /**
     * 8. Input UUID tidak valid ditolak (EXPECTED_*_UUID_INVALID).
     */
    public function test_invalid_uuid_inputs_rejected(): void
    {
        $ctx = $this->createValidContext();

        $invalidUuids = [
            'not-a-uuid',
            '11111111-2222-4333-8444-55555555555g', // 'g' bukan hex
            '11111111222243338444555555555555',     // tanpa tanda strip
            '',
        ];

        foreach ($invalidUuids as $badUuid) {
            // Uji pada roomUuid
            $resRoom = $this->service->verify(
                signatureBinary: $ctx['signature'],
                publicKeyBinary: $ctx['publicKey'],
                roomUuid: $badUuid,
                recipientUserId: $ctx['recipientUserId'],
                welcomeOperationUuid: $ctx['welcomeOperationUuid'],
                challengeUuid: $ctx['challengeUuid'],
                challengeGeneration: $ctx['challengeGeneration'],
                groupIdHashHexOrBin: $ctx['groupIdHashHex'],
                epoch: $ctx['epoch'],
                challengeNonceHexOrBin: $ctx['challengeNonceHex']
            );
            $this->assertFalse($resRoom->isValid());
            $this->assertSame('EXPECTED_ROOM_UUID_INVALID', $resRoom->getErrorCode());

            // Uji pada welcomeOperationUuid
            $resOp = $this->service->verify(
                signatureBinary: $ctx['signature'],
                publicKeyBinary: $ctx['publicKey'],
                roomUuid: $ctx['roomUuid'],
                recipientUserId: $ctx['recipientUserId'],
                welcomeOperationUuid: $badUuid,
                challengeUuid: $ctx['challengeUuid'],
                challengeGeneration: $ctx['challengeGeneration'],
                groupIdHashHexOrBin: $ctx['groupIdHashHex'],
                epoch: $ctx['epoch'],
                challengeNonceHexOrBin: $ctx['challengeNonceHex']
            );
            $this->assertFalse($resOp->isValid());
            $this->assertSame('EXPECTED_WELCOME_OP_UUID_INVALID', $resOp->getErrorCode());

            // Uji pada challengeUuid
            $resChl = $this->service->verify(
                signatureBinary: $ctx['signature'],
                publicKeyBinary: $ctx['publicKey'],
                roomUuid: $ctx['roomUuid'],
                recipientUserId: $ctx['recipientUserId'],
                welcomeOperationUuid: $ctx['welcomeOperationUuid'],
                challengeUuid: $badUuid,
                challengeGeneration: $ctx['challengeGeneration'],
                groupIdHashHexOrBin: $ctx['groupIdHashHex'],
                epoch: $ctx['epoch'],
                challengeNonceHexOrBin: $ctx['challengeNonceHex']
            );
            $this->assertFalse($resChl->isValid());
            $this->assertSame('EXPECTED_CHALLENGE_UUID_INVALID', $resChl->getErrorCode());
        }
    }

    /**
     * 9. Group ID Hash bukan 32 byte biner / bukan 64 hex ditolak (EXPECTED_GROUP_ID_HASH_INVALID).
     */
    public function test_group_id_hash_not_32_bytes_rejected(): void
    {
        $ctx = $this->createValidContext();

        $invalidHashes = [
            str_repeat('a', 62), // 31 byte hex
            str_repeat('a', 66), // 33 byte hex
            str_repeat('g', 64), // non-hex character
            str_repeat("\x00", 31),
        ];

        foreach ($invalidHashes as $badHash) {
            $res = $this->service->verify(
                signatureBinary: $ctx['signature'],
                publicKeyBinary: $ctx['publicKey'],
                roomUuid: $ctx['roomUuid'],
                recipientUserId: $ctx['recipientUserId'],
                welcomeOperationUuid: $ctx['welcomeOperationUuid'],
                challengeUuid: $ctx['challengeUuid'],
                challengeGeneration: $ctx['challengeGeneration'],
                groupIdHashHexOrBin: $badHash,
                epoch: $ctx['epoch'],
                challengeNonceHexOrBin: $ctx['challengeNonceHex']
            );
            $this->assertFalse($res->isValid());
            $this->assertSame('EXPECTED_GROUP_ID_HASH_INVALID', $res->getErrorCode());
        }
    }

    /**
     * 10. Nonce bukan 32 byte biner / bukan 64 hex ditolak (EXPECTED_NONCE_INVALID).
     */
    public function test_challenge_nonce_not_32_bytes_rejected(): void
    {
        $ctx = $this->createValidContext();

        $invalidNonces = [
            str_repeat('b', 62),
            str_repeat('b', 66),
            str_repeat('z', 64),
            str_repeat("\x00", 33),
        ];

        foreach ($invalidNonces as $badNonce) {
            $res = $this->service->verify(
                signatureBinary: $ctx['signature'],
                publicKeyBinary: $ctx['publicKey'],
                roomUuid: $ctx['roomUuid'],
                recipientUserId: $ctx['recipientUserId'],
                welcomeOperationUuid: $ctx['welcomeOperationUuid'],
                challengeUuid: $ctx['challengeUuid'],
                challengeGeneration: $ctx['challengeGeneration'],
                groupIdHashHexOrBin: $ctx['groupIdHashHex'],
                epoch: $ctx['epoch'],
                challengeNonceHexOrBin: $badNonce
            );
            $this->assertFalse($res->isValid());
            $this->assertSame('EXPECTED_NONCE_INVALID', $res->getErrorCode());
        }
    }

    /**
     * 11. Integer negatif ditolak.
     */
    public function test_negative_integers_rejected(): void
    {
        $ctx = $this->createValidContext();

        // User ID negatif
        $resUser = $this->service->verify(
            signatureBinary: $ctx['signature'],
            publicKeyBinary: $ctx['publicKey'],
            roomUuid: $ctx['roomUuid'],
            recipientUserId: -1,
            welcomeOperationUuid: $ctx['welcomeOperationUuid'],
            challengeUuid: $ctx['challengeUuid'],
            challengeGeneration: $ctx['challengeGeneration'],
            groupIdHashHexOrBin: $ctx['groupIdHashHex'],
            epoch: $ctx['epoch'],
            challengeNonceHexOrBin: $ctx['challengeNonceHex']
        );
        $this->assertFalse($resUser->isValid());
        $this->assertSame('EXPECTED_USER_ID_INVALID', $resUser->getErrorCode());

        // Generation negatif
        $resGen = $this->service->verify(
            signatureBinary: $ctx['signature'],
            publicKeyBinary: $ctx['publicKey'],
            roomUuid: $ctx['roomUuid'],
            recipientUserId: $ctx['recipientUserId'],
            welcomeOperationUuid: $ctx['welcomeOperationUuid'],
            challengeUuid: $ctx['challengeUuid'],
            challengeGeneration: -5,
            groupIdHashHexOrBin: $ctx['groupIdHashHex'],
            epoch: $ctx['epoch'],
            challengeNonceHexOrBin: $ctx['challengeNonceHex']
        );
        $this->assertFalse($resGen->isValid());
        $this->assertSame('EXPECTED_GENERATION_INVALID', $resGen->getErrorCode());

        // Epoch negatif
        $resEpoch = $this->service->verify(
            signatureBinary: $ctx['signature'],
            publicKeyBinary: $ctx['publicKey'],
            roomUuid: $ctx['roomUuid'],
            recipientUserId: $ctx['recipientUserId'],
            welcomeOperationUuid: $ctx['welcomeOperationUuid'],
            challengeUuid: $ctx['challengeUuid'],
            challengeGeneration: $ctx['challengeGeneration'],
            groupIdHashHexOrBin: $ctx['groupIdHashHex'],
            epoch: -1,
            challengeNonceHexOrBin: $ctx['challengeNonceHex']
        );
        $this->assertFalse($resEpoch->isValid());
        $this->assertSame('EPOCH_MISMATCH', $resEpoch->getErrorCode());
    }

    /**
     * 12. Integer melebihi PHP_INT_MAX (uint64) dan 2^32-1 (uint32) ditolak sebelum casting.
     */
    public function test_integers_exceeding_platform_max_rejected(): void
    {
        $ctx = $this->createValidContext();

        // User ID melebihi PHP_INT_MAX ("9223372036854775808")
        $resUser = $this->service->verify(
            signatureBinary: $ctx['signature'],
            publicKeyBinary: $ctx['publicKey'],
            roomUuid: $ctx['roomUuid'],
            recipientUserId: '9223372036854775808',
            welcomeOperationUuid: $ctx['welcomeOperationUuid'],
            challengeUuid: $ctx['challengeUuid'],
            challengeGeneration: $ctx['challengeGeneration'],
            groupIdHashHexOrBin: $ctx['groupIdHashHex'],
            epoch: $ctx['epoch'],
            challengeNonceHexOrBin: $ctx['challengeNonceHex']
        );
        $this->assertFalse($resUser->isValid());
        $this->assertSame('EXPECTED_USER_ID_INVALID', $resUser->getErrorCode());

        // Generation melebihi 2^32 - 1 ("4294967296")
        $resGen = $this->service->verify(
            signatureBinary: $ctx['signature'],
            publicKeyBinary: $ctx['publicKey'],
            roomUuid: $ctx['roomUuid'],
            recipientUserId: $ctx['recipientUserId'],
            welcomeOperationUuid: $ctx['welcomeOperationUuid'],
            challengeUuid: $ctx['challengeUuid'],
            challengeGeneration: '4294967296',
            groupIdHashHexOrBin: $ctx['groupIdHashHex'],
            epoch: $ctx['epoch'],
            challengeNonceHexOrBin: $ctx['challengeNonceHex']
        );
        $this->assertFalse($resGen->isValid());
        $this->assertSame('EXPECTED_GENERATION_INVALID', $resGen->getErrorCode());
    }

    /**
     * 13. Input bertipe float, boolean, null, array, notasi ilmiah, dan spasi ditolak.
     */
    public function test_float_boolean_null_array_inputs_rejected(): void
    {
        $ctx = $this->createValidContext();

        $invalidVals = [
            1.5,
            '1.5',
            true,
            false,
            null,
            [],
            '1e5',
            ' 123 ',
            '+100',
        ];

        foreach ($invalidVals as $badVal) {
            $res = $this->service->verify(
                signatureBinary: $ctx['signature'],
                publicKeyBinary: $ctx['publicKey'],
                roomUuid: $ctx['roomUuid'],
                recipientUserId: $badVal,
                welcomeOperationUuid: $ctx['welcomeOperationUuid'],
                challengeUuid: $ctx['challengeUuid'],
                challengeGeneration: $ctx['challengeGeneration'],
                groupIdHashHexOrBin: $ctx['groupIdHashHex'],
                epoch: $ctx['epoch'],
                challengeNonceHexOrBin: $ctx['challengeNonceHex']
            );
            $this->assertFalse($res->isValid(), 'Nilai invalid ' . var_export($badVal, true) . ' harus ditolak.');
            $this->assertSame('EXPECTED_USER_ID_INVALID', $res->getErrorCode());
        }
    }

    /**
     * 14. Boundary User ID pada 0 dan PHP_INT_MAX diterima dan diproses aman.
     */
    public function test_boundary_integers_zero_and_php_int_max(): void
    {
        $ctx = $this->createValidContext();

        // 1. Uji batas bawah: User ID = 0
        $payloadZero = BchatWelcomeAckVerificationService::buildPayload(
            roomUuid: $ctx['roomUuid'],
            recipientUserId: 0,
            welcomeOperationUuid: $ctx['welcomeOperationUuid'],
            challengeUuid: $ctx['challengeUuid'],
            challengeGeneration: $ctx['challengeGeneration'],
            groupIdHashHexOrBin: $ctx['groupIdHashHex'],
            epoch: 1,
            challengeNonceHexOrBin: $ctx['challengeNonceHex']
        );
        $this->assertSame(158, strlen($payloadZero));
        $this->assertSame(0, unpack('J', substr($payloadZero, 42, 8))[1]);

        $sigZero = sodium_crypto_sign_detached($payloadZero, $ctx['secretKey']);
        $resZero = $this->service->verify(
            signatureBinary: $sigZero,
            publicKeyBinary: $ctx['publicKey'],
            roomUuid: $ctx['roomUuid'],
            recipientUserId: 0,
            welcomeOperationUuid: $ctx['welcomeOperationUuid'],
            challengeUuid: $ctx['challengeUuid'],
            challengeGeneration: $ctx['challengeGeneration'],
            groupIdHashHexOrBin: $ctx['groupIdHashHex'],
            epoch: 1,
            challengeNonceHexOrBin: $ctx['challengeNonceHex']
        );
        $this->assertTrue($resZero->isValid());
        $this->assertSame(0, $resZero->getParsedData()['recipient_user_id']);

        // 2. Uji batas atas: User ID = PHP_INT_MAX (9223372036854775807)
        $payloadMax = BchatWelcomeAckVerificationService::buildPayload(
            roomUuid: $ctx['roomUuid'],
            recipientUserId: PHP_INT_MAX,
            welcomeOperationUuid: $ctx['welcomeOperationUuid'],
            challengeUuid: $ctx['challengeUuid'],
            challengeGeneration: $ctx['challengeGeneration'],
            groupIdHashHexOrBin: $ctx['groupIdHashHex'],
            epoch: 1,
            challengeNonceHexOrBin: $ctx['challengeNonceHex']
        );
        $this->assertSame(158, strlen($payloadMax));
        $this->assertSame("\x7f\xff\xff\xff\xff\xff\xff\xff", substr($payloadMax, 42, 8));
        $this->assertSame(PHP_INT_MAX, unpack('J', substr($payloadMax, 42, 8))[1]);

        $sigMax = sodium_crypto_sign_detached($payloadMax, $ctx['secretKey']);
        $resMax = $this->service->verify(
            signatureBinary: $sigMax,
            publicKeyBinary: $ctx['publicKey'],
            roomUuid: $ctx['roomUuid'],
            recipientUserId: PHP_INT_MAX,
            welcomeOperationUuid: $ctx['welcomeOperationUuid'],
            challengeUuid: $ctx['challengeUuid'],
            challengeGeneration: $ctx['challengeGeneration'],
            groupIdHashHexOrBin: $ctx['groupIdHashHex'],
            epoch: 1,
            challengeNonceHexOrBin: $ctx['challengeNonceHex']
        );
        $this->assertTrue($resMax->isValid());
        $this->assertSame(PHP_INT_MAX, $resMax->getParsedData()['recipient_user_id']);
    }

    /**
     * 15. Challenge Generation mematuhi batas bawah 1 dan batas atas 4.294.967.295 (0 ditolak).
     */
    public function test_challenge_generation_boundaries(): void
    {
        $ctx = $this->createValidContext();

        // 0 ditolak (karena generasi dimulai dari 1)
        $resZero = $this->service->verify(
            signatureBinary: $ctx['signature'],
            publicKeyBinary: $ctx['publicKey'],
            roomUuid: $ctx['roomUuid'],
            recipientUserId: $ctx['recipientUserId'],
            welcomeOperationUuid: $ctx['welcomeOperationUuid'],
            challengeUuid: $ctx['challengeUuid'],
            challengeGeneration: 0,
            groupIdHashHexOrBin: $ctx['groupIdHashHex'],
            epoch: 1,
            challengeNonceHexOrBin: $ctx['challengeNonceHex']
        );
        $this->assertFalse($resZero->isValid());
        $this->assertSame('EXPECTED_GENERATION_INVALID', $resZero->getErrorCode());

        // Batas atas uint32: 4294967295
        $payloadMaxGen = BchatWelcomeAckVerificationService::buildPayload(
            roomUuid: $ctx['roomUuid'],
            recipientUserId: $ctx['recipientUserId'],
            welcomeOperationUuid: $ctx['welcomeOperationUuid'],
            challengeUuid: $ctx['challengeUuid'],
            challengeGeneration: 4294967295,
            groupIdHashHexOrBin: $ctx['groupIdHashHex'],
            epoch: 1,
            challengeNonceHexOrBin: $ctx['challengeNonceHex']
        );
        $this->assertSame("\xff\xff\xff\xff", substr($payloadMaxGen, 82, 4));

        $sigMaxGen = sodium_crypto_sign_detached($payloadMaxGen, $ctx['secretKey']);
        $resMaxGen = $this->service->verify(
            signatureBinary: $sigMaxGen,
            publicKeyBinary: $ctx['publicKey'],
            roomUuid: $ctx['roomUuid'],
            recipientUserId: $ctx['recipientUserId'],
            welcomeOperationUuid: $ctx['welcomeOperationUuid'],
            challengeUuid: $ctx['challengeUuid'],
            challengeGeneration: 4294967295,
            groupIdHashHexOrBin: $ctx['groupIdHashHex'],
            epoch: 1,
            challengeNonceHexOrBin: $ctx['challengeNonceHex']
        );
        $this->assertTrue($resMaxGen->isValid());
        $this->assertSame(4294967295, $resMaxGen->getParsedData()['challenge_generation']);
    }

    /**
     * 16. Epoch hanya menerima tepat bernilai 1 (0 ditolak, selain 1 ditolak).
     */
    public function test_epoch_must_strictly_be_one(): void
    {
        $ctx = $this->createValidContext();

        $invalidEpochs = [0, '0', 2, '2', 10, -1, '1.0'];

        foreach ($invalidEpochs as $badEpoch) {
            $res = $this->service->verify(
                signatureBinary: $ctx['signature'],
                publicKeyBinary: $ctx['publicKey'],
                roomUuid: $ctx['roomUuid'],
                recipientUserId: $ctx['recipientUserId'],
                welcomeOperationUuid: $ctx['welcomeOperationUuid'],
                challengeUuid: $ctx['challengeUuid'],
                challengeGeneration: $ctx['challengeGeneration'],
                groupIdHashHexOrBin: $ctx['groupIdHashHex'],
                epoch: $badEpoch,
                challengeNonceHexOrBin: $ctx['challengeNonceHex']
            );
            $this->assertFalse($res->isValid(), 'Epoch ' . var_export($badEpoch, true) . ' harus ditolak.');
            $this->assertSame('EPOCH_MISMATCH', $res->getErrorCode());
        }
    }

    /**
     * 17. Serialisasi integer big-endian bit-demi-bit sesuai standar RFC 9420.
     */
    public function test_big_endian_integer_serialization_exact_bytes(): void
    {
        $ctx = $this->createValidContext();
        $payload = $ctx['payload'];

        // Recipient User ID = 987654321 = 0x3ade68b1 -> uint64be 8 byte
        $expectedUserIdBytes = "\x00\x00\x00\x00\x3a\xde\x68\xb1";
        $this->assertSame($expectedUserIdBytes, substr($payload, 42, 8));

        // Challenge Generation = 1 = 0x00000001 -> uint32be 4 byte
        $expectedGenBytes = "\x00\x00\x00\x01";
        $this->assertSame($expectedGenBytes, substr($payload, 82, 4));

        // Epoch = 1 = 0x0000000000000001 -> uint64be 8 byte
        $expectedEpochBytes = "\x00\x00\x00\x00\x00\x00\x00\x01";
        $this->assertSame($expectedEpochBytes, substr($payload, 118, 8));
    }

    /**
     * 18. Public key bukan 32 byte ditolak (INVALID_PUBLIC_KEY_LENGTH).
     */
    public function test_invalid_public_key_length_rejected(): void
    {
        $ctx = $this->createValidContext();

        // 31 byte
        $res31 = $this->service->verify(
            signatureBinary: $ctx['signature'],
            publicKeyBinary: str_repeat("\x00", 31),
            roomUuid: $ctx['roomUuid'],
            recipientUserId: $ctx['recipientUserId'],
            welcomeOperationUuid: $ctx['welcomeOperationUuid'],
            challengeUuid: $ctx['challengeUuid'],
            challengeGeneration: $ctx['challengeGeneration'],
            groupIdHashHexOrBin: $ctx['groupIdHashHex'],
            epoch: 1,
            challengeNonceHexOrBin: $ctx['challengeNonceHex']
        );
        $this->assertFalse($res31->isValid());
        $this->assertSame('INVALID_PUBLIC_KEY_LENGTH', $res31->getErrorCode());

        // 33 byte
        $res33 = $this->service->verify(
            signatureBinary: $ctx['signature'],
            publicKeyBinary: str_repeat("\x00", 33),
            roomUuid: $ctx['roomUuid'],
            recipientUserId: $ctx['recipientUserId'],
            welcomeOperationUuid: $ctx['welcomeOperationUuid'],
            challengeUuid: $ctx['challengeUuid'],
            challengeGeneration: $ctx['challengeGeneration'],
            groupIdHashHexOrBin: $ctx['groupIdHashHex'],
            epoch: 1,
            challengeNonceHexOrBin: $ctx['challengeNonceHex']
        );
        $this->assertFalse($res33->isValid());
        $this->assertSame('INVALID_PUBLIC_KEY_LENGTH', $res33->getErrorCode());
    }

    /**
     * 19. Signature bukan 64 byte ditolak (INVALID_SIGNATURE_LENGTH).
     */
    public function test_invalid_signature_length_rejected(): void
    {
        $ctx = $this->createValidContext();

        // 63 byte
        $res63 = $this->service->verify(
            signatureBinary: str_repeat("\x00", 63),
            publicKeyBinary: $ctx['publicKey'],
            roomUuid: $ctx['roomUuid'],
            recipientUserId: $ctx['recipientUserId'],
            welcomeOperationUuid: $ctx['welcomeOperationUuid'],
            challengeUuid: $ctx['challengeUuid'],
            challengeGeneration: $ctx['challengeGeneration'],
            groupIdHashHexOrBin: $ctx['groupIdHashHex'],
            epoch: 1,
            challengeNonceHexOrBin: $ctx['challengeNonceHex']
        );
        $this->assertFalse($res63->isValid());
        $this->assertSame('INVALID_SIGNATURE_LENGTH', $res63->getErrorCode());

        // 65 byte
        $res65 = $this->service->verify(
            signatureBinary: str_repeat("\x00", 65),
            publicKeyBinary: $ctx['publicKey'],
            roomUuid: $ctx['roomUuid'],
            recipientUserId: $ctx['recipientUserId'],
            welcomeOperationUuid: $ctx['welcomeOperationUuid'],
            challengeUuid: $ctx['challengeUuid'],
            challengeGeneration: $ctx['challengeGeneration'],
            groupIdHashHexOrBin: $ctx['groupIdHashHex'],
            epoch: 1,
            challengeNonceHexOrBin: $ctx['challengeNonceHex']
        );
        $this->assertFalse($res65->isValid());
        $this->assertSame('INVALID_SIGNATURE_LENGTH', $res65->getErrorCode());
    }

    /**
     * 20. Sodium tidak tersedia menghasilkan kegagalan eksplisit yang aman (SODIUM_EXTENSION_NOT_AVAILABLE).
     *
     * Diuji secara realistis tanpa memodifikasi runtime PHP menggunakan anonymous subclass terisolasi.
     */
    public function test_sodium_unavailable_safety_handling(): void
    {
        $ctx = $this->createValidContext();

        // Subclass anonim yang menyimulasikan ekstensi sodium tidak terpasang
        $serviceWithoutSodium = new class extends BchatWelcomeAckVerificationService {
            protected function isSodiumAvailable(): bool
            {
                return false;
            }
        };

        $result = $serviceWithoutSodium->verify(
            signatureBinary: $ctx['signature'],
            publicKeyBinary: $ctx['publicKey'],
            roomUuid: $ctx['roomUuid'],
            recipientUserId: $ctx['recipientUserId'],
            welcomeOperationUuid: $ctx['welcomeOperationUuid'],
            challengeUuid: $ctx['challengeUuid'],
            challengeGeneration: $ctx['challengeGeneration'],
            groupIdHashHexOrBin: $ctx['groupIdHashHex'],
            epoch: 1,
            challengeNonceHexOrBin: $ctx['challengeNonceHex']
        );

        $this->assertFalse($result->isValid());
        $this->assertSame('SODIUM_EXTENSION_NOT_AVAILABLE', $result->getErrorCode());
        $this->assertNull($result->getParsedData());
        $this->assertNull($result->getPayloadHash());
    }

    /**
     * 21. Round-trip formatter dan parser memulihkan seluruh 8 komponen secara identik.
     */
    public function test_round_trip_formatter_and_parser(): void
    {
        $ctx = $this->createValidContext();

        // Formatter merakit payload kanonikal 158 byte
        $payload = $ctx['payload'];

        // Parser mengekstrak dan memvalidasi buffer biner
        $parseResult = $this->service->parsePayload($payload);
        $this->assertTrue($parseResult->isValid());

        $parsed = $parseResult->getParsedData();
        $this->assertNotNull($parsed);

        $this->assertSame($ctx['roomUuid'], $parsed['room_uuid']);
        $this->assertSame($ctx['recipientUserId'], $parsed['recipient_user_id']);
        $this->assertSame($ctx['welcomeOperationUuid'], $parsed['welcome_operation_uuid']);
        $this->assertSame($ctx['challengeUuid'], $parsed['challenge_uuid']);
        $this->assertSame($ctx['challengeGeneration'], $parsed['challenge_generation']);
        $this->assertSame($ctx['groupIdHashHex'], $parsed['group_id_hash_hex']);
        $this->assertSame(1, $parsed['epoch']);
        $this->assertSame($ctx['challengeNonceHex'], $parsed['challenge_nonce_hex']);
    }

    /**
     * 22. Payload hash SHA-256 identik dengan hash dari buffer 158-byte yang diverifikasi.
     */
    public function test_payload_hash_sha256_matches_verified_buffer(): void
    {
        $ctx = $this->createValidContext();

        $result = $this->service->verify(
            signatureBinary: $ctx['signature'],
            publicKeyBinary: $ctx['publicKey'],
            roomUuid: $ctx['roomUuid'],
            recipientUserId: $ctx['recipientUserId'],
            welcomeOperationUuid: $ctx['welcomeOperationUuid'],
            challengeUuid: $ctx['challengeUuid'],
            challengeGeneration: $ctx['challengeGeneration'],
            groupIdHashHexOrBin: $ctx['groupIdHashHex'],
            epoch: 1,
            challengeNonceHexOrBin: $ctx['challengeNonceHex']
        );

        $this->assertTrue($result->isValid());
        $expectedHash = hash('sha256', $ctx['payload']);
        $this->assertSame($expectedHash, $result->getPayloadHash());
    }

    /**
     * 23. Verifikasi Prinsip Zero Trust: verify() merakit sendiri payload dari konteks server
     * dan tidak menerima masukan buffer payload atau payload hash dari pemanggil/klien.
     */
    public function test_verify_builds_payload_from_server_context_zero_trust(): void
    {
        $ctx = $this->createValidContext();

        // Klien mengirim signature yang sah
        // Server memasukkan parameter konteks tepercaya dari database
        $result = $this->service->verify(
            signatureBinary: $ctx['signature'],
            publicKeyBinary: $ctx['publicKey'],
            roomUuid: $ctx['roomUuid'],
            recipientUserId: $ctx['recipientUserId'],
            welcomeOperationUuid: $ctx['welcomeOperationUuid'],
            challengeUuid: $ctx['challengeUuid'],
            challengeGeneration: $ctx['challengeGeneration'],
            groupIdHashHexOrBin: $ctx['groupIdHashHex'],
            epoch: 1,
            challengeNonceHexOrBin: $ctx['challengeNonceHex']
        );

        $this->assertTrue($result->isValid());

        // Jika ada pihak yang mencoba mengubah konteks server (misal user_id target beralih ke ID lain)
        $diffUserId = $ctx['recipientUserId'] + 1;
        $resultMismatch = $this->service->verify(
            signatureBinary: $ctx['signature'],
            publicKeyBinary: $ctx['publicKey'],
            roomUuid: $ctx['roomUuid'],
            recipientUserId: $diffUserId,
            welcomeOperationUuid: $ctx['welcomeOperationUuid'],
            challengeUuid: $ctx['challengeUuid'],
            challengeGeneration: $ctx['challengeGeneration'],
            groupIdHashHexOrBin: $ctx['groupIdHashHex'],
            epoch: 1,
            challengeNonceHexOrBin: $ctx['challengeNonceHex']
        );

        // Backend merakit buffer baru dengan diffUserId sehingga verifikasi signature gagal seketika
        $this->assertFalse($resultMismatch->isValid());
        $this->assertSame('SIGNATURE_VERIFICATION_FAILED', $resultMismatch->getErrorCode());
    }

    /**
     * 24. Evaluasi buffer biner payload terhadap expected context mendeteksi mismatch parameter secara spesifik.
     */
    public function test_verify_payload_buffer_detects_context_mismatches(): void
    {
        $ctx = $this->createValidContext();

        // 1. Room UUID mismatch
        $resRoom = $this->service->verifyPayloadBuffer(
            payloadBinary: $ctx['payload'],
            signatureBinary: $ctx['signature'],
            publicKeyBinary: $ctx['publicKey'],
            expectedRoomUuid: '99999999-9999-4999-8999-999999999999',
            expectedRecipientUserId: $ctx['recipientUserId'],
            expectedWelcomeOperationUuid: $ctx['welcomeOperationUuid'],
            expectedChallengeUuid: $ctx['challengeUuid'],
            expectedChallengeGeneration: $ctx['challengeGeneration'],
            expectedGroupIdHashHexOrBin: $ctx['groupIdHashHex'],
            expectedEpoch: 1,
            expectedChallengeNonceHexOrBin: $ctx['challengeNonceHex']
        );
        $this->assertFalse($resRoom->isValid());
        $this->assertSame('ROOM_UUID_MISMATCH', $resRoom->getErrorCode());

        // 2. User ID mismatch
        $resUser = $this->service->verifyPayloadBuffer(
            payloadBinary: $ctx['payload'],
            signatureBinary: $ctx['signature'],
            publicKeyBinary: $ctx['publicKey'],
            expectedRoomUuid: $ctx['roomUuid'],
            expectedRecipientUserId: 12345,
            expectedWelcomeOperationUuid: $ctx['welcomeOperationUuid'],
            expectedChallengeUuid: $ctx['challengeUuid'],
            expectedChallengeGeneration: $ctx['challengeGeneration'],
            expectedGroupIdHashHexOrBin: $ctx['groupIdHashHex'],
            expectedEpoch: 1,
            expectedChallengeNonceHexOrBin: $ctx['challengeNonceHex']
        );
        $this->assertFalse($resUser->isValid());
        $this->assertSame('USER_ID_MISMATCH', $resUser->getErrorCode());

        // 3. Welcome Operation UUID mismatch
        $resOp = $this->service->verifyPayloadBuffer(
            payloadBinary: $ctx['payload'],
            signatureBinary: $ctx['signature'],
            publicKeyBinary: $ctx['publicKey'],
            expectedRoomUuid: $ctx['roomUuid'],
            expectedRecipientUserId: $ctx['recipientUserId'],
            expectedWelcomeOperationUuid: '99999999-9999-4999-8999-999999999999',
            expectedChallengeUuid: $ctx['challengeUuid'],
            expectedChallengeGeneration: $ctx['challengeGeneration'],
            expectedGroupIdHashHexOrBin: $ctx['groupIdHashHex'],
            expectedEpoch: 1,
            expectedChallengeNonceHexOrBin: $ctx['challengeNonceHex']
        );
        $this->assertFalse($resOp->isValid());
        $this->assertSame('WELCOME_OP_UUID_MISMATCH', $resOp->getErrorCode());

        // 4. Challenge UUID mismatch
        $resChl = $this->service->verifyPayloadBuffer(
            payloadBinary: $ctx['payload'],
            signatureBinary: $ctx['signature'],
            publicKeyBinary: $ctx['publicKey'],
            expectedRoomUuid: $ctx['roomUuid'],
            expectedRecipientUserId: $ctx['recipientUserId'],
            expectedWelcomeOperationUuid: $ctx['welcomeOperationUuid'],
            expectedChallengeUuid: '99999999-9999-4999-8999-999999999999',
            expectedChallengeGeneration: $ctx['challengeGeneration'],
            expectedGroupIdHashHexOrBin: $ctx['groupIdHashHex'],
            expectedEpoch: 1,
            expectedChallengeNonceHexOrBin: $ctx['challengeNonceHex']
        );
        $this->assertFalse($resChl->isValid());
        $this->assertSame('CHALLENGE_UUID_MISMATCH', $resChl->getErrorCode());

        // 5. Generation mismatch
        $resGen = $this->service->verifyPayloadBuffer(
            payloadBinary: $ctx['payload'],
            signatureBinary: $ctx['signature'],
            publicKeyBinary: $ctx['publicKey'],
            expectedRoomUuid: $ctx['roomUuid'],
            expectedRecipientUserId: $ctx['recipientUserId'],
            expectedWelcomeOperationUuid: $ctx['welcomeOperationUuid'],
            expectedChallengeUuid: $ctx['challengeUuid'],
            expectedChallengeGeneration: 2, // expected gen 2 padahal payload gen 1
            expectedGroupIdHashHexOrBin: $ctx['groupIdHashHex'],
            expectedEpoch: 1,
            expectedChallengeNonceHexOrBin: $ctx['challengeNonceHex']
        );
        $this->assertFalse($resGen->isValid());
        $this->assertSame('GENERATION_MISMATCH', $resGen->getErrorCode());

        // 6. Group ID Hash mismatch
        $resHash = $this->service->verifyPayloadBuffer(
            payloadBinary: $ctx['payload'],
            signatureBinary: $ctx['signature'],
            publicKeyBinary: $ctx['publicKey'],
            expectedRoomUuid: $ctx['roomUuid'],
            expectedRecipientUserId: $ctx['recipientUserId'],
            expectedWelcomeOperationUuid: $ctx['welcomeOperationUuid'],
            expectedChallengeUuid: $ctx['challengeUuid'],
            expectedChallengeGeneration: $ctx['challengeGeneration'],
            expectedGroupIdHashHexOrBin: str_repeat('f', 64),
            expectedEpoch: 1,
            expectedChallengeNonceHexOrBin: $ctx['challengeNonceHex']
        );
        $this->assertFalse($resHash->isValid());
        $this->assertSame('GROUP_ID_HASH_MISMATCH', $resHash->getErrorCode());

        // 7. Nonce mismatch
        $resNonce = $this->service->verifyPayloadBuffer(
            payloadBinary: $ctx['payload'],
            signatureBinary: $ctx['signature'],
            publicKeyBinary: $ctx['publicKey'],
            expectedRoomUuid: $ctx['roomUuid'],
            expectedRecipientUserId: $ctx['recipientUserId'],
            expectedWelcomeOperationUuid: $ctx['welcomeOperationUuid'],
            expectedChallengeUuid: $ctx['challengeUuid'],
            expectedChallengeGeneration: $ctx['challengeGeneration'],
            expectedGroupIdHashHexOrBin: $ctx['groupIdHashHex'],
            expectedEpoch: 1,
            expectedChallengeNonceHexOrBin: str_repeat('f', 64)
        );
        $this->assertFalse($resNonce->isValid());
        $this->assertSame('NONCE_MISMATCH', $resNonce->getErrorCode());
    }
}

