<?php

namespace Database\Seeders;

use App\Constants\AcademicConstant;
use App\Models\Dosen;
use App\Models\DosenPA;
use App\Models\Mahasiswa;
use App\Models\Skripsi;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * AdditiveTestFixtureSeeder
 *
 * Seeder murni aditif (zero deletion, zero update pada data eksisting) untuk
 * mempersiapkan 4 fixture mahasiswa baru (F-02 s.d. F-05) dalam rangka pengujian
 * terisolasi UAT Notifikasi Akademik N6–N7.
 *
 * DILARANG DIJALANKAN DI LUAR DATABASE 'manajemen_skripsi_test'.
 */
class AdditiveTestFixtureSeeder extends Seeder
{
    /**
     * Jalankan seeder aditif idempoten dengan 4-Tier Pre-Mutation Guard & Transaksi Atomik.
     *
     * @throws \RuntimeException Jika terjadi pelanggaran lingkungan, koneksi, atau konflik data.
     */
    public function run(): void
    {
        // =========================================================================
        // PREFLIGHT TIER 1: Validasi Status Lingkungan Framework
        // =========================================================================
        $isTesting = app()->environment('testing') || defined('STRICT_TEST_DATABASE_ISOLATION');
        if (!$isTesting) {
            throw new \RuntimeException(
                'SECURITY VIOLATION: AdditiveTestFixtureSeeder can ONLY be executed in testing environment.'
            );
        }

        // =========================================================================
        // PREFLIGHT TIER 2: Validasi Konfigurasi Koneksi Database Klien
        // =========================================================================
        $conn = DB::connection();
        $dbName = (string) $conn->getConfig('database');
        $dbUser = (string) $conn->getConfig('username');
        $dbHost = (string) $conn->getConfig('host');

        if ($dbName !== 'manajemen_skripsi_test') {
            throw new \RuntimeException(
                "SECURITY VIOLATION: Seeder configured database is [{$dbName}]. Only 'manajemen_skripsi_test' is permitted."
            );
        }

        if ($dbUser !== 'unuha_tester') {
            throw new \RuntimeException(
                "SECURITY VIOLATION: Seeder configured database user is [{$dbUser}]. Only 'unuha_tester' is permitted."
            );
        }

        $allowedHosts = ['127.0.0.1', 'localhost'];
        if (!in_array($dbHost, $allowedHosts, true)) {
            throw new \RuntimeException(
                "SECURITY VIOLATION: Seeder configured database host is [{$dbHost}]. Only loopback host is permitted."
            );
        }

        // =========================================================================
        // PREFLIGHT TIER 3: Probe Sesi Aktif MySQL Server Riil (Fail-Closed)
        // =========================================================================
        try {
            $probe = $conn->selectOne('SELECT DATABASE() as active_db, CURRENT_USER() as auth_user');
        } catch (\Throwable $e) {
            throw new \RuntimeException(
                'SECURITY VIOLATION: Failed to execute server session probe: ' . $e->getMessage()
            );
        }

        if (!$probe || empty($probe->active_db) || empty($probe->auth_user)) {
            throw new \RuntimeException(
                'SECURITY VIOLATION: Server session probe returned empty or invalid results.'
            );
        }

        if ($probe->active_db !== 'manajemen_skripsi_test') {
            throw new \RuntimeException(
                "SECURITY VIOLATION: Active server database is [{$probe->active_db}]. Refusing mutation."
            );
        }

        if ($probe->active_db === 'manajemen_skripsi') {
            throw new \RuntimeException(
                'FATAL SECURITY VIOLATION: Attempted mutation on operational database [manajemen_skripsi]. Aborting.'
            );
        }

        $allowedAuthUsers = ['unuha_tester@localhost', 'unuha_tester@127.0.0.1'];
        if (!in_array($probe->auth_user, $allowedAuthUsers, true)) {
            throw new \RuntimeException(
                "SECURITY VIOLATION: Authenticated server user is [{$probe->auth_user}]. Refusing mutation."
            );
        }

        // =========================================================================
        // PREFLIGHT TIER 4: Audit Provenans Master Dosen & Integritas Fixture Lama
        // (Berjalan murni secara read-only sebelum DML pertama)
        // =========================================================================

        // 1. Verifikasi Keberadaan Akun Dosen Pengujian Terdaftar
        $dosenKaprodiIf = Dosen::where('NIDN', '0011223301')->first();
        if (!$dosenKaprodiIf) {
            throw new \RuntimeException(
                'PREFLIGHT ERROR: Dosen Kaprodi IF Tester (NIDN 0011223301) not found in database testing.'
            );
        }

        $dosenPaTester = Dosen::where('NIDN', '0011223302')->first();
        if (!$dosenPaTester) {
            throw new \RuntimeException(
                'PREFLIGHT ERROR: Dosen PA Tester (NIDN 0011223302) not found in database testing.'
            );
        }

        $dosenBiasaTester = Dosen::where('NIDN', '0011223303')->first();
        if (!$dosenBiasaTester) {
            throw new \RuntimeException(
                'PREFLIGHT ERROR: Dosen Biasa Tester (NIDN 0011223303) not found in database testing.'
            );
        }

        // 2. Verifikasi Integritas Data Fixture Lama (Mahasiswa ID 488 / NIM 2101010001)
        // Pastikan tidak ada kondisi anomali sebelum seeder aditif bekerja
        $legacyMahasiswa = DB::table('mahasiswa')->where('NIM', '2101010001')->first();
        if ($legacyMahasiswa) {
            $legacySkripsi = DB::table('skripsi')->where('id_mahasiswa', $legacyMahasiswa->id_mahasiswa)->first();
            if ($legacySkripsi && $legacySkripsi->status_judul !== 'disetujui') {
                throw new \RuntimeException(
                    "PREFLIGHT INTEGRITY CONFLICT: Legacy thesis for NIM 2101010001 must remain 'disetujui'. Found [{$legacySkripsi->status_judul}]."
                );
            }
        }

        // 3. Verifikasi Prasyarat F-04: Prodi 'Pendidikan Bahasa Inggris' TIDAK MEMILIKI Kaprodi Aktif
        $pbiProdi = 'Pendidikan Bahasa Inggris';
        if (!in_array($pbiProdi, AcademicConstant::ALL_PRODI, true)) {
            throw new \RuntimeException("CONFIG ERROR: Program studi [{$pbiProdi}] is not in official AcademicConstant.");
        }

        $activeKaprodiPbi = DB::table('penugasan_kaprodi')
            ->where('prodi', $pbiProdi)
            ->where('is_aktif', true)
            ->exists();

        if ($activeKaprodiPbi) {
            throw new \RuntimeException(
                "PREFLIGHT CONFLICT: An active Kaprodi assignment already exists for [{$pbiProdi}]. F-04 requires a program study without active Kaprodi."
            );
        }

        // 4. Verifikasi Pasangan Fakultas-Prodi untuk Fixture
        if (!AcademicConstant::isValidPair('Fakultas Sains dan Teknologi', 'Informatika')) {
            throw new \RuntimeException('CONFIG ERROR: Invalid pair Fakultas Sains dan Teknologi - Informatika.');
        }

        if (!AcademicConstant::isValidPair('Fakultas Ilmu Pendidikan', $pbiProdi)) {
            throw new \RuntimeException('CONFIG ERROR: Invalid pair Fakultas Ilmu Pendidikan - Pendidikan Bahasa Inggris.');
        }

        // =========================================================================
        // DEFINISI 4 FIXTURE MAHASISWA BARU
        // =========================================================================
        $fixtureDefinitions = [
            'F-02' => [
                'email'       => 'unuha_mhs_if_baru@test.unuha.ac.id',
                'name'        => 'Mahasiswa IF Baru Tester',
                'nim'         => '2101010002',
                'prodi'       => 'Informatika',
                'fakultas'    => 'Fakultas Sains dan Teknologi',
                'gender'      => 'Laki-laki',
                'phone'       => '081200000002',
                'id_dosen_pa' => $dosenPaTester->id_dosen,
                'keterangan'  => 'Fixture UAT-01/02: Pengajuan judul dari kondisi fresh',
            ],
            'F-03' => [
                'email'       => 'unuha_mhs_if_rangkap@test.unuha.ac.id',
                'name'        => 'Mahasiswa IF Rangkap Tester',
                'nim'         => '2101010003',
                'prodi'       => 'Informatika',
                'fakultas'    => 'Fakultas Sains dan Teknologi',
                'gender'      => 'Perempuan',
                'phone'       => '081200000003',
                'id_dosen_pa' => $dosenKaprodiIf->id_dosen, // Dosen PA merangkap Kaprodi
                'keterangan'  => 'Fixture UAT-04: Pengujian kasus khusus Dosen PA merangkap Kaprodi',
            ],
            'F-04' => [
                'email'       => 'unuha_mhs_pbi_nokaprodi@test.unuha.ac.id',
                'name'        => 'Mahasiswa PBI No Kaprodi Tester',
                'nim'         => '2101010004',
                'prodi'       => $pbiProdi,
                'fakultas'    => 'Fakultas Ilmu Pendidikan',
                'gender'      => 'Perempuan',
                'phone'       => '081200000004',
                'id_dosen_pa' => $dosenBiasaTester->id_dosen,
                'keterangan'  => 'Fixture UAT-05: Pengujian kasus prodi tanpa Kaprodi aktif',
            ],
            'F-05' => [
                'email'       => 'unuha_mhs_if_alur@test.unuha.ac.id',
                'name'        => 'Mahasiswa IF Alur Lanjutan Tester',
                'nim'         => '2101010005',
                'prodi'       => 'Informatika',
                'fakultas'    => 'Fakultas Sains dan Teknologi',
                'gender'      => 'Laki-laki',
                'phone'       => '081200000005',
                'id_dosen_pa' => $dosenPaTester->id_dosen,
                'keterangan'  => 'Fixture UAT-03/06/07/08: Alur organik persetujuan judul & dospem',
            ],
        ];

        // 5. Validasi Konflik Identifier Alami Sebelum Transaksi (Read-Only Check)
        foreach ($fixtureDefinitions as $code => $fix) {
            // Cek apakah email sudah dikuasai akun lain dengan nomor_induk berbeda
            $existingUserByEmail = DB::table('users')->where('email', $fix['email'])->first();
            if ($existingUserByEmail && $existingUserByEmail->nomor_induk !== $fix['nim']) {
                throw new \RuntimeException(
                    "PREFLIGHT CONFLICT [{$code}]: Email [{$fix['email']}] already used by user with different nomor_induk [{$existingUserByEmail->nomor_induk}]."
                );
            }

            // Cek apakah NIM sudah dikuasai akun mahasiswa lain dengan email berbeda
            $existingUserByNim = DB::table('users')->where('nomor_induk', $fix['nim'])->first();
            if ($existingUserByNim && $existingUserByNim->email !== $fix['email']) {
                throw new \RuntimeException(
                    "PREFLIGHT CONFLICT [{$code}]: NIM [{$fix['nim']}] already used by user with different email [{$existingUserByNim->email}]."
                );
            }

            $existingMhs = DB::table('mahasiswa')->where('NIM', $fix['nim'])->first();
            if (
                $existingMhs &&
                (
                    !$existingUserByEmail ||
                    (int) $existingMhs->user_id !== (int) $existingUserByEmail->id
                )
            ) {
                $expectedUserId = $existingUserByEmail ? $existingUserByEmail->id : 'none';
                throw new \RuntimeException(
                    "PREFLIGHT CONFLICT [{$code}]: NIM [{$fix['nim']}] belongs to user ID [{$existingMhs->user_id}], expected [{$expectedUserId}]."
                );
            }
        }

        // =========================================================================
        // EKSEKUSI PENAMBAHAN FIXTURE DALAM TRANSAKSI ATOMIK (ADDITIVE-ONLY)
        // =========================================================================
        DB::transaction(function () use ($fixtureDefinitions) {
            $defaultPassword = Hash::make('password123');

            foreach ($fixtureDefinitions as $code => $fix) {
                // 1. Akun Pengguna (User)
                $user = User::where('email', $fix['email'])->first();
                if (!$user) {
                    $user = User::create([
                        'name'              => $fix['name'],
                        'email'             => $fix['email'],
                        'password'          => $defaultPassword,
                        'role'              => 'mahasiswa',
                        'nomor_induk'       => $fix['nim'],
                        'email_verified_at' => now(),
                    ]);
                } else {
                    // Validasi ketat jika sudah ada
                    if ($user->role !== 'mahasiswa' || $user->nomor_induk !== $fix['nim']) {
                        throw new \RuntimeException(
                            "MUTATION CONFLICT [{$code}]: Existing user record has incompatible attributes. Aborting."
                        );
                    }
                }

                // 2. Profil Mahasiswa
                $mahasiswa = Mahasiswa::where('NIM', $fix['nim'])->first();
                if (!$mahasiswa) {
                    $mahasiswa = Mahasiswa::create([
                        'user_id'        => $user->id,
                        'NIM'            => $fix['nim'],
                        'nama_mahasiswa' => $fix['name'],
                        'prodi'          => $fix['prodi'],
                        'fakultas'       => $fix['fakultas'],
                        'no_handphone'   => $fix['phone'],
                        'jenis_kelamin'  => $fix['gender'],
                    ]);
                } else {
                    // Validasi ketat jika sudah ada
                    if ((int) $mahasiswa->user_id !== (int) $user->id || $mahasiswa->prodi !== $fix['prodi']) {
                        throw new \RuntimeException(
                            "MUTATION CONFLICT [{$code}]: Existing mahasiswa record has incompatible attributes. Aborting."
                        );
                    }
                }

                // 3. Record Skripsi Mahasiswa (Status Awal 'Belum Mengajukan')
                // Menggunakan query tunggal untuk mutual exclusion mahasiswa-skripsi (1 mahasiswa = 1 skripsi)
                $skripsiList = Skripsi::where('id_mahasiswa', $mahasiswa->id_mahasiswa)->get();
                if ($skripsiList->count() > 1) {
                    throw new \RuntimeException(
                        "MUTATION CONFLICT [{$code}]: Multiple skripsi records found for mahasiswa ID [{$mahasiswa->id_mahasiswa}]."
                    );
                }

                $skripsi = $skripsiList->first();
                if (!$skripsi) {
                    $skripsi = Skripsi::create([
                        'id_mahasiswa' => $mahasiswa->id_mahasiswa,
                        'judul'        => 'Menunggu Pengajuan Judul',
                        'status_judul' => 'Belum Mengajukan',
                    ]);
                } else {
                    // Jika skripsi sudah ada, jangan menimpa status_judul jika sudah mengalami transisi organik
                    // Hanya verifikasi kepemilikan id_mahasiswa
                    if ((int) $skripsi->id_mahasiswa !== (int) $mahasiswa->id_mahasiswa) {
                        throw new \RuntimeException(
                            "MUTATION CONFLICT [{$code}]: Inconsistent skripsi ownership."
                        );
                    }
                }

                // 4. Penetapan Dosen Pembimbing Akademik (Dosen PA)
                $dosenPaList = DosenPA::where('id_skripsi', $skripsi->id_skripsi)->get();
                if ($dosenPaList->count() > 1) {
                    throw new \RuntimeException(
                        "MUTATION CONFLICT [{$code}]: Multiple DosenPA records found for skripsi ID [{$skripsi->id_skripsi}]."
                    );
                }

                $dosenPa = $dosenPaList->first();
                if (!$dosenPa) {
                    DosenPA::create([
                        'id_dosen'   => $fix['id_dosen_pa'],
                        'id_skripsi' => $skripsi->id_skripsi,
                    ]);
                } else {
                    if ((int) $dosenPa->id_dosen !== (int) $fix['id_dosen_pa']) {
                        throw new \RuntimeException(
                            "MUTATION CONFLICT [{$code}]: Existing DosenPA record assigned to different lecturer ID [{$dosenPa->id_dosen}]."
                        );
                    }
                }

                // =====================================================================
                // CATATAN ARSITEKTUR INTEGRITAS AKADEMIK:
                // Tidak ada record yang dibuat pada tabel:
                // - `pengajuan_judul`
                // - `pengajuan_judul_logs`
                // - `pengajuan_dospem`
                // - `pengajuan_dospem_logs`
                // - `dosen_pembimbing`
                //
                // Seluruh transisi pengajuan, verifikasi PA, persetujuan Kaprodi,
                // penolakan dospem, dan penetapan dospem wajib dilakukan secara organik
                // melalui alur resmi API/Controller pada saat UAT runtime kelak.
                // =====================================================================
            }
        });
    }
}
