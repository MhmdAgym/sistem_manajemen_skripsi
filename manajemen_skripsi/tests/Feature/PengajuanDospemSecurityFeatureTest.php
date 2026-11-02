<?php

namespace Tests\Feature;

use App\Constants\AcademicConstant;
use App\Models\Admin;
use App\Models\Bimbingan;
use App\Models\Dosen;
use App\Models\DosenPembimbing;
use App\Models\Mahasiswa;
use App\Models\PengajuanDospem;
use App\Models\PengajuanDospemLog;
use App\Models\PengajuanJudul;
use App\Models\PengajuanJudulLog;
use App\Models\Skripsi;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class PengajuanDospemSecurityFeatureTest extends TestCase
{
    use DatabaseTransactions;

    // -----------------------------------------------------------------------
    // FIXTURE HELPERS
    // -----------------------------------------------------------------------

    private function buatAdmin(): array
    {
        $user = User::factory()->create([
            'role'        => 'admin',
            'nomor_induk' => 'ADM_' . uniqid(),
            'name'        => 'Admin Utama',
        ]);

        $admin = Admin::create([
            'users_id'   => $user->id,
            'nama_admin' => 'Admin Utama',
        ]);

        return compact('user', 'admin');
    }

    private function buatMahasiswa(?string $nim = null, string $prodi = 'Informatika'): array
    {
        $nim = $nim ?? (string) rand(1000000000, 9999999999);
        $user = User::factory()->create([
            'role'        => 'mahasiswa',
            'nomor_induk' => $nim,
            'name'        => 'Mahasiswa ' . $nim,
        ]);

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

    private function buatDosen(?string $nidn = null): array
    {
        $nidn = $nidn ?? (string) rand(1000000000, 9999999999);
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

    private function buatSkripsi(Mahasiswa $mahasiswa, string $statusJudul = 'disetujui'): Skripsi
    {
        return Skripsi::create([
            'id_mahasiswa' => $mahasiswa->id_mahasiswa,
            'judul'        => 'Sistem Otomasi Cerdas Berbasis Deep Learning',
            'status_judul' => $statusJudul,
        ]);
    }

    private function buatPersetujuanKaprodiResmi(Mahasiswa $mahasiswa, Skripsi $skripsi, Dosen $kaprodi): PengajuanJudul
    {
        $pengajuan = PengajuanJudul::create([
            'skripsi_id'    => $skripsi->id_skripsi,
            'mahasiswa_id'  => $mahasiswa->id_mahasiswa,
            'dosen_pa_id'   => $kaprodi->user_id,
            'judul'         => $skripsi->judul,
            'deskripsi'     => 'Deskripsi proposal resmi yang telah disetujui.',
            'nama_file'     => 'proposal.pdf',
            'ukuran_file'   => '1.5 MB',
            'file_path'     => 'pengajuan-judul/proposal.pdf',
            'status'        => 'Disetujui Kaprodi',
            'catatan_dosen' => 'Disetujui oleh Kaprodi.',
        ]);

        PengajuanJudulLog::create([
            'pengajuan_judul_id' => $pengajuan->id,
            'user_id'            => $kaprodi->user_id,
            'aktor_nama'         => $kaprodi->user->name ?? 'Kaprodi User',
            'aktor_role'         => 'kaprodi',
            'aktor_nomor_induk'  => $kaprodi->NIDN,
            'tahap'              => 'persetujuan_kaprodi',
            'tindakan'           => 'setujui_kaprodi',
            'status_sebelum'     => 'Menunggu Persetujuan Kaprodi',
            'status_sesudah'     => 'Disetujui Kaprodi',
            'catatan'            => 'Disetujui untuk lanjut ke Dospem Proposal.',
            'snapshot_judul'     => $pengajuan->judul,
            'snapshot_file_path' => $pengajuan->file_path,
        ]);

        return $pengajuan;
    }

    /**
     * Helper untuk memeriksa apakah tabel pengajuan_dospem sudah ada di database saat ini.
     * Karena instruksi Tahap 4B-1B secara ketat MELARANG eksekusi `php artisan migrate`,
     * test yang bergantung pada tabel DB baru akan diskip dengan keterangan yang jelas
     * jika migrasi belum dijalankan di environment test.
     */
    private function pastikanTabelDospemAda(): void
    {
        if (!Schema::hasTable('pengajuan_dospem')) {
            $this->markTestSkipped('Tabel pengajuan_dospem belum dimigrasi (Sesuai batasan Tahap 4B-1B: migration dibuat tapi tidak dieksekusi).');
        }
    }

    // -----------------------------------------------------------------------
    // SKENARIO 1: KEAMANAN AKSES GUEST (401 UNAUTHORIZED)
    // -----------------------------------------------------------------------

    public function test_01_guest_tidak_dapat_mengakses_endpoint_mahasiswa_maupun_admin(): void
    {
        // Endpoint Mahasiswa
        $this->getJson('/api/mahasiswa/pengajuan-dospem/status-kelayakan')->assertStatus(401);
        $this->getJson('/api/mahasiswa/pengajuan-dospem')->assertStatus(401);
        $this->postJson('/api/mahasiswa/pengajuan-dospem', [])->assertStatus(401);

        // Endpoint Admin
        $this->getJson('/api/admin/pengajuan-dospem/dosen-options')->assertStatus(401);
        $this->getJson('/api/admin/pengajuan-dospem')->assertStatus(401);
        $this->getJson('/api/admin/pengajuan-dospem/1')->assertStatus(401);
        $this->postJson('/api/admin/pengajuan-dospem/1/tetapkan', ['id_dosen' => 1])->assertStatus(401);
        $this->patchJson('/api/admin/pengajuan-dospem/1/status', ['status' => 'ditolak', 'catatan_admin' => 'Tolak'])->assertStatus(401);
    }

    // -----------------------------------------------------------------------
    // SKENARIO 2: OTORISASI PERAN ADMIN (403 FORBIDDEN UNTUK NON-ADMIN)
    // -----------------------------------------------------------------------

    public function test_02_mahasiswa_dan_dosen_ditolak_mengakses_endpoint_admin(): void
    {
        $mhs = $this->buatMahasiswa();
        $dsn = $this->buatDosen();

        // Mahasiswa mencoba akses admin
        $this->actingAs($mhs['user'], 'sanctum')
            ->getJson('/api/admin/pengajuan-dospem')
            ->assertStatus(403);

        $this->actingAs($mhs['user'], 'sanctum')
            ->postJson('/api/admin/pengajuan-dospem/1/tetapkan', ['id_dosen' => 1])
            ->assertStatus(403);

        // Dosen mencoba akses admin
        $this->actingAs($dsn['user'], 'sanctum')
            ->getJson('/api/admin/pengajuan-dospem')
            ->assertStatus(403);

        $this->actingAs($dsn['user'], 'sanctum')
            ->patchJson('/api/admin/pengajuan-dospem/1/status', ['status' => 'ditolak', 'catatan_admin' => 'Tolak'])
            ->assertStatus(403);
    }

    // -----------------------------------------------------------------------
    // SKENARIO 3: OTORISASI PERAN MAHASISWA (403 FORBIDDEN UNTUK NON-MAHASISWA)
    // -----------------------------------------------------------------------

    public function test_03_admin_dan_dosen_ditolak_mengakses_endpoint_mahasiswa(): void
    {
        $admin = $this->buatAdmin();
        $dsn = $this->buatDosen();

        // Admin mencoba akses endpoint mahasiswa
        $this->actingAs($admin['user'], 'sanctum')
            ->getJson('/api/mahasiswa/pengajuan-dospem/status-kelayakan')
            ->assertStatus(403);

        $this->actingAs($admin['user'], 'sanctum')
            ->postJson('/api/mahasiswa/pengajuan-dospem', [])
            ->assertStatus(403);

        // Dosen mencoba akses endpoint mahasiswa
        $this->actingAs($dsn['user'], 'sanctum')
            ->getJson('/api/mahasiswa/pengajuan-dospem')
            ->assertStatus(403);
    }

    // -----------------------------------------------------------------------
    // SKENARIO 4: VALIDASI AKADEMIK - MAHASISWA TANPA PERSETUJUAN KAPRODI
    // -----------------------------------------------------------------------

    public function test_04_mahasiswa_tanpa_persetujuan_kaprodi_ditolak_saat_mengajukan(): void
    {
        $this->pastikanTabelDospemAda();

        $mhs = $this->buatMahasiswa();
        $this->buatSkripsi($mhs['mahasiswa'], 'diajukan'); // Judul belum disetujui

        $response = $this->actingAs($mhs['user'], 'sanctum')
            ->postJson('/api/mahasiswa/pengajuan-dospem', [
                'catatan_mahasiswa' => 'Mohon pembimbing',
            ]);

        $response->assertStatus(422);
        $response->assertJsonFragment(['status' => 'error']);
    }

    // -----------------------------------------------------------------------
    // SKENARIO 5: MAHASISWA MEMENUHI SYARAT BERHASIL MENGAJUKAN
    // -----------------------------------------------------------------------

    public function test_05_mahasiswa_dengan_judul_disetujui_kaprodi_berhasil_mengajukan(): void
    {
        $this->pastikanTabelDospemAda();

        $mhs = $this->buatMahasiswa();
        $dsn = $this->buatDosen();
        $skripsi = $this->buatSkripsi($mhs['mahasiswa'], 'disetujui');
        $this->buatPersetujuanKaprodiResmi($mhs['mahasiswa'], $skripsi, $dsn['dosen']);

        $response = $this->actingAs($mhs['user'], 'sanctum')
            ->postJson('/api/mahasiswa/pengajuan-dospem', [
                'catatan_mahasiswa' => 'Permohonan penetapan Dospem Proposal',
            ]);

        $response->assertStatus(201);
        $response->assertJsonPath('data.status', 'menunggu_penetapan');
        $response->assertJsonPath('data.tahap', 'proposal');
        $response->assertJsonPath('data.dosen_id', null);

        // Pastikan log audit terbentuk secara atomik
        $this->assertDatabaseHas('pengajuan_dospem_logs', [
            'aktor_role'     => 'mahasiswa',
            'tindakan'       => 'submit',
            'status_sesudah' => 'menunggu_penetapan',
        ]);
    }

    // -----------------------------------------------------------------------
    // SKENARIO 6: ISOLASI KEPEMILIKAN MAHASISWA (ID PAYLOAD DIABAIKAN)
    // -----------------------------------------------------------------------

    public function test_06_mahasiswa_tidak_dapat_mengajukan_atas_nama_mahasiswa_atau_skripsi_lain(): void
    {
        $this->pastikanTabelDospemAda();

        $mhsA = $this->buatMahasiswa();
        $mhsB = $this->buatMahasiswa();
        $dsn = $this->buatDosen();

        $skripsiA = $this->buatSkripsi($mhsA['mahasiswa'], 'disetujui');
        $skripsiB = $this->buatSkripsi($mhsB['mahasiswa'], 'disetujui');

        $this->buatPersetujuanKaprodiResmi($mhsA['mahasiswa'], $skripsiA, $dsn['dosen']);
        $this->buatPersetujuanKaprodiResmi($mhsB['mahasiswa'], $skripsiB, $dsn['dosen']);

        // Mahasiswa A mengirimkan skripsi_id dan mahasiswa_id milik B
        $response = $this->actingAs($mhsA['user'], 'sanctum')
            ->postJson('/api/mahasiswa/pengajuan-dospem', [
                'skripsi_id'        => $skripsiB->id_skripsi,
                'mahasiswa_id'      => $mhsB['mahasiswa']->id_mahasiswa,
                'catatan_mahasiswa' => 'Percobaan spoofing',
            ]);

        $response->assertStatus(201);
        // Backend harus mengabaikan ID yang dikirim dan tetap mengaitkan ke skripsiA milik A
        $response->assertJsonPath('data.skripsi_id', $skripsiA->id_skripsi);
        $response->assertJsonPath('data.mahasiswa_id', $mhsA['mahasiswa']->id_mahasiswa);
    }

    // -----------------------------------------------------------------------
    // SKENARIO 7: PAYLOAD YANG MENENTUKAN DOSEN ATAU STATUS DIABAIKAN
    // -----------------------------------------------------------------------

    public function test_07_payload_mahasiswa_yang_mencoba_menetapkan_dosen_atau_status_diabaikan(): void
    {
        $this->pastikanTabelDospemAda();

        $mhs = $this->buatMahasiswa();
        $dsn = $this->buatDosen();
        $skripsi = $this->buatSkripsi($mhs['mahasiswa'], 'disetujui');
        $this->buatPersetujuanKaprodiResmi($mhs['mahasiswa'], $skripsi, $dsn['dosen']);

        $response = $this->actingAs($mhs['user'], 'sanctum')
            ->postJson('/api/mahasiswa/pengajuan-dospem', [
                'dosen_id' => $dsn['dosen']->id_dosen,
                'id_dosen' => $dsn['dosen']->id_dosen,
                'status'   => 'ditetapkan',
            ]);

        $response->assertStatus(201);
        // Status tetap menunggu_penetapan dan dosen_id tetap null
        $response->assertJsonPath('data.status', 'menunggu_penetapan');
        $response->assertJsonPath('data.dosen_id', null);
    }

    // -----------------------------------------------------------------------
    // SKENARIO 8: PENGAJUAN AKTIF GANDA DITOLAK (409 CONFLICT)
    // -----------------------------------------------------------------------

    public function test_08_pengajuan_aktif_ganda_ditolak(): void
    {
        $this->pastikanTabelDospemAda();

        $mhs = $this->buatMahasiswa();
        $dsn = $this->buatDosen();
        $skripsi = $this->buatSkripsi($mhs['mahasiswa'], 'disetujui');
        $this->buatPersetujuanKaprodiResmi($mhs['mahasiswa'], $skripsi, $dsn['dosen']);

        // Submit pertama -> Berhasil 201
        $this->actingAs($mhs['user'], 'sanctum')
            ->postJson('/api/mahasiswa/pengajuan-dospem', [])
            ->assertStatus(201);

        // Submit kedua saat pengajuan pertama masih menunggu_penetapan -> Konflik 409
        $response2 = $this->actingAs($mhs['user'], 'sanctum')
            ->postJson('/api/mahasiswa/pengajuan-dospem', []);

        $response2->assertStatus(409);
        $response2->assertJsonFragment(['status' => 'error']);
    }

    // -----------------------------------------------------------------------
    // SKENARIO 9: PENGAJUAN BARU DIPERBOLEHKAN SETELAH DITOLAK
    // -----------------------------------------------------------------------

    public function test_09_pengajuan_baru_diperbolehkan_setelah_pengajuan_sebelumnya_ditolak(): void
    {
        $this->pastikanTabelDospemAda();

        $admin = $this->buatAdmin();
        $mhs = $this->buatMahasiswa();
        $dsn = $this->buatDosen();
        $skripsi = $this->buatSkripsi($mhs['mahasiswa'], 'disetujui');
        $this->buatPersetujuanKaprodiResmi($mhs['mahasiswa'], $skripsi, $dsn['dosen']);

        // 1. Submit pengajuan pertama
        $resSubmit = $this->actingAs($mhs['user'], 'sanctum')
            ->postJson('/api/mahasiswa/pengajuan-dospem', []);
        $resSubmit->assertStatus(201);
        $pengajuanId = $resSubmit->json('data.id');

        // 2. Admin menolak pengajuan pertama
        $this->actingAs($admin['user'], 'sanctum')
            ->patchJson("/api/admin/pengajuan-dospem/{$pengajuanId}/status", [
                'status'        => 'ditolak',
                'catatan_admin' => 'Format usulan tidak sesuai panduan.',
            ])
            ->assertStatus(200);

        // 3. Mahasiswa mengajukan kembali -> Wajib berhasil 201 tanpa error unique constraint
        $resSubmit2 = $this->actingAs($mhs['user'], 'sanctum')
            ->postJson('/api/mahasiswa/pengajuan-dospem', [
                'catatan_mahasiswa' => 'Pengajuan ulang proposal yang telah diperbaiki.',
            ]);

        $resSubmit2->assertStatus(201);
        $this->assertNotEquals($pengajuanId, $resSubmit2->json('data.id'));
    }

    // -----------------------------------------------------------------------
    // SKENARIO 10: ADMIN MELIHAT DAFTAR ANTREAN DAN OPSI DOSEN
    // -----------------------------------------------------------------------

    public function test_10_admin_dapat_melihat_antrean_dan_opsi_dosen(): void
    {
        $this->pastikanTabelDospemAda();

        $admin = $this->buatAdmin();
        $this->buatDosen();

        // Akses antrean admin
        $resIndex = $this->actingAs($admin['user'], 'sanctum')
            ->getJson('/api/admin/pengajuan-dospem');
        $resIndex->assertStatus(200);
        $resIndex->assertJsonStructure(['status', 'data']);

        // Akses opsi dosen admin
        $resOptions = $this->actingAs($admin['user'], 'sanctum')
            ->getJson('/api/admin/pengajuan-dospem/dosen-options');
        $resOptions->assertStatus(200);
        $resOptions->assertJsonStructure([
            'status',
            'data' => [
                '*' => ['id_dosen', 'nama_dosen', 'NIDN', 'jumlah_penugasan_teramati'],
            ],
        ]);
    }

    // -----------------------------------------------------------------------
    // SKENARIO 11: ADMIN MENETAPKAN SATU DOSEN PEMBIMBING PROPOSAL
    // -----------------------------------------------------------------------

    public function test_11_admin_dapat_menetapkan_satu_dosen_pembimbing_proposal(): void
    {
        $this->pastikanTabelDospemAda();

        $admin = $this->buatAdmin();
        $mhs = $this->buatMahasiswa();
        $dsn = $this->buatDosen();
        $skripsi = $this->buatSkripsi($mhs['mahasiswa'], 'disetujui');
        $this->buatPersetujuanKaprodiResmi($mhs['mahasiswa'], $skripsi, $dsn['dosen']);

        // Submit pengajuan
        $resSubmit = $this->actingAs($mhs['user'], 'sanctum')
            ->postJson('/api/mahasiswa/pengajuan-dospem', []);
        $pengajuanId = $resSubmit->json('data.id');

        // Admin menetapkan dosen
        $resTetapkan = $this->actingAs($admin['user'], 'sanctum')
            ->postJson("/api/admin/pengajuan-dospem/{$pengajuanId}/tetapkan", [
                'id_dosen'      => $dsn['dosen']->id_dosen,
                'catatan_admin' => 'Ditetapkan sebagai Pembimbing Proposal.',
            ]);

        $resTetapkan->assertStatus(200);
        $resTetapkan->assertJsonPath('data.status', 'ditetapkan');
        $resTetapkan->assertJsonPath('data.dosen_id', $dsn['dosen']->id_dosen);

        // Pastikan record penugasan resmi masuk ke tabel dosen_pembimbing
        $this->assertDatabaseHas('dosen_pembimbing', [
            'id_skripsi' => $skripsi->id_skripsi,
            'id_dosen'   => $dsn['dosen']->id_dosen,
        ]);

        // Pastikan audit log penetapan tercatat
        $this->assertDatabaseHas('pengajuan_dospem_logs', [
            'pengajuan_dospem_id' => $pengajuanId,
            'aktor_role'          => 'admin',
            'tindakan'            => 'tetapkan',
            'status_sesudah'      => 'ditetapkan',
            'snapshot_dosen_id'   => $dsn['dosen']->id_dosen,
        ]);
    }

    // -----------------------------------------------------------------------
    // SKENARIO 12: DOSEN MELIHAT MAHASISWA SETELAH DITETAPKAN (KOMPATIBILITAS)
    // -----------------------------------------------------------------------

    public function test_12_dosen_yang_ditetapkan_dapat_melihat_mahasiswa_melalui_relasi_bimbingan(): void
    {
        $this->pastikanTabelDospemAda();

        $admin = $this->buatAdmin();
        $mhs = $this->buatMahasiswa();
        $dsn = $this->buatDosen();
        $skripsi = $this->buatSkripsi($mhs['mahasiswa'], 'disetujui');
        $this->buatPersetujuanKaprodiResmi($mhs['mahasiswa'], $skripsi, $dsn['dosen']);

        // Submit & Tetapkan
        $resSubmit = $this->actingAs($mhs['user'], 'sanctum')->postJson('/api/mahasiswa/pengajuan-dospem', []);
        $pengajuanId = $resSubmit->json('data.id');

        $this->actingAs($admin['user'], 'sanctum')
            ->postJson("/api/admin/pengajuan-dospem/{$pengajuanId}/tetapkan", [
                'id_dosen' => $dsn['dosen']->id_dosen,
            ])
            ->assertStatus(200);

        // Dosen memeriksa daftar dosen-pembimbing
        $resDosen = $this->actingAs($dsn['user'], 'sanctum')
            ->getJson('/api/dosen-pembimbing');

        $resDosen->assertStatus(200);
        $resDosen->assertJsonFragment(['id_skripsi' => $skripsi->id_skripsi]);
    }

    // -----------------------------------------------------------------------
    // SKENARIO 13: PERLINDUNGAN APPEND-ONLY PADA MODEL PENGAJUAN DOSPEM LOG
    // -----------------------------------------------------------------------

    public function test_13_model_pengajuan_dospem_log_bersifat_append_only(): void
    {
        $this->pastikanTabelDospemAda();

        $mhs = $this->buatMahasiswa();
        $dsn = $this->buatDosen();
        $skripsi = $this->buatSkripsi($mhs['mahasiswa'], 'disetujui');
        $this->buatPersetujuanKaprodiResmi($mhs['mahasiswa'], $skripsi, $dsn['dosen']);

        $originalCatatan = 'Log pengajuan proposal awal.';

        // Jalankan pengajuan via API aplikasi yang valid
        $response = $this->actingAs($mhs['user'], 'sanctum')
            ->postJson('/api/mahasiswa/pengajuan-dospem', [
                'catatan_mahasiswa' => $originalCatatan,
            ]);

        $response->assertStatus(201);
        $response->assertJsonStructure(['status', 'data' => ['id']]);
        $pengajuanId = $response->json('data.id');
        $this->assertNotNull($pengajuanId, 'ID pengajuan dospem harus tersedia pada respons.');

        // Ambil log persisted dengan query berdasarkan ID pengajuan yang valid
        $log = PengajuanDospemLog::where('pengajuan_dospem_id', $pengajuanId)->first();
        $this->assertNotNull($log, 'Record PengajuanDospemLog persisted harus ditemukan di database.');
        $this->assertEquals($originalCatatan, $log->catatan);

        // 1. Uji penolakan operasi update() melalui API publik Eloquent
        $updateBlocked = false;
        try {
            $log->update(['catatan' => 'Mencoba manipulasi catatan log']);
        } catch (\RuntimeException $e) {
            $updateBlocked = true;
            $this->assertStringContainsString('PengajuanDospemLog bersifat append-only: Pembaruan data log tidak diizinkan.', $e->getMessage());
        }
        $this->assertTrue($updateBlocked, 'Operasi update() pada model PengajuanDospemLog seharusnya ditolak dengan RuntimeException.');

        // 2. Uji penolakan operasi delete() melalui API publik Eloquent
        $deleteBlocked = false;
        try {
            $log->delete();
        } catch (\RuntimeException $e) {
            $deleteBlocked = true;
            $this->assertStringContainsString('PengajuanDospemLog bersifat append-only: Penghapusan data log tidak diizinkan.', $e->getMessage());
        }
        $this->assertTrue($deleteBlocked, 'Operasi delete() pada model PengajuanDospemLog seharusnya ditolak dengan RuntimeException.');

        // 3. Verifikasi record log tetap ada di database dan nilainya tidak berubah
        $logRefreshed = $log->fresh();
        $this->assertNotNull($logRefreshed, 'Record PengajuanDospemLog harus tetap ada di database.');
        $this->assertEquals($originalCatatan, $logRefreshed->catatan, 'Isi catatan log tidak boleh berubah.');
        $this->assertEquals('submit', $logRefreshed->tindakan, 'Tindakan log tidak boleh berubah.');
        $this->assertEquals($log->id, $logRefreshed->id, 'ID log harus tetap identik.');
    }
}
