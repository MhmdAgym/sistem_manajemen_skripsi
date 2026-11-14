<?php

namespace Database\Seeders;

use App\Constants\AcademicConstant;
use App\Models\Admin;
use App\Models\Dosen;
use App\Models\DosenPA;
use App\Models\Mahasiswa;
use App\Models\PengajuanDospem;
use App\Models\PengajuanDospemLog;
use App\Models\PengajuanJudul;
use App\Models\PengajuanJudulLog;
use App\Models\PenugasanKaprodi;
use App\Models\Skripsi;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * UatDospemSeeder
 *
 * Seeder mandiri idempoten untuk mempersiapkan data uji UAT Fitur Dospem
 * (Admin, Kaprodi, Dosen PA, Calon Dospem, serta Mahasiswa Kasus A, B, C).
 *
 * DILARANG KERAS DIJALANKAN DI LUAR DATABASE 'manajemen_skripsi_uat'.
 */
class UatDospemSeeder extends Seeder
{
    /**
     * Jalankan seeder UAT dengan 4-Tier Preflight Guard & Transaksi Atomik.
     *
     * @throws \RuntimeException Jika terjadi pelanggaran lingkungan atau target koneksi.
     */
    public function run(): void
    {
        // =========================================================================
        // PREFLIGHT TIER 1: Validasi Status Lingkungan Framework
        // =========================================================================
        if (!app()->environment('uat')) {
            throw new \RuntimeException(
                'SECURITY VIOLATION: UatDospemSeeder can ONLY be executed in [uat] environment.'
            );
        }

        // =========================================================================
        // PREFLIGHT TIER 2: Validasi Konfigurasi Koneksi Database Klien
        // =========================================================================
        $conn = DB::connection();
        $dbName = (string) $conn->getConfig('database');
        $dbUser = (string) $conn->getConfig('username');
        $dbHost = (string) $conn->getConfig('host');

        if ($dbName !== 'manajemen_skripsi_uat') {
            throw new \RuntimeException(
                "SECURITY VIOLATION: Seeder configured database is [{$dbName}]. Only 'manajemen_skripsi_uat' is permitted."
            );
        }

        if ($dbUser === 'unuha_tester') {
            throw new \RuntimeException(
                "SECURITY VIOLATION: Seeder configured database user is [{$dbUser}]. Testing user 'unuha_tester' is prohibited."
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
                'SECURITY VIOLATION: Failed to execute server session probe in UAT Seeder: ' . $e->getMessage()
            );
        }

        if (!$probe || empty($probe->active_db) || empty($probe->auth_user)) {
            throw new \RuntimeException(
                'SECURITY VIOLATION: Server session probe returned empty or invalid results.'
            );
        }

        if ($probe->active_db !== 'manajemen_skripsi_uat') {
            throw new \RuntimeException(
                "SECURITY VIOLATION: Active server database is [{$probe->active_db}]. Refusing mutation."
            );
        }

        if ($probe->active_db === 'manajemen_skripsi' || $probe->active_db === 'manajemen_skripsi_test') {
            throw new \RuntimeException(
                "FATAL SECURITY VIOLATION: Attempted mutation on protected database [{$probe->active_db}]. Aborting."
            );
        }

        if (str_starts_with((string) $probe->auth_user, 'unuha_tester@')) {
            throw new \RuntimeException(
                "SECURITY VIOLATION: Authenticated server user is [{$probe->auth_user}]. Testing user prohibited."
            );
        }

        // =========================================================================
        // PREFLIGHT TIER 4: Validasi Konstanta Akademik & Eksekusi Atomik Idempoten
        // =========================================================================
        $prodiResmi = 'Informatika';
        if (!in_array($prodiResmi, AcademicConstant::ALL_PRODI, true)) {
            throw new \RuntimeException("CONFIG ERROR: Program studi [{$prodiResmi}] is not in official AcademicConstant.");
        }

        $fakultas = AcademicConstant::getFakultasForProdi($prodiResmi) ?? 'Fakultas Sains dan Teknologi';
        $uatDefaultPassword = Hash::make('password_uat_fiktif');

        DB::transaction(function () use ($prodiResmi, $fakultas, $uatDefaultPassword) {
            // ---------------------------------------------------------------------
            // 1. AKUN ADMINISTRATOR UAT
            // ---------------------------------------------------------------------
            $adminUser = User::updateOrCreate(
                ['email' => 'uat_admin@unuha.ac.id'],
                [
                    'name'              => 'Administrator UAT',
                    'password'          => $uatDefaultPassword,
                    'role'              => 'admin',
                    'nomor_induk'       => 'ADM-UAT-01',
                    'email_verified_at' => now(),
                ]
            );

            Admin::updateOrCreate(
                ['users_id' => $adminUser->id],
                [
                    'nama_admin' => 'Administrator UAT',
                ]
            );

            // ---------------------------------------------------------------------
            // 2. AKUN DOSEN KAPRODI INFORMATIKA (Pemberi Persetujuan Judul)
            // ---------------------------------------------------------------------
            $kaprodiUser = User::updateOrCreate(
                ['email' => 'uat_kaprodi_if@unuha.ac.id'],
                [
                    'name'              => 'Dr. Kaprodi Informatika, M.Kom.',
                    'password'          => $uatDefaultPassword,
                    'role'              => 'dosen',
                    'nomor_induk'       => '1122334401',
                    'email_verified_at' => now(),
                ]
            );

            $kaprodiDosen = Dosen::updateOrCreate(
                ['user_id' => $kaprodiUser->id],
                [
                    'NIDN'          => '1122334401',
                    'nama_dosen'    => 'Dr. Kaprodi Informatika, M.Kom.',
                    'no_handphone'  => '081234567801',
                    'jenis_kelamin' => 'Laki-laki',
                ]
            );

            PenugasanKaprodi::updateOrCreate(
                [
                    'dosen_id' => $kaprodiDosen->id_dosen,
                    'prodi'    => $prodiResmi,
                ],
                [
                    'is_aktif'        => true,
                    'tanggal_mulai'   => '2026-01-01',
                    'tanggal_selesai' => null,
                ]
            );

            // ---------------------------------------------------------------------
            // 3. AKUN DOSEN PEMBIMBING AKADEMIK (Dosen PA)
            // ---------------------------------------------------------------------
            $dosenPaUser = User::updateOrCreate(
                ['email' => 'uat_dosen_pa@unuha.ac.id'],
                [
                    'name'              => 'Dosen Pembimbing Akademik, M.Kom.',
                    'password'          => $uatDefaultPassword,
                    'role'              => 'dosen',
                    'nomor_induk'       => '1122334402',
                    'email_verified_at' => now(),
                ]
            );

            $dosenPaDosen = Dosen::updateOrCreate(
                ['user_id' => $dosenPaUser->id],
                [
                    'NIDN'          => '1122334402',
                    'nama_dosen'    => 'Dosen Pembimbing Akademik, M.Kom.',
                    'no_handphone'  => '081234567802',
                    'jenis_kelamin' => 'Laki-laki',
                ]
            );

            // ---------------------------------------------------------------------
            // 4. AKUN DOSEN CALON PEMBIMBING 1 & 2 (Pilihan Penetapan Admin)
            // ---------------------------------------------------------------------
            $dospem1User = User::updateOrCreate(
                ['email' => 'uat_dospem_1@unuha.ac.id'],
                [
                    'name'              => 'Calon Pembimbing Satu, M.Kom.',
                    'password'          => $uatDefaultPassword,
                    'role'              => 'dosen',
                    'nomor_induk'       => '1122334403',
                    'email_verified_at' => now(),
                ]
            );

            Dosen::updateOrCreate(
                ['user_id' => $dospem1User->id],
                [
                    'NIDN'          => '1122334403',
                    'nama_dosen'    => 'Calon Pembimbing Satu, M.Kom.',
                    'no_handphone'  => '081234567803',
                    'jenis_kelamin' => 'Laki-laki',
                ]
            );

            $dospem2User = User::updateOrCreate(
                ['email' => 'uat_dospem_2@unuha.ac.id'],
                [
                    'name'              => 'Calon Pembimbing Dua, M.Kom.',
                    'password'          => $uatDefaultPassword,
                    'role'              => 'dosen',
                    'nomor_induk'       => '1122334404',
                    'email_verified_at' => now(),
                ]
            );

            Dosen::updateOrCreate(
                ['user_id' => $dospem2User->id],
                [
                    'NIDN'          => '1122334404',
                    'nama_dosen'    => 'Calon Pembimbing Dua, M.Kom.',
                    'no_handphone'  => '081234567804',
                    'jenis_kelamin' => 'Perempuan',
                ]
            );

            // ---------------------------------------------------------------------
            // 5. MAHASISWA KASUS A — MEMENUHI SYARAT PENGAJUAN DOSPEM (Triple Verification OK)
            //    Target Skenario: TC-DSP-MHS-03 s.d. TC-DSP-MHS-05, TC-DSP-ADM-01 s.d. 04
            // ---------------------------------------------------------------------
            $mhsAUser = User::updateOrCreate(
                ['email' => 'uat_mhs_layak@unuha.ac.id'],
                [
                    'name'              => 'Mahasiswa UAT Kasus A (Layak)',
                    'password'          => $uatDefaultPassword,
                    'role'              => 'mahasiswa',
                    'nomor_induk'       => '2026010001',
                    'email_verified_at' => now(),
                ]
            );

            $mhsA = Mahasiswa::updateOrCreate(
                ['user_id' => $mhsAUser->id],
                [
                    'NIM'            => '2026010001',
                    'nama_mahasiswa' => 'Mahasiswa UAT Kasus A (Layak)',
                    'prodi'          => $prodiResmi,
                    'fakultas'       => $fakultas,
                    'no_handphone'   => '081234567811',
                    'jenis_kelamin'  => 'Laki-laki',
                ]
            );

            $skripsiA = Skripsi::updateOrCreate(
                ['id_mahasiswa' => $mhsA->id_mahasiswa],
                [
                    'judul'        => 'Implementasi Kecerdasan Buatan untuk Sistem Manajemen Skripsi',
                    'status_judul' => 'disetujui',
                ]
            );

            DosenPA::updateOrCreate(
                ['id_skripsi' => $skripsiA->id_skripsi],
                [
                    'id_dosen' => $dosenPaDosen->id_dosen,
                ]
            );

            $pengajuanJudulA = PengajuanJudul::updateOrCreate(
                [
                    'mahasiswa_id' => $mhsA->id_mahasiswa,
                    'skripsi_id'   => $skripsiA->id_skripsi,
                ],
                [
                    'dosen_pa_id'   => $dosenPaUser->id,
                    'judul'         => 'Implementasi Kecerdasan Buatan untuk Sistem Manajemen Skripsi',
                    'deskripsi'     => 'Deskripsi proposal skripsi UAT Kasus A untuk pengujian alur Dospem.',
                    'nama_file'     => 'proposal_uat_kasus_a.pdf',
                    'ukuran_file'   => '512 KB',
                    'file_path'     => 'pengajuan-judul/proposal_uat_kasus_a.pdf',
                    'status'        => 'Disetujui Kaprodi',
                    'catatan_dosen' => 'Disetujui Dosen PA untuk dilanjutkan ke Kaprodi.',
                ]
            );

            // Audit log persetujuan Kaprodi (memenuhi Triple Verification Gatekeeper)
            if (!PengajuanJudulLog::where('pengajuan_judul_id', $pengajuanJudulA->id)->where('tindakan', 'setujui_kaprodi')->exists()) {
                PengajuanJudulLog::create([
                    'pengajuan_judul_id' => $pengajuanJudulA->id,
                    'user_id'             => $kaprodiUser->id,
                    'aktor_nama'          => $kaprodiUser->name,
                    'aktor_role'          => 'kaprodi',
                    'aktor_nomor_induk'   => $kaprodiDosen->NIDN,
                    'tahap'               => 'judul',
                    'tindakan'            => 'setujui_kaprodi',
                    'status_sebelum'      => 'Menunggu Persetujuan Kaprodi',
                    'status_sesudah'      => 'Disetujui Kaprodi',
                    'catatan'             => 'Persetujuan resmi Kaprodi Informatika untuk UAT Kasus A.',
                    'snapshot_judul'      => $pengajuanJudulA->judul,
                    'snapshot_file_path'  => $pengajuanJudulA->file_path,
                    'created_at'          => now(),
                ]);
            }

            // ---------------------------------------------------------------------
            // 6. MAHASISWA KASUS B — BELUM MEMENUHI SYARAT (Judul Belum Disetujui)
            //    Target Skenario: TC-DSP-MHS-02 (Penolakan Kelayakan & Form Terkunci)
            // ---------------------------------------------------------------------
            $mhsBUser = User::updateOrCreate(
                ['email' => 'uat_mhs_belum_layak@unuha.ac.id'],
                [
                    'name'              => 'Mahasiswa UAT Kasus B (Belum Layak)',
                    'password'          => $uatDefaultPassword,
                    'role'              => 'mahasiswa',
                    'nomor_induk'       => '2026010002',
                    'email_verified_at' => now(),
                ]
            );

            $mhsB = Mahasiswa::updateOrCreate(
                ['user_id' => $mhsBUser->id],
                [
                    'NIM'            => '2026010002',
                    'nama_mahasiswa' => 'Mahasiswa UAT Kasus B (Belum Layak)',
                    'prodi'          => $prodiResmi,
                    'fakultas'       => $fakultas,
                    'no_handphone'   => '081234567812',
                    'jenis_kelamin'  => 'Laki-laki',
                ]
            );

            $skripsiB = Skripsi::updateOrCreate(
                ['id_mahasiswa' => $mhsB->id_mahasiswa],
                [
                    'judul'        => 'Perancangan Sistem Monitoring Akademik Berbasis Web',
                    'status_judul' => 'diajukan',
                ]
            );

            DosenPA::updateOrCreate(
                ['id_skripsi' => $skripsiB->id_skripsi],
                [
                    'id_dosen' => $dosenPaDosen->id_dosen,
                ]
            );

            PengajuanJudul::updateOrCreate(
                [
                    'mahasiswa_id' => $mhsB->id_mahasiswa,
                    'skripsi_id'   => $skripsiB->id_skripsi,
                ],
                [
                    'dosen_pa_id'   => $dosenPaUser->id,
                    'judul'         => 'Perancangan Sistem Monitoring Akademik Berbasis Web',
                    'deskripsi'     => 'Deskripsi proposal skripsi UAT Kasus B (sedang dalam peninjauan).',
                    'nama_file'     => 'proposal_uat_kasus_b.pdf',
                    'ukuran_file'   => '512 KB',
                    'file_path'     => 'pengajuan-judul/proposal_uat_kasus_b.pdf',
                    'status'        => 'Menunggu Verifikasi Dosen PA',
                    'catatan_dosen' => null,
                ]
            );

            // ---------------------------------------------------------------------
            // 7. MAHASISWA KASUS C — RIWAYAT PENGAJUAN DITOLAK & SIAP MENGAJUKAN ULANG
            //    Target Skenario: TC-DSP-MHS-06 (Pengajuan Ulang Pasca Penolakan Admin)
            // ---------------------------------------------------------------------
            $mhsCUser = User::updateOrCreate(
                ['email' => 'uat_mhs_ulang@unuha.ac.id'],
                [
                    'name'              => 'Mahasiswa UAT Kasus C (Pengajuan Ulang)',
                    'password'          => $uatDefaultPassword,
                    'role'              => 'mahasiswa',
                    'nomor_induk'       => '2026010003',
                    'email_verified_at' => now(),
                ]
            );

            $mhsC = Mahasiswa::updateOrCreate(
                ['user_id' => $mhsCUser->id],
                [
                    'NIM'            => '2026010003',
                    'nama_mahasiswa' => 'Mahasiswa UAT Kasus C (Pengajuan Ulang)',
                    'prodi'          => $prodiResmi,
                    'fakultas'       => $fakultas,
                    'no_handphone'   => '081234567813',
                    'jenis_kelamin'  => 'Perempuan',
                ]
            );

            $skripsiC = Skripsi::updateOrCreate(
                ['id_mahasiswa' => $mhsC->id_mahasiswa],
                [
                    'judul'        => 'Optimasi Algoritma Penjadwalan Sidang Skripsi',
                    'status_judul' => 'disetujui',
                ]
            );

            DosenPA::updateOrCreate(
                ['id_skripsi' => $skripsiC->id_skripsi],
                [
                    'id_dosen' => $dosenPaDosen->id_dosen,
                ]
            );

            $pengajuanJudulC = PengajuanJudul::updateOrCreate(
                [
                    'mahasiswa_id' => $mhsC->id_mahasiswa,
                    'skripsi_id'   => $skripsiC->id_skripsi,
                ],
                [
                    'dosen_pa_id'   => $dosenPaUser->id,
                    'judul'         => 'Optimasi Algoritma Penjadwalan Sidang Skripsi',
                    'deskripsi'     => 'Deskripsi proposal skripsi UAT Kasus C.',
                    'nama_file'     => 'proposal_uat_kasus_c.pdf',
                    'ukuran_file'   => '512 KB',
                    'file_path'     => 'pengajuan-judul/proposal_uat_kasus_c.pdf',
                    'status'        => 'Disetujui Kaprodi',
                    'catatan_dosen' => 'Disetujui Dosen PA.',
                ]
            );

            if (!PengajuanJudulLog::where('pengajuan_judul_id', $pengajuanJudulC->id)->where('tindakan', 'setujui_kaprodi')->exists()) {
                PengajuanJudulLog::create([
                    'pengajuan_judul_id' => $pengajuanJudulC->id,
                    'user_id'             => $kaprodiUser->id,
                    'aktor_nama'          => $kaprodiUser->name,
                    'aktor_role'          => 'kaprodi',
                    'aktor_nomor_induk'   => $kaprodiDosen->NIDN,
                    'tahap'               => 'judul',
                    'tindakan'            => 'setujui_kaprodi',
                    'status_sebelum'      => 'Menunggu Persetujuan Kaprodi',
                    'status_sesudah'      => 'Disetujui Kaprodi',
                    'catatan'             => 'Persetujuan resmi Kaprodi Informatika untuk UAT Kasus C.',
                    'snapshot_judul'      => $pengajuanJudulC->judul,
                    'snapshot_file_path'  => $pengajuanJudulC->file_path,
                    'created_at'          => now(),
                ]);
            }

            // Pengajuan Dospem Pertama yang Berstatus 'ditolak'
            // Virtual Column active_tahap di MySQL bernilai NULL, sehingga unik index tidak terbentur
            $pengajuanDospemC = PengajuanDospem::updateOrCreate(
                [
                    'skripsi_id'   => $skripsiC->id_skripsi,
                    'mahasiswa_id' => $mhsC->id_mahasiswa,
                    'tahap'        => 'proposal',
                ],
                [
                    'status'            => 'ditolak',
                    'dosen_id'          => null,
                    'catatan_mahasiswa' => 'Permohonan pengajuan awal calon dosen pembimbing proposal.',
                    'catatan_admin'     => 'Ditolak Admin UAT: Topik perlu disesuaikan dengan bidang keahlian dosen. Silakan lakukan pengajuan ulang.',
                ]
            );

            // Audit Log Submit Dospem Kasus C
            if (!PengajuanDospemLog::where('pengajuan_dospem_id', $pengajuanDospemC->id)->where('tindakan', 'submit')->exists()) {
                PengajuanDospemLog::create([
                    'pengajuan_dospem_id' => $pengajuanDospemC->id,
                    'user_id'             => $mhsCUser->id,
                    'aktor_nama'          => $mhsCUser->name,
                    'aktor_role'          => 'mahasiswa',
                    'aktor_nomor_induk'   => $mhsC->NIM,
                    'tahap'               => 'proposal',
                    'tindakan'            => 'submit',
                    'status_sebelum'      => null,
                    'status_sesudah'      => 'menunggu_penetapan',
                    'catatan'             => 'Permohonan pengajuan awal calon dosen pembimbing proposal.',
                    'snapshot_dosen_id'   => null,
                    'snapshot_dosen_nama' => null,
                    'created_at'          => now()->subDays(1),
                ]);
            }

            // Audit Log Tolak Dospem Kasus C oleh Admin
            if (!PengajuanDospemLog::where('pengajuan_dospem_id', $pengajuanDospemC->id)->where('tindakan', 'tolak')->exists()) {
                PengajuanDospemLog::create([
                    'pengajuan_dospem_id' => $pengajuanDospemC->id,
                    'user_id'             => $adminUser->id,
                    'aktor_nama'          => $adminUser->name,
                    'aktor_role'          => 'admin',
                    'aktor_nomor_induk'   => 'ADM-UAT-01',
                    'tahap'               => 'proposal',
                    'tindakan'            => 'tolak',
                    'status_sebelum'      => 'menunggu_penetapan',
                    'status_sesudah'      => 'ditolak',
                    'catatan'             => 'Ditolak Admin UAT: Topik perlu disesuaikan dengan bidang keahlian dosen. Silakan lakukan pengajuan ulang.',
                    'snapshot_dosen_id'   => null,
                    'snapshot_dosen_nama' => null,
                    'created_at'          => now(),
                ]);
            }
        });
    }
}

