<?php

namespace Tests\Feature\Auth;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class RegistrationTest extends TestCase
{
    use DatabaseTransactions;

    public function test_new_users_can_register(): void
    {
        $response = $this->post('/register', [
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
            'role' => 'mahasiswa',
            'nomor_induk' => '12345678',
            'no_handphone' => '08123456789',
            'jenis_kelamin' => 'Laki-laki',
            'fakultas' => 'Fakultas Sains dan Teknologi',
            'prodi' => 'Informatika',
        ]);

        $this->assertAuthenticated();
        $response->assertNoContent();
    }
}
