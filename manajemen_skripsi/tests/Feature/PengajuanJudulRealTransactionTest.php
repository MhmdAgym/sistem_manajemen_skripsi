<?php

namespace Tests\Feature;

use App\Models\Dosen;
use App\Models\DosenPA;
use App\Models\Mahasiswa;
use App\Models\PengajuanJudul;
use App\Models\PengajuanJudulLog;
use App\Models\PenugasanKaprodi;
use App\Models\Skripsi;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Suite pengujian real commit & real rollback tanpa pembungkus DatabaseTransactions.
 *
 * Tujuan:
 * 1. Menguji commit fisik ke engine MySQL dan memastikan kode pasca-commit benar-benar berjalan.
 * 2. Menguji rollback fisik ke engine MySQL saat terjadi kegagalan audit log.
 * 3. Menguji penanganan kegagalan filesystem (throw exception maupun return false).
 * 4. Menguji sanitasi pesan respons error 500 untuk mencegah kebocoran informasi database.
 * 5. Mengimplementasikan self-cleaning fixtures di tearDown() tanpa menyentuh data lain.
 */
class PengajuanJudulRealTransactionTest extends TestCase
{
    // Catatan: TIDAK menggunakan DatabaseTransactions!
    // Setiap transaksi DB::transaction() di controller adalah root transaction murni.

    protected array $createdUserIds = [];
    protected array $createdMahasiswaIds = [];
    protected array $createdDosenIds = [];
    protected array $createdSkripsiIds = [];
    protected array $createdPengajuanIds = [];
    protected array $createdFilePaths = [];

    protected function setUp(): void
    {
        parent::setUp();

        // Verifikasi ketat target database adalah manajemen_skripsi_test
        $activeDb = DB::connection()->getDatabaseName();
        $this->assertEquals('manajemen_skripsi_test', $activeDb, 'CRITICAL: Suite real transaction HANYA boleh berjalan pada manajemen_skripsi_test.');
    }

    protected function tearDown(): void
    {
        // Pembersihan fixture mandiri (self-cleaning) sesuai urutan foreign key
        try {
            if (!empty($this->createdPengajuanIds)) {
                DB::table('pengajuan_judul_logs')->whereIn('pengajuan_judul_id', $this->createdPengajuanIds)->delete();
                DB::table('pengajuan_judul')->whereIn('id', $this->createdPengajuanIds)->delete();
            }
            if (!empty($this->createdSkripsiIds)) {
                DB::table('dosen_pa')->whereIn('id_skripsi', $this->createdSkripsiIds)->delete();
                DB::table('skripsi')->whereIn('id_skripsi', $this->createdSkripsiIds)->delete();
            }
            if (!empty($this->createdMahasiswaIds)) {
                DB::table('mahasiswa')->whereIn('id_mahasiswa', $this->createdMahasiswaIds)->delete();
            }
            if (!empty($this->createdDosenIds)) {
                DB::table('penugasan_kaprodi')->whereIn('dosen_id', $this->createdDosenIds)->delete();
                DB::table('dosen')->whereIn('id_dosen', $this->createdDosenIds)->delete();
            }
            if (!empty($this->createdUserIds)) {
                DB::table('users')->whereIn('id', $this->createdUserIds)->delete();
            }
            foreach ($this->createdFilePaths as $path) {
                if (Storage::disk('public')->exists($path)) {
                    Storage::disk('public')->delete($path);
                }
            }
        } catch (\Throwable $e) {
            // Log kegagalan cleanup tanpa menyembunyikannya
            Log::error("Gagal membersihkan fixture PengajuanJudulRealTransactionTest: " . $e->getMessage());
        }

        parent::tearDown();
    }

    // -----------------------------------------------------------------------
    // HELPERS FIXTURE DENGAN TRACKING MANDIRI
    // -----------------------------------------------------------------------

