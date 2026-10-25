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
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class KaprodiWorkflowFeatureTest extends TestCase
{
    use DatabaseTransactions;

    // -----------------------------------------------------------------------
    // FIXTURE HELPERS
    // -----------------------------------------------------------------------

    private function buatMahasiswa(string $nim = '2010110001', string $prodi = 'Informatika'): array
    {
        $user = User::factory()->create([
            'role'        => 'mahasiswa',
            'nomor_induk' => $nim,
            'name'        => 'Mahasiswa ' . $nim,
        ]);

        $mahasiswa = Mahasiswa::create([
            'user_id'        => $user->id,
            'NIM'            => $nim,
            'nama_mahasiswa' => 'Mahasiswa ' . $nim,
            'prodi'          => $prodi,
        ]);

        return compact('user', 'mahasiswa');
    }

    private function buatDosen(string $nidn = '0011223381'): array
    {
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

    private function buatSkripsi(Mahasiswa $mahasiswa, string $judul = 'Rencana Skripsi Awal', string $statusJudul = 'diajukan'): Skripsi
    {
        return Skripsi::create([
            'id_mahasiswa' => $mahasiswa->id_mahasiswa,
            'judul'        => $judul,
            'status_judul' => $statusJudul,
        ]);
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
        string $prodi = 'Sains Pertanian',
        bool $isAktif = true,
        ?string $mulai = null,
        ?string $selesai = null
    ): PenugasanKaprodi {
        return PenugasanKaprodi::create([
            'dosen_id'        => $dosen->id_dosen,
            'prodi'           => $prodi,
            'tanggal_mulai'   => $mulai ?? now()->subMonth()->toDateString(),
            'tanggal_selesai' => $selesai,
            'is_aktif'        => $isAktif,
        ]);
    }

    private function buatFileMock(string $nama = 'dokumen_proposal.pdf'): UploadedFile
    {
        Storage::fake('public');
        return UploadedFile::fake()->create($nama, 400, 'application/pdf');
    }

    // -----------------------------------------------------------------------
    // 1. PENGAJUAN MAHASISWA & ATURAN SKRIPSI
    // -----------------------------------------------------------------------

    /**
     * 1. Mahasiswa dapat mengajukan judul dengan skripsi miliknya yang valid (tepat 1 skripsi).
     */
    public function test_1_mahasiswa_dapat_mengajukan_judul_dengan_skripsi_miliknya_yang_valid(): void
    {
        $mhs = $this->buatMahasiswa('2010110001', 'Informatika');
        $dsn = $this->buatDosen('0011223381');
        $skripsi = $this->buatSkripsi($mhs['mahasiswa'], 'Belum Ditentukan', 'diajukan');
        $this->buatDosenPA($dsn['dosen'], $skripsi);

        $file = $this->buatFileMock();

        $response = $this->actingAs($mhs['user'], 'sanctum')
            ->postJson('/api/mahasiswa/pengajuan-judul', [
                'judul'      => 'Sistem Deteksi Anomali Jaringan Berbasis AI',
                'deskripsi'  => 'Penelitian menggunakan algoritma Random Forest.',
                'file_judul' => $file,
            ]);

        $response->assertStatus(201);
        $response->assertJsonFragment(['status' => 'success']);

        $this->assertDatabaseHas('pengajuan_judul', [
            'skripsi_id'   => $skripsi->id_skripsi,
            'mahasiswa_id' => $mhs['mahasiswa']->id_mahasiswa,
            'dosen_pa_id'  => $dsn['user']->id,
            'judul'        => 'Sistem Deteksi Anomali Jaringan Berbasis AI',
            'status'       => 'Menunggu Verifikasi Dosen PA',
        ]);

        $pengajuanId = $response->json('data.id');
        $this->assertDatabaseHas('pengajuan_judul_logs', [
            'pengajuan_judul_id' => $pengajuanId,
            'user_id'            => $mhs['user']->id,
            'tahap'              => 'pengajuan_awal',
            'tindakan'           => 'submit',
            'status_sesudah'     => 'Menunggu Verifikasi Dosen PA',
        ]);
    }

    /**
     * 2. Mahasiswa tidak dapat mengajukan skripsi milik mahasiswa lain.
     */
    public function test_2_mahasiswa_tidak_dapat_mengajukan_skripsi_milik_mahasiswa_lain(): void
    {
        $mhs1 = $this->buatMahasiswa('2010110001', 'Informatika');
        $mhs2 = $this->buatMahasiswa('2010110002', 'Informatika');
        $dsn = $this->buatDosen('0011223382');

        $skripsi1 = $this->buatSkripsi($mhs1['mahasiswa']);
        $this->buatDosenPA($dsn['dosen'], $skripsi1);

        $skripsi2 = $this->buatSkripsi($mhs2['mahasiswa']);
        $this->buatDosenPA($dsn['dosen'], $skripsi2);

        $file = $this->buatFileMock();

        // Mhs 1 mencoba mengoper skripsi_id / mahasiswa_id milik Mhs 2
        $response = $this->actingAs($mhs1['user'], 'sanctum')
            ->postJson('/api/mahasiswa/pengajuan-judul', [
                'judul'        => 'Judul Mhs 1',
                'deskripsi'    => 'Deskripsi',
                'file_judul'   => $file,
                'skripsi_id'   => $skripsi2->id_skripsi,
                'mahasiswa_id' => $mhs2['mahasiswa']->id_mahasiswa,
            ]);

        $response->assertStatus(201);

        // Backend mengikat pengajuan ke skripsi Mhs 1, BUKAN skripsi Mhs 2
        $this->assertDatabaseHas('pengajuan_judul', [
            'skripsi_id'   => $skripsi1->id_skripsi,
            'mahasiswa_id' => $mhs1['mahasiswa']->id_mahasiswa,
        ]);
        $this->assertDatabaseMissing('pengajuan_judul', [
            'skripsi_id'   => $skripsi2->id_skripsi,
            'mahasiswa_id' => $mhs1['mahasiswa']->id_mahasiswa,
        ]);
    }

    /**
     * 3. Tidak ada skripsi kandidat -> pengajuan ditolak dengan aman (422).
     */
    public function test_3_tidak_ada_skripsi_kandidat_pengajuan_ditolak_dengan_aman(): void
    {
        $mhs = $this->buatMahasiswa('2010110003', 'Informatika');
        $file = $this->buatFileMock();

        $response = $this->actingAs($mhs['user'], 'sanctum')
            ->postJson('/api/mahasiswa/pengajuan-judul', [
                'judul'      => 'Judul Test',
                'deskripsi'  => 'Deskripsi',
                'file_judul' => $file,
            ]);

        $response->assertStatus(422);
        $response->assertJsonFragment([
            'status'  => 'error',
            'message' => 'Data skripsi Anda belum tersedia. Hubungi Administrator.',
        ]);
    }

    /**
     * 4. Kandidat skripsi ambigu (>1 skripsi) -> pengajuan ditolak dengan aman (422).
     */
    public function test_4_kandidat_skripsi_ambigu_pengajuan_ditolak_dengan_aman(): void
    {
        $mhs = $this->buatMahasiswa('2010110004', 'Informatika');
        $dsn = $this->buatDosen('0011223304');

        $skripsiA = $this->buatSkripsi($mhs['mahasiswa'], 'Skripsi A');
        $skripsiB = $this->buatSkripsi($mhs['mahasiswa'], 'Skripsi B');
        $this->buatDosenPA($dsn['dosen'], $skripsiA);
        $this->buatDosenPA($dsn['dosen'], $skripsiB);

        $file = $this->buatFileMock();

        $response = $this->actingAs($mhs['user'], 'sanctum')
            ->postJson('/api/mahasiswa/pengajuan-judul', [
                'judul'      => 'Judul Ambigu',
                'deskripsi'  => 'Deskripsi',
                'file_judul' => $file,
            ]);

        $response->assertStatus(422);
        $response->assertJsonFragment([
            'status'  => 'error',
            'message' => 'Ditemukan lebih dari satu data skripsi aktif untuk akun Anda. Harap hubungi Administrator untuk resolusi data.',
        ]);
    }

    /**
     * 5. Pengajuan aktif duplikat -> ditolak (422).
     */
    public function test_5_pengajuan_aktif_duplikat_ditolak(): void
    {
        $mhs = $this->buatMahasiswa('2010110005', 'Informatika');
        $dsn = $this->buatDosen('0011223305');
        $skripsi = $this->buatSkripsi($mhs['mahasiswa']);
        $this->buatDosenPA($dsn['dosen'], $skripsi);

        // Sudah ada pengajuan aktif yang belum selesai
        PengajuanJudul::create([
            'skripsi_id'   => $skripsi->id_skripsi,
            'mahasiswa_id' => $mhs['mahasiswa']->id_mahasiswa,
            'dosen_pa_id'  => $dsn['user']->id,
            'judul'        => 'Pengajuan Aktif Pertama',
            'deskripsi'    => 'Deskripsi',
            'nama_file'    => 'f.pdf',
            'ukuran_file'  => '100 KB',
            'file_path'    => 'pengajuan-judul/f.pdf',
            'status'       => 'Menunggu Verifikasi Dosen PA',
        ]);

        $file = $this->buatFileMock();

        $response = $this->actingAs($mhs['user'], 'sanctum')
            ->postJson('/api/mahasiswa/pengajuan-judul', [
                'judul'      => 'Pengajuan Kedua',
                'deskripsi'  => 'Deskripsi 2',
                'file_judul' => $file,
            ]);

        $response->assertStatus(422);
        $response->assertJsonFragment([
            'status'  => 'error',
            'message' => 'Masih terdapat pengajuan judul yang aktif dan sedang dalam proses. Harap selesaikan pengajuan tersebut terlebih dahulu.',
        ]);
    }

    // -----------------------------------------------------------------------
    // 2. KEPUTUSAN DOSEN PA
    // -----------------------------------------------------------------------

    /**
     * 6. Dosen PA berwenang dapat menyetujui dan status masuk antrean Kaprodi.
     */
    public function test_6_dosen_pa_berwenang_dapat_menyetujui_dan_masuk_antrean_kaprodi(): void
    {
        $mhs = $this->buatMahasiswa('2010110006', 'Informatika');
        $dsn = $this->buatDosen('0011223306');
        $skripsi = $this->buatSkripsi($mhs['mahasiswa']);
        $this->buatDosenPA($dsn['dosen'], $skripsi);

        $pengajuan = PengajuanJudul::create([
            'skripsi_id'   => $skripsi->id_skripsi,
            'mahasiswa_id' => $mhs['mahasiswa']->id_mahasiswa,
            'dosen_pa_id'  => $dsn['user']->id,
            'judul'        => 'Pengajuan Untuk PA',
            'deskripsi'    => 'Deskripsi',
            'nama_file'    => 'f.pdf',
            'ukuran_file'  => '100 KB',
            'file_path'    => 'pengajuan-judul/f.pdf',
            'status'       => 'Menunggu Verifikasi Dosen PA',
        ]);

        $response = $this->actingAs($dsn['user'], 'sanctum')
            ->patchJson("/api/dosen/pengajuan-judul/{$pengajuan->id}/verifikasi", [
                'status'        => 'Menunggu Persetujuan Kaprodi',
                'catatan_dosen' => 'Disetujui untuk diteruskan ke Kaprodi.',
            ]);

        $response->assertStatus(200);
        $this->assertDatabaseHas('pengajuan_judul', [
            'id'     => $pengajuan->id,
            'status' => 'Menunggu Persetujuan Kaprodi',
        ]);

        $this->assertDatabaseHas('pengajuan_judul_logs', [
            'pengajuan_judul_id' => $pengajuan->id,
            'user_id'            => $dsn['user']->id,
            'tahap'              => 'verifikasi_dosen_pa',
            'tindakan'           => 'setujui_dosen_pa',
            'status_sebelum'     => 'Menunggu Verifikasi Dosen PA',
            'status_sesudah'     => 'Menunggu Persetujuan Kaprodi',
        ]);
    }

    /**
     * 7. Dosen PA tidak berwenang tidak dapat memproses pengajuan tersebut (404).
     */
    public function test_7_dosen_pa_tidak_berwenang_tidak_dapat_memproses_pengajuan(): void
    {
        $mhs = $this->buatMahasiswa('2010110007', 'Informatika');
        $dsn1 = $this->buatDosen('0011223307');
        $dsn2 = $this->buatDosen('0011223399'); // Dosen lain
        $skripsi = $this->buatSkripsi($mhs['mahasiswa']);
        $this->buatDosenPA($dsn1['dosen'], $skripsi);

        $pengajuan = PengajuanJudul::create([
            'skripsi_id'   => $skripsi->id_skripsi,
            'mahasiswa_id' => $mhs['mahasiswa']->id_mahasiswa,
            'dosen_pa_id'  => $dsn1['user']->id,
            'judul'        => 'Pengajuan Dosen 1',
            'deskripsi'    => 'Deskripsi',
            'nama_file'    => 'f.pdf',
            'ukuran_file'  => '100 KB',
            'file_path'    => 'pengajuan-judul/f.pdf',
            'status'       => 'Menunggu Verifikasi Dosen PA',
        ]);

        $response = $this->actingAs($dsn2['user'], 'sanctum')
            ->patchJson("/api/dosen/pengajuan-judul/{$pengajuan->id}/verifikasi", [
                'status' => 'Menunggu Persetujuan Kaprodi',
            ]);

        $response->assertStatus(404);
        $this->assertDatabaseHas('pengajuan_judul', [
            'id'     => $pengajuan->id,
            'status' => 'Menunggu Verifikasi Dosen PA',
        ]);
    }

    /**
     * 8. Keputusan Dosen PA tidak mengubah judul final skripsi.
     */
    public function test_8_keputusan_dosen_pa_tidak_mengubah_judul_final_skripsi(): void
    {
        $mhs = $this->buatMahasiswa('2010110008', 'Informatika');
        $dsn = $this->buatDosen('0011223308');
        $skripsi = $this->buatSkripsi($mhs['mahasiswa'], 'Judul Lama Skripsi', 'diajukan');
        $this->buatDosenPA($dsn['dosen'], $skripsi);

        $pengajuan = PengajuanJudul::create([
            'skripsi_id'   => $skripsi->id_skripsi,
            'mahasiswa_id' => $mhs['mahasiswa']->id_mahasiswa,
            'dosen_pa_id'  => $dsn['user']->id,
            'judul'        => 'Judul Usulan Baru',
            'deskripsi'    => 'Deskripsi',
            'nama_file'    => 'f.pdf',
            'ukuran_file'  => '100 KB',
            'file_path'    => 'pengajuan-judul/f.pdf',
            'status'       => 'Menunggu Verifikasi Dosen PA',
        ]);

        $this->actingAs($dsn['user'], 'sanctum')
            ->patchJson("/api/dosen/pengajuan-judul/{$pengajuan->id}/verifikasi", [
                'status' => 'Menunggu Persetujuan Kaprodi',
            ])
            ->assertStatus(200);

        // Skripsi judul tetap 'Judul Lama Skripsi' dan status_judul tetap 'diajukan'
        $this->assertDatabaseHas('skripsi', [
            'id_skripsi'   => $skripsi->id_skripsi,
            'judul'        => 'Judul Lama Skripsi',
            'status_judul' => 'diajukan',
        ]);
    }

    // -----------------------------------------------------------------------
    // 3. ANTREAN & KEPUTUSAN KAPRODI
    // -----------------------------------------------------------------------

    /**
     * 9. Kaprodi dengan penugasan aktif dan prodi cocok dapat melihat antrean yang sesuai.
     */
    public function test_9_kaprodi_dengan_penugasan_aktif_dan_prodi_cocok_dapat_melihat_antrean(): void
    {
        $dsnKaprodi = $this->buatDosen('0011223309');
        $this->buatPenugasanKaprodi($dsnKaprodi['dosen'], 'Sains Pertanian', true);

        // Pengajuan prodi Sains Pertanian (harus terlihat)
        $mhsSp = $this->buatMahasiswa('2010110009', 'Sains Pertanian');
        $skripsiSp = $this->buatSkripsi($mhsSp['mahasiswa']);
        PengajuanJudul::create([
            'skripsi_id'   => $skripsiSp->id_skripsi,
            'mahasiswa_id' => $mhsSp['mahasiswa']->id_mahasiswa,
            'dosen_pa_id'  => $dsnKaprodi['user']->id,
            'judul'        => 'Pengajuan Sains Pertanian',
            'deskripsi'    => 'D',
            'nama_file'    => 'f.pdf',
            'ukuran_file'  => '100 KB',
            'file_path'    => 'p/f.pdf',
            'status'       => 'Menunggu Persetujuan Kaprodi',
        ]);

        // Pengajuan prodi Pendidikan Fisika (TIDAK boleh terlihat)
        $mhsFis = $this->buatMahasiswa('2010110010', 'Pendidikan Fisika');
        $skripsiFis = $this->buatSkripsi($mhsFis['mahasiswa']);
        PengajuanJudul::create([
            'skripsi_id'   => $skripsiFis->id_skripsi,
            'mahasiswa_id' => $mhsFis['mahasiswa']->id_mahasiswa,
            'dosen_pa_id'  => $dsnKaprodi['user']->id,
            'judul'        => 'Pengajuan Fisika',
            'deskripsi'    => 'D',
            'nama_file'    => 'f.pdf',
            'ukuran_file'  => '100 KB',
            'file_path'    => 'p/f.pdf',
            'status'       => 'Menunggu Persetujuan Kaprodi',
        ]);

        $response = $this->actingAs($dsnKaprodi['user'], 'sanctum')
            ->getJson('/api/kaprodi/pengajuan-judul');

        $response->assertStatus(200);
        $response->assertJsonFragment(['judul' => 'Pengajuan Sains Pertanian']);
        $response->assertJsonMissing(['judul' => 'Pengajuan Fisika']);
    }

    /**
     * 10. Kaprodi tanpa penugasan, penugasan nonaktif, atau kedaluwarsa ditolak (403).
     */
    public function test_10_kaprodi_tanpa_penugasan_atau_penugasan_tidak_aktif_ditolak(): void
    {
        // 1. Dosen tanpa penugasan Kaprodi
        $dsnPolos = $this->buatDosen('0011223311');
        $this->actingAs($dsnPolos['user'], 'sanctum')
            ->getJson('/api/kaprodi/pengajuan-judul')
            ->assertStatus(403);

        // 2. Dosen dengan penugasan tidak aktif (is_aktif = false)
        $dsnNonaktif = $this->buatDosen('0011223312');
        $this->buatPenugasanKaprodi($dsnNonaktif['dosen'], 'Sains Pertanian', false);
        $this->actingAs($dsnNonaktif['user'], 'sanctum')
            ->getJson('/api/kaprodi/pengajuan-judul')
            ->assertStatus(403);

        // 3. Dosen dengan penugasan kedaluwarsa (tanggal_selesai di masa lalu)
        $dsnExpired = $this->buatDosen('0011223313');
        $this->buatPenugasanKaprodi(
            $dsnExpired['dosen'],
            'Sains Pertanian',
            true,
            now()->subMonths(6)->toDateString(),
            now()->subMonth()->toDateString()
        );
        $this->actingAs($dsnExpired['user'], 'sanctum')
            ->getJson('/api/kaprodi/pengajuan-judul')
            ->assertStatus(403);
    }

    /**
     * 11. Kaprodi tidak dapat melihat atau memproses pengajuan prodi lain (404/403).
     */
    public function test_11_kaprodi_tidak_dapat_melihat_atau_memproses_pengajuan_prodi_lain(): void
    {
        $dsnKaprodiPti = $this->buatDosen('0011223314');
        $this->buatPenugasanKaprodi($dsnKaprodiPti['dosen'], 'Pendidikan Teknologi Informasi', true);

        // Pengajuan milik mahasiswa Informatika
        $mhsInf = $this->buatMahasiswa('2010110014', 'Informatika');
        $skripsiInf = $this->buatSkripsi($mhsInf['mahasiswa']);
        $pengajuanInf = PengajuanJudul::create([
            'skripsi_id'   => $skripsiInf->id_skripsi,
            'mahasiswa_id' => $mhsInf['mahasiswa']->id_mahasiswa,
            'dosen_pa_id'  => $dsnKaprodiPti['user']->id,
            'judul'        => 'Judul Informatika Khusus',
            'deskripsi'    => 'D',
            'nama_file'    => 'f.pdf',
            'ukuran_file'  => '100 KB',
            'file_path'    => 'p/f.pdf',
            'status'       => 'Menunggu Persetujuan Kaprodi',
        ]);

        // Kaprodi PTI mencoba melihat detail
        $this->actingAs($dsnKaprodiPti['user'], 'sanctum')
            ->getJson("/api/kaprodi/pengajuan-judul/{$pengajuanInf->id}")
            ->assertStatus(404);

        // Kaprodi PTI mencoba menyetujui
        $this->actingAs($dsnKaprodiPti['user'], 'sanctum')
            ->patchJson("/api/kaprodi/pengajuan-judul/{$pengajuanInf->id}/persetujuan", [
                'status' => 'Disetujui Kaprodi',
            ])
            ->assertStatus(404);
    }

    /**
     * 12. Kaprodi tidak dapat menyetujui pengajuan yang belum disetujui Dosen PA (422).
     */
    public function test_12_kaprodi_tidak_dapat_menyetujui_pengajuan_yang_belum_disetujui_dosen_pa(): void
    {
        $dsnKaprodi = $this->buatDosen('0011223315');
        $this->buatPenugasanKaprodi($dsnKaprodi['dosen'], 'Sains Pertanian', true);

        $mhs = $this->buatMahasiswa('2010110015', 'Sains Pertanian');
        $skripsi = $this->buatSkripsi($mhs['mahasiswa']);

        $pengajuan = PengajuanJudul::create([
            'skripsi_id'   => $skripsi->id_skripsi,
            'mahasiswa_id' => $mhs['mahasiswa']->id_mahasiswa,
            'dosen_pa_id'  => $dsnKaprodi['user']->id,
            'judul'        => 'Pengajuan Baru Belum Diproses PA',
            'deskripsi'    => 'D',
            'nama_file'    => 'f.pdf',
            'ukuran_file'  => '100 KB',
            'file_path'    => 'p/f.pdf',
            'status'       => 'Menunggu Verifikasi Dosen PA', // Belum diverifikasi PA!
        ]);

        $response = $this->actingAs($dsnKaprodi['user'], 'sanctum')
            ->patchJson("/api/kaprodi/pengajuan-judul/{$pengajuan->id}/persetujuan", [
                'status' => 'Disetujui Kaprodi',
            ]);

        $response->assertStatus(422);
        $response->assertJsonFragment([
            'status'  => 'error',
            'message' => 'Pengajuan belum diverifikasi oleh Dosen PA.',
        ]);
    }

    /**
     * 13. Persetujuan Kaprodi memperbarui status pengajuan dan judul skripsi secara atomik.
     */
    public function test_13_persetujuan_kaprodi_memperbarui_status_pengajuan_dan_judul_skripsi(): void
    {
        $dsnKaprodi = $this->buatDosen('0011223316');
        $this->buatPenugasanKaprodi($dsnKaprodi['dosen'], 'Sains Pertanian', true);

        $mhs = $this->buatMahasiswa('2010110016', 'Sains Pertanian');
        $skripsi = $this->buatSkripsi($mhs['mahasiswa'], 'Menunggu Judul Disetujui', 'diajukan');

        $pengajuan = PengajuanJudul::create([
            'skripsi_id'   => $skripsi->id_skripsi,
            'mahasiswa_id' => $mhs['mahasiswa']->id_mahasiswa,
            'dosen_pa_id'  => $dsnKaprodi['user']->id,
            'judul'        => 'Implementasi Arsitektur Microservices pada SI Skripsi',
            'deskripsi'    => 'Deskripsi lengkap',
            'nama_file'    => 'f.pdf',
            'ukuran_file'  => '100 KB',
            'file_path'    => 'p/f.pdf',
            'status'       => 'Menunggu Persetujuan Kaprodi',
        ]);

        $response = $this->actingAs($dsnKaprodi['user'], 'sanctum')
            ->patchJson("/api/kaprodi/pengajuan-judul/{$pengajuan->id}/persetujuan", [
                'status'          => 'Disetujui Kaprodi',
                'catatan_kaprodi' => 'Judul sangat layak dan disetujui program studi.',
            ]);

        $response->assertStatus(200);

        // 1. Pengajuan berubah status
        $this->assertDatabaseHas('pengajuan_judul', [
            'id'     => $pengajuan->id,
            'status' => 'Disetujui Kaprodi',
        ]);

        // 2. Skripsi resmi diperbarui secara atomik
        $this->assertDatabaseHas('skripsi', [
            'id_skripsi'   => $skripsi->id_skripsi,
            'judul'        => 'Implementasi Arsitektur Microservices pada SI Skripsi',
            'status_judul' => 'disetujui',
        ]);

        // 3. Log audit tersimpan
        $this->assertDatabaseHas('pengajuan_judul_logs', [
            'pengajuan_judul_id' => $pengajuan->id,
            'user_id'            => $dsnKaprodi['user']->id,
            'aktor_role'         => 'kaprodi',
            'tahap'              => 'persetujuan_kaprodi',
            'tindakan'           => 'setujui_kaprodi',
            'status_sebelum'     => 'Menunggu Persetujuan Kaprodi',
            'status_sesudah'     => 'Disetujui Kaprodi',
        ]);
    }

    /**
     * 14. Revisi Kaprodi kembali melalui Dosen PA.
     */
    public function test_14_revisi_kaprodi_kembali_melalui_dosen_pa(): void
    {
        $dsnKaprodi = $this->buatDosen('0011223317');
        $this->buatPenugasanKaprodi($dsnKaprodi['dosen'], 'Sains Pertanian', true);

        $mhs = $this->buatMahasiswa('2010110017', 'Sains Pertanian');
        $skripsi = $this->buatSkripsi($mhs['mahasiswa']);

        $pengajuan = PengajuanJudul::create([
            'skripsi_id'   => $skripsi->id_skripsi,
            'mahasiswa_id' => $mhs['mahasiswa']->id_mahasiswa,
            'dosen_pa_id'  => $dsnKaprodi['user']->id,
            'judul'        => 'Judul Perlu Diperbaiki',
            'deskripsi'    => 'Deskripsi',
            'nama_file'    => 'f.pdf',
            'ukuran_file'  => '100 KB',
            'file_path'    => 'p/f.pdf',
            'status'       => 'Menunggu Persetujuan Kaprodi',
        ]);

        // 1. Kaprodi meminta revisi
        $this->actingAs($dsnKaprodi['user'], 'sanctum')
            ->patchJson("/api/kaprodi/pengajuan-judul/{$pengajuan->id}/persetujuan", [
                'status'          => 'Revisi Kaprodi',
                'catatan_kaprodi' => 'Perbaiki ruang lingkup penelitian.',
            ])
            ->assertStatus(200);

        $this->assertDatabaseHas('pengajuan_judul', [
            'id'     => $pengajuan->id,
            'status' => 'Revisi Kaprodi',
        ]);

        // 2. Mahasiswa mengirimkan perbaikan revisi
        $newFile = $this->buatFileMock('revisi_proposal.pdf');
        $resRevisi = $this->actingAs($mhs['user'], 'sanctum')
            ->postJson("/api/mahasiswa/pengajuan-judul/{$pengajuan->id}/revisi", [
                'judul'             => 'Judul Sudah Diperbaiki Sesuai Arahan Kaprodi',
                'deskripsi'         => 'Deskripsi yang disesuaikan',
                'file_judul'        => $newFile,
                'catatan_mahasiswa' => 'Sudah diperbaiki pada bab 1.',
            ]);

        $resRevisi->assertStatus(200);

        // Status WAJIB kembali ke 'Menunggu Verifikasi Dosen PA' (bukan langsung Kaprodi)
        $this->assertDatabaseHas('pengajuan_judul', [
            'id'     => $pengajuan->id,
            'status' => 'Menunggu Verifikasi Dosen PA',
            'judul'  => 'Judul Sudah Diperbaiki Sesuai Arahan Kaprodi',
        ]);

        // 3. Kaprodi belum bisa menyetujui langsung sebelum Dosen PA memverifikasi ulang
        $this->actingAs($dsnKaprodi['user'], 'sanctum')
            ->patchJson("/api/kaprodi/pengajuan-judul/{$pengajuan->id}/persetujuan", [
                'status' => 'Disetujui Kaprodi',
            ])
            ->assertStatus(422);
    }

    /**
     * 15. Penolakan Kaprodi tidak memperbarui judul final skripsi.
     */
    public function test_15_penolakan_kaprodi_tidak_memperbarui_judul_final_skripsi(): void
    {
        $dsnKaprodi = $this->buatDosen('0011223318');
        $this->buatPenugasanKaprodi($dsnKaprodi['dosen'], 'Sains Pertanian', true);

        $mhs = $this->buatMahasiswa('2010110018', 'Sains Pertanian');
        $skripsi = $this->buatSkripsi($mhs['mahasiswa'], 'Judul Default Skripsi', 'diajukan');

        $pengajuan = PengajuanJudul::create([
            'skripsi_id'   => $skripsi->id_skripsi,
            'mahasiswa_id' => $mhs['mahasiswa']->id_mahasiswa,
            'dosen_pa_id'  => $dsnKaprodi['user']->id,
            'judul'        => 'Judul Yang Ditolak Kaprodi',
            'deskripsi'    => 'D',
            'nama_file'    => 'f.pdf',
            'ukuran_file'  => '100 KB',
            'file_path'    => 'p/f.pdf',
            'status'       => 'Menunggu Persetujuan Kaprodi',
        ]);

        $response = $this->actingAs($dsnKaprodi['user'], 'sanctum')
            ->patchJson("/api/kaprodi/pengajuan-judul/{$pengajuan->id}/persetujuan", [
                'status'          => 'Ditolak Kaprodi',
                'catatan_kaprodi' => 'Topik penelitian sudah terlalu sering digunakan.',
            ]);

        $response->assertStatus(200);

        $this->assertDatabaseHas('pengajuan_judul', [
            'id'     => $pengajuan->id,
            'status' => 'Ditolak Kaprodi',
        ]);

        // Skripsi tetap judul lama
        $this->assertDatabaseHas('skripsi', [
            'id_skripsi'   => $skripsi->id_skripsi,
            'judul'        => 'Judul Default Skripsi',
            'status_judul' => 'diajukan',
        ]);
    }

    // -----------------------------------------------------------------------
    // 4. ATOMISITAS TRANSAKSI & AUDIT LOG
    // -----------------------------------------------------------------------

    /**
     * 16. Kegagalan audit log membatalkan seluruh perubahan bisnis (Rollback).
     */
    public function test_16_kegagalan_audit_log_membatalkan_perubahan_bisnis(): void
    {
        $dsnKaprodi = $this->buatDosen('0011223319');
        $this->buatPenugasanKaprodi($dsnKaprodi['dosen'], 'Sains Pertanian', true);

        $mhs = $this->buatMahasiswa('2010110019', 'Sains Pertanian');
        $skripsi = $this->buatSkripsi($mhs['mahasiswa'], 'Judul Lama', 'diajukan');

        $pengajuan = PengajuanJudul::create([
            'skripsi_id'   => $skripsi->id_skripsi,
            'mahasiswa_id' => $mhs['mahasiswa']->id_mahasiswa,
            'dosen_pa_id'  => $dsnKaprodi['user']->id,
            'judul'        => 'Judul Transaksi Uji',
            'deskripsi'    => 'D',
            'nama_file'    => 'f.pdf',
            'ukuran_file'  => '100 KB',
            'file_path'    => 'p/f.pdf',
            'status'       => 'Menunggu Persetujuan Kaprodi',
        ]);

        // Simulasikan kegagalan pada saat penulisan log dengan hook event creating
        PengajuanJudulLog::creating(function () {
            throw new \RuntimeException("SIMULASI ERROR AUDIT LOG DATABASE");
        });

        try {
            $response = $this->actingAs($dsnKaprodi['user'], 'sanctum')
                ->patchJson("/api/kaprodi/pengajuan-judul/{$pengajuan->id}/persetujuan", [
                    'status' => 'Disetujui Kaprodi',
                ]);

            $response->assertStatus(500);
            $this->assertStringNotContainsString('SIMULASI ERROR AUDIT LOG DATABASE', $response->getContent());
            $response->assertJsonFragment([
                'status'  => 'error',
                'message' => 'Terjadi kesalahan pada server saat memproses persetujuan Kaprodi. Silakan coba lagi.',
            ]);
        } finally {
            // Bersihkan callback event
            PengajuanJudulLog::flushEventListeners();
        }

        // Status di database TIDAK boleh berubah (tetap Menunggu Persetujuan Kaprodi)
        $this->assertDatabaseHas('pengajuan_judul', [
            'id'     => $pengajuan->id,
            'status' => 'Menunggu Persetujuan Kaprodi',
        ]);

        // Skripsi TIDAK boleh terupdate
        $this->assertDatabaseHas('skripsi', [
            'id_skripsi'   => $skripsi->id_skripsi,
            'judul'        => 'Judul Lama',
            'status_judul' => 'diajukan',
        ]);
    }

    /**
     * 17. Status sebelum/sesudah dan snapshot aktor tersimpan benar.
     */
    public function test_17_status_sebelum_sesudah_dan_snapshot_aktor_tersimpan_benar(): void
    {
        $dsnKaprodi = $this->buatDosen('0011223320');
        $this->buatPenugasanKaprodi($dsnKaprodi['dosen'], 'Sains Pertanian', true);

        $mhs = $this->buatMahasiswa('2010110020', 'Sains Pertanian');
        $skripsi = $this->buatSkripsi($mhs['mahasiswa']);

        $pengajuan = PengajuanJudul::create([
            'skripsi_id'   => $skripsi->id_skripsi,
            'mahasiswa_id' => $mhs['mahasiswa']->id_mahasiswa,
            'dosen_pa_id'  => $dsnKaprodi['user']->id,
            'judul'        => 'Snapshot Test Judul',
            'deskripsi'    => 'Deskripsi Snapshot',
            'nama_file'    => 'f.pdf',
            'ukuran_file'  => '100 KB',
            'file_path'    => 'pengajuan-judul/f.pdf',
            'status'       => 'Menunggu Persetujuan Kaprodi',
        ]);

        $this->actingAs($dsnKaprodi['user'], 'sanctum')
            ->patchJson("/api/kaprodi/pengajuan-judul/{$pengajuan->id}/persetujuan", [
                'status'          => 'Disetujui Kaprodi',
                'catatan_kaprodi' => 'Catatan lengkap snapshot.',
            ])
            ->assertStatus(200);

        $this->assertDatabaseHas('pengajuan_judul_logs', [
            'pengajuan_judul_id' => $pengajuan->id,
            'user_id'            => $dsnKaprodi['user']->id,
            'aktor_nama'         => $dsnKaprodi['user']->name,
            'aktor_role'         => 'kaprodi',
            'aktor_nomor_induk'  => $dsnKaprodi['dosen']->NIDN,
            'status_sebelum'     => 'Menunggu Persetujuan Kaprodi',
            'status_sesudah'     => 'Disetujui Kaprodi',
            'catatan'            => 'Catatan lengkap snapshot.',
            'snapshot_judul'     => 'Snapshot Test Judul',
        ]);
    }

    // -----------------------------------------------------------------------
    // 5. KEAMANAN & OTORISASI KHUSUS
    // -----------------------------------------------------------------------

    /**
     * 18. Akses endpoint tanpa autentikasi ditolak (401).
     */
    public function test_18_akses_endpoint_tanpa_autentikasi_ditolak(): void
    {
        $this->getJson('/api/kaprodi/pengajuan-judul')->assertStatus(401);
        $this->getJson('/api/kaprodi/pengajuan-judul/1')->assertStatus(401);
        $this->patchJson('/api/kaprodi/pengajuan-judul/1/persetujuan', [])->assertStatus(401);
        $this->postJson('/api/mahasiswa/pengajuan-judul/1/revisi', [])->assertStatus(401);
    }

    /**
     * 19. PA dan Kaprodi yang sama tetap mengikuti dua tahap keputusan.
     */
    public function test_19_pa_dan_kaprodi_yang_sama_tetap_mengikuti_dua_tahap_keputusan(): void
    {
        // Dosen Rangkap: Menjadi PA sekaligus Kaprodi Sains Pertanian
        $dsnRangkap = $this->buatDosen('0011223321');
        $this->buatPenugasanKaprodi($dsnRangkap['dosen'], 'Sains Pertanian', true);

        $mhs = $this->buatMahasiswa('2010110021', 'Sains Pertanian');
        $skripsi = $this->buatSkripsi($mhs['mahasiswa']);
        $this->buatDosenPA($dsnRangkap['dosen'], $skripsi);

        $pengajuan = PengajuanJudul::create([
            'skripsi_id'   => $skripsi->id_skripsi,
            'mahasiswa_id' => $mhs['mahasiswa']->id_mahasiswa,
            'dosen_pa_id'  => $dsnRangkap['user']->id,
            'judul'        => 'Judul Dual Role',
            'deskripsi'    => 'D',
            'nama_file'    => 'f.pdf',
            'ukuran_file'  => '100 KB',
            'file_path'    => 'p/f.pdf',
            'status'       => 'Menunggu Verifikasi Dosen PA',
        ]);

        // Tahap 1: Mencoba langsung menyetujui sebagai Kaprodi -> HARUS DITOLAK (422)
        $this->actingAs($dsnRangkap['user'], 'sanctum')
            ->patchJson("/api/kaprodi/pengajuan-judul/{$pengajuan->id}/persetujuan", [
                'status' => 'Disetujui Kaprodi',
            ])
            ->assertStatus(422);

        // Tahap 2: Dosen memverifikasi sebagai Dosen PA terlebih dahulu
        $this->actingAs($dsnRangkap['user'], 'sanctum')
            ->patchJson("/api/dosen/pengajuan-judul/{$pengajuan->id}/verifikasi", [
                'status'        => 'Menunggu Persetujuan Kaprodi',
                'catatan_dosen' => 'Verifikasi PA OK.',
            ])
            ->assertStatus(200);

        // Tahap 3: Baru kemudian Dosen menyetujui sebagai Kaprodi
        $this->actingAs($dsnRangkap['user'], 'sanctum')
            ->patchJson("/api/kaprodi/pengajuan-judul/{$pengajuan->id}/persetujuan", [
                'status'          => 'Disetujui Kaprodi',
                'catatan_kaprodi' => 'Persetujuan Kaprodi Final OK.',
            ])
            ->assertStatus(200);

        // Menghasilkan TEPAT 2 entri log audit dengan peran & tahap yang berbeda
        $this->assertDatabaseHas('pengajuan_judul_logs', [
            'pengajuan_judul_id' => $pengajuan->id,
            'aktor_role'         => 'dosen_pa',
            'tahap'              => 'verifikasi_dosen_pa',
            'tindakan'           => 'setujui_dosen_pa',
        ]);

        $this->assertDatabaseHas('pengajuan_judul_logs', [
            'pengajuan_judul_id' => $pengajuan->id,
            'aktor_role'         => 'kaprodi',
            'tahap'              => 'persetujuan_kaprodi',
            'tindakan'           => 'setujui_kaprodi',
        ]);
    }

    /**
     * 20. Permintaan dengan status usang (stale status) tidak menghasilkan keputusan ganda.
     */
    public function test_20_status_usang_tidak_menghasilkan_keputusan_ganda(): void
    {
        $dsnKaprodi = $this->buatDosen('0011223322');
        $this->buatPenugasanKaprodi($dsnKaprodi['dosen'], 'Sains Pertanian', true);

        $mhs = $this->buatMahasiswa('2010110022', 'Sains Pertanian');
        $skripsi = $this->buatSkripsi($mhs['mahasiswa']);

        $pengajuan = PengajuanJudul::create([
            'skripsi_id'   => $skripsi->id_skripsi,
            'mahasiswa_id' => $mhs['mahasiswa']->id_mahasiswa,
            'dosen_pa_id'  => $dsnKaprodi['user']->id,
            'judul'        => 'Pengajuan Sudah Selesai',
            'deskripsi'    => 'D',
            'nama_file'    => 'f.pdf',
            'ukuran_file'  => '100 KB',
            'file_path'    => 'p/f.pdf',
            'status'       => 'Disetujui Kaprodi', // Sudah status final!
        ]);

        // Dosen PA mencoba verifikasi pengajuan yang sudah final
        $this->actingAs($dsnKaprodi['user'], 'sanctum')
            ->patchJson("/api/dosen/pengajuan-judul/{$pengajuan->id}/verifikasi", [
                'status' => 'Revisi Dosen PA',
                'catatan_dosen' => 'Revisi ulang',
            ])
            ->assertStatus(422);

        // Kaprodi mencoba menyetujui kembali pengajuan yang sudah disetujui
        $this->actingAs($dsnKaprodi['user'], 'sanctum')
            ->patchJson("/api/kaprodi/pengajuan-judul/{$pengajuan->id}/persetujuan", [
                'status' => 'Disetujui Kaprodi',
            ])
            ->assertStatus(422);
    }

    /**
     * 21. Data historis dengan skripsi_id = NULL tetap dapat dibaca tanpa salah relasi.
     */
    public function test_21_data_historis_dengan_skripsi_id_null_tetap_dapat_dibaca_tanpa_salah_relasi(): void
    {
        $mhs = $this->buatMahasiswa('2010110023', 'Informatika');
        $dsn = $this->buatDosen('0011223323');

        // Simulasi record pengajuan lama dengan skripsi_id = NULL
        $pengajuanLama = PengajuanJudul::create([
            'skripsi_id'   => null,
            'mahasiswa_id' => $mhs['mahasiswa']->id_mahasiswa,
            'dosen_pa_id'  => $dsn['user']->id,
            'judul'        => 'Data Pengajuan Legacy',
            'deskripsi'    => 'Pengajuan lama sebelum sistem memiliki skripsi_id',
            'nama_file'    => 'legacy.pdf',
            'ukuran_file'  => '100 KB',
            'file_path'    => 'pengajuan-judul/legacy.pdf',
            'status'       => 'Disetujui Dosen PA',
        ]);

        // Pembacaan via riwayat mahasiswa tidak crash
        $resMhs = $this->actingAs($mhs['user'], 'sanctum')
            ->getJson('/api/mahasiswa/pengajuan-judul');

        $resMhs->assertStatus(200);
        $resMhs->assertJsonFragment(['judul' => 'Data Pengajuan Legacy']);

        // Verifikasi relasi skripsi pada model menghasilkan null secara aman
        $this->assertNull($pengajuanLama->skripsi);
    }

    /**
     * 22. Semua operasi yang gagal tidak meninggalkan perubahan parsial.
     */
    public function test_22_semua_operasi_yang_gagal_tidak_meninggalkan_perubahan_parsial(): void
    {
        $mhs = $this->buatMahasiswa('2010110024', 'Informatika');

        // Percobaan kirim pengajuan tanpa field wajib 'judul'
        $response = $this->actingAs($mhs['user'], 'sanctum')
            ->postJson('/api/mahasiswa/pengajuan-judul', [
                'deskripsi'  => 'Hanya deskripsi',
                'file_judul' => $this->buatFileMock(),
            ]);

        $response->assertStatus(422);

        // Tidak ada baris pengajuan maupun log yang tersimpan
        $this->assertDatabaseMissing('pengajuan_judul', [
            'mahasiswa_id' => $mhs['mahasiswa']->id_mahasiswa,
        ]);
        $this->assertDatabaseMissing('pengajuan_judul_logs', [
            'user_id' => $mhs['user']->id,
        ]);
    }

    /**
     * 23. Pengajuan legacy (skripsi_id = NULL) dengan tepat satu skripsi mahasiswa dapat disetujui Kaprodi dan memulihkan relasi skripsi_id.
     */
    public function test_23_persetujuan_kaprodi_pada_pengajuan_legacy_null_skripsi_id_dapat_disetujui_dan_memulihkan_relasi(): void
    {
        $mhs = $this->buatMahasiswa('2010110025', 'Sains Pertanian');
        $dsnKaprodi = $this->buatDosen('0011223325');
        $skripsi = $this->buatSkripsi($mhs['mahasiswa'], 'Menunggu Pengajuan Judul', 'Belum Mengajukan');
        $this->buatPenugasanKaprodi($dsnKaprodi['dosen'], 'Sains Pertanian', true);

        // Simulasi pengajuan legacy: skripsi_id = null
        $pengajuan = PengajuanJudul::create([
            'skripsi_id'   => null,
            'mahasiswa_id' => $mhs['mahasiswa']->id_mahasiswa,
            'dosen_pa_id'  => $dsnKaprodi['user']->id,
            'judul'        => 'Pengajuan Legacy Valid',
            'deskripsi'    => 'Deskripsi usulan',
            'nama_file'    => 'legacy.pdf',
            'ukuran_file'  => '100 KB',
            'file_path'    => 'pengajuan-judul/legacy.pdf',
            'status'       => 'Menunggu Persetujuan Kaprodi',
        ]);

        $response = $this->actingAs($dsnKaprodi['user'], 'sanctum')
            ->patchJson("/api/kaprodi/pengajuan-judul/{$pengajuan->id}/persetujuan", [
                'status' => 'Disetujui Kaprodi',
            ]);

        $response->assertStatus(200);
        $response->assertJsonFragment(['status' => 'success']);

        // Pastikan relasi skripsi_id terpulihkan pada pengajuan
        $this->assertDatabaseHas('pengajuan_judul', [
            'id'         => $pengajuan->id,
            'skripsi_id' => $skripsi->id_skripsi,
            'status'     => 'Disetujui Kaprodi',
        ]);

        // Pastikan skripsi terupdate judul dan statusnya
        $this->assertDatabaseHas('skripsi', [
            'id_skripsi'   => $skripsi->id_skripsi,
            'judul'        => 'Pengajuan Legacy Valid',
            'status_judul' => 'disetujui',
        ]);

        // Pastikan log audit tercatat
        $this->assertDatabaseHas('pengajuan_judul_logs', [
            'pengajuan_judul_id' => $pengajuan->id,
            'tahap'              => 'persetujuan_kaprodi',
            'tindakan'           => 'setujui_kaprodi',
            'status_sesudah'     => 'Disetujui Kaprodi',
        ]);
    }

    /**
     * 24. Pengajuan legacy (skripsi_id = NULL) tanpa kandidat skripsi ditolak 422 secara aman.
     */
    public function test_24_persetujuan_kaprodi_pada_pengajuan_null_skripsi_id_tanpa_kandidat_skripsi_ditolak_422(): void
    {
        $mhs = $this->buatMahasiswa('2010110026', 'Sains Pertanian');
        $dsnKaprodi = $this->buatDosen('0011223326');
        $this->buatPenugasanKaprodi($dsnKaprodi['dosen'], 'Sains Pertanian', true);

        // Mahasiswa tidak memiliki skripsi sama sekali
        $pengajuan = PengajuanJudul::create([
            'skripsi_id'   => null,
            'mahasiswa_id' => $mhs['mahasiswa']->id_mahasiswa,
            'dosen_pa_id'  => $dsnKaprodi['user']->id,
            'judul'        => 'Pengajuan Tanpa Skripsi',
            'deskripsi'    => 'Deskripsi',
            'nama_file'    => 'f.pdf',
            'ukuran_file'  => '100 KB',
            'file_path'    => 'pengajuan-judul/f.pdf',
            'status'       => 'Menunggu Persetujuan Kaprodi',
        ]);

        $response = $this->actingAs($dsnKaprodi['user'], 'sanctum')
            ->patchJson("/api/kaprodi/pengajuan-judul/{$pengajuan->id}/persetujuan", [
                'status' => 'Disetujui Kaprodi',
            ]);

        $response->assertStatus(422);
        $response->assertJsonFragment([
            'status'  => 'error',
            'message' => 'Data skripsi induk tidak ditemukan untuk pengajuan ini. Hubungi Administrator.',
        ]);
    }

    /**
     * 25. Pengajuan legacy (skripsi_id = NULL) dengan kandidat skripsi ambigu (>1) ditolak 422 secara aman.
     */
    public function test_25_persetujuan_kaprodi_pada_pengajuan_null_skripsi_id_dengan_kandidat_ambigu_ditolak_422(): void
    {
        $mhs = $this->buatMahasiswa('2010110027', 'Sains Pertanian');
        $dsnKaprodi = $this->buatDosen('0011223327');
        $this->buatPenugasanKaprodi($dsnKaprodi['dosen'], 'Sains Pertanian', true);

        // Mahasiswa memiliki lebih dari 1 skripsi
        $this->buatSkripsi($mhs['mahasiswa'], 'Skripsi 1');
        $this->buatSkripsi($mhs['mahasiswa'], 'Skripsi 2');

        $pengajuan = PengajuanJudul::create([
            'skripsi_id'   => null,
            'mahasiswa_id' => $mhs['mahasiswa']->id_mahasiswa,
            'dosen_pa_id'  => $dsnKaprodi['user']->id,
            'judul'        => 'Pengajuan Skripsi Ambigu',
            'deskripsi'    => 'Deskripsi',
            'nama_file'    => 'f.pdf',
            'ukuran_file'  => '100 KB',
            'file_path'    => 'pengajuan-judul/f.pdf',
            'status'       => 'Menunggu Persetujuan Kaprodi',
        ]);

        $response = $this->actingAs($dsnKaprodi['user'], 'sanctum')
            ->patchJson("/api/kaprodi/pengajuan-judul/{$pengajuan->id}/persetujuan", [
                'status' => 'Disetujui Kaprodi',
            ]);

        $response->assertStatus(422);
        $response->assertJsonFragment([
            'status'  => 'error',
            'message' => 'Ditemukan lebih dari satu kandidat skripsi untuk akun mahasiswa pengaju. Hubungi Administrator untuk resolusi data.',
        ]);
    }

    /**
     * 26. Kegagalan tindakan Kaprodi pada pengajuan legacy tidak meninggalkan mutasi relasi parsial.
     */
    public function test_26_kegagalan_kaprodi_pada_pengajuan_legacy_tidak_meninggalkan_relasi_parsial(): void
    {
        $mhs = $this->buatMahasiswa('2010110028', 'Sains Pertanian');
        $dsnKaprodi = $this->buatDosen('0011223328');
        $skripsi = $this->buatSkripsi($mhs['mahasiswa'], 'Menunggu Judul', 'diajukan');
        $this->buatPenugasanKaprodi($dsnKaprodi['dosen'], 'Sains Pertanian', true);

        $pengajuan = PengajuanJudul::create([
            'skripsi_id'   => null,
            'mahasiswa_id' => $mhs['mahasiswa']->id_mahasiswa,
            'dosen_pa_id'  => $dsnKaprodi['user']->id,
            'judul'        => 'Pengajuan Gagal Validasi',
            'deskripsi'    => 'Deskripsi',
            'nama_file'    => 'f.pdf',
            'ukuran_file'  => '100 KB',
            'file_path'    => 'pengajuan-judul/f.pdf',
            'status'       => 'Menunggu Persetujuan Kaprodi',
        ]);

        // Kirim status 'Revisi Kaprodi' tanpa catatan_kaprodi yang wajib
        $response = $this->actingAs($dsnKaprodi['user'], 'sanctum')
            ->patchJson("/api/kaprodi/pengajuan-judul/{$pengajuan->id}/persetujuan", [
                'status'          => 'Revisi Kaprodi',
                'catatan_kaprodi' => '',
            ]);

        $response->assertStatus(422);

        // Verifikasi skripsi_id pada pengajuan tetap null (tidak ada partial commit)
        $this->assertDatabaseHas('pengajuan_judul', [
            'id'         => $pengajuan->id,
            'skripsi_id' => null,
            'status'     => 'Menunggu Persetujuan Kaprodi',
        ]);
        $this->assertDatabaseHas('skripsi', [
            'id_skripsi'   => $skripsi->id_skripsi,
            'status_judul' => 'diajukan',
        ]);
    }
}
