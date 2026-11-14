<?php

namespace Tests\Unit\Services;

use App\Services\BchatPopVerificationService;
use PHPUnit\Framework\TestCase;

class BchatPopVerificationServiceTest extends TestCase
{
    private BchatPopVerificationService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new BchatPopVerificationService();
    }

    /**
     * Helper untuk membuat data uji kanonis yang valid.
     */
    private function createValidTestData(): array
    {
        $userId = 12345;
        $deviceUuid = 'a1b2c3d4-e5f6-4a7b-8c9d-0e1f2a3b4c5d';
        $nonceHex = str_repeat('a', 64);
        $issuedAt = 1762512000; // Epoch uint64 valid
        // 32-byte public key hex dummy
        $pubKeyHex = str_repeat('b', 64);
        $fingerprint = hash('sha256', hex2bin($pubKeyHex));

        $payload = BchatPopVerificationService::buildPayload(
            $userId,
            $deviceUuid,
            $nonceHex,
            $issuedAt,
            $pubKeyHex
        );

        // 64-byte signature dummy
        $dummySignature = str_repeat("\x00", 64);

        return compact('userId', 'deviceUuid', 'nonceHex', 'issuedAt', 'pubKeyHex', 'fingerprint', 'payload', 'dummySignature');
    }

    /**
     * 1. Payload harus tepat 121 byte.
     */
    public function test_payload_must_be_strictly_121_bytes(): void
    {
        $data = $this->createValidTestData();

        // Terlalu pendek (120 byte)
        $shortPayload = substr($data['payload'], 0, 120);
        $resultShort = $this->service->verify(
            $shortPayload,
            $data['dummySignature'],
            $data['userId'],
            $data['deviceUuid'],
            $data['nonceHex'],
            $data['issuedAt'],
            $data['fingerprint']
        );
        $this->assertFalse($resultShort->isValid());
        $this->assertEquals('INVALID_PAYLOAD_LENGTH', $resultShort->getErrorCode());

        // Terlalu panjang (122 byte)
        $longPayload = $data['payload'] . "\x00";
        $resultLong = $this->service->verify(
            $longPayload,
            $data['dummySignature'],
            $data['userId'],
            $data['deviceUuid'],
            $data['nonceHex'],
            $data['issuedAt'],
            $data['fingerprint']
        );
        $this->assertFalse($resultLong->isValid());
        $this->assertEquals('INVALID_PAYLOAD_LENGTH', $resultLong->getErrorCode());
    }

    /**
     * 2. Signature harus tepat 64 byte.
     */
    public function test_signature_must_be_strictly_64_bytes(): void
    {
        $data = $this->createValidTestData();

        // 63 byte signature
        $shortSig = str_repeat("\x00", 63);
        $resShort = $this->service->verify(
            $data['payload'],
            $shortSig,
            $data['userId'],
            $data['deviceUuid'],
            $data['nonceHex'],
            $data['issuedAt'],
            $data['fingerprint']
        );
        $this->assertFalse($resShort->isValid());
        $this->assertEquals('INVALID_SIGNATURE_LENGTH', $resShort->getErrorCode());

        // 65 byte signature
        $longSig = str_repeat("\x00", 65);
        $resLong = $this->service->verify(
            $data['payload'],
            $longSig,
            $data['userId'],
            $data['deviceUuid'],
            $data['nonceHex'],
            $data['issuedAt'],
            $data['fingerprint']
        );
        $this->assertFalse($resLong->isValid());
        $this->assertEquals('INVALID_SIGNATURE_LENGTH', $resLong->getErrorCode());
    }

    /**
     * 3. Magic header salah ditolak.
     */
    public function test_magic_header_mismatch_is_rejected(): void
    {
        $data = $this->createValidTestData();

        // Ubah byte pertama magic header
        $corruptPayload = 'X' . substr($data['payload'], 1);
        $this->assertEquals(121, strlen($corruptPayload));

        $res = $this->service->verify(
            $corruptPayload,
            $data['dummySignature'],
            $data['userId'],
            $data['deviceUuid'],
            $data['nonceHex'],
            $data['issuedAt'],
            $data['fingerprint']
        );
        $this->assertFalse($res->isValid());
        $this->assertEquals('INVALID_MAGIC_HEADER', $res->getErrorCode());
    }

    /**
     * 4. User ID tidak cocok ditolak.
     */
    public function test_user_id_mismatch_is_rejected(): void
    {
        $data = $this->createValidTestData();

        $res = $this->service->verify(
            $data['payload'],
            $data['dummySignature'],
            99999, // User ID berbeda
            $data['deviceUuid'],
            $data['nonceHex'],
            $data['issuedAt'],
            $data['fingerprint']
        );
        $this->assertFalse($res->isValid());
        $this->assertEquals('USER_ID_MISMATCH', $res->getErrorCode());
    }

    /**
     * 5. Device UUID tidak cocok ditolak.
     */
    public function test_device_uuid_mismatch_is_rejected(): void
    {
        $data = $this->createValidTestData();

        $differentUuid = 'ffffffff-eeee-4ddd-8ccc-bbbbbbbbbbbb';
        $res = $this->service->verify(
            $data['payload'],
            $data['dummySignature'],
            $data['userId'],
            $differentUuid,
            $data['nonceHex'],
            $data['issuedAt'],
            $data['fingerprint']
        );
        $this->assertFalse($res->isValid());
        $this->assertEquals('DEVICE_UUID_MISMATCH', $res->getErrorCode());
    }

    /**
     * 6. Nonce tidak cocok ditolak.
     */
    public function test_nonce_mismatch_is_rejected(): void
    {
        $data = $this->createValidTestData();

        $differentNonce = str_repeat('f', 64);
        $res = $this->service->verify(
            $data['payload'],
            $data['dummySignature'],
            $data['userId'],
            $data['deviceUuid'],
            $differentNonce,
            $data['issuedAt'],
            $data['fingerprint']
        );
        $this->assertFalse($res->isValid());
        $this->assertEquals('NONCE_MISMATCH', $res->getErrorCode());
    }

    /**
     * 7. Timestamp tidak cocok ditolak.
     */
    public function test_timestamp_mismatch_is_rejected(): void
    {
        $data = $this->createValidTestData();

        $res = $this->service->verify(
            $data['payload'],
            $data['dummySignature'],
            $data['userId'],
            $data['deviceUuid'],
            $data['nonceHex'],
            $data['issuedAt'] + 1, // Berbeda 1 detik
            $data['fingerprint']
        );
        $this->assertFalse($res->isValid());
        $this->assertEquals('TIMESTAMP_MISMATCH', $res->getErrorCode());
    }

    /**
     * 8. Boundary User ID dan Timestamp 0 serta PHP_INT_MAX.
     */
    public function test_boundary_timestamp_zero_and_php_int_max(): void
    {
        $deviceUuid = 'a1b2c3d4-e5f6-4a7b-8c9d-0e1f2a3b4c5d';
        $nonceHex = str_repeat('a', 64);
        $pubKeyHex = str_repeat('b', 64);
        $fingerprint = hash('sha256', hex2bin($pubKeyHex));
        $dummySig = str_repeat("\x00", 64);

        // Uji Boundary Bawah: 0
        $payloadZero = BchatPopVerificationService::buildPayload(0, $deviceUuid, $nonceHex, 0, $pubKeyHex);
        $this->assertEquals(121, strlen($payloadZero));
        $this->assertEquals(0, unpack('J', substr($payloadZero, 25, 8))[1]);
        $this->assertEquals(0, unpack('J', substr($payloadZero, 81, 8))[1]);

        $resZero = $this->service->verify($payloadZero, $dummySig, 0, $deviceUuid, $nonceHex, 0, $fingerprint);
        // Gagal di signature verification, tetapi LULUS validasi integer 0 & format
        $this->assertEquals('SIGNATURE_VERIFICATION_FAILED', $resZero->getErrorCode());

        // Uji Boundary Atas: PHP_INT_MAX (9223372036854775807)
        $payloadMax = BchatPopVerificationService::buildPayload(PHP_INT_MAX, $deviceUuid, $nonceHex, PHP_INT_MAX, $pubKeyHex);
        $this->assertEquals(121, strlen($payloadMax));
        // Serialisasi 8-byte big-endian PHP_INT_MAX wajib 0x7fffffffffffffff
        $this->assertEquals("\x7f\xff\xff\xff\xff\xff\xff\xff", substr($payloadMax, 25, 8));
        $this->assertEquals("\x7f\xff\xff\xff\xff\xff\xff\xff", substr($payloadMax, 81, 8));
        // Pembacaan kembali wajib identik PHP_INT_MAX
        $this->assertEquals(PHP_INT_MAX, unpack('J', substr($payloadMax, 25, 8))[1]);
        $this->assertEquals(PHP_INT_MAX, unpack('J', substr($payloadMax, 81, 8))[1]);

        $resMax = $this->service->verify($payloadMax, $dummySig, PHP_INT_MAX, $deviceUuid, $nonceHex, PHP_INT_MAX, $fingerprint);
        $this->assertEquals('SIGNATURE_VERIFICATION_FAILED', $resMax->getErrorCode());

        // Uji Boundary Atas via String representation PHP_INT_MAX
        $resMaxStr = $this->service->verify($payloadMax, $dummySig, (string) PHP_INT_MAX, $deviceUuid, $nonceHex, (string) PHP_INT_MAX, $fingerprint);
        $this->assertEquals('SIGNATURE_VERIFICATION_FAILED', $resMaxStr->getErrorCode());
    }

    /**
     * 9. Overflow dan input desimal tidak valid ditolak sebelum cast pada kedua parameter (User ID dan Timestamp).
     */
    public function test_invalid_decimal_or_negative_inputs_rejected(): void
    {
        $data = $this->createValidTestData();

        $invalidInputs = [
            '1.5',                  // Pecahan desimal string
            1.5,                    // Pecahan float
            '1e3',                  // Notasi ilmiah
            '+100',                 // Bertanda plus
            '-1',                   // Bertanda minus string
            -1,                     // Integer negatif
            ' 100 ',                // Spasi di awal/akhir
            '',                     // String kosong
            '9223372036854775808',  // PHP_INT_MAX + 1 (mencegah float precision loss)
            '10000000000000000000', // Nilai sangat besar di atas 64-bit
            true,                   // Boolean
            false,
            null,
            [],
        ];

        foreach ($invalidInputs as $invalidVal) {
            // Uji pada parameter Timestamp
            $resTs = $this->service->verify(
                $data['payload'],
                $data['dummySignature'],
                $data['userId'],
                $data['deviceUuid'],
                $data['nonceHex'],
                $invalidVal,
                $data['fingerprint']
            );
            $this->assertFalse(
                $resTs->isValid(),
                'Input invalid timestamp ' . var_export($invalidVal, true) . ' wajib ditolak.'
            );
            $this->assertEquals('EXPECTED_TIMESTAMP_INVALID', $resTs->getErrorCode());

            // Uji pada parameter User ID
            $resUser = $this->service->verify(
                $data['payload'],
                $data['dummySignature'],
                $invalidVal,
                $data['deviceUuid'],
                $data['nonceHex'],
                $data['issuedAt'],
                $data['fingerprint']
            );
            $this->assertFalse(
                $resUser->isValid(),
                'Input invalid user_id ' . var_export($invalidVal, true) . ' wajib ditolak.'
            );
            $this->assertEquals('EXPECTED_USER_ID_INVALID', $resUser->getErrorCode());
        }
    }

    /**
     * 9b. buildPayload melempar InvalidArgumentException untuk input float, negatif, dan string non-digit.
     */
    public function test_build_payload_throws_exception_on_invalid_user_id_and_timestamp(): void
    {
        $deviceUuid = 'a1b2c3d4-e5f6-4a7b-8c9d-0e1f2a3b4c5d';
        $nonceHex = str_repeat('a', 64);
        $pubKeyHex = str_repeat('b', 64);

        $invalidCases = ['1.5', 1.5, '1e3', '+100', '-1', -1, ' 100 ', '', '9223372036854775808'];

        foreach ($invalidCases as $invalid) {
            // Invalid User ID
            try {
                BchatPopVerificationService::buildPayload($invalid, $deviceUuid, $nonceHex, 1000, $pubKeyHex);
                $this->fail('buildPayload wajib melempar InvalidArgumentException untuk invalid userId: ' . var_export($invalid, true));
            } catch (\InvalidArgumentException $e) {
                $this->assertStringContainsString('User ID', $e->getMessage());
            }

            // Invalid Timestamp
            try {
                BchatPopVerificationService::buildPayload(1000, $deviceUuid, $nonceHex, $invalid, $pubKeyHex);
                $this->fail('buildPayload wajib melempar InvalidArgumentException untuk invalid issuedAt: ' . var_export($invalid, true));
            } catch (\InvalidArgumentException $e) {
                $this->assertStringContainsString('Timestamp', $e->getMessage());
            }
        }
    }

    /**
     * 9c. Pengujian helper isValidUnsigned64Int secara komprehensif.
     */
    public function test_is_valid_unsigned_64_int_helper_comprehensive(): void
    {
        // Kasus-kasus valid
        $this->assertTrue(BchatPopVerificationService::isValidUnsigned64Int(0));
        $this->assertTrue(BchatPopVerificationService::isValidUnsigned64Int('0'));
        $this->assertTrue(BchatPopVerificationService::isValidUnsigned64Int(12345));
        $this->assertTrue(BchatPopVerificationService::isValidUnsigned64Int('12345'));
        $this->assertTrue(BchatPopVerificationService::isValidUnsigned64Int(PHP_INT_MAX));
        $this->assertTrue(BchatPopVerificationService::isValidUnsigned64Int((string) PHP_INT_MAX));

        // Kasus-kasus tidak valid
        $this->assertFalse(BchatPopVerificationService::isValidUnsigned64Int(-1));
        $this->assertFalse(BchatPopVerificationService::isValidUnsigned64Int('-1'));
        $this->assertFalse(BchatPopVerificationService::isValidUnsigned64Int('1.5'));
        $this->assertFalse(BchatPopVerificationService::isValidUnsigned64Int(1.5));
        $this->assertFalse(BchatPopVerificationService::isValidUnsigned64Int('1e3'));
        $this->assertFalse(BchatPopVerificationService::isValidUnsigned64Int('+100'));
        $this->assertFalse(BchatPopVerificationService::isValidUnsigned64Int(' 100 '));
        $this->assertFalse(BchatPopVerificationService::isValidUnsigned64Int(''));
        $this->assertFalse(BchatPopVerificationService::isValidUnsigned64Int('9223372036854775808')); // PHP_INT_MAX + 1
        $this->assertFalse(BchatPopVerificationService::isValidUnsigned64Int('10000000000000000000'));
        $this->assertFalse(BchatPopVerificationService::isValidUnsigned64Int(null));
        $this->assertFalse(BchatPopVerificationService::isValidUnsigned64Int(true));
        $this->assertFalse(BchatPopVerificationService::isValidUnsigned64Int(false));
        $this->assertFalse(BchatPopVerificationService::isValidUnsigned64Int([]));
    }

    /**
     * 10. Fingerprint public key tidak cocok ditolak.
     */
    public function test_public_key_fingerprint_mismatch_is_rejected(): void
    {
        $data = $this->createValidTestData();

        $wrongFingerprint = hash('sha256', 'kunci_lain');
        $res = $this->service->verify(
            $data['payload'],
            $data['dummySignature'],
            $data['userId'],
            $data['deviceUuid'],
            $data['nonceHex'],
            $data['issuedAt'],
            $wrongFingerprint
        );
        $this->assertFalse($res->isValid());
        $this->assertEquals('FINGERPRINT_MISMATCH', $res->getErrorCode());
    }

    /**
     * 11. Round-trip serialisasi menghasilkan tepat 121 byte.
     */
    public function test_round_trip_serialization_exact_121_bytes(): void
    {
        $userId = 987654321;
        $deviceUuid = '12345678-1234-4234-8234-123456789abc';
        $nonceHex = '0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef';
        $issuedAt = 1762519999;
        $pubKeyHex = 'fedcba9876543210fedcba9876543210fedcba9876543210fedcba9876543210';

        $payload = BchatPopVerificationService::buildPayload(
            $userId,
            $deviceUuid,
            $nonceHex,
            $issuedAt,
            $pubKeyHex
        );

        $this->assertEquals(121, strlen($payload));

        // Offset 0..24: Magic Header
        $this->assertEquals('UNUHA-BCHAT-DEVICE-POP-V1', substr($payload, 0, 25));

        // Offset 25..32: User ID
        $this->assertEquals($userId, unpack('J', substr($payload, 25, 8))[1]);

        // Offset 33..48: Device UUID
        $this->assertEquals(hex2bin(str_replace('-', '', $deviceUuid)), substr($payload, 33, 16));

        // Offset 49..80: Nonce
        $this->assertEquals(hex2bin($nonceHex), substr($payload, 49, 32));

        // Offset 81..88: Timestamp
        $this->assertEquals($issuedAt, unpack('J', substr($payload, 81, 8))[1]);

        // Offset 89..120: Public Key
        $this->assertEquals(hex2bin($pubKeyHex), substr($payload, 89, 32));
    }

    /**
     * 12. Tidak terjadi integer truncation pada 64-bit integer timestamp.
     */
    public function test_no_integer_truncation_on_64_bit_timestamp(): void
    {
        // Nilai timestamp di atas 32-bit (> 4,294,967,295, misalnya timestamp milidetik / epoch masa depan)
        $bigTs = 5000000000; // ~Tahun 2128
        $payload = BchatPopVerificationService::buildPayload(
            1,
            'a1b2c3d4-e5f6-4a7b-8c9d-0e1f2a3b4c5d',
            str_repeat('a', 64),
            $bigTs,
            str_repeat('b', 64)
        );

        $extractedTs = unpack('J', substr($payload, 81, 8))[1];
        $this->assertEquals($bigTs, $extractedTs);
        $this->assertGreaterThan(4294967295, $extractedTs);
    }
}