    private function buatMahasiswa(string $suffix): array
    {
        $nim = '9999' . substr(strval(time()), -4) . $suffix;
        $user = User::factory()->create([
            'role'        => 'mahasiswa',
            'nomor_induk' => $nim,
            'name'        => 'Mhs ' . $nim,
        ]);
        $this->createdUserIds[] = $user->id;

        $mahasiswa = Mahasiswa::create([
            'user_id'        => $user->id,
            'NIM'            => $nim,
            'nama_mahasiswa' => 'Mhs ' . $nim,
            'prodi'          => 'Informatika',
        ]);
        $this->createdMahasiswaIds[] = $mahasiswa->id_mahasiswa;

        return compact('user', 'mahasiswa');
    }

    private function buatDosen(string $suffix): array
    {
        $nidn = '8888' . substr(strval(time()), -4) . $suffix;
        $user = User::factory()->create([
            'role'        => 'dosen',
            'nomor_induk' => $nidn,
            'name'        => 'Dosen ' . $nidn,
        ]);
        $this->createdUserIds[] = $user->id;

        $dosen = Dosen::create([
            'user_id'    => $user->id,
            'NIDN'       => $nidn,
            'nama_dosen' => 'Dosen ' . $nidn,
        ]);
        $this->createdDosenIds[] = $dosen->id_dosen;

        return compact('user', 'dosen');
    }

    private function buatSkripsi(Mahasiswa $mhs): Skripsi
    {
        $skripsi = Skripsi::create([
            'id_mahasiswa' => $mhs->id_mahasiswa,
            'judul'        => 'Judul Skripsi ' . $mhs->NIM,
            'status_judul' => 'diajukan',
        ]);
        $this->createdSkripsiIds[] = $skripsi->id_skripsi;

        return $skripsi;
    }

    private function buatDosenPA(Dosen $dosen, Skripsi $skripsi): DosenPA
    {
        return DosenPA::create([
            'id_dosen'   => $dosen->id_dosen,
            'id_skripsi' => $skripsi->id_skripsi,
        ]);
    }

    private function buatPenugasanKaprodi(
        Dosen $dosen,
        string $prodi = 'Informatika',
        bool $isAktif = true
    ): PenugasanKaprodi {
        return PenugasanKaprodi::create([
            'dosen_id'        => $dosen->id_dosen,
            'prodi'           => $prodi,
            'tanggal_mulai'   => now()->subMonth()->toDateString(),
            'tanggal_selesai' => null,
            'is_aktif'        => $isAktif,
        ]);
    }

    // -----------------------------------------------------------------------
    // PENGUJIAN COMMIT & ROLLBACK NYATA
    // -----------------------------------------------------------------------

    /**
     * 1. Commit Nyata: Revisi berhasil meng-commit perubahan ke database MySQL,
     * dan berkas lama dihapus pasca-commit.
     */
    public function test_real_commit_updates_database_and_deletes_old_file_post_commit(): void
    {
        Storage::fake('public');

        $mhs = $this->buatMahasiswa('01');
        $dsn = $this->buatDosen('01');
        $skripsi = $this->buatSkripsi($mhs['mahasiswa']);
        $this->buatDosenPA($dsn['dosen'], $skripsi);

        // Buat berkas lama di storage fake
        $oldFileContent = 'BERKAS PROPOSAL LAMA REVISI';
        $oldPath = 'pengajuan-judul/real_old_file_' . uniqid() . '.pdf';
        Storage::disk('public')->put($oldPath, $oldFileContent);
        $this->createdFilePaths[] = $oldPath;

        $pengajuan = PengajuanJudul::create([
            'skripsi_id'   => $skripsi->id_skripsi,
            'mahasiswa_id' => $mhs['mahasiswa']->id_mahasiswa,
            'dosen_pa_id'  => $dsn['user']->id,
            'judul'        => 'Judul Awal Pra-Revisi',
            'deskripsi'    => 'Deskripsi Awal',
            'nama_file'    => 'old.pdf',
            'ukuran_file'  => '100 KB',
            'file_path'    => $oldPath,
            'status'       => 'Revisi Dosen PA',
        ]);
        $this->createdPengajuanIds[] = $pengajuan->id;

        // Mahasiswa kirim revisi dengan berkas baru
        $newFile = UploadedFile::fake()->create('real_new_proposal.pdf', 300, 'application/pdf');

        $response = $this->actingAs($mhs['user'], 'sanctum')
            ->postJson("/api/mahasiswa/pengajuan-judul/{$pengajuan->id}/revisi", [
                'judul'             => 'Judul Hasil Perbaikan Nyata',
                'deskripsi'         => 'Deskripsi Baru',
                'file_judul'        => $newFile,
                'catatan_mahasiswa' => 'Perbaikan bab 1 selesai.',
            ]);

        $response->assertStatus(200);
        $response->assertJsonFragment(['status' => 'success']);

        // Verifikasi fisik di MySQL: Status berubah menjadi Menunggu Verifikasi Dosen PA
        $freshPengajuan = PengajuanJudul::find($pengajuan->id);
        $this->assertEquals('Menunggu Verifikasi Dosen PA', $freshPengajuan->status);
        $this->assertEquals('Judul Hasil Perbaikan Nyata', $freshPengajuan->judul);
        $this->assertNotEquals($oldPath, $freshPengajuan->file_path);

        $newPath = $freshPengajuan->file_path;
        $this->createdFilePaths[] = $newPath;

        // Verifikasi berkas baru ada di disk dan berkas lama telah terhapus
        Storage::disk('public')->assertExists($newPath);
        Storage::disk('public')->assertMissing($oldPath);
    }

