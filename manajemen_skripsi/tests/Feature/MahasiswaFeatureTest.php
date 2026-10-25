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
        $admin = User::factory()->create(['role' => 'admin']);
        $response = $this->actingAs($admin, 'sanctum')->getJson('/api/mahasiswa');

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
        $nim = (string) rand(1000000000, 9999999999);
        $user = User::factory()->create([
            'role' => 'mahasiswa',
            'nomor_induk' => $nim,
        ]);

        $mahasiswa = Mahasiswa::create([
            'user_id' => $user->id,
            'NIM' => $nim,
            'nama_mahasiswa' => 'Mahasiswa Tanpa Skripsi',
            'prodi' => 'Informatika',
        ]);

        $response = $this->actingAs($user, 'sanctum')->getJson("/api/mahasiswa/{$mahasiswa->id_mahasiswa}");

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
        $nim = (string) rand(1000000000, 9999999999);
        $nidn1 = (string) rand(1000000000, 9999999999);
        $nidn2 = (string) rand(1000000000, 9999999999);

        $user = User::factory()->create([
            'role' => 'mahasiswa',
            'nomor_induk' => $nim,
        ]);

        $mahasiswa = Mahasiswa::create([
            'user_id' => $user->id,
            'NIM' => $nim,
            'nama_mahasiswa' => 'Mahasiswa Test Feature',
            'prodi' => 'Teknik Informatika',
            'fakultas' => 'Fakultas Sains dan Teknologi',
        ]);

        $userDosen1 = User::factory()->create(['role' => 'dosen', 'nomor_induk' => $nidn1]);
        $dosen1 = Dosen::create([
            'user_id' => $userDosen1->id,
            'NIDN' => $nidn1,
            'nama_dosen' => 'Dosen Pembimbing Akademik',
        ]);

        $userDosen2 = User::factory()->create(['role' => 'dosen', 'nomor_induk' => $nidn2]);
        $dosen2 = Dosen::create([
            'user_id' => $userDosen2->id,
            'NIDN' => $nidn2,
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

        $response = $this->actingAs($user, 'sanctum')->getJson("/api/mahasiswa/{$mahasiswa->id_mahasiswa}");

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
        $admin = User::factory()->create(['role' => 'admin']);
        $nim1 = (string) rand(1000000000, 9999999999);
        $nim2 = (string) rand(1000000000, 9999999999);

        $user = User::factory()->create([
            'name' => 'Original Name',
            'role' => 'mahasiswa',
            'nomor_induk' => $nim1,
        ]);

        $mahasiswa = Mahasiswa::create([
            'user_id' => $user->id,
            'NIM' => $nim1,
            'nama_mahasiswa' => 'Original Name',
            'prodi' => 'Teknik Informatika',
        ]);

        $response = $this->actingAs($admin, 'sanctum')->putJson("/api/mahasiswa/{$mahasiswa->id_mahasiswa}", [
            'nama_mahasiswa' => 'Updated Name',
            'NIM' => $nim2,
            'prodi' => 'Informatika',
            'fakultas' => 'Fakultas Sains dan Teknologi',
            'no_handphone' => '08199999999',
            'jenis_kelamin' => 'Laki-laki',
        ]);

        $response->assertStatus(200);
        $this->assertDatabaseHas('mahasiswa', [
            'id_mahasiswa' => $mahasiswa->id_mahasiswa,
            'nama_mahasiswa' => 'Updated Name',
            'NIM' => $nim2,
            'prodi' => 'Informatika',
            'fakultas' => 'Fakultas Sains dan Teknologi',
            'no_handphone' => '08199999999',
            'jenis_kelamin' => 'Laki-laki',
        ]);

        // Verifikasi sinkronisasi ke tabel users
        $this->assertDatabaseHas('users', [
            'id' => $user->id,
            'name' => 'Updated Name',
            'nomor_induk' => $nim2,
        ]);
    }

    public function test_update_mahasiswa_rejects_mismatched_fakultas_and_prodi(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $nim = (string) rand(1000000000, 9999999999);
        $user = User::factory()->create(['role' => 'mahasiswa', 'nomor_induk' => $nim]);
        $mahasiswa = Mahasiswa::create([
            'user_id' => $user->id,
            'NIM' => $nim,
            'nama_mahasiswa' => 'Mhs Mismatch',
            'prodi' => 'Informatika',
            'fakultas' => 'Fakultas Sains dan Teknologi',
        ]);

        // Kirim pasangan tidak cocok: FAI dengan Informatika
        $response = $this->actingAs($admin, 'sanctum')->putJson("/api/mahasiswa/{$mahasiswa->id_mahasiswa}", [
            'fakultas' => 'Fakultas Agama Islam',
            'prodi'    => 'Informatika',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['prodi']);
        $response->assertJsonFragment([
            'message' => 'Program studi tidak sesuai dengan fakultas yang dipilih.',
        ]);
    }

    public function test_update_mahasiswa_accepts_valid_fakultas_and_prodi(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $nim = (string) rand(1000000000, 9999999999);
        $user = User::factory()->create(['role' => 'mahasiswa', 'nomor_induk' => $nim]);
        $mahasiswa = Mahasiswa::create([
            'user_id' => $user->id,
            'NIM' => $nim,
            'nama_mahasiswa' => 'Mhs Valid',
            'prodi' => 'Informatika',
            'fakultas' => 'Fakultas Sains dan Teknologi',
        ]);

        // Pindah ke FIP dan Pendidikan Teknologi Informasi
        $response = $this->actingAs($admin, 'sanctum')->putJson("/api/mahasiswa/{$mahasiswa->id_mahasiswa}", [
            'fakultas' => 'Fakultas Ilmu Pendidikan',
            'prodi'    => 'Pendidikan Teknologi Informasi',
        ]);

        $response->assertStatus(200);
        $this->assertDatabaseHas('mahasiswa', [
            'id_mahasiswa' => $mahasiswa->id_mahasiswa,
            'fakultas'     => 'Fakultas Ilmu Pendidikan',
            'prodi'        => 'Pendidikan Teknologi Informasi',
        ]);
    }

    public function test_update_mahasiswa_normalizes_shorthand_fakultas(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $nim = (string) rand(1000000000, 9999999999);
        $user = User::factory()->create(['role' => 'mahasiswa', 'nomor_induk' => $nim]);
        $mahasiswa = Mahasiswa::create([
            'user_id' => $user->id,
            'NIM' => $nim,
            'nama_mahasiswa' => 'Mhs Alias',
            'prodi' => 'Informatika',
            'fakultas' => 'Fakultas Sains dan Teknologi',
        ]);

        // Kirim singkatan FAI dengan Pendidikan Bahasa Arab
        $response = $this->actingAs($admin, 'sanctum')->putJson("/api/mahasiswa/{$mahasiswa->id_mahasiswa}", [
            'fakultas' => 'FAI',
            'prodi'    => 'Pendidikan Bahasa Arab',
        ]);

        $response->assertStatus(200);
        $this->assertDatabaseHas('mahasiswa', [
            'id_mahasiswa' => $mahasiswa->id_mahasiswa,
            'fakultas'     => 'Fakultas Agama Islam',
            'prodi'        => 'Pendidikan Bahasa Arab',
        ]);
    }

    public function test_update_mahasiswa_preserves_and_updates_dosen_pa(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $nim = (string) rand(1000000000, 9999999999);
        $nidn = (string) rand(1000000000, 9999999999);

        $user = User::factory()->create(['role' => 'mahasiswa', 'nomor_induk' => $nim]);
        $mahasiswa = Mahasiswa::create([
            'user_id' => $user->id,
            'NIM' => $nim,
            'nama_mahasiswa' => 'Mhs PA Test',
            'prodi' => 'Informatika',
            'fakultas' => 'Fakultas Sains dan Teknologi',
        ]);

        $userDosen = User::factory()->create(['role' => 'dosen', 'nomor_induk' => $nidn]);
        $dosen = Dosen::create([
            'user_id' => $userDosen->id,
            'NIDN' => $nidn,
            'nama_dosen' => 'Dosen PA Baru',
        ]);

        $response = $this->actingAs($admin, 'sanctum')->putJson("/api/mahasiswa/{$mahasiswa->id_mahasiswa}", [
            'dosen_pa_id' => $dosen->id_dosen,
        ]);

        $response->assertStatus(200);
        $this->assertDatabaseHas('dosen_pa', [
            'id_dosen' => $dosen->id_dosen,
        ]);
    }
}
