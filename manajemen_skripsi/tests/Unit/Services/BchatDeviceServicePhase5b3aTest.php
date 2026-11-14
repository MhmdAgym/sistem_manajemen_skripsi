<?php

namespace Tests\Unit\Services;

use App\Exceptions\BchatDeviceException;
use App\Models\BchatRegistrationNonce;
use App\Models\BchatStepUpToken;
use App\Models\User;
use App\Services\BchatDeviceService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

class BchatDeviceServicePhase5b3aTest extends TestCase
{
    use DatabaseTransactions;

    private BchatDeviceService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $activeDb = DB::connection()->getDatabaseName();
        $this->assertEquals(
            'manajemen_skripsi_test',
            $activeDb,
            'CRITICAL SECURITY: Test suite B-CHAT HANYA boleh berjalan pada database manajemen_skripsi_test.'
        );

        $this->service = new BchatDeviceService();
    }

    /**
     * Helper untuk membuat user uji dengan password yang diketahui.
     */
    private function createTestUser(string $password = 'RahasiaBchat123!'): User
    {
        return User::factory()->create([
            'password' => Hash::make($password),
        ]);
    }

    // =========================================================================
    // 1. PENGUJIAN STEP-UP RE-AUTHENTICATION
    // =========================================================================

    /**
     * 1.1 Password benar menghasilkan step-up token valid dengan TTL 5 menit.
     */
    public function test_step_up_with_valid_password_succeeds(): void
    {
        $password = 'PasswordSuperAman!456';
        $user = $this->createTestUser($password);
        $deviceUuid = (string) Str::uuid();

        $result = $this->service->authenticateStepUp($user, $password, $deviceUuid, '127.0.0.1', 'PHPUnit Browser');

        $this->assertIsArray($result);
        $this->assertArrayHasKey('user_id', $result);
        $this->assertArrayHasKey('step_up_token', $result);
        $this->assertArrayHasKey('device_uuid', $result);
        $this->assertArrayHasKey('action_scope', $result);
        $this->assertArrayHasKey('expires_at', $result);

        $this->assertSame((string) $user->id, $result['user_id']);
        $this->assertEquals(strtolower($deviceUuid), $result['device_uuid']);
        $this->assertEquals(BchatDeviceService::ACTION_SCOPE_REGISTRATION, $result['action_scope']);

        // Verifikasi format token: tepat 64 hex chars (32 byte)
        $this->assertEquals(64, strlen($result['step_up_token']));
        $this->assertTrue(ctype_xdigit($result['step_up_token']));
    }

    /**
     * 1.2 Password salah ditolak dengan kode 401 dan pesan aman.
     */
    public function test_step_up_with_invalid_password_throws_401(): void
    {
        $user = $this->createTestUser('PasswordBenar123!');
        $deviceUuid = (string) Str::uuid();

        $this->expectException(BchatDeviceException::class);
        $this->expectExceptionCode(401);

        try {
            $this->service->authenticateStepUp($user, 'PasswordSalah!', $deviceUuid);
        } catch (BchatDeviceException $e) {
            $this->assertEquals('INVALID_CREDENTIALS', $e->getErrorCode());
            $this->assertEquals(401, $e->getHttpStatus());
            $this->assertEquals('Kredensial re-autentikasi tidak valid.', $e->getMessage());
            throw $e;
        }
    }

    /**
     * 1.3 Token memiliki 32 byte entropy sebelum encoding (64 karakter hex).
     */
    public function test_step_up_token_has_32_bytes_entropy(): void
    {
        $password = 'TestingEntropy32Bytes!';
        $user = $this->createTestUser($password);
        $deviceUuid = (string) Str::uuid();

        $result = $this->service->authenticateStepUp($user, $password, $deviceUuid);
        $plainTokenHex = $result['step_up_token'];

        $binaryToken = hex2bin($plainTokenHex);
        $this->assertNotFalse($binaryToken);
        $this->assertEquals(32, strlen($binaryToken));
    }

    /**
     * 1.4 Database HANYA menyimpan hash SHA-256 dari token, BUKAN plaintext.
     */
    public function test_step_up_database_stores_only_sha256_hash_not_plaintext(): void
    {
        $password = 'VerifyHashOnlyDb!';
        $user = $this->createTestUser($password);
        $deviceUuid = (string) Str::uuid();

        $result = $this->service->authenticateStepUp($user, $password, $deviceUuid);
        $plainTokenHex = $result['step_up_token'];
        $expectedHash = hash('sha256', $plainTokenHex);

        // Pastikan token plaintext TIDAK ADA di kolom token_hash
        $this->assertDatabaseMissing('bchat_step_up_tokens', [
            'token_hash' => $plainTokenHex,
        ]);

        // Pastikan hash SHA-256 yang tersimpan cocok
        $this->assertDatabaseHas('bchat_step_up_tokens', [
            'user_id'     => $user->id,
            'device_uuid' => strtolower($deviceUuid),
            'token_hash'  => $expectedHash,
            'is_consumed' => false,
        ]);
    }

    /**
     * 1.5 TTL token tepat 5 menit.
     */
    public function test_step_up_token_ttl_is_exactly_5_minutes(): void
    {
        $password = 'VerifyTTL5Minutes!';
        $user = $this->createTestUser($password);
        $deviceUuid = (string) Str::uuid();

        $before = now();
        $result = $this->service->authenticateStepUp($user, $password, $deviceUuid);
        $after = now();

        $record = BchatStepUpToken::where('token_hash', hash('sha256', $result['step_up_token']))->first();
        $this->assertNotNull($record);

        // Selisih antara expires_at dan created_at tepat 5 menit (300 detik)
        $diffSeconds = $record->created_at->diffInSeconds($record->expires_at);
        $this->assertEquals(300, $diffSeconds);

        // expires_at berada dalam rentang [now + 5 menit]
        $this->assertTrue($record->expires_at->greaterThanOrEqualTo($before->addMinutes(5)->subSecond()));
        $this->assertTrue($record->expires_at->lessThanOrEqualTo($after->addMinutes(5)->addSecond()));
    }

    /**
     * 1.6 Token terikat secara tepat pada user_id dan device_uuid.
     */
    public function test_step_up_token_is_bound_to_user_and_device_uuid(): void
    {
        $password = 'BindingCheckPass!';
        $user = $this->createTestUser($password);
        $deviceUuid = (string) Str::uuid();

        $result = $this->service->authenticateStepUp($user, $password, $deviceUuid);
        $tokenRecord = BchatStepUpToken::where('token_hash', hash('sha256', $result['step_up_token']))->first();

        $this->assertNotNull($tokenRecord);
        $this->assertEquals($user->id, $tokenRecord->user_id);
        $this->assertEquals(strtolower($deviceUuid), $tokenRecord->device_uuid);
        $this->assertEquals(BchatDeviceService::ACTION_SCOPE_REGISTRATION, $tokenRecord->action_scope);
    }

    // =========================================================================
    // 2. PENGUJIAN PENERBITAN REGISTRATION NONCE
    // =========================================================================

    /**
     * 2.1 Token valid berhasil digunakan untuk menerbitkan registration nonce.
     */
    public function test_registration_nonce_with_valid_token_succeeds(): void
    {
        $password = 'NonceIssuanceValid!';
        $user = $this->createTestUser($password);
        $deviceUuid = (string) Str::uuid();
        $publicKeyHex = str_repeat('c', 64);

        $stepUp = $this->service->authenticateStepUp($user, $password, $deviceUuid);
        $plainToken = $stepUp['step_up_token'];

        $nonceResult = $this->service->issueRegistrationNonce($user, $plainToken, $deviceUuid, $publicKeyHex);

        $this->assertIsArray($nonceResult);
        $this->assertArrayHasKey('nonce_uuid', $nonceResult);
        $this->assertArrayHasKey('registration_nonce', $nonceResult);
        $this->assertArrayHasKey('device_uuid', $nonceResult);
        $this->assertArrayHasKey('public_key_fingerprint', $nonceResult);
        $this->assertArrayHasKey('issued_at', $nonceResult);
        $this->assertArrayHasKey('expires_at', $nonceResult);

        // Verifikasi token step-up langsung dikonsumsi (is_consumed = true)
        $tokenRecord = BchatStepUpToken::where('token_hash', hash('sha256', $plainToken))->first();
        $this->assertNotNull($tokenRecord);
        $this->assertTrue($tokenRecord->is_consumed);
        $this->assertNotNull($tokenRecord->consumed_at);

        // Verifikasi record nonce tersimpan di basis data
        $this->assertDatabaseHas('bchat_registration_nonces', [
            'user_id'     => $user->id,
            'device_uuid' => strtolower($deviceUuid),
            'nonce_value' => $nonceResult['registration_nonce'],
            'status'      => 'ISSUED',
        ]);
    }

    /**
     * 2.2 Token yang sudah pernah dikonsumsi ditolak (konsumsi satu kali atomik).
     */
    public function test_registration_nonce_with_consumed_token_rejected_403(): void
    {
        $password = 'ConsumedCheck123!';
        $user = $this->createTestUser($password);
        $deviceUuid = (string) Str::uuid();
        $publicKeyHex = str_repeat('d', 64);

        $stepUp = $this->service->authenticateStepUp($user, $password, $deviceUuid);
        $plainToken = $stepUp['step_up_token'];

        // Penggunaan pertama -> Sukses
        $this->service->issueRegistrationNonce($user, $plainToken, $deviceUuid, $publicKeyHex);

        // Penggunaan kedua -> Wajib DITOLAK
        $this->expectException(BchatDeviceException::class);
        $this->expectExceptionCode(403);

        try {
            $this->service->issueRegistrationNonce($user, $plainToken, $deviceUuid, $publicKeyHex);
        } catch (BchatDeviceException $e) {
            $this->assertEquals('STEP_UP_TOKEN_ALREADY_CONSUMED', $e->getErrorCode());
            $this->assertEquals(403, $e->getHttpStatus());
            throw $e;
        }
    }

    /**
     * 2.3 Token kedaluwarsa ditolak dengan kode 403.
     */
    public function test_registration_nonce_with_expired_token_rejected_403(): void
    {
        $password = 'ExpiredTokenCheck!';
        $user = $this->createTestUser($password);
        $deviceUuid = (string) Str::uuid();
        $publicKeyHex = str_repeat('e', 64);

        $stepUp = $this->service->authenticateStepUp($user, $password, $deviceUuid);
        $plainToken = $stepUp['step_up_token'];

        // Manipulasi waktu kadaluwarsa token menjadi waktu lampau
        BchatStepUpToken::where('token_hash', hash('sha256', $plainToken))
            ->update(['expires_at' => now()->subMinute()]);

        $this->expectException(BchatDeviceException::class);
        $this->expectExceptionCode(403);

        try {
            $this->service->issueRegistrationNonce($user, $plainToken, $deviceUuid, $publicKeyHex);
        } catch (BchatDeviceException $e) {
            $this->assertEquals('STEP_UP_TOKEN_EXPIRED', $e->getErrorCode());
            $this->assertEquals(403, $e->getHttpStatus());
            throw $e;
        }
    }

    /**
     * 2.4 Token milik pengguna lain ditolak dengan kode 403.
     */
    public function test_registration_nonce_with_different_user_token_rejected_403(): void
    {
        $userA = $this->createTestUser('PassUserA123!');
        $userB = $this->createTestUser('PassUserB123!');
        $deviceUuid = (string) Str::uuid();
        $publicKeyHex = str_repeat('f', 64);

        // User A meminta step-up token
        $stepUpA = $this->service->authenticateStepUp($userA, 'PassUserA123!', $deviceUuid);

        // User B mencoba menggunakan token milik User A
        $this->expectException(BchatDeviceException::class);
        $this->expectExceptionCode(403);

        try {
            $this->service->issueRegistrationNonce($userB, $stepUpA['step_up_token'], $deviceUuid, $publicKeyHex);
        } catch (BchatDeviceException $e) {
            $this->assertEquals('STEP_UP_USER_MISMATCH', $e->getErrorCode());
            $this->assertEquals(403, $e->getHttpStatus());
            throw $e;
        }
    }

    /**
     * 2.5 Device UUID berbeda dari token ditolak dengan kode 403.
     */
    public function test_registration_nonce_with_different_device_uuid_rejected_403(): void
    {
        $password = 'DeviceMismatchPass!';
        $user = $this->createTestUser($password);
        $deviceUuidA = (string) Str::uuid();
        $deviceUuidB = (string) Str::uuid();
        $publicKeyHex = str_repeat('a', 64);

        // Step-up terikat pada deviceUuidA
        $stepUp = $this->service->authenticateStepUp($user, $password, $deviceUuidA);

        // Coba klaim nonce untuk deviceUuidB
        $this->expectException(BchatDeviceException::class);
        $this->expectExceptionCode(403);

        try {
            $this->service->issueRegistrationNonce($user, $stepUp['step_up_token'], $deviceUuidB, $publicKeyHex);
        } catch (BchatDeviceException $e) {
            $this->assertEquals('STEP_UP_DEVICE_MISMATCH', $e->getErrorCode());
            $this->assertEquals(403, $e->getHttpStatus());
            throw $e;
        }
    }

    /**
     * 2.6 Scope berbeda ditolak dengan kode 403.
     */
    public function test_registration_nonce_with_different_scope_rejected_403(): void
    {
        $password = 'ScopeMismatchPass!';
        $user = $this->createTestUser($password);
        $deviceUuid = (string) Str::uuid();
        $publicKeyHex = str_repeat('b', 64);

        $stepUp = $this->service->authenticateStepUp($user, $password, $deviceUuid);
        $plainToken = $stepUp['step_up_token'];

        // Ubah action_scope di basis data menjadi scope lain
        BchatStepUpToken::where('token_hash', hash('sha256', $plainToken))
            ->update(['action_scope' => 'PROFILE_UPDATE']);

        $this->expectException(BchatDeviceException::class);
        $this->expectExceptionCode(403);

        try {
            $this->service->issueRegistrationNonce($user, $plainToken, $deviceUuid, $publicKeyHex);
        } catch (BchatDeviceException $e) {
            $this->assertEquals('STEP_UP_SCOPE_MISMATCH', $e->getErrorCode());
            $this->assertEquals(403, $e->getHttpStatus());
            throw $e;
        }
    }

    /**
     * 2.7 Public key tidak valid (bukan 64 hex / decode gagal) ditolak dengan 422.
     */
    public function test_registration_nonce_with_invalid_public_key_rejected_422(): void
    {
        $password = 'InvalidPubKeyPass!';
        $user = $this->createTestUser($password);
        $deviceUuid = (string) Str::uuid();

        $stepUp = $this->service->authenticateStepUp($user, $password, $deviceUuid);
        $plainToken = $stepUp['step_up_token'];

        $invalidPubKeys = [
            'pendek',
            str_repeat('z', 64), // Non-hex characters
            str_repeat('a', 63), // Kurang 1 karakter
            str_repeat('a', 65), // Lebih 1 karakter
        ];

        foreach ($invalidPubKeys as $invalidKey) {
            try {
                $this->service->issueRegistrationNonce($user, $plainToken, $deviceUuid, $invalidKey);
                $this->fail('Public key tidak valid ' . $invalidKey . ' wajib melempar BchatDeviceException.');
            } catch (BchatDeviceException $e) {
                $this->assertEquals('INVALID_PUBLIC_KEY', $e->getErrorCode());
                $this->assertEquals(422, $e->getHttpStatus());
            }
        }
    }

    /**
     * 2.8 Fingerprint dihitung secara presisi dari SHA-256 binary 32-byte public key.
     */
    public function test_registration_nonce_fingerprint_computed_correctly(): void
    {
        $password = 'FingerprintCalcPass!';
        $user = $this->createTestUser($password);
        $deviceUuid = (string) Str::uuid();
        $publicKeyHex = '0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef';

        $expectedFingerprint = hash('sha256', hex2bin($publicKeyHex));

        $stepUp = $this->service->authenticateStepUp($user, $password, $deviceUuid);
        $nonce = $this->service->issueRegistrationNonce($user, $stepUp['step_up_token'], $deviceUuid, $publicKeyHex);

        $this->assertEquals($expectedFingerprint, $nonce['public_key_fingerprint']);
    }

    /**
     * 2.9 Timestamp issued_at berasal dari server (jam server).
     */
    public function test_registration_nonce_issued_at_comes_from_server_timestamp(): void
    {
        $password = 'ServerTimestampPass!';
        $user = $this->createTestUser($password);
        $deviceUuid = (string) Str::uuid();
        $publicKeyHex = str_repeat('1', 64);

        $before = time();
        $stepUp = $this->service->authenticateStepUp($user, $password, $deviceUuid);
        $nonce = $this->service->issueRegistrationNonce($user, $stepUp['step_up_token'], $deviceUuid, $publicKeyHex);
        $after = time();

        $this->assertIsInt($nonce['issued_at']);
        $this->assertGreaterThanOrEqual($before, $nonce['issued_at']);
        $this->assertLessThanOrEqual($after, $nonce['issued_at']);
    }

    /**
     * 2.10 Nonce berukuran 32 byte entropy (64 karakter hex lowercase).
     */
    public function test_registration_nonce_has_32_bytes_entropy_64_hex(): void
    {
        $password = 'NonceEntropyCheckPass!';
        $user = $this->createTestUser($password);
        $deviceUuid = (string) Str::uuid();
        $publicKeyHex = str_repeat('2', 64);

        $stepUp = $this->service->authenticateStepUp($user, $password, $deviceUuid);
        $nonce = $this->service->issueRegistrationNonce($user, $stepUp['step_up_token'], $deviceUuid, $publicKeyHex);

        $nonceHex = $nonce['registration_nonce'];
        $this->assertEquals(64, strlen($nonceHex));
        $this->assertTrue(ctype_xdigit($nonceHex));
        $this->assertEquals(32, strlen(hex2bin($nonceHex)));
    }

    /**
     * 2.11 Penerbitan nonce baru meng-expire seluruh nonce lama yang masih ISSUED milik user.
     */
    public function test_registration_nonce_expires_previous_issued_nonces(): void
    {
        $password = 'MultiNonceExpirePass!';
        $user = $this->createTestUser($password);
        $deviceUuid = (string) Str::uuid();
        $publicKeyHex = str_repeat('3', 64);

        // Penerbitan Nonce Pertama
        $stepUp1 = $this->service->authenticateStepUp($user, $password, $deviceUuid);
        $nonce1 = $this->service->issueRegistrationNonce($user, $stepUp1['step_up_token'], $deviceUuid, $publicKeyHex);

        $record1 = BchatRegistrationNonce::where('nonce_value', $nonce1['registration_nonce'])->first();
        $this->assertNotNull($record1);
        $this->assertEquals('ISSUED', $record1->status);

        // Penerbitan Nonce Kedua
        $stepUp2 = $this->service->authenticateStepUp($user, $password, $deviceUuid);
        $nonce2 = $this->service->issueRegistrationNonce($user, $stepUp2['step_up_token'], $deviceUuid, $publicKeyHex);

        // Verifikasi Nonce Pertama telah di-update menjadi EXPIRED
        $record1->refresh();
        $this->assertEquals('EXPIRED', $record1->status);

        // Verifikasi Nonce Kedua berstatus ISSUED
        $record2 = BchatRegistrationNonce::where('nonce_value', $nonce2['registration_nonce'])->first();
        $this->assertNotNull($record2);
        $this->assertEquals('ISSUED', $record2->status);

        // Verifikasi hanya ada TEPAT 1 nonce berstatus ISSUED untuk user ini
        $activeNonceCount = BchatRegistrationNonce::where('user_id', $user->id)
            ->where('status', 'ISSUED')
            ->count();
        $this->assertEquals(1, $activeNonceCount);
    }

    /**
     * 2.12 Konsumsi token dan penerbitan nonce terikat dalam transaksi atomik (rollback together on error).
     * Memanggil BchatDeviceService::issueRegistrationNonce() secara nyata dan membuktikan bahwa kegagalan
     * saat insert nonce baru me-rollback konsumsi step-up token dan pembatalan nonce lama.
     */
    public function test_atomic_rollback_on_transaction_failure(): void
    {
        $password = 'AtomicRollbackPass!';
        $user = $this->createTestUser($password);
        $deviceUuid = (string) Str::uuid();
        $publicKeyHex = str_repeat('9', 64);
        $expectedFingerprint = hash('sha256', hex2bin($publicKeyHex));

        // 1. Buat nonce lama berstatus ISSUED
        $oldNonceUuid = (string) Str::uuid();
        $oldNonce = BchatRegistrationNonce::create([
            'user_id'                => $user->id,
            'device_uuid'            => $deviceUuid,
            'nonce_uuid'             => $oldNonceUuid,
            'nonce_value'            => str_repeat('8', 64),
            'public_key_fingerprint' => hash('sha256', hex2bin(str_repeat('a', 64))),
            'status'                 => 'ISSUED',
            'issued_at'              => time() - 60,
            'expires_at'             => now()->addMinutes(4),
            'consumed_at'            => null,
        ]);

        // 2. Siapkan step-up token valid
        $stepUp = $this->service->authenticateStepUp($user, $password, $deviceUuid);
        $plainToken = $stepUp['step_up_token'];
        $tokenHash = hash('sha256', $plainToken);

        // Kondisi awal token: is_consumed=false, consumed_at=null
        $tokenRecord = BchatStepUpToken::where('token_hash', $tokenHash)->first();
        $this->assertNotNull($tokenRecord);
        $this->assertFalse($tokenRecord->is_consumed);
        $this->assertNull($tokenRecord->consumed_at);

        // 3. Simpan dispatcher asli dan buat klon terisolasi khusus test ini
        $originalDispatcher = BchatRegistrationNonce::getEventDispatcher();
        $clonedDispatcher = clone $originalDispatcher;

        $eventName = 'eloquent.creating: ' . BchatRegistrationNonce::class;
        $simulatedException = new \RuntimeException('Simulasi kegagalan penyimpanan nonce baru.');

        $clonedDispatcher->listen($eventName, function (BchatRegistrationNonce $model) use ($simulatedException) {
            throw $simulatedException;
        });

        // Set dispatcher model sementara menggunakan klon
        BchatRegistrationNonce::setEventDispatcher($clonedDispatcher);

        // 4 & 5. Panggil issueRegistrationNonce() secara nyata dan tangkap exception
        $caughtException = null;
        try {
            $this->service->issueRegistrationNonce($user, $plainToken, $deviceUuid, $publicKeyHex);
            $this->fail('issueRegistrationNonce() wajib melempar exception saat terjadi kegagalan penyimpanan.');
        } catch (BchatDeviceException $e) {
            $caughtException = $e;
        } finally {
            // 6. Pulihkan event dispatcher asli secara mutlak tanpa memanggil forget()
            BchatRegistrationNonce::setEventDispatcher($originalDispatcher);
        }

        $this->assertNotNull($caughtException, 'Exception domain BchatDeviceException wajib tertangkap.');
        $this->assertEquals('INTERNAL_ERROR', $caughtException->getErrorCode());

        // 7 & 8. Baca ulang token dari database: wajib is_consumed=false dan consumed_at=null
        $tokenRecord->refresh();
        $this->assertFalse($tokenRecord->is_consumed, 'Status token wajib rollback menjadi false.');
        $this->assertNull($tokenRecord->consumed_at, 'consumed_at wajib rollback menjadi null.');

        // 9. Pastikan tidak ada registration nonce baru yang berstatus ISSUED
        $newNonceCount = BchatRegistrationNonce::where('public_key_fingerprint', $expectedFingerprint)->count();
        $this->assertEquals(0, $newNonceCount, 'Nonce baru tidak boleh tersimpan saat transaksi rollback.');

        // 10. Pastikan nonce lama yang sebelumnya ISSUED tetap ISSUED (tidak berubah jadi EXPIRED)
        $oldNonce->refresh();
        $this->assertEquals('ISSUED', $oldNonce->status, 'Status nonce lama wajib rollback tetap ISSUED.');
    }
}

