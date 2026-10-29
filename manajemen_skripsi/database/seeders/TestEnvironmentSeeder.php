<?php

namespace Database\Seeders;

use App\Constants\AcademicConstant;
use App\Models\Admin;
use App\Models\Dosen;
use App\Models\DosenPA;
use App\Models\Mahasiswa;
use App\Models\PengajuanJudul;
use App\Models\PenugasanKaprodi;
use App\Models\Skripsi;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class TestEnvironmentSeeder extends Seeder
{
    /**
     * Jalankan seeder idempoten untuk 5 peran pengujian dengan 4-Tier Pre-Mutation Guard & Transaksi Atomik.
     * DILARANG DIJALANKAN DI LUAR DATABASE 'manajemen_skripsi_test'.
     */
    public function run(): void
    {
        // =========================================================================
        // PREFLIGHT TIER 1: Validasi Status Framework / Konstanta
        // =========================================================================
        $isTesting = app()->environment('testing') || defined('STRICT_TEST_DATABASE_ISOLATION');
        if (!$isTesting) {
            throw new \RuntimeException(
                "SECURITY VIOLATION: TestEnvironmentSeeder can ONLY be executed in testing environment."
            );
        }

        // =========================================================================
        // PREFLIGHT TIER 2: Validasi Konfigurasi Koneksi Database Klien
        // =========================================================================
        $conn = DB::connection();
        $dbName = (string) $conn->getConfig('database');
        $dbUser = (string) $conn->getConfig('username');

        if ($dbName !== 'manajemen_skripsi_test') {
            throw new \RuntimeException(
                "SECURITY VIOLATION: Seeder configured database is [{$dbName}]. Only 'manajemen_skripsi_test' is permitted."
            );
        }

        if ($dbUser !== 'unuha_tester') {
            throw new \RuntimeException(
                "SECURITY VIOLATION: Seeder configured user is [{$dbUser}]. Only 'unuha_tester' is permitted."
            );
        }

        // =========================================================================
        // PREFLIGHT TIER 3: Probe Sesi Aktif MySQL Server Riil
        // =========================================================================
        try {
            $probe = $conn->selectOne("SELECT DATABASE() as active_db, CURRENT_USER() as auth_user");
        } catch (\Throwable $e) {
            throw new \RuntimeException(
                "SECURITY VIOLATION: Failed to execute server session probe: " . $e->getMessage()
            );
        }

        if (!$probe || empty($probe->active_db) || empty($probe->auth_user)) {
            throw new \RuntimeException(
                "SECURITY VIOLATION: Server session probe returned empty or invalid results."
            );
        }

        if ($probe->active_db !== 'manajemen_skripsi_test') {
            throw new \RuntimeException(
                "SECURITY VIOLATION: Active server database is [{$probe->active_db}]. Refusing mutation."
            );
        }

        if ($probe->auth_user !== 'unuha_tester@localhost' && $probe->auth_user !== 'unuha_tester@127.0.0.1') {
            throw new \RuntimeException(
                "SECURITY VIOLATION: Authenticated server user is [{$probe->auth_user}]. Refusing mutation."
            );
        }

        // =========================================================================
        // PREFLIGHT TIER 4: Audit Konflik Record & Verifikasi Provenans Data Uji
        // (Berjalan murni secara read-only sebelum DML pertama)
        // =========================================================================
        $prodiResmi = 'Informatika';
        if (!in_array($prodiResmi, AcademicConstant::ALL_PRODI, true)) {
            throw new \RuntimeException("CONFIG ERROR: Program studi [{$prodiResmi}] is not in official AcademicConstant.");
        }

        $allTestNomorInduk = [
            'ADM-UNUHA-TEST-01',
            '0011223301',
            '0011223302',
            '0011223303',
            '2101010001',
        ];

        $allTestEmails = [
            'unuha_admin_test@unuha.ac.id',
            'unuha_kaprodi_if_test@unuha.ac.id',
            'unuha_dosen_pa_test@unuha.ac.id',
            'unuha_dosen_biasa_test@unuha.ac.id',
            'unuha_mhs_if_test@unuha.ac.id',
        ];

        $testDosenMapping = [
            '0011223301' => 'unuha_kaprodi_if_test@unuha.ac.id',
            '0011223302' => 'unuha_dosen_pa_test@unuha.ac.id',
            '0011223303' => 'unuha_dosen_biasa_test@unuha.ac.id',
        ];

        // 1. Periksa apakah ada nomor_induk testing yang dikuasai oleh akun dengan email asing
        $foreignNomorInduk = DB::table('users')
            ->whereIn('nomor_induk', $allTestNomorInduk)
            ->whereNotIn('email', $allTestEmails)
            ->exists();
        if ($foreignNomorInduk) {
            throw new \RuntimeException(
                "SECURITY PREFLIGHT CONFLICT: Unique identifier collision detected on table [users], column [nomor_induk]. Aborting seeder."
            );
        }

        // 2. Periksa apakah ada NIDN testing yang dikuasai oleh profil dosen dari akun email asing
        $foreignDosenNidn = DB::table('dosen')
            ->join('users', 'dosen.user_id', '=', 'users.id')
            ->whereIn('dosen.NIDN', array_keys($testDosenMapping))
            ->whereNotIn('users.email', $allTestEmails)
            ->exists();
        if ($foreignDosenNidn) {
            throw new \RuntimeException(
                "SECURITY PREFLIGHT CONFLICT: Unique identifier collision detected on table [dosen], column [NIDN]. Aborting seeder."
            );
        }

        // 3. Periksa apakah ada NIM testing yang dikuasai oleh profil mahasiswa dari akun email asing
        $foreignMahasiswaNim = DB::table('mahasiswa')
            ->join('users', 'mahasiswa.user_id', '=', 'users.id')
            ->where('mahasiswa.NIM', '2101010001')
            ->where('users.email', '!=', 'unuha_mhs_if_test@unuha.ac.id')
            ->exists();
        if ($foreignMahasiswaNim) {
            throw new \RuntimeException(
                "SECURITY PREFLIGHT CONFLICT: Unique identifier collision detected on table [mahasiswa], column [NIM]. Aborting seeder."
            );
        }

        // 4. Verifikasi provenans kepemilikan record uji jika akun sudah ada sebelumnya
        $expectedFixtureProfiles = [
            'unuha_admin_test@unuha.ac.id' => [
                'role'        => 'admin',
                'nomor_induk' => 'ADM-UNUHA-TEST-01',
            ],
            'unuha_kaprodi_if_test@unuha.ac.id' => [
                'role'        => 'dosen',
                'nomor_induk' => '0011223301',
                'nidn'        => '0011223301',
            ],
            'unuha_dosen_pa_test@unuha.ac.id' => [
                'role'        => 'dosen',
                'nomor_induk' => '0011223302',
                'nidn'        => '0011223302',
            ],
            'unuha_dosen_biasa_test@unuha.ac.id' => [
                'role'        => 'dosen',
                'nomor_induk' => '0011223303',
                'nidn'        => '0011223303',
            ],
            'unuha_mhs_if_test@unuha.ac.id' => [
                'role'        => 'mahasiswa',
                'nomor_induk' => '2101010001',
                'nim'         => '2101010001',
                'prodi'       => $prodiResmi,
            ],
        ];

        foreach ($expectedFixtureProfiles as $email => $expected) {
            $existingUser = DB::table('users')->where('email', $email)->first();
            if ($existingUser) {
                if ($existingUser->role !== $expected['role']) {
                    throw new \RuntimeException(
                        "SECURITY PREFLIGHT CONFLICT: Role mismatch on pre-existing user record in table [users]. Aborting seeder."
                    );
                }
                if (!empty($existingUser->nomor_induk) && $existingUser->nomor_induk !== $expected['nomor_induk']) {
                    throw new \RuntimeException(
                        "SECURITY PREFLIGHT CONFLICT: Identifier mismatch on pre-existing user record in table [users]. Aborting seeder."
                    );
                }

                if (isset($expected['nidn'])) {
                    $existingDosen = DB::table('dosen')->where('user_id', $existingUser->id)->first();
                    if ($existingDosen && $existingDosen->NIDN !== $expected['nidn']) {
                        throw new \RuntimeException(
                            "SECURITY PREFLIGHT CONFLICT: NIDN mismatch on pre-existing dosen profile in table [dosen]. Aborting seeder."
                        );
                    }
                }

                if (isset($expected['nim'])) {
                    $existingMhs = DB::table('mahasiswa')->where('user_id', $existingUser->id)->first();
                    if ($existingMhs) {
                        if ($existingMhs->NIM !== $expected['nim'] || $existingMhs->prodi !== $expected['prodi']) {
                            throw new \RuntimeException(
                                "SECURITY PREFLIGHT CONFLICT: NIM or prodi mismatch on pre-existing mahasiswa profile in table [mahasiswa]. Aborting seeder."
                            );
                        }
                    }
                }
            }
        }

        // 5. Periksa penugasan Kaprodi aktif untuk prodi Informatika: Jangan menimpa penugasan milik dosen lain
        $foreignActiveKaprodi = DB::table('penugasan_kaprodi')
            ->join('dosen', 'penugasan_kaprodi.dosen_id', '=', 'dosen.id_dosen')
            ->where('penugasan_kaprodi.prodi', $prodiResmi)
            ->where('penugasan_kaprodi.is_aktif', true)
            ->where('dosen.NIDN', '!=', '0011223301')
            ->exists();

        if ($foreignActiveKaprodi) {
            throw new \RuntimeException(
                "SECURITY PREFLIGHT CONFLICT: Active Kaprodi assignment for program study [Informatika] belongs to another unverified lecturer. Refusing to overwrite. Aborting seeder."
            );
        }

        // =========================================================================
        // EKSEKUSI MUTASI SEEDER DALAM TRANSAKSI ATOMIK
        // =========================================================================
        DB::transaction(function () use ($prodiResmi) {
            $defaultPassword = Hash::make('password123');

            // -------------------------------------------------------------------------
            // 1. Akun Admin
            // -------------------------------------------------------------------------
            $adminUser = User::updateOrCreate(
                ['email' => 'unuha_admin_test@unuha.ac.id'],
                [
                    'name'              => 'Admin Tester UNUHA',
                    'password'          => $defaultPassword,
                    'role'              => 'admin',
                    'nomor_induk'       => 'ADM-UNUHA-TEST-01',
                    'email_verified_at' => now(),
                ]
            );

            Admin::updateOrCreate(
                ['users_id' => $adminUser->id],
                [
                    'nama_admin' => 'Admin Tester UNUHA',
                ]
            );

            // -------------------------------------------------------------------------
            // 2. Akun Kaprodi Informatika
            // -------------------------------------------------------------------------
            $kaprodiUser = User::updateOrCreate(
                ['email' => 'unuha_kaprodi_if_test@unuha.ac.id'],
                [
                    'name'              => 'Kaprodi IF Tester, M.Kom.',
                    'password'          => $defaultPassword,
                    'role'              => 'dosen',
                    'nomor_induk'       => '0011223301',
                    'email_verified_at' => now(),
                ]
            );

            $kaprodiDosen = Dosen::updateOrCreate(
                ['user_id' => $kaprodiUser->id],
                [
                    'NIDN'          => '0011223301',
                    'nama_dosen'    => 'Kaprodi IF Tester, M.Kom.',
                    'no_handphone'  => '081234567891',
                    'jenis_kelamin' => 'Laki-laki',
                ]
            );

            // Penugasan Kaprodi Aktif (FK: dosen_id -> dosen.id_dosen)
            PenugasanKaprodi::updateOrCreate(
                [
                    'dosen_id' => $kaprodiDosen->id_dosen,
                    'prodi'    => $prodiResmi,
                ],
                [
                    'tanggal_mulai'   => '2026-01-01',
                    'tanggal_selesai' => null,
                    'is_aktif'        => true,
                ]
            );

            // -------------------------------------------------------------------------
            // 3. Akun Dosen PA (Pembimbing Akademik)
            // -------------------------------------------------------------------------
            $dosenPaUser = User::updateOrCreate(
                ['email' => 'unuha_dosen_pa_test@unuha.ac.id'],
                [
                    'name'              => 'Dosen PA Tester, M.Kom.',
                    'password'          => $defaultPassword,
                    'role'              => 'dosen',
                    'nomor_induk'       => '0011223302',
                    'email_verified_at' => now(),
                ]
            );

            $dosenPaDosen = Dosen::updateOrCreate(
                ['user_id' => $dosenPaUser->id],
                [
                    'NIDN'          => '0011223302',
                    'nama_dosen'    => 'Dosen PA Tester, M.Kom.',
                    'no_handphone'  => '081234567892',
                    'jenis_kelamin' => 'Laki-laki',
                ]
            );

            // -------------------------------------------------------------------------
            // 4. Akun Dosen Biasa (Tanpa Penugasan Kaprodi & PA - Negative Authorization)
            // -------------------------------------------------------------------------
            $dosenBiasaUser = User::updateOrCreate(
                ['email' => 'unuha_dosen_biasa_test@unuha.ac.id'],
                [
                    'name'              => 'Dosen Biasa Tester, M.Kom.',
                    'password'          => $defaultPassword,
                    'role'              => 'dosen',
                    'nomor_induk'       => '0011223303',
                    'email_verified_at' => now(),
                ]
            );

            Dosen::updateOrCreate(
                ['user_id' => $dosenBiasaUser->id],
                [
                    'NIDN'          => '0011223303',
                    'nama_dosen'    => 'Dosen Biasa Tester, M.Kom.',
                    'no_handphone'  => '081234567893',
                    'jenis_kelamin' => 'Perempuan',
                ]
            );

            // -------------------------------------------------------------------------
            // 5. Akun Mahasiswa Informatika & Alur Pengajuan Judul
            // -------------------------------------------------------------------------
            $mhsUser = User::updateOrCreate(
                ['email' => 'unuha_mhs_if_test@unuha.ac.id'],
                [
                    'name'              => 'Mahasiswa IF Tester',
                    'password'          => $defaultPassword,
                    'role'              => 'mahasiswa',
                    'nomor_induk'       => '2101010001',
                    'email_verified_at' => now(),
                ]
            );

            $mahasiswa = Mahasiswa::updateOrCreate(
                ['user_id' => $mhsUser->id],
                [
                    'NIM'            => '2101010001',
                    'nama_mahasiswa' => 'Mahasiswa IF Tester',
                    'prodi'          => $prodiResmi,
                    'fakultas'       => 'Fakultas Sains dan Teknologi',
                    'no_handphone'   => '081234567894',
                    'jenis_kelamin'  => 'Laki-laki',
                ]
            );

            // Data Skripsi Mahasiswa (FK: id_mahasiswa -> mahasiswa.id_mahasiswa)
            $skripsi = Skripsi::updateOrCreate(
                ['id_mahasiswa' => $mahasiswa->id_mahasiswa],
                [
                    'judul'        => 'Rancang Bangun Sistem Informasi Pengujian Terisolasi Berbasis AI',
                    'status_judul' => 'diajukan',
                ]
            );

            // Penetapan Dosen PA untuk Skripsi (FK: id_dosen -> dosen.id_dosen, id_skripsi -> skripsi.id_skripsi)
            DosenPA::updateOrCreate(
                ['id_skripsi' => $skripsi->id_skripsi],
                [
                    'id_dosen' => $dosenPaDosen->id_dosen,
                ]
            );

            // Pengajuan Judul Awal (Siap Ditinjau/Disetujui oleh Kaprodi)
            PengajuanJudul::updateOrCreate(
                [
                    'mahasiswa_id' => $mahasiswa->id_mahasiswa,
                    'judul'        => 'Rancang Bangun Sistem Informasi Pengujian Terisolasi Berbasis AI',
                ],
                [
                    'skripsi_id'    => $skripsi->id_skripsi,
                    'dosen_pa_id'   => $dosenPaUser->id,
                    'deskripsi'     => 'Deskripsi proposal skripsi untuk pengujian alur persetujuan judul oleh Kaprodi Informatika.',
                    'nama_file'     => 'proposal_skripsi_test.pdf',
                    'ukuran_file'   => '512 KB',
                    'file_path'     => 'pengajuan-judul/proposal_skripsi_test.pdf',
                    'status'        => 'Menunggu Persetujuan Kaprodi',
                    'catatan_dosen' => 'Disetujui Dosen PA untuk dilanjutkan ke verifikasi Kaprodi.',
                ]
            );
        });
    }
}
