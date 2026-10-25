<?php

namespace App\Http\Controllers;

use App\Models\NilaiSempro;
use App\Models\PengujiSempro;
use Illuminate\Http\Request;

class NilaiSemproController extends Controller
{
    /**
     * Tampilkan data nilai sempro terfilter sesuai peran.
     */
    public function index(Request $request)
    {
        $user = $request->user();

        if ($user->role === 'admin') {
            $query = NilaiSempro::with(['sempro.skripsi.mahasiswa', 'pengujiSempro.dosen']);
            if ($request->filled('id_sempro')) {
                $query->where('id_sempro', $request->query('id_sempro'));
            }
            return response()->json($query->get());
        }

        if ($user->role === 'mahasiswa') {
            $mahasiswa = $user->mahasiswa;
            if (!$mahasiswa) {
                return response()->json(['message' => 'Profil mahasiswa tidak ditemukan.'], 404);
            }

            $nilai = NilaiSempro::with(['sempro.skripsi', 'pengujiSempro.dosen'])
                ->whereHas('sempro.skripsi', function ($q) use ($mahasiswa) {
                    $q->where('id_mahasiswa', $mahasiswa->id_mahasiswa);
                })
                ->get();

            return response()->json($nilai);
        }

        if ($user->role === 'dosen') {
            $dosen = $user->dosen;
            if (!$dosen) {
                return response()->json(['message' => 'Profil dosen tidak ditemukan.'], 404);
            }

            // Dosen melihat nilai yang mereka berikan atau nilai mahasiswa bimbingan skripsinya
            $nilai = NilaiSempro::with(['sempro.skripsi.mahasiswa', 'pengujiSempro.dosen'])
                ->where(function ($query) use ($dosen) {
                    $query->whereHas('pengujiSempro', function ($q) use ($dosen) {
                        $q->where('id_dosen', $dosen->id_dosen);
                    })->orWhereHas('sempro.skripsi.dosenPembimbing', function ($q) use ($dosen) {
                        $q->where('id_dosen', $dosen->id_dosen);
                    });
                })
                ->get();

            return response()->json($nilai);
        }

        return response()->json([
            'message' => 'Akses ditolak. Anda tidak memiliki izin untuk mengakses fitur ini.'
        ], 403);
    }

    /**
     * Simpan nilai seminar proposal (Hanya Dosen Penguji Berwenang atau Admin).
     */
    public function store(Request $request)
    {
        $user = $request->user();

        if ($user->role !== 'dosen' && $user->role !== 'admin') {
            return response()->json([
                'message' => 'Akses ditolak. Mahasiswa tidak berwenang menginput nilai seminar proposal.'
            ], 403);
        }

        $validatedData = $request->validate([
            'id_sempro'         => 'required|exists:sempro,id_sempro',
            'id_penguji_sempro' => 'required|exists:penguji_sempro,id_penguji_sempro',
            'tanggal_ujian'     => 'required|date',
            'status_ujian'      => 'required|in:lulus,tidak lulus',
        ]);

        $penguji = PengujiSempro::find($validatedData['id_penguji_sempro']);

        // Validasi kesesuaian penugasan penguji dengan sempro yang dinilai
        if ((int) $penguji->id_sempro !== (int) $validatedData['id_sempro']) {
            return response()->json([
                'message' => 'Penguji sempro tidak sesuai dengan seminar proposal yang ditentukan.',
                'errors'  => [
                    'id_penguji_sempro' => ['Penguji sempro tidak terdaftar pada seminar proposal ini.'],
                ],
            ], 422);
        }

        // Jika Dosen, wajib merupakan penguji yang ditugaskan bersangkutan
        if ($user->role === 'dosen') {
            $dosen = $user->dosen;
            if (!$dosen || (int) $penguji->id_dosen !== (int) $dosen->id_dosen) {
                return response()->json([
                    'message' => 'Akses ditolak. Anda bukan dosen penguji yang ditugaskan pada sesi ini.'
                ], 403);
            }
        }

        // Cegah duplikasi nilai dari penguji yang sama pada sempro yang sama
        $exists = NilaiSempro::where('id_sempro', $validatedData['id_sempro'])
            ->where('id_penguji_sempro', $validatedData['id_penguji_sempro'])
            ->first();

        if ($exists) {
            return response()->json([
                'message' => 'Penilaian untuk penguji ini pada seminar proposal bersangkutan sudah pernah disimpan.',
                'errors'  => [
                    'id_penguji_sempro' => ['Nilai dari penguji ini sudah ada pada seminar proposal ini.'],
                ],
            ], 422);
        }

        $nilai = NilaiSempro::create($validatedData);

        return response()->json([
            'message' => 'Data nilai sempro berhasil ditambahkan',
            'data'    => $nilai->load(['sempro', 'pengujiSempro.dosen'])
        ], 201);
    }

