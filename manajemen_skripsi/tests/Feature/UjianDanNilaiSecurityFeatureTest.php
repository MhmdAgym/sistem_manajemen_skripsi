<?php

namespace Tests\Feature;

use App\Constants\AcademicConstant;
use App\Models\Admin;
use App\Models\Dosen;
use App\Models\DosenPengujiSkripsi;
use App\Models\Mahasiswa;
use App\Models\NilaiSempro;
use App\Models\NilaiSkripsi;
use App\Models\PengujiSempro;
use App\Models\Sempro;
use App\Models\Skripsi;
use App\Models\UjianSkripsi;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class UjianDanNilaiSecurityFeatureTest extends TestCase
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
            'judul'        => 'Rancang Bangun Sistem Keamanan API UNUHA',
            'status_judul' => $statusJudul,
        ]);
    }

    private function buatSempro(Skripsi $skripsi, string $status = 'lulus'): Sempro
    {
        return Sempro::create([
            'id_skripsi'     => $skripsi->id_skripsi,
            'tanggal_sempro' => now()->toDateString(),
            'status_sempro'  => $status,
        ]);
    }

    private function buatUjianSkripsi(Sempro $sempro, string $status = 'tidak lulus'): UjianSkripsi
    {
        return UjianSkripsi::create([
            'id_sempro'     => $sempro->id_sempro,
            'tanggal_ujian' => now()->addDays(7)->toDateString(),
            'status_ujian'  => $status,
        ]);
    }

    // -----------------------------------------------------------------------
    // TEST 1: GUEST TIDAK DAPAT MENGAKSES ENDPOINT NILAI & UJIAN
    // -----------------------------------------------------------------------

    public function test_1_guest_tidak_dapat_mengakses_endpoint_sempro_ujian_dan_nilai(): void
    {
        $endpoints = [
            'api/sempro',
            'api/penguji-sempro',
            'api/nilai-sempro',
            'api/ujian-skripsi',
            'api/dosen-penguji-skripsi',
            'api/nilai-skripsi',
        ];

        foreach ($endpoints as $endpoint) {
            $this->getJson($endpoint)->assertStatus(401);
            $this->postJson($endpoint, [])->assertStatus(401);
        }
    }

    // -----------------------------------------------------------------------
    // TEST 2: MAHASISWA TIDAK DAPAT MENGINPUT ATAU MENGUBAH NILAI SEMPRO / SKRIPSI
    // -----------------------------------------------------------------------

    public function test_2_mahasiswa_ditolak_saat_mencoba_input_atau_ubah_nilai(): void
    {
        $mhs = $this->buatMahasiswa();
        $dsn = $this->buatDosen();
        $skripsi = $this->buatSkripsi($mhs['mahasiswa']);
        $sempro = $this->buatSempro($skripsi, 'lulus');
        $pengujiSempro = PengujiSempro::create([
            'id_dosen'  => $dsn['dosen']->id_dosen,
            'id_sempro' => $sempro->id_sempro,
            'peran'     => 'penguji 1',
        ]);
        $ujian = $this->buatUjianSkripsi($sempro);
        $pengujiUjian = DosenPengujiSkripsi::create([
            'id_dosen'         => $dsn['dosen']->id_dosen,
            'id_ujian_skripsi' => $ujian->id_ujian_skripsi,
            'peran'            => 'ketua',
        ]);

        // Mahasiswa mencoba menginput nilai sempro
        $this->actingAs($mhs['user'], 'sanctum')
            ->postJson('/api/nilai-sempro', [
                'id_sempro'         => $sempro->id_sempro,
                'id_penguji_sempro' => $pengujiSempro->id_penguji_sempro,
                'tanggal_ujian'     => now()->toDateString(),
                'status_ujian'      => 'lulus',
            ])
            ->assertStatus(403);

        // Mahasiswa mencoba menginput nilai sidang skripsi
        $this->actingAs($mhs['user'], 'sanctum')
            ->postJson('/api/nilai-skripsi', [
                'id_ujian_skripsi' => $ujian->id_ujian_skripsi,
                'id_dosen'         => $dsn['dosen']->id_dosen,
                'nilai'            => 95.5,
                'catatan'          => 'Nilai palsu dari mahasiswa',
            ])
            ->assertStatus(403);
    }

    // -----------------------------------------------------------------------
    // TEST 3: DOSEN BUKAN PENGUJI TIDAK DAPAT MENGINPUT NILAI SIDANG SKRIPSI
    // -----------------------------------------------------------------------

    public function test_3_dosen_yang_bukan_penguji_ditugaskan_ditolak_menginput_nilai_skripsi(): void
    {
        $mhs = $this->buatMahasiswa();
        $dsnPenguji = $this->buatDosen();
        $dsnBukanPenguji = $this->buatDosen();

        $skripsi = $this->buatSkripsi($mhs['mahasiswa']);
        $sempro = $this->buatSempro($skripsi, 'lulus');
        $ujian = $this->buatUjianSkripsi($sempro);

        DosenPengujiSkripsi::create([
            'id_dosen'         => $dsnPenguji['dosen']->id_dosen,
            'id_ujian_skripsi' => $ujian->id_ujian_skripsi,
            'peran'            => 'ketua',
        ]);

        // Dosen yang bukan penguji mencoba menginput nilai
        $res = $this->actingAs($dsnBukanPenguji['user'], 'sanctum')->postJson('/api/nilai-skripsi', [
            'id_ujian_skripsi' => $ujian->id_ujian_skripsi,
            'id_dosen'         => $dsnBukanPenguji['dosen']->id_dosen,
            'nilai'            => 88.0,
            'catatan'          => 'Nilai ilegal',
        ]);
        $res->assertStatus(403);
    }

    // -----------------------------------------------------------------------
    // TEST 4: DOSEN TIDAK DAPAT MEMALSUKAN ID_DOSEN ORANG LAIN SAAT INPUT NILAI
    // -----------------------------------------------------------------------

    public function test_4_dosen_tidak_dapat_memalsukan_id_dosen_orang_lain_saat_input_nilai(): void
    {
        $mhs = $this->buatMahasiswa();
        $dsnA = $this->buatDosen();
        $dsnB = $this->buatDosen();

        $skripsi = $this->buatSkripsi($mhs['mahasiswa']);
        $sempro = $this->buatSempro($skripsi, 'lulus');
        $ujian = $this->buatUjianSkripsi($sempro);

        DosenPengujiSkripsi::create([
            'id_dosen'         => $dsnB['dosen']->id_dosen,
            'id_ujian_skripsi' => $ujian->id_ujian_skripsi,
            'peran'            => 'ketua',
        ]);

        // Dosen A login, tetapi mengirimkan id_dosen milik Dosen B
        $res = $this->actingAs($dsnA['user'], 'sanctum')->postJson('/api/nilai-skripsi', [
            'id_ujian_skripsi' => $ujian->id_ujian_skripsi,
            'id_dosen'         => $dsnB['dosen']->id_dosen,
            'nilai'            => 90.0,
        ]);
        $res->assertStatus(403);
    }

    // -----------------------------------------------------------------------
    // TEST 5: ISOLASI DATA NILAI SKRIPSI ANTAR-MAHASISWA (IDOR CHECK)
    // -----------------------------------------------------------------------

    public function test_5_mahasiswa_a_tidak_dapat_melihat_nilai_skripsi_mahasiswa_b(): void
    {
        $mhsA = $this->buatMahasiswa();
        $mhsB = $this->buatMahasiswa();
        $dsn = $this->buatDosen();

        $skripsiA = $this->buatSkripsi($mhsA['mahasiswa']);
        $skripsiB = $this->buatSkripsi($mhsB['mahasiswa']);

        $semproA = $this->buatSempro($skripsiA, 'lulus');
        $semproB = $this->buatSempro($skripsiB, 'lulus');

        $ujianA = $this->buatUjianSkripsi($semproA);
        $ujianB = $this->buatUjianSkripsi($semproB);

        DosenPengujiSkripsi::create(['id_dosen' => $dsn['dosen']->id_dosen, 'id_ujian_skripsi' => $ujianA->id_ujian_skripsi, 'peran' => 'ketua']);
        DosenPengujiSkripsi::create(['id_dosen' => $dsn['dosen']->id_dosen, 'id_ujian_skripsi' => $ujianB->id_ujian_skripsi, 'peran' => 'ketua']);

        $nilaiB = NilaiSkripsi::create([
            'id_ujian_skripsi' => $ujianB->id_ujian_skripsi,
            'id_dosen'         => $dsn['dosen']->id_dosen,
            'nilai'            => 98.0,
            'catatan'          => 'Nilai Rahasia Mhs B',
        ]);

        // Mahasiswa A memanggil index -> nilai Mahasiswa B tidak boleh muncul
        $resIndex = $this->actingAs($mhsA['user'], 'sanctum')->getJson('/api/nilai-skripsi');
        $resIndex->assertStatus(200);
        $resIndex->assertJsonMissing(['catatan' => 'Nilai Rahasia Mhs B']);

        // Mahasiswa A memanggil show detail nilai Mahasiswa B -> 403 Forbidden
        $this->actingAs($mhsA['user'], 'sanctum')
            ->getJson('/api/nilai-skripsi/' . $nilaiB->id_nilai)
            ->assertStatus(403);
    }

    // -----------------------------------------------------------------------
    // TEST 6: MAHASISWA TIDAK DAPAT MEMUTASI STATUS SEMPRO MENJADI LULUS
    // -----------------------------------------------------------------------

    public function test_6_mahasiswa_mendaftar_sempro_status_otomatis_tidak_lulus_dan_tidak_bisa_update(): void
    {
        $mhs = $this->buatMahasiswa();
        $skripsi = $this->buatSkripsi($mhs['mahasiswa']);

        // Mahasiswa mendaftar sempro dan mencoba menyuntikkan status_sempro = 'lulus'
        $res = $this->actingAs($mhs['user'], 'sanctum')->postJson('/api/sempro', [
            'id_skripsi'     => $skripsi->id_skripsi,
            'tanggal_sempro' => now()->toDateString(),
            'status_sempro'  => 'lulus',
        ]);
        $res->assertStatus(201);

        // Server harus memaksakan status awal tetap 'tidak lulus'
        $semproId = $res->json('data.id_sempro');
        $this->assertDatabaseHas('sempro', [
            'id_sempro'     => $semproId,
            'status_sempro' => 'tidak lulus',
        ]);

        // Mahasiswa mencoba mengupdate sempro miliknya menjadi lulus
        $this->actingAs($mhs['user'], 'sanctum')
            ->putJson('/api/sempro/' . $semproId, [
                'status_sempro' => 'lulus',
            ])
            ->assertStatus(403);
    }

    // -----------------------------------------------------------------------
    // TEST 7: MAHASISWA TIDAK DAPAT MENDAFTARKAN SEMPRO UNTUK SKRIPSI MILIK ORANG LAIN
    // -----------------------------------------------------------------------

    public function test_7_mahasiswa_tidak_dapat_mendaftarkan_sempro_untuk_skripsi_orang_lain(): void
    {
        $mhsA = $this->buatMahasiswa();
        $mhsB = $this->buatMahasiswa();
        $skripsiB = $this->buatSkripsi($mhsB['mahasiswa']);

        $res = $this->actingAs($mhsA['user'], 'sanctum')->postJson('/api/sempro', [
            'id_skripsi'     => $skripsiB->id_skripsi,
            'tanggal_sempro' => now()->toDateString(),
        ]);
        $res->assertStatus(403);
    }

    // -----------------------------------------------------------------------
    // TEST 8: UJIAN SKRIPSI DITOLAK JIKA STATUS SEMPRO BELUM LULUS
    // -----------------------------------------------------------------------

    public function test_8_penjadwalan_ujian_skripsi_ditolak_jika_sempro_belum_lulus(): void
    {
        $admin = $this->buatAdmin();
        $mhs = $this->buatMahasiswa();
        $skripsi = $this->buatSkripsi($mhs['mahasiswa']);
        $semproBelumLulus = $this->buatSempro($skripsi, 'tidak lulus');

        // Admin mencoba menjadwalkan sidang untuk sempro yang belum lulus
        $res = $this->actingAs($admin['user'], 'sanctum')->postJson('/api/ujian-skripsi', [
            'id_sempro'     => $semproBelumLulus->id_sempro,
            'tanggal_ujian' => now()->addDays(5)->toDateString(),
        ]);
        $res->assertStatus(422);
        $res->assertJsonValidationErrors(['id_sempro']);
    }

    // -----------------------------------------------------------------------
    // TEST 9: ADMIN DAPAT MENETAPKAN PENGUJI DAN MENJADWALKAN UJIAN SECARA SAH
    // -----------------------------------------------------------------------

    public function test_9_admin_dapat_menetapkan_penguji_dan_ujian_skripsi_sah(): void
    {
        $admin = $this->buatAdmin();
        $mhs = $this->buatMahasiswa();
        $dsn1 = $this->buatDosen();
        $dsn2 = $this->buatDosen();
        $skripsi = $this->buatSkripsi($mhs['mahasiswa']);
        $sempro = $this->buatSempro($skripsi, 'lulus');

        // Admin menjadwalkan ujian skripsi
        $resUjian = $this->actingAs($admin['user'], 'sanctum')->postJson('/api/ujian-skripsi', [
            'id_sempro'     => $sempro->id_sempro,
            'tanggal_ujian' => now()->addDays(10)->toDateString(),
        ]);
        $resUjian->assertStatus(201);
        $ujianId = $resUjian->json('data.id_ujian_skripsi');

        // Admin menetapkan Penguji 1 (Ketua)
        $resP1 = $this->actingAs($admin['user'], 'sanctum')->postJson('/api/dosen-penguji-skripsi', [
            'id_dosen'         => $dsn1['dosen']->id_dosen,
            'id_ujian_skripsi' => $ujianId,
            'peran'            => 'ketua',
        ]);
        $resP1->assertStatus(201);

        // Admin menetapkan Penguji 2 (Anggota)
        $resP2 = $this->actingAs($admin['user'], 'sanctum')->postJson('/api/dosen-penguji-skripsi', [
            'id_dosen'         => $dsn2['dosen']->id_dosen,
            'id_ujian_skripsi' => $ujianId,
            'peran'            => 'anggota',
        ]);
        $resP2->assertStatus(201);

        // Pencegahan penetapan dosen yang sama dua kali pada ujian yang sama
        $resP1Duplikat = $this->actingAs($admin['user'], 'sanctum')->postJson('/api/dosen-penguji-skripsi', [
            'id_dosen'         => $dsn1['dosen']->id_dosen,
            'id_ujian_skripsi' => $ujianId,
            'peran'            => 'anggota',
        ]);
        $resP1Duplikat->assertStatus(422);
    }

    // -----------------------------------------------------------------------
    // TEST 10: PENGUJI SAH DAPAT MENGINPUT DAN MEMPERBARUI NILAI SKRIPSI
    // -----------------------------------------------------------------------

    public function test_10_penguji_sah_dapat_input_dan_perbarui_nilai_skripsi(): void
    {
        $mhs = $this->buatMahasiswa();
        $dsn = $this->buatDosen();
        $skripsi = $this->buatSkripsi($mhs['mahasiswa']);
        $sempro = $this->buatSempro($skripsi, 'lulus');
        $ujian = $this->buatUjianSkripsi($sempro);

        DosenPengujiSkripsi::create([
            'id_dosen'         => $dsn['dosen']->id_dosen,
            'id_ujian_skripsi' => $ujian->id_ujian_skripsi,
            'peran'            => 'ketua',
        ]);

        // Dosen penguji sah menginput nilai
        $resInput = $this->actingAs($dsn['user'], 'sanctum')->postJson('/api/nilai-skripsi', [
            'id_ujian_skripsi' => $ujian->id_ujian_skripsi,
            'id_dosen'         => $dsn['dosen']->id_dosen,
            'nilai'            => 85.5,
            'catatan'          => 'Pemaparan baik, perbaiki format sitasi.',
        ]);
        $resInput->assertStatus(201);
        $nilaiId = $resInput->json('data.id_nilai');

        // Dosen penguji sah memperbarui nilai
        $resUpdate = $this->actingAs($dsn['user'], 'sanctum')->putJson('/api/nilai-skripsi/' . $nilaiId, [
            'nilai'   => 87.0,
            'catatan' => 'Perbaikan sitasi telah selesai.',
        ]);
        $resUpdate->assertStatus(200);

        $this->assertDatabaseHas('nilai_skripsi', [
            'id_nilai' => $nilaiId,
            'nilai'    => 87.0,
            'catatan'  => 'Perbaikan sitasi telah selesai.',
        ]);

        // Mahasiswa bersangkutan dapat melihat nilai sah miliknya
        $resMhsShow = $this->actingAs($mhs['user'], 'sanctum')->getJson('/api/nilai-skripsi/' . $nilaiId);
        $resMhsShow->assertStatus(200);
        $resMhsShow->assertJsonFragment(['catatan' => 'Perbaikan sitasi telah selesai.']);
    }

    // -----------------------------------------------------------------------
    // TEST 11: VALIDASI BOUNDARY NILAI ANGKA (MIN:0, MAX:100)
    // -----------------------------------------------------------------------

    public function test_11_validasi_rentang_nilai_skripsi_ditolak_jika_di_luar_0_sampai_100(): void
    {
        $mhs = $this->buatMahasiswa();
        $dsn = $this->buatDosen();
        $skripsi = $this->buatSkripsi($mhs['mahasiswa']);
        $sempro = $this->buatSempro($skripsi, 'lulus');
        $ujian = $this->buatUjianSkripsi($sempro);

        DosenPengujiSkripsi::create([
            'id_dosen'         => $dsn['dosen']->id_dosen,
            'id_ujian_skripsi' => $ujian->id_ujian_skripsi,
            'peran'            => 'ketua',
        ]);

        // Nilai negatif
        $this->actingAs($dsn['user'], 'sanctum')->postJson('/api/nilai-skripsi', [
            'id_ujian_skripsi' => $ujian->id_ujian_skripsi,
            'id_dosen'         => $dsn['dosen']->id_dosen,
            'nilai'            => -5.0,
        ])->assertStatus(422)->assertJsonValidationErrors(['nilai']);

        // Nilai melebihi 100
        $this->actingAs($dsn['user'], 'sanctum')->postJson('/api/nilai-skripsi', [
            'id_ujian_skripsi' => $ujian->id_ujian_skripsi,
            'id_dosen'         => $dsn['dosen']->id_dosen,
            'nilai'            => 105.0,
        ])->assertStatus(422)->assertJsonValidationErrors(['nilai']);
    }

    // -----------------------------------------------------------------------
    // TEST 12: DOSEN TIDAK DAPAT MENGUBAH FOREIGN KEY SAAT UPDATE NILAI SKRIPSI
    // -----------------------------------------------------------------------

    public function test_12_dosen_tidak_dapat_mengubah_foreign_key_saat_update_nilai(): void
    {
        $mhsA = $this->buatMahasiswa();
        $mhsB = $this->buatMahasiswa();
        $dsn = $this->buatDosen();

        $skripsiA = $this->buatSkripsi($mhsA['mahasiswa']);
        $skripsiB = $this->buatSkripsi($mhsB['mahasiswa']);

        $semproA = $this->buatSempro($skripsiA, 'lulus');
        $semproB = $this->buatSempro($skripsiB, 'lulus');

        $ujianA = $this->buatUjianSkripsi($semproA);
        $ujianB = $this->buatUjianSkripsi($semproB);

        DosenPengujiSkripsi::create(['id_dosen' => $dsn['dosen']->id_dosen, 'id_ujian_skripsi' => $ujianA->id_ujian_skripsi, 'peran' => 'ketua']);
        DosenPengujiSkripsi::create(['id_dosen' => $dsn['dosen']->id_dosen, 'id_ujian_skripsi' => $ujianB->id_ujian_skripsi, 'peran' => 'ketua']);

        $nilaiA = NilaiSkripsi::create([
            'id_ujian_skripsi' => $ujianA->id_ujian_skripsi,
            'id_dosen'         => $dsn['dosen']->id_dosen,
            'nilai'            => 80.0,
        ]);

        // Dosen mencoba mengalihkan nilai ke Ujian B
        $res = $this->actingAs($dsn['user'], 'sanctum')->putJson('/api/nilai-skripsi/' . $nilaiA->id_nilai, [
            'id_ujian_skripsi' => $ujianB->id_ujian_skripsi,
            'nilai'            => 82.0,
        ]);
        $res->assertStatus(403);
    }
}
