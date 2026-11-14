<?php

declare(strict_types=1);

namespace Tests\Feature\Bchat;

use App\Models\BchatRegistrationNonce;
use App\Models\BchatUserDevice;
use App\Models\User;
use App\Services\BchatPopVerificationService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Feature Test untuk Device Status Endpoint & Replacement Protection (I-05A.UI-ONBOARDING Tahap A).
 *
 * Cakupan skenario:
 * 1.  Pengguna tanpa perangkat ACTIVE dapat melakukan registrasi awal tanpa confirm_replacement.
 * 2.  Pengguna dengan perangkat ACTIVE ditolak jika confirm_replacement tidak diberikan.
 * 3.  Pengguna dengan perangkat ACTIVE ditolak jika confirm_replacement = false.
 * 4.  Penolakan tidak mencabut perangkat ACTIVE lama.
 * 5.  Pengguna dapat melakukan replacement jika konfirmasi valid dan expected device UUID cocok.
 * 6.  Setelah replacement berhasil, hanya perangkat baru yang ACTIVE.
 * 7.  Expected device UUID yang stale (berbeda dengan aktual) menghasilkan HTTP 409.
 * 8.  Stale UUID tidak menyebabkan perangkat aktif yang sebenarnya ikut dicabut.
 * 9.  Endpoint status mengembalikan status perangkat milik pengguna yang login.
 * 10. Endpoint status dapat merekonsiliasi UUID perangkat staged yang sudah ACTIVE.
 * 11. Endpoint status tidak membocorkan perangkat milik pengguna lain.
 * 12. Endpoint status tidak membocorkan token, nonce, private key, atau material kriptografi.
 *
 * JAMINAN KEAMANAN:
 * - Seluruh pengujian menggunakan DatabaseTransactions: data di-rollback otomatis.
 * - Pengujian TIDAK menggunakan atau memutasi device ID 342 (user 1327) atau 343 (user 1326).
 * - Seluruh pengguna uji dibuat baru via factory dan tidak terkait akun produksi.
 */