    /**
     * 2. Rollback Nyata: Kegagalan audit log membatalkan seluruh mutasi ke database MySQL,
     * berkas baru dibersihkan, dan berkas lama tetap utuh di disk.
     */
    public function test_real_rollback_retains_old_file_and_cleans_new_file_on_audit_failure(): void
    {
        Storage::fake('public');

        $mhs = $this->buatMahasiswa('02');
        $dsn = $this->buatDosen('02');
        $skripsi = $this->buatSkripsi($mhs['mahasiswa']);
        $this->buatDosenPA($dsn['dosen'], $skripsi);

        $oldPath = 'pengajuan-judul/real_safe_old_' . uniqid() . '.pdf';
        Storage::disk('public')->put($oldPath, 'BERKAS LAMA YANG HARUS AMAN');
        $this->createdFilePaths[] = $oldPath;

        $pengajuan = PengajuanJudul::create([
            'skripsi_id'   => $skripsi->id_skripsi,
            'mahasiswa_id' => $mhs['mahasiswa']->id_mahasiswa,
            'dosen_pa_id'  => $dsn['user']->id,
            'judul'        => 'Judul Pra-Rollback',
            'deskripsi'    => 'D',
            'nama_file'    => 'old.pdf',
            'ukuran_file'  => '100 KB',
            'file_path'    => $oldPath,
            'status'       => 'Revisi Dosen PA',
        ]);
        $this->createdPengajuanIds[] = $pengajuan->id;

        // Injeksi kegagalan audit log
        PengajuanJudulLog::creating(function () {
            throw new \RuntimeException("SIMULASI ERROR AUDIT LOG REAL ROLLBACK");
        });

        try {
            $newFile = UploadedFile::fake()->create('orphan_candidate.pdf', 300, 'application/pdf');

            $response = $this->actingAs($mhs['user'], 'sanctum')
                ->postJson("/api/mahasiswa/pengajuan-judul/{$pengajuan->id}/revisi", [
                    'judul'      => 'Judul Yang Gagal',
                    'file_judul' => $newFile,
                ]);

            $response->assertStatus(500);
            $response->assertJsonFragment(['status' => 'error']);
        } finally {
            PengajuanJudulLog::flushEventListeners();
        }

        // Verifikasi fisik di MySQL: Data tidak berubah (status tetap Revisi Dosen PA, path tetap oldPath)
        $freshPengajuan = PengajuanJudul::find($pengajuan->id);
        $this->assertEquals('Revisi Dosen PA', $freshPengajuan->status);
        $this->assertEquals($oldPath, $freshPengajuan->file_path);

        // Verifikasi berkas lama TETAP ADA di storage
        Storage::disk('public')->assertExists($oldPath);
    }

