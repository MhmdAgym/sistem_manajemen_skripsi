<?php

declare(strict_types=1);

namespace Tests\Feature\Bchat;

use App\Models\BchatRegistrationNonce;
use App\Models\BchatRoom;
use App\Models\BchatRoomMember;
use App\Models\BchatUserDevice;
use App\Models\Dosen;
use App\Models\Mahasiswa;
use App\Models\PenugasanPembimbing;
use App\Models\Skripsi;
use App\Models\User;
use App\Services\BchatPopVerificationService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Feature Test untuk B-CHAT Multi-Device Registration & Lifecycle (Tahap 1A).
 *
 * Menguji 11 kriteria utama multi-device:
 * 1.  Registrasi perangkat pertama berhasil (HTTP 201, status ACTIVE).
 * 2.  Registrasi perangkat kedua pada akun yang sama dengan ADD_DEVICE berhasil (HTTP 201).
 * 3.  Perangkat pertama tetap ACTIVE setelah perangkat kedua terdaftar (keduanya ACTIVE bersamaan).
 * 4.  Kedua perangkat memiliki device_uuid dan kunci publik yang unik/berbeda.
 * 5.  Registrasi tanpa autentikasi ditolak (HTTP 401).
 * 6.  PoP (Proof of Possession) tidak valid ditolak (HTTP 422 / 401).
 * 7.  Registration nonce kedaluwarsa atau dikonsumsi ulang (replay) ditolak (HTTP 403).
 * 8.  Revokasi mandiri satu perangkat (/api/bchat/devices/{uuid}/revoke) mencabut target,
 *     namun perangkat aktif lainnya tetap ACTIVE.
 * 9.  Endpoint status (/api/bchat/devices/status) mengembalikan daftar seluruh active_devices.
 * 10. Pembatasan jumlah perangkat aktif (configurable max_active_devices_per_user) berfungsi semestinya.
 * 11. Mode lama MANAGED_REPLACEMENT tetap kompatibel dan mencabut perangkat lama jika confirm_replacement=true.
 */