class BchatDeviceStatusAndReplacementProtectionTest extends TestCase
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

    // =========================================================================
    // HELPER FIXTURE & CRYPTO GENERATORS
    // =========================================================================

    private function createTestUser(string $password = 'PasswordRahasia123!'): User
    {
        return User::factory()->create([
            'password' => Hash::make($password),
        ]);
    }

    private function generateEd25519Keypair(): array
    {
        $keypair   = sodium_crypto_sign_keypair();
        $secretKey = sodium_crypto_sign_secretkey($keypair);
        $publicKey = sodium_crypto_sign_publickey($keypair);

        return [
            'secret_key'     => $secretKey,
            'public_key'     => $publicKey,
            'public_key_hex' => bin2hex($publicKey),
            'fingerprint'    => hash('sha256', $publicKey),
        ];
    }

    private function issueValidNonceForUser(
        User $user,
        string $deviceUuid,
        array $keypair,
        ?int $issuedAt = null
    ): BchatRegistrationNonce {
        $serverIssuedAt = $issuedAt ?? time();

        return BchatRegistrationNonce::create([
            'user_id'                => $user->id,
            'device_uuid'            => strtolower(trim($deviceUuid)),
            'nonce_uuid'             => (string) Str::uuid(),
            'nonce_value'            => bin2hex(random_bytes(32)),
            'public_key_fingerprint' => $keypair['fingerprint'],
            'status'                 => 'ISSUED',
            'issued_at'              => $serverIssuedAt,
            'expires_at'             => now()->addMinutes(5),
            'consumed_at'            => null,
        ]);
    }

    private function createValidPoP(
        int|string $userId,
        string $deviceUuid,
        BchatRegistrationNonce $nonce,
        array $keypair,
        ?int $timestamp = null
    ): array {
        $ts = $timestamp ?? $nonce->issued_at;

        $payload   = BchatPopVerificationService::buildPayload(
            userId: $userId,
            deviceUuid: $deviceUuid,
            nonceHexOrBin: $nonce->nonce_value,
            issuedAt: $ts,
            publicKeyHexOrBin: $keypair['public_key_hex']
        );

        $signature = sodium_crypto_sign_detached($payload, $keypair['secret_key']);

        return [
            'payload_hex'   => bin2hex($payload),
            'signature_hex' => bin2hex($signature),
        ];
    }

    /**
     * Membuat perangkat ACTIVE langsung di DB (simulasi perangkat yang sudah ada sebelumnya).
     * Tidak menggunakan HTTP register agar test isolasi lebih cepat dan tidak bergantung
     * pada alur registrasi yang sedang diuji.
     */
    private function createActiveDevice(User $user, ?string $deviceUuid = null): BchatUserDevice
    {
        $keypair = $this->generateEd25519Keypair();

        return BchatUserDevice::create([
            'user_id'                => $user->id,
            'device_uuid'            => $deviceUuid ?? (string) Str::uuid(),
            'device_name'            => 'Existing Active Device',
            'key_version'            => 1,
            'public_key'             => $keypair['public_key_hex'],
            'public_key_fingerprint' => $keypair['fingerprint'],
            'status'                 => 'ACTIVE',
            'last_active_at'         => now(),
        ]);
    }

    // =========================================================================
    // BAGIAN A: REPLACEMENT PROTECTION
    // =========================================================================

    /**
     * Skenario 1: Pengguna tanpa perangkat ACTIVE dapat melakukan registrasi awal
     * tanpa menyertakan confirm_replacement maupun expected_active_device_uuid.
     */
    public function test_01_initial_registration_succeeds_without_replacement_confirmation(): void
    {
        $user = $this->createTestUser();
        Sanctum::actingAs($user);

        // Pastikan tidak ada perangkat ACTIVE
        $this->assertEquals(0, BchatUserDevice::where('user_id', $user->id)->where('status', 'ACTIVE')->count());

        $deviceUuid = (string) Str::uuid();
        $keypair    = $this->generateEd25519Keypair();
        $nonce      = $this->issueValidNonceForUser($user, $deviceUuid, $keypair);
        $pop        = $this->createValidPoP($user->id, $deviceUuid, $nonce, $keypair);

        $response = $this->postJson('/api/bchat/devices/register', [
            'nonce_uuid'    => $nonce->nonce_uuid,
            'pop_payload'   => $pop['payload_hex'],
            'pop_signature' => $pop['signature_hex'],
            'device_name'   => 'Browser Baru Mahasiswa',
            // confirm_replacement tidak disertakan
        ]);

        $response->assertStatus(201)
            ->assertJson([
                'status' => 'success',
                'data'   => [
                    'device_uuid'    => $deviceUuid,
                    'status'         => 'ACTIVE',
                    'is_replacement' => false,
                ],
            ]);
    }

    /**
     * Skenario 2: Pengguna dengan perangkat ACTIVE ditolak HTTP 409 jika
     * confirm_replacement tidak disertakan dalam payload.
     */
    public function test_02_registration_rejected_without_confirm_replacement_when_active_device_exists(): void
    {
        $user = $this->createTestUser();
        Sanctum::actingAs($user);

        $existingDevice = $this->createActiveDevice($user);

        $newDeviceUuid = (string) Str::uuid();
        $newKeypair    = $this->generateEd25519Keypair();
        $nonce         = $this->issueValidNonceForUser($user, $newDeviceUuid, $newKeypair);
        $pop           = $this->createValidPoP($user->id, $newDeviceUuid, $nonce, $newKeypair);

        $response = $this->postJson('/api/bchat/devices/register', [
            'nonce_uuid'    => $nonce->nonce_uuid,
            'pop_payload'   => $pop['payload_hex'],
            'pop_signature' => $pop['signature_hex'],
            // confirm_replacement sengaja tidak disertakan
        ]);

        $response->assertStatus(409)
            ->assertJson([
                'status'     => 'error',
                'error_code' => 'REPLACEMENT_CONFIRMATION_REQUIRED',
            ]);

        // Perangkat lama harus tetap ACTIVE
        $existingDevice->refresh();
        $this->assertEquals('ACTIVE', $existingDevice->status);
    }

    /**
     * Skenario 3: Pengguna dengan perangkat ACTIVE ditolak HTTP 409 jika
     * confirm_replacement = false (bernilai falsy eksplisit).
     */
    public function test_03_registration_rejected_when_confirm_replacement_is_false(): void
    {
        $user = $this->createTestUser();
        Sanctum::actingAs($user);

        $existingDevice = $this->createActiveDevice($user);

        $newDeviceUuid = (string) Str::uuid();
        $newKeypair    = $this->generateEd25519Keypair();
        $nonce         = $this->issueValidNonceForUser($user, $newDeviceUuid, $newKeypair);
        $pop           = $this->createValidPoP($user->id, $newDeviceUuid, $nonce, $newKeypair);

        $response = $this->postJson('/api/bchat/devices/register', [
            'nonce_uuid'          => $nonce->nonce_uuid,
            'pop_payload'         => $pop['payload_hex'],
            'pop_signature'       => $pop['signature_hex'],
            'confirm_replacement' => false, // Eksplisit false
        ]);

        $response->assertStatus(409)
            ->assertJson([
                'status'     => 'error',
                'error_code' => 'REPLACEMENT_CONFIRMATION_REQUIRED',
            ]);

        // Perangkat lama harus tetap ACTIVE
        $existingDevice->refresh();
        $this->assertEquals('ACTIVE', $existingDevice->status);
    }

    /**
     * Skenario 4: Penolakan akibat kurangnya konfirmasi tidak mencabut perangkat ACTIVE lama.
     * (Verifikasi database state tambahan di luar skenario 2 dan 3.)
     */
    public function test_04_rejection_does_not_revoke_old_device(): void
    {
        $user = $this->createTestUser();
        Sanctum::actingAs($user);

        $existingUuid   = (string) Str::uuid();
        $existingDevice = $this->createActiveDevice($user, $existingUuid);

        $newDeviceUuid = (string) Str::uuid();
        $newKeypair    = $this->generateEd25519Keypair();
        $nonce         = $this->issueValidNonceForUser($user, $newDeviceUuid, $newKeypair);
        $pop           = $this->createValidPoP($user->id, $newDeviceUuid, $nonce, $newKeypair);

        $this->postJson('/api/bchat/devices/register', [
            'nonce_uuid'          => $nonce->nonce_uuid,
            'pop_payload'         => $pop['payload_hex'],
            'pop_signature'       => $pop['signature_hex'],
            'confirm_replacement' => false,
        ])->assertStatus(409);

        // Verifikasi DB: perangkat lama masih ACTIVE
        $this->assertDatabaseHas('bchat_user_devices', [
            'device_uuid' => $existingUuid,
            'user_id'     => $user->id,
            'status'      => 'ACTIVE',
        ]);

        // Perangkat baru TIDAK boleh terdaftar
        $this->assertDatabaseMissing('bchat_user_devices', [
            'device_uuid' => $newDeviceUuid,
            'user_id'     => $user->id,
        ]);
    }

    /**
     * Skenario 5: Pengguna dapat melakukan Managed Replacement jika konfirmasi valid
     * dan expected_active_device_uuid cocok dengan perangkat aktif aktual.
     */
    public function test_05_replacement_succeeds_with_valid_confirmation_and_matching_uuid(): void
    {
        $user = $this->createTestUser();
        Sanctum::actingAs($user);

        $oldDeviceUuid  = (string) Str::uuid();
        $existingDevice = $this->createActiveDevice($user, $oldDeviceUuid);

        $newDeviceUuid = (string) Str::uuid();
        $newKeypair    = $this->generateEd25519Keypair();
        $nonce         = $this->issueValidNonceForUser($user, $newDeviceUuid, $newKeypair);
        $pop           = $this->createValidPoP($user->id, $newDeviceUuid, $nonce, $newKeypair);

        $response = $this->postJson('/api/bchat/devices/register', [
            'nonce_uuid'                  => $nonce->nonce_uuid,
            'pop_payload'                 => $pop['payload_hex'],
            'pop_signature'               => $pop['signature_hex'],
            'device_name'                 => 'Browser Pengganti',
            'confirm_replacement'         => true,
            'expected_active_device_uuid' => $oldDeviceUuid,
        ]);

        $response->assertStatus(201)
            ->assertJson([
                'status' => 'success',
                'data'   => [
                    'device_uuid'    => $newDeviceUuid,
                    'status'         => 'ACTIVE',
                    'is_replacement' => true,
                ],
            ]);

        // Perangkat lama harus REVOKED
        $existingDevice->refresh();
        $this->assertEquals('REVOKED', $existingDevice->status);
        $this->assertEquals('MANAGED_REPLACEMENT', $existingDevice->revocation_reason);
    }

    /**
     * Skenario 6: Setelah replacement berhasil, hanya satu perangkat yang ACTIVE.
     */
    public function test_06_after_replacement_only_new_device_is_active(): void
    {
        $user = $this->createTestUser();
        Sanctum::actingAs($user);

        $oldDeviceUuid = (string) Str::uuid();
        $this->createActiveDevice($user, $oldDeviceUuid);

        $newDeviceUuid = (string) Str::uuid();
        $newKeypair    = $this->generateEd25519Keypair();
        $nonce         = $this->issueValidNonceForUser($user, $newDeviceUuid, $newKeypair);
        $pop           = $this->createValidPoP($user->id, $newDeviceUuid, $nonce, $newKeypair);

        $this->postJson('/api/bchat/devices/register', [
            'nonce_uuid'                  => $nonce->nonce_uuid,
            'pop_payload'                 => $pop['payload_hex'],
            'pop_signature'               => $pop['signature_hex'],
            'confirm_replacement'         => true,
            'expected_active_device_uuid' => $oldDeviceUuid,
        ])->assertStatus(201);

        // Hanya satu perangkat ACTIVE
        $activeDevices = BchatUserDevice::where('user_id', $user->id)
            ->where('status', 'ACTIVE')
            ->get();

        $this->assertCount(1, $activeDevices);
        $this->assertEquals($newDeviceUuid, $activeDevices->first()->device_uuid);
    }

    /**
     * Skenario 7: Expected device UUID yang stale (tidak cocok dengan perangkat aktif aktual)
     * menghasilkan HTTP 409 EXPECTED_DEVICE_UUID_MISMATCH.
     */
    public function test_07_stale_expected_uuid_returns_409(): void
    {
        $user = $this->createTestUser();
        Sanctum::actingAs($user);

        $realDeviceUuid = (string) Str::uuid();
        $this->createActiveDevice($user, $realDeviceUuid);

        $staleUuid = (string) Str::uuid(); // UUID berbeda — stale / salah

        $newDeviceUuid = (string) Str::uuid();
        $newKeypair    = $this->generateEd25519Keypair();
        $nonce         = $this->issueValidNonceForUser($user, $newDeviceUuid, $newKeypair);
        $pop           = $this->createValidPoP($user->id, $newDeviceUuid, $nonce, $newKeypair);

        $response = $this->postJson('/api/bchat/devices/register', [
            'nonce_uuid'                  => $nonce->nonce_uuid,
            'pop_payload'                 => $pop['payload_hex'],
            'pop_signature'               => $pop['signature_hex'],
            'confirm_replacement'         => true,
            'expected_active_device_uuid' => $staleUuid, // UUID yang tidak cocok
        ]);

        $response->assertStatus(409)
            ->assertJson([
                'status'     => 'error',
                'error_code' => 'EXPECTED_DEVICE_UUID_MISMATCH',
            ]);
    }

    /**
     * Skenario 8: Stale UUID tidak menyebabkan perangkat aktif yang sebenarnya ikut dicabut.
     */
    public function test_08_stale_uuid_rejection_does_not_revoke_real_active_device(): void
    {
        $user = $this->createTestUser();
        Sanctum::actingAs($user);

        $realDeviceUuid  = (string) Str::uuid();
        $realActiveDevice = $this->createActiveDevice($user, $realDeviceUuid);

        $staleUuid = (string) Str::uuid();

        $newDeviceUuid = (string) Str::uuid();
        $newKeypair    = $this->generateEd25519Keypair();
        $nonce         = $this->issueValidNonceForUser($user, $newDeviceUuid, $newKeypair);
        $pop           = $this->createValidPoP($user->id, $newDeviceUuid, $nonce, $newKeypair);

        $this->postJson('/api/bchat/devices/register', [
            'nonce_uuid'                  => $nonce->nonce_uuid,
            'pop_payload'                 => $pop['payload_hex'],
            'pop_signature'               => $pop['signature_hex'],
            'confirm_replacement'         => true,
            'expected_active_device_uuid' => $staleUuid,
        ])->assertStatus(409);

        // Perangkat aktif asli harus tetap ACTIVE
        $realActiveDevice->refresh();
        $this->assertEquals('ACTIVE', $realActiveDevice->status);
        $this->assertNull($realActiveDevice->revoked_at);
        $this->assertNull($realActiveDevice->revocation_reason);
    }

    // =========================================================================
    // BAGIAN B: ENDPOINT STATUS PERANGKAT
    // =========================================================================

    /**
     * Skenario 9: Endpoint status mengembalikan has_active_device = true beserta metadata
     * perangkat aktif milik pengguna yang sedang login.
     */
    public function test_09_status_endpoint_returns_active_device_info_for_logged_in_user(): void
    {
        $user = $this->createTestUser();
        Sanctum::actingAs($user);

        $deviceUuid = (string) Str::uuid();
        $device     = $this->createActiveDevice($user, $deviceUuid);

        $response = $this->getJson('/api/bchat/devices/status');

        $response->assertStatus(200)
            ->assertJson([
                'status' => 'success',
                'data'   => [
                    'has_active_device' => true,
                    'active_device'     => [
                        'device_uuid' => $deviceUuid,
                        'status'      => 'ACTIVE',
                        'key_version' => 1,
                    ],
                ],
            ]);
    }

    /**
     * Skenario 9b: Endpoint status mengembalikan has_active_device = false ketika
     * pengguna belum memiliki perangkat ACTIVE.
     */
    public function test_09b_status_endpoint_returns_no_active_device_for_new_user(): void
    {
        $user = $this->createTestUser();
        Sanctum::actingAs($user);

        $response = $this->getJson('/api/bchat/devices/status');

        $response->assertStatus(200)
            ->assertJson([
                'status' => 'success',
                'data'   => [
                    'has_active_device' => false,
                    'active_device'     => null,
                    'queried_device'    => null,
                ],
            ]);
    }

    /**
     * Skenario 10: Endpoint status dapat merekonsiliasi UUID perangkat staged yang sudah ACTIVE.
     * Klien mengirimkan device_uuid staged, endpoint mengembalikan is_active = true.
     */
    public function test_10_status_endpoint_reconciles_staged_uuid_that_is_already_active(): void
    {
        $user = $this->createTestUser();
        Sanctum::actingAs($user);

        // Simulasi: device_uuid ini sudah didaftarkan dan berstatus ACTIVE di backend
        // (misalnya karena browser menutup sebelum menerima respons registrasi)
        $stagedDeviceUuid = (string) Str::uuid();
        $this->createActiveDevice($user, $stagedDeviceUuid);

        $response = $this->getJson('/api/bchat/devices/status?device_uuid=' . $stagedDeviceUuid);

        $response->assertStatus(200)
            ->assertJson([
                'status' => 'success',
                'data'   => [
                    'has_active_device' => true,
                    'queried_device'    => [
                        'device_uuid' => $stagedDeviceUuid,
                        'status'      => 'ACTIVE',
                        'is_active'   => true,
                    ],
                ],
            ]);
    }

    /**
     * Skenario 10b: Endpoint status mengembalikan queried_device = null ketika UUID
     * yang diminta belum pernah terdaftar atas nama user ini.
     */
    public function test_10b_status_endpoint_returns_null_for_unknown_staged_uuid(): void
    {
        $user = $this->createTestUser();
        Sanctum::actingAs($user);

        $unknownUuid = (string) Str::uuid();

        $response = $this->getJson('/api/bchat/devices/status?device_uuid=' . $unknownUuid);

        $response->assertStatus(200)
            ->assertJson([
                'status' => 'success',
                'data'   => [
                    'has_active_device' => false,
                    'queried_device'    => null,
                ],
            ]);
    }

    /**
     * Skenario 11: Endpoint status tidak membocorkan informasi perangkat milik pengguna lain.
     * Jika User B mengirimkan device_uuid milik User A, hasilnya null tanpa error.
     */
    public function test_11_status_endpoint_does_not_leak_other_users_device_info(): void
    {
        $userA = $this->createTestUser();
        $userB = $this->createTestUser();

        // Buat perangkat aktif untuk User A
        $userADeviceUuid = (string) Str::uuid();
        $this->createActiveDevice($userA, $userADeviceUuid);

        // User B mencoba mengambil info device milik User A
        Sanctum::actingAs($userB);

        $response = $this->getJson('/api/bchat/devices/status?device_uuid=' . $userADeviceUuid);

        $response->assertStatus(200);

        $data = $response->json('data');

        // queried_device harus null — tidak membocorkan info milik User A
        $this->assertNull($data['queried_device']);

        // has_active_device mengacu pada User B, bukan User A
        $this->assertFalse($data['has_active_device']);
    }

    /**
     * Skenario 12: Endpoint status tidak mengembalikan token, nonce, private key,
     * atau material kriptografi sensitif apa pun.
     */
    public function test_12_status_endpoint_does_not_expose_cryptographic_material(): void
    {
        $user = $this->createTestUser();
        Sanctum::actingAs($user);

        $deviceUuid = (string) Str::uuid();
        $this->createActiveDevice($user, $deviceUuid);

        $response = $this->getJson('/api/bchat/devices/status?device_uuid=' . $deviceUuid);

        $response->assertStatus(200);

        $rawJson = $response->getContent();

        // Field-field sensitif TIDAK boleh muncul di respons
        $sensitivePatterns = [
            'private_key',
            'secret_key',
            'step_up_token',
            'registration_nonce',
            'nonce_value',
            'token_hash',
            'public_key_fingerprint',  // fingerprint bersifat derivate kriptografis — tidak diperlukan UI
        ];

        foreach ($sensitivePatterns as $pattern) {
            $this->assertStringNotContainsString(
                '"' . $pattern . '"',
                $rawJson,
                "Respons endpoint status TIDAK BOLEH mengekspos field sensitif: {$pattern}"
            );
        }
    }

    /**
     * Skenario tambahan: Tamu (unauthenticated) ditolak mengakses endpoint status dengan HTTP 401.
     */
    public function test_13_guest_cannot_access_status_endpoint(): void
    {
        $response = $this->getJson('/api/bchat/devices/status');
        $response->assertStatus(401);
    }

    /**
     * Skenario tambahan: device_uuid dengan format tidak valid pada query string
     * menghasilkan HTTP 422 (validasi request).
     */
    public function test_14_invalid_uuid_format_in_query_returns_422(): void
    {
        $user = $this->createTestUser();
        Sanctum::actingAs($user);

        $response = $this->getJson('/api/bchat/devices/status?device_uuid=bukan-uuid-valid');
        $response->assertStatus(422);
    }
}

