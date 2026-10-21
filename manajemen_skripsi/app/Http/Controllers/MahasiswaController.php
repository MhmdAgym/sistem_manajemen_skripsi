<?php

namespace App\Http\Controllers;

use App\Models\Mahasiswa;
use App\Models\Skripsi;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class MahasiswaController extends Controller
{
    private function formatMahasiswa(Mahasiswa $m): Mahasiswa
    {
        // Ambil skripsi aktif (terbaru)
        $activeSkripsi = $m->skripsi ? $m->skripsi->sortByDesc('id_skripsi')->first() : null;

        // dosenPA sekarang hasOne — akses sebagai single object per skripsi
        $dosenPA = $m->skripsi ? $m->skripsi->map(function ($s) {
            $pa = $s->dosenPA; // single object (hasOne)
            if (!$pa || !$pa->dosen) return null;
            return [
                'id_dosen'   => $pa->dosen->id_dosen,
                'nama_dosen' => $pa->dosen->nama_dosen,
                'NIDN'       => $pa->dosen->NIDN,
                'user_id'    => $pa->dosen->user_id, // Untuk referensi dosen_pa_id di pengajuan judul
            ];
        })->filter()->unique('id_dosen')->values() : collect();

        $dosenPembimbing = $m->skripsi ? $m->skripsi->flatMap(function ($s) {
            return $s->dosenPembimbing->map(fn($dp) => $dp->dosen?->nama_dosen);
        })->filter()->unique()->values() : collect();

        $m->active_skripsi = $activeSkripsi;
        $m->dosen_pa_list = $dosenPA->pluck('nama_dosen');
        // Menyediakan objek lengkap Dosen PA agar mudah dibaca di dashboard mahasiswa
        $m->dosen_pa_object = $dosenPA->first() ?? null;

        $m->dosen_pembimbing_list = $dosenPembimbing;

        return $m;
    }

    /**
     * [MAHASISWA] Mengambil profil mahasiswa yang sedang login beserta Dosen PA-nya
     */
    public function myProfile(Request $request)
    {
        $user = $request->user();

        if (!$user) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        $mahasiswa = Mahasiswa::with(['user', 'skripsi.dosenPA.dosen', 'skripsi.dosenPembimbing.dosen'])
            ->where('user_id', $user->id)
            ->first();

        if (!$mahasiswa) {
            return response()->json(['message' => 'Data profil mahasiswa tidak ditemukan'], 404);
        }

        $formatted = $this->formatMahasiswa($mahasiswa);

        return response()->json([
            'status'         => 'success',
            'id_mahasiswa'   => $formatted->id_mahasiswa,
            'NIM'            => $formatted->NIM,
            'nama_mahasiswa' => $formatted->nama_mahasiswa,
            'prodi'          => $formatted->prodi,
            'fakultas'       => $formatted->fakultas,
            'no_handphone'   => $formatted->no_handphone,
            'jenis_kelamin'  => $formatted->jenis_kelamin,
            'active_skripsi' => $formatted->active_skripsi,
            // Mengirimkan objek Dosen PA agar langsung terbaca di MainDashboard
            'dosen_pa'       => $formatted->dosen_pa_object, 
        ]);
    }

    // Tampilkan semua data mahasiswa beserta relasinya (untuk Admin)
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
            'dosen_pa_id'    => 'nullable|exists:dosen,id_dosen',
        ]);

        $mahasiswa = DB::transaction(function () use ($validatedData, $request) {
            $m = Mahasiswa::create($validatedData);

            if ($m->user) {
                $m->user->update([
                    'name'        => $m->nama_mahasiswa,
                    'nomor_induk' => $m->NIM,
                ]);
            }

            if ($request->filled('dosen_pa_id')) {
                $skripsi = Skripsi::firstOrCreate(
                    ['id_mahasiswa' => $m->id_mahasiswa],
                    ['status_judul' => 'Belum Mengajukan', 'judul' => 'Menunggu Pengajuan Judul']
                );

                \App\Models\DosenPA::updateOrCreate(
                    ['id_skripsi' => $skripsi->id_skripsi],
                    ['id_dosen' => $request->dosen_pa_id]
                );
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

    // Update data mahasiswa & Dosen PA oleh Admin
    public function update(Request $request, $id)
    {
        $mahasiswa = Mahasiswa::with('skripsi.dosenPA')->find($id);

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
            'dosen_pa_id'    => 'nullable|exists:dosen,id_dosen',
        ]);

        DB::transaction(function () use ($mahasiswa, $validatedData, $request) {
            $mahasiswa->update($validatedData);

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

            if ($request->has('dosen_pa_id')) {
                $activeSkripsi = $mahasiswa->skripsi()->orderByDesc('id_skripsi')->first();

                if (!$activeSkripsi) {
                    $activeSkripsi = Skripsi::create([
                        'id_mahasiswa' => $mahasiswa->id_mahasiswa,
                        'judul'        => 'Menunggu Pengajuan Judul',
                        'status_judul' => 'Belum Mengajukan',
                    ]);
                }

                if (!empty($request->dosen_pa_id)) {
                    \App\Models\DosenPA::updateOrCreate(
                        ['id_skripsi' => $activeSkripsi->id_skripsi],
                        ['id_dosen' => $request->dosen_pa_id]
                    );
                } else {
                    \App\Models\DosenPA::where('id_skripsi', $activeSkripsi->id_skripsi)->delete();
                }
            }
        });

        $mahasiswa->load(['user', 'skripsi.dosenPA.dosen', 'skripsi.dosenPembimbing.dosen']);

        return response()->json([
            'message' => 'Data mahasiswa dan Dosen PA berhasil diperbarui',
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