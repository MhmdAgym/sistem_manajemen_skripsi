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

class MahasiswaFeatureTest extends TestCase
{
    use DatabaseTransactions;

    public function test_can_list_mahasiswa_with_eager_relations(): void
    {
        $response = $this->getJson('/api/mahasiswa');

        $response->assertStatus(200);
        $response->assertJsonStructure([
            '*' => [
                'id_mahasiswa',
                'NIM',
                'nama_mahasiswa',
                'prodi',
                'fakultas',
                'no_handphone',
                'jenis_kelamin',
                'active_skripsi',
                'dosen_pa_list',
                'dosen_pembimbing_list',
                'user',
            ]
        ]);
    }

    public function test_mahasiswa_without_skripsi_returns_null_and_empty_arrays(): void
    {
        $user = User::factory()->create([
            'role' => 'mahasiswa',
            'nomor_induk' => '1111222233',
        ]);

        $mahasiswa = Mahasiswa::create([
            'user_id' => $user->id,
            'NIM' => '1111222233',
            'nama_mahasiswa' => 'Mahasiswa Tanpa Skripsi',
            'prodi' => 'Informatika',
        ]);

        $response = $this->getJson("/api/mahasiswa/{$mahasiswa->id_mahasiswa}");

        $response->assertStatus(200);
        $response->assertJson([
            'id_mahasiswa' => $mahasiswa->id_mahasiswa,
            'active_skripsi' => null,
            'dosen_pa_list' => [],
            'dosen_pembimbing_list' => [],
        ]);
    }

    public function test_can_show_mahasiswa_with_skripsi_pa_and_pembimbing(): void
    {
        $user = User::factory()->create([
            'role' => 'mahasiswa',
            'nomor_induk' => '9999999991',
        ]);

        $mahasiswa = Mahasiswa::create([
            'user_id' => $user->id,
            'NIM' => '9999999991',
            'nama_mahasiswa' => 'Mahasiswa Test Feature',
            'prodi' => 'Teknik Informatika',
            'fakultas' => 'Fakultas Sains dan Teknologi',
        ]);

        $userDosen1 = User::factory()->create(['role' => 'dosen', 'nomor_induk' => '8888888881']);
        $dosen1 = Dosen::create([
            'user_id' => $userDosen1->id,
            'NIDN' => '8888888881',
            'nama_dosen' => 'Dosen Pembimbing Akademik',
        ]);

        $userDosen2 = User::factory()->create(['role' => 'dosen', 'nomor_induk' => '8888888882']);
        $dosen2 = Dosen::create([
            'user_id' => $userDosen2->id,
            'NIDN' => '8888888882',
            'nama_dosen' => 'Dosen Pembimbing Skripsi',
        ]);

        $skripsi = Skripsi::create([
            'id_mahasiswa' => $mahasiswa->id_mahasiswa,
            'judul' => 'Implementasi Machine Learning untuk Prediksi Cuaca',
            'status_judul' => 'disetujui',
        ]);

        DosenPA::create([
            'id_dosen' => $dosen1->id_dosen,
            'id_skripsi' => $skripsi->id_skripsi,
        ]);

        DosenPembimbing::create([
            'id_dosen' => $dosen2->id_dosen,
            'id_skripsi' => $skripsi->id_skripsi,
        ]);

        $response = $this->getJson("/api/mahasiswa/{$mahasiswa->id_mahasiswa}");

        $response->assertStatus(200);
        $response->assertJson([
            'id_mahasiswa' => $mahasiswa->id_mahasiswa,
            'nama_mahasiswa' => 'Mahasiswa Test Feature',
            'dosen_pa_list' => ['Dosen Pembimbing Akademik'],
            'dosen_pembimbing_list' => ['Dosen Pembimbing Skripsi'],
            'active_skripsi' => [
                'judul' => 'Implementasi Machine Learning untuk Prediksi Cuaca',
                'status_judul' => 'disetujui',
            ],
        ]);
    }

    public function test_can_update_mahasiswa_and_sync_with_user(): void
    {
        $user = User::factory()->create([
            'name' => 'Original Name',
            'role' => 'mahasiswa',
            'nomor_induk' => '7777777771',
        ]);

        $mahasiswa = Mahasiswa::create([
            'user_id' => $user->id,
            'NIM' => '7777777771',
            'nama_mahasiswa' => 'Original Name',
            'prodi' => 'Teknik Informatika',
        ]);

        $response = $this->putJson("/api/mahasiswa/{$mahasiswa->id_mahasiswa}", [
            'nama_mahasiswa' => 'Updated Name',
            'NIM' => '7777777772',
            'prodi' => 'Sistem Informasi',
            'fakultas' => 'Fakultas Sains dan Teknologi',
            'no_handphone' => '08199999999',
            'jenis_kelamin' => 'Laki-laki',
        ]);

        $response->assertStatus(200);
        $this->assertDatabaseHas('mahasiswa', [
            'id_mahasiswa' => $mahasiswa->id_mahasiswa,
            'nama_mahasiswa' => 'Updated Name',
            'NIM' => '7777777772',
            'no_handphone' => '08199999999',
            'jenis_kelamin' => 'Laki-laki',
        ]);

        // Verifikasi sinkronisasi ke tabel users
        $this->assertDatabaseHas('users', [
            'id' => $user->id,
            'name' => 'Updated Name',
            'nomor_induk' => '7777777772',
        ]);
    }
}
