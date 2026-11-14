<?php

declare(strict_types=1);

namespace Tests\Feature\Bchat;

use App\Exceptions\BchatAttachmentException;
use App\Models\BchatAttachment;
use App\Models\BchatAttachmentChunk;
use App\Models\BchatRoom;
use App\Models\BchatRoomMember;
use App\Models\BchatUploadSession;
use App\Models\Dosen;
use App\Models\Mahasiswa;
use App\Models\PenugasanPembimbing;
use App\Models\Skripsi;
use App\Models\User;
use App\Services\BchatAttachmentService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Feature Test untuk API Unggah & Unduh Ciphertext Lampiran E2EE (B-CHAT EXP-02 / Tahap 2).
 */
class BchatAttachmentFeatureTest extends TestCase
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

        Storage::fake('local');
    }

    private function createTestUser(string $role = 'mahasiswa'): User
    {
        return User::factory()->create([
            'role'        => $role,
            'nomor_induk' => (string) rand(1000000000, 9999999999),
        ]);
    }

    private function createTestRoomWithMembers(
        User $mahasiswaUser,
        User $dosenUser,
        string $roomStatus = 'ACTIVE',
        string $assignmentStatus = 'AKTIF',
        bool $mhsIsActive = true,
        bool $dosIsActive = true
    ): array {
        $mhs = Mahasiswa::firstOrCreate(
            ['user_id' => $mahasiswaUser->id],
            [
                'NIM'            => $mahasiswaUser->nomor_induk,
                'nama_mahasiswa' => 'Mhs Attachment Test ' . Str::random(4),
                'prodi'          => 'Informatika',
            ]
        );

        $skripsi = Skripsi::create([
            'id_mahasiswa' => $mhs->id_mahasiswa,
            'judul'        => 'Proposal Attachment Test ' . Str::random(5),
            'status_judul' => 'diajukan',
        ]);

        $dosen = Dosen::firstOrCreate(
            ['user_id' => $dosenUser->id],
            [
                'NIDN'       => $dosenUser->nomor_induk,
                'nama_dosen' => 'Dosen Attachment Test ' . Str::random(4),
                'prodi'      => 'Informatika',
            ]
        );

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
            'group_id_hash'           => hash('sha256', 'group_context_attachment_feature'),
        ]);

        $mhsMember = BchatRoomMember::create([
            'room_id'         => $room->id,
            'user_id'         => $mahasiswaUser->id,
            'role'            => 'mahasiswa',
            'is_initiator'    => true,
            'is_active'       => $mhsIsActive,
            'joined_at_epoch' => 1,
        ]);

        $dosMember = BchatRoomMember::create([
            'room_id'         => $room->id,
            'user_id'         => $dosenUser->id,
            'role'            => 'dosen_pembimbing',
            'is_initiator'    => false,
            'is_active'       => $dosIsActive,
            'joined_at_epoch' => 1,
        ]);

        return compact('room', 'penugasan', 'mhs', 'dosen', 'mhsMember', 'dosMember');
    }

    /**
     * 1. Pengguna nonanggota ditolak saat membuat sesi upload (HTTP 403).
     */
    public function test_01_non_member_is_rejected_with_http_403(): void
    {
        $mhs = $this->createTestUser('mahasiswa');
        $dos = $this->createTestUser('dosen');
        $stranger = $this->createTestUser('mahasiswa');
        $fixture = $this->createTestRoomWithMembers($mhs, $dos);

        Sanctum::actingAs($stranger);

        $response = $this->postJson("/api/bchat/rooms/{$fixture['room']->room_uuid}/attachments/upload-session", [
            'attachment_uuid'  => (string) Str::uuid(),
            'total_chunks'     => 1,
            'ciphertext_bytes' => 1048604,
            'plaintext_bytes'  => 1048576,
        ]);

        $response->assertStatus(403);
        $response->assertJson(['error_code' => 'FORBIDDEN']);
    }

    /**
     * 2. Admin ditolak dari mengunggah atau mengunduh lampiran (Zero Plaintext Access).
     */
    public function test_02_admin_is_rejected_from_upload_and_download(): void
    {
        $mhs = $this->createTestUser('mahasiswa');
        $dos = $this->createTestUser('dosen');
        $admin = $this->createTestUser('admin');
        $fixture = $this->createTestRoomWithMembers($mhs, $dos);

        Sanctum::actingAs($admin);

        // Upload ditolak
        $resUpload = $this->postJson("/api/bchat/rooms/{$fixture['room']->room_uuid}/attachments/upload-session", [
            'attachment_uuid'  => (string) Str::uuid(),
            'total_chunks'     => 1,
            'ciphertext_bytes' => 1048604,
            'plaintext_bytes'  => 1048576,
        ]);
        $resUpload->assertStatus(403);

        // Download ditolak
        $dummyAtt = (string) Str::uuid();
        $resDownload = $this->get("/api/bchat/rooms/{$fixture['room']->room_uuid}/attachments/{$dummyAtt}/chunks/0");
        $resDownload->assertStatus(403);
    }

    /**
     * 3. Anggota room lain tidak dapat mengakses sesi atau attachment (Isolasi Room Ketat).
     */
    public function test_03_other_room_member_cannot_access_session_or_attachment(): void
    {
        $mhs1 = $this->createTestUser('mahasiswa');
        $dos1 = $this->createTestUser('dosen');
        $fixture1 = $this->createTestRoomWithMembers($mhs1, $dos1);

        $mhs2 = $this->createTestUser('mahasiswa');
        $dos2 = $this->createTestUser('dosen');
        $fixture2 = $this->createTestRoomWithMembers($mhs2, $dos2);

        // Mahasiswa 1 membuat sesi di Room 1
        Sanctum::actingAs($mhs1);
        $resSess = $this->postJson("/api/bchat/rooms/{$fixture1['room']->room_uuid}/attachments/upload-session", [
            'attachment_uuid'  => (string) Str::uuid(),
            'total_chunks'     => 1,
            'ciphertext_bytes' => 1048604,
            'plaintext_bytes'  => 1048576,
        ]);
        $resSess->assertStatus(201);
        $sessUuid = $resSess->json('data.session_uuid');

        // Mahasiswa 2 mencoba upload chunk ke sesi Room 1 melalui endpoint Room 2
        Sanctum::actingAs($mhs2);
        $resCross = $this->call(
            'PUT',
            "/api/bchat/rooms/{$fixture2['room']->room_uuid}/attachments/upload-session/{$sessUuid}/chunks/0",
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'application/octet-stream'],
            str_repeat('x', 100)
        );
        $resCross->assertStatus(404);
        $resCross->assertJson(['error_code' => 'SESSION_NOT_FOUND']);
    }

    /**
     * 4. Pengguna tidak dapat mengunggah ke sesi milik pengguna lain di room yang sama.
     */
    public function test_04_user_cannot_upload_to_another_users_session_in_same_room(): void
    {
        $mhs = $this->createTestUser('mahasiswa');
        $dos = $this->createTestUser('dosen');
        $fixture = $this->createTestRoomWithMembers($mhs, $dos);

        // Mahasiswa membuat sesi di room
        Sanctum::actingAs($mhs);
        $resSess = $this->postJson("/api/bchat/rooms/{$fixture['room']->room_uuid}/attachments/upload-session", [
            'attachment_uuid'  => (string) Str::uuid(),
            'total_chunks'     => 1,
            'ciphertext_bytes' => 1048604,
            'plaintext_bytes'  => 1048576,
        ]);
        $sessUuid = $resSess->json('data.session_uuid');

        // Dosen (anggota room yang sama) mencoba mengunggah ke sesi milik mahasiswa
        Sanctum::actingAs($dos);
        $resUpload = $this->call(
            'PUT',
            "/api/bchat/rooms/{$fixture['room']->room_uuid}/attachments/upload-session/{$sessUuid}/chunks/0",
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'application/octet-stream'],
            str_repeat('x', 100)
        );
        $resUpload->assertStatus(403);
        $resUpload->assertJson(['error_code' => 'FORBIDDEN_NOT_SESSION_OWNER']);
    }

    /**
     * 5. Room nonaktif (PENDING_WELCOME_ACK / SUSPENDED) ditolak saat membuat sesi unggah.
     */
    public function test_05_non_active_room_is_rejected(): void
    {
        $mhs = $this->createTestUser('mahasiswa');
        $dos = $this->createTestUser('dosen');
        $fixture = $this->createTestRoomWithMembers($mhs, $dos, 'PENDING_WELCOME_ACK');

        Sanctum::actingAs($mhs);
        $response = $this->postJson("/api/bchat/rooms/{$fixture['room']->room_uuid}/attachments/upload-session", [
            'attachment_uuid'  => (string) Str::uuid(),
            'total_chunks'     => 1,
            'ciphertext_bytes' => 1048604,
            'plaintext_bytes'  => 1048576,
        ]);

        $response->assertStatus(403);
    }

    /**
     * 6 & 7 & 8. Chunk valid disimpan (201), retry chunk identik idempoten (200),
     *            dan chunk dengan hash berbeda pada indeks sama ditolak (409 Conflict).
     */
    public function test_06_chunk_upload_stores_retries_idempotently_and_conflicts_on_hash_mismatch(): void
    {
        $mhs = $this->createTestUser('mahasiswa');
        $dos = $this->createTestUser('dosen');
        $fixture = $this->createTestRoomWithMembers($mhs, $dos);

        Sanctum::actingAs($mhs);

        $chunk0PlainLength = 1000;
        $chunk0PayloadLength = $chunk0PlainLength + 28; // Framing overhead 28 B
        $chunk0Bytes = random_bytes($chunk0PayloadLength);
        $chunk0Hash = hash('sha256', $chunk0Bytes);

        $resSess = $this->postJson("/api/bchat/rooms/{$fixture['room']->room_uuid}/attachments/upload-session", [
            'attachment_uuid'  => (string) Str::uuid(),
            'total_chunks'     => 1,
            'ciphertext_bytes' => $chunk0PayloadLength,
            'plaintext_bytes'  => $chunk0PlainLength,
        ]);
        $sessUuid = $resSess->json('data.session_uuid');

        // 6. Upload chunk pertama kali -> 201 Created
        $resChunk = $this->call(
            'PUT',
            "/api/bchat/rooms/{$fixture['room']->room_uuid}/attachments/upload-session/{$sessUuid}/chunks/0",
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'application/octet-stream', 'HTTP_X_PAYLOAD_HASH' => $chunk0Hash],
            $chunk0Bytes
        );
        $resChunk->assertStatus(201);
        $resChunk->assertJson([
            'status' => 'success',
            'data'   => [
                'chunk_index'         => 0,
                'payload_length'      => $chunk0PayloadLength,
                'storage_hash_sha256' => $chunk0Hash,
                'is_replayed'         => false,
            ],
        ]);

        // 7. Retry chunk identik -> 200 OK idempoten
        $resRetry = $this->call(
            'PUT',
            "/api/bchat/rooms/{$fixture['room']->room_uuid}/attachments/upload-session/{$sessUuid}/chunks/0",
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'application/octet-stream', 'HTTP_X_PAYLOAD_HASH' => $chunk0Hash],
            $chunk0Bytes
        );
        $resRetry->assertStatus(200);
        $resRetry->assertJson([
            'status' => 'success',
            'data'   => [
                'chunk_index' => 0,
                'is_replayed' => true,
            ],
        ]);

        // 8. Upload payload berbeda pada indeks 0 -> 409 Conflict
        $differentBytes = random_bytes($chunk0PayloadLength);
        $resConflict = $this->call(
            'PUT',
            "/api/bchat/rooms/{$fixture['room']->room_uuid}/attachments/upload-session/{$sessUuid}/chunks/0",
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'application/octet-stream'],
            $differentBytes
        );
        $resConflict->assertStatus(409);
        $resConflict->assertJson(['error_code' => 'CHUNK_HASH_CONFLICT']);
    }

    /**
     * 9 & 10. Chunk hilang menggagalkan finalisasi (422), finalisasi sukses jika lengkap (200).
     */
    public function test_09_missing_chunks_fails_completion_and_full_chunks_succeeds(): void
    {
        $mhs = $this->createTestUser('mahasiswa');
        $dos = $this->createTestUser('dosen');
        $fixture = $this->createTestRoomWithMembers($mhs, $dos);

        Sanctum::actingAs($mhs);

        $chunkSize = 1048576; // 1 MiB
        $plainTotal = $chunkSize + 500; // 2 chunks: chunk 0 = 1 MiB, chunk 1 = 500 B
        $totalChunks = 2;
        $cipherTotal = $plainTotal + (2 * 28);

        $resSess = $this->postJson("/api/bchat/rooms/{$fixture['room']->room_uuid}/attachments/upload-session", [
            'attachment_uuid'  => (string) Str::uuid(),
            'total_chunks'     => $totalChunks,
            'ciphertext_bytes' => $cipherTotal,
            'plaintext_bytes'  => $plainTotal,
        ]);
        $sessUuid = $resSess->json('data.session_uuid');

        // Upload chunk 0 saja (chunk 1 sengaja belum diunggah)
        $chunk0Bytes = random_bytes($chunkSize + 28);
        $this->call(
            'PUT',
            "/api/bchat/rooms/{$fixture['room']->room_uuid}/attachments/upload-session/{$sessUuid}/chunks/0",
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'application/octet-stream'],
            $chunk0Bytes
        )->assertStatus(201);

        // 9. Coba finalisasi saat chunk 1 masih hilang -> 422 MISSING_CHUNKS
        $resFailComplete = $this->postJson("/api/bchat/rooms/{$fixture['room']->room_uuid}/attachments/upload-session/{$sessUuid}/complete");
        $resFailComplete->assertStatus(422);
        $resFailComplete->assertJson(['error_code' => 'MISSING_CHUNKS']);

        // Upload chunk 1 (500 + 28 B)
        $chunk1Bytes = random_bytes(500 + 28);
        $this->call(
            'PUT',
            "/api/bchat/rooms/{$fixture['room']->room_uuid}/attachments/upload-session/{$sessUuid}/chunks/1",
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'application/octet-stream'],
            $chunk1Bytes
        )->assertStatus(201);

        // 10. Finalisasi sekarang sukses -> 200 OK
        $fileHash = hash('sha256', $chunk0Bytes . $chunk1Bytes);
        $resSuccess = $this->postJson("/api/bchat/rooms/{$fixture['room']->room_uuid}/attachments/upload-session/{$sessUuid}/complete", [
            'file_hash_sha256' => $fileHash,
        ]);
        $resSuccess->assertStatus(200);
        $resSuccess->assertJson([
            'status' => 'success',
            'data'   => [
                'status'           => 'PENDING_MESSAGE',
                'total_chunks'     => 2,
                'ciphertext_bytes' => $cipherTotal,
                'file_hash_sha256' => $fileHash,
            ],
        ]);
    }

    /**
     * 11. Download menghasilkan byte ciphertext identik tanpa membocorkan path fisik internal.
     */
    public function test_11_download_retrieves_identical_bytes_without_leaking_storage_path(): void
    {
        $mhs = $this->createTestUser('mahasiswa');
        $dos = $this->createTestUser('dosen');
        $fixture = $this->createTestRoomWithMembers($mhs, $dos);

        Sanctum::actingAs($mhs);

        $payloadBytes = random_bytes(1000 + 28);
        $payloadHash = hash('sha256', $payloadBytes);
        $attUuid = (string) Str::uuid();

        $resSess = $this->postJson("/api/bchat/rooms/{$fixture['room']->room_uuid}/attachments/upload-session", [
            'attachment_uuid'  => $attUuid,
            'total_chunks'     => 1,
            'ciphertext_bytes' => 1028,
            'plaintext_bytes'  => 1000,
        ]);
        $sessUuid = $resSess->json('data.session_uuid');

        $this->call(
            'PUT',
            "/api/bchat/rooms/{$fixture['room']->room_uuid}/attachments/upload-session/{$sessUuid}/chunks/0",
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'application/octet-stream'],
            $payloadBytes
        )->assertStatus(201);

        $this->postJson("/api/bchat/rooms/{$fixture['room']->room_uuid}/attachments/upload-session/{$sessUuid}/complete")
            ->assertStatus(200);

        // Dosen (penerima bimbingan yang sah) mengunduh chunk 0
        Sanctum::actingAs($dos);
        $resDownload = $this->get("/api/bchat/rooms/{$fixture['room']->room_uuid}/attachments/{$attUuid}/chunks/0");

        $resDownload->assertStatus(200);
        $this->assertSame($payloadBytes, $resDownload->getContent());
        $this->assertEquals($payloadHash, $resDownload->headers->get('X-Storage-Hash-Sha256'));
        $this->assertEquals('application/octet-stream', $resDownload->headers->get('Content-Type'));

        // Pastikan tidak ada kebocoran path storage fisik di body atau header
        $this->assertStringNotContainsString('storage/app/private', $resDownload->getContent());
        $this->assertNull($resDownload->headers->get('X-Storage-Path'));
    }

    /**
     * 12. Indeks chunk di luar rentang ditolak (HTTP 422).
     */
    public function test_12_chunk_index_out_of_range_is_rejected(): void
    {
        $mhs = $this->createTestUser('mahasiswa');
        $dos = $this->createTestUser('dosen');
        $fixture = $this->createTestRoomWithMembers($mhs, $dos);

        Sanctum::actingAs($mhs);
        $resSess = $this->postJson("/api/bchat/rooms/{$fixture['room']->room_uuid}/attachments/upload-session", [
            'attachment_uuid'  => (string) Str::uuid(),
            'total_chunks'     => 1,
            'ciphertext_bytes' => 1028,
            'plaintext_bytes'  => 1000,
        ]);
        $sessUuid = $resSess->json('data.session_uuid');

        // Total chunk adalah 1 (indeks sah hanya 0), indeks 1 harus ditolak
        $resInvalidIndex = $this->call(
            'PUT',
            "/api/bchat/rooms/{$fixture['room']->room_uuid}/attachments/upload-session/{$sessUuid}/chunks/1",
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'application/octet-stream'],
            random_bytes(1028)
        );
        $resInvalidIndex->assertStatus(422);
        $resInvalidIndex->assertJson(['error_code' => 'CHUNK_INDEX_OUT_OF_RANGE']);
    }

    /**
     * 13. Sesi kedaluwarsa ditolak dari menerima chunk baru (HTTP 410).
     */
    public function test_13_expired_session_is_rejected_with_http_410(): void
    {
        $mhs = $this->createTestUser('mahasiswa');
        $dos = $this->createTestUser('dosen');
        $fixture = $this->createTestRoomWithMembers($mhs, $dos);

        Sanctum::actingAs($mhs);
        $resSess = $this->postJson("/api/bchat/rooms/{$fixture['room']->room_uuid}/attachments/upload-session", [
            'attachment_uuid'  => (string) Str::uuid(),
            'total_chunks'     => 1,
            'ciphertext_bytes' => 1028,
            'plaintext_bytes'  => 1000,
        ]);
        $sessUuid = $resSess->json('data.session_uuid');

        // Paksa sesi kedaluwarsa di database
        BchatUploadSession::where('session_uuid', $sessUuid)->update([
            'expires_at' => now()->subMinutes(10),
        ]);

        $resUpload = $this->call(
            'PUT',
            "/api/bchat/rooms/{$fixture['room']->room_uuid}/attachments/upload-session/{$sessUuid}/chunks/0",
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'application/octet-stream'],
            random_bytes(1028)
        );
        $resUpload->assertStatus(410);
        $resUpload->assertJson(['error_code' => 'SESSION_EXPIRED']);
    }

    /**
     * 14. Payload chunk kosong (0 byte) ditolak mutlak (HTTP 422).
     */
    public function test_14_empty_payload_is_rejected(): void
    {
        $mhs = $this->createTestUser('mahasiswa');
        $dos = $this->createTestUser('dosen');
        $fixture = $this->createTestRoomWithMembers($mhs, $dos);

        Sanctum::actingAs($mhs);
        $resSess = $this->postJson("/api/bchat/rooms/{$fixture['room']->room_uuid}/attachments/upload-session", [
            'attachment_uuid'  => (string) Str::uuid(),
            'total_chunks'     => 1,
            'ciphertext_bytes' => 1028,
            'plaintext_bytes'  => 1000,
        ]);
        $sessUuid = $resSess->json('data.session_uuid');

        $resEmpty = $this->call(
            'PUT',
            "/api/bchat/rooms/{$fixture['room']->room_uuid}/attachments/upload-session/{$sessUuid}/chunks/0",
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'application/octet-stream'],
            ''
        );
        $resEmpty->assertStatus(422);
        $resEmpty->assertJson(['error_code' => 'EMPTY_CHUNK_PAYLOAD']);
    }

    /**
     * 15. Path traversal tidak dapat memanipulasi storage privat backend.
     */
    public function test_15_path_traversal_is_blocked_by_service(): void
    {
        $service = app(BchatAttachmentService::class);

        $this->expectException(BchatAttachmentException::class);
        $service->getCiphertextChunk('bchat_attachments/../../passwords.txt');
    }

    /**
     * 16. Tidak ada nama file asli, MIME plaintext, atau CEK dalam rekaman database server.
     */
    public function test_16_no_plaintext_filename_mime_or_cek_in_server_database(): void
    {
        $mhs = $this->createTestUser('mahasiswa');
        $dos = $this->createTestUser('dosen');
        $fixture = $this->createTestRoomWithMembers($mhs, $dos);

        Sanctum::actingAs($mhs);
        $attUuid = (string) Str::uuid();

        $resSess = $this->postJson("/api/bchat/rooms/{$fixture['room']->room_uuid}/attachments/upload-session", [
            'attachment_uuid'  => $attUuid,
            'total_chunks'     => 1,
            'ciphertext_bytes' => 1028,
            'plaintext_bytes'  => 1000,
        ]);
        $sessUuid = $resSess->json('data.session_uuid');

        $attachment = BchatAttachment::where('attachment_uuid', $attUuid)->first();
        $session = BchatUploadSession::where('session_uuid', $sessUuid)->first();

        // Periksa seluruh atribut pada model tidak memuat nama file, MIME, atau CEK
        $attArray = $attachment->toArray();
        $sessArray = $session->toArray();

        $forbiddenKeys = ['file_name', 'filename', 'original_name', 'mime', 'mime_type', 'cek', 'key', 'plaintext'];
        foreach ($forbiddenKeys as $forbidden) {
            $this->assertArrayNotHasKey($forbidden, $attArray);
            $this->assertArrayNotHasKey($forbidden, $sessArray);
        }
    }
}
