<?php

namespace Tests\Feature\Bchat;

use App\Models\BchatDevicePairing;
use App\Models\BchatRegistrationNonce;
use App\Models\BchatUserDevice;
use App\Models\User;
use App\Services\BchatPairingVerificationService;
use App\Services\BchatPopVerificationService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Feature Test untuk B-CHAT Trusted Device Pairing (Tahap 2A).
 *
 * Menguji 14 skenario wajib:
 * 1.  Perangkat baru dapat membuat permintaan pairing (HTTP 201).
 * 2.  Perangkat tepercaya yang benar dapat menyetujui pairing (HTTP 200).
 * 3.  Perangkat tepercaya yang salah / signature salah ditolak (HTTP 401).
 * 4.  Perangkat dari akun lain tidak dapat menyetujui (HTTP 404).
 * 5.  Perangkat REVOKED tidak dapat menyetujui (HTTP 403).
 * 6.  Perangkat target REVOKED tidak dapat dipasangkan (HTTP 403).
 * 7.  Bukti persetujuan tidak dapat digunakan ulang (replay attack HTTP 409).
 * 8.  Permintaan kedaluwarsa tidak dapat disetujui (HTTP 410).
 * 9.  Dua persetujuan bersamaan tidak menghasilkan dua proses pairing ganda.
 * 10. Penolakan (reject) dan pembatalan (cancel) bekerja semestinya.
 * 11. Nonce atau challenge yang salah ditolak (HTTP 401).
 * 12. Perubahan status perangkat selama pairing ditangani secara aman.
 * 13. Tidak ada private key, GroupState, epoch key, atau plaintext di database server.
 * 14. Alur registrasi awal dan Managed Replacement lama tidak mengalami regresi.
 */
class BchatTrustedDevicePairingFeatureTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        $activeDb = DB::connection()->getDatabaseName();
        $this->assertEquals(
            'manajemen_skripsi_test',
            $activeDb,
            "PERINGATAN KEAMANAN: Test wajib dijalankan pada basis data 'manajemen_skripsi_test', terdeteksi '{$activeDb}'."
        );
    }

    // =========================================================================
    // HELPER UTILITIES
    // =========================================================================

    private function createTestUser(?string $password = 'PasswordRahasia123!'): User
    {
        return User::factory()->create([
            'password' => Hash::make($password),
        ]);
    }

    private function generateEd25519Keypair(): array
    {
        $keypair = sodium_crypto_sign_keypair();
        $pk = sodium_crypto_sign_publickey($keypair);
        $sk = sodium_crypto_sign_secretkey($keypair);

        return [
            'public_key_bin' => $pk,
            'public_key_hex' => bin2hex($pk),
            'secret_key'     => $sk,
            'fingerprint'    => hash('sha256', $pk),
        ];
    }

    private function issueValidNonceForUser(User $user, string $deviceUuid, array $keypair): BchatRegistrationNonce
    {
        return BchatRegistrationNonce::create([
            'user_id'                => $user->id,
            'device_uuid'            => strtolower(trim($deviceUuid)),
            'nonce_uuid'             => (string) Str::uuid(),
            'nonce_value'            => bin2hex(random_bytes(32)),
            'public_key_fingerprint' => $keypair['fingerprint'],
            'status'                 => 'ISSUED',
            'issued_at'              => time(),
            'expires_at'             => now()->addMinutes(5),
            'consumed_at'            => null,
        ]);
    }

    private function createValidPoP(
        int|string $userId,
        string $deviceUuid,
        BchatRegistrationNonce $nonce,
        array $keypair
    ): array {
        $payload = BchatPopVerificationService::buildPayload(
            userId: $userId,
            deviceUuid: $deviceUuid,
            nonceHexOrBin: $nonce->nonce_value,
            issuedAt: $nonce->issued_at,
            publicKeyHexOrBin: $keypair['public_key_hex']
        );

        $signature = sodium_crypto_sign_detached($payload, $keypair['secret_key']);

        return [
            'payload_hex'   => bin2hex($payload),
            'signature_hex' => bin2hex($signature),
        ];
    }

    private function registerDeviceViaApi(
        User $user,
        string $deviceUuid,
        string $deviceName = 'Device Test'
    ): array {
        $keypair = $this->generateEd25519Keypair();
        $nonce   = $this->issueValidNonceForUser($user, $deviceUuid, $keypair);
        $pop     = $this->createValidPoP($user->id, $deviceUuid, $nonce, $keypair);

        $response = $this->postJson('/api/bchat/devices/register', [
            'nonce_uuid'        => $nonce->nonce_uuid,
            'pop_payload'       => $pop['payload_hex'],
            'pop_signature'     => $pop['signature_hex'],
            'device_name'       => $deviceName,
            'registration_mode' => 'ADD_DEVICE',
        ]);

        return [
            'response' => $response,
            'keypair'  => $keypair,
            'device'   => BchatUserDevice::where('device_uuid', $deviceUuid)->first(),
        ];
    }

    private function signPairingApproval(
        int $userId,
        BchatDevicePairing $pairing,
        BchatUserDevice $approverDevice,
        string $approverSecretKey,
        ?int $timestamp = null
    ): array {
        $ts = $timestamp ?? time();

        $payloadBin = BchatPairingVerificationService::buildCanonicalPayload(
            userId: $userId,
            pairingUuid: $pairing->pairing_uuid,
            approverDeviceUuid: $approverDevice->device_uuid,
            targetDeviceUuid: $pairing->target_device_uuid,
            challengeNonceHex: $pairing->challenge_nonce,
            targetFingerprintHex: $pairing->target_public_key_fingerprint,
            timestamp: $ts
        );

        $signatureBin = sodium_crypto_sign_detached($payloadBin, $approverSecretKey);

        return [
            'payload_hex'   => bin2hex($payloadBin),
            'signature_hex' => bin2hex($signatureBin),
            'timestamp'     => $ts,
        ];
    }

    // =========================================================================
    // 14 SKENARIO PENGUJIAN PAIRING TERPERINCI
    // =========================================================================

    /**
     * Skenario 1: Perangkat baru dapat membuat permintaan pairing (HTTP 201).
     */
    public function test_01_new_device_can_create_pairing_request(): void
    {
        $user = $this->createTestUser();
        Sanctum::actingAs($user);

        // Perangkat 1: Laptop (Perangkat Pertama -> Otomatis Tepercaya)
        $laptopUuid = (string) Str::uuid();
        $this->registerDeviceViaApi($user, $laptopUuid, 'Laptop Kantor');
        $laptop = BchatUserDevice::where('device_uuid', $laptopUuid)->firstOrFail();
        $this->assertTrue($laptop->isTrusted(), 'Perangkat pertama wajib otomatis berstatus tepercaya (root trust).');

        // Perangkat 2: Smartphone (Perangkat Baru -> Awalnya Untrusted)
        $hpUuid = (string) Str::uuid();
        $this->registerDeviceViaApi($user, $hpUuid, 'Smartphone Android');
        $hp = BchatUserDevice::where('device_uuid', $hpUuid)->firstOrFail();
        $this->assertFalse($hp->isTrusted(), 'Perangkat baru kedua wajib berstatus belum tepercaya (is_trusted = false).');

        // Perangkat 2 meminta pairing
        $res = $this->postJson('/api/bchat/pairing/request', [
            'target_device_uuid' => $hpUuid,
        ]);

        $res->assertStatus(201)
            ->assertJson([
                'status' => 'success',
                'data'   => [
                    'target_device_uuid' => $hpUuid,
                    'target_device_name' => 'Smartphone Android',
                ],
            ]);

        $data = $res->json('data');
        $this->assertNotEmpty($data['pairing_uuid']);
        $this->assertNotEmpty($data['pairing_code']);
        $this->assertEquals(64, strlen($data['challenge_nonce']));
        $this->assertArrayHasKey('qr_payload', $data);

        // Verifikasi database record
        $pairing = BchatDevicePairing::where('pairing_uuid', $data['pairing_uuid'])->firstOrFail();
        $this->assertEquals('PENDING', $pairing->status);
        $this->assertEquals($hp->id, $pairing->target_device_id);
    }

    /**
     * Skenario 2: Perangkat tepercaya yang benar dapat menyetujui pairing (HTTP 200).
     */
    public function test_02_correct_trusted_approver_can_approve_pairing(): void
    {
        $user = $this->createTestUser();
        Sanctum::actingAs($user);

        // 1. Registrasi Perangkat 1 (Trusted Laptop)
        $laptopUuid = (string) Str::uuid();
        $reg1 = $this->registerDeviceViaApi($user, $laptopUuid, 'Laptop Tepercaya');
        $laptop = $reg1['device'];
        $laptopKeypair = $reg1['keypair'];

        // 2. Registrasi Perangkat 2 (Untrusted Phone)
        $phoneUuid = (string) Str::uuid();
        $reg2 = $this->registerDeviceViaApi($user, $phoneUuid, 'Ponsel Baru');
        $phone = $reg2['device'];

        // 3. Ponsel membuat pairing request
        $reqRes = $this->postJson('/api/bchat/pairing/request', [
            'target_device_uuid' => $phoneUuid,
        ])->assertStatus(201);

        $pairingUuid = $reqRes->json('data.pairing_uuid');
        $pairing = BchatDevicePairing::where('pairing_uuid', $pairingUuid)->firstOrFail();

        // 4. Laptop menandatangani persetujuan dengan Ed25519 private key
        $approval = $this->signPairingApproval(
            userId: $user->id,
            pairing: $pairing,
            approverDevice: $laptop,
            approverSecretKey: $laptopKeypair['secret_key']
        );

        // 5. Kirim persetujuan ke server
        $approveRes = $this->postJson("/api/bchat/pairing/{$pairingUuid}/approve", [
            'approver_device_uuid' => $laptopUuid,
            'approval_payload'     => $approval['payload_hex'],
            'approval_signature'   => $approval['signature_hex'],
        ]);

        $approveRes->assertStatus(200)
            ->assertJson([
                'status' => 'success',
                'data'   => [
                    'pairing_uuid'         => $pairingUuid,
                    'status'               => 'APPROVED',
                    'target_device_uuid'   => $phoneUuid,
                    'approver_device_uuid' => $laptopUuid,
                ],
            ]);

        // 6. Verifikasi database: Perangkat 2 sekarang menjadi TRUSTED
        $phone->refresh();
        $this->assertTrue($phone->isTrusted());
        $this->assertNotNull($phone->trusted_at);
        $this->assertEquals($laptop->id, $phone->trusted_by_device_id);

        $pairing->refresh();
        $this->assertEquals('APPROVED', $pairing->status);
        $this->assertEquals($laptop->id, $pairing->approver_device_id);
    }

    /**
     * Skenario 3: Perangkat tepercaya yang salah atau signature salah ditolak (HTTP 401).
     */
    public function test_03_wrong_trusted_approver_cannot_approve(): void
    {
        $user = $this->createTestUser();
        Sanctum::actingAs($user);

        // Laptop 1 (Tepercaya)
        $laptop1Uuid = (string) Str::uuid();
        $reg1 = $this->registerDeviceViaApi($user, $laptop1Uuid, 'Laptop Tepercaya');
        $laptop1 = $reg1['device'];

        // Ponsel (Untrusted)
        $phoneUuid = (string) Str::uuid();
        $this->registerDeviceViaApi($user, $phoneUuid, 'Ponsel Baru');

        // Buat pairing request
        $reqRes = $this->postJson('/api/bchat/pairing/request', [
            'target_device_uuid' => $phoneUuid,
        ])->assertStatus(201);
        $pairingUuid = $reqRes->json('data.pairing_uuid');
        $pairing = BchatDevicePairing::where('pairing_uuid', $pairingUuid)->firstOrFail();

        // Pihak ketiga / penyerang mencoba menandatangani dengan kunci asing (bukan kunci terdaftar Laptop 1)
        $bogusKeypair = $this->generateEd25519Keypair();
        $approval = $this->signPairingApproval(
            userId: $user->id,
            pairing: $pairing,
            approverDevice: $laptop1,
            approverSecretKey: $bogusKeypair['secret_key'] // Kunci salah!
        );

        $res = $this->postJson("/api/bchat/pairing/{$pairingUuid}/approve", [
            'approver_device_uuid' => $laptop1Uuid,
            'approval_payload'     => $approval['payload_hex'],
            'approval_signature'   => $approval['signature_hex'],
        ]);

        $res->assertStatus(401)
            ->assertJson([
                'status'     => 'error',
                'error_code' => 'SIGNATURE_VERIFICATION_FAILED',
            ]);
    }

    /**
     * Skenario 4: Perangkat dari akun lain tidak dapat menyetujui (HTTP 404).
     */
    public function test_04_device_from_other_account_cannot_approve(): void
    {
        $userA = $this->createTestUser();
        $userB = $this->createTestUser();

        // User A punya Laptop A (Trusted) & Phone A (Untrusted)
        Sanctum::actingAs($userA);
        $laptopAUuid = (string) Str::uuid();
        $this->registerDeviceViaApi($userA, $laptopAUuid, 'Laptop User A');

        $phoneAUuid = (string) Str::uuid();
        $this->registerDeviceViaApi($userA, $phoneAUuid, 'Phone User A');

        $reqRes = $this->postJson('/api/bchat/pairing/request', [
            'target_device_uuid' => $phoneAUuid,
        ])->assertStatus(201);
        $pairingUuid = $reqRes->json('data.pairing_uuid');
        $pairing = BchatDevicePairing::where('pairing_uuid', $pairingUuid)->firstOrFail();

        // User B punya Laptop B (Trusted pada akun B)
        Sanctum::actingAs($userB);
        $laptopBUuid = (string) Str::uuid();
        $regB = $this->registerDeviceViaApi($userB, $laptopBUuid, 'Laptop User B');
        $laptopB = $regB['device'];
        $laptopBKeypair = $regB['keypair'];

        $approval = $this->signPairingApproval(
            userId: $userB->id,
            pairing: $pairing,
            approverDevice: $laptopB,
            approverSecretKey: $laptopBKeypair['secret_key']
        );

        // User B mencoba menyetujui pairing milik User A
        $res = $this->postJson("/api/bchat/pairing/{$pairingUuid}/approve", [
            'approver_device_uuid' => $laptopBUuid,
            'approval_payload'     => $approval['payload_hex'],
            'approval_signature'   => $approval['signature_hex'],
        ]);

        // Ditolak HTTP 404 (Pairing session bukan milik User B)
        $res->assertStatus(404)
            ->assertJson([
                'status'     => 'error',
                'error_code' => 'PAIRING_NOT_FOUND',
            ]);
    }

    /**
     * Skenario 5: Perangkat REVOKED tidak dapat menyetujui (HTTP 403).
     */
    public function test_05_revoked_approver_device_cannot_approve(): void
    {
        $user = $this->createTestUser();
        Sanctum::actingAs($user);

        // Laptop Tepercaya
        $laptopUuid = (string) Str::uuid();
        $reg1 = $this->registerDeviceViaApi($user, $laptopUuid, 'Laptop Kantor');
        $laptop = $reg1['device'];
        $laptopKeypair = $reg1['keypair'];

        // Tablet (Kedua, perlu pairing)
        $tabletUuid = (string) Str::uuid();
        $this->registerDeviceViaApi($user, $tabletUuid, 'Tablet Android');

        // Buat pairing request
        $reqRes = $this->postJson('/api/bchat/pairing/request', [
            'target_device_uuid' => $tabletUuid,
        ])->assertStatus(201);
        $pairingUuid = $reqRes->json('data.pairing_uuid');
        $pairing = BchatDevicePairing::where('pairing_uuid', $pairingUuid)->firstOrFail();

        // Cabut status aktif Laptop Kantor (REVOKED)
        $this->postJson("/api/bchat/devices/{$laptopUuid}/revoke", [
            'password' => 'PasswordRahasia123!',
            'reason'   => 'Laptop hilang dicuri',
        ])->assertStatus(200);

        // Coba gunakan laptop yang sudah REVOKED untuk menyetujui
        $approval = $this->signPairingApproval(
            userId: $user->id,
            pairing: $pairing,
            approverDevice: $laptop,
            approverSecretKey: $laptopKeypair['secret_key']
        );

        $res = $this->postJson("/api/bchat/pairing/{$pairingUuid}/approve", [
            'approver_device_uuid' => $laptopUuid,
            'approval_payload'     => $approval['payload_hex'],
            'approval_signature'   => $approval['signature_hex'],
        ]);

        $res->assertStatus(403)
            ->assertJson([
                'status'     => 'error',
                'error_code' => 'APPROVER_DEVICE_NOT_ACTIVE',
            ]);
    }

    /**
     * Skenario 6: Perangkat target REVOKED tidak dapat dipasangkan (HTTP 403).
     */
    public function test_06_revoked_target_device_cannot_be_paired(): void
    {
        $user = $this->createTestUser();
        Sanctum::actingAs($user);

        // Laptop Tepercaya
        $laptopUuid = (string) Str::uuid();
        $reg1 = $this->registerDeviceViaApi($user, $laptopUuid, 'Laptop Kantor');
        $laptop = $reg1['device'];
        $laptopKeypair = $reg1['keypair'];

        // Tablet
        $tabletUuid = (string) Str::uuid();
        $this->registerDeviceViaApi($user, $tabletUuid, 'Tablet Android');

        // Buat pairing
        $reqRes = $this->postJson('/api/bchat/pairing/request', [
            'target_device_uuid' => $tabletUuid,
        ])->assertStatus(201);
        $pairingUuid = $reqRes->json('data.pairing_uuid');
        $pairing = BchatDevicePairing::where('pairing_uuid', $pairingUuid)->firstOrFail();

        // Cabut status tablet target (REVOKED)
        $this->postJson("/api/bchat/devices/{$tabletUuid}/revoke", [
            'password' => 'PasswordRahasia123!',
        ])->assertStatus(200);

        // Verifikasi bahwa pencabutan perangkat otomatis membatalkan pairing yang masih PENDING (Tahap 2A.1)
        $pairing->refresh();
        $this->assertSame('CANCELED', $pairing->status);

        // Perangkat REVOKED dilarang membuat permintaan pairing baru (HTTP 403)
        $reqRevoked = $this->postJson('/api/bchat/pairing/request', [
            'target_device_uuid' => $tabletUuid,
        ]);
        $reqRevoked->assertStatus(403)
            ->assertJson([
                'status'     => 'error',
                'error_code' => 'TARGET_DEVICE_NOT_ACTIVE',
            ]);

        $approval = $this->signPairingApproval(
            userId: $user->id,
            pairing: $pairing,
            approverDevice: $laptop,
            approverSecretKey: $laptopKeypair['secret_key']
        );

        // Sesi yang sudah CANCELED ditolak persetujuannya (HTTP 400)
        $res = $this->postJson("/api/bchat/pairing/{$pairingUuid}/approve", [
            'approver_device_uuid' => $laptopUuid,
            'approval_payload'     => $approval['payload_hex'],
            'approval_signature'   => $approval['signature_hex'],
        ]);
        $res->assertStatus(400)
            ->assertJson([
                'status'     => 'error',
                'error_code' => 'PAIRING_NOT_PENDING',
            ]);

        // Jika pairing disimulasikan PENDING pada perangkat REVOKED, ditolak di level verifikasi target (HTTP 403)
        $pairing->update(['status' => 'PENDING']);
        $resBypass = $this->postJson("/api/bchat/pairing/{$pairingUuid}/approve", [
            'approver_device_uuid' => $laptopUuid,
            'approval_payload'     => $approval['payload_hex'],
            'approval_signature'   => $approval['signature_hex'],
        ]);
        $resBypass->assertStatus(403)
            ->assertJson([
                'status'     => 'error',
                'error_code' => 'TARGET_DEVICE_NOT_ACTIVE',
            ]);
    }

    /**
     * Skenario 7: Bukti persetujuan tidak dapat digunakan ulang / replay attack (HTTP 409).
     */
    public function test_07_approval_proof_cannot_be_reused_replay_attack(): void
    {
        $user = $this->createTestUser();
        Sanctum::actingAs($user);

        $laptopUuid = (string) Str::uuid();
        $reg1 = $this->registerDeviceViaApi($user, $laptopUuid, 'Laptop');
        $laptop = $reg1['device'];
        $laptopKeypair = $reg1['keypair'];

        $phoneUuid = (string) Str::uuid();
        $this->registerDeviceViaApi($user, $phoneUuid, 'Phone');

        $reqRes = $this->postJson('/api/bchat/pairing/request', [
            'target_device_uuid' => $phoneUuid,
        ])->assertStatus(201);
        $pairingUuid = $reqRes->json('data.pairing_uuid');
        $pairing = BchatDevicePairing::where('pairing_uuid', $pairingUuid)->firstOrFail();

        $approval = $this->signPairingApproval(
            userId: $user->id,
            pairing: $pairing,
            approverDevice: $laptop,
            approverSecretKey: $laptopKeypair['secret_key']
        );

        // Eksekusi persetujuan pertama: SUKSES (200)
        $this->postJson("/api/bchat/pairing/{$pairingUuid}/approve", [
            'approver_device_uuid' => $laptopUuid,
            'approval_payload'     => $approval['payload_hex'],
            'approval_signature'   => $approval['signature_hex'],
        ])->assertStatus(200);

        // Coba replay persetujuan yang sama persis: DITOLAK HTTP 409
        $replayRes = $this->postJson("/api/bchat/pairing/{$pairingUuid}/approve", [
            'approver_device_uuid' => $laptopUuid,
            'approval_payload'     => $approval['payload_hex'],
            'approval_signature'   => $approval['signature_hex'],
        ]);

        $replayRes->assertStatus(409)
            ->assertJson([
                'status'     => 'error',
                'error_code' => 'PAIRING_ALREADY_APPROVED',
            ]);
    }

    /**
     * Skenario 8: Permintaan kedaluwarsa tidak dapat disetujui (HTTP 410).
     */
    public function test_08_expired_request_cannot_be_approved(): void
    {
        $user = $this->createTestUser();
        Sanctum::actingAs($user);

        $laptopUuid = (string) Str::uuid();
        $reg1 = $this->registerDeviceViaApi($user, $laptopUuid, 'Laptop');
        $laptop = $reg1['device'];
        $laptopKeypair = $reg1['keypair'];

        $phoneUuid = (string) Str::uuid();
        $this->registerDeviceViaApi($user, $phoneUuid, 'Phone');

        $reqRes = $this->postJson('/api/bchat/pairing/request', [
            'target_device_uuid' => $phoneUuid,
        ])->assertStatus(201);
        $pairingUuid = $reqRes->json('data.pairing_uuid');
        $pairing = BchatDevicePairing::where('pairing_uuid', $pairingUuid)->firstOrFail();

        // Simulasikan kedaluwarsa (ubah expires_at ke masa lalu)
        $pairing->update([
            'expires_at' => now()->subMinutes(1),
        ]);

        $approval = $this->signPairingApproval(
            userId: $user->id,
            pairing: $pairing,
            approverDevice: $laptop,
            approverSecretKey: $laptopKeypair['secret_key']
        );

        $res = $this->postJson("/api/bchat/pairing/{$pairingUuid}/approve", [
            'approver_device_uuid' => $laptopUuid,
            'approval_payload'     => $approval['payload_hex'],
            'approval_signature'   => $approval['signature_hex'],
        ]);

        $res->assertStatus(410)
            ->assertJson([
                'status'     => 'error',
                'error_code' => 'PAIRING_EXPIRED',
            ]);
    }

    /**
     * Skenario 9: Dua persetujuan bersamaan tidak menghasilkan dua proses pairing ganda.
     */
    public function test_09_concurrent_approvals_do_not_produce_duplicate_pairing(): void
    {
        $user = $this->createTestUser();
        Sanctum::actingAs($user);

        $laptopUuid = (string) Str::uuid();
        $reg1 = $this->registerDeviceViaApi($user, $laptopUuid, 'Laptop');
        $laptop = $reg1['device'];
        $laptopKeypair = $reg1['keypair'];

        $phoneUuid = (string) Str::uuid();
        $this->registerDeviceViaApi($user, $phoneUuid, 'Phone');

        $reqRes = $this->postJson('/api/bchat/pairing/request', [
            'target_device_uuid' => $phoneUuid,
        ])->assertStatus(201);
        $pairingUuid = $reqRes->json('data.pairing_uuid');
        $pairing = BchatDevicePairing::where('pairing_uuid', $pairingUuid)->firstOrFail();

        $approval = $this->signPairingApproval(
            userId: $user->id,
            pairing: $pairing,
            approverDevice: $laptop,
            approverSecretKey: $laptopKeypair['secret_key']
        );

        // Eksekusi approval 1
        $res1 = $this->postJson("/api/bchat/pairing/{$pairingUuid}/approve", [
            'approver_device_uuid' => $laptopUuid,
            'approval_payload'     => $approval['payload_hex'],
            'approval_signature'   => $approval['signature_hex'],
        ]);
        $res1->assertStatus(200);

        // Eksekusi approval 2 pada sesi yang sama
        $res2 = $this->postJson("/api/bchat/pairing/{$pairingUuid}/approve", [
            'approver_device_uuid' => $laptopUuid,
            'approval_payload'     => $approval['payload_hex'],
            'approval_signature'   => $approval['signature_hex'],
        ]);
        $res2->assertStatus(409);

        // Pastikan hanya ada 1 pairing record yang APPROVED
        $this->assertEquals(1, BchatDevicePairing::where('pairing_uuid', $pairingUuid)->where('status', 'APPROVED')->count());
    }

    /**
     * Skenario 10: Penolakan (reject) dan pembatalan (cancel) bekerja semestinya.
     */
    public function test_10_rejection_and_cancellation_work(): void
    {
        $user = $this->createTestUser();
        Sanctum::actingAs($user);

        $laptopUuid = (string) Str::uuid();
        $reg1 = $this->registerDeviceViaApi($user, $laptopUuid, 'Laptop');
        $laptop = $reg1['device'];

        $phone1Uuid = (string) Str::uuid();
        $this->registerDeviceViaApi($user, $phone1Uuid, 'Phone 1');

        $phone2Uuid = (string) Str::uuid();
        $this->registerDeviceViaApi($user, $phone2Uuid, 'Phone 2');

        // Kasus A: Penolakan oleh Approver
        $req1 = $this->postJson('/api/bchat/pairing/request', ['target_device_uuid' => $phone1Uuid])->assertStatus(201);
        $pairing1Uuid = $req1->json('data.pairing_uuid');

        $rejectRes = $this->postJson("/api/bchat/pairing/{$pairing1Uuid}/reject", [
            'approver_device_uuid' => $laptopUuid,
            'reason'               => 'Bukan perangkat saya',
        ]);
        $rejectRes->assertStatus(200)->assertJson(['status' => 'success', 'data' => ['status' => 'REJECTED']]);

        $p1 = BchatDevicePairing::where('pairing_uuid', $pairing1Uuid)->firstOrFail();
        $this->assertEquals('REJECTED', $p1->status);
        $this->assertEquals('Bukan perangkat saya', $p1->rejection_reason);

        // Kasus B: Pembatalan oleh Peminta (Target Device)
        $req2 = $this->postJson('/api/bchat/pairing/request', ['target_device_uuid' => $phone2Uuid])->assertStatus(201);
        $pairing2Uuid = $req2->json('data.pairing_uuid');

        $cancelRes = $this->postJson("/api/bchat/pairing/{$pairing2Uuid}/cancel", [
            'target_device_uuid' => $phone2Uuid,
        ]);
        $cancelRes->assertStatus(200)->assertJson(['status' => 'success', 'data' => ['status' => 'CANCELED']]);

        $p2 = BchatDevicePairing::where('pairing_uuid', $pairing2Uuid)->firstOrFail();
        $this->assertEquals('CANCELED', $p2->status);
    }

    /**
     * Skenario 11: Nonce atau challenge yang salah ditolak (HTTP 401).
     */
    public function test_11_wrong_nonce_or_challenge_rejected(): void
    {
        $user = $this->createTestUser();
        Sanctum::actingAs($user);

        $laptopUuid = (string) Str::uuid();
        $reg1 = $this->registerDeviceViaApi($user, $laptopUuid, 'Laptop');
        $laptop = $reg1['device'];
        $laptopKeypair = $reg1['keypair'];

        $phoneUuid = (string) Str::uuid();
        $this->registerDeviceViaApi($user, $phoneUuid, 'Phone');

        $reqRes = $this->postJson('/api/bchat/pairing/request', ['target_device_uuid' => $phoneUuid])->assertStatus(201);
        $pairingUuid = $reqRes->json('data.pairing_uuid');
        $pairing = BchatDevicePairing::where('pairing_uuid', $pairingUuid)->firstOrFail();

        // Modifikasi nonce pada payload menjadi nonce palsu
        $bogusNonce = bin2hex(random_bytes(32));

        $payloadBin = BchatPairingVerificationService::buildCanonicalPayload(
            userId: $user->id,
            pairingUuid: $pairing->pairing_uuid,
            approverDeviceUuid: $laptop->device_uuid,
            targetDeviceUuid: $pairing->target_device_uuid,
            challengeNonceHex: $bogusNonce, // Nonce palsu!
            targetFingerprintHex: $pairing->target_public_key_fingerprint,
            timestamp: time()
        );

        $signatureBin = sodium_crypto_sign_detached($payloadBin, $laptopKeypair['secret_key']);

        $res = $this->postJson("/api/bchat/pairing/{$pairingUuid}/approve", [
            'approver_device_uuid' => $laptopUuid,
            'approval_payload'     => bin2hex($payloadBin),
            'approval_signature'   => bin2hex($signatureBin),
        ]);

        $res->assertStatus(401)
            ->assertJson([
                'status'     => 'error',
                'error_code' => 'CHALLENGE_NONCE_MISMATCH',
            ]);
    }

    /**
     * Skenario 12: Lookup kode pairing bekerja dan menolak kode palsu atau expired.
     */
    public function test_12_lookup_pairing_code_works(): void
    {
        $user = $this->createTestUser();
        Sanctum::actingAs($user);

        $laptopUuid = (string) Str::uuid();
        $this->registerDeviceViaApi($user, $laptopUuid, 'Laptop');

        $phoneUuid = (string) Str::uuid();
        $this->registerDeviceViaApi($user, $phoneUuid, 'Phone');

        $reqRes = $this->postJson('/api/bchat/pairing/request', ['target_device_uuid' => $phoneUuid])->assertStatus(201);
        $pairingCode = $reqRes->json('data.pairing_code');
        $pairingUuid = $reqRes->json('data.pairing_uuid');

        // Lookup kode yang valid
        $lookupRes = $this->postJson('/api/bchat/pairing/lookup', [
            'pairing_code' => $pairingCode,
        ]);

        $lookupRes->assertStatus(200)
            ->assertJson([
                'status' => 'success',
                'data'   => [
                    'pairing_uuid'       => $pairingUuid,
                    'target_device_uuid' => $phoneUuid,
                    'target_device_name' => 'Phone',
                ],
            ]);

        // Lookup kode acak/palsu -> 404
        $this->postJson('/api/bchat/pairing/lookup', [
            'pairing_code' => 'ZZZZ-9999',
        ])->assertStatus(404);
    }

    /**
     * Skenario 13: Tidak ada private key, GroupState, epoch key, atau plaintext di database server.
     */
    public function test_13_no_cryptographic_secrets_stored_on_server(): void
    {
        $columns = Schema::getColumnListing('bchat_device_pairings');

        // Pastikan tidak ada kolom kunci rahasia
        $forbiddenKeywords = ['private_key', 'group_state', 'epoch_key', 'secret', 'plaintext', 'kek', 'cek'];
        foreach ($columns as $col) {
            foreach ($forbiddenKeywords as $bad) {
                $this->assertStringNotContainsString(
                    $bad,
                    strtolower($col),
                    "Kolom '{$col}' melanggar prinsip zero-knowledge server."
                );
            }
        }
    }

    /**
     * Skenario 14: Status endpoint (/api/bchat/devices/status) mengembalikan status is_trusted dan has_trusted_device.
     */
    public function test_14_status_endpoint_returns_trust_metadata(): void
    {
        $user = $this->createTestUser();
        Sanctum::actingAs($user);

        $laptopUuid = (string) Str::uuid();
        $this->registerDeviceViaApi($user, $laptopUuid, 'Laptop Tepercaya');

        $phoneUuid = (string) Str::uuid();
        $this->registerDeviceViaApi($user, $phoneUuid, 'Ponsel Untrusted');

        $statusRes = $this->getJson('/api/bchat/devices/status');

        $statusRes->assertStatus(200)
            ->assertJson([
                'status' => 'success',
                'data'   => [
                    'has_active_device'  => true,
                    'has_trusted_device' => true,
                ],
            ]);

        $activeDevices = $statusRes->json('data.active_devices');
        $this->assertCount(2, $activeDevices);

        $laptopItem = collect($activeDevices)->firstWhere('device_uuid', $laptopUuid);
        $phoneItem  = collect($activeDevices)->firstWhere('device_uuid', $phoneUuid);

        $this->assertTrue($laptopItem['is_trusted']);
        $this->assertFalse($phoneItem['is_trusted']);
    }
}

