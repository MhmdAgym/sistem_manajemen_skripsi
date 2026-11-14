<?php

declare(strict_types=1);

namespace Tests\Feature\Bchat;

use App\Models\BchatKeyPackage;
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
 * Feature Test untuk HTTP Device Registration API (B-CHAT-22B Tahap 2C.2B).
 *
 * Menguji endpoint:
 * - POST /api/bchat/devices/register
 *
 * Cakupan skenario:
 * 1. Guest ditolak HTTP 401.
 * 2. Payload dan signature dengan format salah ditolak HTTP 422.
 * 3. Registrasi perangkat pertama berhasil HTTP 201.
 * 4. Nonce UUID tidak ditemukan ditolak HTTP 404.
 * 5. Nonce milik user lain ditolak HTTP 403.
 * 6. Nonce yang sudah dikonsumsi ditolak HTTP 403.
 * 7. Nonce kedaluwarsa ditolak HTTP 403.
 * 8. Magic header atau parameter PoP rusak ditolak HTTP 422.
 * 9. Signature Ed25519 tidak valid ditolak HTTP 401.
 * 10. Public key yang sudah terdaftar pada perangkat lain ditolak HTTP 409.
 * 11. Managed Replacement berhasil dan memverifikasi:
 *     - perangkat lama REVOKED;
 *     - perangkat baru ACTIVE;
 *     - KeyPackage lama tidak dapat digunakan;
 *     - response tidak mengekspos primary key internal.
 */
