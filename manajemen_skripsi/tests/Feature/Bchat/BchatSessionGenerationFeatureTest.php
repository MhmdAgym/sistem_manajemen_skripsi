<?php

declare(strict_types=1);

namespace Tests\Feature\Bchat;

use App\Exceptions\BchatMessageException;
use App\Exceptions\BchatRoomSessionException;
use App\Models\BchatAttachment;
use App\Models\BchatKeyPackage;
use App\Models\BchatMessage;
use App\Models\BchatRoom;
use App\Models\BchatRoomMember;
use App\Models\BchatRoomSession;
use App\Models\BchatUploadSession;
use App\Models\BchatUserDevice;
use App\Models\BchatWelcomeChallenge;
use App\Models\Dosen;
use App\Models\Mahasiswa;
use App\Models\PenugasanPembimbing;
use App\Models\Skripsi;
use App\Models\User;
use App\Services\BchatMessageService;
use App\Services\BchatRoomSessionService;
use App\Services\BchatWelcomeAckVerificationService;
use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Test Suite Komprehensif: B-CHAT Session Generation Feature Test
 *
 * Menguji fondasi multi-generasi sesi MLS dalam satu ruang bimbingan akademik,
 * mencakup 12 skenario wajib:
 * 1. Room baru mendapatkan sesi generasi 1.
 * 2. Room lama dapat memiliki sesi generasi berikutnya tanpa menghapus sesi sebelumnya.
 * 3. Satu room tidak dapat memiliki 2 sesi ACTIVE secara bersamaan (DB constraint).
 * 4. Backfill mengaitkan seluruh pesan lama ke sesi generasi 1.
 * 5. Lampiran lama tetap terikat pada pesan dan sesi asalnya.
 * 6. Sequence sesi baru tidak bertabrakan dengan sequence sesi lama.
 * 7. Pesan tidak dapat dikirim menggunakan sesi yang sudah ARCHIVED.
 * 8. Sesi PENDING tidak dapat menerima pesan biasa.
 * 9. Sesi baru hanya menjadi ACTIVE setelah handshake Welcome dan ACK valid.
 * 10. Transaksi paralel pembuatan sesi baru terserialisasi dengan aman.
 * 11. Rollback transaksi menjaga konsistensi state.
 * 12. Otorisasi user dan device tetap ditegakkan.
 */