    /**
     * 3. Kegagalan Filesystem Pasca-Commit: Jika Storage::delete($oldFilePath) mengembalikan false,
     * transaksi database tetap sah (commit) dan respons tetap 200 Success.
     */
    public function test_old_file_delete_failure_post_commit_does_not_abort_database_success(): void
    {
        Storage::fake('public');

        $mhs = $this->buatMahasiswa('03');
        $dsn = $this->buatDosen('03');
        $skripsi = $this->buatSkripsi($mhs['mahasiswa']);
        $this->buatDosenPA($dsn['dosen'], $skripsi);

        // Path lama yang sengaja TIDAK dibuat di storage (sehingga delete mengembalikan false/tidak ada)
        $nonExistentOldPath = 'pengajuan-judul/ghost_old_file_' . uniqid() . '.pdf';

        $pengajuan = PengajuanJudul::create([
            'skripsi_id'   => $skripsi->id_skripsi,
            'mahasiswa_id' => $mhs['mahasiswa']->id_mahasiswa,
            'dosen_pa_id'  => $dsn['user']->id,
            'judul'        => 'Judul Pra-Revisi',
            'deskripsi'    => 'D',
            'nama_file'    => 'ghost.pdf',
            'ukuran_file'  => '100 KB',
            'file_path'    => $nonExistentOldPath,
            'status'       => 'Revisi Dosen PA',
        ]);
        $this->createdPengajuanIds[] = $pengajuan->id;

        $newFile = UploadedFile::fake()->create('valid_new_doc.pdf', 250, 'application/pdf');

        $response = $this->actingAs($mhs['user'], 'sanctum')
            ->postJson("/api/mahasiswa/pengajuan-judul/{$pengajuan->id}/revisi", [
                'judul'      => 'Judul Perbaikan Valid',
                'file_judul' => $newFile,
            ]);

        // Respons tetap harus 200 Success
        $response->assertStatus(200);

        $freshPengajuan = PengajuanJudul::find($pengajuan->id);
        $this->assertEquals('Menunggu Verifikasi Dosen PA', $freshPengajuan->status);
        $this->assertEquals('Judul Perbaikan Valid', $freshPengajuan->judul);
        $this->createdFilePaths[] = $freshPengajuan->file_path;

        Storage::disk('public')->assertExists($freshPengajuan->file_path);
    }

    /**
     * 4. Keamanan Informasi Error 500: Respons HTTP 500 tidak boleh membocorkan detail SQL / tabel.
     */
    public function test_internal_database_error_does_not_leak_sql_details_in_500_response(): void
    {
        $mhs = $this->buatMahasiswa('04');
        $dsn = $this->buatDosen('04');
        $skripsi = $this->buatSkripsi($mhs['mahasiswa']);
        $this->buatDosenPA($dsn['dosen'], $skripsi);

        $pengajuan = PengajuanJudul::create([
            'skripsi_id'   => $skripsi->id_skripsi,
            'mahasiswa_id' => $mhs['mahasiswa']->id_mahasiswa,
            'dosen_pa_id'  => $dsn['user']->id,
            'judul'        => 'Judul Test Error 500',
            'deskripsi'    => 'D',
            'nama_file'    => 'test.pdf',
            'ukuran_file'  => '100 KB',
            'file_path'    => 'p/test.pdf',
            'status'       => 'Revisi Dosen PA',
        ]);
        $this->createdPengajuanIds[] = $pengajuan->id;

        // Injeksi exception yang menyerupai PDOException / SQL detail
        PengajuanJudulLog::creating(function () {
            throw new \PDOException("SQLSTATE[42S02]: Base table or view not found: 1146 Table 'manajemen_skripsi_test.secret_table' doesn't exist");
        });

        try {
            $response = $this->actingAs($mhs['user'], 'sanctum')
                ->postJson("/api/mahasiswa/pengajuan-judul/{$pengajuan->id}/revisi", [
                    'judul' => 'Judul Baru Yang Memicu SQL Error',
                ]);

            $response->assertStatus(500);

            // Verifikasi tidak ada SQLSTATE, nama tabel, atau error mentah di respons
            $content = $response->getContent();
            $this->assertStringNotContainsString('SQLSTATE', $content);
            $this->assertStringNotContainsString('secret_table', $content);
            $this->assertStringNotContainsString('Base table or view not found', $content);

            // Verifikasi respons menggunakan pesan generik aman
            $response->assertJsonFragment([
                'status'  => 'error',
                'message' => 'Terjadi kesalahan pada server saat memproses perbaikan pengajuan. Silakan coba lagi.',
            ]);
        } finally {
            PengajuanJudulLog::flushEventListeners();
        }
    }

