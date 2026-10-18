<?php

namespace App\Http\Controllers;

use App\Models\Mahasiswa;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class MahasiswaController extends Controller
{
    private function formatMahasiswa(Mahasiswa $m): Mahasiswa
    {
        $activeSkripsi = $m->skripsi ? $m->skripsi->sortByDesc('id_skripsi')->first() : null;

        $dosenPA = $m->skripsi ? $m->skripsi->flatMap(function ($s) {
            return $s->dosenPA->map(fn($pa) => $pa->dosen?->nama_dosen);
        })->filter()->unique()->values() : collect();

        $dosenPembimbing = $m->skripsi ? $m->skripsi->flatMap(function ($s) {
            return $s->dosenPembimbing->map(fn($dp) => $dp->dosen?->nama_dosen);
        })->filter()->unique()->values() : collect();

        $m->active_skripsi = $activeSkripsi;
        $m->dosen_pa_list = $dosenPA;
        $m->dosen_pembimbing_list = $dosenPembimbing;

        return $m;
    }

    // Tampilkan semua data mahasiswa beserta relasinya
    public function index()
    {
        $mahasiswa = Mahasiswa::with(['user', 'skripsi.dosenPA.dosen', 'skripsi.dosenPembimbing.dosen'])
            ->orderBy('id_mahasiswa', 'desc')
            ->get()
            ->map(fn($m) => $this->formatMahasiswa($m));

        return response()->json($mahasiswa);
    }

    // Simpan data mahasiswa baru
    public function store(Request $request)
    {
        $validatedData = $request->validate([
            'NIM'            => 'required|string|max:10|unique:mahasiswa,NIM',
            'user_id'        => 'required|exists:users,id',
            'nama_mahasiswa' => 'required|string|max:255',
            'prodi'          => 'required|string|max:255',
            'fakultas'       => 'nullable|string|max:255',
            'no_handphone'   => 'nullable|string|max:20',
            'jenis_kelamin'  => 'nullable|string|in:Laki-laki,Perempuan',
        ]);

        $mahasiswa = DB::transaction(function () use ($validatedData) {
            $m = Mahasiswa::create($validatedData);

            // Sinkronkan nama dan nomor_induk pada user jika berbeda
            if ($m->user) {
                $m->user->update([
                    'name'        => $m->nama_mahasiswa,
                    'nomor_induk' => $m->NIM,
                ]);
            }

            return $m;
        });

        $mahasiswa->load(['user', 'skripsi.dosenPA.dosen', 'skripsi.dosenPembimbing.dosen']);

        return response()->json([
            'message' => 'Data mahasiswa berhasil ditambahkan',
            'data'    => $this->formatMahasiswa($mahasiswa)
        ], 201);
    }

    // Tampilkan detail satu mahasiswa
    public function show($id)
    {
        $mahasiswa = Mahasiswa::with(['user', 'skripsi.dosenPA.dosen', 'skripsi.dosenPembimbing.dosen'])->find($id);

        if (!$mahasiswa) {
            return response()->json(['message' => 'Data mahasiswa tidak ditemukan'], 404);
        }

        return response()->json($this->formatMahasiswa($mahasiswa));
    }

    // Update data mahasiswa
    public function update(Request $request, $id)
    {
        $mahasiswa = Mahasiswa::find($id);

        if (!$mahasiswa) {
            return response()->json(['message' => 'Data mahasiswa tidak ditemukan'], 404);
        }

        $validatedData = $request->validate([
            'NIM'            => ['sometimes', 'required', 'string', 'max:10', Rule::unique('mahasiswa', 'NIM')->ignore($id, 'id_mahasiswa')],
            'user_id'        => 'sometimes|required|exists:users,id',
            'nama_mahasiswa' => 'sometimes|required|string|max:255',
            'prodi'          => 'sometimes|required|string|max:255',
            'fakultas'       => 'nullable|string|max:255',
            'no_handphone'   => 'nullable|string|max:20',
            'jenis_kelamin'  => 'nullable|string|in:Laki-laki,Perempuan',
        ]);

        DB::transaction(function () use ($mahasiswa, $validatedData) {
            $mahasiswa->update($validatedData);

            // Sinkronkan dengan data User terkait secara konsisten
            if ($mahasiswa->user) {
                $userUpdates = [];
                if (isset($validatedData['nama_mahasiswa'])) {
                    $userUpdates['name'] = $validatedData['nama_mahasiswa'];
                }
                if (isset($validatedData['NIM'])) {
                    $userUpdates['nomor_induk'] = $validatedData['NIM'];
                }
                if (!empty($userUpdates)) {
                    $mahasiswa->user->update($userUpdates);
                }
            }
        });

        $mahasiswa->load(['user', 'skripsi.dosenPA.dosen', 'skripsi.dosenPembimbing.dosen']);

        return response()->json([
            'message' => 'Data mahasiswa berhasil diperbarui',
            'data'    => $this->formatMahasiswa($mahasiswa)
        ]);
    }

    // Hapus data mahasiswa
    public function destroy($id)
    {
        $mahasiswa = Mahasiswa::find($id);

        if (!$mahasiswa) {
            return response()->json(['message' => 'Data mahasiswa tidak ditemukan'], 404);
        }

        $mahasiswa->delete();

        return response()->json(['message' => 'Data mahasiswa berhasil dihapus']);
    }
}