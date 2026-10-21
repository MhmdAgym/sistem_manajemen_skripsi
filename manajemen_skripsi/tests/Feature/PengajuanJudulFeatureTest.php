<?php

namespace Tests\Feature;

use App\Models\Dosen;
use App\Models\DosenPA;
use App\Models\Mahasiswa;
use App\Models\PengajuanJudul;
use App\Models\Skripsi;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PengajuanJudulFeatureTest extends TestCase
{
    use RefreshDatabase;

    // -----------------------------------------------------------------------
    // HELPERS
    // -----------------------------------------------------------------------

    private function buatMahasiswaDenganDosenPA(): array
    {
        $mahasiswaUser = User::factory()->create(['role' => 'mahasiswa', 'nomor_induk' => '2010112233']);
        $mahasiswa = Mahasiswa::create([
            'user_id' => $mahasiswaUser->id, 'NIM' => '2010112233',
            'nama_mahasiswa' => 'Agus Mahasiswa', 'prodi' => 'Sistem Informasi',
        ]);
        $dosenUser = User::factory()->create(['role' => 'dosen', 'nomor_induk' => '9988776655']);
        $dosen = Dosen::create([
            'user_id' => $dosenUser->id, 'NIDN' => '9988776655', 'nama_dosen' => 'Bapak Dosen PA',
        ]);
        $skripsi = Skripsi::create([
            'id_mahasiswa' => $mahasiswa->id_mahasiswa,
            'judul' => 'Menunggu Pengajuan', 'status_judul' => 'Belum Mengajukan',
        ]);
        $dosenPa = DosenPA::create([
            'id_dosen' => $dosen->id_dosen, 'id_skripsi' => $skripsi->id_skripsi,
        ]);
        return compact('mahasiswaUser', 'mahasiswa', 'dosenUser', 'dosen', 'skripsi', 'dosenPa');
    }

    private function buatFilePDF(): UploadedFile
    {
        Storage::fake('public');
        return UploadedFile::fake()->create('proposal_skripsi.pdf', 500, 'application/pdf');
    }

    // -----------------------------------------------------------------------
    // GROUP 1: AUTENTIKASI & AKSES (401 / 403)
    // -----------------------------------------------------------------------

    public function test_tamu_tidak_bisa_mengakses_pengajuan_judul(): void
    {
        $this->getJson('/api/mahasiswa/pengajuan-judul')->assertStatus(401);
    }

    public function test_tamu_tidak_bisa_kirim_pengajuan(): void
    {
        $this->postJson('/api/mahasiswa/pengajuan-judul', [])->assertStatus(401);
    }

    public function test_tamu_tidak_bisa_akses_daftar_verifikasi_dosen(): void
    {
        $this->getJson('/api/dosen/pengajuan-judul')->assertStatus(401);
    }

    public function test_tamu_tidak_bisa_akses_admin_pengajuan(): void
    {
        $this->getJson('/api/admin/pengajuan-judul')->assertStatus(401);
    }

    public function test_dosen_tidak_bisa_mengakses_endpoint_mahasiswa(): void
    {
        $dosenUser = User::factory()->create(['role' => 'dosen']);
        $this->actingAs($dosenUser, 'sanctum')
            ->getJson('/api/mahasiswa/pengajuan-judul')
            ->assertStatus(403);
    }

    public function test_mahasiswa_tidak_bisa_mengakses_endpoint_dosen(): void
    {
        $data = $this->buatMahasiswaDenganDosenPA();
        $this->actingAs($data['mahasiswaUser'], 'sanctum')
            ->getJson('/api/dosen/pengajuan-judul')
            ->assertStatus(403);
    }

    public function test_mahasiswa_tidak_bisa_akses_admin_pengajuan(): void
    {
        $data = $this->buatMahasiswaDenganDosenPA();
        $this->actingAs($data['mahasiswaUser'], 'sanctum')
            ->getJson('/api/admin/pengajuan-judul')
            ->assertStatus(403);
    }

    public function test_dosen_tidak_bisa_akses_admin_pengajuan(): void
    {
        $dosenUser = User::factory()->create(['role' => 'dosen']);
        $this->actingAs($dosenUser, 'sanctum')
            ->getJson('/api/admin/pengajuan-judul')
            ->assertStatus(403);
    }

    // -----------------------------------------------------------------------
    // GROUP 2: MAHASISWA — INDEX
    // -----------------------------------------------------------------------

    public function test_mahasiswa_hanya_melihat_pengajuannya_sendiri(): void
    {
        $data1 = $this->buatMahasiswaDenganDosenPA();

        $user2 = User::factory()->create(['role' => 'mahasiswa', 'nomor_induk' => '9999999999']);
        Mahasiswa::create([
            'user_id' => $user2->id, 'NIM' => '9999999999',
            'nama_mahasiswa' => 'Mahasiswa Dua', 'prodi' => 'TI',
        ]);

        PengajuanJudul::create([
            'mahasiswa_id' => $data1['mahasiswa']->id_mahasiswa,
            'dosen_pa_id'  => $data1['dosenUser']->id,
            'judul' => 'Judul Mahasiswa Satu', 'deskripsi' => 'Deskripsi',
            'nama_file' => 'file.pdf', 'ukuran_file' => '100 KB',
            'file_path' => 'pengajuan-judul/file.pdf',
            'status' => 'Menunggu Verifikasi Dosen PA',
        ]);

        $response = $this->actingAs($user2, 'sanctum')->getJson('/api/mahasiswa/pengajuan-judul');
        $response->assertStatus(200);
        $response->assertJsonCount(0, 'data');
    }

    public function test_mahasiswa_tanpa_profil_mendapat_404(): void
    {
        $user = User::factory()->create(['role' => 'mahasiswa']);
        $this->actingAs($user, 'sanctum')
            ->getJson('/api/mahasiswa/pengajuan-judul')
            ->assertStatus(404);
    }

    // -----------------------------------------------------------------------
    // GROUP 3: MAHASISWA — STORE
    // -----------------------------------------------------------------------

    public function test_mahasiswa_dengan_dosen_pa_dapat_mengirim_pengajuan(): void
    {
        $data = $this->buatMahasiswaDenganDosenPA();
        $file = $this->buatFilePDF();

        $response = $this->actingAs($data['mahasiswaUser'], 'sanctum')
            ->postJson('/api/mahasiswa/pengajuan-judul', [
                'judul'      => 'Implementasi Machine Learning untuk Deteksi Spam',
                'deskripsi'  => 'Penelitian ini menggunakan algoritma Naive Bayes.',
                'file_judul' => $file,
            ]);

        $response->assertStatus(201);
        $response->assertJsonFragment(['status' => 'success']);

        $this->assertDatabaseHas('pengajuan_judul', [
            'mahasiswa_id' => $data['mahasiswa']->id_mahasiswa,
            'dosen_pa_id'  => $data['dosenUser']->id,
            'judul'        => 'Implementasi Machine Learning untuk Deteksi Spam',
            'status'       => 'Menunggu Verifikasi Dosen PA',
        ]);
    }

    public function test_mahasiswa_tanpa_dosen_pa_diblokir(): void
    {
        $mahasiswaUser = User::factory()->create(['role' => 'mahasiswa', 'nomor_induk' => '1111111111']);
        $mahasiswa = Mahasiswa::create([
            'user_id' => $mahasiswaUser->id, 'NIM' => '1111111111',
            'nama_mahasiswa' => 'Mahasiswa Tanpa PA', 'prodi' => 'TI',
        ]);
        Skripsi::create([
            'id_mahasiswa' => $mahasiswa->id_mahasiswa,
            'judul' => 'Menunggu', 'status_judul' => 'diajukan',
        ]);

        $file = $this->buatFilePDF();
        $response = $this->actingAs($mahasiswaUser, 'sanctum')
            ->postJson('/api/mahasiswa/pengajuan-judul', [
                'judul' => 'Judul Test', 'deskripsi' => 'Deskripsi test', 'file_judul' => $file,
            ]);

        $response->assertStatus(422);
        $response->assertJsonFragment(['status' => 'error']);
    }

    public function test_mahasiswa_tanpa_skripsi_diblokir(): void
    {
        $mahasiswaUser = User::factory()->create(['role' => 'mahasiswa', 'nomor_induk' => '2222222222']);
        Mahasiswa::create([
            'user_id' => $mahasiswaUser->id, 'NIM' => '2222222222',
            'nama_mahasiswa' => 'Mahasiswa Tanpa Skripsi', 'prodi' => 'TI',
        ]);

        $file = $this->buatFilePDF();
        $response = $this->actingAs($mahasiswaUser, 'sanctum')
            ->postJson('/api/mahasiswa/pengajuan-judul', [
                'judul' => 'Judul Test', 'deskripsi' => 'Deskripsi test', 'file_judul' => $file,
            ]);

        $response->assertStatus(422);
    }

    public function test_mahasiswa_tidak_bisa_mengajukan_untuk_mahasiswa_lain(): void
    {
        $data = $this->buatMahasiswaDenganDosenPA();
        $file = $this->buatFilePDF();

        $response = $this->actingAs($data['mahasiswaUser'], 'sanctum')
            ->postJson('/api/mahasiswa/pengajuan-judul', [
                'judul'        => 'Judul Sah',
                'deskripsi'    => 'Deskripsi sah',
                'file_judul'   => $file,
                'mahasiswa_id' => 99999, // Field asing — harus diabaikan backend
                'dosen_pa_id'  => 99999, // Field asing — harus diabaikan backend
            ]);

        $response->assertStatus(201);

        // Pastikan tersimpan berdasarkan user yang login, bukan dari input frontend
        $this->assertDatabaseHas('pengajuan_judul', [
            'mahasiswa_id' => $data['mahasiswa']->id_mahasiswa,
            'dosen_pa_id'  => $data['dosenUser']->id,
        ]);
    }

    public function test_validasi_file_wajib_ada(): void
    {
        $data = $this->buatMahasiswaDenganDosenPA();

        $this->actingAs($data['mahasiswaUser'], 'sanctum')
            ->postJson('/api/mahasiswa/pengajuan-judul', [
                'judul' => 'Judul Test', 'deskripsi' => 'Deskripsi test',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['file_judul']);
    }

    public function test_validasi_judul_wajib_ada(): void
    {
        $data = $this->buatMahasiswaDenganDosenPA();
        $file = $this->buatFilePDF();

        $this->actingAs($data['mahasiswaUser'], 'sanctum')
            ->postJson('/api/mahasiswa/pengajuan-judul', [
                'deskripsi' => 'Deskripsi test', 'file_judul' => $file,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['judul']);
    }

    // -----------------------------------------------------------------------
    // GROUP 4: DOSEN PA — DAFTAR VERIFIKASI
    // -----------------------------------------------------------------------

    public function test_dosen_pa_hanya_melihat_pengajuan_miliknya(): void
    {
        $data1 = $this->buatMahasiswaDenganDosenPA();

        $dosenUser2 = User::factory()->create(['role' => 'dosen', 'nomor_induk' => '7777777777']);
        $dosen2 = Dosen::create(['user_id' => $dosenUser2->id, 'NIDN' => '7777777777', 'nama_dosen' => 'Dosen Dua']);
        $mhsUser2 = User::factory()->create(['role' => 'mahasiswa', 'nomor_induk' => '8888888888']);
        $mhs2 = Mahasiswa::create(['user_id' => $mhsUser2->id, 'NIM' => '8888888888', 'nama_mahasiswa' => 'Mhs Dua', 'prodi' => 'TI']);
        $skripsi2 = Skripsi::create(['id_mahasiswa' => $mhs2->id_mahasiswa, 'judul' => 'X', 'status_judul' => 'diajukan']);
        DosenPA::create(['id_dosen' => $dosen2->id_dosen, 'id_skripsi' => $skripsi2->id_skripsi]);

        PengajuanJudul::create([
            'mahasiswa_id' => $data1['mahasiswa']->id_mahasiswa, 'dosen_pa_id' => $data1['dosenUser']->id,
            'judul' => 'Pengajuan Untuk Dosen 1', 'deskripsi' => 'Test',
            'nama_file' => 'f.pdf', 'ukuran_file' => '1 KB', 'file_path' => 'x/f.pdf',
            'status' => 'Menunggu Verifikasi Dosen PA',
        ]);
        PengajuanJudul::create([
            'mahasiswa_id' => $mhs2->id_mahasiswa, 'dosen_pa_id' => $dosenUser2->id,
            'judul' => 'Pengajuan Untuk Dosen 2', 'deskripsi' => 'Test',
            'nama_file' => 'g.pdf', 'ukuran_file' => '1 KB', 'file_path' => 'x/g.pdf',
            'status' => 'Menunggu Verifikasi Dosen PA',
        ]);

        // Dosen 1 hanya melihat pengajuannya sendiri
        $resp1 = $this->actingAs($data1['dosenUser'], 'sanctum')->getJson('/api/dosen/pengajuan-judul');
        $resp1->assertStatus(200)->assertJsonCount(1, 'data');
        $resp1->assertJsonFragment(['judul' => 'Pengajuan Untuk Dosen 1']);

        // Dosen 2 hanya melihat pengajuannya sendiri
        $resp2 = $this->actingAs($dosenUser2, 'sanctum')->getJson('/api/dosen/pengajuan-judul');
        $resp2->assertStatus(200)->assertJsonCount(1, 'data');
        $resp2->assertJsonFragment(['judul' => 'Pengajuan Untuk Dosen 2']);
    }

    // -----------------------------------------------------------------------
    // GROUP 5: DOSEN PA — VERIFIKASI (ownership + semua status)
    // -----------------------------------------------------------------------

    private function buatPengajuan(array $data, string $judul = 'Judul Test'): PengajuanJudul
    {
        return PengajuanJudul::create([
            'mahasiswa_id' => $data['mahasiswa']->id_mahasiswa,
            'dosen_pa_id'  => $data['dosenUser']->id,
            'judul'        => $judul,
            'deskripsi'    => 'Deskripsi',
            'nama_file'    => 'f.pdf',
            'ukuran_file'  => '100 KB',
            'file_path'    => 'pengajuan-judul/f.pdf',
            'status'       => 'Menunggu Verifikasi Dosen PA',
        ]);
    }

    public function test_dosen_pa_dapat_menyetujui_pengajuan(): void
    {
        $data = $this->buatMahasiswaDenganDosenPA();
        $pengajuan = $this->buatPengajuan($data, 'Judul Test Setujui');

        $this->actingAs($data['dosenUser'], 'sanctum')
            ->patchJson("/api/dosen/pengajuan-judul/{$pengajuan->id}/verifikasi", [
                'status' => 'Disetujui Dosen PA', 'catatan_dosen' => 'Judul disetujui.',
            ])
            ->assertStatus(200)
            ->assertJsonFragment(['status' => 'success']);

        $this->assertDatabaseHas('pengajuan_judul', ['id' => $pengajuan->id, 'status' => 'Disetujui Dosen PA']);
    }

    public function test_dosen_pa_dapat_meminta_revisi(): void
    {
        $data = $this->buatMahasiswaDenganDosenPA();
        $pengajuan = $this->buatPengajuan($data, 'Judul Test Revisi');

        $this->actingAs($data['dosenUser'], 'sanctum')
            ->patchJson("/api/dosen/pengajuan-judul/{$pengajuan->id}/verifikasi", [
                'status' => 'Revisi', 'catatan_dosen' => 'Perlu perbaikan metodologi.',
            ])
            ->assertStatus(200);

        $this->assertDatabaseHas('pengajuan_judul', ['id' => $pengajuan->id, 'status' => 'Revisi']);
    }

    public function test_dosen_pa_dapat_menolak_pengajuan(): void
    {
        $data = $this->buatMahasiswaDenganDosenPA();
        $pengajuan = $this->buatPengajuan($data, 'Judul Test Ditolak');

        $this->actingAs($data['dosenUser'], 'sanctum')
            ->patchJson("/api/dosen/pengajuan-judul/{$pengajuan->id}/verifikasi", [
                'status' => 'Ditolak', 'catatan_dosen' => 'Judul terlalu umum.',
            ])
            ->assertStatus(200);

        $this->assertDatabaseHas('pengajuan_judul', ['id' => $pengajuan->id, 'status' => 'Ditolak']);
    }

    public function test_dosen_pa_tidak_bisa_verifikasi_pengajuan_dosen_lain(): void
    {
        $data1 = $this->buatMahasiswaDenganDosenPA();
        $dosenUser2 = User::factory()->create(['role' => 'dosen', 'nomor_induk' => '6666666666']);
        $pengajuan = $this->buatPengajuan($data1, 'Judul Milik Dosen 1');

        // Dosen 2 mencoba memverifikasi pengajuan milik dosen 1 — harus 404
        $this->actingAs($dosenUser2, 'sanctum')
            ->patchJson("/api/dosen/pengajuan-judul/{$pengajuan->id}/verifikasi", [
                'status' => 'Disetujui Dosen PA',
            ])
            ->assertStatus(404);

        // Status di DB tidak berubah
        $this->assertDatabaseHas('pengajuan_judul', [
            'id' => $pengajuan->id, 'status' => 'Menunggu Verifikasi Dosen PA',
        ]);
    }

    // -----------------------------------------------------------------------
    // GROUP 6: ADMIN
    // -----------------------------------------------------------------------

    public function test_admin_dapat_melihat_semua_pengajuan(): void
    {
        $data = $this->buatMahasiswaDenganDosenPA();
        $adminUser = User::factory()->create(['role' => 'admin']);
        $this->buatPengajuan($data, 'Judul Untuk Admin');

        $this->actingAs($adminUser, 'sanctum')
            ->getJson('/api/admin/pengajuan-judul')
            ->assertStatus(200)
            ->assertJsonCount(1, 'data');
    }
}