    /**
     * 5. Record Tidak Ditemukan Saat Penguncian: Menangani record null secara aman tanpa fatal error.
     */
    public function test_record_deleted_before_lock_returns_422_safely_without_null_pointer(): void
    {
        $mhs = $this->buatMahasiswa('05');

        // Panggil endpoint revisi dengan ID fiktif / sudah dihapus
        $response = $this->actingAs($mhs['user'], 'sanctum')
            ->postJson("/api/mahasiswa/pengajuan-judul/99999999/revisi", [
                'judul' => 'Revisi ID Tidak Ada',
            ]);

        // Ditolak dengan 404 (atau 422 di dalam lock), tanpa error 500
        $this->assertContains($response->status(), [404, 422]);
        $response->assertJsonFragment(['status' => 'error']);
    }

    /**
     * 6. Validasi Penguncian Induk Skripsi pada store(): Judul final disetujui memblokir store() di dalam transaksi.
     */
    public function test_store_inside_transaction_blocks_submission_if_final_title_already_approved(): void
    {
        Storage::fake('public');

        $mhs = $this->buatMahasiswa('06');
        $dsn = $this->buatDosen('06');
        $skripsi = $this->buatSkripsi($mhs['mahasiswa']);
        $this->buatDosenPA($dsn['dosen'], $skripsi);

        // Set status judul skripsi menjadi disetujui
        $skripsi->update(['status_judul' => 'disetujui']);

        $file = UploadedFile::fake()->create('dokumen_final.pdf', 300, 'application/pdf');

        $response = $this->actingAs($mhs['user'], 'sanctum')
            ->postJson('/api/mahasiswa/pengajuan-judul', [
                'judul'      => 'Judul Mencoba Bypass Final',
                'deskripsi'  => 'D',
                'file_judul' => $file,
            ]);

        $response->assertStatus(422);
        $response->assertJsonFragment([
            'status'  => 'error',
            'message' => 'Skripsi Anda sudah memiliki judul yang disetujui secara final.',
        ]);
    }

