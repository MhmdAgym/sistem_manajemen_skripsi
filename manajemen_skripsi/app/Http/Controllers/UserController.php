<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class UserController extends Controller
{
    private function formatUser(User $user): User
    {
        $profil = null;

        if ($user->mahasiswa) {
            $profil = [
                'tipe'          => 'mahasiswa',
                'id_mahasiswa'  => $user->mahasiswa->id_mahasiswa,
                'nomor_induk'   => $user->mahasiswa->NIM,
                'prodi'         => $user->mahasiswa->prodi,
                'fakultas'      => $user->mahasiswa->fakultas,
                'no_handphone'  => $user->mahasiswa->no_handphone,
                'jenis_kelamin' => $user->mahasiswa->jenis_kelamin,
            ];
        } elseif ($user->dosen) {
            $profil = [
                'tipe'          => 'dosen',
                'id_dosen'      => $user->dosen->id_dosen,
                'nomor_induk'   => $user->dosen->NIDN,
                'no_handphone'  => $user->dosen->no_handphone,
                'jenis_kelamin' => $user->dosen->jenis_kelamin,
            ];
        } elseif ($user->admin) {
            $profil = [
                'tipe'       => 'admin',
                'id_admin'   => $user->admin->id_admin,
                'nama_admin' => $user->admin->nama_admin,
            ];
        }

        $user->profil_akademik = $profil;

        return $user;
    }

    /**
     * Tampilkan daftar seluruh pengguna beserta profil akademik terkait.
     */
    public function index(Request $request)
    {
        $query = User::with(['mahasiswa', 'dosen', 'admin']);

        // Filter berdasarkan role
        if ($request->filled('role') && in_array($request->role, ['admin', 'dosen', 'mahasiswa'])) {
            $query->where('role', $request->role);
        }

        // Filter berdasarkan status verifikasi email
        if ($request->filled('verified')) {
            if ($request->verified === '1' || $request->verified === 'true') {
                $query->whereNotNull('email_verified_at');
            } elseif ($request->verified === '0' || $request->verified === 'false') {
                $query->whereNull('email_verified_at');
            }
        }

        // Filter pencarian nama, email, nomor_induk
        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('email', 'like', "%{$search}%")
                  ->orWhere('nomor_induk', 'like', "%{$search}%");
            });
        }

        $users = $query->orderBy('id', 'desc')->get()->map(fn($u) => $this->formatUser($u));

        return response()->json($users);
    }

    /**
     * Tampilkan detail satu akun pengguna.
     */
    public function show($id)
    {
        $user = User::with(['mahasiswa', 'dosen', 'admin'])->find($id);

        if (!$user) {
            return response()->json(['message' => 'Akun pengguna tidak ditemukan'], 404);
        }

        return response()->json($this->formatUser($user));
    }

    /**
     * Perbarui data akun (nama, nomor induk, status verifikasi).
     * Dibatasi hanya untuk administrator berwenang. Tidak melayani pengubahan password secara sembarangan.
     */
    public function update(Request $request, $id)
    {
        $user = User::with(['mahasiswa', 'dosen', 'admin'])->find($id);

        if (!$user) {
            return response()->json(['message' => 'Akun pengguna tidak ditemukan'], 404);
        }

        // Cegah perubahan role secara sembarangan
        if ($request->has('role') && $request->role !== $user->role) {
            return response()->json([
                'message' => 'Perubahan role akun tidak diizinkan demi integritas data relasi akademik.'
            ], 422);
        }

        $validatedData = $request->validate([
            'name'           => 'sometimes|required|string|max:255',
            'nomor_induk'    => ['sometimes', 'nullable', 'string', 'max:20', Rule::unique('users', 'nomor_induk')->ignore($id)],
            'email_verified' => 'sometimes|boolean',
        ]);

        DB::transaction(function () use ($user, $validatedData, $request) {
            $userUpdates = [];

            if (isset($validatedData['name'])) {
                $userUpdates['name'] = $validatedData['name'];
            }

            if (array_key_exists('nomor_induk', $validatedData)) {
                $userUpdates['nomor_induk'] = $validatedData['nomor_induk'];
            }

            if ($request->has('email_verified')) {
                $userUpdates['email_verified_at'] = $request->boolean('email_verified') ? ($user->email_verified_at ?? now()) : null;
            }

            if (!empty($userUpdates)) {
                $user->update($userUpdates);
            }

            // Sinkronkan ke profil akademik terkait
            if ($user->mahasiswa) {
                $mhsUpdates = [];
                if (isset($userUpdates['name'])) {
                    $mhsUpdates['nama_mahasiswa'] = $userUpdates['name'];
                }
                if (isset($userUpdates['nomor_induk']) && !empty($userUpdates['nomor_induk'])) {
                    $mhsUpdates['NIM'] = substr($userUpdates['nomor_induk'], 0, 10);
                }
                if (!empty($mhsUpdates)) {
                    $user->mahasiswa->update($mhsUpdates);
                }
            } elseif ($user->dosen) {
                $dosenUpdates = [];
                if (isset($userUpdates['name'])) {
                    $dosenUpdates['nama_dosen'] = $userUpdates['name'];
                }
                if (isset($userUpdates['nomor_induk']) && !empty($userUpdates['nomor_induk'])) {
                    $dosenUpdates['NIDN'] = substr($userUpdates['nomor_induk'], 0, 10);
                }
                if (!empty($dosenUpdates)) {
                    $user->dosen->update($dosenUpdates);
                }
            } elseif ($user->admin) {
                if (isset($userUpdates['name'])) {
                    $user->admin->update(['nama_admin' => $userUpdates['name']]);
                }
            }
        });

        $user->load(['mahasiswa', 'dosen', 'admin']);

        return response()->json([
            'message' => 'Data akun berhasil diperbarui',
            'data'    => $this->formatUser($user)
        ]);
    }
}
