<?php

declare(strict_types=1);

namespace Tests\Feature\Bchat;

use App\Models\BchatRoom;
use App\Models\BchatRoomMember;
use App\Models\BchatUserDevice;
use App\Models\BchatWelcomeChallenge;
use App\Models\Dosen;
use App\Models\Mahasiswa;
use App\Models\PenugasanPembimbing;
use App\Models\Skripsi;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Feature Test untuk endpoint Welcome Challenge (POST /api/bchat/rooms/{roomUuid}/welcome-challenge).
 *
 * Menguji integrasi end-to-end: otentikasi Sanctum, otorisasi penerima, isolasi peran inisiator,
 * pengambilan tantangan aktif, grace period rotasi (TTL 1-60s), in-place rotation saat kedaluwarsa,
 * serta pencegahan kebocoran data kriptografi.
 */
class BchatWelcomeChallengeFeatureTest extends TestCase
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

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // =========================================================================
    // FIXTURE HELPERS
    // =========================================================================

    private function createTestUser(string $role = 'mahasiswa'): User
    {
        return User::factory()->create([
            'role'        => $role,
            'nomor_induk' => (string) rand(1000000000, 9999999999),
        ]);
    }

    private function createTestDevice(User $user, string $status = 'ACTIVE'): BchatUserDevice
    {
        $keypair = sodium_crypto_sign_keypair();
        $publicKey = sodium_crypto_sign_publickey($keypair);

        return BchatUserDevice::create([
            'user_id'                => $user->id,
            'device_uuid'            => (string) Str::uuid(),
            'device_name'            => 'Feature Test Browser Device',
            'key_version'            => 1,
            'public_key'             => bin2hex($publicKey),
            'public_key_fingerprint' => hash('sha256', $publicKey),
            'status'                 => $status,
            'last_active_at'         => now(),
        ]);
    }

    private function createTestRoomWithMembers(
        User $initiator,
        User $recipient,
        string $assignmentStatus = 'AKTIF',
        string $roomStatus = 'PENDING_WELCOME_ACK'
    ): BchatRoom {
        $mhs = Mahasiswa::create([
            'user_id'        => $initiator->id,
            'NIM'            => $initiator->nomor_induk,
            'nama_mahasiswa' => 'Mahasiswa Test ' . Str::random(4),
            'prodi'          => 'Informatika',
        ]);

        $skripsi = Skripsi::create([
            'id_mahasiswa' => $mhs->id_mahasiswa,
            'judul'        => 'Proposal E2EE Feature Test ' . Str::random(5),
            'status_judul' => 'diajukan',
        ]);

        $dosen = Dosen::create([
            'user_id'    => $recipient->id,
            'NIDN'       => $recipient->nomor_induk,
            'nama_dosen' => 'Dosen Pembimbing Test ' . Str::random(4),
            'prodi'      => 'Informatika',
        ]);

        $penugasan = PenugasanPembimbing::create([
            'skripsi_id'       => $skripsi->id_skripsi,
            'tahap'            => 'proposal',
            'pembimbing_ke'    => 1,
            'dosen_id'         => $dosen->id_dosen,
            'status_penugasan' => $assignmentStatus,
            'tanggal_mulai'    => now()->toDateString(),
        ]);

        $room = BchatRoom::create([
            'room_uuid'               => (string) Str::uuid(),
            'penugasan_pembimbing_id' => $penugasan->id,
            'tahap'                   => 'proposal',
            'pembimbing_ke'           => 1,
            'current_sequence'        => 0,
            'status'                  => $roomStatus,
            'current_epoch'           => 0,
            'group_id_hash'           => hash('sha256', 'group_context_challenge'),
        ]);

        BchatRoomMember::create([
            'room_id'         => $room->id,
            'user_id'         => $initiator->id,
            'role'            => 'mahasiswa',
            'is_initiator'    => true,
            'is_active'       => false,
            'joined_at_epoch' => 0,
        ]);

        BchatRoomMember::create([
            'room_id'         => $room->id,
            'user_id'         => $recipient->id,
            'role'            => 'dosen_pembimbing',
            'is_initiator'    => false,
            'is_active'       => false,
            'joined_at_epoch' => 0,
        ]);

        return $room;
    }

    private function createTestChallenge(
        BchatRoom $room,
        User $recipient,
        BchatUserDevice $recipientDevice,
        ?Carbon $expiresAt = null,
        int $generation = 1
    ): BchatWelcomeChallenge {
        return BchatWelcomeChallenge::create([
            'welcome_operation_uuid' => (string) Str::uuid(),
            'room_id'                => $room->id,
            'recipient_user_id'      => $recipient->id,
            'recipient_device_id'    => $recipientDevice->id,
            'welcome_message'        => 'encrypted_mls_welcome_binary_payload',
            'group_id_hash'          => $room->group_id_hash ?? hash('sha256', 'group_context_challenge'),
            'epoch'                  => 1,
            'operation_status'       => 'ACTIVE',
            'challenge_uuid'         => (string) Str::uuid(),
            'challenge_nonce'        => bin2hex(random_bytes(32)),
            'challenge_generation'   => $generation,
            'challenge_status'       => 'ISSUED',
            'expires_at'             => $expiresAt ?? now()->addMinutes(5),
            'consumed_at'            => null,
        ]);
    }

    // =========================================================================
    // TEST CASES
    // =========================================================================

    /**
     * 1. Pengguna unauthenticated ditolak dengan HTTP 401.
     */
    public function test_01_unauthenticated_request_is_rejected_with_http_401(): void
    {
        $roomUuid = (string) Str::uuid();

        $response = $this->postJson("/api/bchat/rooms/{$roomUuid}/welcome-challenge");

        $response->assertStatus(401);
    }

    /**
     * 2. Pengambilan tantangan sukses oleh recipient yang sah.
     */
    public function test_02_recipient_can_retrieve_active_challenge_successfully(): void
    {
        $initiator = $this->createTestUser('mahasiswa');
        $recipient = $this->createTestUser('dosen');

        $this->createTestDevice($initiator, 'ACTIVE');
        $recipientDevice = $this->createTestDevice($recipient, 'ACTIVE');
        $room = $this->createTestRoomWithMembers($initiator, $recipient);
        $challenge = $this->createTestChallenge($room, $recipient, $recipientDevice);

        Sanctum::actingAs($recipient);

        $response = $this->postJson("/api/bchat/rooms/{$room->room_uuid}/welcome-challenge");

        $response->assertStatus(200);
        $response->assertJson([
            'status'  => 'success',
            'message' => 'Welcome challenge berhasil diambil/diperbarui.',
            'data'    => [
                'welcome_operation_uuid' => $challenge->welcome_operation_uuid,
                'room_uuid'              => $room->room_uuid,
                'welcome_message'        => base64_encode($challenge->welcome_message),
                'group_id_hash'          => $challenge->group_id_hash,
                'epoch'                  => '1',
                'challenge_uuid'         => $challenge->challenge_uuid,
                'challenge_nonce'        => $challenge->challenge_nonce,
                'challenge_generation'   => 1,
            ],
        ]);

        $responseData = $response->json('data');
        $this->assertGreaterThan(0, $responseData['ttl_remaining_seconds']);
        $this->assertEquals('1', $responseData['epoch']);
    }

    /**
     * 3. Inisiator mencoba mengambil challenge ditolak (HTTP 403 / INITIATOR_NOT_ALLOWED_CHALLENGE).
     */
    public function test_03_initiator_retrieval_is_rejected_with_http_403(): void
    {
        $initiator = $this->createTestUser('mahasiswa');
        $recipient = $this->createTestUser('dosen');

        $this->createTestDevice($initiator, 'ACTIVE');
        $recipientDevice = $this->createTestDevice($recipient, 'ACTIVE');
        $room = $this->createTestRoomWithMembers($initiator, $recipient);
        $this->createTestChallenge($room, $recipient, $recipientDevice);

        Sanctum::actingAs($initiator);

        $response = $this->postJson("/api/bchat/rooms/{$room->room_uuid}/welcome-challenge");

        $response->assertStatus(403);
        $response->assertJson([
            'status'     => 'error',
            'error_code' => 'INITIATOR_NOT_ALLOWED_CHALLENGE',
        ]);
    }

    /**
     * 4. Pengguna asing (bukan anggota room) ditolak (HTTP 403 / UNAUTHORIZED_NOT_MEMBER).
     */
    public function test_04_stranger_cannot_retrieve_challenge_returns_403(): void
    {
        $initiator = $this->createTestUser('mahasiswa');
        $recipient = $this->createTestUser('dosen');
        $stranger = $this->createTestUser('dosen');

        $this->createTestDevice($initiator, 'ACTIVE');
        $recipientDevice = $this->createTestDevice($recipient, 'ACTIVE');
        $room = $this->createTestRoomWithMembers($initiator, $recipient);
        $this->createTestChallenge($room, $recipient, $recipientDevice);

        Sanctum::actingAs($stranger);

        $response = $this->postJson("/api/bchat/rooms/{$room->room_uuid}/welcome-challenge");

        $response->assertStatus(403);
        $response->assertJson([
            'status'     => 'error',
            'error_code' => 'UNAUTHORIZED_NOT_MEMBER',
        ]);
    }

    /**
     * 5. Perangkat pemohon tidak aktif ditolak (HTTP 422 / RECIPIENT_DEVICE_NOT_ACTIVE).
     */
    public function test_05_recipient_with_no_active_device_is_rejected_with_http_422(): void
    {
        $initiator = $this->createTestUser('mahasiswa');
        $recipient = $this->createTestUser('dosen');

        $this->createTestDevice($initiator, 'ACTIVE');
        $revokedDevice = $this->createTestDevice($recipient, 'REVOKED');
        $room = $this->createTestRoomWithMembers($initiator, $recipient);
        $this->createTestChallenge($room, $recipient, $revokedDevice);

        Sanctum::actingAs($recipient);

        $response = $this->postJson("/api/bchat/rooms/{$room->room_uuid}/welcome-challenge");

        $response->assertStatus(422);
        $response->assertJson([
            'status'     => 'error',
            'error_code' => 'RECIPIENT_DEVICE_NOT_ACTIVE',
        ]);
    }

    /**
     * 6. Tantangan dengan sisa TTL > 60 detik dikembalikan secara identik tanpa rotasi.
     */
    public function test_06_challenge_with_ttl_above_60s_returns_identical_without_rotation(): void
    {
        $initiator = $this->createTestUser('mahasiswa');
        $recipient = $this->createTestUser('dosen');

        $this->createTestDevice($initiator, 'ACTIVE');
        $recipientDevice = $this->createTestDevice($recipient, 'ACTIVE');
        $room = $this->createTestRoomWithMembers($initiator, $recipient);
        $challenge = $this->createTestChallenge($room, $recipient, $recipientDevice, now()->addSeconds(180));

        Sanctum::actingAs($recipient);

        $responseA = $this->postJson("/api/bchat/rooms/{$room->room_uuid}/welcome-challenge");
        $responseB = $this->postJson("/api/bchat/rooms/{$room->room_uuid}/welcome-challenge");

        $responseA->assertStatus(200);
        $responseB->assertStatus(200);

        $dataA = $responseA->json('data');
        $dataB = $responseB->json('data');

        $this->assertEquals($challenge->challenge_uuid, $dataA['challenge_uuid']);
        $this->assertEquals($dataA['challenge_uuid'], $dataB['challenge_uuid']);
        $this->assertEquals($dataA['challenge_nonce'], $dataB['challenge_nonce']);
        $this->assertEquals(1, $dataA['challenge_generation']);
        $this->assertEquals(1, $dataB['challenge_generation']);
    }

    /**
     * 7. Tantangan dengan sisa TTL antara 1-60 detik dikembalikan secara identik (grace period).
     */
    public function test_07_challenge_with_ttl_between_1_and_60s_returns_identical_without_rotation(): void
    {
        $initiator = $this->createTestUser('mahasiswa');
        $recipient = $this->createTestUser('dosen');

        $this->createTestDevice($initiator, 'ACTIVE');
        $recipientDevice = $this->createTestDevice($recipient, 'ACTIVE');
        $room = $this->createTestRoomWithMembers($initiator, $recipient);
        $challenge = $this->createTestChallenge($room, $recipient, $recipientDevice, now()->addSeconds(30));

        Sanctum::actingAs($recipient);

        $response = $this->postJson("/api/bchat/rooms/{$room->room_uuid}/welcome-challenge");

        $response->assertStatus(200);
        $data = $response->json('data');

        $this->assertEquals($challenge->challenge_uuid, $data['challenge_uuid']);
        $this->assertEquals($challenge->challenge_nonce, $data['challenge_nonce']);
        $this->assertEquals(1, $data['challenge_generation']);
    }

    /**
     * 8. Tantangan kedaluwarsa (TTL <= 0) dirotasi in-place dengan generation +1 dan metadata induk immutable.
     */
    public function test_08_expired_challenge_is_rotated_in_place_with_generation_increment(): void
    {
        $initiator = $this->createTestUser('mahasiswa');
        $recipient = $this->createTestUser('dosen');

        $this->createTestDevice($initiator, 'ACTIVE');
        $recipientDevice = $this->createTestDevice($recipient, 'ACTIVE');
        $room = $this->createTestRoomWithMembers($initiator, $recipient);

        // Buat challenge yang sudah kedaluwarsa 10 detik lalu
        $oldExpiresAt = now()->subSeconds(10);
        $challenge = $this->createTestChallenge($room, $recipient, $recipientDevice, $oldExpiresAt, 1);
        $initialUuid = $challenge->challenge_uuid;
        $initialNonce = $challenge->challenge_nonce;
        $initialOpUuid = $challenge->welcome_operation_uuid;

        Sanctum::actingAs($recipient);

        $response = $this->postJson("/api/bchat/rooms/{$room->room_uuid}/welcome-challenge");

        $response->assertStatus(200);
        $data = $response->json('data');

        // Verifikasi rotasi atribut tantangan
        $this->assertNotEquals($initialUuid, $data['challenge_uuid']);
        $this->assertNotEquals($initialNonce, $data['challenge_nonce']);
        $this->assertEquals(2, $data['challenge_generation']);

        // Verifikasi metadata induk tetap immutable
        $this->assertEquals($initialOpUuid, $data['welcome_operation_uuid']);
        $this->assertEquals($room->room_uuid, $data['room_uuid']);
        $this->assertEquals('1', $data['epoch']);
        $this->assertEquals(base64_encode($challenge->welcome_message), $data['welcome_message']);

        // Verifikasi tidak ada baris baru di database (rotasi in-place)
        $this->assertEquals(1, BchatWelcomeChallenge::where('room_id', $room->id)->count());
    }

    /**
     * 9. Isolasi data: tidak ada kebocoran Welcome message atau challenge nonce ke pihak ketiga.
     */
    public function test_09_data_isolation_prevents_leakage_to_unauthorized_parties(): void
    {
        $initiator = $this->createTestUser('mahasiswa');
        $recipient = $this->createTestUser('dosen');
        $unrelatedUser = $this->createTestUser('mahasiswa');

        $this->createTestDevice($initiator, 'ACTIVE');
        $recipientDevice = $this->createTestDevice($recipient, 'ACTIVE');
        $room = $this->createTestRoomWithMembers($initiator, $recipient);
        $challenge = $this->createTestChallenge($room, $recipient, $recipientDevice);

        Sanctum::actingAs($unrelatedUser);

        $response = $this->postJson("/api/bchat/rooms/{$room->room_uuid}/welcome-challenge");

        $response->assertStatus(403);

        $body = $response->getContent();
        $this->assertStringNotContainsString($challenge->challenge_nonce, $body);
        $this->assertStringNotContainsString(base64_encode($challenge->welcome_message), $body);
    }
}

