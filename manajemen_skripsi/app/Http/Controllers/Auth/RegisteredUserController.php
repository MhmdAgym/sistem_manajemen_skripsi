<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\Mahasiswa;
use App\Models\Dosen;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules;

class RegisteredUserController extends Controller
{
    public function store(Request $request): Response
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => [
                'required',
                'string',
                'lowercase',
                'email',
                'max:255',
                'unique:users,email',
            ],
            'password' => [
                'required',
                'confirmed',
                Rules\Password::defaults(),
            ],
            'role' => [
                'required',
                'string',
                'in:mahasiswa,dosen',
            ],
            'nomor_induk' => [
                'required',
                'string',
                'regex:/^[0-9]{1,10}$/',
                'unique:users,nomor_induk',
            ],
            'no_handphone' => [
                'required',
                'string',
                'regex:/^[0-9+\-\s]{8,20}$/',
            ],
            'jenis_kelamin' => [
                'required',
                'in:Laki-laki,Perempuan',
            ],

            // Khusus mahasiswa
            'fakultas' => [
                'required_if:role,mahasiswa',
                'nullable',
                'in:Fakultas Agama Islam,Fakultas Ilmu Pendidikan,Fakultas Sains dan Teknologi',
            ],
            'prodi' => [
                'required_if:role,mahasiswa',
                'nullable',
                'string',
                'max:255',
            ],
        ]);

        // Pastikan prodi sesuai dengan fakultas yang dipilih.
        $daftarProdi = [
            'Fakultas Agama Islam' => [
                'Pendidikan Agama Islam',
                'Pendidikan Guru Madrasah Ibtidaiyah',
                'Pendidikan Bahasa Arab',
            ],
            'Fakultas Ilmu Pendidikan' => [
                'Pendidikan Fisika',
                'Pendidikan Ekonomi',
                'Pendidikan Bahasa Inggris',
                'Pendidikan Bahasa dan Sastra Indonesia',
                'Pendidikan Teknologi Informasi',
            ],
            'Fakultas Sains dan Teknologi' => [
                'Informatika',
                'Matematika',
                'Sains Pertanian',
            ],
        ];

        if (
            $validated['role'] === 'mahasiswa' &&
            !in_array(
                $validated['prodi'],
                $daftarProdi[$validated['fakultas']] ?? [],
                true
            )
        ) {
            return response()->json([
                'message' => 'Program studi tidak sesuai dengan fakultas.',
                'errors' => [
                    'prodi' => [
                        'Program studi tidak sesuai dengan fakultas yang dipilih.',
                    ],
                ],
            ], 422);
        }

        DB::transaction(function () use ($validated, &$user) {
            $user = User::create([
    'name' => $validated['name'],
    'email' => $validated['email'],
    'password' => Hash::make($validated['password']),
    'role' => $validated['role'],
    'nomor_induk' => $validated['nomor_induk'],
]);

            if ($validated['role'] === 'mahasiswa') {
                Mahasiswa::create([
                    'user_id' => $user->id,
                    'NIM' => $validated['nomor_induk'],
                    'nama_mahasiswa' => $validated['name'],
                    'no_handphone' => $validated['no_handphone'],
                    'jenis_kelamin' => $validated['jenis_kelamin'],
                    'fakultas' => $validated['fakultas'],
                    'prodi' => $validated['prodi'],
                ]);
            } else {
                Dosen::create([
                    'user_id' => $user->id,
                    'NIDN' => $validated['nomor_induk'],
                    'nama_dosen' => $validated['name'],
                    'no_handphone' => $validated['no_handphone'],
                    'jenis_kelamin' => $validated['jenis_kelamin'],
                ]);
            }
        });

        event(new Registered($user));

Auth::login($user);

$request->session()->regenerate();

return response()->noContent();
    }
}
