<?php

namespace Database\Seeders;

use App\Models\User;
use App\Models\Admin;
use App\Models\Dosen;
use App\Models\Mahasiswa;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // 1. Akun Admin
        $adminUser = User::updateOrCreate(
            ['email' => 'admin@test.com'],
            [
                'name' => 'Administrator',
                'password' => 'password',
                'role' => 'admin',
                'nomor_induk' => null,
                'email_verified_at' => now(),
            ]
        );

        Admin::updateOrCreate(
            ['users_id' => $adminUser->id],
            [
                'nama_admin' => 'Administrator',
            ]
        );

        // 2. Akun Dosen
        $dosenUser = User::updateOrCreate(
            ['email' => 'dosen@test.com'],
            [
                'name' => 'Bapak Dosen',
                'password' => 'password',
                'role' => 'dosen',
                'nomor_induk' => '9988776655',
                'email_verified_at' => now(),
            ]
        );

        Dosen::updateOrCreate(
            ['user_id' => $dosenUser->id],
            [
                'NIDN' => '9988776655',
                'nama_dosen' => 'Bapak Dosen',
            ]
        );

        // 3. Akun Mahasiswa
        $mahasiswaUser = User::updateOrCreate(
            ['email' => 'mahasiswa@test.com'],
            [
                'name' => 'Agus Mahasiswa',
                'password' => 'password',
                'role' => 'mahasiswa',
                'nomor_induk' => '2010112233',
                'email_verified_at' => now(),
            ]
        );

        Mahasiswa::updateOrCreate(
            ['user_id' => $mahasiswaUser->id],
            [
                'NIM' => '2010112233',
                'nama_mahasiswa' => 'Agus Mahasiswa',
                'prodi' => 'Sistem Informasi',
            ]
        );
    }
}
