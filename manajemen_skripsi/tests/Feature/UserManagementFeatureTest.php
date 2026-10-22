<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\Dosen;
use App\Models\Mahasiswa;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class UserManagementFeatureTest extends TestCase
{
    use DatabaseTransactions;

    public function test_guest_cannot_access_user_management(): void
    {
        $response = $this->getJson('/api/users');
        $response->assertStatus(401);
    }

    public function test_non_admin_cannot_access_user_management(): void
    {
        $mahasiswaUser = User::factory()->create([
            'role' => 'mahasiswa',
            'nomor_induk' => '1234567890',
        ]);

        Sanctum::actingAs($mahasiswaUser);

        $response = $this->getJson('/api/users');
        $response->assertStatus(403);
        $response->assertJson([
            'message' => 'Akses ditolak. Fitur ini hanya dapat diakses oleh Administrator.'
        ]);
    }

    public function test_admin_can_list_users_with_academic_profiles(): void
    {
        $adminUser = User::factory()->create([
            'role' => 'admin',
            'name' => 'Admin User',
        ]);
        Admin::create([
            'users_id' => $adminUser->id,
            'nama_admin' => 'Admin User',
        ]);

        $mhsUser = User::factory()->create([
            'role' => 'mahasiswa',
            'nomor_induk' => '1122334455',
            'name' => 'Mhs Akun',
        ]);
        Mahasiswa::create([
            'user_id' => $mhsUser->id,
            'NIM' => '1122334455',
            'nama_mahasiswa' => 'Mhs Akun',
            'prodi' => 'Teknik Informatika',
        ]);

        Sanctum::actingAs($adminUser);

        $response = $this->getJson('/api/users');

        $response->assertStatus(200);
        $response->assertJsonStructure([
            '*' => [
                'id',
                'name',
                'email',
                'role',
                'nomor_induk',
                'email_verified_at',
                'profil_akademik',
            ]
        ]);

        // Verifikasi password TIDAK bocor dalam response JSON
        $data = $response->json();
        $this->assertArrayNotHasKey('password', $data[0]);
    }

    public function test_admin_can_update_user_and_sync_profile(): void
    {
        $adminUser = User::factory()->create([
            'role' => 'admin',
        ]);

        $mhsUser = User::factory()->create([
            'role' => 'mahasiswa',
            'nomor_induk' => '6677889901',
            'name' => 'Nama Sebelum Update',
        ]);
        $mahasiswa = Mahasiswa::create([
            'user_id' => $mhsUser->id,
            'NIM' => '6677889901',
            'nama_mahasiswa' => 'Nama Sebelum Update',
            'prodi' => 'Sains Komputer',
        ]);

        Sanctum::actingAs($adminUser);

        $response = $this->putJson("/api/users/{$mhsUser->id}", [
            'name' => 'Nama Setelah Update',
            'nomor_induk' => '6677889902',
            'email_verified' => true,
        ]);

        $response->assertStatus(200);

        $this->assertDatabaseHas('users', [
            'id' => $mhsUser->id,
            'name' => 'Nama Setelah Update',
            'nomor_induk' => '6677889902',
        ]);

        // Pastikan profil mahasiswa tersinkronisasi
        $this->assertDatabaseHas('mahasiswa', [
            'id_mahasiswa' => $mahasiswa->id_mahasiswa,
            'nama_mahasiswa' => 'Nama Setelah Update',
            'NIM' => '6677889902',
        ]);
    }

    public function test_arbitrary_role_change_is_prevented(): void
    {
        $adminUser = User::factory()->create(['role' => 'admin']);
        $targetUser = User::factory()->create(['role' => 'mahasiswa']);

        Sanctum::actingAs($adminUser);

        $response = $this->putJson("/api/users/{$targetUser->id}", [
            'role' => 'admin',
        ]);

        $response->assertStatus(422);
        $response->assertJson([
            'message' => 'Perubahan role akun tidak diizinkan demi integritas data relasi akademik.'
        ]);
    }
}
