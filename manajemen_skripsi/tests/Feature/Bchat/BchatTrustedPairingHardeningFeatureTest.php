<?php

declare(strict_types=1);

namespace Tests\Feature\Bchat;

use App\Models\BchatAttachment;
use App\Models\BchatDevicePairing;
use App\Models\BchatKeyPackage;
use App\Models\BchatRegistrationNonce;
use App\Models\BchatRoom;
use App\Models\BchatRoomMember;
use App\Models\BchatUploadSession;
use App\Models\BchatUserDevice;
use App\Models\Dosen;
use App\Models\Mahasiswa;
use App\Models\PenugasanPembimbing;
use App\Models\Skripsi;
use App\Models\User;
use App\Services\BchatPairingVerificationService;
use App\Services\BchatPopVerificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Pengujian Terarah Tahap 2A.1: Trusted Pairing Security Hardening.
 */
class BchatTrustedPairingHardeningFeatureTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $activeDb = DB::connection()->getDatabaseName();
        $this->assertSame(
            'manajemen_skripsi_test',
            $activeDb,
            "PERINGATAN KEAMANAN: Test wajib dijalankan pada basis data 'manajemen_skripsi_test', terdeteksi '{$activeDb}'."
        );

        config(['broadcasting.default' => 'reverb']);
        require base_path('routes/channels.php');
    }

    // =========================================================================
    // HELPER FUNCTIONS
    // =========================================================================

    private function createTestUser(string $role = 'mahasiswa', string $password = 'PasswordRahasia123!'): User
    {
        return User::factory()->create([
            'role'        => $role,
            'password'    => Hash::make($password),
            'nomor_induk' => (string) rand(1000000000, 9999999999),
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
        string $deviceName = 'Device Test',
        string $mode = 'ADD_DEVICE',
        bool $confirmReplacement = false,
        ?string $expectedActiveUuid = null
    ): array {
        $keypair = $this->generateEd25519Keypair();
        $nonce   = $this->issueValidNonceForUser($user, $deviceUuid, $keypair);
        $pop     = $this->createValidPoP($user->id, $deviceUuid, $nonce, $keypair);

        $postData = [
            'nonce_uuid'        => $nonce->nonce_uuid,
            'pop_payload'       => $pop['payload_hex'],
            'pop_signature'     => $pop['signature_hex'],
            'device_name'       => $deviceName,
            'registration_mode' => $mode,
        ];

        if ($confirmReplacement) {
            $postData['confirm_replacement'] = true;
            $postData['expected_active_device_uuid'] = $expectedActiveUuid;
        }

        $response = $this->actingAs($user, 'sanctum')->postJson('/api/bchat/devices/register', $postData);

        return [
            'response' => $response,
            'keypair'  => $keypair,
            'device'   => BchatUserDevice::where('device_uuid', $deviceUuid)->first(),
        ];
    }

    private function createPairingRoomWithMembers(User $mhs, User $dosen): BchatRoom
    {
        $mhsModel = Mahasiswa::create([
            'user_id'         => $mhs->id,
            'NIM'             => $mhs->nomor_induk,
            'nama_mahasiswa'  => 'Mahasiswa Test',
            'prodi'           => 'Informatika',
            'status_akademik' => 'aktif',
        ]);

        $skripsi = Skripsi::create([
            'id_mahasiswa' => $mhsModel->id_mahasiswa,
            'judul'        => 'Proposal Hardening Test',
            'status_judul' => 'diajukan',
        ]);

        $dosenModel = Dosen::create([
            'user_id'    => $dosen->id,
            'NIDN'       => $dosen->nomor_induk,
            'nama_dosen' => 'Dosen Test',
            'prodi'      => 'Informatika',
        ]);

        $penugasan = PenugasanPembimbing::create([
            'skripsi_id'       => $skripsi->id_skripsi,
            'tahap'            => 'proposal',
            'pembimbing_ke'    => 1,
            'dosen_id'         => $dosenModel->id_dosen,
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
            'group_id_hash'           => hash('sha256', 'group_context_hardening_' . Str::random(6)),
        ]);

        BchatRoomMember::create([
            'room_id'         => $room->id,
            'user_id'         => $mhs->id,
            'role'            => 'mahasiswa',
            'is_initiator'    => true,
            'is_active'       => true,
            'joined_at_epoch' => 1,
        ]);

        BchatRoomMember::create([
            'room_id'         => $room->id,
            'user_id'         => $dosen->id,
            'role'            => 'dosen_pembimbing',
            'is_initiator'    => false,
            'is_active'       => true,
            'joined_at_epoch' => 1,
        ]);

        return $room;
    }

    private function signPairingApproval(
        int $userId,
        BchatDevicePairing $pairing,
        BchatUserDevice $approverDevice,
        string $approverSecretKey
    ): array {
        $serverTime = time();

        $payloadBinary = BchatPairingVerificationService::buildCanonicalPayload(
            userId: $userId,
            pairingUuid: $pairing->pairing_uuid,
            approverDeviceUuid: $approverDevice->device_uuid,
            targetDeviceUuid: $pairing->target_device_uuid,
            challengeNonceHex: $pairing->challenge_nonce,
            targetFingerprintHex: $pairing->target_public_key_fingerprint,
            timestamp: $serverTime
        );

        $signatureBinary = sodium_crypto_sign_detached($payloadBinary, $approverSecretKey);

        return [
            'payload_hex'   => bin2hex($payloadBinary),
            'signature_hex' => bin2hex($signatureBinary),
        ];
    }

    private function authenticateBroadcastingWithSignedChallenge(
        User $user,
        string $deviceUuid,
        array $keypair,
        string $channelName,
        string $socketId = '1234.5678'
    ): \Illuminate\Testing\TestResponse {
        $challengeRes = $this->actingAs($user, 'sanctum')->postJson('/api/bchat/broadcasting/challenge', [
            'device_uuid'  => $deviceUuid,
            'socket_id'    => $socketId,
            'channel_name' => $channelName,
        ]);

        if ($challengeRes->status() !== 201) {
            return $this->actingAs($user, 'sanctum')
                ->withHeader('X-Bchat-Device-Uuid', $deviceUuid)
                ->postJson('/broadcasting/auth', [
                    'channel_name' => $channelName,
                    'socket_id'    => $socketId,
                ]);
        }

        $challengeData = $challengeRes->json('data');
        $payloadBinary = hex2bin($challengeData['payload_hex']);
        $signatureBinary = sodium_crypto_sign_detached($payloadBinary, $keypair['secret_key']);

        return $this->actingAs($user, 'sanctum')
            ->withHeaders([
                'X-Bchat-Device-Uuid'    => $deviceUuid,
                'X-Bchat-Challenge-Uuid' => $challengeData['challenge_uuid'],
                'X-Bchat-Signature'      => bin2hex($signatureBinary),
            ])
            ->postJson('/broadcasting/auth', [
                'channel_name' => $channelName,
                'socket_id'    => $socketId,
            ]);
    }

    // =========================================================================
    // SECTION A: BOOTSTRAP TRUST ROOT & PAIRING STATE
    // =========================================================================

    public function test_01_bootstrap_trust_root_only_for_first_device_and_additional_device_is_untrusted(): void
    {
        $user = $this->createTestUser('mahasiswa');

        // Perangkat 1: First device registration (bootstrap trust root)
        $laptopUuid = (string) Str::uuid();
        $reg1 = $this->registerDeviceViaApi($user, $laptopUuid, 'Laptop Pertama', 'MANAGED_REPLACEMENT');
        $reg1['response']->assertStatus(201);

        $this->assertTrue($reg1['device']->is_trusted);
        $this->assertNotNull($reg1['device']->trusted_at);
        $this->assertTrue($reg1['response']->json('data.is_trusted'));

        // Perangkat 2: Additional device registration (ADD_DEVICE) -> wajib untrusted
        $phoneUuid = (string) Str::uuid();
        $reg2 = $this->registerDeviceViaApi($user, $phoneUuid, 'HP Tambahan', 'ADD_DEVICE');
        $reg2['response']->assertStatus(201);

        $this->assertFalse($reg2['device']->is_trusted);
        $this->assertNull($reg2['device']->trusted_at);
        $this->assertFalse($reg2['response']->json('data.is_trusted'));

        // Pastikan status perangkat aktif mencerminkan status tepercaya masing-masing
        $statusRes = $this->actingAs($user, 'sanctum')->getJson('/api/bchat/devices/status')->assertStatus(200);
        $devices = collect($statusRes->json('data.active_devices'));

        $laptopData = $devices->firstWhere('device_uuid', $laptopUuid);
        $phoneData = $devices->firstWhere('device_uuid', $phoneUuid);

        $this->assertTrue($laptopData['is_trusted']);
        $this->assertFalse($phoneData['is_trusted']);
    }

    public function test_02_managed_replacement_inherits_trust_status_and_revoked_device_cannot_reregister(): void
    {
        $user = $this->createTestUser('dosen');

        // Daftarkan perangkat 1 (menjadi trusted)
        $oldUuid = (string) Str::uuid();
        $reg1 = $this->registerDeviceViaApi($user, $oldUuid, 'PC Lama', 'MANAGED_REPLACEMENT');
        $reg1['response']->assertStatus(201);
        $this->assertTrue($reg1['device']->is_trusted);

        // Managed replacement ke perangkat baru
        $newUuid = (string) Str::uuid();
        $regReplace = $this->registerDeviceViaApi(
            user: $user,
            deviceUuid: $newUuid,
            deviceName: 'PC Baru Pengganti',
            mode: 'MANAGED_REPLACEMENT',
            confirmReplacement: true,
            expectedActiveUuid: $oldUuid
        );
        $regReplace['response']->assertStatus(201);

        $this->assertFalse($regReplace['response']->json('data.is_trusted'));
        $this->assertFalse($regReplace['device']->is_trusted);
        $this->assertTrue($regReplace['response']->json('data.is_replacement'));

        // Perangkat lama berstatus REVOKED
        $oldDevice = BchatUserDevice::where('device_uuid', $oldUuid)->firstOrFail();
        $this->assertSame('REVOKED', $oldDevice->status);

        // Coba daftarkan kembali perangkat lama yang sudah REVOKED -> Ditolak (HTTP 403)
        $regOldAgain = $this->registerDeviceViaApi($user, $oldUuid, 'Mencoba Daftarkan Revoked', 'ADD_DEVICE');
        $regOldAgain['response']->assertStatus(403)
            ->assertJson([
                'status'     => 'error',
                'error_code' => 'REVOKED_DEVICE_CANNOT_REREGISTER',
            ]);
    }

    // =========================================================================
    // SECTION B: ENFORCE TRUST STATUS ON BACKEND (UNTRUSTED DEVICE BLOCKED)
    // =========================================================================

    public function test_03_untrusted_device_blocked_from_sending_and_reading_messages(): void
    {
        $mhs = $this->createTestUser('mahasiswa');
        $dosen = $this->createTestUser('dosen');
        $room = $this->createPairingRoomWithMembers($mhs, $dosen);

        // Mhs punya laptop (trusted) dan hp (untrusted)
        $laptopUuid = (string) Str::uuid();
        $this->registerDeviceViaApi($mhs, $laptopUuid, 'Laptop Mahasiswa');

        $phoneUuid = (string) Str::uuid();
        $regPhone = $this->registerDeviceViaApi($mhs, $phoneUuid, 'HP Mahasiswa', 'ADD_DEVICE');

        $messagePayload = [
            'message_uuid'        => (string) Str::uuid(),
            'epoch'               => 1,
            'generation'          => 0,
            'content_type'        => 1,
            'ciphertext_payload'  => base64_encode('secret ciphertext'),
            'payload_hash_sha256' => hash('sha256', 'secret ciphertext'),
            'client_sent_at'      => now()->toIso8601String(),
        ];

        // 1. HP Mahasiswa (untrusted) mengirim pesan -> 403 DEVICE_NOT_TRUSTED
        $sendRes = $this->actingAs($mhs, 'sanctum')
            ->withHeader('X-Bchat-Device-Uuid', $phoneUuid)
            ->postJson("/api/bchat/rooms/{$room->room_uuid}/messages", $messagePayload);

        $sendRes->assertStatus(403)
            ->assertJson([
                'status'     => 'error',
                'error_code' => 'DEVICE_NOT_TRUSTED',
            ]);

        // 2. HP Mahasiswa (untrusted) membaca pesan -> 403 DEVICE_NOT_TRUSTED
        $readRes = $this->actingAs($mhs, 'sanctum')
            ->withHeader('X-Bchat-Device-Uuid', $phoneUuid)
            ->getJson("/api/bchat/rooms/{$room->room_uuid}/messages");

        $readRes->assertStatus(403)
            ->assertJson([
                'status'     => 'error',
                'error_code' => 'DEVICE_NOT_TRUSTED',
            ]);

        // 3. Laptop Mahasiswa (trusted) berhasil mengirim pesan -> 201 Created
        $sendTrustedRes = $this->actingAs($mhs, 'sanctum')
            ->withHeader('X-Bchat-Device-Uuid', $laptopUuid)
            ->postJson("/api/bchat/rooms/{$room->room_uuid}/messages", $messagePayload);

        $sendTrustedRes->assertStatus(201);

        // 4. Laptop Mahasiswa (trusted) berhasil membaca pesan -> 200 OK
        $readTrustedRes = $this->actingAs($mhs, 'sanctum')
            ->withHeader('X-Bchat-Device-Uuid', $laptopUuid)
            ->getJson("/api/bchat/rooms/{$room->room_uuid}/messages");

        $readTrustedRes->assertStatus(200);
    }

    public function test_04_untrusted_device_blocked_from_attachment_operations(): void
    {
        $mhs = $this->createTestUser('mahasiswa');
        $dosen = $this->createTestUser('dosen');
        $room = $this->createPairingRoomWithMembers($mhs, $dosen);

        $laptopUuid = (string) Str::uuid();
        $this->registerDeviceViaApi($mhs, $laptopUuid, 'Laptop Mahasiswa');

        $phoneUuid = (string) Str::uuid();
        $regPhone = $this->registerDeviceViaApi($mhs, $phoneUuid, 'HP Mahasiswa', 'ADD_DEVICE');

        $sessionPayload = [
            'attachment_uuid'   => (string) Str::uuid(),
            'total_chunks'      => 1,
            'ciphertext_bytes'  => 1028,
            'plaintext_bytes'   => 1000,
            'file_hash_sha256'  => str_repeat('a', 64),
        ];

        // HP (untrusted) buat sesi upload attachment -> 403 DEVICE_NOT_TRUSTED
        $untrustedSession = $this->actingAs($mhs, 'sanctum')
            ->withHeader('X-Bchat-Device-Uuid', $phoneUuid)
            ->postJson("/api/bchat/rooms/{$room->room_uuid}/attachments/upload-session", $sessionPayload);

        $untrustedSession->assertStatus(403)
            ->assertJson([
                'status'     => 'error',
                'error_code' => 'DEVICE_NOT_TRUSTED',
            ]);

        // Laptop (trusted) buat sesi upload attachment -> 201 Created
        $trustedSession = $this->actingAs($mhs, 'sanctum')
            ->withHeader('X-Bchat-Device-Uuid', $laptopUuid)
            ->postJson("/api/bchat/rooms/{$room->room_uuid}/attachments/upload-session", $sessionPayload);

        $trustedSession->assertStatus(201);
    }

    public function test_05_untrusted_device_blocked_from_key_packages_deposit_and_partner_fetch(): void
    {
        $mhs = $this->createTestUser('mahasiswa');
        $dosen = $this->createTestUser('dosen');
        $room = $this->createPairingRoomWithMembers($mhs, $dosen);
        $room->update(['status' => 'PENDING_WELCOME_ACK', 'current_epoch' => 0]);

        // Dosen punya PC (trusted) dan Tablet (untrusted)
        $pcUuid = (string) Str::uuid();
        $this->registerDeviceViaApi($dosen, $pcUuid, 'PC Dosen');

        $tabletUuid = (string) Str::uuid();
        $this->registerDeviceViaApi($dosen, $tabletUuid, 'Tablet Dosen', 'ADD_DEVICE');

        $pkgData = 'dummy_binary_kp_32_bytes_pad___';
        $pkgBase64 = base64_encode($pkgData);
        $pkgHash = hash('sha256', $pkgData);

        // 1. Tablet Dosen (untrusted) coba upload KeyPackage -> 403 DEVICE_NOT_TRUSTED
        $uploadUntrusted = $this->actingAs($dosen, 'sanctum')->postJson('/api/bchat/key-packages', [
            'device_uuid' => $tabletUuid,
            'packages'    => [
                [
                    'key_package_hash' => $pkgHash,
                    'key_package_data' => $pkgBase64,
                ],
            ],
        ]);

        $uploadUntrusted->assertStatus(403)
            ->assertJson([
                'status'     => 'error',
                'error_code' => 'DEVICE_NOT_TRUSTED',
            ]);

        // 2. PC Dosen (trusted) sukses upload KeyPackage -> 201 Created
        $uploadTrusted = $this->actingAs($dosen, 'sanctum')->postJson('/api/bchat/key-packages', [
            'device_uuid' => $pcUuid,
            'packages'    => [
                [
                    'key_package_hash' => $pkgHash,
                    'key_package_data' => $pkgBase64,
                ],
            ],
        ]);

        $uploadTrusted->assertStatus(201);

        // 3. Mhs punya laptop (trusted) dan HP (untrusted)
        $mhsLaptopUuid = (string) Str::uuid();
        $this->registerDeviceViaApi($mhs, $mhsLaptopUuid, 'Laptop Mahasiswa');

        $mhsPhoneUuid = (string) Str::uuid();
        $this->registerDeviceViaApi($mhs, $mhsPhoneUuid, 'HP Mahasiswa', 'ADD_DEVICE');

        // HP Mhs (untrusted) coba fetch partner key package -> 403 DEVICE_NOT_TRUSTED
        $fetchUntrusted = $this->actingAs($mhs, 'sanctum')
            ->withHeader('X-Bchat-Device-Uuid', $mhsPhoneUuid)
            ->getJson("/api/bchat/rooms/{$room->room_uuid}/partner-key-package");

        $fetchUntrusted->assertStatus(403)
            ->assertJson([
                'status'     => 'error',
                'error_code' => 'DEVICE_NOT_TRUSTED',
            ]);

        // Laptop Mhs (trusted) sukses fetch partner key package -> 200 OK
        $fetchTrusted = $this->actingAs($mhs, 'sanctum')
            ->withHeader('X-Bchat-Device-Uuid', $mhsLaptopUuid)
            ->getJson("/api/bchat/rooms/{$room->room_uuid}/partner-key-package");

        $fetchTrusted->assertStatus(200);
    }

    public function test_06_untrusted_device_blocked_from_websocket_private_channel(): void
    {
        $mhs = $this->createTestUser('mahasiswa');
        $dosen = $this->createTestUser('dosen');
        $room = $this->createPairingRoomWithMembers($mhs, $dosen);

        $mhsLaptopUuid = (string) Str::uuid();
        $laptop = $this->registerDeviceViaApi($mhs, $mhsLaptopUuid, 'Laptop Mahasiswa');

        $mhsPhoneUuid = (string) Str::uuid();
        $phone = $this->registerDeviceViaApi($mhs, $mhsPhoneUuid, 'HP Mahasiswa', 'ADD_DEVICE');

        // Autentikasi channel privat Reverb untuk HP Mahasiswa (untrusted) -> 403 Forbidden
        $resUntrusted = $this->authenticateBroadcastingWithSignedChallenge(
            user: $mhs,
            deviceUuid: $mhsPhoneUuid,
            keypair: $phone['keypair'],
            channelName: "private-bchat.rooms.{$room->room_uuid}",
            socketId: '1234.5678'
        );

        $resUntrusted->assertStatus(403);

        // Autentikasi channel privat Reverb untuk Laptop Mahasiswa (trusted) -> 200 OK
        $resTrusted = $this->authenticateBroadcastingWithSignedChallenge(
            user: $mhs,
            deviceUuid: $mhsLaptopUuid,
            keypair: $laptop['keypair'],
            channelName: "private-bchat.rooms.{$room->room_uuid}",
            socketId: '1234.5678'
        );

        $resTrusted->assertStatus(200);
    }

    // =========================================================================
    // SECTION C: NON-E2EE ENDPOINTS REMAIN ACCESSIBLE FOR UNTRUSTED DEVICES
    // =========================================================================

    public function test_07_untrusted_target_device_can_access_pairing_endpoints_and_cancel(): void
    {
        $user = $this->createTestUser('mahasiswa');

        $laptopUuid = (string) Str::uuid();
        $this->registerDeviceViaApi($user, $laptopUuid, 'Laptop Tepercaya');

        $phoneUuid = (string) Str::uuid();
        $this->registerDeviceViaApi($user, $phoneUuid, 'HP Baru Belum Tepercaya', 'ADD_DEVICE');

        // Untrusted device bisa cek status perangkat
        $statusRes = $this->actingAs($user, 'sanctum')
            ->withHeader('X-Bchat-Device-Uuid', $phoneUuid)
            ->getJson('/api/bchat/devices/status')
            ->assertStatus(200);

        $phoneData = collect($statusRes->json('data.active_devices'))->firstWhere('device_uuid', $phoneUuid);
        $this->assertFalse($phoneData['is_trusted']);

        // Untrusted device bisa membuat pairing request
        $reqRes = $this->actingAs($user, 'sanctum')
            ->withHeader('X-Bchat-Device-Uuid', $phoneUuid)
            ->postJson('/api/bchat/pairing/request', [
                'target_device_uuid' => $phoneUuid,
            ])->assertStatus(201);

        $pairingUuid = $reqRes->json('data.pairing_uuid');

        // Untrusted device bisa melakukan polling status pairing
        $pollRes = $this->actingAs($user, 'sanctum')
            ->withHeader('X-Bchat-Device-Uuid', $phoneUuid)
            ->getJson("/api/bchat/pairing/{$pairingUuid}")
            ->assertStatus(200);

        $this->assertSame('PENDING', $pollRes->json('data.status'));

        // Untrusted device bisa membatalkan pairing request
        $cancelRes = $this->actingAs($user, 'sanctum')
            ->withHeader('X-Bchat-Device-Uuid', $phoneUuid)
            ->postJson("/api/bchat/pairing/{$pairingUuid}/cancel", [
                'target_device_uuid' => $phoneUuid,
            ])->assertStatus(200);

        $this->assertSame('CANCELED', $cancelRes->json('data.status'));
    }

    // =========================================================================
    // SECTION D: LIFECYCLE & REVOCATION CONSISTENCY
    // =========================================================================

    public function test_08_revoking_approver_device_automatically_cancels_pending_pairings(): void
    {
        $user = $this->createTestUser('mahasiswa');

        $laptopUuid = (string) Str::uuid();
        $this->registerDeviceViaApi($user, $laptopUuid, 'Laptop Approver');

        $phoneUuid = (string) Str::uuid();
        $this->registerDeviceViaApi($user, $phoneUuid, 'HP Target', 'ADD_DEVICE');

        // Buat pairing request
        $reqRes = $this->actingAs($user, 'sanctum')->postJson('/api/bchat/pairing/request', [
            'target_device_uuid' => $phoneUuid,
        ])->assertStatus(201);

        $pairingUuid = $reqRes->json('data.pairing_uuid');
        $pairing = BchatDevicePairing::where('pairing_uuid', $pairingUuid)->firstOrFail();
        $this->assertSame('PENDING', $pairing->status);

        // Cabut perangkat laptop approver
        $this->actingAs($user, 'sanctum')->postJson("/api/bchat/devices/{$laptopUuid}/revoke", [
            'password' => 'PasswordRahasia123!',
        ])->assertStatus(200);

        // Pairing PENDING otomatis menjadi CANCELED
        $pairing->refresh();
        $this->assertSame('CANCELED', $pairing->status);
        $this->assertStringContainsString('REVOKED', (string) $pairing->rejection_reason);
    }

    public function test_09_paired_device_can_perform_e2ee_operations_after_approval(): void
    {
        $mhs = $this->createTestUser('mahasiswa');
        $dosen = $this->createTestUser('dosen');
        $room = $this->createPairingRoomWithMembers($mhs, $dosen);

        // Mahasiswa punya Laptop (trusted) dan HP (untrusted)
        $laptopUuid = (string) Str::uuid();
        $regLaptop = $this->registerDeviceViaApi($mhs, $laptopUuid, 'Laptop Mahasiswa');

        $phoneUuid = (string) Str::uuid();
        $regPhone = $this->registerDeviceViaApi($mhs, $phoneUuid, 'HP Mahasiswa', 'ADD_DEVICE');

        // Buat pairing request untuk HP
        $reqRes = $this->actingAs($mhs, 'sanctum')->postJson('/api/bchat/pairing/request', [
            'target_device_uuid' => $phoneUuid,
        ])->assertStatus(201);

        $pairingUuid = $reqRes->json('data.pairing_uuid');
        $pairing = BchatDevicePairing::where('pairing_uuid', $pairingUuid)->firstOrFail();

        // Approve pairing dari Laptop
        $approval = $this->signPairingApproval(
            userId: $mhs->id,
            pairing: $pairing,
            approverDevice: $regLaptop['device'],
            approverSecretKey: $regLaptop['keypair']['secret_key']
        );

        $approveRes = $this->actingAs($mhs, 'sanctum')->postJson("/api/bchat/pairing/{$pairingUuid}/approve", [
            'approver_device_uuid' => $laptopUuid,
            'approval_payload'     => $approval['payload_hex'],
            'approval_signature'   => $approval['signature_hex'],
        ])->assertStatus(200);

        $this->assertSame('APPROVED', $approveRes->json('data.status'));

        // HP sekarang sudah TRUSTED
        $phoneDevice = BchatUserDevice::where('device_uuid', $phoneUuid)->firstOrFail();
        $this->assertTrue($phoneDevice->is_trusted);

        // HP sekarang BISA mengirim pesan E2EE
        $messagePayload = [
            'message_uuid'        => (string) Str::uuid(),
            'epoch'               => 1,
            'generation'          => 0,
            'content_type'        => 1,
            'ciphertext_payload'  => base64_encode('pesan dari hp setelah pairing'),
            'payload_hash_sha256' => hash('sha256', 'pesan dari hp setelah pairing'),
            'client_sent_at'      => now()->toIso8601String(),
        ];

        $sendRes = $this->actingAs($mhs, 'sanctum')
            ->withHeader('X-Bchat-Device-Uuid', $phoneUuid)
            ->postJson("/api/bchat/rooms/{$room->room_uuid}/messages", $messagePayload);

        $sendRes->assertStatus(201);

        // HP sekarang BISA membaca riwayat pesan
        $readRes = $this->actingAs($mhs, 'sanctum')
            ->withHeader('X-Bchat-Device-Uuid', $phoneUuid)
            ->getJson("/api/bchat/rooms/{$room->room_uuid}/messages");

        $readRes->assertStatus(200);

        // HP sekarang BISA mengotorisasi channel broadcast WebSocket privat
        $authRes = $this->authenticateBroadcastingWithSignedChallenge(
            user: $mhs,
            deviceUuid: $phoneUuid,
            keypair: $regPhone['keypair'],
            channelName: "private-bchat.rooms.{$room->room_uuid}",
            socketId: '9999.8888'
        );

        $authRes->assertStatus(200);
    }

    // =========================================================================
    // SECTION E: PEMERIKSAAN TERARAH TAHAP 2A.1 (TTL, LEGACY, & REVERB BINDING)
    // =========================================================================

    /**
     * Pemeriksaan 1: Konsistensi masa berlaku pairing (300s / 5m) dan boundary checks.
     */
    public function test_10_pairing_ttl_is_consistently_300_seconds_and_boundary_checks(): void
    {
        $user = $this->createTestUser('mahasiswa');

        $laptopUuid = (string) Str::uuid();
        $regLaptop = $this->registerDeviceViaApi($user, $laptopUuid, 'Laptop Tepercaya');

        $phoneUuid = (string) Str::uuid();
        $this->registerDeviceViaApi($user, $phoneUuid, 'HP Target', 'ADD_DEVICE');

        // 1. Pembuatan pairing request: expires_in_seconds harus tepat 300 detik
        $reqRes = $this->actingAs($user, 'sanctum')->postJson('/api/bchat/pairing/request', [
            'target_device_uuid' => $phoneUuid,
        ])->assertStatus(201);

        $this->assertSame(300, $reqRes->json('data.expires_in_seconds'));
        $pairingUuid = $reqRes->json('data.pairing_uuid');

        $pairing = BchatDevicePairing::where('pairing_uuid', $pairingUuid)->firstOrFail();
        $expectedExpiresAt = $pairing->created_at->addSeconds(300);
        $this->assertEqualsWithDelta(
            $expectedExpiresAt->timestamp,
            $pairing->expires_at->timestamp,
            2,
            'expires_at harus tepat 300 detik dari waktu pembuatan'
        );

        // 2. Status polling mengembalikan expires_in_seconds dinamis
        $statusRes = $this->actingAs($user, 'sanctum')->getJson("/api/bchat/pairing/{$pairingUuid}/status");
        $statusRes->assertStatus(200);
        $this->assertLessThanOrEqual(300, $statusRes->json('data.expires_in_seconds'));
        $this->assertGreaterThanOrEqual(295, $statusRes->json('data.expires_in_seconds'));

        // 3. Boundary check: Tepat 2 detik sebelum kedaluwarsa -> approve sukses (HTTP 200)
        $pairing->update(['expires_at' => now()->addSeconds(2)]);

        $approval = $this->signPairingApproval(
            userId: $user->id,
            pairing: $pairing,
            approverDevice: $regLaptop['device'],
            approverSecretKey: $regLaptop['keypair']['secret_key']
        );

        $approveNearExpiry = $this->actingAs($user, 'sanctum')->postJson("/api/bchat/pairing/{$pairingUuid}/approve", [
            'approver_device_uuid' => $laptopUuid,
            'approval_payload'     => $approval['payload_hex'],
            'approval_signature'   => $approval['signature_hex'],
        ]);
        $approveNearExpiry->assertStatus(200);
        $this->assertSame('APPROVED', $approveNearExpiry->json('data.status'));

        // 4. Boundary check: Sesi kedua tepat 1 detik setelah kedaluwarsa -> tolak dengan HTTP 410 PAIRING_EXPIRED
        $tabletUuid = (string) Str::uuid();
        $this->registerDeviceViaApi($user, $tabletUuid, 'Tablet Target', 'ADD_DEVICE');

        $req2 = $this->actingAs($user, 'sanctum')->postJson('/api/bchat/pairing/request', [
            'target_device_uuid' => $tabletUuid,
        ])->assertStatus(201);
        $pairing2Uuid = $req2->json('data.pairing_uuid');
        $pairing2 = BchatDevicePairing::where('pairing_uuid', $pairing2Uuid)->firstOrFail();

        // Manipulasi expires_at menjadi 1 detik di masa lalu
        $pairing2->update(['expires_at' => now()->subSecond()]);

        $approval2 = $this->signPairingApproval(
            userId: $user->id,
            pairing: $pairing2,
            approverDevice: $regLaptop['device'],
            approverSecretKey: $regLaptop['keypair']['secret_key']
        );

        // A. Coba approve yang sudah kedaluwarsa -> 410
        $approveExpired = $this->actingAs($user, 'sanctum')->postJson("/api/bchat/pairing/{$pairing2Uuid}/approve", [
            'approver_device_uuid' => $laptopUuid,
            'approval_payload'     => $approval2['payload_hex'],
            'approval_signature'   => $approval2['signature_hex'],
        ]);
        $approveExpired->assertStatus(410)
            ->assertJson(['status' => 'error', 'error_code' => 'PAIRING_EXPIRED']);

        // Status di database otomatis menjadi EXPIRED
        $pairing2->refresh();
        $this->assertSame('EXPIRED', $pairing2->status);

        // B. Coba reject yang sudah kedaluwarsa -> 410
        $pairing3 = BchatDevicePairing::create([
            'user_id'                       => $user->id,
            'pairing_uuid'                  => (string) Str::uuid(),
            'pairing_code'                  => 'PAIR-TEST',
            'target_device_id'              => $pairing2->target_device_id,
            'target_device_uuid'            => $tabletUuid,
            'target_public_key_fingerprint' => hash('sha256', 'dummy'),
            'challenge_nonce'               => bin2hex(random_bytes(32)),
            'status'                        => 'PENDING',
            'expires_at'                    => now()->subSeconds(2),
        ]);

        $rejectExpired = $this->actingAs($user, 'sanctum')->postJson("/api/bchat/pairing/{$pairing3->pairing_uuid}/reject", [
            'approver_device_uuid' => $laptopUuid,
            'reason'               => 'Menolak sesi yang kedaluwarsa',
        ]);
        $rejectExpired->assertStatus(410)
            ->assertJson(['status' => 'error', 'error_code' => 'PAIRING_EXPIRED']);

        // C. Coba cancel yang sudah kedaluwarsa -> 410
        $cancelExpired = $this->actingAs($user, 'sanctum')->postJson("/api/bchat/pairing/{$pairing3->pairing_uuid}/cancel", [
            'target_device_uuid' => $tabletUuid,
        ]);
        $cancelExpired->assertStatus(410)
            ->assertJson(['status' => 'error', 'error_code' => 'PAIRING_EXPIRED']);
    }

    /**
     * Pemeriksaan 2: Keamanan legacy trust root fail-closed & pencegahan perolehan trust otomatis.
     */
    public function test_11_legacy_trust_root_fail_closed_and_new_devices_never_gain_trust_automatically(): void
    {
        $user = $this->createTestUser('dosen');

        // Simulasi kondisi pre-migrasi: pengguna memiliki 2 perangkat aktif
        // Perangkat 1: Dibuat lebih awal (ID lebih kecil)
        $legacyDevice1 = BchatUserDevice::create([
            'user_id'                => $user->id,
            'device_uuid'            => (string) Str::uuid(),
            'device_name'            => 'PC Dosen Lama (Tertua)',
            'key_version'            => 1,
            'public_key'             => bin2hex(random_bytes(32)),
            'public_key_fingerprint' => hash('sha256', 'legacy1'),
            'status'                 => 'ACTIVE',
            'is_trusted'             => false,
            'created_at'             => now()->subDays(10),
            'last_active_at'         => now()->subDays(1),
        ]);

        // Perangkat 2: Dibuat setelahnya (ID lebih besar)
        $legacyDevice2 = BchatUserDevice::create([
            'user_id'                => $user->id,
            'device_uuid'            => (string) Str::uuid(),
            'device_name'            => 'Laptop Dosen Lama (Kedua)',
            'key_version'            => 1,
            'public_key'             => bin2hex(random_bytes(32)),
            'public_key_fingerprint' => hash('sha256', 'legacy2'),
            'status'                 => 'ACTIVE',
            'is_trusted'             => false,
            'created_at'             => now()->subDays(5),
            'last_active_at'         => now()->subHours(2),
        ]);

        // Jalankan logika migrasi fail-closed (hanya 1 perangkat tertua per user yang di-upgrade)
        $legacyActiveDeviceIds = DB::table('bchat_user_devices')
            ->where('status', 'ACTIVE')
            ->where('user_id', $user->id)
            ->orderBy('id', 'asc')
            ->get(['id', 'user_id'])
            ->groupBy('user_id')
            ->map(fn ($group) => $group->first()->id)
            ->values()
            ->all();

        DB::table('bchat_user_devices')
            ->whereIn('id', $legacyActiveDeviceIds)
            ->update([
                'is_trusted' => true,
                'trusted_at' => now(),
            ]);

        $legacyDevice1->refresh();
        $legacyDevice2->refresh();

        // Verifikasi fail-closed: HANYA perangkat 1 yang menjadi trusted
        $this->assertTrue($legacyDevice1->is_trusted, 'Perangkat tertua sah sebagai legacy trust root');
        $this->assertFalse($legacyDevice2->is_trusted, 'Perangkat kedua tetap untrusted (fail-closed)');

        // Pendaftaran perangkat baru via ADD_DEVICE: TIDAK BISA mendapatkan trust otomatis
        $newPhoneUuid = (string) Str::uuid();
        $regPhone = $this->registerDeviceViaApi($user, $newPhoneUuid, 'HP Baru Dosen', 'ADD_DEVICE');
        $this->assertFalse($regPhone['device']->is_trusted, 'Perangkat baru via ADD_DEVICE mutlak untrusted');

        // Managed replacement terhadap untrusted device ($legacyDevice2) -> penggantinya TETAP untrusted
        $replacementPhoneUuid = (string) Str::uuid();
        $regReplacement = $this->registerDeviceViaApi(
            user: $user,
            deviceUuid: $replacementPhoneUuid,
            deviceName: 'HP Pengganti Untrusted',
            mode: 'MANAGED_REPLACEMENT',
            confirmReplacement: true,
            expectedActiveUuid: $legacyDevice2->device_uuid
        );
        $this->assertFalse($regReplacement['device']->is_trusted, 'Pengganti untrusted device tetap untrusted');

        // Managed replacement terhadap trusted device ($legacyDevice1) -> penggantinya tetap untrusted (wajib alur pairing)
        $replacementPcUuid = (string) Str::uuid();
        $regPcReplacement = $this->registerDeviceViaApi(
            user: $user,
            deviceUuid: $replacementPcUuid,
            deviceName: 'PC Pengganti Trusted',
            mode: 'MANAGED_REPLACEMENT',
            confirmReplacement: true,
            expectedActiveUuid: $legacyDevice1->device_uuid
        );
        $this->assertFalse($regPcReplacement['device']->is_trusted, 'Pengganti tetap is_trusted = false (wajib pairing)');
    }

    /**
     * Pemeriksaan 3: Pengikatan otorisasi Reverb ke perangkat aktual (Strict Matrix).
     */
    public function test_12_reverb_auth_strict_device_binding_matrix(): void
    {
        $mhs = $this->createTestUser('mahasiswa');
        $dosen = $this->createTestUser('dosen');
        $room = $this->createPairingRoomWithMembers($mhs, $dosen);

        $laptopUuid = (string) Str::uuid();
        $laptop = $this->registerDeviceViaApi($mhs, $laptopUuid, 'Laptop Mahasiswa (Trusted)');

        $phoneUuid = (string) Str::uuid();
        $this->registerDeviceViaApi($mhs, $phoneUuid, 'HP Mahasiswa (Untrusted)', 'ADD_DEVICE');

        $revokedUuid = (string) Str::uuid();
        $this->registerDeviceViaApi($mhs, $revokedUuid, 'Tablet Bekas (Revoked)', 'ADD_DEVICE');
        $this->actingAs($mhs, 'sanctum')->postJson("/api/bchat/devices/{$revokedUuid}/revoke", [
            'password' => 'PasswordRahasia123!',
        ])->assertStatus(200);

        // Kasus 1: Perangkat ACTIVE dan tepercaya (Laptop) dengan challenge valid -> DITERIMA (HTTP 200)
        $resTrusted = $this->authenticateBroadcastingWithSignedChallenge(
            user: $mhs,
            deviceUuid: $laptopUuid,
            keypair: $laptop['keypair'],
            channelName: "private-bchat.rooms.{$room->room_uuid}",
            socketId: '1111.2222'
        );
        $resTrusted->assertStatus(200);

        // Kasus 2: Perangkat ACTIVE tetapi untrusted (HP) -> DITOLAK (HTTP 403)
        $resUntrusted = $this->actingAs($mhs, 'sanctum')
            ->withHeader('X-Bchat-Device-Uuid', $phoneUuid)
            ->postJson('/broadcasting/auth', [
                'channel_name' => "private-bchat.rooms.{$room->room_uuid}",
                'socket_id'    => '1111.3333',
            ]);
        $resUntrusted->assertStatus(403);

        // Kasus 3: Perangkat untrusted TIDAK mengirim header X-Bchat-Device-Uuid
        // (Meskipun akun memiliki Laptop yang tepercaya, tidak boleh ada fallback longgar) -> DITOLAK (HTTP 403)
        $resNoHeader = $this->actingAs($mhs, 'sanctum')
            ->postJson('/broadcasting/auth', [
                'channel_name' => "private-bchat.rooms.{$room->room_uuid}",
                'socket_id'    => '1111.4444',
            ]);
        $resNoHeader->assertStatus(403);

        // Kasus 4: Header kosong atau spasi -> DITOLAK (HTTP 403)
        $resEmptyHeader = $this->actingAs($mhs, 'sanctum')
            ->withHeader('X-Bchat-Device-Uuid', '   ')
            ->postJson('/broadcasting/auth', [
                'channel_name' => "private-bchat.rooms.{$room->room_uuid}",
                'socket_id'    => '1111.5555',
            ]);
        $resEmptyHeader->assertStatus(403);

        // Kasus 5: Perangkat berstatus REVOKED -> DITOLAK (HTTP 403)
        $resRevoked = $this->actingAs($mhs, 'sanctum')
            ->withHeader('X-Bchat-Device-Uuid', $revokedUuid)
            ->postJson('/broadcasting/auth', [
                'channel_name' => "private-bchat.rooms.{$room->room_uuid}",
                'socket_id'    => '1111.6666',
            ]);
        $resRevoked->assertStatus(403);

        // Kasus 6: UUID acak yang tidak terdaftar pada akun -> DITOLAK (HTTP 403)
        $resRandomUuid = $this->actingAs($mhs, 'sanctum')
            ->withHeader('X-Bchat-Device-Uuid', (string) Str::uuid())
            ->postJson('/broadcasting/auth', [
                'channel_name' => "private-bchat.rooms.{$room->room_uuid}",
                'socket_id'    => '1111.7777',
            ]);
        $resRandomUuid->assertStatus(403);

        // Kasus 7: Pencabutan langsung memblokir koneksi baru/re-auth Laptop
        $this->actingAs($mhs, 'sanctum')->postJson("/api/bchat/devices/{$laptopUuid}/revoke", [
            'password' => 'PasswordRahasia123!',
        ])->assertStatus(200);

        $resRevokedAfter = $this->actingAs($mhs, 'sanctum')
            ->withHeader('X-Bchat-Device-Uuid', $laptopUuid)
            ->postJson('/broadcasting/auth', [
                'channel_name' => "private-bchat.rooms.{$room->room_uuid}",
                'socket_id'    => '1111.8888',
            ]);
        $resRevokedAfter->assertStatus(403);
    }
}