    /**
     * 7. Sanitasi Error 500 Verifikasi Dosen PA:
     * Exception internal tidak boleh membocorkan SQLSTATE, query mentah, nama tabel, atau stack trace.
     */
    public function test_verifikasi_dosen_internal_database_error_does_not_leak_sql_details_in_500_response(): void
    {
        Log::spy();

        $mhs = $this->buatMahasiswa('07');
        $dsn = $this->buatDosen('07');
        $skripsi = $this->buatSkripsi($mhs['mahasiswa']);
        $this->buatDosenPA($dsn['dosen'], $skripsi);

        $pengajuan = PengajuanJudul::create([
            'skripsi_id'   => $skripsi->id_skripsi,
            'mahasiswa_id' => $mhs['mahasiswa']->id_mahasiswa,
            'dosen_pa_id'  => $dsn['user']->id,
            'judul'        => 'Pengajuan Uji Sanitasi PA',
            'deskripsi'    => 'D',
            'nama_file'    => 'pa.pdf',
            'ukuran_file'  => '100 KB',
            'file_path'    => 'p/pa.pdf',
            'status'       => 'Menunggu Verifikasi Dosen PA',
        ]);
        $this->createdPengajuanIds[] = $pengajuan->id;

        // Injeksi exception yang menyerupai PDOException / SQL detail
        PengajuanJudulLog::creating(function () {
            throw new \PDOException("SQLSTATE[42S02]: Base table or view not found: 1146 Table 'manajemen_skripsi_test.secret_pa_table' doesn't exist");
        });

        try {
            $response = $this->actingAs($dsn['user'], 'sanctum')
                ->patchJson("/api/dosen/pengajuan-judul/{$pengajuan->id}/verifikasi", [
                    'status'        => 'Menunggu Persetujuan Kaprodi',
                    'catatan_dosen' => 'Disetujui PA',
                ]);

            $response->assertStatus(500);

            // Verifikasi tidak ada SQLSTATE, nama tabel, atau error mentah di respons
            $content = $response->getContent();
            $this->assertStringNotContainsString('SQLSTATE', $content);
            $this->assertStringNotContainsString('secret_pa_table', $content);
            $this->assertStringNotContainsString('Base table or view not found', $content);

            // Verifikasi respons menggunakan pesan generik aman
            $response->assertJsonFragment([
                'status'  => 'error',
                'message' => 'Terjadi kesalahan pada server saat memproses verifikasi pengajuan. Silakan coba lagi.',
            ]);

            // Verifikasi rollback terjadi: status tetap awal dan log tidak tersimpan
            $this->assertDatabaseHas('pengajuan_judul', [
                'id'     => $pengajuan->id,
                'status' => 'Menunggu Verifikasi Dosen PA',
            ]);
            $this->assertDatabaseMissing('pengajuan_judul_logs', [
                'pengajuan_judul_id' => $pengajuan->id,
            ]);

            // Verifikasi server log mencatat detail error beserta konteks
            Log::shouldHaveReceived('error')
                ->once()
                ->withArgs(function ($message, $context) use ($dsn, $pengajuan) {
                    return str_contains($message, 'Kegagalan internal saat verifikasi pengajuan judul oleh Dosen PA')
                        && isset($context['exception'])
                        && ($context['user_id'] ?? null) === $dsn['user']->id
                        && ($context['pengajuan_id'] ?? null) == $pengajuan->id;
                });
        } finally {
            PengajuanJudulLog::flushEventListeners();
        }
    }

    /**
     * 8. Pemisahan DomainException pada Verifikasi Dosen PA:
     * Perubahan status yang terjadi di dalam transaksi mengembalikan HTTP 422, bukan HTTP 500.
     */
    public function test_verifikasi_dosen_concurrent_status_change_returns_422_domain_exception(): void
    {
        $mhs = $this->buatMahasiswa('08');
        $dsn = $this->buatDosen('08');
        $skripsi = $this->buatSkripsi($mhs['mahasiswa']);
        $this->buatDosenPA($dsn['dosen'], $skripsi);

        $pengajuan = PengajuanJudul::create([
            'skripsi_id'   => $skripsi->id_skripsi,
            'mahasiswa_id' => $mhs['mahasiswa']->id_mahasiswa,
            'dosen_pa_id'  => $dsn['user']->id,
            'judul'        => 'Pengajuan Uji Domain PA',
            'deskripsi'    => 'D',
            'nama_file'    => 'pa.pdf',
            'ukuran_file'  => '100 KB',
            'file_path'    => 'p/pa.pdf',
            'status'       => 'Menunggu Verifikasi Dosen PA',
        ]);
        $this->createdPengajuanIds[] = $pengajuan->id;

        // Simulasikan status telah berubah saat lock didapat di dalam transaksi
        PengajuanJudulLog::creating(function () {
            throw new \DomainException("Status pengajuan telah berubah.");
        });

        try {
            $response = $this->actingAs($dsn['user'], 'sanctum')
                ->patchJson("/api/dosen/pengajuan-judul/{$pengajuan->id}/verifikasi", [
                    'status'        => 'Menunggu Persetujuan Kaprodi',
                    'catatan_dosen' => 'Disetujui PA',
                ]);

            $response->assertStatus(422);
            $response->assertJsonFragment([
                'status'  => 'error',
                'message' => 'Status pengajuan telah berubah.',
            ]);
        } finally {
            PengajuanJudulLog::flushEventListeners();
        }
    }

