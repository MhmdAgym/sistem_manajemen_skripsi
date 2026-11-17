<?php

namespace Tests\Feature\Bchat;

use App\Constants\AcademicConstant;
use App\Models\Admin;
use App\Models\BchatProvisioningOutbox;
use App\Models\Dosen;
use App\Models\DosenPembimbing;
use App\Models\Mahasiswa;
use App\Models\PengajuanDospem;
use App\Models\PengajuanDospemLog;
use App\Models\PengajuanJudul;
use App\Models\PengajuanJudulLog;
use App\Models\PenugasanPembimbing;
use App\Models\PenugasanPembimbingLog;
use App\Models\Skripsi;
use App\Models\User;
use App\Services\BchatProvisioningOutboxService;
use Carbon\Carbon;
use DomainException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class BchatProvisioningOutboxServiceTest extends TestCase
{
    use DatabaseTransactions;

    private BchatProvisioningOutboxService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $activeDb = DB::connection()->getDatabaseName();
        $this->assertEquals(
            'manajemen_skripsi_test',
            $activeDb,
            'CRITICAL SECURITY: Test suite B-CHAT HANYA boleh berjalan pada database manajemen_skripsi_test.'
        );

        $this->service = app(BchatProvisioningOutboxService::class);
    }

    // -----------------------------------------------------------------------
    // FIXTURE HELPERS
    // -----------------------------------------------------------------------

    private function buatAdmin(): array
    {
        $user = User::factory()->create([
            'role'        => 'admin',
            'nomor_induk' => 'ADM' . rand(1000, 9999),
            'name'        => 'Admin Tester',
        ]);

        $admin = Admin::create([
            'users_id'   => $user->id,
            'nama_admin' => 'Admin Tester',
        ]);

        return compact('user', 'admin');
    }

    private function buatMahasiswa(): array
    {
        $nim = (string) rand(1000000000, 9999999999);
        $user = User::factory()->create([
            'role'        => 'mahasiswa',
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

    private function buatDosen(): array
    {
        $nidn = (string) rand(1000000000, 9999999999);
        $user = User::factory()->create([
            'role'        => 'dosen',
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
            'judul'        => 'Sistem Otomasi Chat E2EE ' . uniqid(),
            'status_judul' => 'disetujui',
        ]);
    }

    private function buatPersetujuanJudul(Mahasiswa $mahasiswa, Skripsi $skripsi, Dosen $dosenPa): PengajuanJudul
    {
        $pengajuanJudul = PengajuanJudul::create([
            'skripsi_id'    => $skripsi->id_skripsi,
            'mahasiswa_id'  => $mahasiswa->id_mahasiswa,
            'dosen_pa_id'   => $dosenPa->user_id,
            'judul'         => $skripsi->judul,
            'deskripsi'     => 'Deskripsi proposal resmi yang telah disetujui Kaprodi.',
            'nama_file'     => 'proposal.pdf',
            'ukuran_file'   => '1.0 MB',
            'file_path'     => 'pengajuan-judul/proposal.pdf',
            'status'        => 'Disetujui Kaprodi',
            'catatan_dosen' => 'Disetujui oleh Kaprodi.',
        ]);

        PengajuanJudulLog::create([
            'pengajuan_judul_id' => $pengajuanJudul->id,
            'user_id'            => $dosenPa->user_id,
            'aktor_nama'         => $dosenPa->user->name ?? 'Kaprodi',
            'aktor_role'         => 'kaprodi',
            'aktor_nomor_induk'  => $dosenPa->NIDN,
            'tahap'              => 'persetujuan_kaprodi',
            'tindakan'           => 'setujui_kaprodi',
            'status_sebelum'     => 'Menunggu Persetujuan Kaprodi',
            'status_sesudah'     => 'Disetujui Kaprodi',
            'catatan'            => 'Disetujui.',
            'snapshot_judul'     => $pengajuanJudul->judul,
            'snapshot_file_path' => $pengajuanJudul->file_path,
        ]);

        return $pengajuanJudul;
    }

    private function buatPengajuanDospem(Mahasiswa $mahasiswa, Skripsi $skripsi): PengajuanDospem
    {
        return PengajuanDospem::create([
            'skripsi_id'        => $skripsi->id_skripsi,
            'mahasiswa_id'      => $mahasiswa->id_mahasiswa,
            'tahap'             => 'proposal',
            'status'            => 'menunggu_penetapan',
            'dosen_id'          => null,
            'catatan_mahasiswa' => 'Mohon penetapan dospem proposal.',
            'catatan_admin'     => null,
        ]);
    }

    private function buatPenugasanProposal(Skripsi $skripsi, Dosen $dosen, PengajuanDospem $pengajuan): PenugasanPembimbing
    {
        return PenugasanPembimbing::create([
            'skripsi_id'          => $skripsi->id_skripsi,
            'tahap'               => 'proposal',
            'pembimbing_ke'       => 1,
            'dosen_id'            => $dosen->id_dosen,
            'sk_dokumen_id'       => null,
            'pengajuan_dospem_id' => $pengajuan->id,
            'status_penugasan'    => 'AKTIF',
            'tanggal_mulai'       => Carbon::now('UTC')->toDateString(),
        ]);
    }

    // -----------------------------------------------------------------------
    // TEST CASES
    // -----------------------------------------------------------------------

    /**
     * 1. Event valid berhasil dibuat dalam transaksi aktif.
     */
    public function test_event_valid_berhasil_dibuat_dalam_transaksi(): void
    {
        ['mahasiswa' => $mahasiswa] = $this->buatMahasiswa();
        ['dosen' => $dosen] = $this->buatDosen();
        $skripsi = $this->buatSkripsi($mahasiswa);
        $pengajuan = $this->buatPengajuanDospem($mahasiswa, $skripsi);

        $outbox = DB::transaction(function () use ($skripsi, $dosen, $pengajuan) {
            $penugasan = $this->buatPenugasanProposal($skripsi, $dosen, $pengajuan);
            return $this->service->recordProposalProvisioningEvent($penugasan);
        });

        $this->assertInstanceOf(BchatProvisioningOutbox::class, $outbox);
        $this->assertEquals('PROPOSAL_ROOM_PROVISION', $outbox->event_type);
        $this->assertEquals('PENDING', $outbox->status);
        $this->assertEquals(0, $outbox->attempts);
        $this->assertEquals(1, $outbox->generation_token);
        $this->assertNull($outbox->locked_at);
        $this->assertNull($outbox->locked_by);
        $this->assertNull($outbox->failed_at);
        $this->assertNull($outbox->completed_at);

        // Validasi UUID format
        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i',
            $outbox->event_uuid
        );

        // Terdaftar di database
        $this->assertDatabaseHas('bchat_provisioning_outbox', [
            'id'                      => $outbox->id,
            'event_uuid'              => $outbox->event_uuid,
            'penugasan_pembimbing_id' => $outbox->penugasan_pembimbing_id,
            'event_type'              => 'PROPOSAL_ROOM_PROVISION',
            'status'                  => 'PENDING',
        ]);
    }

    /**
     * 2. Payload minimal tepat hanya berisi schema_version: 1.
     */
    public function test_payload_tepat_hanya_berisi_schema_version_1(): void
    {
        ['mahasiswa' => $mahasiswa] = $this->buatMahasiswa();
        ['dosen' => $dosen] = $this->buatDosen();
        $skripsi = $this->buatSkripsi($mahasiswa);
        $pengajuan = $this->buatPengajuanDospem($mahasiswa, $skripsi);

        $outbox = DB::transaction(function () use ($skripsi, $dosen, $pengajuan) {
            $penugasan = $this->buatPenugasanProposal($skripsi, $dosen, $pengajuan);
            return $this->service->recordProposalProvisioningEvent($penugasan);
        });

        $this->assertSame(['schema_version' => 1], $outbox->payload);

        // Verifikasi raw JSON di database
        $raw = DB::table('bchat_provisioning_outbox')->where('id', $outbox->id)->value('payload');
        $this->assertJsonStringEqualsJsonString(json_encode(['schema_version' => 1]), $raw);
    }

    /**
     * 3. Timestamp menggunakan UTC dan mempertahankan presisi mikrodetik (DATETIME(6)).
     */
    public function test_timestamp_menggunakan_utc_dan_mempertahankan_mikrodetik(): void
    {
        ['mahasiswa' => $mahasiswa] = $this->buatMahasiswa();
        ['dosen' => $dosen] = $this->buatDosen();
        $skripsi = $this->buatSkripsi($mahasiswa);
        $pengajuan = $this->buatPengajuanDospem($mahasiswa, $skripsi);

        $outbox = DB::transaction(function () use ($skripsi, $dosen, $pengajuan) {
            $penugasan = $this->buatPenugasanProposal($skripsi, $dosen, $pengajuan);
            return $this->service->recordProposalProvisioningEvent($penugasan);
        });

        $rawCreatedAt = DB::table('bchat_provisioning_outbox')->where('id', $outbox->id)->value('created_at');
        $rawUpdatedAt = DB::table('bchat_provisioning_outbox')->where('id', $outbox->id)->value('updated_at');

        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}\.\d{6}$/', $rawCreatedAt);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}\.\d{6}$/', $rawUpdatedAt);
    }

    /**
     * 4. Pemanggilan berulang pada penugasan yang sama bersifat idempoten dan mengembalikan event existing.
     */
    public function test_pemanggilan_berulang_mengembalikan_event_existing_idempoten(): void
    {
        ['mahasiswa' => $mahasiswa] = $this->buatMahasiswa();
        ['dosen' => $dosen] = $this->buatDosen();
        $skripsi = $this->buatSkripsi($mahasiswa);
        $pengajuan = $this->buatPengajuanDospem($mahasiswa, $skripsi);

        $penugasanId = null;
        DB::transaction(function () use ($skripsi, $dosen, $pengajuan, &$penugasanId) {
            $penugasan = $this->buatPenugasanProposal($skripsi, $dosen, $pengajuan);
            $penugasanId = $penugasan->id;

            $outbox1 = $this->service->recordProposalProvisioningEvent($penugasan);
            $outbox2 = $this->service->recordProposalProvisioningEvent($penugasan);

            $this->assertEquals($outbox1->id, $outbox2->id);
            $this->assertEquals($outbox1->event_uuid, $outbox2->event_uuid);
        });

        // Pastikan hanya ada tepat 1 record untuk penugasan ini di database
        $count = DB::table('bchat_provisioning_outbox')
            ->where('penugasan_pembimbing_id', $penugasanId)
            ->count();
        $this->assertEquals(1, $count);
    }

    /**
     * 5. Event existing dengan status PROCESSING dan COMPLETED tetap idempoten (mengembalikan event).
     */
    public function test_event_existing_processing_dan_completed_tetap_idempoten(): void
    {
        ['mahasiswa' => $mahasiswa] = $this->buatMahasiswa();
        ['dosen' => $dosen] = $this->buatDosen();
        $skripsi = $this->buatSkripsi($mahasiswa);
        $pengajuan = $this->buatPengajuanDospem($mahasiswa, $skripsi);

        DB::transaction(function () use ($skripsi, $dosen, $pengajuan) {
            $penugasan = $this->buatPenugasanProposal($skripsi, $dosen, $pengajuan);
            $outbox = $this->service->recordProposalProvisioningEvent($penugasan);

            // Simulasi status PROCESSING
            $outbox->update(['status' => 'PROCESSING']);
            $resProcessing = $this->service->recordProposalProvisioningEvent($penugasan);
            $this->assertEquals($outbox->id, $resProcessing->id);
            $this->assertEquals('PROCESSING', $resProcessing->status);

            // Simulasi status COMPLETED
            $outbox->update(['status' => 'COMPLETED']);
            $resCompleted = $this->service->recordProposalProvisioningEvent($penugasan);
            $this->assertEquals($outbox->id, $resCompleted->id);
            $this->assertEquals('COMPLETED', $resCompleted->status);
        });
    }

    /**
     * 6. Event existing dengan status FAILED menolak pembuatan ulang otomatis (harus lewat replay administratif).
     */
    public function test_event_existing_failed_tidak_otomatis_direplay_dan_menolak_penggantian(): void
    {
        ['mahasiswa' => $mahasiswa] = $this->buatMahasiswa();
        ['dosen' => $dosen] = $this->buatDosen();
        $skripsi = $this->buatSkripsi($mahasiswa);
        $pengajuan = $this->buatPengajuanDospem($mahasiswa, $skripsi);

        DB::transaction(function () use ($skripsi, $dosen, $pengajuan) {
            $penugasan = $this->buatPenugasanProposal($skripsi, $dosen, $pengajuan);
            $outbox = $this->service->recordProposalProvisioningEvent($penugasan);

            // Set ke FAILED
            $outbox->update(['status' => 'FAILED']);

            $this->expectException(DomainException::class);
            $this->expectExceptionCode(409);
            $this->expectExceptionMessage('berstatus FAILED. Pembuatan ulang otomatis dilarang; intervensi replay administratif diperlukan.');

            $this->service->recordProposalProvisioningEvent($penugasan);
        });
    }

    /**
     * 7. Rollback transaksi membatalkan seluruh perubahan penugasan dan outbox secara atomik.
     */
    public function test_rollback_transaksi_menghapus_penugasan_dan_outbox_bersama(): void
    {
        ['mahasiswa' => $mahasiswa] = $this->buatMahasiswa();
        ['dosen' => $dosen] = $this->buatDosen();
        $skripsi = $this->buatSkripsi($mahasiswa);
        $pengajuan = $this->buatPengajuanDospem($mahasiswa, $skripsi);

        $penugasanId = null;
        try {
            DB::transaction(function () use ($skripsi, $dosen, $pengajuan, &$penugasanId) {
                $penugasan = $this->buatPenugasanProposal($skripsi, $dosen, $pengajuan);
                $penugasanId = $penugasan->id;
                $this->service->recordProposalProvisioningEvent($penugasan);

                throw new \RuntimeException('Simulasi error tak terduga sebelum commit.');
            });
        } catch (\RuntimeException $e) {
            // Expected
        }

        // Keduanya wajib tidak ada di database
        $this->assertDatabaseMissing('penugasan_pembimbing', [
            'skripsi_id' => $skripsi->id_skripsi,
        ]);
        if ($penugasanId) {
            $this->assertDatabaseMissing('bchat_provisioning_outbox', [
                'penugasan_pembimbing_id' => $penugasanId,
            ]);
            $this->assertEquals(
                0,
                DB::table('bchat_provisioning_outbox')->where('penugasan_pembimbing_id', $penugasanId)->count()
            );
        }
    }

    /**
     * 8. Integrasi controller tetapkan() berhasil membuat penugasan kanonis, log, legacy write, dan outbox.
     */
    public function test_integrasi_controller_tetapkan_berhasil_menghasilkan_canonical_write_dan_outbox(): void
    {
        ['user' => $adminUser] = $this->buatAdmin();
        ['mahasiswa' => $mahasiswa] = $this->buatMahasiswa();
        ['dosen' => $dosen] = $this->buatDosen();
        ['dosen' => $dosenPa] = $this->buatDosen();

        $skripsi = $this->buatSkripsi($mahasiswa);
        $this->buatPersetujuanJudul($mahasiswa, $skripsi, $dosenPa);
        $pengajuan = $this->buatPengajuanDospem($mahasiswa, $skripsi);

        $response = $this->actingAs($adminUser)->postJson("/api/admin/pengajuan-dospem/{$pengajuan->id}/tetapkan", [
            'id_dosen'      => $dosen->id_dosen,
            'catatan_admin' => 'Dosen proposal ditetapkan.',
        ]);

        $response->assertStatus(200);
        $response->assertJson([
            'status'  => 'success',
            'message' => 'Dosen Pembimbing Proposal berhasil ditetapkan.',
        ]);

        // 1. Pengajuan dospem ter-update
        $this->assertDatabaseHas('pengajuan_dospem', [
            'id'       => $pengajuan->id,
            'status'   => 'ditetapkan',
            'dosen_id' => $dosen->id_dosen,
        ]);

        // 2. Legacy write dosen_pembimbing terbuat
        $this->assertDatabaseHas('dosen_pembimbing', [
            'id_skripsi' => $skripsi->id_skripsi,
            'id_dosen'   => $dosen->id_dosen,
        ]);

        // 3. Canonical penugasan_pembimbing terbuat
        $this->assertDatabaseHas('penugasan_pembimbing', [
            'skripsi_id'          => $skripsi->id_skripsi,
            'tahap'               => 'proposal',
            'pembimbing_ke'       => 1,
            'dosen_id'            => $dosen->id_dosen,
            'sk_dokumen_id'       => null,
            'pengajuan_dospem_id' => $pengajuan->id,
            'status_penugasan'    => 'AKTIF',
        ]);

        $penugasan = PenugasanPembimbing::where('pengajuan_dospem_id', $pengajuan->id)->firstOrFail();

        // 4. Audit log mutasi penugasan pembimbing terbuat
        $this->assertDatabaseHas('penugasan_pembimbing_logs', [
            'penugasan_pembimbing_id' => $penugasan->id,
            'tindakan'                => 'PENETAPAN_AWAL',
            'status_sesudah'          => 'AKTIF',
            'snapshot_dosen_baru_id'  => $dosen->id_dosen,
        ]);

        // 5. Transactional outbox event terbuat
        $this->assertDatabaseHas('bchat_provisioning_outbox', [
            'penugasan_pembimbing_id' => $penugasan->id,
            'event_type'              => 'PROPOSAL_ROOM_PROVISION',
            'status'                  => 'PENDING',
            'attempts'                => 0,
            'generation_token'        => 1,
        ]);
    }

    /**
     * 9. Kegagalan outbox di controller me-rollback seluruh mutasi akademik tanpa sisa.
     */
    public function test_integrasi_controller_kegagalan_outbox_me_rollback_seluruh_perubahan_akademik(): void
    {
        ['user' => $adminUser] = $this->buatAdmin();
        ['mahasiswa' => $mahasiswa] = $this->buatMahasiswa();
        ['dosen' => $dosen] = $this->buatDosen();
        ['dosen' => $dosenPa] = $this->buatDosen();

        $skripsi = $this->buatSkripsi($mahasiswa);
        $this->buatPersetujuanJudul($mahasiswa, $skripsi, $dosenPa);
        $pengajuan = $this->buatPengajuanDospem($mahasiswa, $skripsi);

        // Mock service untuk menyimulasikan kegagalan outbox
        $this->mock(BchatProvisioningOutboxService::class, function ($mock) {
            $mock->shouldReceive('recordProposalProvisioningEvent')
                ->once()
                ->andThrow(new \RuntimeException('Simulasi kegagalan koneksi storage outbox.'));
        });

        $existingOutboxIds = DB::table('bchat_provisioning_outbox')->pluck('id')->all();

        $response = $this->actingAs($adminUser)->postJson("/api/admin/pengajuan-dospem/{$pengajuan->id}/tetapkan", [
            'id_dosen' => $dosen->id_dosen,
        ]);

        $response->assertStatus(500);

        // Seluruh mutasi akademik wajib bersih (rollback)
        $this->assertDatabaseHas('pengajuan_dospem', [
            'id'     => $pengajuan->id,
            'status' => 'menunggu_penetapan',
        ]);

        $this->assertDatabaseMissing('dosen_pembimbing', [
            'id_skripsi' => $skripsi->id_skripsi,
        ]);

        $this->assertDatabaseMissing('penugasan_pembimbing', [
            'skripsi_id' => $skripsi->id_skripsi,
        ]);

        $this->assertDatabaseMissing('penugasan_pembimbing_logs', [
            'snapshot_dosen_baru_id' => $dosen->id_dosen,
        ]);

        // Verifikasi tidak ada baris outbox baru yang dibuat oleh transaksi yang gagal
        $newOutboxCount = DB::table('bchat_provisioning_outbox')
            ->when(!empty($existingOutboxIds), fn ($q) => $q->whereNotIn('id', $existingOutboxIds))
            ->count();
        $this->assertEquals(0, $newOutboxCount);
    }

    /**
     * 10. Controller menolak duplikasi penetapan jika slot proposal sudah aktif.
     */
    public function test_integrasi_controller_menolak_duplikasi_jika_slot_proposal_sudah_aktif(): void
    {
        ['user' => $adminUser] = $this->buatAdmin();
        ['mahasiswa' => $mahasiswa] = $this->buatMahasiswa();
        ['dosen' => $dosen1] = $this->buatDosen();
        ['dosen' => $dosen2] = $this->buatDosen();
        ['dosen' => $dosenPa] = $this->buatDosen();

        $skripsi = $this->buatSkripsi($mahasiswa);
        $this->buatPersetujuanJudul($mahasiswa, $skripsi, $dosenPa);
        $pengajuan = $this->buatPengajuanDospem($mahasiswa, $skripsi);

        // Penetapan pertama berhasil
        $response1 = $this->actingAs($adminUser)->postJson("/api/admin/pengajuan-dospem/{$pengajuan->id}/tetapkan", [
            'id_dosen' => $dosen1->id_dosen,
        ]);
        $response1->assertStatus(200);

        // Percobaan penetapan kedua harus ditolak
        $response2 = $this->actingAs($adminUser)->postJson("/api/admin/pengajuan-dospem/{$pengajuan->id}/tetapkan", [
            'id_dosen' => $dosen2->id_dosen,
        ]);
        $response2->assertStatus(409);
    }
}
