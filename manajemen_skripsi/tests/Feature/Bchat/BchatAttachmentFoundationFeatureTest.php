<?php

declare(strict_types=1);

namespace Tests\Feature\Bchat;

use App\Exceptions\BchatAttachmentException;
use App\Models\BchatAttachment;
use App\Models\BchatAttachmentChunk;
use App\Models\BchatMessage;
use App\Models\BchatRoom;
use App\Models\BchatRoomMember;
use App\Models\BchatUploadSession;
use App\Models\Dosen;
use App\Models\Mahasiswa;
use App\Models\PenugasanPembimbing;
use App\Models\Skripsi;
use App\Models\User;
use App\Policies\BchatAttachmentPolicy;
use App\Services\BchatAttachmentService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class BchatAttachmentFoundationFeatureTest extends TestCase
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
            'group_id_hash'           => hash('sha256', 'group_context_attachment'),
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
     * 1. Relasi model BchatUploadSession, BchatAttachment, BchatAttachmentChunk,
     *    BchatRoom, dan BchatMessage bekerja sesuai skema migration.
     */
    public function test_01_models_and_relations_work_according_to_migration_schema(): void
    {
        $mhsUser = $this->createTestUser('mahasiswa');
        $dosUser = $this->createTestUser('dosen');
        $fixture = $this->createTestRoomWithMembers($mhsUser, $dosUser);
        $room = $fixture['room'];

        // Buat upload session
        $session = BchatUploadSession::create([
            'session_uuid' => (string) Str::uuid(),
            'room_id'      => $room->id,
            'user_id'      => $mhsUser->id,
            'total_bytes'  => 2097208, // 2 chunk x (1 MiB + 28 B)
            'total_chunks' => 2,
            'status'       => 'UPLOADING',
            'expires_at'   => now()->addMinutes(120),
        ]);

        $this->assertFalse($session->isExpired());
        $this->assertTrue($session->room->is($room));
        $this->assertTrue($session->user->is($mhsUser));

        // Buat message manifest
        $message = BchatMessage::create([
            'room_id'             => $room->id,
            'server_sequence'     => 1,
            'message_uuid'        => (string) Str::uuid(),
            'sender_id'           => $mhsUser->id,
            'epoch'               => 1,
            'generation'          => 0,
            'content_type'        => 2, // Attachment manifest
            'ciphertext_payload'  => 'fake_encrypted_attachment_manifest_wire',
            'payload_hash_sha256' => hash('sha256', 'fake_encrypted_attachment_manifest_wire'),
            'client_sent_at'      => now(),
        ]);

        // Buat attachment
        $attachment = BchatAttachment::create([
            'attachment_uuid'   => (string) Str::uuid(),
            'upload_session_id' => $session->id,
            'room_id'           => $room->id,
            'message_id'        => $message->id,
            'plaintext_bytes'   => 2097152, // 2 MiB
            'ciphertext_bytes'  => 2097208, // + 56 B framing
            'total_chunks'      => 2,
            'file_hash_sha256'  => hash('sha256', 'combined_ciphertext_dummy'),
            'status'            => 'ATTACHED',
        ]);

        // Buat 2 chunks
        $chunk0 = BchatAttachmentChunk::create([
            'attachment_id'       => $attachment->id,
            'chunk_index'         => 0,
            'payload_length'      => 1048604,
            'storage_path'        => 'bchat_attachments/ab/ab1234567890.bin',
            'storage_hash_sha256' => hash('sha256', 'chunk0_bytes'),
        ]);

        $chunk1 = BchatAttachmentChunk::create([
            'attachment_id'       => $attachment->id,
            'chunk_index'         => 1,
            'payload_length'      => 1048604,
            'storage_path'        => 'bchat_attachments/cd/cd1234567890.bin',
            'storage_hash_sha256' => hash('sha256', 'chunk1_bytes'),
        ]);

        // Verifikasi relasi balik
        $this->assertTrue($attachment->room->is($room));
        $this->assertTrue($attachment->uploadSession->is($session));
        $this->assertTrue($attachment->message->is($message));
        $this->assertCount(2, $attachment->chunks);
        $this->assertEquals(0, $attachment->chunks->first()->chunk_index);
        $this->assertTrue($chunk0->attachment->is($attachment));

        // Verifikasi relasi pada BchatRoom
        $this->assertTrue($room->uploadSessions->contains('id', $session->id));
        $this->assertTrue($room->attachments->contains('id', $attachment->id));

        // Verifikasi relasi pada BchatMessage
        $this->assertTrue($message->attachments->contains('id', $attachment->id));
    }

    /**
     * 2. BchatAttachmentPolicy: Mengizinkan anggota sah dan menolak pengguna non-anggota.
     */
    public function test_02_policy_authorizes_members_and_rejects_non_members(): void
    {
        $mhsUser = $this->createTestUser('mahasiswa');
        $dosUser = $this->createTestUser('dosen');
        $strangerUser = $this->createTestUser('mahasiswa');
        $fixture = $this->createTestRoomWithMembers($mhsUser, $dosUser);
        $room = $fixture['room'];

        $session = BchatUploadSession::create([
            'session_uuid' => (string) Str::uuid(),
            'room_id'      => $room->id,
            'user_id'      => $mhsUser->id,
            'total_bytes'  => 1048604,
            'total_chunks' => 1,
            'status'       => 'COMPLETED',
            'expires_at'   => now()->addMinutes(120),
        ]);

        $attachment = BchatAttachment::create([
            'attachment_uuid'   => (string) Str::uuid(),
            'upload_session_id' => $session->id,
            'room_id'           => $room->id,
            'message_id'        => null,
            'plaintext_bytes'   => 1048576,
            'ciphertext_bytes'  => 1048604,
            'total_chunks'      => 1,
            'file_hash_sha256'  => hash('sha256', 'single_chunk_file'),
            'status'            => 'PENDING_MESSAGE',
        ]);

        $policy = new BchatAttachmentPolicy();

        // Anggota sah (mahasiswa & dosen bimbingan) diizinkan viewAny, view, dan download
        $this->assertTrue($policy->viewAny($mhsUser, $room));
        $this->assertTrue($policy->viewAny($dosUser, $room));
        $this->assertTrue($policy->view($mhsUser, $attachment));
        $this->assertTrue($policy->view($dosUser, $attachment));
        $this->assertTrue($policy->download($mhsUser, $attachment));
        $this->assertTrue($policy->download($dosUser, $attachment));

        // Pengguna asing (bukan anggota room) DITOLAK MUTLAK
        $this->assertFalse($policy->viewAny($strangerUser, $room));
        $this->assertFalse($policy->view($strangerUser, $attachment));
        $this->assertFalse($policy->download($strangerUser, $attachment));
        $this->assertFalse($policy->upload($strangerUser, $room));

        // Modifikasi dan penghapusan DITOLAK MUTLAK untuk semua pengguna
        $this->assertFalse($policy->update($mhsUser, $attachment));
        $this->assertFalse($policy->delete($mhsUser, $attachment));
    }

    /**
     * 3. BchatAttachmentPolicy: Admin yang bukan anggota room DITOLAK dari view/download (Zero Plaintext Access).
     */
    public function test_03_policy_denies_admin_from_viewing_or_downloading_attachments(): void
    {
        $mhsUser = $this->createTestUser('mahasiswa');
        $dosUser = $this->createTestUser('dosen');
        $adminUser = $this->createTestUser('admin');
        $fixture = $this->createTestRoomWithMembers($mhsUser, $dosUser);
        $room = $fixture['room'];

        $session = BchatUploadSession::create([
            'session_uuid' => (string) Str::uuid(),
            'room_id'      => $room->id,
            'user_id'      => $mhsUser->id,
            'total_bytes'  => 1048604,
            'total_chunks' => 1,
            'status'       => 'COMPLETED',
            'expires_at'   => now()->addMinutes(120),
        ]);

        $attachment = BchatAttachment::create([
            'attachment_uuid'   => (string) Str::uuid(),
            'upload_session_id' => $session->id,
            'room_id'           => $room->id,
            'message_id'        => null,
            'plaintext_bytes'   => 1048576,
            'ciphertext_bytes'  => 1048604,
            'total_chunks'      => 1,
            'file_hash_sha256'  => hash('sha256', 'admin_test_file'),
            'status'            => 'PENDING_MESSAGE',
        ]);

        $policy = new BchatAttachmentPolicy();

        // Admin bukan anggota room pairwise, maka DITOLAK MUTLAK
        $this->assertFalse($policy->viewAny($adminUser, $room));
        $this->assertFalse($policy->view($adminUser, $attachment));
        $this->assertFalse($policy->download($adminUser, $attachment));
        $this->assertFalse($policy->upload($adminUser, $room));
    }

    /**
     * 4. BchatAttachmentPolicy: Upload ditolak jika room bukan ACTIVE atau penugasan tidak aktif.
     */
    public function test_04_policy_denies_upload_on_non_active_rooms(): void
    {
        $mhsUser = $this->createTestUser('mahasiswa');
        $dosUser = $this->createTestUser('dosen');

        // Room PENDING_WELCOME_ACK
        $pendingFixture = $this->createTestRoomWithMembers($mhsUser, $dosUser, 'PENDING_WELCOME_ACK');
        $policy = new BchatAttachmentPolicy();

        $this->assertFalse($policy->upload($mhsUser, $pendingFixture['room']));

        // Room ARCHIVED_READONLY
        $archivedFixture = $this->createTestRoomWithMembers($mhsUser, $dosUser, 'ARCHIVED_READONLY', 'DIGANTI');
        $this->assertFalse($policy->upload($mhsUser, $archivedFixture['room']));
        // Tetapi anggota masih boleh membaca histori/download
        $this->assertTrue($policy->viewAny($mhsUser, $archivedFixture['room']));
    }

    /**
     * 5. BchatAttachmentService: Penyimpanan dan pembacaan ulang ciphertext menghasilkan byte identik.
     */
    public function test_05_attachment_service_stores_and_retrieves_identical_bytes(): void
    {
        Storage::fake('local');
        $service = new BchatAttachmentService('local');

        $randomBytes = random_bytes(1024 * 64); // 64 KiB ciphertext payload
        $expectedHash = hash('sha256', $randomBytes);

        $storeResult = $service->storeCiphertextChunk($randomBytes, $expectedHash);

        $this->assertArrayHasKey('storage_path', $storeResult);
        $this->assertArrayHasKey('storage_hash_sha256', $storeResult);
        $this->assertEquals($expectedHash, $storeResult['storage_hash_sha256']);
        $this->assertEquals(strlen($randomBytes), $storeResult['payload_length']);

        // Verifikasi fisik di storage fake
        Storage::disk('local')->assertExists($storeResult['storage_path']);

        // Baca ulang dan pastikan identik 100%
        $retrievedBytes = $service->getCiphertextChunk($storeResult['storage_path']);
        $this->assertSame($randomBytes, $retrievedBytes);

        // Verifikasi integritas helper
        $this->assertTrue($service->verifyChunkIntegrity($storeResult['storage_path'], $expectedHash));
    }

    /**
     * 6. BchatAttachmentService: Menolak payload kosong (0 byte) dan mendeteksi ketidakcocokan hash.
     */
    public function test_06_attachment_service_rejects_empty_payload_and_hash_mismatch(): void
    {
        Storage::fake('local');
        $service = new BchatAttachmentService('local');

        // Uji payload kosong
        try {
            $service->storeCiphertextChunk('');
            $this->fail('Expected BchatAttachmentException was not thrown for empty payload.');
        } catch (BchatAttachmentException $e) {
            $this->assertEquals('EMPTY_CIPHERTEXT_PAYLOAD', $e->getErrorCode());
            $this->assertEquals(422, $e->getHttpStatus());
        }

        // Uji hash mismatch
        $bytes = 'some_ciphertext_payload_bytes';
        $wrongHash = str_repeat('a', 64);

        try {
            $service->storeCiphertextChunk($bytes, $wrongHash);
            $this->fail('Expected BchatAttachmentException was not thrown for hash mismatch.');
        } catch (BchatAttachmentException $e) {
            $this->assertEquals('STORAGE_HASH_MISMATCH', $e->getErrorCode());
            $this->assertEquals(422, $e->getHttpStatus());
        }
    }

    /**
     * 7. BchatAttachmentService: Mitigasi path traversal menolak path di luar bchat_attachments/.
     */
    public function test_07_attachment_service_mitigates_path_traversal(): void
    {
        Storage::fake('local');
        $service = new BchatAttachmentService('local');

        $maliciousPaths = [
            '../etc/passwd',
            'bchat_attachments/../../secret.txt',
            '/bchat_attachments/ab/chunk.bin',
            'C:\\Windows\\System32\\calc.exe',
            'other_dir/chunk.bin',
        ];

        foreach ($maliciousPaths as $path) {
            try {
                $service->getCiphertextChunk($path);
                $this->fail("Expected BchatAttachmentException for malicious path: {$path}");
            } catch (BchatAttachmentException $e) {
                $this->assertTrue(
                    in_array($e->getErrorCode(), ['INVALID_STORAGE_PATH', 'INVALID_STORAGE_DIRECTORY'], true),
                    "Unexpected error code {$e->getErrorCode()} for path {$path}"
                );
            }
        }
    }
}
