<?php

declare(strict_types=1);

namespace Tests\Feature\Bchat;

use App\Models\BchatRoom;
use App\Models\BchatRoomMember;
use App\Models\BchatTrustBootstrapChallenge;
use App\Models\BchatUserDevice;
use App\Models\Dosen;
use App\Models\Mahasiswa;
use App\Models\PenugasanPembimbing;
use App\Models\Skripsi;
use App\Models\User;
use App\Services\BchatTrustBootstrapService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

class BchatTrustBootstrapFeatureTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;
    protected string $password = 'Rahasia123!';
    protected string $keyPair;
    protected string $publicKeyHex;
    protected string $privateKeyHex;
    protected string $fingerprint;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create([
            'password' => Hash::make($this->password),
        ]);

        // Generate Ed25519 keypair for test device
        $kp = sodium_crypto_sign_keypair();
        $pub = sodium_crypto_sign_publickey($kp);
        $priv = sodium_crypto_sign_secretkey($kp);

        $this->publicKeyHex = bin2hex($pub);
        $this->privateKeyHex = bin2hex($priv);
        $this->fingerprint = hash('sha256', $this->publicKeyHex);
    }

    protected function createUntrustedActiveDevice(User $user, ?string $deviceUuid = null): BchatUserDevice
    {
        return BchatUserDevice::create([
            'user_id'                => $user->id,
            'device_uuid'            => $deviceUuid ?? (string) Str::uuid(),
            'device_name'            => 'Test Device',
            'key_version'            => 1,
            'public_key'             => $this->publicKeyHex,
            'public_key_fingerprint' => $this->fingerprint,
            'status'                 => 'ACTIVE',
            'is_trusted'             => false,
            'trusted_at'             => null,
            'trusted_by_device_id'   => null,
        ]);
    }

    /**
     * Uji 1: Perangkat aktif untrusted dengan password dan private key valid berhasil bootstrap.
     */
    public function test_active_untrusted_device_with_valid_password_and_key_successfully_bootstraps(): void
    {
        $device = $this->createUntrustedActiveDevice($this->user);

        // Step 1: Issue challenge
        $responseChallenge = $this->actingAs($this->user, 'sanctum')->postJson(
            "/api/bchat/devices/{$device->device_uuid}/trust-bootstrap/challenge",
            ['password' => $this->password]
        );

        $responseChallenge->assertStatus(200)
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('data.purpose', 'TRUST_BOOTSTRAP');

        $challengeUuid = $responseChallenge->json('data.challenge_uuid');
        $payloadToSign = $responseChallenge->json('data.payload_to_sign');

        // Step 2: Sign payload using private key
        $privKeyBin = hex2bin($this->privateKeyHex);
        $sigBin = sodium_crypto_sign_detached($payloadToSign, $privKeyBin);
        $signatureHex = bin2hex($sigBin);

        // Step 3: Verify and bootstrap
        $responseVerify = $this->actingAs($this->user, 'sanctum')->postJson(
            "/api/bchat/devices/{$device->device_uuid}/trust-bootstrap/verify",
            [
                'challenge_uuid' => $challengeUuid,
                'signature'      => $signatureHex,
            ]
        );

        $responseVerify->assertStatus(200)
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('data.is_trusted', true)
            ->assertJsonPath('data.is_root_of_trust', true);

        // Assert database state
        $device->refresh();
        $this->assertTrue((bool) $device->is_trusted);
        $this->assertNotNull($device->trusted_at);
        $this->assertNull($device->trusted_by_device_id);

        $this->assertDatabaseHas('bchat_trust_bootstrap_challenges', [
            'challenge_uuid' => $challengeUuid,
            'status'         => 'CONSUMED',
        ]);
    }

    /**
     * Uji 2: Tanda tangan salah ditolak (INVALID_SIGNATURE, 422).
     */
    public function test_invalid_signature_is_rejected(): void
    {
        $device = $this->createUntrustedActiveDevice($this->user);

        $responseChallenge = $this->actingAs($this->user, 'sanctum')->postJson(
            "/api/bchat/devices/{$device->device_uuid}/trust-bootstrap/challenge",
            ['password' => $this->password]
        );

        $challengeUuid = $responseChallenge->json('data.challenge_uuid');

        // Send dummy 128 hex chars signature
        $dummySignature = str_repeat('aa', 64);

        $responseVerify = $this->actingAs($this->user, 'sanctum')->postJson(
            "/api/bchat/devices/{$device->device_uuid}/trust-bootstrap/verify",
            [
                'challenge_uuid' => $challengeUuid,
                'signature'      => $dummySignature,
            ]
        );

        $responseVerify->assertStatus(422)
            ->assertJsonPath('error_code', 'INVALID_SIGNATURE');

        $device->refresh();
        $this->assertFalse((bool) $device->is_trusted);
    }

    /**
     * Uji 3: Password salah ditolak (INVALID_CREDENTIALS, 401).
     */
    public function test_wrong_password_is_rejected(): void
    {
        $device = $this->createUntrustedActiveDevice($this->user);

        $response = $this->actingAs($this->user, 'sanctum')->postJson(
            "/api/bchat/devices/{$device->device_uuid}/trust-bootstrap/challenge",
            ['password' => 'WrongPassword!']
        );

        $response->assertStatus(401)
            ->assertJsonPath('error_code', 'INVALID_CREDENTIALS');
    }

    /**
     * Uji 4: Challenge kedaluwarsa ditolak (CHALLENGE_EXPIRED, 410).
     */
    public function test_expired_challenge_is_rejected(): void
    {
        $device = $this->createUntrustedActiveDevice($this->user);

        $responseChallenge = $this->actingAs($this->user, 'sanctum')->postJson(
            "/api/bchat/devices/{$device->device_uuid}/trust-bootstrap/challenge",
            ['password' => $this->password]
        );

        $challengeUuid = $responseChallenge->json('data.challenge_uuid');
        $payloadToSign = $responseChallenge->json('data.payload_to_sign');

        // Manually expire the challenge in database
        BchatTrustBootstrapChallenge::where('challenge_uuid', $challengeUuid)
            ->update(['expires_at' => now()->subMinute()]);

        $sigBin = sodium_crypto_sign_detached($payloadToSign, hex2bin($this->privateKeyHex));
        $signatureHex = bin2hex($sigBin);

        $responseVerify = $this->actingAs($this->user, 'sanctum')->postJson(
            "/api/bchat/devices/{$device->device_uuid}/trust-bootstrap/verify",
            [
                'challenge_uuid' => $challengeUuid,
                'signature'      => $signatureHex,
            ]
        );

        $responseVerify->assertStatus(410)
            ->assertJsonPath('error_code', 'CHALLENGE_EXPIRED');

        $device->refresh();
        $this->assertFalse((bool) $device->is_trusted);
    }

    /**
     * Uji 5: Challenge yang digunakan ulang ditolak (CHALLENGE_ALREADY_CONSUMED, 409).
     */
    public function test_reused_challenge_is_rejected(): void
    {
        $device = $this->createUntrustedActiveDevice($this->user);

        $responseChallenge = $this->actingAs($this->user, 'sanctum')->postJson(
            "/api/bchat/devices/{$device->device_uuid}/trust-bootstrap/challenge",
            ['password' => $this->password]
        );

        $challengeUuid = $responseChallenge->json('data.challenge_uuid');
        $payloadToSign = $responseChallenge->json('data.payload_to_sign');
        $sigBin = sodium_crypto_sign_detached($payloadToSign, hex2bin($this->privateKeyHex));
        $signatureHex = bin2hex($sigBin);

        // First verification: success
        $this->actingAs($this->user, 'sanctum')->postJson(
            "/api/bchat/devices/{$device->device_uuid}/trust-bootstrap/verify",
            [
                'challenge_uuid' => $challengeUuid,
                'signature'      => $signatureHex,
            ]
        )->assertStatus(200);

        // Second verification with the same challenge: must fail
        $responseSecond = $this->actingAs($this->user, 'sanctum')->postJson(
            "/api/bchat/devices/{$device->device_uuid}/trust-bootstrap/verify",
            [
                'challenge_uuid' => $challengeUuid,
                'signature'      => $signatureHex,
            ]
        );

        $responseSecond->assertStatus(400); // Already trusted or consumed
    }

    /**
     * Uji 6: Perangkat revoked ditolak (DEVICE_NOT_ACTIVE, 400).
     */
    public function test_revoked_device_cannot_bootstrap(): void
    {
        $device = $this->createUntrustedActiveDevice($this->user);
        $device->update(['status' => 'REVOKED', 'revoked_at' => now()]);

        $response = $this->actingAs($this->user, 'sanctum')->postJson(
            "/api/bchat/devices/{$device->device_uuid}/trust-bootstrap/challenge",
            ['password' => $this->password]
        );

        $response->assertStatus(400)
            ->assertJsonPath('error_code', 'DEVICE_NOT_ACTIVE');
    }

    /**
     * Uji 7: Akun yang sudah memiliki trusted device tidak dapat bootstrap lagi (ACCOUNT_ALREADY_HAS_TRUSTED_DEVICE, 400/409).
     */
    public function test_account_with_existing_trusted_device_cannot_bootstrap(): void
    {
        // First device is already trusted
        BchatUserDevice::create([
            'user_id'                => $this->user->id,
            'device_uuid'            => (string) Str::uuid(),
            'device_name'            => 'Laptop Utama',
            'key_version'            => 1,
            'public_key'             => bin2hex(random_bytes(32)),
            'public_key_fingerprint' => hash('sha256', 'dummy'),
            'status'                 => 'ACTIVE',
            'is_trusted'             => true,
            'trusted_at'             => now(),
        ]);

        // Second device untrusted tries to bootstrap
        $secondDevice = $this->createUntrustedActiveDevice($this->user);

        $response = $this->actingAs($this->user, 'sanctum')->postJson(
            "/api/bchat/devices/{$secondDevice->device_uuid}/trust-bootstrap/challenge",
            ['password' => $this->password]
        );

        $response->assertStatus(400)
            ->assertJsonPath('error_code', 'ACCOUNT_ALREADY_HAS_TRUSTED_DEVICE');
    }

    /**
     * Uji 8: Akun yang pernah memiliki trust root tidak dapat melakukan bootstrap pertama lagi (ACCOUNT_PREVIOUSLY_HAD_TRUST_ROOT, 400/409).
     */
    public function test_account_that_previously_had_trusted_device_cannot_bootstrap_first_root(): void
    {
        // A previous device that was trusted, but now revoked
        BchatUserDevice::create([
            'user_id'                => $this->user->id,
            'device_uuid'            => (string) Str::uuid(),
            'device_name'            => 'Old Trusted Device',
            'key_version'            => 1,
            'public_key'             => bin2hex(random_bytes(32)),
            'public_key_fingerprint' => hash('sha256', 'dummy2'),
            'status'                 => 'REVOKED',
            'is_trusted'             => true,
            'trusted_at'             => now()->subDays(5),
            'revoked_at'             => now()->subDay(),
        ]);

        // New untrusted active device
        $device = $this->createUntrustedActiveDevice($this->user);

        $response = $this->actingAs($this->user, 'sanctum')->postJson(
            "/api/bchat/devices/{$device->device_uuid}/trust-bootstrap/challenge",
            ['password' => $this->password]
        );

        $response->assertStatus(400)
            ->assertJsonPath('error_code', 'ACCOUNT_PREVIOUSLY_HAD_TRUST_ROOT');
    }

    /**
     * Uji 9: Dua permintaan bootstrap bersamaan tidak menghasilkan dua trust root.
     */
    public function test_concurrent_bootstrap_requests_cannot_produce_two_trust_roots(): void
    {
        $device1 = $this->createUntrustedActiveDevice($this->user);

        // Create second device for the same user
        $kp2 = sodium_crypto_sign_keypair();
        $pub2 = sodium_crypto_sign_publickey($kp2);
        $priv2 = sodium_crypto_sign_secretkey($kp2);
        $device2 = BchatUserDevice::create([
            'user_id'                => $this->user->id,
            'device_uuid'            => (string) Str::uuid(),
            'device_name'            => 'Second Device',
            'key_version'            => 1,
            'public_key'             => bin2hex($pub2),
            'public_key_fingerprint' => hash('sha256', bin2hex($pub2)),
            'status'                 => 'ACTIVE',
            'is_trusted'             => false,
            'trusted_at'             => null,
        ]);

        // Issue challenges for both
        $res1 = $this->actingAs($this->user, 'sanctum')->postJson(
            "/api/bchat/devices/{$device1->device_uuid}/trust-bootstrap/challenge",
            ['password' => $this->password]
        );
        $res1->assertStatus(200);

        // When device1 succeeds:
        $sig1 = bin2hex(sodium_crypto_sign_detached($res1->json('data.payload_to_sign'), hex2bin($this->privateKeyHex)));
        $verify1 = $this->actingAs($this->user, 'sanctum')->postJson(
            "/api/bchat/devices/{$device1->device_uuid}/trust-bootstrap/verify",
            [
                'challenge_uuid' => $res1->json('data.challenge_uuid'),
                'signature'      => $sig1,
            ]
        );
        $verify1->assertStatus(200);

        // Now device 2 tries to challenge/verify: must fail because device 1 is now trusted!
        $res2 = $this->actingAs($this->user, 'sanctum')->postJson(
            "/api/bchat/devices/{$device2->device_uuid}/trust-bootstrap/challenge",
            ['password' => $this->password]
        );
        $res2->assertStatus(400)
            ->assertJsonPath('error_code', 'ACCOUNT_ALREADY_HAS_TRUSTED_DEVICE');

        // Only 1 device is trusted in DB
        $trustedCount = BchatUserDevice::where('user_id', $this->user->id)->where('is_trusted', true)->count();
        $this->assertEquals(1, $trustedCount);
    }

    /**
     * Uji 10: Challenge tidak dapat digunakan untuk tujuan lain (INVALID_CHALLENGE_PURPOSE).
     */
    public function test_challenge_with_invalid_purpose_is_rejected(): void
    {
        $device = $this->createUntrustedActiveDevice($this->user);

        // Create challenge with different purpose
        $ch = BchatTrustBootstrapChallenge::create([
            'user_id'                => $this->user->id,
            'device_id'              => $device->id,
            'challenge_uuid'         => (string) Str::uuid(),
            'device_uuid'            => $device->device_uuid,
            'public_key_fingerprint' => $device->public_key_fingerprint,
            'challenge_nonce'        => bin2hex(random_bytes(32)),
            'purpose'                => 'WEBSOCKET_AUTH',
            'status'                 => 'ISSUED',
            'expires_at'             => now()->addMinutes(5),
        ]);

        $payload = BchatTrustBootstrapService::buildPayloadToSign($ch->challenge_nonce, $device->device_uuid, $device->public_key_fingerprint);
        $sig = bin2hex(sodium_crypto_sign_detached($payload, hex2bin($this->privateKeyHex)));

        $res = $this->actingAs($this->user, 'sanctum')->postJson(
            "/api/bchat/devices/{$device->device_uuid}/trust-bootstrap/verify",
            [
                'challenge_uuid' => $ch->challenge_uuid,
                'signature'      => $sig,
            ]
        );

        $res->assertStatus(400)
            ->assertJsonPath('error_code', 'INVALID_CHALLENGE_PURPOSE');
    }

    /**
     * Uji 11: Perangkat yang tidak memiliki private key asli tidak dapat bootstrap (kunci privat asing ditolak).
     */
    public function test_device_without_matching_private_key_cannot_bootstrap(): void
    {
        $device = $this->createUntrustedActiveDevice($this->user);

        $resChallenge = $this->actingAs($this->user, 'sanctum')->postJson(
            "/api/bchat/devices/{$device->device_uuid}/trust-bootstrap/challenge",
            ['password' => $this->password]
        );

        $challengeUuid = $resChallenge->json('data.challenge_uuid');
        $payloadToSign = $resChallenge->json('data.payload_to_sign');

        // Sign with a DIFFERENT (unrelated) private key
        $alienKp = sodium_crypto_sign_keypair();
        $alienPriv = sodium_crypto_sign_secretkey($alienKp);
        $alienSig = bin2hex(sodium_crypto_sign_detached($payloadToSign, $alienPriv));

        $resVerify = $this->actingAs($this->user, 'sanctum')->postJson(
            "/api/bchat/devices/{$device->device_uuid}/trust-bootstrap/verify",
            [
                'challenge_uuid' => $challengeUuid,
                'signature'      => $alienSig,
            ]
        );

        $resVerify->assertStatus(422)
            ->assertJsonPath('error_code', 'INVALID_SIGNATURE');

        $device->refresh();
        $this->assertFalse((bool) $device->is_trusted);
    }

    /**
     * Uji 12: Pairing dan autentikasi WebSocket tetap menerapkan pemeriksaan trust normal.
     */
    public function test_pairing_and_ws_auth_still_enforce_normal_trust_checks(): void
    {
        $device = $this->createUntrustedActiveDevice($this->user);

        // 1. Pairing initiation with untrusted device: rejected
        $resPairing = $this->actingAs($this->user, 'sanctum')->postJson(
            '/api/bchat/pairing/request',
            ['target_device_uuid' => $device->device_uuid]
        );
        $resPairing->assertStatus(400)
            ->assertJsonPath('error_code', 'NO_TRUSTED_DEVICE_AVAILABLE');

        // Setup active room with $this->user as member
        $mhsModel = Mahasiswa::create([
            'user_id'         => $this->user->id,
            'NIM'             => $this->user->nomor_induk ?? '1234567890',
            'nama_mahasiswa'  => 'Mahasiswa Test',
            'prodi'           => 'Informatika',
            'status_akademik' => 'aktif',
        ]);

        $skripsi = Skripsi::create([
            'id_mahasiswa' => $mhsModel->id_mahasiswa,
            'judul'        => 'Proposal Hardening Test',
            'status_judul' => 'diajukan',
        ]);

        $dosenUser = User::factory()->create([
            'role'        => 'dosen',
            'nomor_induk' => '9876543210',
        ]);
        $dosenModel = Dosen::create([
            'user_id'    => $dosenUser->id,
            'NIDN'       => $dosenUser->nomor_induk,
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
            'user_id'         => $this->user->id,
            'role'            => 'mahasiswa',
            'is_initiator'    => true,
            'is_active'       => true,
            'joined_at_epoch' => 1,
        ]);

        // 2. WebSocket auth with untrusted device: rejected with 403 DEVICE_NOT_TRUSTED
        $resWs = $this->actingAs($this->user, 'sanctum')->postJson(
            '/api/bchat/broadcasting/challenge',
            [
                'device_uuid'  => $device->device_uuid,
                'socket_id'    => '123.456',
                'channel_name' => "private-bchat.rooms.{$room->room_uuid}",
            ]
        );
        $resWs->assertStatus(403)
            ->assertJsonPath('error_code', 'DEVICE_NOT_TRUSTED');
    }
}
