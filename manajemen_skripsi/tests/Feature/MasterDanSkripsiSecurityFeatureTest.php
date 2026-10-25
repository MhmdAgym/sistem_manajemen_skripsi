<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\Dosen;
use App\Models\DosenPA;
use App\Models\Mahasiswa;
use App\Models\Skripsi;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class MasterDanSkripsiSecurityFeatureTest extends TestCase
{
    use DatabaseTransactions;

    private function generateNumber(): string
    {
        return (string) rand(1000000000, 9999999999);
    }

    /**
     * 1. Guest tidak terautentikasi ditolak pada endpoint master dan skripsi (401).
     */
    public function test_guest_unauthenticated_cannot_access_protected_master_and_skripsi_endpoints(): void
    {
        $this->getJson('/api/mahasiswa')->assertStatus(401);
        $this->getJson('/api/dosen')->assertStatus(401);
        $this->getJson('/api/skripsi')->assertStatus(401);
        $this->getJson('/api/admin')->assertStatus(401);
        $this->getJson('/api/dosen-pa')->assertStatus(401);
    }

    /**
     * 2. Non-admin (mahasiswa dan dosen) ditolak (403) saat mengakses daftar lengkap mahasiswa & dosen.
     */
    public function test_non_admin_cannot_access_full_mahasiswa_and_dosen_indexes(): void
    {
        $mhsUser = User::factory()->create(['role' => 'mahasiswa', 'nomor_induk' => $this->generateNumber()]);
        $dosenUser = User::factory()->create(['role' => 'dosen', 'nomor_induk' => $this->generateNumber()]);

        // Mahasiswa mencoba akses daftar lengkap
        $this->actingAs($mhsUser, 'sanctum')->getJson('/api/mahasiswa')->assertStatus(403);
        $this->actingAs($mhsUser, 'sanctum')->getJson('/api/dosen')->assertStatus(403);

        // Dosen mencoba akses daftar lengkap
        $this->actingAs($dosenUser, 'sanctum')->getJson('/api/mahasiswa')->assertStatus(403);
        $this->actingAs($dosenUser, 'sanctum')->getJson('/api/dosen')->assertStatus(403);
    }

    /**
     * 3. Admin dapat mengakses daftar lengkap mahasiswa dan dosen dengan kontrak data yang sah.
     */
    public function test_admin_can_access_full_mahasiswa_and_dosen_indexes_with_compatible_structure(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'nomor_induk' => $this->generateNumber()]);

        $resMhs = $this->actingAs($admin, 'sanctum')->getJson('/api/mahasiswa');
        $resMhs->assertStatus(200);
        $resMhs->assertJsonStructure([
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

        $resDosen = $this->actingAs($admin, 'sanctum')->getJson('/api/dosen');
        $resDosen->assertStatus(200);
        $resDosen->assertJsonStructure([
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

    /**
     * 4. Mahasiswa hanya dapat melihat profilnya sendiri dan ditolak (403) pada profil mahasiswa lain (IDOR).
     */
    public function test_mahasiswa_can_view_own_detail_but_forbidden_from_other_students(): void
    {
        $user1 = User::factory()->create(['role' => 'mahasiswa', 'nomor_induk' => $this->generateNumber()]);
        $mhs1 = Mahasiswa::create([
            'user_id'        => $user1->id,
            'NIM'            => $user1->nomor_induk,
            'nama_mahasiswa' => 'Mahasiswa Satu',
            'prodi'          => 'Informatika',
            'fakultas'       => 'Fakultas Sains dan Teknologi',
        ]);

        $user2 = User::factory()->create(['role' => 'mahasiswa', 'nomor_induk' => $this->generateNumber()]);
        $mhs2 = Mahasiswa::create([
            'user_id'        => $user2->id,
            'NIM'            => $user2->nomor_induk,
            'nama_mahasiswa' => 'Mahasiswa Dua',
            'prodi'          => 'Informatika',
            'fakultas'       => 'Fakultas Sains dan Teknologi',
        ]);

        // Melihat profil sendiri -> 200
        $this->actingAs($user1, 'sanctum')
            ->getJson("/api/mahasiswa/{$mhs1->id_mahasiswa}")
            ->assertStatus(200)
            ->assertJsonFragment(['nama_mahasiswa' => 'Mahasiswa Satu']);

        // Mencoba melihat profil mahasiswa lain -> 403 Forbidden
        $this->actingAs($user1, 'sanctum')
            ->getJson("/api/mahasiswa/{$mhs2->id_mahasiswa}")
            ->assertStatus(403)
            ->assertJsonFragment(['message' => 'Anda tidak memiliki hak akses untuk melihat profil mahasiswa ini.']);
    }

    /**
     * 5. Dosen hanya dapat melihat profilnya sendiri dan ditolak (403) pada profil dosen lain (IDOR).
     */
    public function test_dosen_can_view_own_detail_but_forbidden_from_other_lecturers(): void
    {
        $userD1 = User::factory()->create(['role' => 'dosen', 'nomor_induk' => $this->generateNumber()]);
        $dosen1 = Dosen::create([
            'user_id'    => $userD1->id,
            'NIDN'       => $userD1->nomor_induk,
            'nama_dosen' => 'Dosen Satu',
        ]);

        $userD2 = User::factory()->create(['role' => 'dosen', 'nomor_induk' => $this->generateNumber()]);
        $dosen2 = Dosen::create([
            'user_id'    => $userD2->id,
            'NIDN'       => $userD2->nomor_induk,
            'nama_dosen' => 'Dosen Dua',
        ]);

        // Melihat profil sendiri -> 200
        $this->actingAs($userD1, 'sanctum')
            ->getJson("/api/dosen/{$dosen1->id_dosen}")
            ->assertStatus(200)
            ->assertJsonFragment(['nama_dosen' => 'Dosen Satu']);

        // Mencoba melihat profil dosen lain -> 403 Forbidden
        $this->actingAs($userD1, 'sanctum')
            ->getJson("/api/dosen/{$dosen2->id_dosen}")
            ->assertStatus(403)
            ->assertJsonFragment(['message' => 'Anda tidak memiliki hak akses untuk melihat profil dosen ini.']);
    }

    /**
     * 6. Non-admin ditolak (403) saat mencoba mutasi create, update, atau delete pada master data.
     */
    public function test_non_admin_cannot_create_update_or_delete_master_data(): void
    {
        $mhsUser = User::factory()->create(['role' => 'mahasiswa', 'nomor_induk' => $this->generateNumber()]);
        $dosenUser = User::factory()->create(['role' => 'dosen', 'nomor_induk' => $this->generateNumber()]);

        $mhs = Mahasiswa::create([
            'user_id'        => $mhsUser->id,
            'NIM'            => $mhsUser->nomor_induk,
            'nama_mahasiswa' => 'Mahasiswa Target',
            'prodi'          => 'Informatika',
        ]);

        $dosen = Dosen::create([
            'user_id'    => $dosenUser->id,
            'NIDN'       => $dosenUser->nomor_induk,
            'nama_dosen' => 'Dosen Target',
        ]);

        // Mahasiswa mencoba store, update, destroy mahasiswa
        $this->actingAs($mhsUser, 'sanctum')->postJson('/api/mahasiswa', [])->assertStatus(403);
        $this->actingAs($mhsUser, 'sanctum')->putJson("/api/mahasiswa/{$mhs->id_mahasiswa}", [])->assertStatus(403);
        $this->actingAs($mhsUser, 'sanctum')->deleteJson("/api/mahasiswa/{$mhs->id_mahasiswa}")->assertStatus(403);

        // Dosen mencoba store, update, destroy dosen
        $this->actingAs($dosenUser, 'sanctum')->postJson('/api/dosen', [])->assertStatus(403);
        $this->actingAs($dosenUser, 'sanctum')->putJson("/api/dosen/{$dosen->id_dosen}", [])->assertStatus(403);
        $this->actingAs($dosenUser, 'sanctum')->deleteJson("/api/dosen/{$dosen->id_dosen}")->assertStatus(403);
    }

    /**
     * 7. Pencegahan manipulasi relasi user_id (mass assignment / tampering).
     */
    public function test_user_id_tampering_is_prevented_during_profile_update(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'nomor_induk' => $this->generateNumber()]);
        $userA = User::factory()->create(['role' => 'mahasiswa', 'nomor_induk' => $this->generateNumber()]);
        $userB = User::factory()->create(['role' => 'mahasiswa', 'nomor_induk' => $this->generateNumber()]);

        $mhs = Mahasiswa::create([
            'user_id'        => $userA->id,
            'NIM'            => $userA->nomor_induk,
            'nama_mahasiswa' => 'Mahasiswa Asli',
            'prodi'          => 'Informatika',
            'fakultas'       => 'Fakultas Sains dan Teknologi',
        ]);

        // Coba kirim user_id milik userB
        $response = $this->actingAs($admin, 'sanctum')->putJson("/api/mahasiswa/{$mhs->id_mahasiswa}", [
            'user_id'        => $userB->id,
            'nama_mahasiswa' => 'Mahasiswa Ganti Nama',
        ]);

        $response->assertStatus(200);

        // Verifikasi di database: user_id mahasiswa tetap userA, tidak terbajak ke userB
        $mhs->refresh();
        $this->assertEquals($userA->id, $mhs->user_id);
    }

    /**
     * 8. Status judul skripsi TIDAK DAPAT diubah secara langsung via generic CRUD update (422 rejection).
     */
    public function test_skripsi_status_judul_cannot_be_mutated_directly_via_generic_crud(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'nomor_induk' => $this->generateNumber()]);
        $userMhs = User::factory()->create(['role' => 'mahasiswa', 'nomor_induk' => $this->generateNumber()]);

        $mhs = Mahasiswa::create([
            'user_id'        => $userMhs->id,
            'NIM'            => $userMhs->nomor_induk,
            'nama_mahasiswa' => 'Mahasiswa Skripsi',
            'prodi'          => 'Informatika',
        ]);

        $skripsi = Skripsi::create([
            'id_mahasiswa' => $mhs->id_mahasiswa,
            'judul'        => 'Judul Awal Skripsi',
            'status_judul' => 'Belum Mengajukan',
        ]);

        // Upaya 1: Admin mencoba mengubah status_judul langsung menjadi 'disetujui' via CRUD
        $response = $this->actingAs($admin, 'sanctum')->putJson("/api/skripsi/{$skripsi->id_skripsi}", [
            'status_judul' => 'disetujui',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['status_judul']);
        $response->assertJsonFragment([
            'message' => 'Status judul skripsi tidak dapat diubah melalui endpoint ini. Perubahan status judul harus melalui alur resmi verifikasi Dosen PA dan persetujuan Kaprodi.',
        ]);

        // Verifikasi di database bahwa status_judul TIDAK berubah
        $skripsi->refresh();
        $this->assertEquals('Belum Mengajukan', $skripsi->status_judul);

        // Upaya 2: Admin mengubah judul administratif yang sah tanpa mengubah status_judul
        $resValid = $this->actingAs($admin, 'sanctum')->putJson("/api/skripsi/{$skripsi->id_skripsi}", [
            'judul' => 'Judul Diperbarui Secara Administratif',
        ]);

        $resValid->assertStatus(200);
        $skripsi->refresh();
        $this->assertEquals('Judul Diperbarui Secara Administratif', $skripsi->judul);
    }

    /**
     * 9. Otorisasi skripsi: Mahasiswa hanya dapat melihat skripsinya sendiri, ditolak dari skripsi mahasiswa lain.
     */
    public function test_skripsi_access_ownership_and_idor_protection(): void
    {
        $user1 = User::factory()->create(['role' => 'mahasiswa', 'nomor_induk' => $this->generateNumber()]);
        $mhs1 = Mahasiswa::create([
            'user_id'        => $user1->id,
            'NIM'            => $user1->nomor_induk,
            'nama_mahasiswa' => 'Mahasiswa 1 Skripsi',
            'prodi'          => 'Informatika',
        ]);
        $skripsi1 = Skripsi::create([
            'id_mahasiswa' => $mhs1->id_mahasiswa,
            'judul'        => 'Skripsi Milik Mahasiswa 1',
            'status_judul' => 'diajukan',
        ]);

        $user2 = User::factory()->create(['role' => 'mahasiswa', 'nomor_induk' => $this->generateNumber()]);
        $mhs2 = Mahasiswa::create([
            'user_id'        => $user2->id,
            'NIM'            => $user2->nomor_induk,
            'nama_mahasiswa' => 'Mahasiswa 2 Skripsi',
            'prodi'          => 'Informatika',
        ]);
        $skripsi2 = Skripsi::create([
            'id_mahasiswa' => $mhs2->id_mahasiswa,
            'judul'        => 'Skripsi Milik Mahasiswa 2',
            'status_judul' => 'diajukan',
        ]);

        // Mahasiswa 1 melihat skripsi miliknya sendiri -> 200
        $this->actingAs($user1, 'sanctum')
            ->getJson("/api/skripsi/{$skripsi1->id_skripsi}")
            ->assertStatus(200)
            ->assertJsonFragment(['judul' => 'Skripsi Milik Mahasiswa 1']);

        // Mahasiswa 1 mencoba melihat skripsi milik mahasiswa 2 -> 403 Forbidden
        $this->actingAs($user1, 'sanctum')
            ->getJson("/api/skripsi/{$skripsi2->id_skripsi}")
            ->assertStatus(403)
            ->assertJsonFragment(['message' => 'Anda tidak memiliki hak akses untuk melihat data skripsi ini.']);

        // Mahasiswa 1 list skripsi -> hanya memunculkan miliknya sendiri
        $resList = $this->actingAs($user1, 'sanctum')->getJson('/api/skripsi');
        $resList->assertStatus(200);
        $dataList = $resList->json();
        $this->assertCount(1, $dataList);
        $this->assertEquals($skripsi1->id_skripsi, $dataList[0]['id_skripsi']);
    }

    /**
     * 10. Endpoint admin & dosen-pa mutlak menolak non-admin (403).
     */
    public function test_admin_and_dosen_pa_endpoints_strictly_forbid_non_admins(): void
    {
        $mhsUser = User::factory()->create(['role' => 'mahasiswa', 'nomor_induk' => $this->generateNumber()]);
        $dosenUser = User::factory()->create(['role' => 'dosen', 'nomor_induk' => $this->generateNumber()]);

        // Akses ke /api/admin
        $this->actingAs($mhsUser, 'sanctum')->getJson('/api/admin')->assertStatus(403);
        $this->actingAs($dosenUser, 'sanctum')->getJson('/api/admin')->assertStatus(403);

        // Akses ke /api/dosen-pa
        $this->actingAs($mhsUser, 'sanctum')->getJson('/api/dosen-pa')->assertStatus(403);
        $this->actingAs($dosenUser, 'sanctum')->getJson('/api/dosen-pa')->assertStatus(403);
    }
}
