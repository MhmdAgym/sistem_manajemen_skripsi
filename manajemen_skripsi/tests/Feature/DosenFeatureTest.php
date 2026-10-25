<?php

namespace Tests\Feature;

use App\Models\Dosen;
use App\Models\DosenPA;
use App\Models\DosenPembimbing;
use App\Models\Mahasiswa;
use App\Models\Skripsi;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class DosenFeatureTest extends TestCase
{
    use DatabaseTransactions;

    public function test_can_list_dosen_with_bimbingan_count(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $response = $this->actingAs($admin, 'sanctum')->getJson('/api/dosen');

        $response->assertStatus(200);
        $response->assertJsonStructure([
            '*' => [
                'id_dosen',
                'NIDN',
                'nama_dosen',
                'no_handphone',
                'jenis_kelamin',
                'total_mahasiswa_bimbingan',
                'mahasiswa_bimbingan',
                'user',
            ]
        ]);
    }

    public function test_dosen_without_bimbingan_returns_zero_count(): void
    {
        $nidn = (string) rand(1000000000, 9999999999);
        $user = User::factory()->create(['role' => 'dosen', 'nomor_induk' => $nidn]);
        $dosen = Dosen::create([
            'user_id' => $user->id,
            'NIDN' => $nidn,
            'nama_dosen' => 'Dosen Baru Belum Ada Bimbingan',
        ]);

        $response = $this->actingAs($user, 'sanctum')->getJson("/api/dosen/{$dosen->id_dosen}");

        $response->assertStatus(200);
        $response->assertJson([
            'id_dosen' => $dosen->id_dosen,
            'total_mahasiswa_bimbingan' => 0,
            'mahasiswa_bimbingan' => [],
        ]);
    }

    public function test_dosen_bimbingan_counts_unique_students_and_ignores_pa(): void
    {
        $nidn = (string) rand(1000000000, 9999999999);
        $nim1 = (string) rand(1000000000, 9999999999);
        $nim2 = (string) rand(1000000000, 9999999999);

        $userDosen = User::factory()->create(['role' => 'dosen', 'nomor_induk' => $nidn]);
        $dosen = Dosen::create([
            'user_id' => $userDosen->id,
            'NIDN' => $nidn,
            'nama_dosen' => 'Dr. Bimbingan Murni',
        ]);

        // Mahasiswa 1
        $userMhs1 = User::factory()->create(['role' => 'mahasiswa', 'nomor_induk' => $nim1]);
        $mhs1 = Mahasiswa::create([
            'user_id' => $userMhs1->id,
            'NIM' => $nim1,
            'nama_mahasiswa' => 'Mahasiswa Bimbingan 1',
            'prodi' => 'Informatika',
            'fakultas' => 'Fakultas Sains dan Teknologi',
        ]);
        $skripsi1 = Skripsi::create([
            'id_mahasiswa' => $mhs1->id_mahasiswa,
            'judul' => 'Judul Skripsi 1',
            'status_judul' => 'disetujui',
        ]);
        // Dosen menjadi pembimbing Mahasiswa 1
        DosenPembimbing::create([
            'id_dosen' => $dosen->id_dosen,
            'id_skripsi' => $skripsi1->id_skripsi,
        ]);

        // Skripsi ke-2 untuk mahasiswa yang sama (skenario duplikasi jika ada multiple skripsi)
        $skripsi1b = Skripsi::create([
            'id_mahasiswa' => $mhs1->id_mahasiswa,
            'judul' => 'Judul Skripsi 1 Revisi',
            'status_judul' => 'disetujui',
        ]);
        DosenPembimbing::create([
            'id_dosen' => $dosen->id_dosen,
            'id_skripsi' => $skripsi1b->id_skripsi,
        ]);

        // Mahasiswa 2 (hanya Dosen PA, BUKAN dosen pembimbing)
        $userMhs2 = User::factory()->create(['role' => 'mahasiswa', 'nomor_induk' => $nim2]);
        $mhs2 = Mahasiswa::create([
            'user_id' => $userMhs2->id,
            'NIM' => $nim2,
            'nama_mahasiswa' => 'Mahasiswa Hanya PA',
            'prodi' => 'Sistem Informasi',
        ]);
        $skripsi2 = Skripsi::create([
            'id_mahasiswa' => $mhs2->id_mahasiswa,
            'judul' => 'Judul Skripsi PA Saja',
            'status_judul' => 'diajukan',
        ]);
        // Dosen ini hanya Dosen PA untuk skripsi2, BUKAN DosenPembimbing
        DosenPA::create([
            'id_dosen' => $dosen->id_dosen,
            'id_skripsi' => $skripsi2->id_skripsi,
        ]);

        $response = $this->actingAs($userDosen, 'sanctum')->getJson("/api/dosen/{$dosen->id_dosen}");

        $response->assertStatus(200);
        // Pastikan jumlah mahasiswa bimbingan adalah 1 (unik), dan Mahasiswa 2 (PA) TIDAK dihitung
        $response->assertJson([
            'id_dosen' => $dosen->id_dosen,
            'total_mahasiswa_bimbingan' => 1,
        ]);

        $data = $response->json();
        $this->assertCount(1, $data['mahasiswa_bimbingan']);
        $this->assertEquals($mhs1->id_mahasiswa, $data['mahasiswa_bimbingan'][0]['id_mahasiswa']);
        $this->assertEquals('Mahasiswa Bimbingan 1', $data['mahasiswa_bimbingan'][0]['nama_mahasiswa']);
    }

    public function test_can_update_dosen_and_sync_with_user(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $nidn1 = (string) rand(1000000000, 9999999999);
        $nidn2 = (string) rand(1000000000, 9999999999);

        $user = User::factory()->create([
            'name' => 'Original Dosen',
            'role' => 'dosen',
            'nomor_induk' => $nidn1,
        ]);

        $dosen = Dosen::create([
            'user_id' => $user->id,
            'NIDN' => $nidn1,
            'nama_dosen' => 'Original Dosen',
        ]);

        $response = $this->actingAs($admin, 'sanctum')->putJson("/api/dosen/{$dosen->id_dosen}", [
            'nama_dosen' => 'Dr. Dosen Terupdate, M.Kom.',
            'NIDN' => $nidn2,
            'no_handphone' => '081234567899',
            'jenis_kelamin' => 'Laki-laki',
        ]);

        $response->assertStatus(200);
        $this->assertDatabaseHas('dosen', [
            'id_dosen' => $dosen->id_dosen,
            'nama_dosen' => 'Dr. Dosen Terupdate, M.Kom.',
            'NIDN' => $nidn2,
            'no_handphone' => '081234567899',
            'jenis_kelamin' => 'Laki-laki',
        ]);

        // Verifikasi sinkronisasi ke tabel users
        $this->assertDatabaseHas('users', [
            'id' => $user->id,
            'name' => 'Dr. Dosen Terupdate, M.Kom.',
            'nomor_induk' => $nidn2,
        ]);
    }
}