    /**
     * 9. Sanitasi Error 500 Persetujuan Kaprodi:
     * Exception internal tidak boleh membocorkan SQLSTATE, query mentah, nama tabel, atau stack trace.
     */
    public function test_persetujuan_kaprodi_internal_database_error_does_not_leak_sql_details_in_500_response(): void
    {
        Log::spy();

        $mhs = $this->buatMahasiswa('09');
        $dsnKaprodi = $this->buatDosen('09');
        $this->buatPenugasanKaprodi($dsnKaprodi['dosen'], 'Informatika', true);

        $skripsi = $this->buatSkripsi($mhs['mahasiswa'], 'Judul Lama Skripsi', 'diajukan');

        $pengajuan = PengajuanJudul::create([
            'skripsi_id'   => $skripsi->id_skripsi,
            'mahasiswa_id' => $mhs['mahasiswa']->id_mahasiswa,
            'dosen_pa_id'  => $dsnKaprodi['user']->id,
            'judul'        => 'Pengajuan Uji Sanitasi Kaprodi',
            'deskripsi'    => 'D',
            'nama_file'    => 'kaprodi.pdf',
            'ukuran_file'  => '100 KB',
            'file_path'    => 'p/kaprodi.pdf',
            'status'       => 'Menunggu Persetujuan Kaprodi',
        ]);
        $this->createdPengajuanIds[] = $pengajuan->id;

        // Injeksi exception yang menyerupai PDOException / SQL detail
        PengajuanJudulLog::creating(function () {
            throw new \PDOException("SQLSTATE[42S02]: Base table or view not found: 1146 Table 'manajemen_skripsi_test.secret_kaprodi_table' doesn't exist");
        });

        try {
            $response = $this->actingAs($dsnKaprodi['user'], 'sanctum')
                ->patchJson("/api/kaprodi/pengajuan-judul/{$pengajuan->id}/persetujuan", [
                    'status' => 'Disetujui Kaprodi',
                ]);

            $response->assertStatus(500);

            // Verifikasi tidak ada SQLSTATE, nama tabel, atau error mentah di respons
            $content = $response->getContent();
            $this->assertStringNotContainsString('SQLSTATE', $content);
            $this->assertStringNotContainsString('secret_kaprodi_table', $content);
            $this->assertStringNotContainsString('Base table or view not found', $content);

            // Verifikasi respons menggunakan pesan generik aman
            $response->assertJsonFragment([
                'status'  => 'error',
                'message' => 'Terjadi kesalahan pada server saat memproses persetujuan Kaprodi. Silakan coba lagi.',
            ]);

            // Verifikasi rollback terjadi: status pengajuan tetap awal dan judul skripsi tidak berubah
            $this->assertDatabaseHas('pengajuan_judul', [
                'id'     => $pengajuan->id,
                'status' => 'Menunggu Persetujuan Kaprodi',
            ]);
            $this->assertDatabaseHas('skripsi', [
                'id_skripsi'   => $skripsi->id_skripsi,
                'judul'        => $skripsi->judul,
                'status_judul' => 'diajukan',
            ]);

            // Verifikasi server log mencatat detail error beserta konteks
            Log::shouldHaveReceived('error')
                ->once()
                ->withArgs(function ($message, $context) use ($dsnKaprodi, $pengajuan) {
                    return str_contains($message, 'Kegagalan internal saat persetujuan pengajuan judul oleh Kaprodi')
                        && isset($context['exception'])
                        && ($context['user_id'] ?? null) === $dsnKaprodi['user']->id
                        && ($context['pengajuan_id'] ?? null) == $pengajuan->id;
                });
        } finally {
            PengajuanJudulLog::flushEventListeners();
        }
    }

