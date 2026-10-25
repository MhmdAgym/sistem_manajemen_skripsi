<?php

namespace Tests\Feature;

use App\Constants\AcademicConstant;
use App\Models\Admin;
use App\Models\Bimbingan;
use App\Models\Dosen;
use App\Models\DosenPA;
use App\Models\DosenPembimbing;
use App\Models\Mahasiswa;
use App\Models\PengajuanJudul;
use App\Models\PenugasanKaprodi;
use App\Models\Skripsi;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class DospemDanBimbinganSecurityFeatureTest extends TestCase
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
            'judul'        => 'Rancang Bangun Sistem Informasi Berbasis AI',
            'status_judul' => $statusJudul,
        ]);
    }

    // -----------------------------------------------------------------------
    // SKENARIO 1 & 2: PENGUJIAN AKSES GUEST PADA DOSEN-PEMBIMBING
    // -----------------------------------------------------------------------

    public function test_1_guest_tidak_dapat_membaca_data_dosen_pembimbing(): void
    {
        $mhs = $this->buatMahasiswa();
        $dsn = $this->buatDosen();
        $skripsi = $this->buatSkripsi($mhs['mahasiswa']);
        $dp = DosenPembimbing::create([
            'id_skripsi' => $skripsi->id_skripsi,
            'id_dosen'   => $dsn['dosen']->id_dosen,
        ]);

        // Akses daftar tanpa login
        $responseIndex = $this->getJson('/api/dosen-pembimbing');
        $responseIndex->assertStatus(401);

        // Akses detail tanpa login
        $responseShow = $this->getJson('/api/dosen-pembimbing/' . $dp->id_pembimbing);
        $responseShow->assertStatus(401);
    }

    public function test_2_guest_tidak_dapat_membuat_mengubah_atau_menghapus_data_dosen_pembimbing(): void
    {
        $mhs = $this->buatMahasiswa();
        $dsn = $this->buatDosen();
        $skripsi = $this->buatSkripsi($mhs['mahasiswa']);
        $dp = DosenPembimbing::create([
            'id_skripsi' => $skripsi->id_skripsi,
            'id_dosen'   => $dsn['dosen']->id_dosen,
        ]);

        // Guest POST
        $resStore = $this->postJson('/api/dosen-pembimbing', [
            'id_skripsi' => $skripsi->id_skripsi,
            'id_dosen'   => $dsn['dosen']->id_dosen,
        ]);
        $resStore->assertStatus(401);

        // Guest PUT
        $resUpdate = $this->putJson('/api/dosen-pembimbing/' . $dp->id_pembimbing, [
            'id_dosen' => $dsn['dosen']->id_dosen,
        ]);
        $resUpdate->assertStatus(401);

        // Guest DELETE
        $resDelete = $this->deleteJson('/api/dosen-pembimbing/' . $dp->id_pembimbing);
        $resDelete->assertStatus(401);
    }

    // -----------------------------------------------------------------------
    // SKENARIO 3: PENGUJIAN AKSES GUEST PADA BIMBINGAN
    // -----------------------------------------------------------------------

    public function test_3_guest_tidak_dapat_membaca_atau_memanipulasi_data_bimbingan(): void
    {
        $mhs = $this->buatMahasiswa();
        $skripsi = $this->buatSkripsi($mhs['mahasiswa']);
        $bimbingan = Bimbingan::create([
            'id_skripsi' => $skripsi->id_skripsi,
            'tanggal'    => '2026-10-25',
            'catatan'    => 'Konsultasi Bab 1',
        ]);

        $this->getJson('/api/bimbingan')->assertStatus(401);
        $this->getJson('/api/bimbingan/' . $bimbingan->id_bimbingan)->assertStatus(401);
        $this->postJson('/api/bimbingan', [
            'id_skripsi' => $skripsi->id_skripsi,
            'tanggal'    => '2026-10-25',
            'catatan'    => 'Sesi Liar',
        ])->assertStatus(401);
        $this->putJson('/api/bimbingan/' . $bimbingan->id_bimbingan, [
            'catatan' => 'Ubah Liar',
        ])->assertStatus(401);
        $this->deleteJson('/api/bimbingan/' . $bimbingan->id_bimbingan)->assertStatus(401);
    }

    // -----------------------------------------------------------------------
    // SKENARIO 4: MAHASISWA & DOSEN DILARANG MENGUBAH PENUGASAN PEMBIMBING
    // -----------------------------------------------------------------------

    public function test_4_mahasiswa_dan_dosen_tidak_dapat_mengubah_penugasan_pembimbing_kewenangan_admin(): void
    {
        $mhs = $this->buatMahasiswa();
        $dsn = $this->buatDosen();
        $skripsi = $this->buatSkripsi($mhs['mahasiswa']);
        $dp = DosenPembimbing::create([
            'id_skripsi' => $skripsi->id_skripsi,
            'id_dosen'   => $dsn['dosen']->id_dosen,
        ]);

        // Mahasiswa mencoba menetapkan pembimbing
        $this->actingAs($mhs['user'], 'sanctum')
            ->postJson('/api/dosen-pembimbing', [
                'id_skripsi' => $skripsi->id_skripsi,
                'id_dosen'   => $dsn['dosen']->id_dosen,
            ])
            ->assertStatus(403);

        // Mahasiswa mencoba menghapus penugasan pembimbing
        $this->actingAs($mhs['user'], 'sanctum')
            ->deleteJson('/api/dosen-pembimbing/' . $dp->id_pembimbing)
            ->assertStatus(403);

        // Dosen mencoba menetapkan pembimbing
        $this->actingAs($dsn['user'], 'sanctum')
            ->postJson('/api/dosen-pembimbing', [
                'id_skripsi' => $skripsi->id_skripsi,
                'id_dosen'   => $dsn['dosen']->id_dosen,
            ])
            ->assertStatus(403);

        // Dosen mencoba memperbarui penugasan pembimbing
        $this->actingAs($dsn['user'], 'sanctum')
            ->putJson('/api/dosen-pembimbing/' . $dp->id_pembimbing, [
                'id_dosen' => $dsn['dosen']->id_dosen,
            ])
            ->assertStatus(403);
    }

    // -----------------------------------------------------------------------
    // SKENARIO 5: ISOLASI BIMBINGAN ANTAR-MAHASISWA (AKSES LINTAS PENGGUNA)
    // -----------------------------------------------------------------------

    public function test_5_mahasiswa_a_tidak_dapat_membaca_atau_mengubah_catatan_bimbingan_mahasiswa_b(): void
    {
        $mhsA = $this->buatMahasiswa();
        $mhsB = $this->buatMahasiswa();
        $dsn = $this->buatDosen();

        $skripsiA = $this->buatSkripsi($mhsA['mahasiswa']);
        $skripsiB = $this->buatSkripsi($mhsB['mahasiswa']);

        DosenPembimbing::create(['id_skripsi' => $skripsiA->id_skripsi, 'id_dosen' => $dsn['dosen']->id_dosen]);
        DosenPembimbing::create(['id_skripsi' => $skripsiB->id_skripsi, 'id_dosen' => $dsn['dosen']->id_dosen]);

        $bimbinganA = Bimbingan::create([
            'id_skripsi' => $skripsiA->id_skripsi,
            'tanggal'    => '2026-10-25',
            'catatan'    => 'Catatan Mhs A',
        ]);

        $bimbinganB = Bimbingan::create([
            'id_skripsi' => $skripsiB->id_skripsi,
            'tanggal'    => '2026-10-25',
            'catatan'    => 'Catatan Mhs B Rahasia',
        ]);

        // Mhs A memanggil index -> hanya menerima data bimbingan A, tidak boleh ada bimbingan B
        $resIndex = $this->actingAs($mhsA['user'], 'sanctum')->getJson('/api/bimbingan');
        $resIndex->assertStatus(200);
        $resIndex->assertJsonFragment(['catatan' => 'Catatan Mhs A']);
        $resIndex->assertJsonMissing(['catatan' => 'Catatan Mhs B Rahasia']);

        // Mhs A mencoba melihat detail bimbingan B
        $this->actingAs($mhsA['user'], 'sanctum')
            ->getJson('/api/bimbingan/' . $bimbinganB->id_bimbingan)
            ->assertStatus(403);

        // Mhs A mencoba mengupdate bimbingan B
        $this->actingAs($mhsA['user'], 'sanctum')
            ->putJson('/api/bimbingan/' . $bimbinganB->id_bimbingan, [
                'catatan' => 'Mencoba merusak catatan Mhs B',
            ])
            ->assertStatus(403);

        // Mhs A mencoba menghapus bimbingan B
        $this->actingAs($mhsA['user'], 'sanctum')
            ->deleteJson('/api/bimbingan/' . $bimbinganB->id_bimbingan)
            ->assertStatus(403);
    }

    // -----------------------------------------------------------------------
    // SKENARIO 6: ISOLASI DOSEN YANG BUKAN PEMBIMBING
    // -----------------------------------------------------------------------

    public function test_6_dosen_yang_bukan_pembimbing_tidak_dapat_mengakses_catatan_bimbingan_di_luar_kewenangannya(): void
    {
        $mhs = $this->buatMahasiswa();
        $dsnPembimbing = $this->buatDosen();
        $dsnLain = $this->buatDosen();

        $skripsi = $this->buatSkripsi($mhs['mahasiswa']);
        DosenPembimbing::create(['id_skripsi' => $skripsi->id_skripsi, 'id_dosen' => $dsnPembimbing['dosen']->id_dosen]);

        $bimbingan = Bimbingan::create([
            'id_skripsi' => $skripsi->id_skripsi,
            'tanggal'    => '2026-10-25',
            'catatan'    => 'Catatan dengan Pembimbing Resmi',
        ]);

        // Dosen lain tidak melihat data ini di index
        $resIndex = $this->actingAs($dsnLain['user'], 'sanctum')->getJson('/api/bimbingan');
        $resIndex->assertStatus(200);
        $resIndex->assertJsonMissing(['catatan' => 'Catatan dengan Pembimbing Resmi']);

        // Dosen lain ditolak saat mengakses detail
        $this->actingAs($dsnLain['user'], 'sanctum')
            ->getJson('/api/bimbingan/' . $bimbingan->id_bimbingan)
            ->assertStatus(403);

        // Dosen lain ditolak saat mencoba update
        $this->actingAs($dsnLain['user'], 'sanctum')
            ->putJson('/api/bimbingan/' . $bimbingan->id_bimbingan, [
                'catatan' => 'Catatan liar dari dosen bukan pembimbing',
            ])
            ->assertStatus(403);

        // Dosen lain ditolak saat mencoba menghapus
        $this->actingAs($dsnLain['user'], 'sanctum')
            ->deleteJson('/api/bimbingan/' . $bimbingan->id_bimbingan)
            ->assertStatus(403);
    }

    // -----------------------------------------------------------------------
    // SKENARIO 7: CEGAH PEMALSUAN FOREIGN KEY (TAMPERING ID_SKRIPSI)
    // -----------------------------------------------------------------------

    public function test_7_pengguna_tidak_dapat_memalsukan_foreign_key_untuk_membuat_catatan_bagi_skripsi_lain(): void
    {
        $mhsA = $this->buatMahasiswa();
        $mhsB = $this->buatMahasiswa();
        $dsn = $this->buatDosen();

        $skripsiA = $this->buatSkripsi($mhsA['mahasiswa']);
        $skripsiB = $this->buatSkripsi($mhsB['mahasiswa']);

        DosenPembimbing::create(['id_skripsi' => $skripsiA->id_skripsi, 'id_dosen' => $dsn['dosen']->id_dosen]);
        DosenPembimbing::create(['id_skripsi' => $skripsiB->id_skripsi, 'id_dosen' => $dsn['dosen']->id_dosen]);

        // Mhs A mencoba membuat bimbingan dengan menyuntikkan id_skripsi milik Mhs B
        $resTamperCreate = $this->actingAs($mhsA['user'], 'sanctum')->postJson('/api/bimbingan', [
            'id_skripsi' => $skripsiB->id_skripsi,
            'tanggal'    => '2026-10-25',
            'catatan'    => 'Catatan Palsu untuk Mhs B',
        ]);
        $resTamperCreate->assertStatus(403);

        // Buat bimbingan sah milik Mhs A
        $bimbinganA = Bimbingan::create([
            'id_skripsi' => $skripsiA->id_skripsi,
            'tanggal'    => '2026-10-25',
            'catatan'    => 'Bimbingan Sah Mhs A',
        ]);

        // Mhs A mencoba mengupdate bimbingan miliknya tapi mengalihkan foreign key ke skripsi B
        $resTamperUpdate = $this->actingAs($mhsA['user'], 'sanctum')->putJson('/api/bimbingan/' . $bimbinganA->id_bimbingan, [
            'id_skripsi' => $skripsiB->id_skripsi,
            'catatan'    => 'Coba alihkan ke Skripsi B',
        ]);
        $resTamperUpdate->assertStatus(403);
    }

    // -----------------------------------------------------------------------
    // SKENARIO 8: ADMIN TETAP DAPAT MELAKUKAN OPERASI PENUGASAN YANG SAH
    // -----------------------------------------------------------------------

    public function test_8_admin_tetap_dapat_melakukan_operasi_penugasan_yang_sah(): void
    {
        $admin = $this->buatAdmin();
        $mhs = $this->buatMahasiswa();
        $dsn1 = $this->buatDosen();
        $dsn2 = $this->buatDosen();
        $skripsi = $this->buatSkripsi($mhs['mahasiswa']);

        // Admin menetapkan pembimbing 1
        $resStore = $this->actingAs($admin['user'], 'sanctum')->postJson('/api/dosen-pembimbing', [
            'id_skripsi' => $skripsi->id_skripsi,
            'id_dosen'   => $dsn1['dosen']->id_dosen,
        ]);
        $resStore->assertStatus(201);
        $resStore->assertJsonFragment(['message' => 'Dosen Pembimbing berhasil ditetapkan']);
        $dpId = $resStore->json('data.id_pembimbing');

        // Admin melihat list
        $resIndex = $this->actingAs($admin['user'], 'sanctum')->getJson('/api/dosen-pembimbing');
        $resIndex->assertStatus(200);

        // Admin melihat detail
        $resShow = $this->actingAs($admin['user'], 'sanctum')->getJson('/api/dosen-pembimbing/' . $dpId);
        $resShow->assertStatus(200);

        // Admin mengupdate dosen pembimbing menjadi dsn2
        $resUpdate = $this->actingAs($admin['user'], 'sanctum')->putJson('/api/dosen-pembimbing/' . $dpId, [
            'id_dosen' => $dsn2['dosen']->id_dosen,
        ]);
        $resUpdate->assertStatus(200);
        $resUpdate->assertJsonFragment(['message' => 'Data Dosen Pembimbing berhasil diperbarui']);

        // Admin menghapus penugasan
        $resDelete = $this->actingAs($admin['user'], 'sanctum')->deleteJson('/api/dosen-pembimbing/' . $dpId);
        $resDelete->assertStatus(200);
        $this->assertDatabaseMissing('dosen_pembimbing', ['id_pembimbing' => $dpId]);
    }

    // -----------------------------------------------------------------------
    // SKENARIO 9: OPERASI SAH BIMBINGAN OLEH MAHASISWA DAN DOSEN PEMBIMBING
    // -----------------------------------------------------------------------

    public function test_9_operasi_sah_bimbingan_oleh_mahasiswa_dan_dosen_pembimbing_berjalan(): void
    {
        $mhs = $this->buatMahasiswa();
        $dsn = $this->buatDosen();
        $skripsi = $this->buatSkripsi($mhs['mahasiswa']);
        DosenPembimbing::create(['id_skripsi' => $skripsi->id_skripsi, 'id_dosen' => $dsn['dosen']->id_dosen]);

        // Mahasiswa menambahkan log bimbingan sah
        $resStore = $this->actingAs($mhs['user'], 'sanctum')->postJson('/api/bimbingan', [
            'id_skripsi' => $skripsi->id_skripsi,
            'tanggal'    => '2026-10-25',
            'catatan'    => 'Bimbingan Bab 1 dan 2 selesai disusun.',
        ]);
        $resStore->assertStatus(201);
        $bimbinganId = $resStore->json('data.id_bimbingan');

        // Mahasiswa melihat detail bimbingan sah miliknya
        $this->actingAs($mhs['user'], 'sanctum')
            ->getJson('/api/bimbingan/' . $bimbinganId)
            ->assertStatus(200)
            ->assertJsonFragment(['catatan' => 'Bimbingan Bab 1 dan 2 selesai disusun.']);

        // Dosen pembimbing melihat detail dan memberikan catatan revisi pada bimbingan mahasiswa binaannya
        $resDosenShow = $this->actingAs($dsn['user'], 'sanctum')->getJson('/api/bimbingan/' . $bimbinganId);
        $resDosenShow->assertStatus(200);

        $resDosenUpdate = $this->actingAs($dsn['user'], 'sanctum')->putJson('/api/bimbingan/' . $bimbinganId, [
            'catatan' => 'ACC Bab 1. Untuk Bab 2 tambahkan kajian pustaka 5 tahun terakhir.',
        ]);
        $resDosenUpdate->assertStatus(200);
        $this->assertDatabaseHas('bimbingan', [
            'id_bimbingan' => $bimbinganId,
            'catatan'      => 'ACC Bab 1. Untuk Bab 2 tambahkan kajian pustaka 5 tahun terakhir.',
        ]);
    }

    // -----------------------------------------------------------------------
    // SKENARIO 10: VALIDASI PAYLOAD TIDAK SAH & ATURAN BISNIS
    // -----------------------------------------------------------------------

    public function test_10_validasi_payload_tidak_sah_menghasilkan_respons_yang_sesuai(): void
    {
        $admin = $this->buatAdmin();
        $mhs = $this->buatMahasiswa();
        $dsn = $this->buatDosen();
        $skripsi = $this->buatSkripsi($mhs['mahasiswa']);

        // Payload kosong saat admin menetapkan pembimbing
        $this->actingAs($admin['user'], 'sanctum')
            ->postJson('/api/dosen-pembimbing', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['id_skripsi', 'id_dosen']);

        // Penugasan pertama berhasil
        $this->actingAs($admin['user'], 'sanctum')
            ->postJson('/api/dosen-pembimbing', [
                'id_skripsi' => $skripsi->id_skripsi,
                'id_dosen'   => $dsn['dosen']->id_dosen,
            ])
            ->assertStatus(201);

        // Penugasan dosen yang SAMA pada skripsi yang SAMA (duplikasi) ditolak 422
        $this->actingAs($admin['user'], 'sanctum')
            ->postJson('/api/dosen-pembimbing', [
                'id_skripsi' => $skripsi->id_skripsi,
                'id_dosen'   => $dsn['dosen']->id_dosen,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['id_dosen']);

        // Mahasiswa mencoba membuat bimbingan pada skripsi yang BELUM memiliki pembimbing
        $mhsTanpaDospem = $this->buatMahasiswa();
        $skripsiTanpaDospem = $this->buatSkripsi($mhsTanpaDospem['mahasiswa']);

        $resTanpaDospem = $this->actingAs($mhsTanpaDospem['user'], 'sanctum')->postJson('/api/bimbingan', [
            'id_skripsi' => $skripsiTanpaDospem->id_skripsi,
            'tanggal'    => '2026-10-25',
            'catatan'    => 'Mencoba bimbingan tanpa pembimbing',
        ]);
        $resTanpaDospem->assertStatus(422);
    }

    // -----------------------------------------------------------------------
    // SKENARIO 11: PENGAMANAN TIDAK MERUSAK WORKFLOW KAPRODI / PENGAJUAN JUDUL
    // -----------------------------------------------------------------------

    public function test_11_pengamanan_tidak_merusak_endpoint_pengajuan_judul_atau_workflow_kaprodi(): void
    {
        $prodi = 'Pendidikan Bahasa Arab';
        PenugasanKaprodi::where('prodi', $prodi)->delete();

        $mhs = $this->buatMahasiswa(null, $prodi);
        $dsnKaprodi = $this->buatDosen();
        $dsnPA = $this->buatDosen();

        // Berikan penugasan Kaprodi aktif pada prodi bersih
        PenugasanKaprodi::create([
            'dosen_id'      => $dsnKaprodi['dosen']->id_dosen,
            'prodi'         => $prodi,
            'tanggal_mulai' => now()->subMonth()->toDateString(),
            'is_aktif'      => true,
        ]);

        $skripsi = $this->buatSkripsi($mhs['mahasiswa'], 'diajukan');
        DosenPA::create(['id_dosen' => $dsnPA['dosen']->id_dosen, 'id_skripsi' => $skripsi->id_skripsi]);

        PengajuanJudul::create([
            'skripsi_id'   => $skripsi->id_skripsi,
            'mahasiswa_id' => $mhs['mahasiswa']->id_mahasiswa,
            'dosen_pa_id'  => $dsnPA['user']->id,
            'judul'        => 'Analisis Sentimen Menggunakan Transformer',
            'deskripsi'    => 'Penelitian analisis sentimen',
            'nama_file'    => 'proposal.pdf',
            'ukuran_file'  => '200 KB',
            'file_path'    => 'pengajuan-judul/proposal.pdf',
            'status'       => 'Menunggu Persetujuan Kaprodi',
        ]);

        // Kaprodi melihat antrean
        $resAntrean = $this->actingAs($dsnKaprodi['user'], 'sanctum')->getJson('/api/kaprodi/pengajuan-judul');
        $resAntrean->assertStatus(200);
        $resAntrean->assertJsonFragment(['judul' => 'Analisis Sentimen Menggunakan Transformer']);

        // Mahasiswa melihat riwayat pengajuan judul miliknya
        $resMhs = $this->actingAs($mhs['user'], 'sanctum')->getJson('/api/mahasiswa/pengajuan-judul');
        $resMhs->assertStatus(200);
        $resMhs->assertJsonFragment(['judul' => 'Analisis Sentimen Menggunakan Transformer']);
    }
}
