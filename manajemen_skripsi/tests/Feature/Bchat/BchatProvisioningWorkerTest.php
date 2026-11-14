<?php

namespace Tests\Feature\Bchat;

use App\Constants\AcademicConstant;
use App\Models\BchatProvisioningOutbox;
use App\Models\BchatRoom;
use App\Models\BchatRoomMember;
use App\Models\Dosen;
use App\Models\Mahasiswa;
use App\Models\PenugasanPembimbing;
use App\Models\Skripsi;
use App\Models\User;
use App\Services\BchatProvisioningWorkerService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class BchatProvisioningWorkerTest extends TestCase
{
    use DatabaseTransactions;

    private BchatProvisioningWorkerService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $activeDb = DB::connection()->getDatabaseName();
        $this->assertEquals(
            'manajemen_skripsi_test',
            $activeDb,
            'CRITICAL SECURITY: Test suite B-CHAT HANYA boleh berjalan pada database manajemen_skripsi_test.'
        );

        $this->service = new BchatProvisioningWorkerService('worker:test-suite:1001:aabbccdd');
    }

    // -----------------------------------------------------------------------
    // FIXTURE HELPERS
    // -----------------------------------------------------------------------

    private function buatMahasiswa(?string $userRole = 'mahasiswa'): array
    {
        $nim = (string) rand(1000000000, 9999999999);
        $user = User::factory()->create([
            'role'        => $userRole,
            'nomor_induk' => $nim,
            'name'        => 'Mahasiswa ' . $nim,
        ]);

        $prodi = 'Informatika';
        $fakultas = AcademicConstant::getFakultasForProdi($prodi) ?? 'Fakultas Sains dan Teknologi';

        $mahasiswa = Mahasiswa::create([
            'user_id'        => $user->id,
            'NIM'            => $nim,
            'nama_mahasiswa' => 'Mahasiswa ' . $nim,
            'prodi'          => $prodi,
            'fakultas'       => $fakultas,
        ]);

        return compact('user', 'mahasiswa');
    }

    private function buatDosen(?string $userRole = 'dosen'): array
    {
        $nidn = (string) rand(1000000000, 9999999999);
        $user = User::factory()->create([
            'role'        => $userRole,
            'nomor_induk' => $nidn,
            'name'        => 'Dosen ' . $nidn,
        ]);

        $dosen = Dosen::create([
            'user_id'    => $user->id,
            'NIDN'       => $nidn,
            'nama_dosen' => 'Dosen ' . $nidn,
        ]);

        return compact('user', 'dosen');
    }

    private function buatSkripsi(Mahasiswa $mahasiswa): Skripsi
    {
        return Skripsi::create([
            'id_mahasiswa' => $mahasiswa->id_mahasiswa,
            'judul'        => 'Rancang Bangun E2EE Chat ' . uniqid(),
            'status_judul' => 'disetujui',
        ]);
    }

    private function buatPenugasan(Skripsi $skripsi, Dosen $dosen, string $status = 'AKTIF'): PenugasanPembimbing
    {
        return PenugasanPembimbing::create([
            'skripsi_id'        => $skripsi->id_skripsi,
            'tahap'             => 'proposal',
            'pembimbing_ke'     => 1,
            'dosen_id'          => $dosen->id_dosen,
            'sk_dokumen_id'     => null,
            'status_penugasan'  => $status,
            'tanggal_mulai'     => Carbon::now('UTC')->toDateString(),
        ]);
    }

    private function buatOutbox(
        PenugasanPembimbing $penugasan,
        string $status = 'PENDING',
        int $attempts = 0,
        ?Carbon $nextAttemptAt = null
    ): BchatProvisioningOutbox {
        $now = Carbon::now('UTC');
        return BchatProvisioningOutbox::create([
            'event_uuid'              => (string) Str::uuid(),
            'penugasan_pembimbing_id' => $penugasan->id,
            'event_type'              => 'PROPOSAL_ROOM_PROVISION',
            'payload'                 => ['schema_version' => 1],
            'status'                  => $status,
            'attempts'                => $attempts,
            'next_attempt_at'         => $nextAttemptAt,
            'generation_token'        => 1,
            'created_at'              => $now,
            'updated_at'              => $now,
        ]);
    }

    // -----------------------------------------------------------------------
    // FEATURE TESTS
    // -----------------------------------------------------------------------

    /**
     * 1. Claim mengambil event PENDING menggunakan klausa SKIP LOCKED.
     */
    public function test_claim_picks_pending_event_with_skip_locked_clause(): void
    {
        $mhs = $this->buatMahasiswa();
        $dos = $this->buatDosen();
        $skripsi = $this->buatSkripsi($mhs['mahasiswa']);
        $penugasan = $this->buatPenugasan($skripsi, $dos['dosen']);
        $outbox = $this->buatOutbox($penugasan, 'PENDING');

        $claimed = $this->service->claimNextPendingEvent();

        $this->assertNotNull($claimed);
        $this->assertSame($outbox->id, $claimed->id);
        $this->assertSame('PROCESSING', $claimed->status);
        $this->assertSame($this->service->getWorkerIdentity(), $claimed->locked_by);
        $this->assertNotNull($claimed->locked_at);
        $this->assertSame(2, (int) $claimed->generation_token);
    }

    /**
     * 2. Eksklusi status sekuensial: event yang telah PROCESSING tidak diambil instance worker kedua.
     * Catatan: Uji ini membuktikan filter status antrean secara sekuensial, bukan konkurensi locking simultan paralel.
     */
    public function test_sequential_claim_excludes_processing_event_for_second_worker_instance(): void
    {
        $mhs = $this->buatMahasiswa();
        $dos = $this->buatDosen();
        $skripsi = $this->buatSkripsi($mhs['mahasiswa']);
        $penugasan = $this->buatPenugasan($skripsi, $dos['dosen']);
        $this->buatOutbox($penugasan, 'PENDING');

        $worker1 = new BchatProvisioningWorkerService('worker:node-1:1001:11111111');
        $worker2 = new BchatProvisioningWorkerService('worker:node-2:2002:22222222');

        $claimed1 = $worker1->claimNextPendingEvent();
        $this->assertNotNull($claimed1);

        $claimed2 = $worker2->claimNextPendingEvent();
        $this->assertNull($claimed2, 'Worker kedua tidak boleh mengambil event yang telah berstatus PROCESSING');
    }

    /**
     * 3. Provisioning room baru menghasilkan room PENDING_WELCOME_ACK dan dua anggota INACTIVE.
     */
    public function test_provisioning_creates_new_room_with_correct_two_members(): void
    {
        $mhs = $this->buatMahasiswa();
        $dos = $this->buatDosen();
        $skripsi = $this->buatSkripsi($mhs['mahasiswa']);
        $penugasan = $this->buatPenugasan($skripsi, $dos['dosen']);
        $outbox = $this->buatOutbox($penugasan, 'PENDING');

        $claimed = $this->service->claimNextPendingEvent();
        $this->assertNotNull($claimed);

        $success = $this->service->processClaimedEvent($claimed, (int) $claimed->generation_token);
        $this->assertTrue($success);

        // Verifikasi Room
        $room = BchatRoom::where('penugasan_pembimbing_id', $penugasan->id)->first();
        $this->assertNotNull($room);
        $this->assertSame('PENDING_WELCOME_ACK', $room->status);
        $this->assertSame('proposal', $room->tahap);
        $this->assertSame(1, (int) $room->pembimbing_ke);
        $this->assertSame(0, (int) $room->current_epoch);

        // Verifikasi Anggota Tepat Dua
        $members = BchatRoomMember::where('room_id', $room->id)->get();
        $this->assertCount(2, $members);

        // Verifikasi Mahasiswa (is_initiator = false, is_active = false)
        $mhsMember = $members->firstWhere('role', 'mahasiswa');
        $this->assertNotNull($mhsMember);
        $this->assertSame($mhs['user']->id, (int) $mhsMember->user_id);
        $this->assertFalse($mhsMember->is_initiator);
        $this->assertFalse($mhsMember->is_active);

        // Verifikasi Dosen Pembimbing (is_initiator = false, is_active = false)
        $dosMember = $members->firstWhere('role', 'dosen_pembimbing');
        $this->assertNotNull($dosMember);
        $this->assertSame($dos['user']->id, (int) $dosMember->user_id);
        $this->assertFalse($dosMember->is_initiator);
        $this->assertFalse($dosMember->is_active);

        // Verifikasi Outbox COMPLETED
        $freshOutbox = $outbox->fresh();
        $this->assertSame('COMPLETED', $freshOutbox->status);
        $this->assertNotNull($freshOutbox->completed_at);
        $this->assertNull($freshOutbox->locked_by);
        $this->assertNull($freshOutbox->locked_at);
    }

    /**
     * 4. Deep verification menolak penugasan jika akun user mahasiswa memiliki role yang tidak sesuai.
     */
    public function test_academic_verification_rejects_user_with_invalid_role(): void
    {
        // Buat user mahasiswa tetapi rolenya 'dosen' (mismatch)
        $mhs = $this->buatMahasiswa('dosen');
        $dos = $this->buatDosen();
        $skripsi = $this->buatSkripsi($mhs['mahasiswa']);
        $penugasan = $this->buatPenugasan($skripsi, $dos['dosen']);
        $outbox = $this->buatOutbox($penugasan, 'PENDING');

        $claimed = $this->service->claimNextPendingEvent();
        $success = $this->service->processClaimedEvent($claimed, (int) $claimed->generation_token);

        $this->assertFalse($success);
        $freshOutbox = $outbox->fresh();
        $this->assertSame('FAILED', $freshOutbox->status);
        $this->assertSame('MAHASISWA_ROLE_MISMATCH', $freshOutbox->last_error_code);
    }

    /**
     * 5. Penanganan locked_at NULL di awal T2 memicu rollback dan recovery via T_FAIL.
     */
    public function test_null_locked_at_triggers_rollback_and_guarded_recovery(): void
    {
        $mhs = $this->buatMahasiswa();
        $dos = $this->buatDosen();
        $skripsi = $this->buatSkripsi($mhs['mahasiswa']);
        $penugasan = $this->buatPenugasan($skripsi, $dos['dosen']);
        $outbox = $this->buatOutbox($penugasan, 'PENDING');

        $claimed = $this->service->claimNextPendingEvent();

        // Paksa locked_at menjadi NULL di database
        DB::table('bchat_provisioning_outbox')
            ->where('id', $claimed->id)
            ->update(['locked_at' => null]);

        $success = $this->service->processClaimedEvent($claimed, (int) $claimed->generation_token);
        $this->assertFalse($success);

        $freshOutbox = $outbox->fresh();
        $this->assertSame('PENDING', $freshOutbox->status);
        $this->assertSame(1, $freshOutbox->attempts);
        $this->assertSame('LOCKED_AT_NULL', $freshOutbox->last_error_code);
    }

    /**
     * 6. Existing room yang valid diverifikasi secara mendalam dengan row locking terurut.
     */
    public function test_existing_valid_room_handled_idempotently(): void
    {
        $mhs = $this->buatMahasiswa();
        $dos = $this->buatDosen();
        $skripsi = $this->buatSkripsi($mhs['mahasiswa']);
        $penugasan = $this->buatPenugasan($skripsi, $dos['dosen']);

        // Pra-buat room dan member sah
        $room = BchatRoom::create([
            'room_uuid'               => (string) Str::uuid(),
            'penugasan_pembimbing_id' => $penugasan->id,
            'tahap'                   => 'proposal',
            'pembimbing_ke'           => 1,
            'current_sequence'        => 0,
            'status'                  => 'PENDING_WELCOME_ACK',
            'current_epoch'           => 0,
        ]);

        BchatRoomMember::create([
            'room_id'      => $room->id,
            'user_id'      => $mhs['user']->id,
            'role'         => 'mahasiswa',
            'is_initiator' => false,
            'is_active'    => false,
        ]);

        BchatRoomMember::create([
            'room_id'      => $room->id,
            'user_id'      => $dos['user']->id,
            'role'         => 'dosen_pembimbing',
            'is_initiator' => false,
            'is_active'    => false,
        ]);

        $outbox = $this->buatOutbox($penugasan, 'PENDING');
        $claimed = $this->service->claimNextPendingEvent();

        $success = $this->service->processClaimedEvent($claimed, (int) $claimed->generation_token);
        $this->assertTrue($success);

        // Tidak boleh ada penambahan room baru
        $this->assertSame(1, BchatRoom::where('penugasan_pembimbing_id', $penugasan->id)->count());
        $this->assertSame('COMPLETED', $outbox->fresh()->status);
    }

    /**
     * 7. Existing room dengan jumlah member bukan tepat dua ditolak via Kondisi A.
     */
    public function test_existing_room_with_invalid_member_count_rejected(): void
    {
        $mhs = $this->buatMahasiswa();
        $dos = $this->buatDosen();
        $skripsi = $this->buatSkripsi($mhs['mahasiswa']);
        $penugasan = $this->buatPenugasan($skripsi, $dos['dosen']);

        $room = BchatRoom::create([
            'room_uuid'               => (string) Str::uuid(),
            'penugasan_pembimbing_id' => $penugasan->id,
            'tahap'                   => 'proposal',
            'pembimbing_ke'           => 1,
            'status'                  => 'PENDING_WELCOME_ACK',
        ]);

        // Hanya 1 member (korup)
        BchatRoomMember::create([
            'room_id'      => $room->id,
            'user_id'      => $mhs['user']->id,
            'role'         => 'mahasiswa',
            'is_initiator' => false,
            'is_active'    => false,
        ]);

        $outbox = $this->buatOutbox($penugasan, 'PENDING');
        $claimed = $this->service->claimNextPendingEvent();

        $success = $this->service->processClaimedEvent($claimed, (int) $claimed->generation_token);
        $this->assertFalse($success);

        $freshOutbox = $outbox->fresh();
        $this->assertSame('FAILED', $freshOutbox->status);
        $this->assertSame('ROOM_INTEGRITY_CONFLICT', $freshOutbox->last_error_code);
        $this->assertNotNull($freshOutbox->failed_at);
        $this->assertSame(0, $freshOutbox->attempts, 'Attempts tidak boleh bertambah pada kegagalan Kondisi A');
    }

    /**
     * 8. Existing room dengan user_id mismatch ditolak via Kondisi A.
     */
    public function test_existing_room_with_user_id_mismatch_rejected(): void
    {
        $mhs = $this->buatMahasiswa();
        $dos = $this->buatDosen();
        $otherUser = User::factory()->create(['role' => 'mahasiswa']);

        $skripsi = $this->buatSkripsi($mhs['mahasiswa']);
        $penugasan = $this->buatPenugasan($skripsi, $dos['dosen']);

        $room = BchatRoom::create([
            'room_uuid'               => (string) Str::uuid(),
            'penugasan_pembimbing_id' => $penugasan->id,
            'tahap'                   => 'proposal',
            'pembimbing_ke'           => 1,
            'status'                  => 'PENDING_WELCOME_ACK',
        ]);

        // Mahasiswa di room bukan mahasiswa pada penugasan
        BchatRoomMember::create([
            'room_id'      => $room->id,
            'user_id'      => $otherUser->id,
            'role'         => 'mahasiswa',
            'is_initiator' => false,
            'is_active'    => false,
        ]);

        BchatRoomMember::create([
            'room_id'      => $room->id,
            'user_id'      => $dos['user']->id,
            'role'         => 'dosen_pembimbing',
            'is_initiator' => false,
            'is_active'    => false,
        ]);

        $outbox = $this->buatOutbox($penugasan, 'PENDING');
        $claimed = $this->service->claimNextPendingEvent();

        $success = $this->service->processClaimedEvent($claimed, (int) $claimed->generation_token);
        $this->assertFalse($success);

        $freshOutbox = $outbox->fresh();
        $this->assertSame('FAILED', $freshOutbox->status);
        $this->assertSame('ROOM_INTEGRITY_CONFLICT', $freshOutbox->last_error_code);
    }

    /**
     * 9. Existing room dengan status terminal ditolak dan tidak diaktifkan kembali.
     */
    public function test_existing_room_with_terminal_status_rejected(): void
    {
        $mhs = $this->buatMahasiswa();
        $dos = $this->buatDosen();
        $skripsi = $this->buatSkripsi($mhs['mahasiswa']);
        $penugasan = $this->buatPenugasan($skripsi, $dos['dosen']);

        $room = BchatRoom::create([
            'room_uuid'               => (string) Str::uuid(),
            'penugasan_pembimbing_id' => $penugasan->id,
            'tahap'                   => 'proposal',
            'pembimbing_ke'           => 1,
            'status'                  => 'TERMINATED',
        ]);

        $outbox = $this->buatOutbox($penugasan, 'PENDING');
        $claimed = $this->service->claimNextPendingEvent();

        $success = $this->service->processClaimedEvent($claimed, (int) $claimed->generation_token);
        $this->assertFalse($success);

        $freshOutbox = $outbox->fresh();
        $this->assertSame('FAILED', $freshOutbox->status);
        $this->assertSame('ROOM_TERMINAL_STATE_CONFLICT', $freshOutbox->last_error_code);
        $this->assertSame('TERMINATED', $room->fresh()->status, 'Status room terminal dilarang diubah');
    }

    /**
     * 10. Existing room dengan status member tidak konsisten dengan lifecycle ditolak.
     */
    public function test_existing_room_with_inconsistent_member_status_rejected(): void
    {
        $mhs = $this->buatMahasiswa();
        $dos = $this->buatDosen();
        $skripsi = $this->buatSkripsi($mhs['mahasiswa']);
        $penugasan = $this->buatPenugasan($skripsi, $dos['dosen']);

        // Room PENDING_WELCOME_ACK tetapi salah satu member aktif
        $room = BchatRoom::create([
            'room_uuid'               => (string) Str::uuid(),
            'penugasan_pembimbing_id' => $penugasan->id,
            'tahap'                   => 'proposal',
            'pembimbing_ke'           => 1,
            'status'                  => 'PENDING_WELCOME_ACK',
        ]);

        BchatRoomMember::create([
            'room_id'      => $room->id,
            'user_id'      => $mhs['user']->id,
            'role'         => 'mahasiswa',
            'is_initiator' => false,
            'is_active'    => true, // KORUP: Tidak boleh aktif saat PENDING_WELCOME_ACK
        ]);

        BchatRoomMember::create([
            'room_id'      => $room->id,
            'user_id'      => $dos['user']->id,
            'role'         => 'dosen_pembimbing',
            'is_initiator' => false,
            'is_active'    => false,
        ]);

        $outbox = $this->buatOutbox($penugasan, 'PENDING');
        $claimed = $this->service->claimNextPendingEvent();

        $success = $this->service->processClaimedEvent($claimed, (int) $claimed->generation_token);
        $this->assertFalse($success);

        $freshOutbox = $outbox->fresh();
        $this->assertSame('FAILED', $freshOutbox->status);
        $this->assertSame('MEMBER_STATUS_CORRUPTED', $freshOutbox->last_error_code);
    }

    /**
     * 11. Invariant akademik gagal (penugasan DIBATALKAN) ditolak via Kondisi A.
     */
    public function test_academic_invariant_failure_marks_outbox_failed_under_condition_a(): void
    {
        $mhs = $this->buatMahasiswa();
        $dos = $this->buatDosen();
        $skripsi = $this->buatSkripsi($mhs['mahasiswa']);
        $penugasan = $this->buatPenugasan($skripsi, $dos['dosen'], 'DIBATALKAN');
        $outbox = $this->buatOutbox($penugasan, 'PENDING');

        $claimed = $this->service->claimNextPendingEvent();
        $success = $this->service->processClaimedEvent($claimed, (int) $claimed->generation_token);

        $this->assertFalse($success);

        $freshOutbox = $outbox->fresh();
        $this->assertSame('FAILED', $freshOutbox->status);
        $this->assertSame('ASSIGNMENT_INACTIVE', $freshOutbox->last_error_code);
        $this->assertSame(0, $freshOutbox->attempts);

        // Pastikan tidak ada room yang terbuat
        $this->assertNull(BchatRoom::where('penugasan_pembimbing_id', $penugasan->id)->first());
    }

    /**
     * 12. T_FAIL melakukan guarded recovery dan menaikkan retry attempts.
     */
    public function test_t_fail_performs_guarded_recovery_and_increments_attempts(): void
    {
        $mhs = $this->buatMahasiswa();
        $dos = $this->buatDosen();
        $skripsi = $this->buatSkripsi($mhs['mahasiswa']);
        $penugasan = $this->buatPenugasan($skripsi, $dos['dosen']);
        $outbox = $this->buatOutbox($penugasan, 'PENDING');

        $claimed = $this->service->claimNextPendingEvent();
        $expectedToken = (int) $claimed->generation_token;

        // Picu technical failure Kondisi B
        $this->service->handleTechnicalFailure(
            $claimed->id,
            $expectedToken,
            'DEADLOCK',
            'Simulated deadlock occurred'
        );

        $freshOutbox = $outbox->fresh();
        $this->assertSame('PENDING', $freshOutbox->status);
        $this->assertSame(1, $freshOutbox->attempts);
        $this->assertNotNull($freshOutbox->next_attempt_at);
        $this->assertSame('DEADLOCK', $freshOutbox->last_error_code);
        $this->assertNull($freshOutbox->locked_by);
        $this->assertNull($freshOutbox->locked_at);
    }

    /**
     * 13. T_FAIL menolak recovery jika token fencing tidak cocok.
     */
    public function test_t_fail_rejects_recovery_if_fencing_mismatched(): void
    {
        $mhs = $this->buatMahasiswa();
        $dos = $this->buatDosen();
        $skripsi = $this->buatSkripsi($mhs['mahasiswa']);
        $penugasan = $this->buatPenugasan($skripsi, $dos['dosen']);
        $outbox = $this->buatOutbox($penugasan, 'PENDING');

        $claimed = $this->service->claimNextPendingEvent();

        // Panggil failure handler dengan token usang
        $this->service->handleTechnicalFailure(
            $claimed->id,
            999999, // Token salah
            'DEADLOCK',
            'Simulated error'
        );

        $freshOutbox = $outbox->fresh();
        // Row tidak boleh berubah karena fencing gagal
        $this->assertSame('PROCESSING', $freshOutbox->status);
        $this->assertSame(0, $freshOutbox->attempts);
    }

    /**
     * 14. Reaper melakukan recovery pada event PROCESSING yang melebihi 60 detik.
     */
    public function test_reaper_performs_recovery_after_60_seconds(): void
    {
        $mhs = $this->buatMahasiswa();
        $dos = $this->buatDosen();
        $skripsi = $this->buatSkripsi($mhs['mahasiswa']);
        $penugasan = $this->buatPenugasan($skripsi, $dos['dosen']);
        $outbox = $this->buatOutbox($penugasan, 'PENDING');

        $claimed = $this->service->claimNextPendingEvent();

        // Mundurkan locked_at melebihi 60 detik (misal 65 detik yang lalu)
        DB::table('bchat_provisioning_outbox')
            ->where('id', $claimed->id)
            ->update([
                'locked_at' => Carbon::now('UTC')->subSeconds(65)->format('Y-m-d H:i:s.u'),
            ]);

        $reaped = $this->service->reapStalledEvents();
        $this->assertSame(1, $reaped);

        $freshOutbox = $outbox->fresh();
        $this->assertSame('PENDING', $freshOutbox->status);
        $this->assertSame(1, $freshOutbox->attempts);
        $this->assertSame('LEASE_TIMEOUT', $freshOutbox->last_error_code);
        $this->assertNull($freshOutbox->locked_by);
        $this->assertNull($freshOutbox->locked_at);
    }

    /**
     * 15. Retry mencapai batas kuota ke-6 dan bertransisi menjadi terminal FAILED.
     */
    public function test_retry_reaches_terminal_failed_on_sixth_attempt(): void
    {
        $mhs = $this->buatMahasiswa();
        $dos = $this->buatDosen();
        $skripsi = $this->buatSkripsi($mhs['mahasiswa']);
        $penugasan = $this->buatPenugasan($skripsi, $dos['dosen']);

        // Outbox dengan 5 kali gagal sebelumnya
        $outbox = $this->buatOutbox($penugasan, 'PENDING', 5);

        $claimed = $this->service->claimNextPendingEvent();
        $this->assertSame(5, $claimed->attempts);

        // Kegagalan ke-6 via T_FAIL
        $this->service->handleTechnicalFailure(
            $claimed->id,
            (int) $claimed->generation_token,
            'DATABASE_ERROR',
            'Connection timed out repeatedly'
        );

        $freshOutbox = $outbox->fresh();
        $this->assertSame('FAILED', $freshOutbox->status);
        $this->assertSame(6, $freshOutbox->attempts);
        $this->assertNotNull($freshOutbox->failed_at);
        $this->assertNull($freshOutbox->next_attempt_at);
        $this->assertSame('DATABASE_ERROR', $freshOutbox->last_error_code);
    }

    /**
     * 16. TEST 1: Existing room dengan status ACTIVE dan kedua anggota aktif ditangani secara idempoten.
     */
    public function test_existing_active_room_handled_idempotently(): void
    {
        $mhs = $this->buatMahasiswa();
        $dos = $this->buatDosen();
        $skripsi = $this->buatSkripsi($mhs['mahasiswa']);
        $penugasan = $this->buatPenugasan($skripsi, $dos['dosen']);

        // Pra-buat room ACTIVE dengan kedua anggota aktif
        $room = BchatRoom::create([
            'room_uuid'               => (string) Str::uuid(),
            'penugasan_pembimbing_id' => $penugasan->id,
            'tahap'                   => 'proposal',
            'pembimbing_ke'           => 1,
            'current_sequence'        => 5,
            'status'                  => 'ACTIVE',
            'current_epoch'           => 1,
        ]);

        $mhsMember = BchatRoomMember::create([
            'room_id'         => $room->id,
            'user_id'         => $mhs['user']->id,
            'role'            => 'mahasiswa',
            'is_initiator'    => false,
            'is_active'       => true,
            'joined_at_epoch' => 0,
        ]);

        $dosMember = BchatRoomMember::create([
            'room_id'         => $room->id,
            'user_id'         => $dos['user']->id,
            'role'            => 'dosen_pembimbing',
            'is_initiator'    => false,
            'is_active'       => true,
            'joined_at_epoch' => 0,
        ]);

        $outbox = $this->buatOutbox($penugasan, 'PENDING');
        $claimed = $this->service->claimNextPendingEvent();

        $success = $this->service->processClaimedEvent($claimed, (int) $claimed->generation_token);
        $this->assertTrue($success);

        // Verifikasi outbox menjadi COMPLETED
        $freshOutbox = $outbox->fresh();
        $this->assertSame('COMPLETED', $freshOutbox->status);
        $this->assertNotNull($freshOutbox->completed_at);
        $this->assertNull($freshOutbox->locked_by);
        $this->assertNull($freshOutbox->locked_at);

        // Verifikasi tidak ada room atau anggota baru yang dibuat
        $this->assertSame(1, BchatRoom::where('penugasan_pembimbing_id', $penugasan->id)->count());
        $this->assertSame(2, BchatRoomMember::where('room_id', $room->id)->count());

        // Verifikasi integritas status room dan anggota tetap terjaga
        $freshRoom = $room->fresh();
        $this->assertSame('ACTIVE', $freshRoom->status);
        $this->assertSame(1, (int) $freshRoom->current_epoch);
        $this->assertTrue($mhsMember->fresh()->is_active);
        $this->assertTrue($dosMember->fresh()->is_active);
    }

    /**
     * 17. TEST 2: Skenario user_id mahasiswa dan dosen identik ditolak via Kondisi A dengan error IDENTICAL_USER_IDENTITIES.
     */
    public function test_identical_user_identities_rejected_under_condition_a(): void
    {
        $initialMembersCount = BchatRoomMember::count();

        $nim = (string) rand(1000000000, 9999999999);
        $sharedUser = User::factory()->create([
            'role'        => 'mahasiswa',
            'nomor_induk' => $nim,
            'name'        => 'Shared User ' . $nim,
        ]);

        $prodi = 'Informatika';
        $fakultas = AcademicConstant::getFakultasForProdi($prodi) ?? 'Fakultas Sains dan Teknologi';

        $mahasiswa = Mahasiswa::create([
            'user_id'        => $sharedUser->id,
            'NIM'            => $nim,
            'nama_mahasiswa' => 'Mahasiswa Shared',
            'prodi'          => $prodi,
            'fakultas'       => $fakultas,
        ]);

        $dosen = Dosen::create([
            'user_id'    => $sharedUser->id,
            'NIDN'       => $nim,
            'nama_dosen' => 'Dosen Shared',
        ]);

        $skripsi = $this->buatSkripsi($mahasiswa);
        $penugasan = $this->buatPenugasan($skripsi, $dosen);
        $outbox = $this->buatOutbox($penugasan, 'PENDING');

        $claimed = $this->service->claimNextPendingEvent();
        $success = $this->service->processClaimedEvent($claimed, (int) $claimed->generation_token);

        $this->assertFalse($success);

        $freshOutbox = $outbox->fresh();
        $this->assertSame('FAILED', $freshOutbox->status);
        $this->assertSame('IDENTICAL_USER_IDENTITIES', $freshOutbox->last_error_code);
        $this->assertSame(0, $freshOutbox->attempts);
        $this->assertNotNull($freshOutbox->failed_at);

        // Pastikan tidak ada room atau anggota yang terbuat
        $this->assertNull(BchatRoom::where('penugasan_pembimbing_id', $penugasan->id)->first());
        $this->assertSame($initialMembersCount, BchatRoomMember::count(), 'Tidak boleh ada record BchatRoomMember baru yang tersimpan.');
        $this->assertSame(0, BchatRoomMember::where('user_id', $sharedUser->id)->count(), 'Tidak boleh ada member yang terkait dengan user uji.');
    }

    /**
     * 18. TEST 3: Lease kedaluwarsa pada pemeriksaan kedua (pre-commit T2) memicu rollback T2 dan pemulihan T_FAIL.
     */
    public function test_lease_expired_pre_commit_triggers_rollback_and_guarded_recovery(): void
    {
        $initialMembersCount = BchatRoomMember::count();

        $mhs = $this->buatMahasiswa();
        $dos = $this->buatDosen();
        $skripsi = $this->buatSkripsi($mhs['mahasiswa']);
        $penugasan = $this->buatPenugasan($skripsi, $dos['dosen']);
        $outbox = $this->buatOutbox($penugasan, 'PENDING');

        // 1. Simpan kondisi Carbon testNow dan tetapkan waktu awal uji
        $previousTestNow = Carbon::getTestNow();
        $initialTime = Carbon::parse('2026-11-07 10:00:00.000000', 'UTC');
        Carbon::setTestNow($initialTime);

        // 2. Simpan dan validasi ketersediaan Event Dispatcher asli
        $originalDispatcher = BchatRoom::getEventDispatcher();
        $this->assertNotNull($originalDispatcher, 'Event dispatcher must be available on BchatRoom');

        // 3. Clone dispatcher asli agar listener lama tetap utuh di objek aslinya
        $testDispatcher = clone $originalDispatcher;

        // 4. Pasang clone sementara pada model BchatRoom
        BchatRoom::setEventDispatcher($testDispatcher);

        $listenerActive = true;
        $tempListener = function () use (&$listenerActive, $initialTime) {
            if (! $listenerActive) {
                return;
            }
            Carbon::setTestNow($initialTime->copy()->addSeconds(35));
            $listenerActive = false;
        };

        try {
            try {
                $claimed = $this->service->claimNextPendingEvent();
                $this->assertNotNull($claimed);
                $expectedToken = (int) $claimed->generation_token;

                // 5. Daftarkan listener sementara hanya pada testDispatcher (clone)
                BchatRoom::saving($tempListener);

                $success = $this->service->processClaimedEvent($claimed, $expectedToken);
                $this->assertFalse($success);

                // Verifikasi seluruh perubahan T2 di-rollback (tidak ada room dan member yang tersisa)
                $this->assertNull(BchatRoom::where('penugasan_pembimbing_id', $penugasan->id)->first());
                $this->assertSame($initialMembersCount, BchatRoomMember::count(), 'Seluruh perubahan T2 harus di-rollback penuh.');
                $this->assertSame(0, BchatRoomMember::whereIn('user_id', [$mhs['user']->id, $dos['user']->id])->count(), 'Tidak boleh ada member yang tersisa untuk penugasan ini.');

                // Verifikasi recovery T_FAIL berhasil dijalankan dengan fencing guard
                $freshOutbox = $outbox->fresh();
                $this->assertSame('PENDING', $freshOutbox->status);
                $this->assertSame(1, $freshOutbox->attempts);
                $this->assertSame('LEASE_EXPIRED_PRE_COMMIT', $freshOutbox->last_error_code);
                $this->assertNotNull($freshOutbox->next_attempt_at);
                $this->assertNull($freshOutbox->locked_by);
                $this->assertNull($freshOutbox->locked_at);
                $this->assertSame($expectedToken + 1, (int) $freshOutbox->generation_token);
            } finally {
                // 6. Nonaktifkan listener dan pulihkan dispatcher asli BchatRoom
                $listenerActive = false;
                BchatRoom::setEventDispatcher($originalDispatcher);
            }
        } finally {
            // 7. Pulihkan kondisi Carbon testNow ke keadaan semula secara independen
            Carbon::setTestNow($previousTestNow);
        }
    }
}
