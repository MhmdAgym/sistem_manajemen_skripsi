<?php

namespace App\Http\Controllers;

use App\Models\Mahasiswa;
use App\Models\Skripsi;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use App\Constants\AcademicConstant;

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

    // Tampilkan semua data mahasiswa beserta relasinya (Hanya untuk Admin)
    public function index(Request $request)
    {
        $user = $request->user();
        if (!$user || $user->role !== 'admin') {
            return response()->json([
                'message' => 'Hanya admin yang memiliki izin mengakses daftar lengkap mahasiswa.'
            ], 403);
        }

        $mahasiswa = Mahasiswa::with(['user', 'skripsi.dosenPA.dosen', 'skripsi.dosenPembimbing.dosen'])
            ->orderBy('id_mahasiswa', 'desc')
            ->get()
            ->map(fn($m) => $this->formatMahasiswa($m));

        return response()->json($mahasiswa);
    }

    // Simpan data mahasiswa baru (Hanya untuk Admin)
    public function store(Request $request)
    {
        $user = $request->user();
        if (!$user || $user->role !== 'admin') {
            return response()->json([
                'message' => 'Hanya admin yang memiliki izin menambahkan data mahasiswa.'
            ], 403);
        }

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

        // Validasi kesesuaian pasangan Fakultas dan Program Studi
        $normalizedFakultas = AcademicConstant::normalizeFakultas($validatedData['fakultas'] ?? null);
        if (!AcademicConstant::isValidPair($normalizedFakultas, $validatedData['prodi'] ?? null)) {
            return response()->json([
                'message' => 'Program studi tidak sesuai dengan fakultas yang dipilih.',
                'errors'  => [
                    'prodi' => ['Program studi tidak sesuai dengan fakultas yang dipilih.'],
                ],
            ], 422);
        }
        $validatedData['fakultas'] = $normalizedFakultas;

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

    // Tampilkan detail satu mahasiswa (Admin, Mahasiswa bersangkutan, atau Dosen PA/Pembimbing terkait)
    public function show(Request $request, $id)
    {
        $user = $request->user();
        if (!$user) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        $mahasiswa = Mahasiswa::with(['user', 'skripsi.dosenPA.dosen', 'skripsi.dosenPembimbing.dosen'])->find($id);

        if (!$mahasiswa) {
            return response()->json(['message' => 'Data mahasiswa tidak ditemukan'], 404);
        }

        // Otorisasi:
        // 1. Admin boleh melihat semua data
        // 2. Mahasiswa hanya boleh melihat datanya sendiri
        // 3. Dosen boleh melihat jika ia adalah Dosen PA atau Dosen Pembimbing mahasiswa tersebut
        if ($user->role !== 'admin') {
            $isOwner = ($user->role === 'mahasiswa' && $mahasiswa->user_id === $user->id);
            $isAssignedDosen = false;

            if ($user->role === 'dosen') {
                $dosen = \App\Models\Dosen::where('user_id', $user->id)->first();
                if ($dosen) {
                    $isAssignedDosen = $mahasiswa->skripsi && $mahasiswa->skripsi->contains(function ($s) use ($dosen) {
                        $isPA = $s->dosenPA && $s->dosenPA->id_dosen == $dosen->id_dosen;
                        $isPembimbing = $s->dosenPembimbing && $s->dosenPembimbing->contains('id_dosen', $dosen->id_dosen);
                        return $isPA || $isPembimbing;
                    });
                }
            }

            if (!$isOwner && !$isAssignedDosen) {
                return response()->json([
                    'message' => 'Anda tidak memiliki hak akses untuk melihat profil mahasiswa ini.'
                ], 403);
            }
        }

        return response()->json($this->formatMahasiswa($mahasiswa));
    }

    // Update data mahasiswa & Dosen PA oleh Admin
    public function update(Request $request, $id)
    {
        $user = $request->user();
        if (!$user || $user->role !== 'admin') {
            return response()->json([
                'message' => 'Hanya admin yang memiliki izin mengubah data mahasiswa.'
            ], 403);
        }

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

        // Pencegahan IDOR / Mass Assignment relasi user: user_id profil tidak boleh diubah sembarangan
        unset($validatedData['user_id']);

        // Validasi keabsahan pasangan Fakultas dan Program Studi jika diubah
        if ($request->has('fakultas') || $request->has('prodi')) {
            $targetFakultas = $request->has('fakultas') ? $request->input('fakultas') : $mahasiswa->fakultas;
            $targetProdi    = $request->has('prodi') ? $request->input('prodi') : $mahasiswa->prodi;

            $normalizedFakultas = AcademicConstant::normalizeFakultas($targetFakultas);

            if (!AcademicConstant::isValidPair($normalizedFakultas, $targetProdi)) {
                return response()->json([
                    'message' => 'Program studi tidak sesuai dengan fakultas yang dipilih.',
                    'errors'  => [
                        'prodi' => ['Program studi tidak sesuai dengan fakultas yang dipilih.'],
                    ],
                ], 422);
            }

            if ($request->has('fakultas')) {
                $validatedData['fakultas'] = $normalizedFakultas;
            }
        }

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

    // Hapus data mahasiswa (Hanya untuk Admin)
    public function destroy(Request $request, $id)
    {
        $user = $request->user();
        if (!$user || $user->role !== 'admin') {
            return response()->json([
                'message' => 'Hanya admin yang memiliki izin menghapus data mahasiswa.'
            ], 403);
        }

        $mahasiswa = Mahasiswa::find($id);

        if (!$mahasiswa) {
            return response()->json(['message' => 'Data mahasiswa tidak ditemukan'], 404);
        }

        $mahasiswa->delete();

        return response()->json(['message' => 'Data mahasiswa berhasil dihapus']);
    }
}