<?php

namespace App\Http\Controllers;

use App\Models\DosenPengujiSkripsi;
use App\Models\NilaiSkripsi;
use Illuminate\Http\Request;

class NilaiSkripsiController extends Controller
{
    /**
     * Tampilkan data nilai skripsi terfilter sesuai peran.
     */
    public function index(Request $request)
    {
        $user = $request->user();

        if ($user->role === 'admin') {
            $query = NilaiSkripsi::with(['ujianSkripsi.sempro.skripsi.mahasiswa', 'dosen']);
            if ($request->filled('id_ujian_skripsi')) {
                $query->where('id_ujian_skripsi', $request->query('id_ujian_skripsi'));
            }
            return response()->json($query->get());
        }

        if ($user->role === 'mahasiswa') {
            $mahasiswa = $user->mahasiswa;
            if (!$mahasiswa) {
                return response()->json(['message' => 'Profil mahasiswa tidak ditemukan.'], 404);
            }

            $nilai = NilaiSkripsi::with(['ujianSkripsi.sempro.skripsi', 'dosen'])
                ->whereHas('ujianSkripsi.sempro.skripsi', function ($q) use ($mahasiswa) {
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

            $nilai = NilaiSkripsi::with(['ujianSkripsi.sempro.skripsi.mahasiswa', 'dosen'])
                ->where(function ($query) use ($dosen) {
                    $query->where('id_dosen', $dosen->id_dosen)
                          ->orWhereHas('ujianSkripsi.sempro.skripsi.dosenPembimbing', function ($q) use ($dosen) {
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
     * Simpan nilai ujian skripsi (Hanya Dosen Penguji Berwenang atau Admin).
     */
    public function store(Request $request)
    {
        $user = $request->user();

        if ($user->role !== 'dosen' && $user->role !== 'admin') {
            return response()->json([
                'message' => 'Akses ditolak. Mahasiswa tidak berwenang menginput nilai ujian skripsi.'
            ], 403);
        }

        $validatedData = $request->validate([
            'id_ujian_skripsi' => 'required|exists:ujian_skripsi,id_ujian_skripsi',
            'id_dosen'         => 'required|exists:dosen,id_dosen',
            'nilai'            => 'required|numeric|min:0|max:100',
            'catatan'          => 'nullable|string',
        ]);

        if ($user->role === 'dosen') {
            $dosen = $user->dosen;
            if (!$dosen || (int) $validatedData['id_dosen'] !== (int) $dosen->id_dosen) {
                return response()->json([
                    'message' => 'Akses ditolak. Anda tidak berhak menginput nilai atas nama dosen lain.'
                ], 403);
            }

            // Pastikan dosen login terdaftar sebagai dewan penguji pada ujian skripsi ini
            $isPenguji = DosenPengujiSkripsi::where('id_ujian_skripsi', $validatedData['id_ujian_skripsi'])
                ->where('id_dosen', $dosen->id_dosen)
                ->exists();

            if (!$isPenguji) {
                return response()->json([
                    'message' => 'Akses ditolak. Anda bukan dosen penguji yang ditugaskan pada ujian skripsi ini.'
                ], 403);
            }
        }

        // Cegah duplikasi nilai dari dosen yang sama pada ujian skripsi yang sama
        $exists = NilaiSkripsi::where('id_ujian_skripsi', $validatedData['id_ujian_skripsi'])
            ->where('id_dosen', $validatedData['id_dosen'])
            ->first();

        if ($exists) {
            return response()->json([
                'message' => 'Nilai dari dosen penguji ini sudah pernah disimpan pada ujian skripsi bersangkutan.',
                'errors'  => [
                    'id_dosen' => ['Nilai dari dosen penguji ini sudah ada pada ujian skripsi ini.'],
                ],
            ], 422);
        }

        $nilai = NilaiSkripsi::create($validatedData);

        return response()->json([
            'message' => 'Data nilai skripsi berhasil ditambahkan',
            'data'    => $nilai->load(['ujianSkripsi', 'dosen'])
        ], 201);
    }

    /**
     * Tampilkan detail satu penilaian skripsi.
     */
    public function show(Request $request, $id)
    {
        $user = $request->user();
        $nilai = NilaiSkripsi::with(['ujianSkripsi.sempro.skripsi.mahasiswa', 'dosen'])->find($id);

        if (!$nilai) {
            return response()->json(['message' => 'Data nilai skripsi tidak ditemukan'], 404);
        }

        if ($user->role === 'admin') {
            return response()->json($nilai);
        }

        if ($user->role === 'mahasiswa') {
            $mahasiswa = $user->mahasiswa;
            if (!$mahasiswa || (int) $nilai->ujianSkripsi?->sempro?->skripsi?->id_mahasiswa !== (int) $mahasiswa->id_mahasiswa) {
                return response()->json([
                    'message' => 'Akses ditolak. Anda tidak berhak melihat nilai ujian skripsi ini.'
                ], 403);
            }
            return response()->json($nilai);
        }

        if ($user->role === 'dosen') {
            $dosen = $user->dosen;
            $isPenguji = $dosen && (int) $nilai->id_dosen === (int) $dosen->id_dosen;
            $isPembimbing = $dosen && $nilai->ujianSkripsi?->sempro?->skripsi?->dosenPembimbing->contains('id_dosen', $dosen->id_dosen);

            if (!$isPenguji && !$isPembimbing) {
                return response()->json([
                    'message' => 'Akses ditolak. Anda bukan penguji atau pembimbing pada ujian skripsi ini.'
                ], 403);
            }
            return response()->json($nilai);
        }

        return response()->json([
            'message' => 'Akses ditolak. Anda tidak memiliki izin untuk melihat data ini.'
        ], 403);
    }

    /**
     * Perbarui nilai ujian skripsi (Hanya Dosen Penguji Bersangkutan atau Admin).
     */
    public function update(Request $request, $id)
    {
        $user = $request->user();

        if ($user->role !== 'dosen' && $user->role !== 'admin') {
            return response()->json([
                'message' => 'Akses ditolak. Mahasiswa tidak memiliki kewenangan mengubah nilai ujian skripsi.'
            ], 403);
        }

        $nilai = NilaiSkripsi::find($id);

        if (!$nilai) {
            return response()->json(['message' => 'Data nilai skripsi tidak ditemukan'], 404);
        }

        if ($user->role === 'dosen') {
            $dosen = $user->dosen;
            if (!$dosen || (int) $nilai->id_dosen !== (int) $dosen->id_dosen) {
                return response()->json([
                    'message' => 'Akses ditolak. Anda bukan dosen penguji yang memberikan nilai ini.'
                ], 403);
            }
        }

        $validatedData = $request->validate([
            'id_ujian_skripsi' => 'sometimes|required|exists:ujian_skripsi,id_ujian_skripsi',
            'id_dosen'         => 'sometimes|required|exists:dosen,id_dosen',
            'nilai'            => 'sometimes|required|numeric|min:0|max:100',
            'catatan'          => 'nullable|string',
        ]);

        // Cegah pengubahan relasi foreign key oleh non-admin
        if ($user->role !== 'admin') {
            if (isset($validatedData['id_ujian_skripsi']) && (int) $validatedData['id_ujian_skripsi'] !== (int) $nilai->id_ujian_skripsi) {
                return response()->json([
                    'message' => 'Akses ditolak. Mengubah relasi ujian skripsi pada nilai tidak diizinkan.'
                ], 403);
            }
            if (isset($validatedData['id_dosen']) && (int) $validatedData['id_dosen'] !== (int) $nilai->id_dosen) {
                return response()->json([
                    'message' => 'Akses ditolak. Mengubah dosen penilai pada nilai tidak diizinkan.'
                ], 403);
            }
        }

        $updateData = [];
        if (isset($validatedData['nilai'])) {
            $updateData['nilai'] = $validatedData['nilai'];
        }
        if (array_key_exists('catatan', $validatedData)) {
            $updateData['catatan'] = $validatedData['catatan'];
        }
        if ($user->role === 'admin') {
            if (isset($validatedData['id_ujian_skripsi'])) {
                $updateData['id_ujian_skripsi'] = $validatedData['id_ujian_skripsi'];
            }
            if (isset($validatedData['id_dosen'])) {
                $updateData['id_dosen'] = $validatedData['id_dosen'];
            }
        }

        $nilai->update($updateData);

        return response()->json([
            'message' => 'Data nilai skripsi berhasil diperbarui',
            'data'    => $nilai->load(['ujianSkripsi', 'dosen'])
        ]);
    }

    /**
     * Hapus nilai ujian skripsi (Khusus Administrator).
     */
    public function destroy(Request $request, $id)
    {
        $user = $request->user();
        if ($user->role !== 'admin') {
            return response()->json([
                'message' => 'Akses ditolak. Menghapus data nilai skripsi hanya diizinkan untuk Administrator.'
            ], 403);
        }

        $nilai = NilaiSkripsi::find($id);

        if (!$nilai) {
            return response()->json(['message' => 'Data nilai skripsi tidak ditemukan'], 404);
        }

        $nilai->delete();

        return response()->json(['message' => 'Data nilai skripsi berhasil dihapus']);
    }
}