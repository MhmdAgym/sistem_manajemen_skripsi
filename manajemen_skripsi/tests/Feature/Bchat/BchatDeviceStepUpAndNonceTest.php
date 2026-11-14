<?php

namespace Tests\Feature\Bchat;

use App\Models\BchatRegistrationNonce;
use App\Models\BchatStepUpToken;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class BchatDeviceStepUpAndNonceTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        $activeDb = DB::connection()->getDatabaseName();
        $this->assertEquals(
            'manajemen_skripsi_test',
            $activeDb,
            'CRITICAL SECURITY: Test suite B-CHAT HANYA boleh berjalan pada database manajemen_skripsi_test.'
        );
    }

    private function createTestUser(string $password = 'PasswordRahasia123!'): User
    {
        return User::factory()->create([
            'password' => Hash::make($password),
        ]);
    }

    // =========================================================================
    // 1. PENGUJIAN ENDPOINT STEP-UP RE-AUTH (POST /api/bchat/devices/step-up)
    // =========================================================================

    /**
     * 1.1 Tamu (unauthenticated) ditolak mengakses endpoint step-up dengan HTTP 401.
     */
    public function test_guest_cannot_access_step_up_endpoint(): void
    {
        $response = $this->postJson('/api/bchat/devices/step-up', [
            'password'    => 'Password123!',
            'device_uuid' => (string) Str::uuid(),
        ]);

        $response->assertStatus(401);
    }

    /**
     * 1.2 User terotentikasi dengan password benar berhasil mendapatkan step-up token (HTTP 200).
     */
    public function test_authenticated_user_step_up_with_valid_password_succeeds(): void
    {
        $password = 'PasswordValidBanget123!';
        $user = $this->createTestUser($password);
        $deviceUuid = (string) Str::uuid();

        Sanctum::actingAs($user);

        $response = $this->postJson('/api/bchat/devices/step-up', [
            'password'    => $password,
            'device_uuid' => $deviceUuid,
        ]);

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'status',
            'message',
            'data' => [
                'user_id',
                'step_up_token',
                'device_uuid',
                'action_scope',
                'expires_at',
            ],
        ]);

        $data = $response->json('data');
        $this->assertSame((string) $user->id, $data['user_id']);
        $this->assertEquals(strtolower($deviceUuid), $data['device_uuid']);
        $this->assertEquals('DEVICE_REGISTRATION', $data['action_scope']);
        $this->assertEquals(64, strlen($data['step_up_token']));

        // Buktikan database hanya menyimpan hash SHA-256
        $expectedHash = hash('sha256', $data['step_up_token']);
        $this->assertDatabaseHas('bchat_step_up_tokens', [
            'user_id'     => $user->id,
            'device_uuid' => strtolower($deviceUuid),
            'token_hash'  => $expectedHash,
            'is_consumed' => false,
        ]);
    }

    /**
     * 1.3 Password salah ditolak dengan HTTP 401 dan kode error INVALID_CREDENTIALS.
     */
    public function test_authenticated_user_step_up_with_invalid_password_returns_401(): void
    {
        $user = $this->createTestUser('PasswordBenar123!');
        $deviceUuid = (string) Str::uuid();

        Sanctum::actingAs($user);

        $response = $this->postJson('/api/bchat/devices/step-up', [
            'password'    => 'PasswordSalahTotal!',
            'device_uuid' => $deviceUuid,
        ]);

        $response->assertStatus(401);
        $response->assertJson([
            'status'     => 'error',
            'error_code' => 'INVALID_CREDENTIALS',
        ]);
    }

    /**
     * 1.4 Validasi format request step-up melempar HTTP 422 jika input tidak lengkap atau tidak valid.
     */
    public function test_step_up_validation_errors(): void
    {
        $user = $this->createTestUser();
        Sanctum::actingAs($user);

        // Input kosong
        $resEmpty = $this->postJson('/api/bchat/devices/step-up', []);
        $resEmpty->assertStatus(422);
        $resEmpty->assertJsonValidationErrors(['password', 'device_uuid']);

        // Device UUID bukan format UUID valid
        $resInvalidUuid = $this->postJson('/api/bchat/devices/step-up', [
            'password'    => 'PasswordRahasia123!',
            'device_uuid' => 'bukan-uuid-valid-1234',
        ]);
        $resInvalidUuid->assertStatus(422);
        $resInvalidUuid->assertJsonValidationErrors(['device_uuid']);
    }

    // =========================================================================
    // 2. PENGUJIAN ENDPOINT NONCE (POST /api/bchat/devices/registration-nonce)
    // =========================================================================

    /**
     * 2.1 Tamu (unauthenticated) ditolak mengakses endpoint nonce dengan HTTP 401.
     */
    public function test_guest_cannot_access_registration_nonce_endpoint(): void
    {
        $response = $this->postJson('/api/bchat/devices/registration-nonce', [
            'step_up_token' => str_repeat('a', 64),
            'device_uuid'   => (string) Str::uuid(),
            'public_key'    => str_repeat('b', 64),
        ]);

        $response->assertStatus(401);
    }

    /**
     * 2.2 User terotentikasi dengan step-up token valid berhasil mendapatkan registration nonce (HTTP 200).
     */
    public function test_authenticated_user_issue_nonce_with_valid_token_succeeds(): void
    {
        $password = 'TestNonceEndpointSuccess123!';
        $user = $this->createTestUser($password);
        $deviceUuid = (string) Str::uuid();
        $publicKeyHex = str_repeat('1', 64);

        Sanctum::actingAs($user);

        // 1. Dapatkan Step-Up Token
        $stepUpRes = $this->postJson('/api/bchat/devices/step-up', [
            'password'    => $password,
            'device_uuid' => $deviceUuid,
        ]);
        $stepUpRes->assertStatus(200);
        $plainToken = $stepUpRes->json('data.step_up_token');

        // 2. Minta Registration Nonce
        $nonceRes = $this->postJson('/api/bchat/devices/registration-nonce', [
            'step_up_token' => $plainToken,
            'device_uuid'   => $deviceUuid,
            'public_key'    => $publicKeyHex,
        ]);

        $nonceRes->assertStatus(200);
        $nonceRes->assertJsonStructure([
            'status',
            'message',
            'data' => [
                'nonce_uuid',
                'registration_nonce',
                'device_uuid',
                'public_key_fingerprint',
                'issued_at',
                'expires_at',
            ],
        ]);

        $nonceData = $nonceRes->json('data');
        $this->assertEquals(64, strlen($nonceData['registration_nonce']));
        $this->assertEquals(hash('sha256', hex2bin($publicKeyHex)), $nonceData['public_key_fingerprint']);
        $this->assertIsInt($nonceData['issued_at']);

        // 3. Buktikan Step-up token telah dikonsumsi (is_consumed = true)
        $this->assertDatabaseHas('bchat_step_up_tokens', [
            'token_hash'  => hash('sha256', $plainToken),
            'is_consumed' => true,
        ]);

        // 4. Buktikan Nonce tercatat di database dengan status ISSUED
        $this->assertDatabaseHas('bchat_registration_nonces', [
            'user_id'     => $user->id,
            'device_uuid' => strtolower($deviceUuid),
            'nonce_value' => $nonceData['registration_nonce'],
            'status'      => 'ISSUED',
        ]);
    }

    /**
     * 2.3 Token yang sudah pernah dikonsumsi ditolak dengan HTTP 403.
     */
    public function test_authenticated_user_issue_nonce_with_consumed_token_returns_403(): void
    {
        $password = 'TestDoubleNonceUse123!';
        $user = $this->createTestUser($password);
        $deviceUuid = (string) Str::uuid();
        $publicKeyHex = str_repeat('2', 64);

        Sanctum::actingAs($user);

        $stepUpRes = $this->postJson('/api/bchat/devices/step-up', [
            'password'    => $password,
            'device_uuid' => $deviceUuid,
        ]);
        $plainToken = $stepUpRes->json('data.step_up_token');

        // Panggilan 1 -> Sukses
        $res1 = $this->postJson('/api/bchat/devices/registration-nonce', [
            'step_up_token' => $plainToken,
            'device_uuid'   => $deviceUuid,
            'public_key'    => $publicKeyHex,
        ]);
        $res1->assertStatus(200);

        // Panggilan 2 dengan token yang sama -> Wajib 403 Forbidden
        $res2 = $this->postJson('/api/bchat/devices/registration-nonce', [
            'step_up_token' => $plainToken,
            'device_uuid'   => $deviceUuid,
            'public_key'    => $publicKeyHex,
        ]);
        $res2->assertStatus(403);
        $res2->assertJson([
            'status'     => 'error',
            'error_code' => 'STEP_UP_TOKEN_ALREADY_CONSUMED',
        ]);
    }

    /**
     * 2.4 Device UUID yang tidak cocok dengan otorisasi step-up ditolak dengan HTTP 403.
     */
    public function test_authenticated_user_issue_nonce_with_mismatched_device_uuid_returns_403(): void
    {
        $password = 'MismatchedDeviceCheck123!';
        $user = $this->createTestUser($password);
        $deviceUuidA = (string) Str::uuid();
        $deviceUuidB = (string) Str::uuid();
        $publicKeyHex = str_repeat('3', 64);

        Sanctum::actingAs($user);

        $stepUpRes = $this->postJson('/api/bchat/devices/step-up', [
            'password'    => $password,
            'device_uuid' => $deviceUuidA,
        ]);
        $plainToken = $stepUpRes->json('data.step_up_token');

        // Gunakan token device A untuk meminta nonce bagi device B
        $nonceRes = $this->postJson('/api/bchat/devices/registration-nonce', [
            'step_up_token' => $plainToken,
            'device_uuid'   => $deviceUuidB,
            'public_key'    => $publicKeyHex,
        ]);

        $nonceRes->assertStatus(403);
        $nonceRes->assertJson([
            'status'     => 'error',
            'error_code' => 'STEP_UP_DEVICE_MISMATCH',
        ]);
    }

    /**
     * 2.5 Token milik user lain ditolak dengan HTTP 403.
     */
    public function test_authenticated_user_issue_nonce_with_other_user_token_returns_403(): void
    {
        $userA = $this->createTestUser('PassUserA123!');
        $userB = $this->createTestUser('PassUserB123!');
        $deviceUuid = (string) Str::uuid();
        $publicKeyHex = str_repeat('4', 64);

        // User A meminta step-up
        Sanctum::actingAs($userA);
        $stepUpA = $this->postJson('/api/bchat/devices/step-up', [
            'password'    => 'PassUserA123!',
            'device_uuid' => $deviceUuid,
        ]);
        $plainTokenA = $stepUpA->json('data.step_up_token');

        // User B mencoba menggunakan token milik User A
        Sanctum::actingAs($userB);
        $nonceRes = $this->postJson('/api/bchat/devices/registration-nonce', [
            'step_up_token' => $plainTokenA,
            'device_uuid'   => $deviceUuid,
            'public_key'    => $publicKeyHex,
        ]);

        $nonceRes->assertStatus(403);
        $nonceRes->assertJson([
            'status'     => 'error',
            'error_code' => 'STEP_UP_USER_MISMATCH',
        ]);
    }

    /**
     * 2.6 Public key invalid ditolak dengan HTTP 422.
     */
    public function test_authenticated_user_issue_nonce_with_invalid_public_key_returns_422(): void
    {
        $password = 'InvalidPubKeyCheck123!';
        $user = $this->createTestUser($password);
        $deviceUuid = (string) Str::uuid();

        Sanctum::actingAs($user);

        $stepUpRes = $this->postJson('/api/bchat/devices/step-up', [
            'password'    => $password,
            'device_uuid' => $deviceUuid,
        ]);
        $plainToken = $stepUpRes->json('data.step_up_token');

        $invalidPubKeys = [
            'bukan-hex-pendek',
            str_repeat('g', 64), // 'g' bukan karakter heksadesimal
            str_repeat('a', 63), // Kurang 1 karakter
        ];

        foreach ($invalidPubKeys as $invalidKey) {
            $nonceRes = $this->postJson('/api/bchat/devices/registration-nonce', [
                'step_up_token' => $plainToken,
                'device_uuid'   => $deviceUuid,
                'public_key'    => $invalidKey,
            ]);

            $nonceRes->assertStatus(422);
        }
    }

    /**
     * 2.7 Meminta nonce baru meng-expire nonce lama dan hanya mempertahankan TEPAT 1 nonce ISSUED.
     */
    public function test_only_one_active_issued_nonce_maintained_across_multiple_calls(): void
    {
        $password = 'MultiNonceSessionCheck123!';
        $user = $this->createTestUser($password);
        $deviceUuid = (string) Str::uuid();
        $publicKeyHex = str_repeat('5', 64);

        Sanctum::actingAs($user);

        // Putaran 1
        $step1 = $this->postJson('/api/bchat/devices/step-up', ['password' => $password, 'device_uuid' => $deviceUuid]);
        $nonceRes1 = $this->postJson('/api/bchat/devices/registration-nonce', [
            'step_up_token' => $step1->json('data.step_up_token'),
            'device_uuid'   => $deviceUuid,
            'public_key'    => $publicKeyHex,
        ]);
        $nonceVal1 = $nonceRes1->json('data.registration_nonce');

        // Putaran 2
        $step2 = $this->postJson('/api/bchat/devices/step-up', ['password' => $password, 'device_uuid' => $deviceUuid]);
        $nonceRes2 = $this->postJson('/api/bchat/devices/registration-nonce', [
            'step_up_token' => $step2->json('data.step_up_token'),
            'device_uuid'   => $deviceUuid,
            'public_key'    => $publicKeyHex,
        ]);
        $nonceVal2 = $nonceRes2->json('data.registration_nonce');

        // Verifikasi nonce 1 berubah status menjadi EXPIRED
        $this->assertDatabaseHas('bchat_registration_nonces', [
            'nonce_value' => $nonceVal1,
            'status'      => 'EXPIRED',
        ]);

        // Verifikasi nonce 2 berstatus ISSUED
        $this->assertDatabaseHas('bchat_registration_nonces', [
            'nonce_value' => $nonceVal2,
            'status'      => 'ISSUED',
        ]);

        // Verifikasi jumlah nonce berstatus ISSUED untuk user ini TEPAT 1
        $issuedCount = BchatRegistrationNonce::where('user_id', $user->id)
            ->where('status', 'ISSUED')
            ->count();
        $this->assertEquals(1, $issuedCount);
    }
}