class BchatSessionGenerationFeatureTest extends TestCase
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

    /**
     * @return array{device: BchatUserDevice, keypair: string, secretKey: string, publicKey: string}
     */
    private function createTestDevice(User $user, string $status = 'ACTIVE', bool $isTrusted = true): array
    {
        $keypair = sodium_crypto_sign_keypair();
        $secretKey = sodium_crypto_sign_secretkey($keypair);
        $publicKey = sodium_crypto_sign_publickey($keypair);

        $device = BchatUserDevice::create([
            'user_id'                => $user->id,
            'device_uuid'            => (string) Str::uuid(),
            'device_name'            => 'Device Test ' . Str::random(4),
            'key_version'            => 1,
            'public_key'             => bin2hex($publicKey),
            'public_key_fingerprint' => hash('sha256', $publicKey),
            'status'                 => $status,
            'is_trusted'             => $isTrusted,
            'trusted_at'             => $isTrusted ? now() : null,
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
            'group_id_hash'           => hash('sha256', 'group_context_gen_' . Str::random(6)),
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

    private function createTestKeyPackage(User $user, BchatUserDevice $device): BchatKeyPackage
    {
        return BchatKeyPackage::create([
            'key_package_uuid' => (string) Str::uuid(),
            'user_id'          => $user->id,
            'device_id'        => $device->id,
            'key_package_hash' => hash('sha256', 'keypkg_' . Str::random(10)),
            'key_package_data' => 'dummy_key_package_binary_payload',
            'cipher_suite'     => 1,
            'init_key'         => bin2hex(random_bytes(32)),
            'leaf_node'        => 'dummy_leaf_node',
            'is_consumed'      => false,
            'consumed_at'      => null,
            'expires_at'       => now()->addDays(7),
        ]);
    }

    // =========================================================================
    // 12 SKENARIO PENGUJIAN SESI GENERATION
    // =========================================================================

    /**
     * Skenario 1: Room baru mendapatkan sesi generasi 1.
     */
    public function test_01_new_room_gets_generation_1_session(): void
    {
        $initiator = $this->createTestUser('mahasiswa');
        $recipient = $this->createTestUser('dosen');
        $this->createTestDevice($initiator);
        $this->createTestDevice($recipient);

        $room = $this->createTestRoomWithMembers($initiator, $recipient);

        // Ambil sesi aktif pertama yang di-provision
        $session = BchatRoomSession::create([
            'session_uuid'       => (string) Str::uuid(),
            'room_id'            => $room->id,
            'generation_number'  => 1,
            'status'             => 'ACTIVE',
            'group_id_hash'      => $room->group_id_hash,
            'start_epoch'        => 1,
            'start_sequence'     => 1,
            'current_sequence'   => 0,
            'created_by_user_id' => $initiator->id,
            'activated_at'       => now(),
        ]);

        $this->assertNotNull($session);
        $this->assertEquals(1, $session->generation_number);
        $this->assertEquals('ACTIVE', $session->status);
        $this->assertEquals($room->id, $session->room_id);
        $this->assertTrue($room->activeSession()->where('id', $session->id)->exists());
    }

    /**
     * Skenario 2: Room lama dapat memiliki sesi generasi berikutnya tanpa menghapus sesi sebelumnya.
     */
    public function test_02_old_room_can_have_next_session_generation_without_deleting_previous_session(): void
    {
        $initiator = $this->createTestUser('mahasiswa');
        $recipient = $this->createTestUser('dosen');
        $initDev = $this->createTestDevice($initiator);
        $this->createTestDevice($recipient);

        $room = $this->createTestRoomWithMembers($initiator, $recipient);

        // Buat Sesi 1 ACTIVE
        $session1 = BchatRoomSession::create([
            'session_uuid'       => (string) Str::uuid(),
            'room_id'            => $room->id,
            'generation_number'  => 1,
            'status'             => 'ACTIVE',
            'group_id_hash'      => $room->group_id_hash,
            'start_epoch'        => 1,
            'start_sequence'     => 1,
            'current_sequence'   => 10,
            'created_by_user_id' => $initiator->id,
            'activated_at'       => now()->subDays(1),
        ]);

        $room->current_sequence = 10;
        $room->current_epoch = 1;
        $room->save();

        // Buat generasi berikutnya via Service
        $sessionService = app(BchatRoomSessionService::class);
        $session2 = $sessionService->createNextSession(
            creator: $initiator,
            roomOrUuid: $room,
            groupIdHash: hash('sha256', 'new_group_session_2')
        );

        $this->assertEquals(2, $session2->generation_number);
        $this->assertEquals('PENDING', $session2->status);
        $this->assertEquals(11, $session2->start_sequence);

        // Sesi 1 TIDAK diarsipkan saat createNextSession! Sesi 1 TETAP ACTIVE!
        $session1Refreshed = BchatRoomSession::find($session1->id);
        $this->assertNotNull($session1Refreshed);
        $this->assertEquals('ACTIVE', $session1Refreshed->status);
        $this->assertNull($session1Refreshed->archived_at);

        // Total sesi untuk room ini adalah 2
        $this->assertEquals(2, $room->sessions()->count());
    }

    /**
     * Skenario 3: Satu room tidak dapat memiliki 2 sesi ACTIVE secara bersamaan (Constraint DB).
     */
    public function test_03_room_cannot_have_two_active_sessions_simultaneously(): void
    {
        $initiator = $this->createTestUser('mahasiswa');
        $recipient = $this->createTestUser('dosen');
        $this->createTestDevice($initiator);
        $this->createTestDevice($recipient);

        $room = $this->createTestRoomWithMembers($initiator, $recipient);

        // Buat Sesi 1 ACTIVE
        BchatRoomSession::create([
            'session_uuid'       => (string) Str::uuid(),
            'room_id'            => $room->id,
            'generation_number'  => 1,
            'status'             => 'ACTIVE',
            'group_id_hash'      => $room->group_id_hash,
            'start_epoch'        => 1,
            'start_sequence'     => 1,
            'current_sequence'   => 0,
            'activated_at'       => now(),
        ]);

        // Coba masukkan sesi kedua dengan status ACTIVE untuk room yang sama
        $this->expectException(QueryException::class);

        BchatRoomSession::create([
            'session_uuid'       => (string) Str::uuid(),
            'room_id'            => $room->id,
            'generation_number'  => 2,
            'status'             => 'ACTIVE', // Ditolak oleh uk_session_active_room_slot
            'group_id_hash'      => hash('sha256', 'duplicate_active'),
            'start_epoch'        => 1,
            'start_sequence'     => 1,
            'current_sequence'   => 0,
            'activated_at'       => now(),
        ]);
    }

    /**
     * Skenario 4: Backfill mengaitkan seluruh pesan lama ke sesi generasi 1.
     */
    public function test_04_backfill_links_all_existing_messages_to_generation_1_session(): void
    {
        $initiator = $this->createTestUser('mahasiswa');
        $recipient = $this->createTestUser('dosen');
        $this->createTestDevice($initiator);
        $this->createTestDevice($recipient);

        $room = $this->createTestRoomWithMembers($initiator, $recipient);

        // Buat sesi generasi 1
        $session1 = BchatRoomSession::create([
            'session_uuid'       => (string) Str::uuid(),
            'room_id'            => $room->id,
            'generation_number'  => 1,
            'status'             => 'ACTIVE',
            'group_id_hash'      => $room->group_id_hash,
            'start_epoch'        => 1,
            'start_sequence'     => 1,
            'current_sequence'   => 10,
            'activated_at'       => now(),
        ]);

        // Buat pesan dengan session_id = null (simulasi data legacy sebelum migrasi)
        for ($i = 1; $i <= 5; $i++) {
            BchatMessage::create([
                'room_id'             => $room->id,
                'session_id'          => null,
                'server_sequence'     => $i,
                'message_uuid'        => (string) Str::uuid(),
                'sender_id'           => $initiator->id,
                'epoch'               => 1,
                'generation'          => 0,
                'content_type'        => 1,
                'ciphertext_payload'  => 'ciphertext_bytes_' . $i,
                'payload_hash_sha256' => hash('sha256', 'ciphertext_bytes_' . $i),
                'client_sent_at'      => now(),
            ]);
        }

        // Jalankan logika backfill persis seperti pada migration up()
        DB::table('bchat_messages')
            ->where('room_id', $room->id)
            ->whereNull('session_id')
            ->update(['session_id' => $session1->id]);

        // Verifikasi seluruh pesan kini terikat ke session 1
        $linkedCount = BchatMessage::where('room_id', $room->id)
            ->where('session_id', $session1->id)
            ->count();

        $this->assertEquals(5, $linkedCount);
        $this->assertEquals(0, BchatMessage::where('room_id', $room->id)->whereNull('session_id')->count());
    }

    /**
     * Skenario 5: Lampiran lama tetap terikat pada pesan dan sesi asalnya.
     */
    public function test_05_old_attachments_remain_linked_to_their_original_messages_and_session(): void
    {
        $initiator = $this->createTestUser('mahasiswa');
        $recipient = $this->createTestUser('dosen');
        $this->createTestDevice($initiator);
        $this->createTestDevice($recipient);

        $room = $this->createTestRoomWithMembers($initiator, $recipient);

        $session1 = BchatRoomSession::create([
            'session_uuid'       => (string) Str::uuid(),
            'room_id'            => $room->id,
            'generation_number'  => 1,
            'status'             => 'ACTIVE',
            'group_id_hash'      => $room->group_id_hash,
            'start_epoch'        => 1,
            'start_sequence'     => 1,
            'current_sequence'   => 1,
            'activated_at'       => now(),
        ]);

        $message = BchatMessage::create([
            'room_id'             => $room->id,
            'session_id'          => $session1->id,
            'server_sequence'     => 1,
            'message_uuid'        => (string) Str::uuid(),
            'sender_id'           => $initiator->id,
            'epoch'               => 1,
            'generation'          => 0,
            'content_type'        => 2,
            'ciphertext_payload'  => 'manifest_payload',
            'payload_hash_sha256' => hash('sha256', 'manifest_payload'),
            'client_sent_at'      => now(),
        ]);

        $uploadSession = BchatUploadSession::create([
            'session_uuid'       => (string) Str::uuid(),
            'room_id'            => $room->id,
            'user_id'            => $initiator->id,
            'total_bytes'        => 1024,
            'total_chunks'       => 1,
            'status'             => 'COMPLETED',
            'expires_at'         => now()->addHour(),
        ]);

        $attachment = BchatAttachment::create([
            'attachment_uuid'   => (string) Str::uuid(),
            'upload_session_id' => $uploadSession->id,
            'room_id'           => $room->id,
            'session_id'        => $session1->id,
            'message_id'        => $message->id,
            'plaintext_bytes'   => 1000,
            'ciphertext_bytes'  => 1028,
            'total_chunks'      => 1,
            'file_hash_sha256'  => hash('sha256', 'raw_file'),
            'status'            => 'ATTACHED',
        ]);

        $this->assertEquals($session1->id, $attachment->session_id);
        $this->assertEquals($message->id, $attachment->message_id);
        $this->assertEquals($room->id, $attachment->room_id);
        $this->assertEquals($session1->id, $attachment->session->id);
        $this->assertEquals($message->id, $attachment->message->id);
    }

    /**
     * Skenario 6: Sequence sesi baru tidak bertabrakan dengan sequence sesi lama.
     */
    public function test_06_new_session_sequence_does_not_collide_with_old_session_sequence(): void
    {
        $initiator = $this->createTestUser('mahasiswa');
        $recipient = $this->createTestUser('dosen');
        $initDev = $this->createTestDevice($initiator);
        $this->createTestDevice($recipient);

        $room = $this->createTestRoomWithMembers($initiator, $recipient);

        // Sesi 1 memiliki 5 pesan (sequence 1..5)
        $session1 = BchatRoomSession::create([
            'session_uuid'       => (string) Str::uuid(),
            'room_id'            => $room->id,
            'generation_number'  => 1,
            'status'             => 'ACTIVE',
            'group_id_hash'      => $room->group_id_hash,
            'start_epoch'        => 1,
            'start_sequence'     => 1,
            'current_sequence'   => 5,
            'activated_at'       => now(),
        ]);

        for ($i = 1; $i <= 5; $i++) {
            BchatMessage::create([
                'room_id'             => $room->id,
                'session_id'          => $session1->id,
                'server_sequence'     => $i,
                'message_uuid'        => (string) Str::uuid(),
                'sender_id'           => $initiator->id,
                'epoch'               => 1,
                'generation'          => 0,
                'content_type'        => 1,
                'ciphertext_payload'  => 'cipher_' . $i,
                'payload_hash_sha256' => hash('sha256', 'cipher_' . $i),
                'client_sent_at'      => now(),
            ]);
        }

        $room->current_sequence = 5;
        $room->save();

        // Buat Sesi 2
        $sessionService = app(BchatRoomSessionService::class);
        $session2 = $sessionService->createNextSession($initiator, $room);

        // Arsipkan Sesi 1 secara eksplisit sebelum mengaktifkan Sesi 2 (sesuai invariant uk_session_active_room_slot)
        $session1->status = 'ARCHIVED';
        $session1->archived_at = now();
        $session1->end_sequence = 5;
        $session1->save();

        // Aktifkan sesi 2 secara manual untuk pengujian sequence
        $session2->status = 'ACTIVE';
        $session2->activated_at = now();
        $session2->save();

        // Kirim pesan baru pada Sesi 2 melalui BchatMessageService
        $msgService = app(BchatMessageService::class);
        $rawCipher = 'cipher_session_2_msg_1';
        $res = $msgService->sendMessage($initiator, $room->room_uuid, [
            'message_uuid'        => (string) Str::uuid(),
            'epoch'               => 1,
            'generation'          => 0,
            'content_type'        => 1,
            'ciphertext_payload'  => base64_encode($rawCipher),
            'payload_hash_sha256' => hash('sha256', $rawCipher),
            'client_sent_at'      => now()->toIso8601String(),
        ]);

        $newMessage = $res['message'];

        // Sekuens pesan baru wajib tepat bernilai 6 (melanjutkan sekuens room)
        $this->assertEquals(6, $newMessage->server_sequence);
        $this->assertEquals($session2->id, $newMessage->session_id);

        // Sequence pada session 2 ter-update ke 6
        $session2Refreshed = BchatRoomSession::find($session2->id);
        $this->assertEquals(6, $session2Refreshed->current_sequence);
    }

    /**
     * Skenario 7: Pesan tidak dapat dikirim menggunakan sesi yang sudah ARCHIVED.
     */
    public function test_07_messages_cannot_be_sent_using_archived_session(): void
    {
        $initiator = $this->createTestUser('mahasiswa');
        $recipient = $this->createTestUser('dosen');
        $this->createTestDevice($initiator);
        $this->createTestDevice($recipient);

        $room = $this->createTestRoomWithMembers($initiator, $recipient);

        // Sesi 1 diarsipkan dan BELUM ada sesi aktif lain
        BchatRoomSession::create([
            'session_uuid'       => (string) Str::uuid(),
            'room_id'            => $room->id,
            'generation_number'  => 1,
            'status'             => 'ARCHIVED',
            'group_id_hash'      => $room->group_id_hash,
            'start_epoch'        => 1,
            'start_sequence'     => 1,
            'current_sequence'   => 5,
            'archived_at'        => now(),
        ]);

        $msgService = app(BchatMessageService::class);
        $rawCipher = 'cipher_test_fail';

        $this->expectException(BchatMessageException::class);
        $this->expectExceptionMessage('Ruang obrolan tidak memiliki sesi kriptografi yang aktif.');

        $msgService->sendMessage($initiator, $room->room_uuid, [
            'message_uuid'        => (string) Str::uuid(),
            'epoch'               => 1,
            'generation'          => 0,
            'content_type'        => 1,
            'ciphertext_payload'  => base64_encode($rawCipher),
            'payload_hash_sha256' => hash('sha256', $rawCipher),
            'client_sent_at'      => now()->toIso8601String(),
        ]);
    }

    /**
     * Skenario 8: Sesi PENDING tidak dapat menerima pesan biasa.
     */
    public function test_08_pending_session_cannot_receive_normal_messages(): void
    {
        $initiator = $this->createTestUser('mahasiswa');
        $recipient = $this->createTestUser('dosen');
        $this->createTestDevice($initiator);
        $this->createTestDevice($recipient);

        $room = $this->createTestRoomWithMembers($initiator, $recipient);

        // Hanya ada sesi PENDING
        BchatRoomSession::create([
            'session_uuid'       => (string) Str::uuid(),
            'room_id'            => $room->id,
            'generation_number'  => 1,
            'status'             => 'PENDING',
            'group_id_hash'      => $room->group_id_hash,
            'start_epoch'        => 1,
            'start_sequence'     => 1,
            'current_sequence'   => 0,
        ]);

        $msgService = app(BchatMessageService::class);
        $rawCipher = 'cipher_test_pending';

        $this->expectException(BchatMessageException::class);
        $this->expectExceptionMessage('Ruang obrolan tidak memiliki sesi kriptografi yang aktif.');

        $msgService->sendMessage($initiator, $room->room_uuid, [
            'message_uuid'        => (string) Str::uuid(),
            'epoch'               => 1,
            'generation'          => 0,
            'content_type'        => 1,
            'ciphertext_payload'  => base64_encode($rawCipher),
            'payload_hash_sha256' => hash('sha256', $rawCipher),
            'client_sent_at'      => now()->toIso8601String(),
        ]);
    }

    /**
     * Skenario 9: Sesi baru hanya menjadi ACTIVE setelah handshake Welcome dan ACK valid.
     */
    public function test_09_new_session_only_becomes_active_after_valid_welcome_and_ack(): void
    {
        $initiator = $this->createTestUser('mahasiswa');
        $recipient = $this->createTestUser('dosen');
        $initDev = $this->createTestDevice($initiator);
        $recipDev = $this->createTestDevice($recipient);

        $room = $this->createTestRoomWithMembers($initiator, $recipient);

        // Buat Sesi 1 ARCHIVED
        BchatRoomSession::create([
            'session_uuid'       => (string) Str::uuid(),
            'room_id'            => $room->id,
            'generation_number'  => 1,
            'status'             => 'ARCHIVED',
            'group_id_hash'      => $room->group_id_hash,
            'start_epoch'        => 1,
            'start_sequence'     => 1,
            'current_sequence'   => 10,
            'archived_at'        => now(),
        ]);

        $sessionService = app(BchatRoomSessionService::class);

        // 1. Buat Sesi 2 (status: PENDING)
        $session2 = $sessionService->createNextSession($initiator, $room);
        $this->assertEquals('PENDING', $session2->status);

        // 2. Publikasikan Welcome untuk Sesi 2
        $keyPackage = $this->createTestKeyPackage($recipient, $recipDev['device']);
        $rawWelcome = 'encrypted_welcome_for_session_2';

        $challenge = $sessionService->publishSessionWelcome($initiator, $session2, [
            'recipient_device_uuid' => $recipDev['device']->device_uuid,
            'key_package_hash'      => $keyPackage->key_package_hash,
            'group_id_hash'         => hash('sha256', 'group_session_2'),
            'welcome_message'       => base64_encode($rawWelcome),
        ]);

        $this->assertNotNull($challenge);
        $this->assertEquals($session2->id, $challenge->session_id);
        $this->assertEquals('ACTIVE', $challenge->operation_status);

        // 3. Verifikasi tanda tangan palsu DITOLAK dan sesi tetap PENDING
        $fakeSignature = base64_encode(random_bytes(64));
        try {
            $sessionService->processSessionWelcomeAck($recipient, $session2, [
                'challenge_uuid' => $challenge->challenge_uuid,
                'signature'      => $fakeSignature,
            ]);
            $this->fail('Harus gagal karena signature palsu');
        } catch (BchatRoomSessionException $e) {
            $this->assertEquals('SIGNATURE_VERIFICATION_FAILED', $e->getErrorCode());
        }

        $this->assertEquals('PENDING', $session2->fresh()->status);

        // 4. Verifikasi tanda tangan kanonikal Ed25519 yang sah BERHASIL mengaktifkan sesi 2
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

        $validSigBin = sodium_crypto_sign_detached($canonicalPayload, $recipDev['secretKey']);
        $validSigBase64 = base64_encode($validSigBin);

        $activatedSession = $sessionService->processSessionWelcomeAck($recipient, $session2, [
            'challenge_uuid' => $challenge->challenge_uuid,
            'signature'      => $validSigBase64,
        ]);

        $this->assertEquals('ACTIVE', $activatedSession->status);
        $this->assertNotNull($activatedSession->activated_at);
        $this->assertEquals('ACTIVE', $room->fresh()->status);
        $this->assertEquals('CONSUMED', $challenge->fresh()->operation_status);
    }

    /**
     * Skenario 10: Transaksi paralel pembuatan sesi baru terserialisasi dengan aman.
     */
    public function test_10_concurrent_requests_to_create_next_session_are_serialized_safely(): void
    {
        $initiator = $this->createTestUser('mahasiswa');
        $recipient = $this->createTestUser('dosen');
        $this->createTestDevice($initiator);
        $this->createTestDevice($recipient);

        $room = $this->createTestRoomWithMembers($initiator, $recipient);

        // Sesi 1 ACTIVE
        BchatRoomSession::create([
            'session_uuid'       => (string) Str::uuid(),
            'room_id'            => $room->id,
            'generation_number'  => 1,
            'status'             => 'ACTIVE',
            'group_id_hash'      => $room->group_id_hash,
            'start_epoch'        => 1,
            'start_sequence'     => 1,
            'current_sequence'   => 0,
            'activated_at'       => now(),
        ]);

        $sessionService = app(BchatRoomSessionService::class);

        // Request pertama membuat Sesi 2 (PENDING)
        $session2 = $sessionService->createNextSession($initiator, $room);
        $this->assertEquals('PENDING', $session2->status);

        // Request kedua saat Sesi 2 masih PENDING harus ditolak dengan SESSION_ALREADY_PENDING (409)
        $this->expectException(BchatRoomSessionException::class);
        $this->expectExceptionMessage('Sesi generasi baru masih menunggu verifikasi handshake.');

        $sessionService->createNextSession($initiator, $room);
    }

    /**
     * Skenario 11: Rollback transaksi menjaga konsistensi state.
     */
    public function test_11_transaction_rollback_preserves_clean_state_without_half_active_sessions(): void
    {
        $initiator = $this->createTestUser('mahasiswa');
        $recipient = $this->createTestUser('dosen');
        $this->createTestDevice($initiator);
        $this->createTestDevice($recipient);

        $room = $this->createTestRoomWithMembers($initiator, $recipient);

        $session1 = BchatRoomSession::create([
            'session_uuid'       => (string) Str::uuid(),
            'room_id'            => $room->id,
            'generation_number'  => 1,
            'status'             => 'ACTIVE',
            'group_id_hash'      => $room->group_id_hash,
            'start_epoch'        => 1,
            'start_sequence'     => 1,
            'current_sequence'   => 0,
            'activated_at'       => now(),
        ]);

        try {
            DB::transaction(function () use ($room, $session1) {
                // Arsipkan sesi 1
                $session1->status = 'ARCHIVED';
                $session1->save();

                // Simulasikan kegagalan sistem
                throw new \RuntimeException('Simulated database deadlock or server crash');
            });
        } catch (\RuntimeException $e) {
            // Expected
        }

        // State harus kembali persis seperti semula (Sesi 1 tetap ACTIVE)
        $this->assertEquals('ACTIVE', $session1->fresh()->status);
        $this->assertEquals(1, $room->sessions()->count());
    }

    /**
     * Skenario 12: Otorisasi user dan device tetap ditegakkan.
     */
    public function test_12_user_and_device_authorization_strictly_enforced(): void
    {
        $initiator = $this->createTestUser('mahasiswa');
        $recipient = $this->createTestUser('dosen');
        $stranger = $this->createTestUser('mahasiswa');

        $this->createTestDevice($initiator);
        $this->createTestDevice($recipient);
        $this->createTestDevice($stranger);

        $room = $this->createTestRoomWithMembers($initiator, $recipient);

        $sessionService = app(BchatRoomSessionService::class);

        // 1. Orang luar (stranger) ditolak dengan FORBIDDEN_NOT_MEMBER (403)
        try {
            $sessionService->createNextSession($stranger, $room);
            $this->fail('Orang luar harus ditolak');
        } catch (BchatRoomSessionException $e) {
            $this->assertEquals(403, $e->getHttpStatus());
            $this->assertEquals('FORBIDDEN_NOT_MEMBER', $e->getErrorCode());
        }

        // 2. Pengguna tanpa perangkat aktif ditolak dengan DEVICE_NOT_ACTIVE (403)
        $userNoDevice = $this->createTestUser('mahasiswa');
        // Masukkan userNoDevice ke room
        BchatRoomMember::create([
            'room_id'         => $room->id,
            'user_id'         => $userNoDevice->id,
            'role'            => 'mahasiswa',
            'is_initiator'    => false,
            'is_active'       => true,
            'joined_at_epoch' => 1,
        ]);

        try {
            $sessionService->createNextSession($userNoDevice, $room);
            $this->fail('Pengguna tanpa perangkat harus ditolak');
        } catch (BchatRoomSessionException $e) {
            $this->assertEquals(403, $e->getHttpStatus());
            $this->assertEquals('DEVICE_NOT_ACTIVE', $e->getErrorCode());
        }

        // 3. Pengguna dengan perangkat untrusted ditolak dengan DEVICE_NOT_TRUSTED (403)
        $this->createTestDevice($userNoDevice, 'ACTIVE', false); // is_trusted = false

        try {
            $sessionService->createNextSession($userNoDevice, $room);
            $this->fail('Pengguna dengan perangkat untrusted harus ditolak');
        } catch (BchatRoomSessionException $e) {
            $this->assertEquals(403, $e->getHttpStatus());
            $this->assertEquals('DEVICE_NOT_TRUSTED', $e->getErrorCode());
        }
    }

    /**
     * Skenario 13: Endpoint API POST /api/bchat/rooms/{roomUuid}/sessions/next dapat membuat sesi baru.
     */
    public function test_13_api_can_create_next_session_via_endpoint(): void
    {
        $initiator = $this->createTestUser('mahasiswa');
        $recipient = $this->createTestUser('dosen');
        $initDev = $this->createTestDevice($initiator);
        $this->createTestDevice($recipient);

        $room = $this->createTestRoomWithMembers($initiator, $recipient);

        BchatRoomSession::create([
            'session_uuid'       => (string) Str::uuid(),
            'room_id'            => $room->id,
            'generation_number'  => 1,
            'status'             => 'ACTIVE',
            'group_id_hash'      => $room->group_id_hash,
            'start_epoch'        => 1,
            'start_sequence'     => 1,
            'current_sequence'   => 5,
            'activated_at'       => now(),
        ]);

        Sanctum::actingAs($initiator);

        $response = $this->withHeader('X-Bchat-Device-Uuid', $initDev['device']->device_uuid)
            ->postJson("/api/bchat/rooms/{$room->room_uuid}/sessions/next", [
                'group_id_hash' => hash('sha256', 'session_2_api'),
            ]);

        $response->assertStatus(201)
            ->assertJson([
                'status'  => 'success',
                'message' => 'Generasi sesi kriptografi baru berhasil dibuat (status PENDING).',
                'data'    => [
                    'generation_number' => 2,
                    'status'            => 'PENDING',
                    'start_sequence'    => 1,
                ],
            ]);

        $this->assertEquals(2, $room->sessions()->count());
    }

    /**
     * Skenario 14: Endpoint API GET sessions dan GET active session.
     */
    public function test_14_api_can_list_sessions_and_get_active_session(): void
    {
        $initiator = $this->createTestUser('mahasiswa');
        $recipient = $this->createTestUser('dosen');
        $initDev = $this->createTestDevice($initiator);
        $this->createTestDevice($recipient);

        $room = $this->createTestRoomWithMembers($initiator, $recipient);

        $session1 = BchatRoomSession::create([
            'session_uuid'       => (string) Str::uuid(),
            'room_id'            => $room->id,
            'generation_number'  => 1,
            'status'             => 'ACTIVE',
            'group_id_hash'      => $room->group_id_hash,
            'start_epoch'        => 1,
            'start_sequence'     => 1,
            'current_sequence'   => 5,
            'activated_at'       => now(),
        ]);

        Sanctum::actingAs($initiator);

        // List sessions
        $resList = $this->getJson("/api/bchat/rooms/{$room->room_uuid}/sessions");
        $resList->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.session_uuid', $session1->session_uuid);

        // Get active session
        $resActive = $this->getJson("/api/bchat/rooms/{$room->room_uuid}/sessions/active");
        $resActive->assertStatus(200)
            ->assertJsonPath('data.session_uuid', $session1->session_uuid)
            ->assertJsonPath('data.status', 'ACTIVE');
    }

    /**
     * Skenario 15: API dapat memfilter riwayat pesan berdasarkan session_uuid atau generation_number.
     */
    public function test_15_api_can_filter_messages_by_session_uuid_or_generation_number(): void
    {
        $initiator = $this->createTestUser('mahasiswa');
        $recipient = $this->createTestUser('dosen');
        $initDev = $this->createTestDevice($initiator);
        $this->createTestDevice($recipient);

        $room = $this->createTestRoomWithMembers($initiator, $recipient);

        // Sesi 1 dengan 2 pesan
        $session1 = BchatRoomSession::create([
            'session_uuid'       => (string) Str::uuid(),
            'room_id'            => $room->id,
            'generation_number'  => 1,
            'status'             => 'ARCHIVED',
            'group_id_hash'      => $room->group_id_hash,
            'start_epoch'        => 1,
            'start_sequence'     => 1,
            'end_sequence'       => 2,
            'current_sequence'   => 2,
            'activated_at'       => now()->subDays(1),
        ]);

        for ($i = 1; $i <= 2; $i++) {
            BchatMessage::create([
                'room_id'             => $room->id,
                'session_id'          => $session1->id,
                'server_sequence'     => $i,
                'message_uuid'        => (string) Str::uuid(),
                'sender_id'           => $initiator->id,
                'epoch'               => 1,
                'generation'          => 0,
                'content_type'        => 1,
                'ciphertext_payload'  => 'cipher_' . $i,
                'payload_hash_sha256' => hash('sha256', 'cipher_' . $i),
                'client_sent_at'      => now(),
            ]);
        }

        // Sesi 2 dengan 3 pesan
        $session2 = BchatRoomSession::create([
            'session_uuid'       => (string) Str::uuid(),
            'room_id'            => $room->id,
            'generation_number'  => 2,
            'status'             => 'ACTIVE',
            'group_id_hash'      => hash('sha256', 'session_2'),
            'start_epoch'        => 1,
            'start_sequence'     => 3,
            'current_sequence'   => 5,
            'activated_at'       => now(),
        ]);

        for ($i = 3; $i <= 5; $i++) {
            BchatMessage::create([
                'room_id'             => $room->id,
                'session_id'          => $session2->id,
                'server_sequence'     => $i,
                'message_uuid'        => (string) Str::uuid(),
                'sender_id'           => $initiator->id,
                'epoch'               => 1,
                'generation'          => 0,
                'content_type'        => 1,
                'ciphertext_payload'  => 'cipher_' . $i,
                'payload_hash_sha256' => hash('sha256', 'cipher_' . $i),
                'client_sent_at'      => now(),
            ]);
        }

        Sanctum::actingAs($initiator);

        // 1. Tanpa filter: mengambil seluruh 5 pesan lintas generasi
        $resAll = $this->withHeader('X-Bchat-Device-Uuid', $initDev['device']->device_uuid)
            ->getJson("/api/bchat/rooms/{$room->room_uuid}/messages");
        $resAll->assertStatus(200)->assertJsonCount(5, 'data');

        // 2. Filter session_uuid Sesi 1: hanya 2 pesan
        $resS1 = $this->withHeader('X-Bchat-Device-Uuid', $initDev['device']->device_uuid)
            ->getJson("/api/bchat/rooms/{$room->room_uuid}/messages?session_uuid={$session1->session_uuid}");
        $resS1->assertStatus(200)->assertJsonCount(2, 'data');

        // 3. Filter generation_number Sesi 2: hanya 3 pesan
        $resS2 = $this->withHeader('X-Bchat-Device-Uuid', $initDev['device']->device_uuid)
            ->getJson("/api/bchat/rooms/{$room->room_uuid}/messages?generation_number=2");
        $resS2->assertStatus(200)->assertJsonCount(3, 'data');
    }

    // =========================================================================
    // SKENARIO KHUSUS: ATTACHMENT SESSION BINDING (SKENARIO 1–8)
    // =========================================================================

    /**
     * Skenario 1: Upload lampiran baru otomatis menyimpan session_id sesi aktif yang sah.
     */
    public function test_scenario_01_upload_session_stores_valid_session_id_of_active_session(): void
    {
        $initiator = $this->createTestUser('mahasiswa');
        $recipient = $this->createTestUser('dosen');
        $initDev = $this->createTestDevice($initiator);
        $this->createTestDevice($recipient);
        $room = $this->createTestRoomWithMembers($initiator, $recipient);

        $session1 = BchatRoomSession::create([
            'session_uuid'       => (string) Str::uuid(),
            'room_id'            => $room->id,
            'generation_number'  => 1,
            'status'             => 'ACTIVE',
            'group_id_hash'      => $room->group_id_hash,
            'start_epoch'        => 1,
            'start_sequence'     => 1,
            'current_sequence'   => 0,
            'activated_at'       => now(),
        ]);

        Sanctum::actingAs($initiator);
        $attUuid = (string) Str::uuid();

        $response = $this->withHeader('X-Bchat-Device-Uuid', $initDev['device']->device_uuid)
            ->postJson("/api/bchat/rooms/{$room->room_uuid}/attachments/upload-session", [
                'attachment_uuid'  => $attUuid,
                'total_chunks'     => 1,
                'ciphertext_bytes' => 1028,
                'plaintext_bytes'  => 1000,
            ]);

        $response->assertStatus(201);

        $attachment = BchatAttachment::where('attachment_uuid', $attUuid)->first();
        $this->assertNotNull($attachment);
        $this->assertEquals($session1->id, $attachment->session_id);
        $this->assertEquals('PENDING_MESSAGE', $attachment->status);
    }

    /**
     * Skenario 2: Upload ditolak jika room tidak memiliki sesi ACTIVE (409 NO_ACTIVE_CRYPTO_SESSION).
     */
    public function test_scenario_02_upload_rejected_if_room_has_no_active_crypto_session(): void
    {
        $initiator = $this->createTestUser('mahasiswa');
        $recipient = $this->createTestUser('dosen');
        $initDev = $this->createTestDevice($initiator);
        $this->createTestDevice($recipient);
        $room = $this->createTestRoomWithMembers($initiator, $recipient);

        // Hanya ada sesi ARCHIVED, tidak ada sesi ACTIVE
        BchatRoomSession::create([
            'session_uuid'       => (string) Str::uuid(),
            'room_id'            => $room->id,
            'generation_number'  => 1,
            'status'             => 'ARCHIVED',
            'group_id_hash'      => $room->group_id_hash,
            'start_epoch'        => 1,
            'start_sequence'     => 1,
            'current_sequence'   => 5,
            'archived_at'        => now(),
        ]);

        Sanctum::actingAs($initiator);
        $attUuid = (string) Str::uuid();

        $response = $this->withHeader('X-Bchat-Device-Uuid', $initDev['device']->device_uuid)
            ->postJson("/api/bchat/rooms/{$room->room_uuid}/attachments/upload-session", [
                'attachment_uuid'  => $attUuid,
                'total_chunks'     => 1,
                'ciphertext_bytes' => 1028,
                'plaintext_bytes'  => 1000,
            ]);

        $response->assertStatus(409)
            ->assertJson([
                'status'     => 'error',
                'error_code' => 'NO_ACTIVE_CRYPTO_SESSION',
            ]);

        $this->assertDatabaseMissing('bchat_attachments', ['attachment_uuid' => $attUuid]);
    }

    /**
     * Skenario 3: Upload yang dimulai pada sesi lama ditolak saat diselesaikan jika sesi telah berganti generasi (409 CRYPTO_SESSION_MISMATCH).
     */
    public function test_scenario_03_upload_started_on_old_session_rejected_when_completed_after_generation_changed(): void
    {
        Storage::fake('local');
        $initiator = $this->createTestUser('mahasiswa');
        $recipient = $this->createTestUser('dosen');
        $initDev = $this->createTestDevice($initiator);
        $this->createTestDevice($recipient);
        $room = $this->createTestRoomWithMembers($initiator, $recipient);

        $session1 = BchatRoomSession::create([
            'session_uuid'       => (string) Str::uuid(),
            'room_id'            => $room->id,
            'generation_number'  => 1,
            'status'             => 'ACTIVE',
            'group_id_hash'      => $room->group_id_hash,
            'start_epoch'        => 1,
            'start_sequence'     => 1,
            'current_sequence'   => 0,
            'activated_at'       => now(),
        ]);

        Sanctum::actingAs($initiator);
        $attUuid = (string) Str::uuid();

        // 1. Buat sesi upload pada Sesi 1
        $initRes = $this->withHeader('X-Bchat-Device-Uuid', $initDev['device']->device_uuid)
            ->postJson("/api/bchat/rooms/{$room->room_uuid}/attachments/upload-session", [
                'attachment_uuid'  => $attUuid,
                'total_chunks'     => 1,
                'ciphertext_bytes' => 76,
                'plaintext_bytes'  => 48,
            ]);
        $initRes->assertStatus(201);
        $uploadSessionUuid = $initRes->json('data.session_uuid');

        // Upload chunk 0
        $chunkPayload = random_bytes(76);
        $chunkHash = hash('sha256', $chunkPayload);
        $uploadChunkRes = $this->withHeader('X-Bchat-Device-Uuid', $initDev['device']->device_uuid)
            ->call('PUT', "/api/bchat/rooms/{$room->room_uuid}/attachments/upload-session/{$uploadSessionUuid}/chunks/0",
                [], [], [],
                ['CONTENT_TYPE' => 'application/octet-stream', 'HTTP_X_CONTENT_SHA256' => $chunkHash],
                $chunkPayload
            );
        $uploadChunkRes->assertStatus(201);

        // 2. Transisi generasi: Sesi 1 diarsipkan, Sesi 2 aktif
        $session1->status = 'ARCHIVED';
        $session1->archived_at = now();
        $session1->save();

        $session2 = BchatRoomSession::create([
            'session_uuid'       => (string) Str::uuid(),
            'room_id'            => $room->id,
            'generation_number'  => 2,
            'status'             => 'ACTIVE',
            'group_id_hash'      => hash('sha256', 'session_2'),
            'start_epoch'        => 1,
            'start_sequence'     => 1,
            'current_sequence'   => 0,
            'activated_at'       => now(),
        ]);

        // 3. Selesaikan upload yang dimulai pada sesi lama -> Ditolak 409
        $completeRes = $this->withHeader('X-Bchat-Device-Uuid', $initDev['device']->device_uuid)
            ->postJson("/api/bchat/rooms/{$room->room_uuid}/attachments/upload-session/{$uploadSessionUuid}/complete");

        $completeRes->assertStatus(409)
            ->assertJson([
                'status'     => 'error',
                'error_code' => 'CRYPTO_SESSION_MISMATCH',
            ]);
    }

    /**
     * Skenario 4: Manifest pesan menolak lampiran yang berasal dari generasi sesi yang berbeda (409 ATTACHMENT_SESSION_MISMATCH).
     */
    public function test_scenario_04_message_manifest_rejects_attachment_from_different_generation(): void
    {
        $initiator = $this->createTestUser('mahasiswa');
        $recipient = $this->createTestUser('dosen');
        $initDev = $this->createTestDevice($initiator);
        $this->createTestDevice($recipient);
        $room = $this->createTestRoomWithMembers($initiator, $recipient);

        $session1 = BchatRoomSession::create([
            'session_uuid'       => (string) Str::uuid(),
            'room_id'            => $room->id,
            'generation_number'  => 1,
            'status'             => 'ARCHIVED',
            'group_id_hash'      => $room->group_id_hash,
            'start_epoch'        => 1,
            'start_sequence'     => 1,
            'current_sequence'   => 5,
            'archived_at'        => now(),
        ]);

        $session2 = BchatRoomSession::create([
            'session_uuid'       => (string) Str::uuid(),
            'room_id'            => $room->id,
            'generation_number'  => 2,
            'status'             => 'ACTIVE',
            'group_id_hash'      => hash('sha256', 'session_2'),
            'start_epoch'        => 1,
            'start_sequence'     => 6,
            'current_sequence'   => 5,
            'activated_at'       => now(),
        ]);

        $uploadSession = BchatUploadSession::create([
            'session_uuid'       => (string) Str::uuid(),
            'room_id'            => $room->id,
            'user_id'            => $initiator->id,
            'total_bytes'        => 1024,
            'total_chunks'       => 1,
            'status'             => 'COMPLETED',
            'expires_at'         => now()->addHour(),
        ]);

        $attUuid = (string) Str::uuid();
        $attachment = BchatAttachment::create([
            'attachment_uuid'   => $attUuid,
            'upload_session_id' => $uploadSession->id,
            'room_id'           => $room->id,
            'session_id'        => $session1->id, // Terikat pada Sesi 1
            'message_id'        => null,
            'plaintext_bytes'   => 1000,
            'ciphertext_bytes'  => 1024,
            'total_chunks'      => 1,
            'file_hash_sha256'  => hash('sha256', 'file1'),
            'status'            => 'PENDING_MESSAGE',
        ]);

        Sanctum::actingAs($initiator);

        // Kirim manifest pesan pada Sesi 2 yang mereferensikan lampiran Sesi 1
        $rawPayload = 'manifest_payload_diff_gen';
        $response = $this->withHeader('X-Bchat-Device-Uuid', $initDev['device']->device_uuid)
            ->postJson("/api/bchat/rooms/{$room->room_uuid}/messages", [
                'message_uuid'        => (string) Str::uuid(),
                'epoch'               => 1,
                'generation'          => 0,
                'content_type'        => 2,
                'ciphertext_payload'  => base64_encode($rawPayload),
                'payload_hash_sha256' => hash('sha256', $rawPayload),
                'client_sent_at'      => now()->toIso8601String(),
                'attachment_uuids'    => [$attUuid],
            ]);

        $response->assertStatus(409)
            ->assertJson([
                'status'     => 'error',
                'error_code' => 'ATTACHMENT_SESSION_MISMATCH',
            ]);

        $this->assertNull($attachment->fresh()->message_id);
        $this->assertEquals('PENDING_MESSAGE', $attachment->fresh()->status);
    }

    /**
     * Skenario 5: Manifest pesan menolak lampiran dari room yang berbeda (403 ATTACHMENT_ROOM_MISMATCH).
     */
    public function test_scenario_05_message_manifest_rejects_attachment_from_different_room(): void
    {
        $initiatorA = $this->createTestUser('mahasiswa');
        $recipientA = $this->createTestUser('dosen');
        $initDevA = $this->createTestDevice($initiatorA);
        $this->createTestDevice($recipientA);

        $initiatorB = $this->createTestUser('mahasiswa');
        $recipientB = $this->createTestUser('dosen');
        $this->createTestDevice($initiatorB);
        $this->createTestDevice($recipientB);

        $roomA = $this->createTestRoomWithMembers($initiatorA, $recipientA);
        $sessionA = BchatRoomSession::create([
            'session_uuid'       => (string) Str::uuid(),
            'room_id'            => $roomA->id,
            'generation_number'  => 1,
            'status'             => 'ACTIVE',
            'group_id_hash'      => $roomA->group_id_hash,
            'start_epoch'        => 1,
            'start_sequence'     => 1,
            'current_sequence'   => 0,
            'activated_at'       => now(),
        ]);

        $roomB = $this->createTestRoomWithMembers($initiatorB, $recipientB);
        $sessionB = BchatRoomSession::create([
            'session_uuid'       => (string) Str::uuid(),
            'room_id'            => $roomB->id,
            'generation_number'  => 1,
            'status'             => 'ACTIVE',
            'group_id_hash'      => $roomB->group_id_hash,
            'start_epoch'        => 1,
            'start_sequence'     => 1,
            'current_sequence'   => 0,
            'activated_at'       => now(),
        ]);

        $uploadSessionB = BchatUploadSession::create([
            'session_uuid'       => (string) Str::uuid(),
            'room_id'            => $roomB->id,
            'user_id'            => $initiatorB->id,
            'total_bytes'        => 1024,
            'total_chunks'       => 1,
            'status'             => 'COMPLETED',
            'expires_at'         => now()->addHour(),
        ]);

        $attUuid = (string) Str::uuid();
        $attachmentB = BchatAttachment::create([
            'attachment_uuid'   => $attUuid,
            'upload_session_id' => $uploadSessionB->id,
            'room_id'           => $roomB->id,
            'session_id'        => $sessionB->id,
            'message_id'        => null,
            'plaintext_bytes'   => 1000,
            'ciphertext_bytes'  => 1024,
            'total_chunks'      => 1,
            'file_hash_sha256'  => hash('sha256', 'fileB'),
            'status'            => 'PENDING_MESSAGE',
        ]);

        Sanctum::actingAs($initiatorA);

        // Kirim manifest pesan pada Room A mereferensikan lampiran Room B
        $rawPayload = 'manifest_cross_room';
        $response = $this->withHeader('X-Bchat-Device-Uuid', $initDevA['device']->device_uuid)
            ->postJson("/api/bchat/rooms/{$roomA->room_uuid}/messages", [
                'message_uuid'        => (string) Str::uuid(),
                'epoch'               => 1,
                'generation'          => 0,
                'content_type'        => 2,
                'ciphertext_payload'  => base64_encode($rawPayload),
                'payload_hash_sha256' => hash('sha256', $rawPayload),
                'client_sent_at'      => now()->toIso8601String(),
                'attachment_uuids'    => [$attUuid],
            ]);

        $response->assertStatus(403)
            ->assertJson([
                'status'     => 'error',
                'error_code' => 'ATTACHMENT_ROOM_MISMATCH',
            ]);

        $this->assertNull($attachmentB->fresh()->message_id);
    }

    /**
     * Skenario 6: Manifest pesan menolak lampiran yang sudah terikat pada pesan lain (409 ATTACHMENT_ALREADY_ATTACHED).
     */
    public function test_scenario_06_message_manifest_rejects_attachment_already_bound_to_another_message(): void
    {
        $initiator = $this->createTestUser('mahasiswa');
        $recipient = $this->createTestUser('dosen');
        $initDev = $this->createTestDevice($initiator);
        $this->createTestDevice($recipient);
        $room = $this->createTestRoomWithMembers($initiator, $recipient);

        $session = BchatRoomSession::create([
            'session_uuid'       => (string) Str::uuid(),
            'room_id'            => $room->id,
            'generation_number'  => 1,
            'status'             => 'ACTIVE',
            'group_id_hash'      => $room->group_id_hash,
            'start_epoch'        => 1,
            'start_sequence'     => 1,
            'current_sequence'   => 1,
            'activated_at'       => now(),
        ]);

        $existingMessage = BchatMessage::create([
            'room_id'             => $room->id,
            'session_id'          => $session->id,
            'server_sequence'     => 1,
            'message_uuid'        => (string) Str::uuid(),
            'sender_id'           => $initiator->id,
            'epoch'               => 1,
            'generation'          => 0,
            'content_type'        => 2,
            'ciphertext_payload'  => 'cipher1',
            'payload_hash_sha256' => hash('sha256', 'cipher1'),
            'client_sent_at'      => now(),
        ]);

        $uploadSession = BchatUploadSession::create([
            'session_uuid'       => (string) Str::uuid(),
            'room_id'            => $room->id,
            'user_id'            => $initiator->id,
            'total_bytes'        => 1024,
            'total_chunks'       => 1,
            'status'             => 'COMPLETED',
            'expires_at'         => now()->addHour(),
        ]);

        $attUuid = (string) Str::uuid();
        $attachment = BchatAttachment::create([
            'attachment_uuid'   => $attUuid,
            'upload_session_id' => $uploadSession->id,
            'room_id'           => $room->id,
            'session_id'        => $session->id,
            'message_id'        => $existingMessage->id, // Sudah terikat
            'plaintext_bytes'   => 1000,
            'ciphertext_bytes'  => 1024,
            'total_chunks'      => 1,
            'file_hash_sha256'  => hash('sha256', 'file1'),
            'status'            => 'ATTACHED',
        ]);

        Sanctum::actingAs($initiator);

        $rawPayload = 'manifest_payload_reuse';
        $response = $this->withHeader('X-Bchat-Device-Uuid', $initDev['device']->device_uuid)
            ->postJson("/api/bchat/rooms/{$room->room_uuid}/messages", [
                'message_uuid'        => (string) Str::uuid(),
                'epoch'               => 1,
                'generation'          => 0,
                'content_type'        => 2,
                'ciphertext_payload'  => base64_encode($rawPayload),
                'payload_hash_sha256' => hash('sha256', $rawPayload),
                'client_sent_at'      => now()->toIso8601String(),
                'attachment_uuids'    => [$attUuid],
            ]);

        $response->assertStatus(409)
            ->assertJson([
                'status'     => 'error',
                'error_code' => 'ATTACHMENT_ALREADY_ATTACHED',
            ]);

        $this->assertEquals($existingMessage->id, $attachment->fresh()->message_id);
    }

    /**
     * Skenario 7: Kegagalan transaksi pada pengiriman pesan manifest membatalkan seluruh binding lampiran (zero partial binding).
     */
    public function test_scenario_07_transaction_failure_aborts_all_attachment_bindings_zero_partial_binding(): void
    {
        $initiator = $this->createTestUser('mahasiswa');
        $recipient = $this->createTestUser('dosen');
        $initDev = $this->createTestDevice($initiator);
        $this->createTestDevice($recipient);
        $room = $this->createTestRoomWithMembers($initiator, $recipient);

        $session = BchatRoomSession::create([
            'session_uuid'       => (string) Str::uuid(),
            'room_id'            => $room->id,
            'generation_number'  => 1,
            'status'             => 'ACTIVE',
            'group_id_hash'      => $room->group_id_hash,
            'start_epoch'        => 1,
            'start_sequence'     => 1,
            'current_sequence'   => 0,
            'activated_at'       => now(),
        ]);

        $uploadSession = BchatUploadSession::create([
            'session_uuid'       => (string) Str::uuid(),
            'room_id'            => $room->id,
            'user_id'            => $initiator->id,
            'total_bytes'        => 1024,
            'total_chunks'       => 1,
            'status'             => 'COMPLETED',
            'expires_at'         => now()->addHour(),
        ]);

        $attUuid1 = (string) Str::uuid();
        $attachment1 = BchatAttachment::create([
            'attachment_uuid'   => $attUuid1,
            'upload_session_id' => $uploadSession->id,
            'room_id'           => $room->id,
            'session_id'        => $session->id,
            'message_id'        => null,
            'plaintext_bytes'   => 1000,
            'ciphertext_bytes'  => 1024,
            'total_chunks'      => 1,
            'file_hash_sha256'  => hash('sha256', 'file1'),
            'status'            => 'PENDING_MESSAGE',
        ]);

        $attUuid2 = (string) Str::uuid();
        $attachment2 = BchatAttachment::create([
            'attachment_uuid'   => $attUuid2,
            'upload_session_id' => $uploadSession->id,
            'room_id'           => $room->id,
            'session_id'        => $session->id,
            'message_id'        => null,
            'plaintext_bytes'   => 2000,
            'ciphertext_bytes'  => 2048,
            'total_chunks'      => 1,
            'file_hash_sha256'  => hash('sha256', 'file2'),
            'status'            => 'PENDING_MESSAGE',
        ]);

        $nonExistentUuid = (string) Str::uuid();

        Sanctum::actingAs($initiator);

        // Sertakan att1 (ada), nonExistentUuid (tidak ada), att2 (ada)
        $rawPayload = 'manifest_payload_partial';
        $response = $this->withHeader('X-Bchat-Device-Uuid', $initDev['device']->device_uuid)
            ->postJson("/api/bchat/rooms/{$room->room_uuid}/messages", [
                'message_uuid'        => (string) Str::uuid(),
                'epoch'               => 1,
                'generation'          => 0,
                'content_type'        => 2,
                'ciphertext_payload'  => base64_encode($rawPayload),
                'payload_hash_sha256' => hash('sha256', $rawPayload),
                'client_sent_at'      => now()->toIso8601String(),
                'attachment_uuids'    => [$attUuid1, $nonExistentUuid, $attUuid2],
            ]);

        $response->assertStatus(404)
            ->assertJson([
                'status'     => 'error',
                'error_code' => 'ATTACHMENT_NOT_FOUND',
            ]);

        // Zero partial binding: keduanya tetap null dan PENDING_MESSAGE
        $this->assertNull($attachment1->fresh()->message_id);
        $this->assertEquals('PENDING_MESSAGE', $attachment1->fresh()->status);
        $this->assertNull($attachment2->fresh()->message_id);
        $this->assertEquals('PENDING_MESSAGE', $attachment2->fresh()->status);
    }

    /**
     * Skenario 8: Alur lengkap upload, chunking, penyelesaian, dan download berfungsi normal dengan session binding aktif.
     */
    public function test_scenario_08_full_upload_chunk_complete_and_download_flow_works_with_session_binding(): void
    {
        Storage::fake('local');
        $initiator = $this->createTestUser('mahasiswa');
        $recipient = $this->createTestUser('dosen');
        $initDev = $this->createTestDevice($initiator);
        $recipDev = $this->createTestDevice($recipient);
        $room = $this->createTestRoomWithMembers($initiator, $recipient);

        $session = BchatRoomSession::create([
            'session_uuid'       => (string) Str::uuid(),
            'room_id'            => $room->id,
            'generation_number'  => 1,
            'status'             => 'ACTIVE',
            'group_id_hash'      => $room->group_id_hash,
            'start_epoch'        => 1,
            'start_sequence'     => 1,
            'current_sequence'   => 0,
            'activated_at'       => now(),
        ]);

        Sanctum::actingAs($initiator);
        $attUuid = (string) Str::uuid();
        $chunkBytes = random_bytes(268);
        $chunkHash = hash('sha256', $chunkBytes);

        // 1. Sesi upload
        $initRes = $this->withHeader('X-Bchat-Device-Uuid', $initDev['device']->device_uuid)
            ->postJson("/api/bchat/rooms/{$room->room_uuid}/attachments/upload-session", [
                'attachment_uuid'  => $attUuid,
                'total_chunks'     => 1,
                'ciphertext_bytes' => 268,
                'plaintext_bytes'  => 240,
            ]);
        $initRes->assertStatus(201);
        $uploadSessUuid = $initRes->json('data.session_uuid');

        // 2. Upload chunk 0
        $chunkRes = $this->withHeader('X-Bchat-Device-Uuid', $initDev['device']->device_uuid)
            ->call('PUT', "/api/bchat/rooms/{$room->room_uuid}/attachments/upload-session/{$uploadSessUuid}/chunks/0",
                [], [], [],
                ['CONTENT_TYPE' => 'application/octet-stream', 'HTTP_X_CONTENT_SHA256' => $chunkHash],
                $chunkBytes
            );
        $chunkRes->assertStatus(201);

        // 3. Finalisasi upload
        $compRes = $this->withHeader('X-Bchat-Device-Uuid', $initDev['device']->device_uuid)
            ->postJson("/api/bchat/rooms/{$room->room_uuid}/attachments/upload-session/{$uploadSessUuid}/complete", [
                'file_hash_sha256' => $chunkHash,
            ]);
        $compRes->assertStatus(200);

        $attachment = BchatAttachment::where('attachment_uuid', $attUuid)->first();
        $this->assertEquals($session->id, $attachment->session_id);

        // 4. Kirim pesan manifest
        $rawPayload = 'manifest_full_flow';
        $msgRes = $this->withHeader('X-Bchat-Device-Uuid', $initDev['device']->device_uuid)
            ->postJson("/api/bchat/rooms/{$room->room_uuid}/messages", [
                'message_uuid'        => (string) Str::uuid(),
                'epoch'               => 1,
                'generation'          => 0,
                'content_type'        => 2,
                'ciphertext_payload'  => base64_encode($rawPayload),
                'payload_hash_sha256' => hash('sha256', $rawPayload),
                'client_sent_at'      => now()->toIso8601String(),
                'attachment_uuids'    => [$attUuid],
            ]);
        $msgRes->assertStatus(201);

        $message = BchatMessage::where('message_uuid', $msgRes->json('data.message_uuid'))->first();
        $this->assertEquals($session->id, $message->session_id);
        $this->assertEquals($message->id, $attachment->fresh()->message_id);
        $this->assertEquals('ATTACHED', $attachment->fresh()->status);

        // 5. Download chunk oleh penerima
        Sanctum::actingAs($recipient);
        $downRes = $this->withHeader('X-Bchat-Device-Uuid', $recipDev['device']->device_uuid)
            ->get("/api/bchat/rooms/{$room->room_uuid}/attachments/{$attUuid}/chunks/0");

        $downRes->assertStatus(200);
        $this->assertEquals($chunkBytes, $downRes->getContent());
    }

    // =========================================================================
    // SKENARIO KHUSUS: SESSION TRANSITION CORRECTION (SKENARIO 9–16)
    // =========================================================================

    /**
     * Skenario 9: Pembuatan generasi sesi baru (PENDING) TIDAK mengubah sesi lama menjadi ARCHIVED.
     */
    public function test_scenario_09_creating_next_session_does_not_archive_old_active_session(): void
    {
        $initiator = $this->createTestUser('mahasiswa');
        $recipient = $this->createTestUser('dosen');
        $this->createTestDevice($initiator);
        $this->createTestDevice($recipient);
        $room = $this->createTestRoomWithMembers($initiator, $recipient);

        $session1 = BchatRoomSession::create([
            'session_uuid'       => (string) Str::uuid(),
            'room_id'            => $room->id,
            'generation_number'  => 1,
            'status'             => 'ACTIVE',
            'group_id_hash'      => $room->group_id_hash,
            'start_epoch'        => 1,
            'start_sequence'     => 1,
            'current_sequence'   => 10,
            'activated_at'       => now()->subDays(1),
        ]);

        $sessionService = app(BchatRoomSessionService::class);
        $session2 = $sessionService->createNextSession($initiator, $room);

        $this->assertEquals('PENDING', $session2->status);
        $this->assertEquals(2, $session2->generation_number);

        // Sesi 1 WAJIB TETAP ACTIVE!
        $session1Fresh = $session1->fresh();
        $this->assertEquals('ACTIVE', $session1Fresh->status);
        $this->assertNull($session1Fresh->archived_at);
        $this->assertNull($session1Fresh->end_sequence);
    }

    /**
     * Skenario 10: Sesi lama tetap dapat mengirim dan menerima pesan selama sesi baru berstatus PENDING.
     */
    public function test_scenario_10_old_session_can_still_send_and_receive_messages_while_new_session_is_pending(): void
    {
        $initiator = $this->createTestUser('mahasiswa');
        $recipient = $this->createTestUser('dosen');
        $this->createTestDevice($initiator);
        $this->createTestDevice($recipient);
        $room = $this->createTestRoomWithMembers($initiator, $recipient);

        $session1 = BchatRoomSession::create([
            'session_uuid'       => (string) Str::uuid(),
            'room_id'            => $room->id,
            'generation_number'  => 1,
            'status'             => 'ACTIVE',
            'group_id_hash'      => $room->group_id_hash,
            'start_epoch'        => 1,
            'start_sequence'     => 1,
            'current_sequence'   => 5,
            'activated_at'       => now(),
        ]);

        $room->current_sequence = 5;
        $room->save();

        for ($i = 1; $i <= 5; $i++) {
            BchatMessage::create([
                'room_id'             => $room->id,
                'session_id'          => $session1->id,
                'server_sequence'     => $i,
                'message_uuid'        => (string) Str::uuid(),
                'sender_id'           => $initiator->id,
                'epoch'               => 1,
                'generation'          => 0,
                'content_type'        => 1,
                'ciphertext_payload'  => 'cipher_' . $i,
                'payload_hash_sha256' => hash('sha256', 'cipher_' . $i),
                'client_sent_at'      => now(),
            ]);
        }

        // Buat generasi 2 (PENDING)
        $sessionService = app(BchatRoomSessionService::class);
        $session2 = $sessionService->createNextSession($initiator, $room);
        $this->assertEquals('PENDING', $session2->status);

        // Kirim pesan selama masa PENDING
        $msgService = app(BchatMessageService::class);
        $rawPayload = 'message_during_pending_transition';
        $res = $msgService->sendMessage($initiator, $room->room_uuid, [
            'message_uuid'        => (string) Str::uuid(),
            'epoch'               => 1,
            'generation'          => 0,
            'content_type'        => 1,
            'ciphertext_payload'  => base64_encode($rawPayload),
            'payload_hash_sha256' => hash('sha256', $rawPayload),
            'client_sent_at'      => now()->toIso8601String(),
        ]);

        $message = $res['message'];
        $this->assertEquals($session1->id, $message->session_id);
        $this->assertEquals(6, $message->server_sequence);
        $this->assertEquals(6, $room->fresh()->current_sequence);
        $this->assertEquals(6, $session1->fresh()->current_sequence);
    }

    /**
     * Skenario 11: Welcome ACK yang valid mengarsipkan sesi lama dan mengaktifkan sesi baru secara atomik.
     */
    public function test_scenario_11_valid_welcome_ack_atomically_archives_old_and_activates_new_session(): void
    {
        $initiator = $this->createTestUser('mahasiswa');
        $recipient = $this->createTestUser('dosen');
        $initDev = $this->createTestDevice($initiator);
        $recipDev = $this->createTestDevice($recipient);
        $room = $this->createTestRoomWithMembers($initiator, $recipient);

        $session1 = BchatRoomSession::create([
            'session_uuid'       => (string) Str::uuid(),
            'room_id'            => $room->id,
            'generation_number'  => 1,
            'status'             => 'ACTIVE',
            'group_id_hash'      => $room->group_id_hash,
            'start_epoch'        => 1,
            'start_sequence'     => 1,
            'current_sequence'   => 10,
            'activated_at'       => now()->subHour(),
        ]);
        $room->current_sequence = 10;
        $room->current_epoch = 1;
        $room->save();

        $sessionService = app(BchatRoomSessionService::class);
        $session2 = $sessionService->createNextSession($initiator, $room);
        $this->assertEquals('PENDING', $session2->status);

        $keyPackage = $this->createTestKeyPackage($recipient, $recipDev['device']);
        $challenge = $sessionService->publishSessionWelcome($initiator, $session2, [
            'recipient_device_uuid' => $recipDev['device']->device_uuid,
            'key_package_hash'      => $keyPackage->key_package_hash,
            'group_id_hash'         => hash('sha256', 'group_session_2'),
            'welcome_message'       => base64_encode('welcome_data_2'),
        ]);

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

        $validSig = base64_encode(sodium_crypto_sign_detached($canonicalPayload, $recipDev['secretKey']));

        $activated = $sessionService->processSessionWelcomeAck($recipient, $session2, [
            'challenge_uuid' => $challenge->challenge_uuid,
            'signature'      => $validSig,
        ]);

        $this->assertEquals('ACTIVE', $activated->status);
        $this->assertEquals(11, $activated->start_sequence);
        $this->assertNotNull($activated->activated_at);

        // Sesi lama terarsipkan secara atomik
        $session1Fresh = $session1->fresh();
        $this->assertEquals('ARCHIVED', $session1Fresh->status);
        $this->assertEquals(10, $session1Fresh->end_sequence);
        $this->assertEquals(1, $session1Fresh->end_epoch);
        $this->assertNotNull($session1Fresh->archived_at);

        // Hanya ada 1 sesi ACTIVE di room ini
        $this->assertEquals(1, BchatRoomSession::where('room_id', $room->id)->where('status', 'ACTIVE')->count());
    }

    /**
     * Skenario 12: Welcome ACK yang tidak valid (signature salah, challenge salah) TIDAK mengubah status sesi lama maupun baru.
     */
    public function test_scenario_12_invalid_welcome_ack_leaves_old_and_new_session_states_unchanged(): void
    {
        $initiator = $this->createTestUser('mahasiswa');
        $recipient = $this->createTestUser('dosen');
        $this->createTestDevice($initiator);
        $recipDev = $this->createTestDevice($recipient);
        $room = $this->createTestRoomWithMembers($initiator, $recipient);

        $session1 = BchatRoomSession::create([
            'session_uuid'       => (string) Str::uuid(),
            'room_id'            => $room->id,
            'generation_number'  => 1,
            'status'             => 'ACTIVE',
            'group_id_hash'      => $room->group_id_hash,
            'start_epoch'        => 1,
            'start_sequence'     => 1,
            'current_sequence'   => 5,
            'activated_at'       => now(),
        ]);

        $sessionService = app(BchatRoomSessionService::class);
        $session2 = $sessionService->createNextSession($initiator, $room);

        $keyPackage = $this->createTestKeyPackage($recipient, $recipDev['device']);
        $challenge = $sessionService->publishSessionWelcome($initiator, $session2, [
            'recipient_device_uuid' => $recipDev['device']->device_uuid,
            'key_package_hash'      => $keyPackage->key_package_hash,
            'group_id_hash'         => hash('sha256', 'group_session_2'),
            'welcome_message'       => base64_encode('welcome_data_2'),
        ]);

        // Signature palsu
        try {
            $sessionService->processSessionWelcomeAck($recipient, $session2, [
                'challenge_uuid' => $challenge->challenge_uuid,
                'signature'      => base64_encode(random_bytes(64)),
            ]);
            $this->fail('Harus gagal karena signature palsu');
        } catch (BchatRoomSessionException $e) {
            $this->assertEquals('SIGNATURE_VERIFICATION_FAILED', $e->getErrorCode());
        }

        // Status kedua sesi tetap tidak berubah
        $this->assertEquals('ACTIVE', $session1->fresh()->status);
        $this->assertEquals('PENDING', $session2->fresh()->status);
    }

    /**
     * Skenario 13: Welcome yang kedaluwarsa atau gagal verifikasi tidak menyebabkan room kehilangan sesi aktif.
     */
    public function test_scenario_13_expired_welcome_does_not_cause_room_to_lose_active_session(): void
    {
        $initiator = $this->createTestUser('mahasiswa');
        $recipient = $this->createTestUser('dosen');
        $this->createTestDevice($initiator);
        $recipDev = $this->createTestDevice($recipient);
        $room = $this->createTestRoomWithMembers($initiator, $recipient);

        $session1 = BchatRoomSession::create([
            'session_uuid'       => (string) Str::uuid(),
            'room_id'            => $room->id,
            'generation_number'  => 1,
            'status'             => 'ACTIVE',
            'group_id_hash'      => $room->group_id_hash,
            'start_epoch'        => 1,
            'start_sequence'     => 1,
            'current_sequence'   => 5,
            'activated_at'       => now(),
        ]);

        $sessionService = app(BchatRoomSessionService::class);
        $session2 = $sessionService->createNextSession($initiator, $room);

        $keyPackage = $this->createTestKeyPackage($recipient, $recipDev['device']);
        $challenge = $sessionService->publishSessionWelcome($initiator, $session2, [
            'recipient_device_uuid' => $recipDev['device']->device_uuid,
            'key_package_hash'      => $keyPackage->key_package_hash,
            'group_id_hash'         => hash('sha256', 'group_session_2'),
            'welcome_message'       => base64_encode('welcome_data_2'),
        ]);

        // Kedaluwarsa-kan challenge
        $challenge->expires_at = now()->subMinutes(10);
        $challenge->save();

        try {
            $sessionService->processSessionWelcomeAck($recipient, $session2, [
                'challenge_uuid' => $challenge->challenge_uuid,
                'signature'      => base64_encode(random_bytes(64)),
            ]);
            $this->fail('Harus gagal karena challenge kedaluwarsa');
        } catch (BchatRoomSessionException $e) {
            $this->assertEquals('CHALLENGE_EXPIRED', $e->getErrorCode());
        }

        // Room tetap memiliki sesi aktif (Sesi 1)
        $this->assertEquals('ACTIVE', $session1->fresh()->status);
        $this->assertEquals('PENDING', $session2->fresh()->status);
        $this->assertTrue($room->hasActiveCryptoSession());
    }

    /**
     * Skenario 14: Dua Welcome ACK yang diproses bersamaan tidak menghasilkan dua sesi ACTIVE.
     */
    public function test_scenario_14_concurrent_welcome_acks_do_not_produce_two_active_sessions(): void
    {
        $initiator = $this->createTestUser('mahasiswa');
        $recipient = $this->createTestUser('dosen');
        $this->createTestDevice($initiator);
        $recipDev = $this->createTestDevice($recipient);
        $room = $this->createTestRoomWithMembers($initiator, $recipient);

        $session1 = BchatRoomSession::create([
            'session_uuid'       => (string) Str::uuid(),
            'room_id'            => $room->id,
            'generation_number'  => 1,
            'status'             => 'ACTIVE',
            'group_id_hash'      => $room->group_id_hash,
            'start_epoch'        => 1,
            'start_sequence'     => 1,
            'current_sequence'   => 5,
            'activated_at'       => now(),
        ]);

        $sessionService = app(BchatRoomSessionService::class);
        $session2 = $sessionService->createNextSession($initiator, $room);

        $keyPackage = $this->createTestKeyPackage($recipient, $recipDev['device']);
        $challenge = $sessionService->publishSessionWelcome($initiator, $session2, [
            'recipient_device_uuid' => $recipDev['device']->device_uuid,
            'key_package_hash'      => $keyPackage->key_package_hash,
            'group_id_hash'         => hash('sha256', 'group_session_2'),
            'welcome_message'       => base64_encode('welcome_data_2'),
        ]);

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

        $validSig = base64_encode(sodium_crypto_sign_detached($canonicalPayload, $recipDev['secretKey']));

        // Pemrosesan ACK pertama berhasil
        $sessionService->processSessionWelcomeAck($recipient, $session2, [
            'challenge_uuid' => $challenge->challenge_uuid,
            'signature'      => $validSig,
        ]);

        // Pemrosesan ACK kedua untuk challenge yang sama ditolak (CHALLENGE_ALREADY_CONSUMED atau INVALID_SESSION_STATUS)
        try {
            $sessionService->processSessionWelcomeAck($recipient, $session2, [
                'challenge_uuid' => $challenge->challenge_uuid,
                'signature'      => $validSig,
            ]);
            $this->fail('ACK kedua harus gagal');
        } catch (BchatRoomSessionException $e) {
            $this->assertContains($e->getErrorCode(), ['CHALLENGE_ALREADY_CONSUMED', 'INVALID_SESSION_STATUS', 'SESSION_NOT_PENDING']);
        }

        // Tepat 1 sesi ACTIVE pada room ini (didukung oleh database index uk_session_active_room_slot)
        $this->assertEquals(1, BchatRoomSession::where('room_id', $room->id)->where('status', 'ACTIVE')->count());
    }

    /**
     * Skenario 15: Rollback transaksi pada aktivasi sesi baru mempertahankan sesi lama tetap ACTIVE.
     */
    public function test_scenario_15_transaction_rollback_during_session_activation_preserves_old_session_as_active(): void
    {
        $initiator = $this->createTestUser('mahasiswa');
        $recipient = $this->createTestUser('dosen');
        $this->createTestDevice($initiator);
        $this->createTestDevice($recipient);
        $room = $this->createTestRoomWithMembers($initiator, $recipient);

        $session1 = BchatRoomSession::create([
            'session_uuid'       => (string) Str::uuid(),
            'room_id'            => $room->id,
            'generation_number'  => 1,
            'status'             => 'ACTIVE',
            'group_id_hash'      => $room->group_id_hash,
            'start_epoch'        => 1,
            'start_sequence'     => 1,
            'current_sequence'   => 5,
            'activated_at'       => now(),
        ]);

        $session2 = BchatRoomSession::create([
            'session_uuid'       => (string) Str::uuid(),
            'room_id'            => $room->id,
            'generation_number'  => 2,
            'status'             => 'PENDING',
            'group_id_hash'      => hash('sha256', 'session_2'),
            'start_epoch'        => 1,
            'start_sequence'     => 6,
            'current_sequence'   => 5,
        ]);

        try {
            DB::transaction(function () use ($session1, $session2) {
                $session1->update(['status' => 'ARCHIVED', 'archived_at' => now()]);
                $session2->update(['status' => 'ACTIVE', 'activated_at' => now()]);

                throw new \RuntimeException('Simulated catastrophic database failure during transition');
            });
        } catch (\RuntimeException $e) {
            // Expected
        }

        // Konsistensi terjaga: Sesi 1 tetap ACTIVE, Sesi 2 tetap PENDING
        $this->assertEquals('ACTIVE', $session1->fresh()->status);
        $this->assertEquals('PENDING', $session2->fresh()->status);
    }

    /**
     * Skenario 16: Sesi ARCHIVED dan PENDING ditolak untuk pengiriman pesan.
     */
    public function test_scenario_16_archived_and_pending_sessions_are_rejected_for_message_sending(): void
    {
        $initiator = $this->createTestUser('mahasiswa');
        $recipient = $this->createTestUser('dosen');
        $this->createTestDevice($initiator);
        $this->createTestDevice($recipient);
        $room = $this->createTestRoomWithMembers($initiator, $recipient);

        $msgService = app(BchatMessageService::class);
        $rawPayload = 'cipher_msg_attempt';

        // Kasus A: Room hanya memiliki sesi ARCHIVED
        $session1 = BchatRoomSession::create([
            'session_uuid'       => (string) Str::uuid(),
            'room_id'            => $room->id,
            'generation_number'  => 1,
            'status'             => 'ARCHIVED',
            'group_id_hash'      => $room->group_id_hash,
            'start_epoch'        => 1,
            'start_sequence'     => 1,
            'current_sequence'   => 5,
            'archived_at'        => now(),
        ]);

        try {
            $msgService->sendMessage($initiator, $room->room_uuid, [
                'message_uuid'        => (string) Str::uuid(),
                'epoch'               => 1,
                'generation'          => 0,
                'content_type'        => 1,
                'ciphertext_payload'  => base64_encode($rawPayload),
                'payload_hash_sha256' => hash('sha256', $rawPayload),
                'client_sent_at'      => now()->toIso8601String(),
            ]);
            $this->fail('Pengiriman harus gagal jika sesi ARCHIVED');
        } catch (BchatMessageException $e) {
            $this->assertEquals(409, $e->getHttpStatus());
            $this->assertEquals('Ruang obrolan tidak memiliki sesi kriptografi yang aktif.', $e->getMessage());
        }

        // Kasus B: Room hanya memiliki sesi ARCHIVED dan PENDING (tidak ada ACTIVE)
        $session2 = BchatRoomSession::create([
            'session_uuid'       => (string) Str::uuid(),
            'room_id'            => $room->id,
            'generation_number'  => 2,
            'status'             => 'PENDING',
            'group_id_hash'      => hash('sha256', 'pending_gen'),
            'start_epoch'        => 1,
            'start_sequence'     => 6,
            'current_sequence'   => 5,
        ]);

        try {
            $msgService->sendMessage($initiator, $room->room_uuid, [
                'message_uuid'        => (string) Str::uuid(),
                'epoch'               => 1,
                'generation'          => 0,
                'content_type'        => 1,
                'ciphertext_payload'  => base64_encode($rawPayload),
                'payload_hash_sha256' => hash('sha256', $rawPayload),
                'client_sent_at'      => now()->toIso8601String(),
            ]);
            $this->fail('Pengiriman harus gagal jika sesi PENDING');
        } catch (BchatMessageException $e) {
            $this->assertEquals(409, $e->getHttpStatus());
            $this->assertEquals('Ruang obrolan tidak memiliki sesi kriptografi yang aktif.', $e->getMessage());
        }
    }

    /**
     * Skenario 17: Endpoint API GET /sessions/pending dan GET /sessions/{sessionUuid}/welcome-challenge
     */
    public function test_scenario_17_api_can_get_pending_session_and_welcome_challenge(): void
    {
        $initiator = $this->createTestUser('mahasiswa');
        $recipient = $this->createTestUser('dosen');
        $initDev = $this->createTestDevice($initiator);
        $recipDev = $this->createTestDevice($recipient);
        $room = $this->createTestRoomWithMembers($initiator, $recipient);

        $sessionService = app(BchatRoomSessionService::class);

        // Buat Gen 1 ACTIVE
        $session1 = BchatRoomSession::create([
            'session_uuid'       => (string) Str::uuid(),
            'room_id'            => $room->id,
            'generation_number'  => 1,
            'status'             => 'ACTIVE',
            'group_id_hash'      => $room->group_id_hash,
            'start_epoch'        => 1,
            'start_sequence'     => 1,
            'current_sequence'   => 10,
            'activated_at'       => now(),
        ]);
        $room->current_sequence = 10;
        $room->save();

        // 1. Buat Gen 2 PENDING
        $session2 = $sessionService->createNextSession($initiator, $room);

        // Mahasiswa publish welcome
        $keyPackage = $this->createTestKeyPackage($recipient, $recipDev['device']);
        $rawWelcome = 'welcome_binary_payload_gen2';
        $challenge = $sessionService->publishSessionWelcome($initiator, $session2, [
            'recipient_device_uuid' => $recipDev['device']->device_uuid,
            'key_package_hash'      => $keyPackage->key_package_hash,
            'group_id_hash'         => hash('sha256', 'group_gen_2'),
            'welcome_message'       => base64_encode($rawWelcome),
        ]);

        // Dosen cek pending session
        Sanctum::actingAs($recipient);
        $resPending = $this->withHeader('X-Bchat-Device-Uuid', $recipDev['device']->device_uuid)
            ->getJson("/api/bchat/rooms/{$room->room_uuid}/sessions/pending");
        $resPending->assertStatus(200)
            ->assertJsonPath('data.session_uuid', $session2->session_uuid)
            ->assertJsonPath('data.status', 'PENDING');

        // Dosen fetch welcome-challenge via API
        $resChAfter = $this->withHeader('X-Bchat-Device-Uuid', $recipDev['device']->device_uuid)
            ->getJson("/api/bchat/rooms/{$room->room_uuid}/sessions/{$session2->session_uuid}/welcome-challenge");
        $resChAfter->assertStatus(200)
            ->assertJsonPath('data.challenge_uuid', $challenge->challenge_uuid)
            ->assertJsonPath('data.session_uuid', $session2->session_uuid)
            ->assertJsonPath('data.welcome_message', base64_encode($rawWelcome));
    }

    /**
     * Skenario 18: Welcome ACK kedaluwarsa ditolak dan tidak mengarsipkan Generation 1.
     */
    public function test_scenario_18_expired_welcome_ack_is_rejected_and_does_not_archive_generation_1(): void
    {
        $initiator = $this->createTestUser('mahasiswa');
        $recipient = $this->createTestUser('dosen');
        $initDev = $this->createTestDevice($initiator);
        $recipDev = $this->createTestDevice($recipient);
        $room = $this->createTestRoomWithMembers($initiator, $recipient);

        $session1 = BchatRoomSession::create([
            'session_uuid'       => (string) Str::uuid(),
            'room_id'            => $room->id,
            'generation_number'  => 1,
            'status'             => 'ACTIVE',
            'group_id_hash'      => $room->group_id_hash,
            'start_epoch'        => 1,
            'start_sequence'     => 1,
            'current_sequence'   => 10,
            'activated_at'       => now(),
        ]);
        $room->current_sequence = 10;
        $room->save();

        $sessionService = app(BchatRoomSessionService::class);
        $session2 = $sessionService->createNextSession($initiator, $room);

        $keyPackage = $this->createTestKeyPackage($recipient, $recipDev['device']);
        $challenge = $sessionService->publishSessionWelcome($initiator, $session2, [
            'recipient_device_uuid' => $recipDev['device']->device_uuid,
            'key_package_hash'      => $keyPackage->key_package_hash,
            'group_id_hash'         => hash('sha256', 'group_gen_2'),
            'welcome_message'       => base64_encode('welcome_data'),
        ]);

        // Simulasikan waktu lewat dari expired
        $challenge->expires_at = now()->subMinutes(10);
        $challenge->save();

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
        $validSig = base64_encode(sodium_crypto_sign_detached($canonicalPayload, $recipDev['secretKey']));

        try {
            $sessionService->processSessionWelcomeAck($recipient, $session2, [
                'challenge_uuid' => $challenge->challenge_uuid,
                'signature'      => $validSig,
            ]);
            $this->fail('Harus gagal karena challenge kedaluwarsa');
        } catch (BchatRoomSessionException $e) {
            $this->assertEquals('CHALLENGE_EXPIRED', $e->getErrorCode());
            $this->assertEquals(409, $e->getHttpStatus());
        }

        // Gen 1 tetap ACTIVE, Gen 2 tetap PENDING
        $this->assertEquals('ACTIVE', $session1->fresh()->status);
        $this->assertEquals('PENDING', $session2->fresh()->status);
    }

    /**
     * Skenario 19: Replay Welcome ACK ditolak.
     */
    public function test_scenario_19_replay_welcome_ack_is_rejected(): void
    {
        $initiator = $this->createTestUser('mahasiswa');
        $recipient = $this->createTestUser('dosen');
        $initDev = $this->createTestDevice($initiator);
        $recipDev = $this->createTestDevice($recipient);
        $room = $this->createTestRoomWithMembers($initiator, $recipient);

        $session1 = BchatRoomSession::create([
            'session_uuid'       => (string) Str::uuid(),
            'room_id'            => $room->id,
            'generation_number'  => 1,
            'status'             => 'ACTIVE',
            'group_id_hash'      => $room->group_id_hash,
            'start_epoch'        => 1,
            'start_sequence'     => 1,
            'current_sequence'   => 10,
            'activated_at'       => now(),
        ]);
        $room->current_sequence = 10;
        $room->save();

        $sessionService = app(BchatRoomSessionService::class);
        $session2 = $sessionService->createNextSession($initiator, $room);

        $keyPackage = $this->createTestKeyPackage($recipient, $recipDev['device']);
        $challenge = $sessionService->publishSessionWelcome($initiator, $session2, [
            'recipient_device_uuid' => $recipDev['device']->device_uuid,
            'key_package_hash'      => $keyPackage->key_package_hash,
            'group_id_hash'         => hash('sha256', 'group_gen_2'),
            'welcome_message'       => base64_encode('welcome_data'),
        ]);

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
        $validSig = base64_encode(sodium_crypto_sign_detached($canonicalPayload, $recipDev['secretKey']));

        // ACK pertama sukses
        $activated = $sessionService->processSessionWelcomeAck($recipient, $session2, [
            'challenge_uuid' => $challenge->challenge_uuid,
            'signature'      => $validSig,
        ]);
        $this->assertEquals('ACTIVE', $activated->status);

        // Replay ACK kedua harus ditolak
        try {
            $sessionService->processSessionWelcomeAck($recipient, $session2, [
                'challenge_uuid' => $challenge->challenge_uuid,
                'signature'      => $validSig,
            ]);
            $this->fail('Replay ACK harus ditolak');
        } catch (BchatRoomSessionException $e) {
            $this->assertEquals('INVALID_SESSION_STATUS', $e->getErrorCode());
            $this->assertEquals(409, $e->getHttpStatus());
        }
    }

    /**
     * Skenario 20: Perangkat revoked dan non-trusted ditolak dari seluruh operasi sesi.
     */
    public function test_scenario_20_revoked_and_untrusted_devices_are_rejected(): void
    {
        $initiator = $this->createTestUser('mahasiswa');
        $recipient = $this->createTestUser('dosen');
        $initDev = $this->createTestDevice($initiator);
        $this->createTestDevice($recipient);
        $room = $this->createTestRoomWithMembers($initiator, $recipient);

        // Tandai perangkat inisiator sebagai REVOKED
        $initDev['device']->status = 'REVOKED';
        $initDev['device']->is_trusted = false;
        $initDev['device']->save();

        Sanctum::actingAs($initiator);

        // Coba buat sesi baru dengan perangkat revoked
        $response = $this->withHeader('X-Bchat-Device-Uuid', $initDev['device']->device_uuid)
            ->postJson("/api/bchat/rooms/{$room->room_uuid}/sessions/next");

        $response->assertStatus(403)
            ->assertJsonPath('error_code', 'DEVICE_NOT_ACTIVE');
    }
}
