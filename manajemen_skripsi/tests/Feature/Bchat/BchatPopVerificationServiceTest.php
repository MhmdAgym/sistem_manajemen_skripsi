<?php

namespace Tests\Feature\Bchat;

use App\Services\BchatPopVerificationService;
use Tests\TestCase;

class BchatPopVerificationServiceTest extends TestCase
{
    private BchatPopVerificationService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new BchatPopVerificationService();
    }

    /**
     * 1. Runtime Sodium aktif dan seluruh fungsi Ed25519 tersedia.
     */
    public function test_runtime_sodium_extension_and_ed25519_functions_available(): void
    {
        $this->assertTrue(extension_loaded('sodium'), 'Ekstensi native ext-sodium wajib aktif di runtime.');
        $this->assertTrue(function_exists('sodium_crypto_sign_verify_detached'), 'Fungsi sodium_crypto_sign_verify_detached wajib tersedia.');
        $this->assertTrue(function_exists('sodium_crypto_sign_detached'), 'Fungsi sodium_crypto_sign_detached wajib tersedia.');
        $this->assertTrue(function_exists('sodium_crypto_sign_keypair'), 'Fungsi sodium_crypto_sign_keypair wajib tersedia.');
        $this->assertTrue(function_exists('sodium_crypto_sign_publickey'), 'Fungsi sodium_crypto_sign_publickey wajib tersedia.');
        $this->assertTrue(function_exists('sodium_crypto_sign_secretkey'), 'Fungsi sodium_crypto_sign_secretkey wajib tersedia.');
    }

    /**
     * 2. Signature Ed25519 valid diterima dan seluruh data diekstrak secara aman.
     */
    public function test_valid_ed25519_signature_verifies_successfully(): void
    {
        // Generate keypair acak saat runtime test (tanpa hardcode private key)
        $keypair = sodium_crypto_sign_keypair();
        $secretKey = sodium_crypto_sign_secretkey($keypair);
        $publicKey = sodium_crypto_sign_publickey($keypair);

        $userId = 42;
        $deviceUuid = 'b5c6d7e8-f9a0-4b1c-8d2e-3f4a5b6c7d8e';
        $nonceHex = bin2hex(random_bytes(32));
        $issuedAt = time();
        $pubKeyHex = bin2hex($publicKey);
        $fingerprint = hash('sha256', $publicKey);

        // Susun payload kanonis 121 byte
        $payload = BchatPopVerificationService::buildPayload(
            $userId,
            $deviceUuid,
            $nonceHex,
            $issuedAt,
            $pubKeyHex
        );
        $this->assertEquals(121, strlen($payload));

        // Tanda tangani payload menggunakan private key
        $signature = sodium_crypto_sign_detached($payload, $secretKey);
        $this->assertEquals(64, strlen($signature));

        // Eksekusi verifikasi melalui service
        $result = $this->service->verify(
            $payload,
            $signature,
            $userId,
            $deviceUuid,
            $nonceHex,
            $issuedAt,
            $fingerprint
        );

        $this->assertTrue($result->isValid(), 'Verifikasi PoP valid wajib berhasil.');
        $this->assertNull($result->getErrorCode());

        $parsed = $result->getParsedData();
        $this->assertIsArray($parsed);
        $this->assertEquals($userId, $parsed['user_id']);
        $this->assertEquals($deviceUuid, $parsed['device_uuid']);
        $this->assertEquals($nonceHex, $parsed['nonce_hex']);
        $this->assertEquals($issuedAt, $parsed['issued_at']);
        $this->assertEquals($pubKeyHex, $parsed['public_key_hex']);
        $this->assertEquals($fingerprint, $parsed['public_key_fingerprint']);
    }

    /**
     * 3. Signature yang dimodifikasi ditolak.
     */
    public function test_corrupted_signature_fails_verification(): void
    {
        $keypair = sodium_crypto_sign_keypair();
        $secretKey = sodium_crypto_sign_secretkey($keypair);
        $publicKey = sodium_crypto_sign_publickey($keypair);

        $userId = 99;
        $deviceUuid = '11223344-5566-4778-8899-aabbccddeeff';
        $nonceHex = bin2hex(random_bytes(32));
        $issuedAt = time();
        $pubKeyHex = bin2hex($publicKey);
        $fingerprint = hash('sha256', $publicKey);

        $payload = BchatPopVerificationService::buildPayload(
            $userId,
            $deviceUuid,
            $nonceHex,
            $issuedAt,
            $pubKeyHex
        );

        $signature = sodium_crypto_sign_detached($payload, $secretKey);

        // Corrupt 1 bit pada signature
        $corruptedSignature = $signature;
        $corruptedSignature[0] = chr(ord($corruptedSignature[0]) ^ 0x01);

        $result = $this->service->verify(
            $payload,
            $corruptedSignature,
            $userId,
            $deviceUuid,
            $nonceHex,
            $issuedAt,
            $fingerprint
        );

        $this->assertFalse($result->isValid());
        $this->assertEquals('SIGNATURE_VERIFICATION_FAILED', $result->getErrorCode());
        $this->assertNull($result->getParsedData());
    }

    /**
     * 4. Public key yang salah ditolak (signature dari Keypair A diverifikasi dengan Keypair B).
     */
    public function test_wrong_public_key_fails_verification(): void
    {
        // Keypair A (penandatangan asli)
        $keypairA = sodium_crypto_sign_keypair();
        $secretKeyA = sodium_crypto_sign_secretkey($keypairA);
        $publicKeyA = sodium_crypto_sign_publickey($keypairA);

        // Keypair B (kunci salah)
        $keypairB = sodium_crypto_sign_keypair();
        $publicKeyB = sodium_crypto_sign_publickey($keypairB);

        $userId = 100;
        $deviceUuid = 'abcdef01-2345-4678-89ab-cdef01234567';
        $nonceHex = bin2hex(random_bytes(32));
        $issuedAt = time();

        // Payload disusun membawa PublicKey B, tetapi ditandatangani SecretKey A
        $payloadWithB = BchatPopVerificationService::buildPayload(
            $userId,
            $deviceUuid,
            $nonceHex,
            $issuedAt,
            bin2hex($publicKeyB)
        );
        $fingerprintB = hash('sha256', $publicKeyB);

        // Tandatangani dengan SecretKey A (kunci berbeda)
        $signatureFromA = sodium_crypto_sign_detached($payloadWithB, $secretKeyA);

        $result = $this->service->verify(
            $payloadWithB,
            $signatureFromA,
            $userId,
            $deviceUuid,
            $nonceHex,
            $issuedAt,
            $fingerprintB
        );

        $this->assertFalse($result->isValid());
        $this->assertEquals('SIGNATURE_VERIFICATION_FAILED', $result->getErrorCode());
    }

    /**
     * 5. Payload yang dimodifikasi setelah ditandatangani ditolak.
     */
    public function test_tampered_payload_byte_fails_verification(): void
    {
        $keypair = sodium_crypto_sign_keypair();
        $secretKey = sodium_crypto_sign_secretkey($keypair);
        $publicKey = sodium_crypto_sign_publickey($keypair);

        $userId = 777;
        $deviceUuid = '12345678-abcd-4ef0-8123-456789abcdef';
        $nonceHex = bin2hex(random_bytes(32));
        $issuedAt = time();
        $pubKeyHex = bin2hex($publicKey);
        $fingerprint = hash('sha256', $publicKey);

        $payload = BchatPopVerificationService::buildPayload(
            $userId,
            $deviceUuid,
            $nonceHex,
            $issuedAt,
            $pubKeyHex
        );

        $signature = sodium_crypto_sign_detached($payload, $secretKey);

        // Modifikasi 1 byte di dalam payload (misal pada timestamp byte ke-87)
        $tamperedPayload = $payload;
        $tamperedPayload[87] = chr(ord($tamperedPayload[87]) ^ 0x01);

        $result = $this->service->verify(
            $tamperedPayload,
            $signature,
            $userId,
            $deviceUuid,
            $nonceHex,
            $issuedAt,
            $fingerprint
        );

        $this->assertFalse($result->isValid());
        // Akan ditolak baik pada validasi timestamp mismatch atau signature mismatch
        $this->assertContains($result->getErrorCode(), ['TIMESTAMP_MISMATCH', 'SIGNATURE_VERIFICATION_FAILED']);
    }
}

