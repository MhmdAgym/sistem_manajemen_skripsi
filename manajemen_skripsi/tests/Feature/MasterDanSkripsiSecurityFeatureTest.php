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

    /**
     * 11. Dosen PA update tidak dapat dialihkan ke skripsi yang sudah memiliki Dosen PA (422).
     */
    public function test_11_dosen_pa_update_cannot_reassign_to_skripsi_with_existing_pa(): void
    {
        $adminUser = User::factory()->create(['role' => 'admin', 'nomor_induk' => $this->generateNumber()]);

        // Siapkan 2 dosen
        $dosenUser1 = User::factory()->create(['role' => 'dosen', 'nomor_induk' => $this->generateNumber()]);
        $dosen1 = Dosen::create(['user_id' => $dosenUser1->id, 'NIDN' => $dosenUser1->nomor_induk, 'nama_dosen' => 'Dosen PA 1']);

        $dosenUser2 = User::factory()->create(['role' => 'dosen', 'nomor_induk' => $this->generateNumber()]);
        $dosen2 = Dosen::create(['user_id' => $dosenUser2->id, 'NIDN' => $dosenUser2->nomor_induk, 'nama_dosen' => 'Dosen PA 2']);

        // Siapkan 3 mahasiswa dan skripsi
        $mhsUser1 = User::factory()->create(['role' => 'mahasiswa', 'nomor_induk' => $this->generateNumber()]);
        $mhs1 = Mahasiswa::create(['user_id' => $mhsUser1->id, 'NIM' => $mhsUser1->nomor_induk, 'nama_mahasiswa' => 'Mhs 1', 'prodi' => 'Informatika', 'fakultas' => 'Fakultas Sains dan Teknologi']);
        $skripsi1 = Skripsi::create(['id_mahasiswa' => $mhs1->id_mahasiswa, 'judul' => 'Skripsi 1', 'status_judul' => 'disetujui']);

        $mhsUser2 = User::factory()->create(['role' => 'mahasiswa', 'nomor_induk' => $this->generateNumber()]);
        $mhs2 = Mahasiswa::create(['user_id' => $mhsUser2->id, 'NIM' => $mhsUser2->nomor_induk, 'nama_mahasiswa' => 'Mhs 2', 'prodi' => 'Informatika', 'fakultas' => 'Fakultas Sains dan Teknologi']);
        $skripsi2 = Skripsi::create(['id_mahasiswa' => $mhs2->id_mahasiswa, 'judul' => 'Skripsi 2', 'status_judul' => 'disetujui']);

        $mhsUser3 = User::factory()->create(['role' => 'mahasiswa', 'nomor_induk' => $this->generateNumber()]);
        $mhs3 = Mahasiswa::create(['user_id' => $mhsUser3->id, 'NIM' => $mhsUser3->nomor_induk, 'nama_mahasiswa' => 'Mhs 3', 'prodi' => 'Informatika', 'fakultas' => 'Fakultas Sains dan Teknologi']);
        $skripsi3 = Skripsi::create(['id_mahasiswa' => $mhs3->id_mahasiswa, 'judul' => 'Skripsi 3', 'status_judul' => 'disetujui']);

        // Dosen PA 1 terhubung ke skripsi 1
        $pa1 = DosenPA::create(['id_dosen' => $dosen1->id_dosen, 'id_skripsi' => $skripsi1->id_skripsi]);

        // Dosen PA 2 terhubung ke skripsi 2
        $pa2 = DosenPA::create(['id_dosen' => $dosen2->id_dosen, 'id_skripsi' => $skripsi2->id_skripsi]);

        // 1. Admin mencoba update PA 2 untuk mengambil skripsi 1 (sudah ada PA 1) -> 422
        $resConflict = $this->actingAs($adminUser, 'sanctum')->putJson("/api/dosen-pa/{$pa2->id_dosen_pa}", [
            'id_skripsi' => $skripsi1->id_skripsi,
        ]);
        $resConflict->assertStatus(422)
            ->assertJsonValidationErrors(['id_skripsi'])
            ->assertJsonFragment([
                'message' => 'Skripsi ini sudah memiliki Dosen PA lain.',
            ]);

        // 2. Admin update PA 2 dengan skripsi yang sama (skripsi 2) -> 200
        $resSame = $this->actingAs($adminUser, 'sanctum')->putJson("/api/dosen-pa/{$pa2->id_dosen_pa}", [
            'id_skripsi' => $skripsi2->id_skripsi,
        ]);
        $resSame->assertStatus(200);

        // 3. Admin update PA 2 mengarah ke skripsi 3 (belum memiliki PA) -> 200
        $resReassign = $this->actingAs($adminUser, 'sanctum')->putJson("/api/dosen-pa/{$pa2->id_dosen_pa}", [
            'id_skripsi' => $skripsi3->id_skripsi,
        ]);
        $resReassign->assertStatus(200);
    }

    /**
     * 12. Admin store dan update memvalidasi role akun admin dan keunikan users_id.
     */
    public function test_12_admin_store_and_update_validates_admin_role_and_unique_users_id(): void
    {
        $adminAuth = User::factory()->create(['role' => 'admin', 'nomor_induk' => $this->generateNumber()]);

        // 1. Store dengan user ber-role mahasiswa ditolak (422)
        $mhsUser = User::factory()->create(['role' => 'mahasiswa', 'nomor_induk' => $this->generateNumber()]);
        $this->actingAs($adminAuth, 'sanctum')->postJson('/api/admin', [
            'nama_admin' => 'Admin Palsu',
            'users_id'   => $mhsUser->id,
        ])->assertStatus(422)->assertJsonValidationErrors(['users_id']);

        // 2. Store dengan user role admin berhasil (201)
        $validUser1 = User::factory()->create(['role' => 'admin', 'nomor_induk' => $this->generateNumber()]);
        $resStore = $this->actingAs($adminAuth, 'sanctum')->postJson('/api/admin', [
            'nama_admin' => 'Admin Baru 1',
            'users_id'   => $validUser1->id,
        ]);
        $resStore->assertStatus(201);
        $adminId1 = $resStore->json('data.id_admin');

        // 3. Store dengan users_id yang sama ditolak karena unique (422)
        $this->actingAs($adminAuth, 'sanctum')->postJson('/api/admin', [
            'nama_admin' => 'Admin Duplikat',
            'users_id'   => $validUser1->id,
        ])->assertStatus(422)->assertJsonValidationErrors(['users_id']);

        // 4. Update data admin yang sama mempertahankan users_id -> 200
        $this->actingAs($adminAuth, 'sanctum')->putJson("/api/admin/{$adminId1}", [
            'nama_admin' => 'Admin Baru 1 Updated',
            'users_id'   => $validUser1->id,
        ])->assertStatus(200);

        // 5. Update dengan user ber-role bukan admin ditolak (422)
        $this->actingAs($adminAuth, 'sanctum')->putJson("/api/admin/{$adminId1}", [
            'users_id' => $mhsUser->id,
        ])->assertStatus(422)->assertJsonValidationErrors(['users_id']);

        // 6. Buat admin kedua, lalu update admin kedua memakai users_id admin pertama -> Ditolak (422)
        $validUser2 = User::factory()->create(['role' => 'admin', 'nomor_induk' => $this->generateNumber()]);
        $resStore2 = $this->actingAs($adminAuth, 'sanctum')->postJson('/api/admin', [
            'nama_admin' => 'Admin Baru 2',
            'users_id'   => $validUser2->id,
        ]);
        $resStore2->assertStatus(201);
        $adminId2 = $resStore2->json('data.id_admin');

        $this->actingAs($adminAuth, 'sanctum')->putJson("/api/admin/{$adminId2}", [
            'users_id' => $validUser1->id,
        ])->assertStatus(422)->assertJsonValidationErrors(['users_id']);
    }

    /**
     * 13. Mahasiswa store memvalidasi role user_id harus mahasiswa dan unik.
     */
    public function test_13_mahasiswa_store_validates_role_and_unique_user_id(): void
    {
        $adminAuth = User::factory()->create(['role' => 'admin', 'nomor_induk' => $this->generateNumber()]);

        // 1. User ber-role 'dosen' tidak dapat didaftarkan sebagai mahasiswa -> 422
        $dosenUser = User::factory()->create(['role' => 'dosen', 'nomor_induk' => $this->generateNumber()]);
        $this->actingAs($adminAuth, 'sanctum')->postJson('/api/mahasiswa', [
            'user_id'        => $dosenUser->id,
            'NIM'            => '9999990001',
            'nama_mahasiswa' => 'Mahasiswa Salah Role',
            'prodi'          => 'Informatika',
            'fakultas'       => 'Fakultas Sains dan Teknologi',
        ])->assertStatus(422)->assertJsonValidationErrors(['user_id']);

        // 2. User ber-role 'mahasiswa' valid -> 201
        $mhsUser = User::factory()->create(['role' => 'mahasiswa', 'nomor_induk' => $this->generateNumber()]);
        $this->actingAs($adminAuth, 'sanctum')->postJson('/api/mahasiswa', [
            'user_id'        => $mhsUser->id,
            'NIM'            => '9999990002',
            'nama_mahasiswa' => 'Mahasiswa Sah',
            'prodi'          => 'Informatika',
            'fakultas'       => 'Fakultas Sains dan Teknologi',
        ])->assertStatus(201);

        // 3. User yang sudah dipakai mahasiswa lain tidak dapat didaftarkan ulang -> 422
        $this->actingAs($adminAuth, 'sanctum')->postJson('/api/mahasiswa', [
            'user_id'        => $mhsUser->id,
            'NIM'            => '9999990003',
            'nama_mahasiswa' => 'Mahasiswa Duplicate User',
            'prodi'          => 'Informatika',
            'fakultas'       => 'Fakultas Sains dan Teknologi',
        ])->assertStatus(422)->assertJsonValidationErrors(['user_id']);
    }

    /**
     * 14. Dosen store memvalidasi role user_id harus dosen dan unik.
     */
    public function test_14_dosen_store_validates_role_and_unique_user_id(): void
    {
        $adminAuth = User::factory()->create(['role' => 'admin', 'nomor_induk' => $this->generateNumber()]);

        // 1. User ber-role 'mahasiswa' tidak dapat didaftarkan sebagai dosen -> 422
        $mhsUser = User::factory()->create(['role' => 'mahasiswa', 'nomor_induk' => $this->generateNumber()]);
        $this->actingAs($adminAuth, 'sanctum')->postJson('/api/dosen', [
            'user_id'    => $mhsUser->id,
            'NIDN'       => '8888880001',
            'nama_dosen' => 'Dosen Salah Role',
        ])->assertStatus(422)->assertJsonValidationErrors(['user_id']);

        // 2. User ber-role 'dosen' valid -> 201
        $dosenUser = User::factory()->create(['role' => 'dosen', 'nomor_induk' => $this->generateNumber()]);
        $this->actingAs($adminAuth, 'sanctum')->postJson('/api/dosen', [
            'user_id'    => $dosenUser->id,
            'NIDN'       => '8888880002',
            'nama_dosen' => 'Dosen Sah',
        ])->assertStatus(201);

        // 3. User yang sudah dipakai dosen lain tidak dapat didaftarkan ulang -> 422
        $this->actingAs($adminAuth, 'sanctum')->postJson('/api/dosen', [
            'user_id'    => $dosenUser->id,
            'NIDN'       => '8888880003',
            'nama_dosen' => 'Dosen Duplicate User',
        ])->assertStatus(422)->assertJsonValidationErrors(['user_id']);
    }
}
