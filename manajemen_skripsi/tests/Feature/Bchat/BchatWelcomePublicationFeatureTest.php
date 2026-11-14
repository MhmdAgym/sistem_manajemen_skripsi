<?php

declare(strict_types=1);

namespace Tests\Feature\Bchat;

use App\Models\BchatKeyPackage;
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
 * Feature Test untuk endpoint Welcome Publication (POST /api/bchat/rooms/{roomUuid}/welcome).
 *
 * Menguji integrasi end-to-end: otentikasi Sanctum, otorisasi inisiator, FormRequest validation,
 * validasi Base64 kanonikal RFC 4648, batas ukuran payload, konsumsi KeyPackage atomik,
 * lock hierarchy, serta konsistensi serialisasi decimal string.
 */
class BchatWelcomePublicationFeatureTest extends TestCase
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
            'consumed_at'      => $isConsumed ? now()->subMinute() : null,
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
            'group_id_hash'           => null,
        ]);

        BchatRoomMember::create([
            'room_id'         => $room->id,
            'user_id'         => $initiator->id,
            'role'            => 'mahasiswa',
            'is_initiator'    => false,
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

    // =========================================================================
    // TEST CASES
    // =========================================================================

    /**
     * 1. Pengguna unauthenticated ditolak dengan HTTP 401.
     */
    public function test_01_unauthenticated_request_is_rejected_with_http_401(): void
    {
        $roomUuid = (string) Str::uuid();

        $response = $this->postJson("/api/bchat/rooms/{$roomUuid}/welcome", [
            'recipient_device_uuid' => (string) Str::uuid(),
            'key_package_hash'      => hash('sha256', 'dummy_kp'),
            'group_id_hash'         => hash('sha256', 'dummy_group'),
            'epoch'                 => 1,
            'welcome_message'       => base64_encode('dummy_payload'),
        ]);

        $response->assertStatus(401);
    }

    /**
     * 2. Publikasi sukses oleh inisiator yang sah dengan seluruh invarian terpenuhi.
     */
    public function test_02_initiator_can_publish_welcome_successfully(): void
    {
        $initiator = $this->createTestUser('mahasiswa');
        $recipient = $this->createTestUser('dosen');

        $this->createTestDevice($initiator, 'ACTIVE');
        $recipientDevice = $this->createTestDevice($recipient, 'ACTIVE');
        $keyPackage = $this->createTestKeyPackage($recipient, $recipientDevice, false);
        $room = $this->createTestRoomWithMembers($initiator, $recipient);

        Sanctum::actingAs($initiator);

        $groupIdHash = hash('sha256', 'group_context_sample');
        $rawWelcomeBinary = 'mls_welcome_encrypted_message_binary_bytes_12345';
        $welcomeBase64 = base64_encode($rawWelcomeBinary);

        $payload = [
            'recipient_device_uuid' => $recipientDevice->device_uuid,
            'key_package_hash'      => $keyPackage->key_package_hash,
            'group_id_hash'         => $groupIdHash,
            'epoch'                 => 1,
            'welcome_message'       => $welcomeBase64,
        ];

        $response = $this->postJson("/api/bchat/rooms/{$room->room_uuid}/welcome", $payload);

        $response->assertStatus(200);
        $response->assertJson([
            'status'  => 'success',
            'message' => 'Payload Welcome E2EE berhasil dipublikasikan.',
            'data'    => [
                'room_uuid'             => $room->room_uuid,
                'recipient_user_id'     => (string) $recipient->id,
                'recipient_device_uuid' => $recipientDevice->device_uuid,
                'epoch'                 => '1',
                'challenge_generation'  => 1,
            ],
        ]);

        // Verifikasi database: Welcome challenge terbuat aktif
        $challenge = BchatWelcomeChallenge::where('room_id', $room->id)->first();
        $this->assertNotNull($challenge);
        $this->assertEquals('ACTIVE', $challenge->operation_status);
        $this->assertEquals('ISSUED', $challenge->challenge_status);
        $this->assertEquals(1, $challenge->challenge_generation);
        $this->assertEquals($recipient->id, $challenge->recipient_user_id);
        $this->assertEquals($recipientDevice->id, $challenge->recipient_device_id);
        $this->assertEquals($rawWelcomeBinary, $challenge->welcome_message);

        // Verifikasi KeyPackage terkonsumsi
        $freshKeyPkg = $keyPackage->fresh();
        $this->assertTrue((bool) $freshKeyPkg->is_consumed);
        $this->assertNotNull($freshKeyPkg->consumed_at);

        // Verifikasi room tetap PENDING_WELCOME_ACK dengan epoch 0
        $freshRoom = $room->fresh();
        $this->assertEquals('PENDING_WELCOME_ACK', $freshRoom->status);
        $this->assertEquals(0, $freshRoom->current_epoch);
        $this->assertEquals($groupIdHash, $freshRoom->group_id_hash);
    }

    /**
     * 3. Pengguna bukan anggota room ditolak (HTTP 403 / UNAUTHORIZED_NOT_MEMBER).
     */
    public function test_03_stranger_cannot_publish_welcome_returns_403(): void
    {
        $initiator = $this->createTestUser('mahasiswa');
        $recipient = $this->createTestUser('dosen');
        $stranger = $this->createTestUser('mahasiswa');

        $this->createTestDevice($initiator, 'ACTIVE');
        $recipientDevice = $this->createTestDevice($recipient, 'ACTIVE');
        $keyPackage = $this->createTestKeyPackage($recipient, $recipientDevice, false);
        $room = $this->createTestRoomWithMembers($initiator, $recipient);

        Sanctum::actingAs($stranger);

        $payload = [
            'recipient_device_uuid' => $recipientDevice->device_uuid,
            'key_package_hash'      => $keyPackage->key_package_hash,
            'group_id_hash'         => hash('sha256', 'group_stranger_test'),
            'epoch'                 => 1,
            'welcome_message'       => base64_encode('mls_welcome_bytes'),
        ];

        $response = $this->postJson("/api/bchat/rooms/{$room->room_uuid}/welcome", $payload);

        $response->assertStatus(403);
        $response->assertJson([
            'status'     => 'error',
            'error_code' => 'UNAUTHORIZED_NOT_MEMBER',
        ]);
    }

    /**
     * 4. Anggota penerima yang mencoba mempublikasikan kembali saat operasi aktif ada ditolak (HTTP 409).
     */
    public function test_04_publishing_when_active_operation_exists_returns_409(): void
    {
        $initiator = $this->createTestUser('mahasiswa');
        $recipient = $this->createTestUser('dosen');

        $this->createTestDevice($initiator, 'ACTIVE');
        $recipientDevice = $this->createTestDevice($recipient, 'ACTIVE');
        $keyPackage = $this->createTestKeyPackage($recipient, $recipientDevice, false);
        $room = $this->createTestRoomWithMembers($initiator, $recipient);

        // Buat operasi aktif yang sudah eksis
        BchatWelcomeChallenge::create([
            'welcome_operation_uuid' => (string) Str::uuid(),
            'room_id'                => $room->id,
            'recipient_user_id'      => $recipient->id,
            'recipient_device_id'    => $recipientDevice->id,
            'welcome_message'        => 'existing_binary',
            'group_id_hash'          => hash('sha256', 'existing_group'),
            'epoch'                  => 1,
            'operation_status'       => 'ACTIVE',
            'challenge_uuid'         => (string) Str::uuid(),
            'challenge_nonce'        => bin2hex(random_bytes(32)),
            'challenge_generation'   => 1,
            'challenge_status'       => 'ISSUED',
            'expires_at'             => now()->addMinutes(5),
        ]);

        Sanctum::actingAs($initiator);

        $payload = [
            'recipient_device_uuid' => $recipientDevice->device_uuid,
            'key_package_hash'      => $keyPackage->key_package_hash,
            'group_id_hash'         => hash('sha256', 'group_second_try'),
            'epoch'                 => 1,
            'welcome_message'       => base64_encode('mls_welcome_bytes_second'),
        ];

        $response = $this->postJson("/api/bchat/rooms/{$room->room_uuid}/welcome", $payload);

        $response->assertStatus(409);
        $response->assertJson([
            'status'     => 'error',
            'error_code' => 'ACTIVE_WELCOME_OPERATION_EXISTS',
        ]);
    }

    /**
     * 5. Payload welcome_message dengan Base64 tidak valid atau non-kanonikal ditolak (HTTP 422).
     */
    public function test_05_invalid_or_non_canonical_base64_payload_rejected_with_http_422(): void
    {
        $initiator = $this->createTestUser('mahasiswa');
        $roomUuid = (string) Str::uuid();

        Sanctum::actingAs($initiator);

        // Skenario A: Format bukan base64 valid
        $responseA = $this->postJson("/api/bchat/rooms/{$roomUuid}/welcome", [
            'recipient_device_uuid' => (string) Str::uuid(),
            'key_package_hash'      => hash('sha256', 'dummy_kp'),
            'group_id_hash'         => hash('sha256', 'dummy_group'),
            'epoch'                 => 1,
            'welcome_message'       => '@@not_valid_base64$$',
        ]);
        $responseA->assertStatus(422);
        $responseA->assertJsonValidationErrors(['welcome_message']);

        // Skenario B: Base64 dengan bit residual / non-canonical padding
        // "Zm9v" didekode menjadi "foo", tetapi "Zm9v=" memiliki bit padding ilegal
        $responseB = $this->postJson("/api/bchat/rooms/{$roomUuid}/welcome", [
            'recipient_device_uuid' => (string) Str::uuid(),
            'key_package_hash'      => hash('sha256', 'dummy_kp'),
            'group_id_hash'         => hash('sha256', 'dummy_group'),
            'epoch'                 => 1,
            'welcome_message'       => 'Zm9v=',
        ]);
        $responseB->assertStatus(422);
        $responseB->assertJsonValidationErrors(['welcome_message']);
    }

    /**
     * 6. Payload welcome_message melebihi batas ukuran ditolak (HTTP 422).
     */
    public function test_06_payload_exceeding_max_allowed_size_is_rejected_with_http_422(): void
    {
        $initiator = $this->createTestUser('mahasiswa');
        $roomUuid = (string) Str::uuid();

        Sanctum::actingAs($initiator);

        // Pre-decode guard: string Base64 melebihi 349.528 karakter
        $oversizedString = str_repeat('A', 349529);

        $response = $this->postJson("/api/bchat/rooms/{$roomUuid}/welcome", [
            'recipient_device_uuid' => (string) Str::uuid(),
            'key_package_hash'      => hash('sha256', 'dummy_kp'),
            'group_id_hash'         => hash('sha256', 'dummy_group'),
            'epoch'                 => 1,
            'welcome_message'       => $oversizedString,
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['welcome_message']);
    }

    /**
     * 7. KeyPackage yang sudah pernah dikonsumsi ditolak (HTTP 409).
     */
    public function test_07_consumed_key_package_is_rejected(): void
    {
        $initiator = $this->createTestUser('mahasiswa');
        $recipient = $this->createTestUser('dosen');

        $this->createTestDevice($initiator, 'ACTIVE');
        $recipientDevice = $this->createTestDevice($recipient, 'ACTIVE');
        $consumedKeyPackage = $this->createTestKeyPackage($recipient, $recipientDevice, true);
        $room = $this->createTestRoomWithMembers($initiator, $recipient);

        Sanctum::actingAs($initiator);

        $payload = [
            'recipient_device_uuid' => $recipientDevice->device_uuid,
            'key_package_hash'      => $consumedKeyPackage->key_package_hash,
            'group_id_hash'         => hash('sha256', 'group_consumed_test'),
            'epoch'                 => 1,
            'welcome_message'       => base64_encode('mls_welcome_bytes'),
        ];

        $response = $this->postJson("/api/bchat/rooms/{$room->room_uuid}/welcome", $payload);

        $response->assertStatus(409);
        $response->assertJson([
            'status'     => 'error',
            'error_code' => 'KEY_PACKAGE_ALREADY_CONSUMED',
        ]);
    }

    /**
     * 8. Epoch target bukan 1 ditolak oleh FormRequest (HTTP 422).
     */
    public function test_08_invalid_target_epoch_is_rejected_with_http_422(): void
    {
        $initiator = $this->createTestUser('mahasiswa');
        $roomUuid = (string) Str::uuid();

        Sanctum::actingAs($initiator);

        $response = $this->postJson("/api/bchat/rooms/{$roomUuid}/welcome", [
            'recipient_device_uuid' => (string) Str::uuid(),
            'key_package_hash'      => hash('sha256', 'dummy_kp'),
            'group_id_hash'         => hash('sha256', 'dummy_group'),
            'epoch'                 => 2, // Salah, target Welcome pertama wajib 1
            'welcome_message'       => base64_encode('valid_payload'),
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['epoch']);
    }

    /**
     * 9. Rollback integritas transaksi: KeyPackage tetap unconsumed jika publikasi gagal.
     */
    public function test_09_transaction_rollback_preserves_unconsumed_key_package_on_failure(): void
    {
        $initiator = $this->createTestUser('mahasiswa');
        $recipient = $this->createTestUser('dosen');

        $this->createTestDevice($initiator, 'ACTIVE');
        $recipientDevice = $this->createTestDevice($recipient, 'ACTIVE');
        $keyPackage = $this->createTestKeyPackage($recipient, $recipientDevice, false);

        // Buat room dengan status sudah ACTIVE sehingga validasi status gagal
        $room = $this->createTestRoomWithMembers($initiator, $recipient, 'AKTIF', 'ACTIVE');

        Sanctum::actingAs($initiator);

        $payload = [
            'recipient_device_uuid' => $recipientDevice->device_uuid,
            'key_package_hash'      => $keyPackage->key_package_hash,
            'group_id_hash'         => hash('sha256', 'group_rollback_test'),
            'epoch'                 => 1,
            'welcome_message'       => base64_encode('mls_welcome_bytes'),
        ];

        $response = $this->postJson("/api/bchat/rooms/{$room->room_uuid}/welcome", $payload);

        $response->assertStatus(409);
        $response->assertJson([
            'status'     => 'error',
            'error_code' => 'INVALID_ROOM_STATUS',
        ]);

        // Verifikasi bahwa KeyPackage TIDAK ikut terkonsumsi (rollback sukses)
        $freshKeyPkg = $keyPackage->fresh();
        $this->assertFalse((bool) $freshKeyPkg->is_consumed);
        $this->assertNull($freshKeyPkg->consumed_at);
    }
}