class BchatDeviceRegistrationFeatureTest extends TestCase
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
        $keypair = sodium_crypto_sign_keypair();
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

        $payload = BchatPopVerificationService::buildPayload(
            userId: $userId,
            deviceUuid: $deviceUuid,
            nonceHexOrBin: $nonce->nonce_value,
            issuedAt: $ts,
            publicKeyHexOrBin: $keypair['public_key_hex']
        );

        $signature = sodium_crypto_sign_detached($payload, $keypair['secret_key']);

        return [
            'payload_binary'   => $payload,
            'payload_hex'      => bin2hex($payload),
            'signature_binary' => $signature,
            'signature_hex'    => bin2hex($signature),
        ];
    }

    private function createTestKeyPackage(
        User $user,
        BchatUserDevice $device,
        bool $isConsumed = false
    ): BchatKeyPackage {
        return BchatKeyPackage::create([
            'user_id'          => $user->id,
            'device_id'        => $device->id,
            'key_package_hash' => hash('sha256', Str::random(32)),
            'key_package_data' => 'mls_key_package_binary_' . Str::random(32),
            'is_consumed'      => $isConsumed,
            'consumed_at'      => $isConsumed ? now()->subHour() : null,
        ]);
    }

    // =========================================================================
    // 11 SKENARIO PENGUJIAN HTTP DEVICE REGISTRATION
    // =========================================================================

    /**
     * Skenario 1: Tamu (unauthenticated) ditolak mengakses endpoint registrasi dengan HTTP 401.
     */
    public function test_01_guest_cannot_access_register_endpoint(): void
    {
        $response = $this->postJson('/api/bchat/devices/register', [
            'nonce_uuid'    => (string) Str::uuid(),
            'pop_payload'   => str_repeat('a', 242),
            'pop_signature' => str_repeat('b', 128),
        ]);

        $response->assertStatus(401);
    }

    /**
     * Skenario 2: Payload dan signature dengan format salah ditolak dengan HTTP 422.
     */
    public function test_02_validation_fails_for_invalid_payload_and_signature_formats(): void
    {
        $user = $this->createTestUser();
        Sanctum::actingAs($user);

        // Kasus A: Field kosong
        $responseMissing = $this->postJson('/api/bchat/devices/register', []);
        $responseMissing->assertStatus(422)
            ->assertJsonValidationErrors(['nonce_uuid', 'pop_payload', 'pop_signature']);

        // Kasus B: Format UUID salah, panjang hex payload/signature salah, device_name > 100 char
        $responseInvalid = $this->postJson('/api/bchat/devices/register', [
            'nonce_uuid'    => 'bukan-uuid-yang-valid',
            'pop_payload'   => '123456abcdef', // kurang dari 242 char
            'pop_signature' => '123456abcdef', // kurang dari 128 char
            'device_name'   => str_repeat('D', 101),
        ]);
        $responseInvalid->assertStatus(422)
            ->assertJsonValidationErrors(['nonce_uuid', 'pop_payload', 'pop_signature', 'device_name']);

        // Kasus C: Karakter uppercase hex (harus strictly lowercase hex)
        $responseUppercase = $this->postJson('/api/bchat/devices/register', [
            'nonce_uuid'    => (string) Str::uuid(),
            'pop_payload'   => str_repeat('A', 242),
            'pop_signature' => str_repeat('F', 128),
        ]);
        $responseUppercase->assertStatus(422)
            ->assertJsonValidationErrors(['pop_payload', 'pop_signature']);
    }

    /**
     * Skenario 3: Registrasi perangkat pertama berhasil dengan HTTP 201 dan metadata yang aman.
     */
    public function test_03_successful_initial_device_registration_returns_201_and_device_metadata(): void
    {
        $user = $this->createTestUser();
        Sanctum::actingAs($user);

        $deviceUuid = (string) Str::uuid();
        $keypair = $this->generateEd25519Keypair();
        $nonce = $this->issueValidNonceForUser($user, $deviceUuid, $keypair);
        $pop = $this->createValidPoP($user->id, $deviceUuid, $nonce, $keypair);

        $response = $this->postJson('/api/bchat/devices/register', [
            'nonce_uuid'    => $nonce->nonce_uuid,
            'pop_payload'   => $pop['payload_hex'],
            'pop_signature' => $pop['signature_hex'],
            'device_name'   => 'Chrome Desktop Windows',
        ]);

        $response->assertStatus(201)
            ->assertJson([
                'status'  => 'success',
                'message' => 'Perangkat berhasil didaftarkan.',
                'data'    => [
                    'device_uuid'    => $deviceUuid,
                    'device_name'    => 'Chrome Desktop Windows',
                    'key_version'    => 1,
                    'status'         => 'ACTIVE',
                    'public_key'     => $keypair['public_key_hex'],
                    'is_replacement' => false,
                ],
            ]);

        // Verifikasi allowlist: ID internal database dan fingerprint tidak bocor
        $data = $response->json('data');
        $this->assertArrayNotHasKey('device_id', $data);
        $this->assertArrayNotHasKey('revoked_device_id', $data);
        $this->assertArrayNotHasKey('public_key_fingerprint', $data);

        // Verifikasi mutasi database
        $this->assertDatabaseHas('bchat_user_devices', [
            'user_id'     => $user->id,
            'device_uuid' => $deviceUuid,
            'status'      => 'ACTIVE',
            'key_version' => 1,
            'public_key'  => $keypair['public_key_hex'],
        ]);

        $nonce->refresh();
        $this->assertEquals('CONSUMED', $nonce->status);
        $this->assertNotNull($nonce->consumed_at);
    }

    /**
     * Skenario 4: Nonce UUID tidak ditemukan ditolak dengan HTTP 404.
     */
    public function test_04_registration_fails_if_nonce_uuid_not_found(): void
    {
        $user = $this->createTestUser();
        Sanctum::actingAs($user);

        $dummyNonceUuid = (string) Str::uuid();

        $response = $this->postJson('/api/bchat/devices/register', [
            'nonce_uuid'    => $dummyNonceUuid,
            'pop_payload'   => str_repeat('a', 242),
            'pop_signature' => str_repeat('b', 128),
        ]);

        $response->assertStatus(404)
            ->assertJson([
                'status'     => 'error',
                'error_code' => 'REGISTRATION_NONCE_NOT_FOUND',
            ]);
    }

    /**
     * Skenario 5: Nonce milik user lain ditolak dengan HTTP 403.
     */
    public function test_05_registration_fails_if_nonce_belongs_to_another_user(): void
    {
        $userA = $this->createTestUser();
        $userB = $this->createTestUser();

        $deviceUuid = (string) Str::uuid();
        $keypair = $this->generateEd25519Keypair();
        $nonceUserB = $this->issueValidNonceForUser($userB, $deviceUuid, $keypair);
        $pop = $this->createValidPoP($userB->id, $deviceUuid, $nonceUserB, $keypair);

        // User A mencoba meregistrasikan perangkat memakai nonce milik User B
        Sanctum::actingAs($userA);

        $response = $this->postJson('/api/bchat/devices/register', [
            'nonce_uuid'    => $nonceUserB->nonce_uuid,
            'pop_payload'   => $pop['payload_hex'],
            'pop_signature' => $pop['signature_hex'],
        ]);

        $response->assertStatus(403)
            ->assertJson([
                'status'     => 'error',
                'error_code' => 'NONCE_USER_MISMATCH',
            ]);
    }

    /**
     * Skenario 6: Nonce yang sudah dikonsumsi ditolak dengan HTTP 403.
     */
    public function test_06_registration_fails_if_nonce_already_consumed(): void
    {
        $user = $this->createTestUser();
        Sanctum::actingAs($user);

        $deviceUuid = (string) Str::uuid();
        $keypair = $this->generateEd25519Keypair();
        $nonce = $this->issueValidNonceForUser($user, $deviceUuid, $keypair);

        // Tandai nonce sudah pernah dikonsumsi
        $nonce->update([
            'status'      => 'CONSUMED',
            'consumed_at' => now()->subMinute(),
        ]);

        $pop = $this->createValidPoP($user->id, $deviceUuid, $nonce, $keypair);

        $response = $this->postJson('/api/bchat/devices/register', [
            'nonce_uuid'    => $nonce->nonce_uuid,
            'pop_payload'   => $pop['payload_hex'],
            'pop_signature' => $pop['signature_hex'],
        ]);

        $response->assertStatus(403)
            ->assertJson([
                'status'     => 'error',
                'error_code' => 'NONCE_ALREADY_CONSUMED',
            ]);
    }

    /**
     * Skenario 7: Nonce kedaluwarsa ditolak dengan HTTP 403.
     */
    public function test_07_registration_fails_if_nonce_expired(): void
    {
        $user = $this->createTestUser();
        Sanctum::actingAs($user);

        $deviceUuid = (string) Str::uuid();
        $keypair = $this->generateEd25519Keypair();
        $nonce = $this->issueValidNonceForUser($user, $deviceUuid, $keypair);

        // Tandai nonce telah kedaluwarsa
        $nonce->update([
            'status'     => 'EXPIRED',
            'expires_at' => now()->subMinutes(10),
        ]);

        $pop = $this->createValidPoP($user->id, $deviceUuid, $nonce, $keypair);

        $response = $this->postJson('/api/bchat/devices/register', [
            'nonce_uuid'    => $nonce->nonce_uuid,
            'pop_payload'   => $pop['payload_hex'],
            'pop_signature' => $pop['signature_hex'],
        ]);

        $response->assertStatus(403)
            ->assertJson([
                'status'     => 'error',
                'error_code' => 'NONCE_EXPIRED',
            ]);
    }

    /**
     * Skenario 8: Magic header atau parameter PoP rusak ditolak dengan HTTP 422.
     */
    public function test_08_registration_fails_if_pop_magic_header_or_parameters_corrupted(): void
    {
        $user = $this->createTestUser();
        Sanctum::actingAs($user);

        $deviceUuid = (string) Str::uuid();
        $keypair = $this->generateEd25519Keypair();
        $nonce = $this->issueValidNonceForUser($user, $deviceUuid, $keypair);
        $pop = $this->createValidPoP($user->id, $deviceUuid, $nonce, $keypair);

        // Rusak karakter pertama magic header ('U' -> 'X')
        $corruptPayloadBinary = 'X' . substr($pop['payload_binary'], 1);
        $corruptPayloadHex = bin2hex($corruptPayloadBinary);
        // Tanda tangani payload rusak tersebut
        $signatureCorrupt = bin2hex(sodium_crypto_sign_detached($corruptPayloadBinary, $keypair['secret_key']));

        $response = $this->postJson('/api/bchat/devices/register', [
            'nonce_uuid'    => $nonce->nonce_uuid,
            'pop_payload'   => $corruptPayloadHex,
            'pop_signature' => $signatureCorrupt,
        ]);

        $response->assertStatus(422)
            ->assertJson([
                'status'     => 'error',
                'error_code' => 'INVALID_MAGIC_HEADER',
            ]);
    }

    /**
     * Skenario 9: Signature Ed25519 tidak valid ditolak dengan HTTP 401.
     */
    public function test_09_registration_fails_if_ed25519_signature_is_invalid(): void
    {
        $user = $this->createTestUser();
        Sanctum::actingAs($user);

        $deviceUuid = (string) Str::uuid();
        $keypair = $this->generateEd25519Keypair();
        $nonce = $this->issueValidNonceForUser($user, $deviceUuid, $keypair);
        $pop = $this->createValidPoP($user->id, $deviceUuid, $nonce, $keypair);

        // Gunakan signature 128 karakter yang salah
        $invalidSignatureHex = str_repeat('0', 128);

        $response = $this->postJson('/api/bchat/devices/register', [
            'nonce_uuid'    => $nonce->nonce_uuid,
            'pop_payload'   => $pop['payload_hex'],
            'pop_signature' => $invalidSignatureHex,
        ]);

        $response->assertStatus(401)
            ->assertJson([
                'status'     => 'error',
                'error_code' => 'SIGNATURE_VERIFICATION_FAILED',
            ]);
    }

    /**
     * Skenario 10: Public key yang sudah terdaftar pada perangkat lain ditolak dengan HTTP 409.
     */
    public function test_10_registration_fails_if_public_key_already_registered_on_another_device(): void
    {
        $userA = $this->createTestUser();
        $userB = $this->createTestUser();

        $keypairShared = $this->generateEd25519Keypair();

        // Daftarkan perangkat untuk User A dengan public key tersebut
        BchatUserDevice::create([
            'user_id'                => $userA->id,
            'device_uuid'            => (string) Str::uuid(),
            'device_name'            => 'Perangkat User A',
            'key_version'            => 1,
            'public_key'             => $keypairShared['public_key_hex'],
            'public_key_fingerprint' => $keypairShared['fingerprint'],
            'status'                 => 'ACTIVE',
        ]);

        // User B mencoba mendaftarkan perangkat menggunakan public key yang sama
        Sanctum::actingAs($userB);

        $deviceUuidB = (string) Str::uuid();
        $nonceUserB = $this->issueValidNonceForUser($userB, $deviceUuidB, $keypairShared);
        $pop = $this->createValidPoP($userB->id, $deviceUuidB, $nonceUserB, $keypairShared);

        $response = $this->postJson('/api/bchat/devices/register', [
            'nonce_uuid'    => $nonceUserB->nonce_uuid,
            'pop_payload'   => $pop['payload_hex'],
            'pop_signature' => $pop['signature_hex'],
        ]);

        $response->assertStatus(409)
            ->assertJson([
                'status'     => 'error',
                'error_code' => 'PUBLIC_KEY_ALREADY_IN_USE',
            ]);
    }

    /**
     * Skenario 11: Managed Replacement berhasil dan memverifikasi:
     * - perangkat lama REVOKED;
     * - perangkat baru ACTIVE;
     * - KeyPackage lama tidak dapat digunakan;
     * - response tidak mengekspos primary key internal.
     */
    public function test_11_managed_replacement_successfully_revokes_old_device_and_invalidates_key_packages(): void
    {
        $user = $this->createTestUser();
        Sanctum::actingAs($user);

        // 1. Buat perangkat aktif lama (Device 1)
        $oldKeypair = $this->generateEd25519Keypair();
        $oldDeviceUuid = (string) Str::uuid();
        $oldDevice = BchatUserDevice::create([
            'user_id'                => $user->id,
            'device_uuid'            => $oldDeviceUuid,
            'device_name'            => 'Old Active Device',
            'key_version'            => 1,
            'public_key'             => $oldKeypair['public_key_hex'],
            'public_key_fingerprint' => $oldKeypair['fingerprint'],
            'status'                 => 'ACTIVE',
        ]);

        // 2. Buat KeyPackage aktif milik perangkat lama
        $oldKeyPackage = $this->createTestKeyPackage($user, $oldDevice, isConsumed: false);

        // 3. Daftarkan perangkat baru (Device 2)
        $newKeypair = $this->generateEd25519Keypair();
        $newDeviceUuid = (string) Str::uuid();
        $nonce = $this->issueValidNonceForUser($user, $newDeviceUuid, $newKeypair);
        $pop = $this->createValidPoP($user->id, $newDeviceUuid, $nonce, $newKeypair);

        $response = $this->postJson('/api/bchat/devices/register', [
            'nonce_uuid'                  => $nonce->nonce_uuid,
            'pop_payload'                 => $pop['payload_hex'],
            'pop_signature'               => $pop['signature_hex'],
            'device_name'                 => 'New Replaced Device',
            'confirm_replacement'         => true,
            'expected_active_device_uuid' => $oldDeviceUuid,
        ]);

        $response->assertStatus(201)
            ->assertJson([
                'status'  => 'success',
                'message' => 'Perangkat berhasil didaftarkan.',
                'data'    => [
                    'device_uuid'    => $newDeviceUuid,
                    'device_name'    => 'New Replaced Device',
                    'key_version'    => 1,
                    'status'         => 'ACTIVE',
                    'public_key'     => $newKeypair['public_key_hex'],
                    'is_replacement' => true,
                ],
            ]);

        // Verifikasi tidak mengekspos primary key internal
        $data = $response->json('data');
        $this->assertArrayNotHasKey('device_id', $data);
        $this->assertArrayNotHasKey('revoked_device_id', $data);

        // 4. Verifikasi mutasi database:
        // A. Perangkat lama berstatus REVOKED dengan reason MANAGED_REPLACEMENT
        $oldDevice->refresh();
        $this->assertEquals('REVOKED', $oldDevice->status);
        $this->assertNotNull($oldDevice->revoked_at);
        $this->assertEquals('MANAGED_REPLACEMENT', $oldDevice->revocation_reason);

        // B. KeyPackage lama dibatalkan (is_consumed = true)
        $oldKeyPackage->refresh();
        $this->assertTrue($oldKeyPackage->is_consumed);
        $this->assertNotNull($oldKeyPackage->consumed_at);

        // C. Perangkat baru berstatus ACTIVE dan menjadi satu-satunya perangkat aktif untuk user
        $activeDevices = BchatUserDevice::where('user_id', $user->id)
            ->where('status', 'ACTIVE')
            ->get();
        $this->assertCount(1, $activeDevices);
        $this->assertEquals($newDeviceUuid, $activeDevices->first()->device_uuid);
    }
}

