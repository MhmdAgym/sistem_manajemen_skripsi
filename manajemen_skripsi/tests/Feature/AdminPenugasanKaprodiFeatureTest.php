<?php

namespace Tests\Feature;

use App\Constants\AcademicConstant;
use App\Models\Dosen;
use App\Models\PenugasanKaprodi;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class AdminPenugasanKaprodiFeatureTest extends TestCase
{
    use DatabaseTransactions;

    private function buatAdmin(): User
    {
        return User::factory()->create([
            'role'        => 'admin',
            'name'        => 'Admin Institusi',
            'nomor_induk' => 'ADM999',
        ]);
    }

    private function buatDosen(string $nidn = '1122334455'): array
    {
        $user = User::factory()->create([
            'role'        => 'dosen',
            'name'        => 'Dosen ' . $nidn,
            'nomor_induk' => $nidn,
        ]);

        $dosen = Dosen::create([
            'user_id'    => $user->id,
            'NIDN'       => $nidn,
            'nama_dosen' => 'Dr. Dosen ' . $nidn,
        ]);

        return compact('user', 'dosen');
    }

    private function buatMahasiswa(string $nim = '2026110001'): User
    {
        return User::factory()->create([
            'role'        => 'mahasiswa',
            'name'        => 'Mahasiswa ' . $nim,
            'nomor_induk' => $nim,
        ]);
    }

    /**
     * 1. Guest tanpa autentikasi menerima HTTP 401 pada seluruh endpoint admin penugasan.
     */
    public function test_guest_cannot_access_penugasan_kaprodi_endpoints(): void
    {
        $this->getJson('/api/admin/penugasan-kaprodi')->assertStatus(401);
        $this->getJson('/api/admin/penugasan-kaprodi/dosen-options')->assertStatus(401);
        $this->postJson('/api/admin/penugasan-kaprodi', [])->assertStatus(401);
        $this->getJson('/api/admin/penugasan-kaprodi/1')->assertStatus(401);
        $this->patchJson('/api/admin/penugasan-kaprodi/1', [])->assertStatus(401);
        $this->patchJson('/api/admin/penugasan-kaprodi/1/nonaktifkan')->assertStatus(401);
    }

    /**
     * 2. Pengguna non-admin (mahasiswa & dosen) ditolak dengan HTTP 403 pada seluruh 6 endpoint
     *    dan tidak terjadi mutasi data pada basis data pengujian.
     */
    public function test_non_admin_cannot_access_penugasan_kaprodi_endpoints(): void
    {
        $mhs = $this->buatMahasiswa('2026110099');
        $dsnActor = $this->buatDosen('1122339901');

        $fixtureDosen = $this->buatDosen('1122339902');
        $fixtureDate = now()->subMonths(2)->toDateString();
        $fixturePenugasan = PenugasanKaprodi::create([
            'dosen_id'        => $fixtureDosen['dosen']->id_dosen,
            'prodi'           => 'Pendidikan Matematika',
            'tanggal_mulai'   => $fixtureDate,
            'tanggal_selesai' => null,
            'is_aktif'        => true,
        ]);

        $expectedDeniedMessage = [
            'message' => 'Akses ditolak. Fitur ini hanya dapat diakses oleh Administrator.'
        ];
        $mutationDate = now()->toDateString();

        // 1. Pengujian untuk peran Mahasiswa pada seluruh 6 endpoint
        $this->actingAs($mhs)
            ->getJson('/api/admin/penugasan-kaprodi/dosen-options')
            ->assertStatus(403)
            ->assertExactJson($expectedDeniedMessage);

        $this->actingAs($mhs)
            ->getJson('/api/admin/penugasan-kaprodi')
            ->assertStatus(403)
            ->assertExactJson($expectedDeniedMessage);

        $this->actingAs($mhs)
            ->postJson('/api/admin/penugasan-kaprodi', [
                'dosen_id'      => $fixtureDosen['dosen']->id_dosen,
                'prodi'         => 'Teknik Mesin',
                'tanggal_mulai' => $mutationDate,
            ])
            ->assertStatus(403)
            ->assertExactJson($expectedDeniedMessage);
        $this->assertDatabaseMissing('penugasan_kaprodi', ['prodi' => 'Teknik Mesin']);

        $this->actingAs($mhs)
            ->getJson("/api/admin/penugasan-kaprodi/{$fixturePenugasan->id}")
            ->assertStatus(403)
            ->assertExactJson($expectedDeniedMessage);

        $this->actingAs($mhs)
            ->patchJson("/api/admin/penugasan-kaprodi/{$fixturePenugasan->id}", [
                'is_aktif'        => false,
                'tanggal_selesai' => $mutationDate,
            ])
            ->assertStatus(403)
            ->assertExactJson($expectedDeniedMessage);
        $fixturePenugasan->refresh();
        $this->assertTrue((bool) $fixturePenugasan->is_aktif);
        $this->assertNull($fixturePenugasan->tanggal_selesai);

        $this->actingAs($mhs)
            ->patchJson("/api/admin/penugasan-kaprodi/{$fixturePenugasan->id}/nonaktifkan")
            ->assertStatus(403)
            ->assertExactJson($expectedDeniedMessage);
        $fixturePenugasan->refresh();
        $this->assertTrue((bool) $fixturePenugasan->is_aktif);
        $this->assertNull($fixturePenugasan->tanggal_selesai);

        // 2. Pengujian untuk peran Dosen pada seluruh 6 endpoint
        $this->actingAs($dsnActor['user'])
            ->getJson('/api/admin/penugasan-kaprodi/dosen-options')
            ->assertStatus(403)
            ->assertExactJson($expectedDeniedMessage);

        $this->actingAs($dsnActor['user'])
            ->getJson('/api/admin/penugasan-kaprodi')
            ->assertStatus(403)
            ->assertExactJson($expectedDeniedMessage);

        $this->actingAs($dsnActor['user'])
            ->postJson('/api/admin/penugasan-kaprodi', [
                'dosen_id'      => $dsnActor['dosen']->id_dosen,
                'prodi'         => 'Informatika',
                'tanggal_mulai' => $mutationDate,
            ])
            ->assertStatus(403)
            ->assertExactJson($expectedDeniedMessage);
        $this->assertDatabaseMissing('penugasan_kaprodi', [
            'prodi'    => 'Informatika',
            'dosen_id' => $dsnActor['dosen']->id_dosen,
        ]);

        $this->actingAs($dsnActor['user'])
            ->getJson("/api/admin/penugasan-kaprodi/{$fixturePenugasan->id}")
            ->assertStatus(403)
            ->assertExactJson($expectedDeniedMessage);

        $this->actingAs($dsnActor['user'])
            ->patchJson("/api/admin/penugasan-kaprodi/{$fixturePenugasan->id}", [
                'is_aktif'        => false,
                'tanggal_selesai' => $mutationDate,
            ])
            ->assertStatus(403)
            ->assertExactJson($expectedDeniedMessage);
        $fixturePenugasan->refresh();
        $this->assertTrue((bool) $fixturePenugasan->is_aktif);
        $this->assertNull($fixturePenugasan->tanggal_selesai);

        $this->actingAs($dsnActor['user'])
            ->patchJson("/api/admin/penugasan-kaprodi/{$fixturePenugasan->id}/nonaktifkan")
            ->assertStatus(403)
            ->assertExactJson($expectedDeniedMessage);
        $fixturePenugasan->refresh();
        $this->assertTrue((bool) $fixturePenugasan->is_aktif);
        $this->assertNull($fixturePenugasan->tanggal_selesai);
    }

    /**
     * 3. Admin dapat membaca daftar penugasan Kaprodi dan status operasionalnya.
     */
    public function test_admin_can_list_penugasan_kaprodi(): void
    {
        $admin = $this->buatAdmin();
        $dsn = $this->buatDosen('1234567801');

        PenugasanKaprodi::create([
            'dosen_id'        => $dsn['dosen']->id_dosen,
            'prodi'           => 'Informatika',
            'tanggal_mulai'   => now()->subDays(10)->toDateString(),
            'tanggal_selesai' => null,
            'is_aktif'        => true,
        ]);

        $response = $this->actingAs($admin)
            ->getJson('/api/admin/penugasan-kaprodi')
            ->assertStatus(200)
            ->assertJsonPath('status', 'success');

        $this->assertNotEmpty($response->json('data'));
        $item = collect($response->json('data'))->firstWhere('dosen_id', $dsn['dosen']->id_dosen);
        $this->assertNotNull($item);
        $this->assertEquals('Aktif', $item['status_operasional']);
        $this->assertEquals('Informatika', $item['prodi']);
    }

    /**
     * 4. Admin dapat membaca opsi dosen dengan nilai ID dosen (id_dosen) yang benar.
     */
    public function test_admin_can_get_dosen_options(): void
    {
        $admin = $this->buatAdmin();
        $dsn = $this->buatDosen('1234567802');

        $response = $this->actingAs($admin)
            ->getJson('/api/admin/penugasan-kaprodi/dosen-options')
            ->assertStatus(200)
            ->assertJsonPath('status', 'success');

        $dosenList = $response->json('data');
        $found = collect($dosenList)->firstWhere('id_dosen', $dsn['dosen']->id_dosen);
        $this->assertNotNull($found);
        $this->assertEquals($dsn['dosen']->nama_dosen, $found['nama_dosen']);
    }

    /**
     * 5. Admin dapat membuat penugasan Kaprodi yang sah.
     */
    public function test_admin_can_create_valid_active_penugasan(): void
    {
        $admin = $this->buatAdmin();
        $dsn = $this->buatDosen('1234567803');

        $payload = [
            'dosen_id'        => $dsn['dosen']->id_dosen,
            'prodi'           => 'Pendidikan Bahasa dan Sastra Indonesia',
            'tanggal_mulai'   => now()->toDateString(),
            'tanggal_selesai' => null,
            'is_aktif'        => true,
        ];

        $response = $this->actingAs($admin)
            ->postJson('/api/admin/penugasan-kaprodi', $payload)
            ->assertStatus(201)
            ->assertJsonPath('status', 'success');

        $this->assertDatabaseHas('penugasan_kaprodi', [
            'dosen_id' => $dsn['dosen']->id_dosen,
            'prodi'    => 'Pendidikan Bahasa dan Sastra Indonesia',
            'is_aktif' => true,
        ]);
    }

    /**
     * 6. Pembuatan penugasan menolak ID dosen yang tidak terdaftar di tabel dosen.
     */
    public function test_create_rejects_invalid_dosen_id(): void
    {
        $admin = $this->buatAdmin();

        $response = $this->actingAs($admin)
            ->postJson('/api/admin/penugasan-kaprodi', [
                'dosen_id'      => 9999999, // Tidak ada di tabel dosen
                'prodi'         => 'Informatika',
                'tanggal_mulai' => now()->toDateString(),
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['dosen_id']);
    }

    /**
     * 7. Pembuatan penugasan menolak nama program studi di luar 11 prodi resmi UNUHA.
     */
    public function test_create_rejects_invalid_prodi(): void
    {
        $admin = $this->buatAdmin();
        $dsn = $this->buatDosen('1234567804');

        $response = $this->actingAs($admin)
            ->postJson('/api/admin/penugasan-kaprodi', [
                'dosen_id'      => $dsn['dosen']->id_dosen,
                'prodi'         => 'Teknik Mesin Nuklir', // Tidak resmi
                'tanggal_mulai' => now()->toDateString(),
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['prodi']);
    }

    /**
     * 8. Pembuatan penugasan menolak jika tanggal selesai lebih awal dari tanggal mulai.
     */
    public function test_create_rejects_tanggal_selesai_before_tanggal_mulai(): void
    {
        $admin = $this->buatAdmin();
        $dsn = $this->buatDosen('1234567805');

        $response = $this->actingAs($admin)
            ->postJson('/api/admin/penugasan-kaprodi', [
                'dosen_id'        => $dsn['dosen']->id_dosen,
                'prodi'           => 'Informatika',
                'tanggal_mulai'   => '2026-10-25',
                'tanggal_selesai' => '2026-10-20', // Lebih awal dari mulai
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['tanggal_selesai']);
    }

    /**
     * 9. Penugasan dengan tanggal mulai masa depan berstatus 'Belum Berlaku' dan tidak memberi akses Kaprodi.
     */
    public function test_future_assignment_does_not_grant_kaprodi_access(): void
    {
        $admin = $this->buatAdmin();
        $dsn = $this->buatDosen('1234567806');

        PenugasanKaprodi::create([
            'dosen_id'        => $dsn['dosen']->id_dosen,
            'prodi'           => 'Informatika',
            'tanggal_mulai'   => now()->addMonth()->toDateString(), // Masa depan
            'tanggal_selesai' => null,
            'is_aktif'        => true,
        ]);

        // Status di daftar admin harus 'Belum Berlaku'
        $resAdmin = $this->actingAs($admin)->getJson('/api/admin/penugasan-kaprodi');
        $item = collect($resAdmin->json('data'))->firstWhere('dosen_id', $dsn['dosen']->id_dosen);
        $this->assertEquals('Belum Berlaku', $item['status_operasional']);

        // Dosen tidak boleh memiliki akses ke antrean Kaprodi
        $this->actingAs($dsn['user'])
            ->getJson('/api/kaprodi/pengajuan-judul')
            ->assertStatus(403);
    }

    /**
     * 10. Penugasan kedaluwarsa berstatus 'Kedaluwarsa' dan tidak memberi akses Kaprodi.
     */
    public function test_expired_assignment_does_not_grant_kaprodi_access(): void
    {
        $admin = $this->buatAdmin();
        $dsn = $this->buatDosen('1234567807');

        PenugasanKaprodi::create([
            'dosen_id'        => $dsn['dosen']->id_dosen,
            'prodi'           => 'Informatika',
            'tanggal_mulai'   => now()->subYear()->toDateString(),
            'tanggal_selesai' => now()->subDay()->toDateString(), // Kemarin
            'is_aktif'        => true,
        ]);

        $resAdmin = $this->actingAs($admin)->getJson('/api/admin/penugasan-kaprodi');
        $item = collect($resAdmin->json('data'))->firstWhere('dosen_id', $dsn['dosen']->id_dosen);
        $this->assertEquals('Kedaluwarsa', $item['status_operasional']);

        $this->actingAs($dsn['user'])
            ->getJson('/api/kaprodi/pengajuan-judul')
            ->assertStatus(403);
    }

    /**
     * 11. Penugasan nonaktif (is_aktif = false) berstatus 'Nonaktif' dan tidak memberi akses Kaprodi.
     */
    public function test_inactive_assignment_does_not_grant_kaprodi_access(): void
    {
        $admin = $this->buatAdmin();
        $dsn = $this->buatDosen('1234567808');

        PenugasanKaprodi::create([
            'dosen_id'        => $dsn['dosen']->id_dosen,
            'prodi'           => 'Informatika',
            'tanggal_mulai'   => now()->subMonth()->toDateString(),
            'tanggal_selesai' => null,
            'is_aktif'        => false,
        ]);

        $resAdmin = $this->actingAs($admin)->getJson('/api/admin/penugasan-kaprodi');
        $item = collect($resAdmin->json('data'))->firstWhere('dosen_id', $dsn['dosen']->id_dosen);
        $this->assertEquals('Nonaktif', $item['status_operasional']);

        $this->actingAs($dsn['user'])
            ->getJson('/api/kaprodi/pengajuan-judul')
            ->assertStatus(403);
    }

    /**
     * 12. Menolak penugasan aktif ganda pada prodi yang sama jika tanpa flag pergantian (HTTP 409).
     */
    public function test_prevent_conflicting_active_assignment_without_replace_flag(): void
    {
        $admin = $this->buatAdmin();
        $dsn1 = $this->buatDosen('1234567809');
        $dsn2 = $this->buatDosen('1234567810');

        PenugasanKaprodi::create([
            'dosen_id'        => $dsn1['dosen']->id_dosen,
            'prodi'           => 'Pendidikan Guru Madrasah Ibtidaiyah',
            'tanggal_mulai'   => now()->subMonth()->toDateString(),
            'tanggal_selesai' => null,
            'is_aktif'        => true,
        ]);

        // Coba menugaskan dosen 2 pada prodi Pendidikan Guru Madrasah Ibtidaiyah tanpa gantikan_penugasan_aktif
        $response = $this->actingAs($admin)
            ->postJson('/api/admin/penugasan-kaprodi', [
                'dosen_id'      => $dsn2['dosen']->id_dosen,
                'prodi'         => 'Pendidikan Guru Madrasah Ibtidaiyah',
                'tanggal_mulai' => now()->toDateString(),
            ])
            ->assertStatus(409)
            ->assertJsonPath('status', 'error');

        $this->assertStringContainsString('telah memiliki Kaprodi aktif', $response->json('message'));
    }

    /**
     * 13. Pergantian penugasan aktif dengan flag pergantian menonaktifkan Kaprodi lama dan menjaga riwayat.
     */
    public function test_assignment_replacement_maintains_history_and_transitions_safely(): void
    {
        $admin = $this->buatAdmin();
        $dsn1 = $this->buatDosen('1234567811');
        $dsn2 = $this->buatDosen('1234567812');

        $oldPenugasan = PenugasanKaprodi::create([
            'dosen_id'        => $dsn1['dosen']->id_dosen,
            'prodi'           => 'Pendidikan Agama Islam',
            'tanggal_mulai'   => now()->subMonths(6)->toDateString(),
            'tanggal_selesai' => null,
            'is_aktif'        => true,
        ]);

        $response = $this->actingAs($admin)
            ->postJson('/api/admin/penugasan-kaprodi', [
                'dosen_id'                 => $dsn2['dosen']->id_dosen,
                'prodi'                    => 'Pendidikan Agama Islam',
                'tanggal_mulai'            => now()->toDateString(),
                'gantikan_penugasan_aktif' => true,
            ])
            ->assertStatus(201);

        // Kaprodi lama harus tetap ada di basis data (tidak di-hard-delete)
        $oldPenugasan->refresh();
        $this->assertFalse((bool)$oldPenugasan->is_aktif);
        $this->assertNotNull($oldPenugasan->tanggal_selesai);

        // Kaprodi baru aktif
        $this->assertDatabaseHas('penugasan_kaprodi', [
            'dosen_id' => $dsn2['dosen']->id_dosen,
            'prodi'    => 'Pendidikan Agama Islam',
            'is_aktif' => true,
        ]);

        // Dosen 2 sekarang berwenang mengakses antrean Kaprodi
        $this->actingAs($dsn2['user'])
            ->getJson('/api/kaprodi/pengajuan-judul')
            ->assertStatus(200);

        // Dosen 1 dicabut hak akses Kaprodinya
        $this->actingAs($dsn1['user'])
            ->getJson('/api/kaprodi/pengajuan-judul')
            ->assertStatus(403);
    }

    /**
     * 14. Endpoint nonaktifkan mencabut akses secara instan dan mempertahankan riwayat.
     */
    public function test_deactivate_endpoint_revokes_access_safely(): void
    {
        $admin = $this->buatAdmin();
        $dsn = $this->buatDosen('1234567813');

        $penugasan = PenugasanKaprodi::create([
            'dosen_id'        => $dsn['dosen']->id_dosen,
            'prodi'           => 'Matematika',
            'tanggal_mulai'   => now()->subMonth()->toDateString(),
            'tanggal_selesai' => null,
            'is_aktif'        => true,
        ]);

        $this->actingAs($admin)
            ->patchJson("/api/admin/penugasan-kaprodi/{$penugasan->id}/nonaktifkan")
            ->assertStatus(200)
            ->assertJsonPath('status', 'success');

        $penugasan->refresh();
        $this->assertFalse((bool)$penugasan->is_aktif);
        $this->assertEquals(now()->toDateString(), $penugasan->tanggal_selesai->toDateString());

        // Hak akses langsung tertutup
        $this->actingAs($dsn['user'])
            ->getJson('/api/kaprodi/pengajuan-judul')
            ->assertStatus(403);
    }

    /**
     * 15. Verifikasi isolasi basis data: Pengujian berjalan 100% pada database manajemen_skripsi_test.
     */
    public function test_database_isolation_strictly_targets_test_database(): void
    {
        $session = DB::selectOne("SELECT DATABASE() as active_db, CURRENT_USER() as auth_user");
        $this->assertEquals('manajemen_skripsi_test', $session->active_db);
        $this->assertContains(
            $session->auth_user,
            ['unuha_tester@localhost', 'unuha_tester@127.0.0.1']
        );
    }

    /**
     * 16. Named lock dipastikan terbebas (IS_FREE_LOCK = 1) setelah operasi penugasan selesai.
     */
    public function test_named_lock_releases_cleanly_after_success(): void
    {
        $admin = $this->buatAdmin();
        $dsn = $this->buatDosen('1234567815');
        $prodi = 'Pendidikan Fisika';
        $lockKey = 'penugasan_kaprodi_lock_' . md5($prodi);

        $this->actingAs($admin)
            ->postJson('/api/admin/penugasan-kaprodi', [
                'dosen_id'      => $dsn['dosen']->id_dosen,
                'prodi'         => $prodi,
                'tanggal_mulai' => now()->toDateString(),
            ])
            ->assertStatus(201);

        // Periksa bahwa lock telah benar-benar dilepas dan bebas
        $isFree = DB::selectOne("SELECT IS_FREE_LOCK(?) as is_free", [$lockKey]);
        $this->assertEquals(1, (int) $isFree->is_free, "Named lock harus dalam keadaan bebas (released) setelah transaksi selesai");
    }

    /**
     * 17. Named lock gagal diperoleh (timeout karena dipegang koneksi lain) mengembalikan HTTP 503 secara fail-safe.
     */
    public function test_named_lock_timeout_returns_http_503(): void
    {
        $admin = $this->buatAdmin();
        $dsn = $this->buatDosen('1234567814');
        $prodi = 'Sains Pertanian';
        $lockKey = 'penugasan_kaprodi_lock_' . md5($prodi);

        // Buka koneksi PDO kedua yang sah ke database test
        $pdoOther = new \PDO(
            'mysql:host=127.0.0.1;port=3306;dbname=manajemen_skripsi_test',
            'unuha_tester',
            config('database.connections.mysql.password')
        );

        // Koneksi kedua menahan lock
        $acquired = $pdoOther->query("SELECT GET_LOCK('{$lockKey}', 5)")->fetchColumn();
        $this->assertEquals(1, (int)$acquired, "Koneksi penguji kedua harus berhasil memegang named lock");

        try {
            // Request admin mencoba membuat penugasan pada prodi Sains Pertanian
            // Controller mencoba GET_LOCK dengan timeout 3 detik, dan harus gagal (HTTP 503)
            $response = $this->actingAs($admin)
                ->postJson('/api/admin/penugasan-kaprodi', [
                    'dosen_id'      => $dsn['dosen']->id_dosen,
                    'prodi'         => $prodi,
                    'tanggal_mulai' => now()->toDateString(),
                ])
                ->assertStatus(503)
                ->assertJsonPath('status', 'error');

            $this->assertStringContainsString('Sistem sedang memproses penugasan', $response->json('message'));

            // Buktikan tidak ada mutasi basis data saat named lock gagal diperoleh
            $this->assertDatabaseMissing('penugasan_kaprodi', [
                'prodi' => $prodi,
            ]);
        } finally {
            // Lepas lock pada koneksi kedua
            $pdoOther->query("SELECT RELEASE_LOCK('{$lockKey}')");
            $pdoOther = null;
        }

        // Setelah lock dilepas, request yang sama kini harus berhasil dengan HTTP 201
        $this->actingAs($admin)
            ->postJson('/api/admin/penugasan-kaprodi', [
                'dosen_id'      => $dsn['dosen']->id_dosen,
                'prodi'         => $prodi,
                'tanggal_mulai' => now()->toDateString(),
            ])
            ->assertStatus(201);
    }

    /**
     * 18. Driver yang tidak mendukung GET_LOCK (atau kueri error) gagal secara fail-closed (HTTP 503) tanpa mutasi.
     */
    public function test_unsupported_or_failing_driver_fails_closed_without_executing_callback(): void
    {
        Log::spy();

        $controller = new \App\Http\Controllers\Admin\PenugasanKaprodiController();
        $refMethod = new \ReflectionMethod($controller, 'withNamedLock');
        $refMethod->setAccessible(true);

        // Buat koneksi sqlite in-memory yang tidak memiliki fungsi GET_LOCK
        $sqliteConn = DB::build([
            'driver'   => 'sqlite',
            'database' => ':memory:',
        ]);
        $this->app['db']->extend('sqlite_mem_test', fn() => $sqliteConn);

        $origDefault = $this->app['config']->get('database.default');
        $this->app['config']->set('database.default', 'sqlite_mem_test');
        $this->app['db']->setDefaultConnection('sqlite_mem_test');

        $callbackExecuted = false;

        try {
            /** @var \Illuminate\Http\JsonResponse $response */
            $response = $refMethod->invoke($controller, 'Teknik Sipil', function () use (&$callbackExecuted) {
                $callbackExecuted = true;
                return response()->json(['status' => 'success']);
            });

            $this->assertEquals(503, $response->getStatusCode());
            $this->assertFalse($callbackExecuted, "Callback TIDAK boleh dijalankan jika driver gagal menjalankan GET_LOCK");
            $this->assertStringContainsString('Terjadi kendala saat mengamankan antrean', $response->getData(true)['message']);

            // Verifikasi bahwa error dicatat ke log
            Log::shouldHaveReceived('error')->once();
        } finally {
            $this->app['config']->set('database.default', $origDefault);
            $this->app['db']->setDefaultConnection($origDefault);
        }
    }

    /**
     * 19. Exception dari callback/transaksi tidak ditutupi oleh pelepasan lock, dan lock tetap dilepas via finally.
     */
    public function test_callback_exception_is_not_masked_and_lock_is_released(): void
    {
        $controller = new \App\Http\Controllers\Admin\PenugasanKaprodiController();
        $refMethod = new \ReflectionMethod($controller, 'withNamedLock');
        $refMethod->setAccessible(true);

        $prodi = 'Biologi Terapan';
        $lockKey = 'penugasan_kaprodi_lock_' . md5($prodi);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Simulasi kegagalan bisnis dalam callback');

        try {
            $refMethod->invoke($controller, $prodi, function () {
                throw new \RuntimeException('Simulasi kegagalan bisnis dalam callback');
            });
        } finally {
            // Pastikan lock tetap dibebaskan pada jalur exception
            $isFree = DB::selectOne("SELECT IS_FREE_LOCK(?) as is_free", [$lockKey]);
            $this->assertEquals(1, (int) $isFree->is_free, "Lock harus sudah dilepas bahkan ketika callback melempar exception");
        }
    }

    /**
     * 20. Jika RELEASE_LOCK tidak terkonfirmasi (misal lock hilang/dilepas koneksi lain), peringatan dicatat ke log.
     */
    public function test_unconfirmed_release_lock_logs_warning(): void
    {
        Log::spy();

        $controller = new \App\Http\Controllers\Admin\PenugasanKaprodiController();
        $refMethod = new \ReflectionMethod($controller, 'withNamedLock');
        $refMethod->setAccessible(true);

        $prodi = 'Teknik Elektro';
        $lockKey = 'penugasan_kaprodi_lock_' . md5($prodi);

        $response = $refMethod->invoke($controller, $prodi, function () use ($lockKey) {
            // Simulasi: di tengah callback, lock sudah dilepas atau tidak ada
            // sehingga saat finally memanggil RELEASE_LOCK, statusnya NULL (tidak ditemukan)
            DB::select("SELECT RELEASE_LOCK(?)", [$lockKey]);
            return response()->json(['status' => 'success']);
        });

        $this->assertEquals(200, $response->getStatusCode());
        // Verifikasi bahwa warning dicatat karena pelepasan lock saat finally mengembalikan rel_status != 1
        Log::shouldHaveReceived('warning')->atLeast()->once();
    }

    /**
     * 21. Admin dapat membaca detail satu penugasan Kaprodi (show) dengan relasi dosen.user dan status operasional.
     */
    public function test_admin_can_view_single_penugasan_kaprodi_detail(): void
    {
        $admin = $this->buatAdmin();
        $dsn = $this->buatDosen('1234567831');
        $tglMulai = now()->subMonths(3)->toDateString();

        $penugasan = PenugasanKaprodi::create([
            'dosen_id'        => $dsn['dosen']->id_dosen,
            'prodi'           => 'Pendidikan Matematika',
            'tanggal_mulai'   => $tglMulai,
            'tanggal_selesai' => null,
            'is_aktif'        => true,
        ]);

        $response = $this->actingAs($admin)
            ->getJson("/api/admin/penugasan-kaprodi/{$penugasan->id}")
            ->assertStatus(200)
            ->assertJsonPath('status', 'success');

        $this->assertEquals($penugasan->id, $response->json('data.id'));
        $this->assertEquals('Pendidikan Matematika', $response->json('data.prodi'));
        $this->assertEquals('Aktif', $response->json('data.status_operasional'));
        $this->assertEquals($dsn['dosen']->id_dosen, $response->json('data.dosen.id_dosen'));
        $this->assertEquals($dsn['user']->id, $response->json('data.dosen.user.id'));
    }

    /**
     * 22. Admin dapat memperbarui penugasan Kaprodi yang valid (update) dan perubahan tersimpan di database.
     */
    public function test_admin_can_update_existing_penugasan_kaprodi(): void
    {
        $admin = $this->buatAdmin();
        $dsn = $this->buatDosen('1234567832');
        $tglMulaiAwal = now()->subMonths(4)->toDateString();
        $tglSelesaiBaru = now()->addMonths(6)->toDateString();

        $penugasan = PenugasanKaprodi::create([
            'dosen_id'        => $dsn['dosen']->id_dosen,
            'prodi'           => 'Pendidikan Fisika',
            'tanggal_mulai'   => $tglMulaiAwal,
            'tanggal_selesai' => null,
            'is_aktif'        => true,
        ]);

        // Catat record lain di prodi berbeda untuk memastikan tidak ada efek samping mutasi
        $dsnLain = $this->buatDosen('1234567833');
        $otherPenugasan = PenugasanKaprodi::create([
            'dosen_id'        => $dsnLain['dosen']->id_dosen,
            'prodi'           => 'Pendidikan Bahasa Inggris',
            'tanggal_mulai'   => $tglMulaiAwal,
            'tanggal_selesai' => null,
            'is_aktif'        => true,
        ]);

        $payload = [
            'tanggal_selesai' => $tglSelesaiBaru,
            'is_aktif'        => true,
        ];

        $response = $this->actingAs($admin)
            ->patchJson("/api/admin/penugasan-kaprodi/{$penugasan->id}", $payload)
            ->assertStatus(200)
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('message', 'Penugasan Kaprodi berhasil diperbarui.');

        $this->assertStringStartsWith(
            $tglSelesaiBaru,
            (string) $response->json('data.tanggal_selesai')
        );

        // Buktikan record target terbarukan di database
        $this->assertDatabaseHas('penugasan_kaprodi', [
            'id'              => $penugasan->id,
            'tanggal_selesai' => $tglSelesaiBaru,
            'is_aktif'        => true,
        ]);

        // Buktikan record lain tidak terpengaruh
        $otherPenugasan->refresh();
        $this->assertNull($otherPenugasan->tanggal_selesai);
        $this->assertTrue((bool) $otherPenugasan->is_aktif);
    }

    /**
     * 23. Pembaruan penugasan menolak konflik penugasan aktif ganda pada prodi yang sama jika tanpa flag pergantian (HTTP 409).
     */
    public function test_admin_update_rejects_conflicting_active_assignment_without_replace_flag(): void
    {
        $admin = $this->buatAdmin();
        $dsnA = $this->buatDosen('1234567834');
        $dsnB = $this->buatDosen('1234567835');

        $prodi = 'Pendidikan Ekonomi';
        $tglMulaiA = now()->subMonths(2)->toDateString();
        $tglMulaiB = now()->subMonths(6)->toDateString();
        $tglSelesaiB = now()->subMonths(2)->toDateString();
        $tglMulaiBaruB = now()->subDays(2)->toDateString();

        // Record A: Kaprodi aktif saat ini pada prodi Pendidikan Ekonomi
        $recordA = PenugasanKaprodi::create([
            'dosen_id'        => $dsnA['dosen']->id_dosen,
            'prodi'           => $prodi,
            'tanggal_mulai'   => $tglMulaiA,
            'tanggal_selesai' => null,
            'is_aktif'        => true,
        ]);

        // Record B: Riwayat penugasan lama (nonaktif) pada prodi yang sama
        $recordB = PenugasanKaprodi::create([
            'dosen_id'        => $dsnB['dosen']->id_dosen,
            'prodi'           => $prodi,
            'tanggal_mulai'   => $tglMulaiB,
            'tanggal_selesai' => $tglSelesaiB,
            'is_aktif'        => false,
        ]);

        // Coba perbarui Record B menjadi aktif tanpa menyertakan gantikan_penugasan_aktif
        $conflictPayload = [
            'is_aktif'        => true,
            'tanggal_mulai'   => $tglMulaiBaruB,
            'tanggal_selesai' => null,
        ];

        $response = $this->actingAs($admin)
            ->patchJson("/api/admin/penugasan-kaprodi/{$recordB->id}", $conflictPayload)
            ->assertStatus(409)
            ->assertExactJson([
                'status'  => 'error',
                'message' => "Program studi {$prodi} telah memiliki Kaprodi aktif lain. Nonaktifkan Kaprodi tersebut atau beri konfirmasi penggantian.",
            ]);

        // Verifikasi keutuhan 5 kolom pada Record A (harus tetap aktif tanpa terganggu)
        $recordA->refresh();
        $this->assertEquals($prodi, $recordA->prodi);
        $this->assertEquals($dsnA['dosen']->id_dosen, $recordA->dosen_id);
        $this->assertTrue((bool) $recordA->is_aktif);
        $this->assertEquals($tglMulaiA, $recordA->tanggal_mulai->toDateString());
        $this->assertNull($recordA->tanggal_selesai);

        // Verifikasi keutuhan 5 kolom pada Record B (harus tetap nonaktif tanpa mutasi parsial)
        $recordB->refresh();
        $this->assertEquals($prodi, $recordB->prodi);
        $this->assertEquals($dsnB['dosen']->id_dosen, $recordB->dosen_id);
        $this->assertFalse((bool) $recordB->is_aktif);
        $this->assertEquals($tglMulaiB, $recordB->tanggal_mulai->toDateString());
        $this->assertEquals($tglSelesaiB, $recordB->tanggal_selesai->toDateString());
    }

    /**
     * 24. Endpoint show, update, dan nonaktifkan deterministik mengembalikan HTTP 404 jika ID resource tidak ditemukan.
     */
    public function test_penugasan_kaprodi_returns_404_when_record_not_found(): void
    {
        $admin = $this->buatAdmin();

        $nonExistentId = ((int) (PenugasanKaprodi::max('id') ?? 0)) + 9999;
        $this->assertDatabaseMissing('penugasan_kaprodi', ['id' => $nonExistentId]);

        $initialCount = PenugasanKaprodi::count();
        $expectedNotFoundJson = [
            'status'  => 'error',
            'message' => 'Penugasan Kaprodi tidak ditemukan.',
        ];

        // 1. Show 404
        $this->actingAs($admin)
            ->getJson("/api/admin/penugasan-kaprodi/{$nonExistentId}")
            ->assertStatus(404)
            ->assertExactJson($expectedNotFoundJson);

        // 2. Update 404
        $this->actingAs($admin)
            ->patchJson("/api/admin/penugasan-kaprodi/{$nonExistentId}", [
                'is_aktif' => false,
            ])
            ->assertStatus(404)
            ->assertExactJson($expectedNotFoundJson);

        // 3. Nonaktifkan 404
        $this->actingAs($admin)
            ->patchJson("/api/admin/penugasan-kaprodi/{$nonExistentId}/nonaktifkan")
            ->assertStatus(404)
            ->assertExactJson($expectedNotFoundJson);

        // Pastikan jumlah record basis data tidak berubah
        $this->assertEquals($initialCount, PenugasanKaprodi::count());
    }
}