class BchatMultiDeviceRegistrationFeatureTest extends TestCase
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
    // HELPERS & FIXTURES
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

        $payload = BchatPopVerificationService::buildPayload(
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

    private function registerDeviceViaApi(
        User $user,
        string $deviceUuid,
        string $deviceName = 'Browser Test',
        string $mode = 'ADD_DEVICE',
        bool $confirmReplacement = false,
        ?string $expectedActiveUuid = null
    ): array {
        $keypair = $this->generateEd25519Keypair();
        $nonce   = $this->issueValidNonceForUser($user, $deviceUuid, $keypair);
        $pop     = $this->createValidPoP($user->id, $deviceUuid, $nonce, $keypair);

        $body = [
            'nonce_uuid'        => $nonce->nonce_uuid,
            'pop_payload'       => $pop['payload_hex'],
            'pop_signature'     => $pop['signature_hex'],
            'device_name'       => $deviceName,
            'registration_mode' => $mode,
        ];

        if ($confirmReplacement) {
            $body['confirm_replacement'] = true;
        }
        if ($expectedActiveUuid) {
            $body['expected_active_device_uuid'] = $expectedActiveUuid;
        }

        $response = $this->postJson('/api/bchat/devices/register', $body);

        return [
            'response' => $response,
            'keypair'  => $keypair,
            'nonce'    => $nonce,
        ];
    }

    private function createActiveRoomWithMembers(User $initiator, User $recipient): BchatRoom
    {
        $mhs = Mahasiswa::create([
            'user_id'        => $initiator->id,
            'NIM'            => (string) rand(1000000000, 9999999999),
            'nama_mahasiswa' => 'Mahasiswa Test ' . Str::random(4),
            'prodi'          => 'Informatika',
        ]);

        $skripsi = Skripsi::create([
            'id_mahasiswa' => $mhs->id_mahasiswa,
            'judul'        => 'Proposal E2EE Test ' . Str::random(5),
            'status_judul' => 'diajukan',
        ]);

        $dosen = Dosen::create([
            'user_id'    => $recipient->id,
            'NIDN'       => (string) rand(1000000000, 9999999999),
            'nama_dosen' => 'Dosen Test ' . Str::random(4),
            'prodi'      => 'Informatika',
        ]);

        $penugasan = PenugasanPembimbing::create([
            'skripsi_id'       => $skripsi->id_skripsi,
            'tahap'            => 'proposal',
            'pembimbing_ke'    => 1,
            'dosen_id'         => $dosen->id_dosen,
            'status_penugasan' => 'AKTIF',
            'tanggal_mulai'    => now()->toDateString(),
        ]);

        $room = BchatRoom::create([
            'room_uuid'               => (string) Str::uuid(),
            'penugasan_pembimbing_id' => $penugasan->id,
            'tahap'                   => 'proposal',
            'pembimbing_ke'           => 1,
            'current_sequence'        => 0,
            'status'                  => 'ACTIVE',
            'current_epoch'           => 1,
            'group_id_hash'           => hash('sha256', Str::random(16)),
        ]);

        BchatRoomMember::create([
            'room_id'         => $room->id,
            'user_id'         => $initiator->id,
            'role'            => 'mahasiswa',
            'is_initiator'    => true,
            'is_active'       => true,
            'joined_at_epoch' => 1,
        ]);

        BchatRoomMember::create([
            'room_id'         => $room->id,
            'user_id'         => $recipient->id,
            'role'            => 'dosen_pembimbing',
            'is_initiator'    => false,
            'is_active'       => true,
            'joined_at_epoch' => 1,
        ]);

        return $room;
    }

    // =========================================================================
    // SKENARIO TEST MULTI-DEVICE
    // =========================================================================

    /**
     * Skenario 1: Registrasi perangkat pertama berhasil dengan HTTP 201 dan status ACTIVE.
     */
    public function test_01_first_device_registration_succeeds(): void
    {
        $user = $this->createTestUser();
        Sanctum::actingAs($user);

        $deviceUuid = (string) Str::uuid();
        $result = $this->registerDeviceViaApi($user, $deviceUuid, 'Laptop Mahasiswa', 'ADD_DEVICE');

        $result['response']->assertStatus(201)
            ->assertJson([
                'status' => 'success',
                'data'   => [
                    'device_uuid' => $deviceUuid,
                    'status'      => 'ACTIVE',
                ],
            ]);

        $this->assertDatabaseHas('bchat_user_devices', [
            'user_id'     => $user->id,
            'device_uuid' => $deviceUuid,
            'status'      => 'ACTIVE',
        ]);
    }

    /**
     * Skenario 2 & 3: Registrasi perangkat kedua pada akun yang sama dengan mode ADD_DEVICE
     * berhasil, dan perangkat pertama TETAP ACTIVE (Multi-device concurrent active).
     */
    public function test_02_and_03_second_device_registers_and_first_device_remains_active(): void
    {
        $user = $this->createTestUser();
        Sanctum::actingAs($user);

        // Perangkat 1: Laptop
        $device1Uuid = (string) Str::uuid();
        $res1 = $this->registerDeviceViaApi($user, $device1Uuid, 'Laptop Utama', 'ADD_DEVICE');
        $res1['response']->assertStatus(201);

        // Perangkat 2: Smartphone
        $device2Uuid = (string) Str::uuid();
        $res2 = $this->registerDeviceViaApi($user, $device2Uuid, 'Smartphone Android', 'ADD_DEVICE');
        $res2['response']->assertStatus(201)
            ->assertJson([
                'status' => 'success',
                'data'   => [
                    'device_uuid' => $device2Uuid,
                    'status'      => 'ACTIVE',
                ],
            ]);

        // Verifikasi di DB: KEDUA perangkat berstatus ACTIVE
        $activeDevices = BchatUserDevice::where('user_id', $user->id)
            ->where('status', 'ACTIVE')
            ->orderBy('id', 'asc')
            ->get();

        $this->assertCount(2, $activeDevices);
        $this->assertEquals($device1Uuid, $activeDevices[0]->device_uuid);
        $this->assertEquals($device2Uuid, $activeDevices[1]->device_uuid);
        $this->assertEquals('ACTIVE', $activeDevices[0]->status);
        $this->assertEquals('ACTIVE', $activeDevices[1]->status);
    }

    /**
     * Skenario 4: Kedua perangkat memiliki device_uuid unik dan public_key unik.
     */
    public function test_04_devices_have_distinct_uuids_and_keys(): void
    {
        $user = $this->createTestUser();
        Sanctum::actingAs($user);

        $device1Uuid = (string) Str::uuid();
        $device2Uuid = (string) Str::uuid();

        $res1 = $this->registerDeviceViaApi($user, $device1Uuid, 'Device 1', 'ADD_DEVICE');
        $res2 = $this->registerDeviceViaApi($user, $device2Uuid, 'Device 2', 'ADD_DEVICE');

        $res1['response']->assertStatus(201);
        $res2['response']->assertStatus(201);

        $d1 = BchatUserDevice::where('device_uuid', $device1Uuid)->firstOrFail();
        $d2 = BchatUserDevice::where('device_uuid', $device2Uuid)->firstOrFail();

        $this->assertNotEquals($d1->device_uuid, $d2->device_uuid);
        $this->assertNotEquals($d1->public_key, $d2->public_key);
        $this->assertNotEquals($d1->public_key_fingerprint, $d2->public_key_fingerprint);
    }

    /**
     * Skenario 5: Pendaftaran perangkat tanpa autentikasi ditolak (HTTP 401).
     */
    public function test_05_unauthenticated_registration_rejected(): void
    {
        $user = $this->createTestUser();
        $deviceUuid = (string) Str::uuid();
        $keypair = $this->generateEd25519Keypair();
        $nonce = $this->issueValidNonceForUser($user, $deviceUuid, $keypair);
        $pop = $this->createValidPoP($user->id, $deviceUuid, $nonce, $keypair);

        // Jangan set Sanctum::actingAs
        $response = $this->postJson('/api/bchat/devices/register', [
            'nonce_uuid'        => $nonce->nonce_uuid,
            'pop_payload'       => $pop['payload_hex'],
            'pop_signature'     => $pop['signature_hex'],
            'device_name'       => 'Hacker Device',
            'registration_mode' => 'ADD_DEVICE',
        ]);

        $response->assertStatus(401);
    }

    /**
     * Skenario 6: PoP dengan signature palsu ditolak (HTTP 401).
     */
    public function test_06_invalid_pop_rejected(): void
    {
        $user = $this->createTestUser();
        Sanctum::actingAs($user);

        $deviceUuid = (string) Str::uuid();
        $keypair = $this->generateEd25519Keypair();
        $nonce = $this->issueValidNonceForUser($user, $deviceUuid, $keypair);
        $pop = $this->createValidPoP($user->id, $deviceUuid, $nonce, $keypair);

        // Rusak signature
        $corruptedSig = str_repeat('0', 128);

        $response = $this->postJson('/api/bchat/devices/register', [
            'nonce_uuid'        => $nonce->nonce_uuid,
            'pop_payload'       => $pop['payload_hex'],
            'pop_signature'     => $corruptedSig,
            'device_name'       => 'Device Corrupt PoP',
            'registration_mode' => 'ADD_DEVICE',
        ]);

        $response->assertStatus(401);
    }

    /**
     * Skenario 7: Nonce kedaluwarsa atau dikonsumsi dua kali ditolak (HTTP 403 / 422).
     */
    public function test_07_expired_or_reused_nonce_rejected(): void
    {
        $user = $this->createTestUser();
        Sanctum::actingAs($user);

        // Kasus 1: Replay nonce yang sudah consumed
        $deviceUuid1 = (string) Str::uuid();
        $res1 = $this->registerDeviceViaApi($user, $deviceUuid1, 'Dev 1', 'ADD_DEVICE');
        $res1['response']->assertStatus(201);

        // Coba pakai nonce yang sama lagi untuk registrasi perangkat lain
        $deviceUuid2 = (string) Str::uuid();
        $pop2 = $this->createValidPoP($user->id, $deviceUuid2, $res1['nonce'], $res1['keypair']);

        $responseReplay = $this->postJson('/api/bchat/devices/register', [
            'nonce_uuid'        => $res1['nonce']->nonce_uuid,
            'pop_payload'       => $pop2['payload_hex'],
            'pop_signature'     => $pop2['signature_hex'],
            'device_name'       => 'Replay Device',
            'registration_mode' => 'ADD_DEVICE',
        ]);

        $responseReplay->assertStatus(403);

        // Kasus 2: Nonce expired
        $deviceUuid3 = (string) Str::uuid();
        $keypair3 = $this->generateEd25519Keypair();
        $expiredNonce = BchatRegistrationNonce::create([
            'user_id'                => $user->id,
            'device_uuid'            => $deviceUuid3,
            'nonce_uuid'             => (string) Str::uuid(),
            'nonce_value'            => bin2hex(random_bytes(32)),
            'public_key_fingerprint' => $keypair3['fingerprint'],
            'status'                 => 'EXPIRED',
            'issued_at'              => time() - 600,
            'expires_at'             => now()->subMinutes(5),
            'consumed_at'            => null,
        ]);

        $pop3 = $this->createValidPoP($user->id, $deviceUuid3, $expiredNonce, $keypair3, time() - 600);
        $responseExpired = $this->postJson('/api/bchat/devices/register', [
            'nonce_uuid'        => $expiredNonce->nonce_uuid,
            'pop_payload'       => $pop3['payload_hex'],
            'pop_signature'     => $pop3['signature_hex'],
            'device_name'       => 'Expired Device',
            'registration_mode' => 'ADD_DEVICE',
        ]);

        $responseExpired->assertStatus(403);
    }

    /**
     * Skenario 8: Revokasi satu perangkat via endpoint /api/bchat/devices/{uuid}/revoke
     * mencabut perangkat tersebut tanpa mencabut perangkat aktif lainnya.
     */
    public function test_08_revoke_device_only_revokes_target_device(): void
    {
        $user = $this->createTestUser();
        Sanctum::actingAs($user);

        $device1Uuid = (string) Str::uuid();
        $device2Uuid = (string) Str::uuid();

        $this->registerDeviceViaApi($user, $device1Uuid, 'Perangkat 1', 'ADD_DEVICE');
        $this->registerDeviceViaApi($user, $device2Uuid, 'Perangkat 2', 'ADD_DEVICE');

        // Pastikan keduanya ACTIVE
        $this->assertEquals(2, BchatUserDevice::where('user_id', $user->id)->where('status', 'ACTIVE')->count());

        // Revoke perangkat 1 dengan password akun
        $revokeRes = $this->postJson("/api/bchat/devices/{$device1Uuid}/revoke", [
            'password' => 'PasswordRahasia123!',
            'reason'   => 'Perangkat hilang',
        ]);

        $revokeRes->assertStatus(200)
            ->assertJson([
                'status' => 'success',
                'data'   => [
                    'device_uuid' => $device1Uuid,
                    'status'      => 'REVOKED',
                ],
            ]);

        // Verifikasi DB: Perangkat 1 REVOKED, Perangkat 2 tetap ACTIVE
        $d1 = BchatUserDevice::where('device_uuid', $device1Uuid)->firstOrFail();
        $d2 = BchatUserDevice::where('device_uuid', $device2Uuid)->firstOrFail();

        $this->assertEquals('REVOKED', $d1->status);
        $this->assertNotNull($d1->revoked_at);

        $this->assertEquals('ACTIVE', $d2->status);
        $this->assertNull($d2->revoked_at);
    }

    /**
     * Skenario 08a: Pencabutan tanpa password atau step-up token ditolak (HTTP 403 STEP_UP_REQUIRED).
     */
    public function test_08a_revoke_without_password_rejected(): void
    {
        $user = $this->createTestUser();
        Sanctum::actingAs($user);

        $deviceUuid = (string) Str::uuid();
        $this->registerDeviceViaApi($user, $deviceUuid, 'Laptop Test', 'ADD_DEVICE');

        $res = $this->postJson("/api/bchat/devices/{$deviceUuid}/revoke", [
            'reason' => 'Perangkat dicabut',
        ]);

        $res->assertStatus(403)
            ->assertJson([
                'status'     => 'error',
                'error_code' => 'STEP_UP_REQUIRED',
            ]);
    }

    /**
     * Skenario 08b: Pencabutan dengan password salah ditolak (HTTP 401 INVALID_CREDENTIALS).
     */
    public function test_08b_revoke_with_wrong_password_rejected(): void
    {
        $user = $this->createTestUser();
        Sanctum::actingAs($user);

        $deviceUuid = (string) Str::uuid();
        $this->registerDeviceViaApi($user, $deviceUuid, 'Laptop Test', 'ADD_DEVICE');

        $res = $this->postJson("/api/bchat/devices/{$deviceUuid}/revoke", [
            'password' => 'PasswordSalahTotal999!',
            'reason'   => 'Perangkat dicabut',
        ]);

        $res->assertStatus(401)
            ->assertJson([
                'status'     => 'error',
                'error_code' => 'INVALID_CREDENTIALS',
            ]);
    }

    /**
     * Skenario 08c: Pencabutan perangkat milik akun lain ditolak (HTTP 404 ACTIVE_DEVICE_NOT_FOUND).
     */
    public function test_08c_revoke_other_user_device_rejected(): void
    {
        $userA = $this->createTestUser('PasswordA123!');
        $userB = $this->createTestUser('PasswordB123!');

        Sanctum::actingAs($userB);
        $deviceBUuid = (string) Str::uuid();
        $this->registerDeviceViaApi($userB, $deviceBUuid, 'Laptop User B', 'ADD_DEVICE');

        // Login sebagai User A dan coba cabut perangkat User B
        Sanctum::actingAs($userA);
        $res = $this->postJson("/api/bchat/devices/{$deviceBUuid}/revoke", [
            'password' => 'PasswordA123!',
            'reason'   => 'Mencoba membajak',
        ]);

        $res->assertStatus(404)
            ->assertJson([
                'status'     => 'error',
                'error_code' => 'ACTIVE_DEVICE_NOT_FOUND',
            ]);
    }

    /**
     * Skenario 08d: Perangkat REVOKED langsung diblokir dari pengiriman pesan (HTTP 403 DEVICE_NOT_ACTIVE).
     */
    public function test_08d_revoked_device_blocked_from_sending_messages(): void
    {
        $mhs = $this->createTestUser();
        $dosen = $this->createTestUser();

        $room = $this->createActiveRoomWithMembers($mhs, $dosen);

        Sanctum::actingAs($mhs);
        $deviceUuid = (string) Str::uuid();
        $this->registerDeviceViaApi($mhs, $deviceUuid, 'Laptop Mahasiswa', 'ADD_DEVICE');

        // Cabut perangkat
        $this->postJson("/api/bchat/devices/{$deviceUuid}/revoke", [
            'password' => 'PasswordRahasia123!',
        ])->assertStatus(200);

        // Coba kirim pesan dengan header perangkat yang sudah REVOKED
        $rawCiphertext = 'dummy_ciphertext_payload';
        $payloadHash = hash('sha256', $rawCiphertext);

        $res = $this->withHeader('X-Bchat-Device-Uuid', $deviceUuid)
            ->postJson("/api/bchat/rooms/{$room->room_uuid}/messages", [
                'message_uuid'          => (string) Str::uuid(),
                'epoch'                 => 1,
                'generation'            => 0,
                'content_type'          => 1,
                'ciphertext_payload'    => base64_encode($rawCiphertext),
                'payload_hash_sha256'   => $payloadHash,
                'client_sent_at'        => now()->toIso8601String(),
            ]);

        $res->assertStatus(403)
            ->assertJson([
                'status'     => 'error',
                'error_code' => 'DEVICE_NOT_ACTIVE',
            ]);
    }

    /**
     * Skenario 08e: Perangkat REVOKED langsung diblokir dari upload attachment (HTTP 403 DEVICE_NOT_ACTIVE).
     */
    public function test_08e_revoked_device_blocked_from_uploading_attachment(): void
    {
        $mhs = $this->createTestUser();
        $dosen = $this->createTestUser();

        $room = $this->createActiveRoomWithMembers($mhs, $dosen);

        Sanctum::actingAs($mhs);
        $deviceUuid = (string) Str::uuid();
        $this->registerDeviceViaApi($mhs, $deviceUuid, 'Laptop Mahasiswa', 'ADD_DEVICE');

        // Cabut perangkat
        $this->postJson("/api/bchat/devices/{$deviceUuid}/revoke", [
            'password' => 'PasswordRahasia123!',
        ])->assertStatus(200);

        // Coba inisiasi upload session dengan header perangkat REVOKED
        $res = $this->withHeader('X-Bchat-Device-Uuid', $deviceUuid)
            ->postJson("/api/bchat/rooms/{$room->room_uuid}/attachments/upload-session", [
                'attachment_uuid'  => (string) Str::uuid(),
                'total_chunks'     => 1,
                'ciphertext_bytes' => 1028,
                'plaintext_bytes'  => 1000,
            ]);

        $res->assertStatus(403)
            ->assertJson([
                'status'     => 'error',
                'error_code' => 'DEVICE_NOT_ACTIVE',
            ]);
    }

    /**
     * Skenario 08f: Pengguna tanpa perangkat aktif ditolak dari otorisasi private Reverb broadcast channel.
     */
    public function test_08f_user_with_only_revoked_devices_denied_from_broadcast_channel(): void
    {
        $mhs = $this->createTestUser();
        $dosen = $this->createTestUser();

        $room = $this->createActiveRoomWithMembers($mhs, $dosen);

        Sanctum::actingAs($mhs);
        $deviceUuid = (string) Str::uuid();
        $this->registerDeviceViaApi($mhs, $deviceUuid, 'Laptop Mahasiswa', 'ADD_DEVICE');

        // Cabut perangkat
        $this->postJson("/api/bchat/devices/{$deviceUuid}/revoke", [
            'password' => 'PasswordRahasia123!',
        ])->assertStatus(200);

        config(['broadcasting.default' => 'reverb']);

        // Coba otorisasi private broadcast channel
        $res = $this->postJson('/broadcasting/auth', [
            'socket_id'    => '1234.5678',
            'channel_name' => "private-bchat.rooms.{$room->room_uuid}",
        ]);

        $res->assertStatus(403);
    }

    /**
     * Skenario 9: Endpoint status /api/bchat/devices/status mengembalikan daftar seluruh active_devices.
     */
    public function test_09_status_endpoint_returns_active_devices_list(): void
    {
        $user = $this->createTestUser();
        Sanctum::actingAs($user);

        $device1Uuid = (string) Str::uuid();
        $device2Uuid = (string) Str::uuid();

        $this->registerDeviceViaApi($user, $device1Uuid, 'Laptop Kantor', 'ADD_DEVICE');
        $this->registerDeviceViaApi($user, $device2Uuid, 'Tablet Android', 'ADD_DEVICE');

        $statusRes = $this->getJson('/api/bchat/devices/status');

        $statusRes->assertStatus(200)
            ->assertJson([
                'status' => 'success',
                'data'   => [
                    'has_active_device' => true,
                ],
            ]);

        $data = $statusRes->json('data');
        $this->assertArrayHasKey('active_devices', $data);
        $this->assertCount(2, $data['active_devices']);

        $uuids = array_column($data['active_devices'], 'device_uuid');
        $this->assertContains($device1Uuid, $uuids);
        $this->assertContains($device2Uuid, $uuids);

        // Cabut perangkat 1, lalu periksa ulang active_devices dan revoked_devices
        $this->postJson("/api/bchat/devices/{$device1Uuid}/revoke", [
            'password' => 'PasswordRahasia123!',
        ])->assertStatus(200);

        $statusResAfter = $this->getJson('/api/bchat/devices/status');
        $dataAfter = $statusResAfter->json('data');

        $this->assertArrayHasKey('active_devices', $dataAfter);
        $this->assertArrayHasKey('revoked_devices', $dataAfter);
        $this->assertCount(1, $dataAfter['active_devices']);
        $this->assertCount(1, $dataAfter['revoked_devices']);
        $this->assertEquals($device2Uuid, $dataAfter['active_devices'][0]['device_uuid']);
        $this->assertEquals($device1Uuid, $dataAfter['revoked_devices'][0]['device_uuid']);
    }

    /**
     * Skenario 10: Pembatasan maksimum perangkat aktif yang dapat dikonfigurasi
     * (config bchat.max_active_devices_per_user).
     */
    public function test_10_max_active_devices_limit_enforced(): void
    {
        $user = $this->createTestUser();
        Sanctum::actingAs($user);

        // Set konfigurasi max devices = 2
        Config::set('bchat.max_active_devices_per_user', 2);

        $device1Uuid = (string) Str::uuid();
        $device2Uuid = (string) Str::uuid();
        $device3Uuid = (string) Str::uuid();

        $res1 = $this->registerDeviceViaApi($user, $device1Uuid, 'Perangkat 1', 'ADD_DEVICE');
        $res1['response']->assertStatus(201);

        $res2 = $this->registerDeviceViaApi($user, $device2Uuid, 'Perangkat 2', 'ADD_DEVICE');
        $res2['response']->assertStatus(201);

        // Perangkat 3 harus ditolak karena limit 2 telah tercapai
        $res3 = $this->registerDeviceViaApi($user, $device3Uuid, 'Perangkat 3 Melebihi Kuota', 'ADD_DEVICE');
        $res3['response']->assertStatus(422)
            ->assertJson([
                'status'     => 'error',
                'error_code' => 'MAX_ACTIVE_DEVICES_REACHED',
            ]);

        // Pastikan hanya 2 perangkat yang ACTIVE di DB
        $this->assertEquals(2, BchatUserDevice::where('user_id', $user->id)->where('status', 'ACTIVE')->count());
    }

    /**
     * Skenario 10a: Verifikasi pembacaan konfigurasi environment variable BCHAT_ dan fallback BCAT_.
     */
    public function test_10a_config_environment_variable_precedence_and_fallback(): void
    {
        // 1. Uji pemuatan file config langsung
        $configPath = config_path('bchat.php');
        $this->assertFileExists($configPath);

        // 2. Simulasikan BCHAT_ disetel
        putenv('BCHAT_MAX_ACTIVE_DEVICES_PER_USER=5');
        putenv('BCAT_MAX_ACTIVE_DEVICES_PER_USER=3');
        $configWithBoth = require $configPath;
        $this->assertEquals('5', (string) $configWithBoth['max_active_devices_per_user']);

        // 3. Simulasikan hanya fallback BCAT_ disetel
        putenv('BCHAT_MAX_ACTIVE_DEVICES_PER_USER'); // unset
        $configFallback = require $configPath;
        $this->assertEquals('3', (string) $configFallback['max_active_devices_per_user']);

        // 4. Simulasikan keduanya null / tidak disetel
        putenv('BCAT_MAX_ACTIVE_DEVICES_PER_USER'); // unset
        $configNull = require $configPath;
        $this->assertNull($configNull['max_active_devices_per_user']);
    }

    /**
     * Skenario 10b: Konfigurasi null tetap mengizinkan perangkat aktif tanpa batas.
     */
    public function test_10b_null_config_allows_unlimited_active_devices(): void
    {
        $user = $this->createTestUser();
        Sanctum::actingAs($user);

        // Set null (unlimited)
        Config::set('bchat.max_active_devices_per_user', null);

        // Daftarkan 4 perangkat berbeda
        for ($i = 1; $i <= 4; $i++) {
            $uuid = (string) Str::uuid();
            $res = $this->registerDeviceViaApi($user, $uuid, "Perangkat {$i}", 'ADD_DEVICE');
            $res['response']->assertStatus(201);
        }

        $activeCount = BchatUserDevice::where('user_id', $user->id)->where('status', 'ACTIVE')->count();
        $this->assertEquals(4, $activeCount);
    }

    /**
     * Skenario 10c: Kuota penuh TIDAK menghalangi rotasi kunci perangkat yang sama secara sah.
     */
    public function test_10c_full_quota_allows_legitimate_key_rotation_for_same_device(): void
    {
        $user = $this->createTestUser();
        Sanctum::actingAs($user);

        // Kuota disetel = 2
        Config::set('bchat.max_active_devices_per_user', 2);

        $device1Uuid = (string) Str::uuid();
        $device2Uuid = (string) Str::uuid();

        // Daftarkan perangkat 1 dan 2 (kuota menjadi penuh = 2)
        $res1 = $this->registerDeviceViaApi($user, $device1Uuid, 'Perangkat 1', 'ADD_DEVICE');
        $res1['response']->assertStatus(201);

        $res2 = $this->registerDeviceViaApi($user, $device2Uuid, 'Perangkat 2 Versi 1', 'ADD_DEVICE');
        $res2['response']->assertStatus(201);

        $this->assertEquals(2, BchatUserDevice::where('user_id', $user->id)->where('status', 'ACTIVE')->count());

        // Lakukan rotasi kunci pada Perangkat 2 (UUID sama, keypair baru)
        $rotateRes = $this->registerDeviceViaApi($user, $device2Uuid, 'Perangkat 2 Versi 2 Rotasi Kunci', 'ADD_DEVICE');
        $rotateRes['response']->assertStatus(201)
            ->assertJson([
                'status' => 'success',
                'data'   => [
                    'device_uuid' => $device2Uuid,
                    'key_version' => 2,
                    'status'      => 'ACTIVE',
                ],
            ]);

        // Verifikasi bahwa total perangkat aktif tetap tepat 2 (tidak bocor kuota)
        $this->assertEquals(2, BchatUserDevice::where('user_id', $user->id)->where('status', 'ACTIVE')->count());

        // Periksa bahwa versi 1 dari perangkat 2 berstatus REVOKED dan versi 2 berstatus ACTIVE
        $versions = BchatUserDevice::where('user_id', $user->id)
            ->where('device_uuid', $device2Uuid)
            ->orderBy('key_version', 'asc')
            ->get();

        $this->assertCount(2, $versions);
        $this->assertEquals(1, $versions[0]->key_version);
        $this->assertEquals('REVOKED', $versions[0]->status);
        $this->assertEquals(2, $versions[1]->key_version);
        $this->assertEquals('ACTIVE', $versions[1]->status);
    }

    /**
     * Skenario 11: Mode lama MANAGED_REPLACEMENT tetap berfungsi dengan normal
     * (backward compatibility dengan confirm_replacement = true).
     */
    public function test_11_legacy_managed_replacement_flow_works(): void
    {
        $user = $this->createTestUser();
        Sanctum::actingAs($user);

        // Daftarkan perangkat lama
        $oldDeviceUuid = (string) Str::uuid();
        $resOld = $this->registerDeviceViaApi($user, $oldDeviceUuid, 'Laptop Lama', 'ADD_DEVICE');
        $resOld['response']->assertStatus(201);

        // Daftarkan perangkat baru dengan mode MANAGED_REPLACEMENT + confirm_replacement
        $newDeviceUuid = (string) Str::uuid();
        $resNew = $this->registerDeviceViaApi(
            user: $user,
            deviceUuid: $newDeviceUuid,
            deviceName: 'Laptop Pengganti',
            mode: 'MANAGED_REPLACEMENT',
            confirmReplacement: true,
            expectedActiveUuid: $oldDeviceUuid
        );

        $resNew['response']->assertStatus(201)
            ->assertJson([
                'status' => 'success',
                'data'   => [
                    'device_uuid'    => $newDeviceUuid,
                    'status'         => 'ACTIVE',
                    'is_replacement' => true,
                ],
            ]);

        // Periksa status di DB
        $oldDevice = BchatUserDevice::where('device_uuid', $oldDeviceUuid)->firstOrFail();
        $newDevice = BchatUserDevice::where('device_uuid', $newDeviceUuid)->firstOrFail();

        $this->assertEquals('REVOKED', $oldDevice->status);
        $this->assertNotNull($oldDevice->revoked_at);

        $this->assertEquals('ACTIVE', $newDevice->status);
        $this->assertNull($newDevice->revoked_at);
    }

    /**
     * Skenario 12: Perangkat yang telah dicabut (REVOKED) tidak dapat mendaftar ulang
     * melalui jalur pendaftaran biasa (pencegahan resusitasi perangkat terkompromi).
     */
    public function test_12_revoked_device_cannot_reregister(): void
    {
        $user = $this->createTestUser();
        Sanctum::actingAs($user);

        // 1. Daftarkan perangkat awal
        $deviceUuid = (string) Str::uuid();
        $regRes = $this->registerDeviceViaApi($user, $deviceUuid, 'Smartphone Asli', 'ADD_DEVICE');
        $regRes['response']->assertStatus(201);

        // 2. Cabut perangkat tersebut dengan password
        $revokeRes = $this->postJson("/api/bchat/devices/{$deviceUuid}/revoke", [
            'password' => 'PasswordRahasia123!',
            'reason'   => 'Perangkat hilang dicuri',
        ]);
        $revokeRes->assertStatus(200);

        // Pastikan status di DB adalah REVOKED dan tidak ada yang ACTIVE
        $this->assertEquals(0, BchatUserDevice::where('user_id', $user->id)->where('status', 'ACTIVE')->count());
        $this->assertEquals(1, BchatUserDevice::where('user_id', $user->id)->where('device_uuid', $deviceUuid)->where('status', 'REVOKED')->count());

        // 3. Coba daftarkan kembali perangkat dengan device_uuid yang sama
        $reregisterRes = $this->registerDeviceViaApi($user, $deviceUuid, 'Smartphone Resusitasi', 'ADD_DEVICE');

        // Harus ditolak HTTP 403 dengan kode error REVOKED_DEVICE_CANNOT_REREGISTER
        $reregisterRes['response']->assertStatus(403)
            ->assertJson([
                'status'     => 'error',
                'error_code' => 'REVOKED_DEVICE_CANNOT_REREGISTER',
            ]);

        // Pastikan DB tetap bersih dan perangkat tidak bangkit menjadi ACTIVE
        $this->assertEquals(0, BchatUserDevice::where('user_id', $user->id)->where('status', 'ACTIVE')->count());
    }
}
