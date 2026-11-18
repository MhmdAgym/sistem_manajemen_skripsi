<?php

declare(strict_types=1);

namespace Tests\Feature\Bchat;

use App\Models\BchatAttachment;
use App\Models\BchatDevicePairing;
use App\Models\BchatKeyPackage;
use App\Models\BchatRegistrationNonce;
use App\Models\BchatRoom;
use App\Models\BchatRoomMember;
use App\Models\BchatUserDevice;
use App\Models\BchatWelcomeChallenge;
use App\Models\BchatWsAuthChallenge;
use App\Models\Dosen;
use App\Models\Mahasiswa;
use App\Models\PenugasanPembimbing;
use App\Models\Skripsi;
use App\Models\User;
use App\Services\BchatDeviceService;
use App\Services\BchatPopVerificationService;
use App\Services\BchatWelcomeAckVerificationService;
use App\Services\BchatWsAuthVerificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Pengujian Terarah Tahap 2A.2:
 * 1. Legacy Trust Root Fail-Closed Hardening
 * 2. Cryptographic Reverb Device Authentication (Signed WebSocket Auth Challenge)
 */
class BchatWsAuthAndLegacyTrustHardeningFeatureTest extends TestCase
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

    /**
     * Helper untuk alur lengkap otorisasi Reverb channel menggunakan Signed WebSocket Auth Challenge.
     */
    private function authenticateBroadcastingWithSignedChallenge(
        User $user,
        string $deviceUuid,
        array $keypair,
        string $channelName,
        string $socketId = '1234.5678'
    ): array {
        // 1. Minta challenge dari backend
        $challengeRes = $this->actingAs($user, 'sanctum')->postJson('/api/bchat/broadcasting/challenge', [
            'device_uuid'  => $deviceUuid,
            'socket_id'    => $socketId,
            'channel_name' => $channelName,
        ]);

        if ($challengeRes->status() !== 201) {
            // Jika penerbitan challenge ditolak, teruskan auth tanpa signature valid
            $authRes = $this->actingAs($user, 'sanctum')
                ->withHeader('X-Bchat-Device-Uuid', $deviceUuid)
                ->postJson('/broadcasting/auth', [
                    'channel_name' => $channelName,
                    'socket_id'    => $socketId,
                ]);

            return [
                'challenge_response' => $challengeRes,
                'auth_response'      => $authRes,
                'challenge_data'     => null,
            ];
        }

        $challengeData = $challengeRes->json('data');
        $payloadBinary = hex2bin($challengeData['payload_hex']);
        $signatureBinary = sodium_crypto_sign_detached($payloadBinary, $keypair['secret_key']);
        $signatureHex = bin2hex($signatureBinary);

        // 2. Panggil /broadcasting/auth Reverb dengan header kriptografis
        $authRes = $this->actingAs($user, 'sanctum')
            ->withHeaders([
                'X-Bchat-Device-Uuid'    => $deviceUuid,
                'X-Bchat-Challenge-Uuid' => $challengeData['challenge_uuid'],
                'X-Bchat-Signature'      => $signatureHex,
            ])
            ->postJson('/broadcasting/auth', [
                'channel_name' => $channelName,
                'socket_id'    => $socketId,
            ]);

        return [
            'challenge_response' => $challengeRes,
            'auth_response'      => $authRes,
            'challenge_data'     => $challengeData,
            'signature_hex'      => $signatureHex,
        ];
    }

    // =========================================================================
    // SECTION D: 12 SKENARIO PENGUJIAN WAJIB REVERB CRYPTOGRAPHIC AUTH
    // =========================================================================

    /**
     * Skenario 1: Perangkat trusted dengan private key yang benar diterima (HTTP 200).
     */
    public function test_01_trusted_device_with_valid_keypair_and_signed_challenge_is_authorized(): void
    {
        $mhs = $this->createTestUser('mahasiswa');
        $dosen = $this->createTestUser('dosen');
        $room = $this->createPairingRoomWithMembers($mhs, $dosen);

        $laptopUuid = (string) Str::uuid();
        $laptop = $this->registerDeviceViaApi($mhs, $laptopUuid, 'Laptop Mahasiswa');
        $this->assertTrue($laptop['device']->is_trusted);

        $channel = "private-bchat.rooms.{$room->room_uuid}";
        $result = $this->authenticateBroadcastingWithSignedChallenge(
            user: $mhs,
            deviceUuid: $laptopUuid,
            keypair: $laptop['keypair'],
            channelName: $channel,
            socketId: '1001.2001'
        );

        $result['challenge_response']->assertStatus(201);
        $result['auth_response']->assertStatus(200);

        // Pastikan status challenge di DB berubah menjadi CONSUMED
        $challengeInDb = BchatWsAuthChallenge::where('challenge_uuid', $result['challenge_data']['challenge_uuid'])->first();
        $this->assertSame('CONSUMED', $challengeInDb->status);
        $this->assertNotNull($challengeInDb->consumed_at);
    }

    /**
     * Skenario 2: Perangkat untrusted ditolak saat minta challenge dan saat auth (HTTP 403).
     */
    public function test_02_untrusted_device_rejected_at_challenge_issue_and_auth(): void
    {
        $mhs = $this->createTestUser('mahasiswa');
        $dosen = $this->createTestUser('dosen');
        $room = $this->createPairingRoomWithMembers($mhs, $dosen);

        $laptopUuid = (string) Str::uuid();
        $this->registerDeviceViaApi($mhs, $laptopUuid, 'Laptop (Trusted)');

        $phoneUuid = (string) Str::uuid();
        $phone = $this->registerDeviceViaApi($mhs, $phoneUuid, 'HP (Untrusted)', 'ADD_DEVICE');
        $this->assertFalse($phone['device']->is_trusted);

        $channel = "private-bchat.rooms.{$room->room_uuid}";
        $result = $this->authenticateBroadcastingWithSignedChallenge(
            user: $mhs,
            deviceUuid: $phoneUuid,
            keypair: $phone['keypair'],
            channelName: $channel,
            socketId: '1002.2002'
        );

        // Minta challenge ditolak 403 DEVICE_NOT_TRUSTED
        $result['challenge_response']->assertStatus(403)
            ->assertJson([
                'status'     => 'error',
                'error_code' => 'DEVICE_NOT_TRUSTED',
            ]);

        // Autentikasi Reverb ditolak 403
        $result['auth_response']->assertStatus(403);
    }

    /**
     * Skenario 3: Perangkat revoked ditolak (HTTP 403).
     */
    public function test_03_revoked_device_rejected(): void
    {
        $mhs = $this->createTestUser('mahasiswa');
        $dosen = $this->createTestUser('dosen');
        $room = $this->createPairingRoomWithMembers($mhs, $dosen);

        $laptopUuid = (string) Str::uuid();
        $laptop = $this->registerDeviceViaApi($mhs, $laptopUuid, 'Laptop Mahasiswa');

        // Cabut perangkat
        $this->actingAs($mhs, 'sanctum')->postJson("/api/bchat/devices/{$laptopUuid}/revoke", [
            'password' => 'PasswordRahasia123!',
        ])->assertStatus(200);

        $channel = "private-bchat.rooms.{$room->room_uuid}";
        $result = $this->authenticateBroadcastingWithSignedChallenge(
            user: $mhs,
            deviceUuid: $laptopUuid,
            keypair: $laptop['keypair'],
            channelName: $channel,
            socketId: '1003.2003'
        );

        $result['challenge_response']->assertStatus(403)
            ->assertJson([
                'status'     => 'error',
                'error_code' => 'DEVICE_NOT_ACTIVE',
            ]);

        $result['auth_response']->assertStatus(403);
    }

    /**
     * Skenario 4: UUID laptop tepercaya yang dikirim oleh HP untrusted tanpa private key laptop ditolak (HTTP 403).
     */
    public function test_04_spoofed_laptop_uuid_sent_by_phone_without_laptop_private_key_is_rejected(): void
    {
        $mhs = $this->createTestUser('mahasiswa');
        $dosen = $this->createTestUser('dosen');
        $room = $this->createPairingRoomWithMembers($mhs, $dosen);

        $laptopUuid = (string) Str::uuid();
        $laptop = $this->registerDeviceViaApi($mhs, $laptopUuid, 'Laptop (Trusted)');

        $phoneUuid = (string) Str::uuid();
        $phone = $this->registerDeviceViaApi($mhs, $phoneUuid, 'HP (Untrusted)', 'ADD_DEVICE');

        $channel = "private-bchat.rooms.{$room->room_uuid}";
        $socketId = '1004.2004';

        // HP mencoba minta challenge dengan mengklaim laptopUuid
        $challengeRes = $this->actingAs($mhs, 'sanctum')->postJson('/api/bchat/broadcasting/challenge', [
            'device_uuid'  => $laptopUuid,
            'socket_id'    => $socketId,
            'channel_name' => $channel,
        ]);
        $challengeRes->assertStatus(201);
        $challengeData = $challengeRes->json('data');

        // HP menandatangani payload menggunakan private key HP sendiri (BUKAN private key Laptop)
        $payloadBinary = hex2bin($challengeData['payload_hex']);
        $spoofedSigBinary = sodium_crypto_sign_detached($payloadBinary, $phone['keypair']['secret_key']);

        // Kirim ke Reverb /broadcasting/auth
        $authRes = $this->actingAs($mhs, 'sanctum')
            ->withHeaders([
                'X-Bchat-Device-Uuid'    => $laptopUuid,
                'X-Bchat-Challenge-Uuid' => $challengeData['challenge_uuid'],
                'X-Bchat-Signature'      => bin2hex($spoofedSigBinary),
            ])
            ->postJson('/broadcasting/auth', [
                'channel_name' => $channel,
                'socket_id'    => $socketId,
            ]);

        // Verifikasi signature gagal terhadap public key Laptop yang tersimpan di DB -> DITOLAK 403
        $authRes->assertStatus(403);
    }

    /**
     * Skenario 5: Signature salah / korup ditolak (HTTP 403).
     */
    public function test_05_invalid_signature_rejected(): void
    {
        $mhs = $this->createTestUser('mahasiswa');
        $dosen = $this->createTestUser('dosen');
        $room = $this->createPairingRoomWithMembers($mhs, $dosen);

        $laptopUuid = (string) Str::uuid();
        $laptop = $this->registerDeviceViaApi($mhs, $laptopUuid, 'Laptop Mahasiswa');

        $channel = "private-bchat.rooms.{$room->room_uuid}";
        $socketId = '1005.2005';

        $challengeRes = $this->actingAs($mhs, 'sanctum')->postJson('/api/bchat/broadcasting/challenge', [
            'device_uuid'  => $laptopUuid,
            'socket_id'    => $socketId,
            'channel_name' => $channel,
        ]);
        $challengeRes->assertStatus(201);
        $challengeData = $challengeRes->json('data');

        // Buat signature palsu / rusak (128 karakter hex acak)
        $corruptSignature = str_repeat('ff', 64);

        $authRes = $this->actingAs($mhs, 'sanctum')
            ->withHeaders([
                'X-Bchat-Device-Uuid'    => $laptopUuid,
                'X-Bchat-Challenge-Uuid' => $challengeData['challenge_uuid'],
                'X-Bchat-Signature'      => $corruptSignature,
            ])
            ->postJson('/broadcasting/auth', [
                'channel_name' => $channel,
                'socket_id'    => $socketId,
            ]);

        $authRes->assertStatus(403);
    }

    /**
     * Skenario 6: Signature dari perangkat lain ditolak (HTTP 403).
     */
    public function test_06_signature_from_different_device_rejected(): void
    {
        $mhs = $this->createTestUser('mahasiswa');
        $dosen = $this->createTestUser('dosen');
        $room = $this->createPairingRoomWithMembers($mhs, $dosen);

        $laptop1Uuid = (string) Str::uuid();
        $laptop1 = $this->registerDeviceViaApi($mhs, $laptop1Uuid, 'Laptop 1');

        $otherKeypair = $this->generateEd25519Keypair();

        $channel = "private-bchat.rooms.{$room->room_uuid}";
        $socketId = '1006.2006';

        $challengeRes = $this->actingAs($mhs, 'sanctum')->postJson('/api/bchat/broadcasting/challenge', [
            'device_uuid'  => $laptop1Uuid,
            'socket_id'    => $socketId,
            'channel_name' => $channel,
        ]);
        $challengeRes->assertStatus(201);
        $challengeData = $challengeRes->json('data');

        $payloadBinary = hex2bin($challengeData['payload_hex']);
        $sigBinary = sodium_crypto_sign_detached($payloadBinary, $otherKeypair['secret_key']);

        $authRes = $this->actingAs($mhs, 'sanctum')
            ->withHeaders([
                'X-Bchat-Device-Uuid'    => $laptop1Uuid,
                'X-Bchat-Challenge-Uuid' => $challengeData['challenge_uuid'],
                'X-Bchat-Signature'      => bin2hex($sigBinary),
            ])
            ->postJson('/broadcasting/auth', [
                'channel_name' => $channel,
                'socket_id'    => $socketId,
            ]);

        $authRes->assertStatus(403);
    }

    /**
     * Skenario 7: Signature untuk socket ID berbeda ditolak (HTTP 403).
     */
    public function test_07_signature_for_different_socket_id_rejected(): void
    {
        $mhs = $this->createTestUser('mahasiswa');
        $dosen = $this->createTestUser('dosen');
        $room = $this->createPairingRoomWithMembers($mhs, $dosen);

        $laptopUuid = (string) Str::uuid();
        $laptop = $this->registerDeviceViaApi($mhs, $laptopUuid, 'Laptop Mahasiswa');

        $channel = "private-bchat.rooms.{$room->room_uuid}";
        $issuedSocketId = '1007.2007';
        $attackerSocketId = '9999.8888';

        $challengeRes = $this->actingAs($mhs, 'sanctum')->postJson('/api/bchat/broadcasting/challenge', [
            'device_uuid'  => $laptopUuid,
            'socket_id'    => $issuedSocketId,
            'channel_name' => $channel,
        ]);
        $challengeRes->assertStatus(201);
        $challengeData = $challengeRes->json('data');

        $payloadBinary = hex2bin($challengeData['payload_hex']);
        $sigBinary = sodium_crypto_sign_detached($payloadBinary, $laptop['keypair']['secret_key']);

        // Klien mencoba menyambung ke socket ID berbeda
        $authRes = $this->actingAs($mhs, 'sanctum')
            ->withHeaders([
                'X-Bchat-Device-Uuid'    => $laptopUuid,
                'X-Bchat-Challenge-Uuid' => $challengeData['challenge_uuid'],
                'X-Bchat-Signature'      => bin2hex($sigBinary),
            ])
            ->postJson('/broadcasting/auth', [
                'channel_name' => $channel,
                'socket_id'    => $attackerSocketId,
            ]);

        $authRes->assertStatus(403);
    }

    /**
     * Skenario 8: Signature untuk channel berbeda ditolak (HTTP 403).
     */
    public function test_08_signature_for_different_channel_rejected(): void
    {
        $mhs = $this->createTestUser('mahasiswa');
        $dosen = $this->createTestUser('dosen');
        $room1 = $this->createPairingRoomWithMembers($mhs, $dosen);

        $mhs2 = $this->createTestUser('mahasiswa');
        $dosen2 = $this->createTestUser('dosen');
        $room2 = $this->createPairingRoomWithMembers($mhs2, $dosen2);

        $laptopUuid = (string) Str::uuid();
        $laptop = $this->registerDeviceViaApi($mhs, $laptopUuid, 'Laptop Mahasiswa');

        $socketId = '1008.2008';
        $channel1 = "private-bchat.rooms.{$room1->room_uuid}";
        $channel2 = "private-bchat.rooms.{$room2->room_uuid}";

        // Challenge diterbitkan untuk room 1
        $challengeRes = $this->actingAs($mhs, 'sanctum')->postJson('/api/bchat/broadcasting/challenge', [
            'device_uuid'  => $laptopUuid,
            'socket_id'    => $socketId,
            'channel_name' => $channel1,
        ]);
        $challengeRes->assertStatus(201);
        $challengeData = $challengeRes->json('data');

        $payloadBinary = hex2bin($challengeData['payload_hex']);
        $sigBinary = sodium_crypto_sign_detached($payloadBinary, $laptop['keypair']['secret_key']);

        // Klien mencoba mengautentikasi room 2 dengan challenge room 1
        $authRes = $this->actingAs($mhs, 'sanctum')
            ->withHeaders([
                'X-Bchat-Device-Uuid'    => $laptopUuid,
                'X-Bchat-Challenge-Uuid' => $challengeData['challenge_uuid'],
                'X-Bchat-Signature'      => bin2hex($sigBinary),
            ])
            ->postJson('/broadcasting/auth', [
                'channel_name' => $channel2,
                'socket_id'    => $socketId,
            ]);

        $authRes->assertStatus(403);
    }

    /**
     * Skenario 9: Nonce yang digunakan ulang ditolak (anti-replay: status CONSUMED, percobaan kedua 403).
     */
    public function test_09_replayed_challenge_nonce_rejected_anti_replay(): void
    {
        $mhs = $this->createTestUser('mahasiswa');
        $dosen = $this->createTestUser('dosen');
        $room = $this->createPairingRoomWithMembers($mhs, $dosen);

        $laptopUuid = (string) Str::uuid();
        $laptop = $this->registerDeviceViaApi($mhs, $laptopUuid, 'Laptop Mahasiswa');

        $channel = "private-bchat.rooms.{$room->room_uuid}";
        $socketId = '1009.2009';

        $challengeRes = $this->actingAs($mhs, 'sanctum')->postJson('/api/bchat/broadcasting/challenge', [
            'device_uuid'  => $laptopUuid,
            'socket_id'    => $socketId,
            'channel_name' => $channel,
        ]);
        $challengeRes->assertStatus(201);
        $challengeData = $challengeRes->json('data');

        $payloadBinary = hex2bin($challengeData['payload_hex']);
        $sigBinary = sodium_crypto_sign_detached($payloadBinary, $laptop['keypair']['secret_key']);
        $sigHex = bin2hex($sigBinary);

        // Percobaan 1: Berhasil (200 OK)
        $authRes1 = $this->actingAs($mhs, 'sanctum')
            ->withHeaders([
                'X-Bchat-Device-Uuid'    => $laptopUuid,
                'X-Bchat-Challenge-Uuid' => $challengeData['challenge_uuid'],
                'X-Bchat-Signature'      => $sigHex,
            ])
            ->postJson('/broadcasting/auth', [
                'channel_name' => $channel,
                'socket_id'    => $socketId,
            ]);
        $authRes1->assertStatus(200);

        // Percobaan 2: Replay challenge dan signature yang sama persis -> DITOLAK (HTTP 403)
        $authRes2 = $this->actingAs($mhs, 'sanctum')
            ->withHeaders([
                'X-Bchat-Device-Uuid'    => $laptopUuid,
                'X-Bchat-Challenge-Uuid' => $challengeData['challenge_uuid'],
                'X-Bchat-Signature'      => $sigHex,
            ])
            ->postJson('/broadcasting/auth', [
                'channel_name' => $channel,
                'socket_id'    => $socketId,
            ]);
        $authRes2->assertStatus(403);
    }

    /**
     * Skenario 10: Challenge kedaluwarsa ditolak (TTL > 60s, status EXPIRED, HTTP 403).
     */
    public function test_10_expired_challenge_rejected(): void
    {
        $mhs = $this->createTestUser('mahasiswa');
        $dosen = $this->createTestUser('dosen');
        $room = $this->createPairingRoomWithMembers($mhs, $dosen);

        $laptopUuid = (string) Str::uuid();
        $laptop = $this->registerDeviceViaApi($mhs, $laptopUuid, 'Laptop Mahasiswa');

        $channel = "private-bchat.rooms.{$room->room_uuid}";
        $socketId = '1010.2010';

        $challengeRes = $this->actingAs($mhs, 'sanctum')->postJson('/api/bchat/broadcasting/challenge', [
            'device_uuid'  => $laptopUuid,
            'socket_id'    => $socketId,
            'channel_name' => $channel,
        ]);
        $challengeRes->assertStatus(201);
        $challengeData = $challengeRes->json('data');

        // Simulasikan kedaluwarsa (ubah expires_at ke 5 detik yang lalu)
        BchatWsAuthChallenge::where('challenge_uuid', $challengeData['challenge_uuid'])
            ->update(['expires_at' => now()->subSeconds(5)]);

        $payloadBinary = hex2bin($challengeData['payload_hex']);
        $sigBinary = sodium_crypto_sign_detached($payloadBinary, $laptop['keypair']['secret_key']);

        $authRes = $this->actingAs($mhs, 'sanctum')
            ->withHeaders([
                'X-Bchat-Device-Uuid'    => $laptopUuid,
                'X-Bchat-Challenge-Uuid' => $challengeData['challenge_uuid'],
                'X-Bchat-Signature'      => bin2hex($sigBinary),
            ])
            ->postJson('/broadcasting/auth', [
                'channel_name' => $channel,
                'socket_id'    => $socketId,
            ]);

        $authRes->assertStatus(403);

        $dbChallenge = BchatWsAuthChallenge::where('challenge_uuid', $challengeData['challenge_uuid'])->first();
        $this->assertSame('EXPIRED', $dbChallenge->status);
    }

    /**
     * Skenario 11: Pencabutan perangkat menggagalkan autentikasi berikutnya (HTTP 403).
     */
    public function test_11_revoking_device_invalidates_future_challenges_and_auth(): void
    {
        $mhs = $this->createTestUser('mahasiswa');
        $dosen = $this->createTestUser('dosen');
        $room = $this->createPairingRoomWithMembers($mhs, $dosen);

        $laptopUuid = (string) Str::uuid();
        $laptop = $this->registerDeviceViaApi($mhs, $laptopUuid, 'Laptop Mahasiswa');

        $channel = "private-bchat.rooms.{$room->room_uuid}";
        $socketId = '1011.2011';

        // 1. Minta challenge saat masih aktif
        $challengeRes = $this->actingAs($mhs, 'sanctum')->postJson('/api/bchat/broadcasting/challenge', [
            'device_uuid'  => $laptopUuid,
            'socket_id'    => $socketId,
            'channel_name' => $channel,
        ]);
        $challengeRes->assertStatus(201);
        $challengeData = $challengeRes->json('data');

        $payloadBinary = hex2bin($challengeData['payload_hex']);
        $sigBinary = sodium_crypto_sign_detached($payloadBinary, $laptop['keypair']['secret_key']);

        // 2. Cabut perangkat sebelum konsumsi
        $this->actingAs($mhs, 'sanctum')->postJson("/api/bchat/devices/{$laptopUuid}/revoke", [
            'password' => 'PasswordRahasia123!',
        ])->assertStatus(200);

        // 3. Autentikasi dengan challenge yang sudah terlanjur terbit -> DITOLAK 403
        $authRes = $this->actingAs($mhs, 'sanctum')
            ->withHeaders([
                'X-Bchat-Device-Uuid'    => $laptopUuid,
                'X-Bchat-Challenge-Uuid' => $challengeData['challenge_uuid'],
                'X-Bchat-Signature'      => bin2hex($sigBinary),
            ])
            ->postJson('/broadcasting/auth', [
                'channel_name' => $channel,
                'socket_id'    => $socketId,
            ]);

        $authRes->assertStatus(403);
    }

    /**
     * Skenario 12: Pengguna lain tidak dapat menggunakan challenge atau public key perangkat tersebut (IDOR / 403).
     */
    public function test_12_cross_user_idor_challenge_and_public_key_rejected(): void
    {
        $mhs = $this->createTestUser('mahasiswa');
        $attacker = $this->createTestUser('mahasiswa');
        $dosen = $this->createTestUser('dosen');
        $room = $this->createPairingRoomWithMembers($mhs, $dosen);

        $laptopUuid = (string) Str::uuid();
        $laptop = $this->registerDeviceViaApi($mhs, $laptopUuid, 'Laptop Mahasiswa');

        $channel = "private-bchat.rooms.{$room->room_uuid}";
        $socketId = '1012.2012';

        // Mhs menerbitkan challenge
        $challengeRes = $this->actingAs($mhs, 'sanctum')->postJson('/api/bchat/broadcasting/challenge', [
            'device_uuid'  => $laptopUuid,
            'socket_id'    => $socketId,
            'channel_name' => $channel,
        ]);
        $challengeRes->assertStatus(201);
        $challengeData = $challengeRes->json('data');

        $payloadBinary = hex2bin($challengeData['payload_hex']);
        $sigBinary = sodium_crypto_sign_detached($payloadBinary, $laptop['keypair']['secret_key']);

        // Attacker mencoba menggunakan challenge mhs pada koneksi socket attacker
        $authRes = $this->actingAs($attacker, 'sanctum')
            ->withHeaders([
                'X-Bchat-Device-Uuid'    => $laptopUuid,
                'X-Bchat-Challenge-Uuid' => $challengeData['challenge_uuid'],
                'X-Bchat-Signature'      => bin2hex($sigBinary),
            ])
            ->postJson('/broadcasting/auth', [
                'channel_name' => $channel,
                'socket_id'    => $socketId,
            ]);

        $authRes->assertStatus(403);
    }

    // =========================================================================
    // SECTION B: PEMERIKSAAN 1 — LEGACY TRUST ROOT HARDENING
    // =========================================================================

    /**
     * Skenario 13: Perangkat lama dengan bukti Welcome ACK yang valid dipertahankan sebagai is_trusted = true.
     */
    public function test_13_legacy_trust_root_preserved_with_valid_welcome_ack_proof(): void
    {
        $mhs = $this->createTestUser('mahasiswa');
        $dosen = $this->createTestUser('dosen');
        $room = $this->createPairingRoomWithMembers($mhs, $dosen);

        $devUuid = (string) Str::uuid();
        $keypair = $this->generateEd25519Keypair();

        $device = BchatUserDevice::create([
            'user_id'                => $mhs->id,
            'device_uuid'            => $devUuid,
            'device_name'            => 'Legacy Laptop Valid',
            'public_key'             => $keypair['public_key_hex'],
            'public_key_fingerprint' => $keypair['fingerprint'],
            'status'                 => 'ACTIVE',
            'is_trusted'             => true,
            'trusted_at'             => now(),
            'key_version'            => 1,
        ]);

        // Berikan bukti historis Welcome ACK dengan valid signed ACK kanonis
        $welcomeOpUuid = (string) Str::uuid();
        $challengeUuid = (string) Str::uuid();
        $challengeNonce = bin2hex(random_bytes(32));

        $payload = BchatWelcomeAckVerificationService::buildPayload(
            roomUuid: $room->room_uuid,
            recipientUserId: $mhs->id,
            welcomeOperationUuid: $welcomeOpUuid,
            challengeUuid: $challengeUuid,
            challengeGeneration: 1,
            groupIdHashHexOrBin: $room->group_id_hash,
            epoch: 1,
            challengeNonceHexOrBin: $challengeNonce
        );

        $signature = sodium_crypto_sign_detached($payload, $keypair['secret_key']);
        $payloadHash = hash('sha256', $payload);

        BchatWelcomeChallenge::create([
            'welcome_operation_uuid' => $welcomeOpUuid,
            'room_id'                => $room->id,
            'inviter_user_id'        => $dosen->id,
            'recipient_user_id'      => $mhs->id,
            'recipient_device_id'    => $device->id,
            'challenge_uuid'         => $challengeUuid,
            'challenge_nonce'        => $challengeNonce,
            'challenge_generation'   => 1,
            'welcome_message'        => 'encrypted_mls_welcome_payload',
            'group_id_hash'          => $room->group_id_hash,
            'epoch'                  => 1,
            'welcome_payload'        => 'payload_data',
            'operation_status'       => 'CONSUMED',
            'challenge_status'       => 'CONSUMED',
            'ack_signature'          => bin2hex($signature),
            'ack_payload_hash'       => $payloadHash,
            'consumed_at'            => now(),
            'ack_received_at'        => now(),
            'expires_at'             => now()->addDay(),
        ]);

        $hasProof = BchatDeviceService::hasHistoricalTrustProof($device);
        $this->assertTrue($hasProof);
    }

    /**
     * Skenario 14: Perangkat dengan KeyPackage consumed tanpa Welcome ACK dan tanpa Pairing Approval menghasilkan is_trusted = false.
     */
    public function test_14_key_package_consumed_alone_without_welcome_ack_or_pairing_is_untrusted(): void
    {
        $mhs = $this->createTestUser('mahasiswa');
        $devUuid = (string) Str::uuid();
        $keypair = $this->generateEd25519Keypair();

        $device = BchatUserDevice::create([
            'user_id'                => $mhs->id,
            'device_uuid'            => $devUuid,
            'device_name'            => 'Legacy PC KeyPackage Only',
            'public_key'             => $keypair['public_key_hex'],
            'public_key_fingerprint' => $keypair['fingerprint'],
            'status'                 => 'ACTIVE',
            'is_trusted'             => false,
            'trusted_at'             => null,
            'key_version'            => 1,
        ]);

        $pkgData = bin2hex(random_bytes(64));
        BchatKeyPackage::create([
            'user_id'          => $mhs->id,
            'device_id'        => $device->id,
            'key_package_hash' => hash('sha256', $pkgData),
            'key_package_data' => $pkgData,
            'is_consumed'      => true,
            'consumed_at'      => now(),
        ]);

        // KeyPackage saja tidak membuktikan penguasaan private key -> wajib false
        $hasProof = BchatDeviceService::hasHistoricalTrustProof($device);
        $this->assertFalse($hasProof);
    }

    /**
     * Skenario 15: Perangkat lama tanpa bukti trust historis diubah menjadi is_trusted = false (Fail-Closed).
     */
    public function test_15_legacy_device_without_historical_proof_is_untrusted(): void
    {
        $mhs = $this->createTestUser('mahasiswa');
        $devUuid = (string) Str::uuid();
        $keypair = $this->generateEd25519Keypair();

        $device = BchatUserDevice::create([
            'user_id'                => $mhs->id,
            'device_uuid'            => $devUuid,
            'device_name'            => 'Legacy Device No Proof',
            'public_key'             => $keypair['public_key_hex'],
            'public_key_fingerprint' => $keypair['fingerprint'],
            'status'                 => 'ACTIVE',
            'is_trusted'             => false,
            'trusted_at'             => null,
            'key_version'            => 1,
        ]);

        $hasProof = BchatDeviceService::hasHistoricalTrustProof($device);
        $this->assertFalse($hasProof);
    }

    /**
     * Skenario 16: Dua perangkat ACTIVE dalam satu akun: hanya yang memiliki bukti sah yang tepercaya.
     */
    public function test_16_multiple_active_devices_in_one_account_evaluated_individually(): void
    {
        $mhs = $this->createTestUser('mahasiswa');
        $dosen = $this->createTestUser('dosen');
        $room = $this->createPairingRoomWithMembers($mhs, $dosen);

        $kp1 = $this->generateEd25519Keypair();
        $kp2 = $this->generateEd25519Keypair();

        $dev1 = BchatUserDevice::create([
            'user_id'                => $mhs->id,
            'device_uuid'            => (string) Str::uuid(),
            'device_name'            => 'Perangkat 1 (Sah)',
            'public_key'             => $kp1['public_key_hex'],
            'public_key_fingerprint' => $kp1['fingerprint'],
            'status'                 => 'ACTIVE',
            'is_trusted'             => true,
            'trusted_at'             => now(),
            'key_version'            => 1,
        ]);

        $dev2 = BchatUserDevice::create([
            'user_id'                => $mhs->id,
            'device_uuid'            => (string) Str::uuid(),
            'device_name'            => 'Perangkat 2 (Tanpa Bukti)',
            'public_key'             => $kp2['public_key_hex'],
            'public_key_fingerprint' => $kp2['fingerprint'],
            'status'                 => 'ACTIVE',
            'is_trusted'             => false,
            'trusted_at'             => null,
            'key_version'            => 1,
        ]);

        // Dev 1 memiliki bukti Welcome ACK kanonis bertanda tangan valid
        $welcomeOpUuid = (string) Str::uuid();
        $challengeUuid = (string) Str::uuid();
        $challengeNonce = bin2hex(random_bytes(32));

        $payload = BchatWelcomeAckVerificationService::buildPayload(
            roomUuid: $room->room_uuid,
            recipientUserId: $mhs->id,
            welcomeOperationUuid: $welcomeOpUuid,
            challengeUuid: $challengeUuid,
            challengeGeneration: 1,
            groupIdHashHexOrBin: $room->group_id_hash,
            epoch: 1,
            challengeNonceHexOrBin: $challengeNonce
        );

        $signature = sodium_crypto_sign_detached($payload, $kp1['secret_key']);
        $payloadHash = hash('sha256', $payload);

        BchatWelcomeChallenge::create([
            'welcome_operation_uuid' => $welcomeOpUuid,
            'room_id'                => $room->id,
            'inviter_user_id'        => $dosen->id,
            'recipient_user_id'      => $mhs->id,
            'recipient_device_id'    => $dev1->id,
            'challenge_uuid'         => $challengeUuid,
            'challenge_nonce'        => $challengeNonce,
            'challenge_generation'   => 1,
            'welcome_message'        => 'encrypted_mls_welcome_payload',
            'group_id_hash'          => $room->group_id_hash,
            'epoch'                  => 1,
            'welcome_payload'        => 'payload',
            'operation_status'       => 'CONSUMED',
            'challenge_status'       => 'CONSUMED',
            'ack_signature'          => bin2hex($signature),
            'ack_payload_hash'       => $payloadHash,
            'consumed_at'            => now(),
            'ack_received_at'        => now(),
            'expires_at'             => now()->addDay(),
        ]);

        $this->assertTrue(BchatDeviceService::hasHistoricalTrustProof($dev1));
        $this->assertFalse(BchatDeviceService::hasHistoricalTrustProof($dev2));
    }

    /**
     * Skenario 17: Perangkat baru melalui ADD_DEVICE tetap strictly untrusted (is_trusted = false).
     */
    public function test_17_add_device_is_strictly_untrusted_regardless_of_account_trust(): void
    {
        $mhs = $this->createTestUser('mahasiswa');

        $laptopUuid = (string) Str::uuid();
        $laptop = $this->registerDeviceViaApi($mhs, $laptopUuid, 'Laptop (First Device)');
        $this->assertTrue($laptop['device']->is_trusted);

        $phoneUuid = (string) Str::uuid();
        $phone = $this->registerDeviceViaApi($mhs, $phoneUuid, 'HP (Additional Device)', 'ADD_DEVICE');
        $this->assertFalse($phone['device']->is_trusted);
        $this->assertNull($phone['device']->trusted_at);
    }

    /**
     * Skenario 18: Managed Replacement mewarisi status trust hanya jika perangkat sumber tepercaya.
     */
    public function test_18_managed_replacement_inherits_trust_only_if_source_was_trusted(): void
    {
        $mhs = $this->createTestUser('mahasiswa');

        // 1. Akun memiliki Laptop tepercaya
        $laptopUuid = (string) Str::uuid();
        $this->registerDeviceViaApi($mhs, $laptopUuid, 'Laptop');

        // 2. Akun memiliki HP tidak tepercaya
        $phoneUuid = (string) Str::uuid();
        $phone = $this->registerDeviceViaApi($mhs, $phoneUuid, 'HP', 'ADD_DEVICE');
        $this->assertFalse($phone['device']->is_trusted);

        // 3. Managed Replacement untuk HP tidak tepercaya
        $newPhoneUuid = (string) Str::uuid();
        $replacePhone = $this->registerDeviceViaApi(
            user: $mhs,
            deviceUuid: $newPhoneUuid,
            deviceName: 'HP Pengganti',
            mode: 'MANAGED_REPLACEMENT',
            confirmReplacement: true,
            expectedActiveUuid: $phoneUuid
        );

        $this->assertFalse($replacePhone['device']->is_trusted);
        $this->assertNull($replacePhone['device']->trusted_at);

        // 4. Managed Replacement untuk Laptop tepercaya -> tetap is_trusted = false (wajib pairing)
        $newLaptopUuid = (string) Str::uuid();
        $replaceLaptop = $this->registerDeviceViaApi(
            user: $mhs,
            deviceUuid: $newLaptopUuid,
            deviceName: 'Laptop Pengganti',
            mode: 'MANAGED_REPLACEMENT',
            confirmReplacement: true,
            expectedActiveUuid: $laptopUuid
        );

        $this->assertFalse($replaceLaptop['device']->is_trusted);
        $this->assertNull($replaceLaptop['device']->trusted_at);
    }
}