    /**
     * Tampilkan detail satu penilaian sempro.
     */
    public function show(Request $request, $id)
    {
        $user = $request->user();
        $nilai = NilaiSempro::with(['sempro.skripsi.mahasiswa', 'pengujiSempro.dosen'])->find($id);

        if (!$nilai) {
            return response()->json(['message' => 'Data nilai sempro tidak ditemukan'], 404);
        }

        if ($user->role === 'admin') {
            return response()->json($nilai);
        }

        if ($user->role === 'mahasiswa') {
            $mahasiswa = $user->mahasiswa;
            if (!$mahasiswa || (int) $nilai->sempro?->skripsi?->id_mahasiswa !== (int) $mahasiswa->id_mahasiswa) {
                return response()->json([
                    'message' => 'Akses ditolak. Anda tidak berhak melihat nilai seminar proposal ini.'
                ], 403);
            }
            return response()->json($nilai);
        }

        if ($user->role === 'dosen') {
            $dosen = $user->dosen;
            $isPenguji = $dosen && (int) $nilai->pengujiSempro?->id_dosen === (int) $dosen->id_dosen;
            $isPembimbing = $dosen && $nilai->sempro?->skripsi?->dosenPembimbing->contains('id_dosen', $dosen->id_dosen);

            if (!$isPenguji && !$isPembimbing) {
                return response()->json([
                    'message' => 'Akses ditolak. Anda bukan penguji atau pembimbing pada seminar proposal ini.'
                ], 403);
            }
            return response()->json($nilai);
        }

        return response()->json([
            'message' => 'Akses ditolak. Anda tidak memiliki izin untuk melihat data ini.'
        ], 403);
    }

    /**
     * Perbarui nilai seminar proposal (Hanya Penguji Bersangkutan atau Admin).
     */
    public function update(Request $request, $id)
    {
        $user = $request->user();

        if ($user->role !== 'dosen' && $user->role !== 'admin') {
            return response()->json([
                'message' => 'Akses ditolak. Mahasiswa tidak memiliki kewenangan mengubah nilai seminar proposal.'
            ], 403);
        }

        $nilai = NilaiSempro::with('pengujiSempro')->find($id);

        if (!$nilai) {
            return response()->json(['message' => 'Data nilai sempro tidak ditemukan'], 404);
        }

        if ($user->role === 'dosen') {
            $dosen = $user->dosen;
            if (!$dosen || (int) $nilai->pengujiSempro?->id_dosen !== (int) $dosen->id_dosen) {
                return response()->json([
                    'message' => 'Akses ditolak. Anda bukan dosen penguji yang memberikan nilai ini.'
                ], 403);
            }
        }

        $validatedData = $request->validate([
            'id_sempro'         => 'sometimes|required|exists:sempro,id_sempro',
            'id_penguji_sempro' => 'sometimes|required|exists:penguji_sempro,id_penguji_sempro',
            'tanggal_ujian'     => 'sometimes|required|date',
            'status_ujian'      => 'sometimes|required|in:lulus,tidak lulus',
        ]);

        // Cegah perubahan relasi foreign key oleh non-admin
        if ($user->role !== 'admin') {
            if (isset($validatedData['id_sempro']) && (int) $validatedData['id_sempro'] !== (int) $nilai->id_sempro) {
                return response()->json([
                    'message' => 'Akses ditolak. Mengubah relasi seminar proposal pada nilai tidak diizinkan.'
                ], 403);
            }
            if (isset($validatedData['id_penguji_sempro']) && (int) $validatedData['id_penguji_sempro'] !== (int) $nilai->id_penguji_sempro) {
                return response()->json([
                    'message' => 'Akses ditolak. Mengubah relasi penugasan penguji pada nilai tidak diizinkan.'
                ], 403);
            }
        }

        $nilai->update($validatedData);

        return response()->json([
            'message' => 'Data nilai sempro berhasil diperbarui',
            'data'    => $nilai->load(['sempro', 'pengujiSempro.dosen'])
        ]);
    }

    /**
     * Hapus nilai seminar proposal (Khusus Administrator).
     */
    public function destroy(Request $request, $id)
    {
        $user = $request->user();
        if ($user->role !== 'admin') {
            return response()->json([
                'message' => 'Akses ditolak. Menghapus data nilai sempro hanya diizinkan untuk Administrator.'
            ], 403);
        }

        $nilai = NilaiSempro::find($id);

        if (!$nilai) {
            return response()->json(['message' => 'Data nilai sempro tidak ditemukan'], 404);
        }

        $nilai->delete();

        return response()->json(['message' => 'Data nilai sempro berhasil dihapus']);
    }
}