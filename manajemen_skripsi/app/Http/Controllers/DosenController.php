<?php

namespace App\Http\Controllers;

use App\Models\Dosen;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class DosenController extends Controller
{
    private function formatDosen(Dosen $d): Dosen
    {
        // Menghitung mahasiswa unik berdasarkan relasi dosen_pembimbing murni
        $mahasiswaList = $d->dosenPembimbing ? $d->dosenPembimbing
            ->map(function ($dp) {
                if (!$dp->skripsi || !$dp->skripsi->mahasiswa) {
                    return null;
                }
                $m = $dp->skripsi->mahasiswa;
                return [
                    'id_mahasiswa'   => $m->id_mahasiswa,
                    'NIM'            => $m->NIM,
                    'nama_mahasiswa' => $m->nama_mahasiswa,
                    'prodi'          => $m->prodi,
                    'fakultas'       => $m->fakultas,
                    'no_handphone'   => $m->no_handphone,
                    'judul_skripsi'  => $dp->skripsi->judul,
                    'status_judul'   => $dp->skripsi->status_judul,
                    'id_skripsi'     => $dp->skripsi->id_skripsi,
                ];
            })
            ->filter()
            ->unique('id_mahasiswa')
            ->values() : collect();

        $d->total_mahasiswa_bimbingan = $mahasiswaList->count();
        $d->mahasiswa_bimbingan = $mahasiswaList;

        return $d;
    }

    // Tampilkan semua data dosen beserta relasi bimbingan unik (Hanya untuk Admin)
    public function index(Request $request)
    {
        $user = $request->user();
        if (!$user || $user->role !== 'admin') {
            return response()->json([
                'message' => 'Hanya admin yang memiliki izin mengakses daftar lengkap dosen.'
            ], 403);
        }

        $dosen = Dosen::with(['user', 'dosenPembimbing.skripsi.mahasiswa'])
            ->orderBy('id_dosen', 'desc')
            ->get()
            ->map(fn($d) => $this->formatDosen($d));

        return response()->json($dosen);
    }

    // Simpan data dosen baru (Hanya untuk Admin)
    public function store(Request $request)
    {
        $user = $request->user();
        if (!$user || $user->role !== 'admin') {
            return response()->json([
                'message' => 'Hanya admin yang memiliki izin menambahkan data dosen.'
            ], 403);
        }

        $validatedData = $request->validate([
            'NIDN'          => 'required|string|max:10|unique:dosen,NIDN',
            'user_id'       => 'required|exists:users,id',
            'nama_dosen'    => 'required|string|max:255',
            'no_handphone'  => 'nullable|string|max:20',
            'jenis_kelamin' => 'nullable|string|in:Laki-laki,Perempuan',
        ]);

        $dosen = DB::transaction(function () use ($validatedData) {
            $d = Dosen::create($validatedData);

            // Sinkronkan ke akun user jika ada
            if ($d->user) {
                $d->user->update([
                    'name'        => $d->nama_dosen,
                    'nomor_induk' => $d->NIDN,
                ]);
            }

            return $d;
        });

        $dosen->load(['user', 'dosenPembimbing.skripsi.mahasiswa']);

        return response()->json([
            'message' => 'Data dosen berhasil ditambahkan',
            'data'    => $this->formatDosen($dosen)
        ], 201);
    }

    // Tampilkan detail satu dosen (Admin atau Dosen bersangkutan)
    public function show(Request $request, $id)
    {
        $user = $request->user();
        if (!$user) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        $dosen = Dosen::with(['user', 'dosenPembimbing.skripsi.mahasiswa'])->find($id);

        if (!$dosen) {
            return response()->json(['message' => 'Data dosen tidak ditemukan'], 404);
        }

        if ($user->role !== 'admin') {
            if ($user->role !== 'dosen' || $dosen->user_id !== $user->id) {
                return response()->json([
                    'message' => 'Anda tidak memiliki hak akses untuk melihat profil dosen ini.'
                ], 403);
            }
        }

        return response()->json($this->formatDosen($dosen));
    }

    // Update data dosen (Hanya untuk Admin)
    public function update(Request $request, $id)
    {
        $user = $request->user();
        if (!$user || $user->role !== 'admin') {
            return response()->json([
                'message' => 'Hanya admin yang memiliki izin mengubah data dosen.'
            ], 403);
        }

        $dosen = Dosen::find($id);

        if (!$dosen) {
            return response()->json(['message' => 'Data dosen tidak ditemukan'], 404);
        }

        $validatedData = $request->validate([
            'NIDN'          => ['sometimes', 'required', 'string', 'max:10', Rule::unique('dosen', 'NIDN')->ignore($id, 'id_dosen')],
            'user_id'       => 'sometimes|required|exists:users,id',
            'nama_dosen'    => 'sometimes|required|string|max:255',
            'no_handphone'  => 'nullable|string|max:20',
            'jenis_kelamin' => 'nullable|string|in:Laki-laki,Perempuan',
        ]);

        // Pencegahan IDOR / manipulasi relasi user
        unset($validatedData['user_id']);

        DB::transaction(function () use ($dosen, $validatedData) {
            $dosen->update($validatedData);

            // Sinkronkan dengan user terkait
            if ($dosen->user) {
                $userUpdates = [];
                if (isset($validatedData['nama_dosen'])) {
                    $userUpdates['name'] = $validatedData['nama_dosen'];
                }
                if (isset($validatedData['NIDN'])) {
                    $userUpdates['nomor_induk'] = $validatedData['NIDN'];
                }
                if (!empty($userUpdates)) {
                    $dosen->user->update($userUpdates);
                }
            }
        });

        $dosen->load(['user', 'dosenPembimbing.skripsi.mahasiswa']);

        return response()->json([
            'message' => 'Data dosen berhasil diperbarui',
            'data'    => $this->formatDosen($dosen)
        ]);
    }

    // Hapus data dosen (Hanya untuk Admin)
    public function destroy(Request $request, $id)
    {
        $user = $request->user();
        if (!$user || $user->role !== 'admin') {
            return response()->json([
                'message' => 'Hanya admin yang memiliki izin menghapus data dosen.'
            ], 403);
        }

        $dosen = Dosen::find($id);

        if (!$dosen) {
            return response()->json(['message' => 'Data dosen tidak ditemukan'], 404);
        }

        $dosen->delete();

        return response()->json(['message' => 'Data dosen berhasil dihapus']);
    }
}