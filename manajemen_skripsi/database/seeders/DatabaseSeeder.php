<?php

namespace Database\Seeders;

use App\Models\User;
use App\Models\Admin;
use App\Models\Dosen;
use App\Models\Mahasiswa;
use App\Models\Skripsi;
use App\Models\DosenPA;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     * Menyediakan data lengkap untuk testing alur Pengajuan Judul:
     * Admin → menetapkan Dosen PA di tabel skripsi → Mahasiswa mengajukan → Dosen PA verifikasi
     */
    public function run(): void
    {
        // =========================================================
        // 1. Akun Admin
        // =========================================================
        $adminUser = User::updateOrCreate(
            ['email' => 'admin@test.com'],
            [
                'name'              => 'Administrator',
                'password'          => Hash::make('password'),
                'role'              => 'admin',
                'nomor_induk'       => null,
                'email_verified_at' => now(),
            ]
        );

        Admin::updateOrCreate(
            ['users_id' => $adminUser->id],
            [
                'nama_admin' => 'Administrator',
            ]
        );

        // =========================================================
        // 2. Akun Dosen
        // =========================================================
        $dosenUser = User::updateOrCreate(
            ['email' => 'dosen@test.com'],
            [
                'name'              => 'Bapak Dosen',
                'password'          => Hash::make('password'),
                'role'              => 'dosen',
                'nomor_induk'       => '9988776655',
                'email_verified_at' => now(),
            ]
        );

        $dosen = Dosen::updateOrCreate(
            ['user_id' => $dosenUser->id],
            [
                'NIDN'       => '9988776655',
                'nama_dosen' => 'Bapak Dosen',
            ]
        );

        // =========================================================
        // 3. Akun Mahasiswa
        // =========================================================
        $mahasiswaUser = User::updateOrCreate(
            ['email' => 'mahasiswa@test.com'],
            [
                'name'              => 'Agus Mahasiswa',
                'password'          => Hash::make('password'),
                'role'              => 'mahasiswa',
                'nomor_induk'       => '2010112233',
                'email_verified_at' => now(),
            ]
        );

        $mahasiswa = Mahasiswa::updateOrCreate(
            ['user_id' => $mahasiswaUser->id],
            [
                'NIM'            => '2010112233',
                'nama_mahasiswa' => 'Agus Mahasiswa',
                'prodi'          => 'Sistem Informasi',
            ]
        );

        // =========================================================
        // 4. Data Skripsi untuk Mahasiswa (dikelola Admin)
        //    Ini adalah data yang dibuat admin saat mahasiswa terdaftar
        // =========================================================
        $skripsi = Skripsi::updateOrCreate(
            ['id_mahasiswa' => $mahasiswa->id_mahasiswa],
            [
                'judul'        => 'Judul Sementara - Menunggu Pengajuan',
                'status_judul' => 'diajukan',
            ]
        );

        // =========================================================
        // 5. Penetapan Dosen PA oleh Admin
        //    Admin menetapkan dosen sebagai PA untuk skripsi mahasiswa
        // =========================================================
        DosenPA::updateOrCreate(
            ['id_skripsi' => $skripsi->id_skripsi],
            [
                'id_dosen' => $dosen->id_dosen,
            ]
        );
    }
}