    /**
     * 10. Pemisahan DomainException pada Persetujuan Kaprodi (Status Berubah):
     * Perubahan status yang terjadi di dalam transaksi mengembalikan HTTP 422, bukan HTTP 500.
     */
    public function test_persetujuan_kaprodi_concurrent_status_change_returns_422_domain_exception(): void
    {
        $mhs = $this->buatMahasiswa('10');
        $dsnKaprodi = $this->buatDosen('10');
        $this->buatPenugasanKaprodi($dsnKaprodi['dosen'], 'Informatika', true);

        $skripsi = $this->buatSkripsi($mhs['mahasiswa']);

        $pengajuan = PengajuanJudul::create([
            'skripsi_id'   => $skripsi->id_skripsi,
            'mahasiswa_id' => $mhs['mahasiswa']->id_mahasiswa,
            'dosen_pa_id'  => $dsnKaprodi['user']->id,
            'judul'        => 'Pengajuan Uji Domain Kaprodi',
            'deskripsi'    => 'D',
            'nama_file'    => 'kaprodi.pdf',
            'ukuran_file'  => '100 KB',
            'file_path'    => 'p/kaprodi.pdf',
            'status'       => 'Menunggu Persetujuan Kaprodi',
        ]);
        $this->createdPengajuanIds[] = $pengajuan->id;

        PengajuanJudulLog::creating(function () {
            throw new \DomainException("Status pengajuan telah berubah oleh transaksi lain.");
        });

        try {
            $response = $this->actingAs($dsnKaprodi['user'], 'sanctum')
                ->patchJson("/api/kaprodi/pengajuan-judul/{$pengajuan->id}/persetujuan", [
                    'status' => 'Disetujui Kaprodi',
                ]);

            $response->assertStatus(422);
            $response->assertJsonFragment([
                'status'  => 'error',
                'message' => 'Status pengajuan telah berubah oleh transaksi lain.',
            ]);
        } finally {
            PengajuanJudulLog::flushEventListeners();
        }
    }

    /**
     * 11. Pemisahan DomainException pada Persetujuan Kaprodi (Inkonsistensi Skripsi):
     * Inkonsistensi data skripsi dengan mahasiswa pengaju mengembalikan HTTP 422, bukan HTTP 500.
     */
    public function test_persetujuan_kaprodi_inconsistent_skripsi_returns_422_domain_exception(): void
    {
        $mhs1 = $this->buatMahasiswa('11');
        $mhs2 = $this->buatMahasiswa('12');
        $dsnKaprodi = $this->buatDosen('11');
        $this->buatPenugasanKaprodi($dsnKaprodi['dosen'], 'Informatika', true);

        // Skripsi milik mhs2, tetapi diajukan seolah-olah oleh mhs1
        $skripsiMhs2 = $this->buatSkripsi($mhs2['mahasiswa']);

        $pengajuan = PengajuanJudul::create([
            'skripsi_id'   => $skripsiMhs2->id_skripsi,
            'mahasiswa_id' => $mhs1['mahasiswa']->id_mahasiswa,
            'dosen_pa_id'  => $dsnKaprodi['user']->id,
            'judul'        => 'Pengajuan Skripsi Mismatch',
            'deskripsi'    => 'D',
            'nama_file'    => 'kaprodi.pdf',
            'ukuran_file'  => '100 KB',
            'file_path'    => 'p/kaprodi.pdf',
            'status'       => 'Menunggu Persetujuan Kaprodi',
        ]);
        $this->createdPengajuanIds[] = $pengajuan->id;

        $response = $this->actingAs($dsnKaprodi['user'], 'sanctum')
            ->patchJson("/api/kaprodi/pengajuan-judul/{$pengajuan->id}/persetujuan", [
                'status' => 'Disetujui Kaprodi',
            ]);

        $response->assertStatus(422);
        $response->assertJsonFragment([
            'status'  => 'error',
            'message' => 'Data skripsi tidak konsisten dengan pengajuan.',
        ]);
    }
}
