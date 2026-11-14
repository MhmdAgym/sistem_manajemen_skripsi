<?php

declare(strict_types=1);

namespace Tests\Feature\Bchat;

use App\Events\BchatRoomActivated;
use App\Models\BchatRoom;
use App\Models\BchatRoomMember;
use App\Models\BchatUserDevice;
use App\Models\BchatWelcomeChallenge;
use App\Models\Dosen;
use App\Models\Mahasiswa;
use App\Models\PenugasanPembimbing;
use App\Models\Skripsi;
use App\Models\User;
use App\Services\BchatWelcomeAckVerificationService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Feature Test untuk endpoint Welcome ACK (POST /api/bchat/rooms/{roomUuid}/welcome-ack).
 *
 * Menguji integrasi end-to-end: routing, middleware auth:sanctum, throttling,
 * request validation, service delegation, JSON serialization, dan Reverb broadcasting.
 */
class BchatWelcomeAckFeatureTest extends TestCase
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

    private function createTestDevice(User $user, string $status = 'ACTIVE'): array
    {
        $keypair = sodium_crypto_sign_keypair();
        $secretKey = sodium_crypto_sign_secretkey($keypair);
        $publicKey = sodium_crypto_sign_publickey($keypair);

        $device = BchatUserDevice::create([
            'user_id'                => $user->id,
            'device_uuid'            => (string) Str::uuid(),
            'device_name'            => 'Feature Test Browser Device',
            'key_version'            => 1,
            'public_key'             => bin2hex($publicKey),
            'public_key_fingerprint' => hash('sha256', $publicKey),
            'status'                 => $status,
            'last_active_at'         => now(),
        ]);

        return [
            'device'    => $device,
            'keypair'   => $keypair,
            'secretKey' => $secretKey,
            'publicKey' => $publicKey,
        ];
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
            'group_id_hash'           => hash('sha256', 'group_context_feature'),
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
        BchatUserDevice $recipientDevice
    ): BchatWelcomeChallenge {
        return BchatWelcomeChallenge::create([
            'welcome_operation_uuid' => (string) Str::uuid(),
            'room_id'                => $room->id,
            'recipient_user_id'      => $recipient->id,
            'recipient_device_id'    => $recipientDevice->id,
            'welcome_message'        => 'encrypted_welcome_binary_payload',
            'group_id_hash'          => $room->group_id_hash ?? hash('sha256', 'group_context_feature'),
            'epoch'                  => 1,
            'operation_status'       => 'ACTIVE',
            'challenge_uuid'         => (string) Str::uuid(),
            'challenge_nonce'        => bin2hex(random_bytes(32)),
            'challenge_generation'   => 1,
            'challenge_status'       => 'ISSUED',
            'expires_at'             => now()->addMinutes(5),
            'consumed_at'            => null,
        ]);
    }

    private function generateValidSignature(
        BchatRoom $room,
        User $recipient,
        BchatWelcomeChallenge $challenge,
        string $secretKey
    ): string {
        $canonicalPayload = BchatWelcomeAckVerificationService::buildPayload(
            roomUuid: $room->room_uuid,
            recipientUserId: (int) $recipient->id,
            welcomeOperationUuid: $challenge->welcome_operation_uuid,
            challengeUuid: $challenge->challenge_uuid,
            challengeGeneration: (int) $challenge->challenge_generation,
            groupIdHashHexOrBin: $challenge->group_id_hash,
            epoch: 1,
            challengeNonceHexOrBin: $challenge->challenge_nonce
        );

        $sigBin = sodium_crypto_sign_detached($canonicalPayload, $secretKey);

        return base64_encode($sigBin);
    }

    // =========================================================================
    // FEATURE TESTS
    // =========================================================================

    /**
     * 1. Pengguna tanpa autentikasi (tamu) ditolak dengan HTTP 401.
     */
    public function test_01_guest_cannot_access_welcome_ack_endpoint(): void
    {
        $roomUuid = (string) Str::uuid();

        $response = $this->postJson("/api/bchat/rooms/{$roomUuid}/welcome-ack", [
            'challenge_uuid' => (string) Str::uuid(),
            'signature'      => base64_encode(random_bytes(64)),
        ]);

        $response->assertStatus(401);
    }

    /**
     * 2. Validasi format request HTTP (FormRequest) menolak input kosong atau tidak valid (HTTP 422).
     */
    public function test_02_validation_error_on_missing_or_malformed_fields(): void
    {
        $user = $this->createTestUser('dosen');
        Sanctum::actingAs($user);
        $roomUuid = (string) Str::uuid();

        // Kosong tanpa body
        $response = $this->postJson("/api/bchat/rooms/{$roomUuid}/welcome-ack", []);
        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['challenge_uuid', 'signature']);

        // Challenge UUID bukan UUID dan Signature bukan Base64 kanonikal 64 byte
        $response2 = $this->postJson("/api/bchat/rooms/{$roomUuid}/welcome-ack", [
            'challenge_uuid' => 'bukan-uuid',
            'signature'      => 'pendek',
        ]);
        $response2->assertStatus(422);
        $response2->assertJsonValidationErrors(['challenge_uuid', 'signature']);
    }

    /**
     * 3. Endpoint Welcome ACK sukses mengaktifkan room dan mengembalikan JSON 200 standar.
     */
    public function test_03_authenticated_recipient_submits_valid_ack_succeeds(): void
    {
        Event::fake([BchatRoomActivated::class]);

        $initiator = $this->createTestUser('mahasiswa');
        $recipient = $this->createTestUser('dosen');
        $deviceData = $this->createTestDevice($recipient);
        $room = $this->createTestRoomWithMembers($initiator, $recipient);
        $challenge = $this->createTestChallenge($room, $recipient, $deviceData['device']);

        $signatureBase64 = $this->generateValidSignature($room, $recipient, $challenge, $deviceData['secretKey']);

        Sanctum::actingAs($recipient);

        $response = $this->postJson("/api/bchat/rooms/{$room->room_uuid}/welcome-ack", [
            'challenge_uuid' => $challenge->challenge_uuid,
            'signature'      => $signatureBase64,
        ]);

        $response->assertStatus(200);
        $response->assertJson([
            'status'  => 'success',
            'message' => 'Welcome ACK berhasil diverifikasi dan ruang obrolan telah aktif.',
            'data'    => [
                'status'        => 'success',
                'room_status'   => 'ACTIVE',
                'current_epoch' => '1',
                'room_uuid'     => $room->room_uuid,
            ],
        ]);

        // Verifikasi database aktif
        $room->refresh();
        $this->assertSame('ACTIVE', $room->status);
        $this->assertSame(1, (int) $room->current_epoch);
    }

    /**
     * 4. Inisiator mencoba memanggil endpoint Welcome ACK ditolak dengan HTTP 403.
     */
    public function test_04_initiator_calling_welcome_ack_endpoint_rejected_with_403(): void
    {
        $initiator = $this->createTestUser('mahasiswa');
        $recipient = $this->createTestUser('dosen');
        $deviceData = $this->createTestDevice($recipient);
        $room = $this->createTestRoomWithMembers($initiator, $recipient);
        $challenge = $this->createTestChallenge($room, $recipient, $deviceData['device']);

        $signatureBase64 = $this->generateValidSignature($room, $recipient, $challenge, $deviceData['secretKey']);

        Sanctum::actingAs($initiator); // Login sebagai inisiator

        $response = $this->postJson("/api/bchat/rooms/{$room->room_uuid}/welcome-ack", [
            'challenge_uuid' => $challenge->challenge_uuid,
            'signature'      => $signatureBase64,
        ]);

        $response->assertStatus(403);
        $response->assertJson([
            'status'     => 'error',
            'error_code' => 'INITIATOR_NOT_ALLOWED_ACK',
        ]);
    }

    /**
     * 5. Pengguna luar yang bukan anggota room ditolak dengan HTTP 403.
     */
    public function test_05_unauthorized_non_member_rejected_with_403(): void
    {
        $initiator = $this->createTestUser('mahasiswa');
        $recipient = $this->createTestUser('dosen');
        $stranger = $this->createTestUser('dosen');
        $deviceData = $this->createTestDevice($recipient);
        $room = $this->createTestRoomWithMembers($initiator, $recipient);
        $challenge = $this->createTestChallenge($room, $recipient, $deviceData['device']);

        $signatureBase64 = $this->generateValidSignature($room, $recipient, $challenge, $deviceData['secretKey']);

        Sanctum::actingAs($stranger);

        $response = $this->postJson("/api/bchat/rooms/{$room->room_uuid}/welcome-ack", [
            'challenge_uuid' => $challenge->challenge_uuid,
            'signature'      => $signatureBase64,
        ]);

        $response->assertStatus(403);
        $response->assertJson([
            'status'     => 'error',
            'error_code' => 'UNAUTHORIZED_NOT_MEMBER',
        ]);
    }

    /**
     * 6. Signature tidak valid ditolak dengan HTTP 422 SIGNATURE_VERIFICATION_FAILED.
     */
    public function test_06_invalid_signature_returns_422(): void
    {
        $initiator = $this->createTestUser('mahasiswa');
        $recipient = $this->createTestUser('dosen');
        $deviceData = $this->createTestDevice($recipient);
        $room = $this->createTestRoomWithMembers($initiator, $recipient);
        $challenge = $this->createTestChallenge($room, $recipient, $deviceData['device']);

        // Signature acak 64 byte valid base64 tapi bukan ditandatangani oleh device penerima
        $invalidSig = base64_encode(random_bytes(64));

        Sanctum::actingAs($recipient);

        $response = $this->postJson("/api/bchat/rooms/{$room->room_uuid}/welcome-ack", [
            'challenge_uuid' => $challenge->challenge_uuid,
            'signature'      => $invalidSig,
        ]);

        $response->assertStatus(422);
        $response->assertJson([
            'status'     => 'error',
            'error_code' => 'SIGNATURE_VERIFICATION_FAILED',
        ]);
    }

    /**
     * 7. Replay attack: Pemanggilan berulang setelah sukses ditolak dengan HTTP 409.
     */
    public function test_07_replay_attack_rejected_with_409(): void
    {
        $initiator = $this->createTestUser('mahasiswa');
        $recipient = $this->createTestUser('dosen');
        $deviceData = $this->createTestDevice($recipient);
        $room = $this->createTestRoomWithMembers($initiator, $recipient);
        $challenge = $this->createTestChallenge($room, $recipient, $deviceData['device']);

        $signatureBase64 = $this->generateValidSignature($room, $recipient, $challenge, $deviceData['secretKey']);

        Sanctum::actingAs($recipient);

        // Request 1 sukses
        $res1 = $this->postJson("/api/bchat/rooms/{$room->room_uuid}/welcome-ack", [
            'challenge_uuid' => $challenge->challenge_uuid,
            'signature'      => $signatureBase64,
        ]);
        $res1->assertStatus(200);

        // Request 2 replay ditolak
        $res2 = $this->postJson("/api/bchat/rooms/{$room->room_uuid}/welcome-ack", [
            'challenge_uuid' => $challenge->challenge_uuid,
            'signature'      => $signatureBase64,
        ]);
        $res2->assertStatus(409);
    }
}

