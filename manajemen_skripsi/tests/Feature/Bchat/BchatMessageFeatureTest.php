<?php

declare(strict_types=1);

namespace Tests\Feature\Bchat;

use App\Events\BchatMessageSent;
use App\Models\BchatMessage;
use App\Models\BchatRoom;
use App\Models\BchatRoomMember;
use App\Models\BchatUserDevice;
use App\Models\Dosen;
use App\Models\Mahasiswa;
use App\Models\PenugasanPembimbing;
use App\Models\Skripsi;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Feature Test untuk Transport Pesan Aplikasi B-CHAT E2EE (I-04B.4R).
 *
 * Menguji integrasi end-to-end:
 * - POST /api/bchat/rooms/{roomUuid}/messages (Pengiriman ciphertext terenkripsi, idempotensi, integritas SHA-256, Reverb event broadcast)
 * - GET /api/bchat/rooms/{roomUuid}/messages (Pengambilan ciphertext dengan cursor pagination after_sequence)
 * - Otorisasi ketat via BchatMessagePolicy dan BchatRoomPolicy.
 */
class BchatMessageFeatureTest extends TestCase
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

    private function createActiveRoomWithMembers(
        User $initiator,
        User $recipient,
        string $assignmentStatus = 'AKTIF',
        string $roomStatus = 'ACTIVE'
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
            'current_epoch'           => 1,
            'group_id_hash'           => hash('sha256', 'group_context_feature_' . Str::random(6)),
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

    private function generatePayload(string $rawBytes = 'mls_dummy_ciphertext_bytes'): array
    {
        $b64 = base64_encode($rawBytes);
        $hash = hash('sha256', $rawBytes);

        return [
            'message_uuid'        => (string) Str::uuid(),
            'epoch'               => 1,
            'generation'          => 0,
            'content_type'        => 1,
            'ciphertext_payload'  => $b64,
            'payload_hash_sha256' => $hash,
            'client_sent_at'      => now()->toIso8601String(),
        ];
    }

    // =========================================================================
    // FEATURE TESTS
    // =========================================================================

    public function test_active_member_can_send_message_and_event_is_broadcasted(): void
    {
        Event::fake([BchatMessageSent::class]);

        $mhs = $this->createTestUser('mahasiswa');
        $dosen = $this->createTestUser('dosen');
        $room = $this->createActiveRoomWithMembers($mhs, $dosen);

        Sanctum::actingAs($mhs);

        $payload = $this->generatePayload('test_mls_application_message');

        $response = $this->postJson("/api/bchat/rooms/{$room->room_uuid}/messages", $payload);

        $response->assertStatus(201)
            ->assertJson([
                'status'  => 'success',
                'message' => 'Pesan E2EE berhasil dikirim dan disimpan.',
                'data'    => [
                    'server_sequence'     => 1,
                    'message_uuid'        => $payload['message_uuid'],
                    'sender_id'           => $mhs->id,
                    'epoch'               => 1,
                    'generation'          => 0,
                    'content_type'        => 1,
                    'ciphertext_payload'  => $payload['ciphertext_payload'],
                    'payload_hash_sha256' => $payload['payload_hash_sha256'],
                ],
            ]);

        // Verifikasi persistensi database
        $this->assertDatabaseHas('bchat_messages', [
            'room_id'             => $room->id,
            'sender_id'           => $mhs->id,
            'message_uuid'        => $payload['message_uuid'],
            'server_sequence'     => 1,
            'payload_hash_sha256' => $payload['payload_hash_sha256'],
        ]);

        // Verifikasi update sequence pada room
        $this->assertEquals(1, $room->fresh()->current_sequence);

        // Verifikasi broadcast event HANYA berisi metadata
        Event::assertDispatched(BchatMessageSent::class, function (BchatMessageSent $event) use ($room, $payload, $mhs) {
            $data = $event->broadcastWith();
            return $data['room_uuid'] === $room->room_uuid
                && $data['server_sequence'] === 1
                && $data['message_uuid'] === $payload['message_uuid']
                && $data['sender_id'] === $mhs->id
                && !array_key_exists('ciphertext_payload', $data)
                && !array_key_exists('plaintext', $data);
        });
    }

    public function test_send_message_requires_authentication(): void
    {
        $mhs = $this->createTestUser('mahasiswa');
        $dosen = $this->createTestUser('dosen');
        $room = $this->createActiveRoomWithMembers($mhs, $dosen);

        $payload = $this->generatePayload();

        $response = $this->postJson("/api/bchat/rooms/{$room->room_uuid}/messages", $payload);

        $response->assertStatus(401);
    }

    public function test_non_member_cannot_send_message(): void
    {
        $mhs = $this->createTestUser('mahasiswa');
        $dosen = $this->createTestUser('dosen');
        $outsider = $this->createTestUser('mahasiswa');
        $room = $this->createActiveRoomWithMembers($mhs, $dosen);

        Sanctum::actingAs($outsider);

        $payload = $this->generatePayload();

        $response = $this->postJson("/api/bchat/rooms/{$room->room_uuid}/messages", $payload);

        $response->assertStatus(403)
            ->assertJson([
                'status'     => 'error',
                'error_code' => 'FORBIDDEN_CANNOT_SEND_MESSAGE',
            ]);
    }

    public function test_inactive_room_rejects_send_message(): void
    {
        $mhs = $this->createTestUser('mahasiswa');
        $dosen = $this->createTestUser('dosen');
        // Room status PENDING_WELCOME_ACK
        $room = $this->createActiveRoomWithMembers($mhs, $dosen, 'AKTIF', 'PENDING_WELCOME_ACK');

        Sanctum::actingAs($mhs);

        $payload = $this->generatePayload();

        $response = $this->postJson("/api/bchat/rooms/{$room->room_uuid}/messages", $payload);

        $response->assertStatus(403)
            ->assertJson([
                'status'     => 'error',
                'error_code' => 'FORBIDDEN_CANNOT_SEND_MESSAGE',
            ]);
    }

    public function test_send_message_validates_sha256_hash_integrity(): void
    {
        $mhs = $this->createTestUser('mahasiswa');
        $dosen = $this->createTestUser('dosen');
        $room = $this->createActiveRoomWithMembers($mhs, $dosen);

        Sanctum::actingAs($mhs);

        $payload = $this->generatePayload();
        // Tamper hash
        $payload['payload_hash_sha256'] = str_repeat('a', 64);

        $response = $this->postJson("/api/bchat/rooms/{$room->room_uuid}/messages", $payload);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['payload_hash_sha256']);
    }

    public function test_send_message_monotonic_sequence_increment(): void
    {
        $mhs = $this->createTestUser('mahasiswa');
        $dosen = $this->createTestUser('dosen');
        $room = $this->createActiveRoomWithMembers($mhs, $dosen);

        Sanctum::actingAs($mhs);

        $payload1 = $this->generatePayload('first_message');
        $res1 = $this->postJson("/api/bchat/rooms/{$room->room_uuid}/messages", $payload1);
        $res1->assertStatus(201);
        $this->assertEquals(1, $res1->json('data.server_sequence'));

        Sanctum::actingAs($dosen);

        $payload2 = $this->generatePayload('second_reply');
        $res2 = $this->postJson("/api/bchat/rooms/{$room->room_uuid}/messages", $payload2);
        $res2->assertStatus(201);
        $this->assertEquals(2, $res2->json('data.server_sequence'));

        $this->assertEquals(2, $room->fresh()->current_sequence);
    }

    public function test_send_message_idempotency_same_payload_returns_200(): void
    {
        $mhs = $this->createTestUser('mahasiswa');
        $dosen = $this->createTestUser('dosen');
        $room = $this->createActiveRoomWithMembers($mhs, $dosen);

        Sanctum::actingAs($mhs);

        $payload = $this->generatePayload('idempotent_test_message');

        // Pengiriman pertama
        $res1 = $this->postJson("/api/bchat/rooms/{$room->room_uuid}/messages", $payload);
        $res1->assertStatus(201);

        // Pengiriman ulang identik (retry aman)
        $res2 = $this->postJson("/api/bchat/rooms/{$room->room_uuid}/messages", $payload);
        $res2->assertStatus(200)
            ->assertJson([
                'status'  => 'success',
                'message' => 'Pesan sudah pernah diterima sebelumnya (idempoten).',
                'data'    => [
                    'server_sequence' => 1,
                    'message_uuid'    => $payload['message_uuid'],
                ],
            ]);

        // Pastikan tidak ada duplikasi baris database
        $this->assertEquals(1, BchatMessage::where('room_id', $room->id)->count());
    }

    public function test_send_message_idempotency_different_payload_returns_409_conflict(): void
    {
        $mhs = $this->createTestUser('mahasiswa');
        $dosen = $this->createTestUser('dosen');
        $room = $this->createActiveRoomWithMembers($mhs, $dosen);

        Sanctum::actingAs($mhs);

        $payloadOriginal = $this->generatePayload('original_message_body');
        $res1 = $this->postJson("/api/bchat/rooms/{$room->room_uuid}/messages", $payloadOriginal);
        $res1->assertStatus(201);

        // Kirim payload berbeda dengan UUID sama
        $payloadModified = $this->generatePayload('tampered_different_body');
        $payloadModified['message_uuid'] = $payloadOriginal['message_uuid'];

        $res2 = $this->postJson("/api/bchat/rooms/{$room->room_uuid}/messages", $payloadModified);
        $res2->assertStatus(409)
            ->assertJson([
                'status'     => 'error',
                'error_code' => 'MESSAGE_UUID_CONFLICT',
            ]);
    }

    public function test_get_messages_cursor_pagination(): void
    {
        $mhs = $this->createTestUser('mahasiswa');
        $dosen = $this->createTestUser('dosen');
        $room = $this->createActiveRoomWithMembers($mhs, $dosen);

        Sanctum::actingAs($mhs);

        for ($i = 1; $i <= 3; $i++) {
            $payload = $this->generatePayload("message_number_{$i}");
            $this->postJson("/api/bchat/rooms/{$room->room_uuid}/messages", $payload)->assertStatus(201);
        }

        // Ambil semua pesan
        $resAll = $this->getJson("/api/bchat/rooms/{$room->room_uuid}/messages");
        $resAll->assertStatus(200)
            ->assertJsonCount(3, 'data');

        // Ambil dengan cursor after_sequence=1
        $resAfter1 = $this->getJson("/api/bchat/rooms/{$room->room_uuid}/messages?after_sequence=1");
        $resAfter1->assertStatus(200)
            ->assertJsonCount(2, 'data');
        $this->assertEquals(2, $resAfter1->json('data.0.server_sequence'));
        $this->assertEquals(3, $resAfter1->json('data.1.server_sequence'));

        // Ambil dengan limit 1
        $resLimit1 = $this->getJson("/api/bchat/rooms/{$room->room_uuid}/messages?after_sequence=2&limit=1");
        $resLimit1->assertStatus(200)
            ->assertJsonCount(1, 'data');
        $this->assertEquals(3, $resLimit1->json('data.0.server_sequence'));
    }

    public function test_non_member_cannot_read_messages(): void
    {
        $mhs = $this->createTestUser('mahasiswa');
        $dosen = $this->createTestUser('dosen');
        $outsider = $this->createTestUser('mahasiswa');
        $room = $this->createActiveRoomWithMembers($mhs, $dosen);

        Sanctum::actingAs($outsider);

        $response = $this->getJson("/api/bchat/rooms/{$room->room_uuid}/messages");

        $response->assertStatus(403)
            ->assertJson([
                'status'     => 'error',
                'error_code' => 'FORBIDDEN_CANNOT_READ_MESSAGES',
            ]);
    }
}
