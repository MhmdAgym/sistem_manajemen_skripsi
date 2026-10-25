<?php

namespace App\Http\Controllers;

use App\Models\DosenPengujiSkripsi;
use Illuminate\Http\Request;

class DosenPengujiSkripsiController extends Controller
{
    /**
     * Tampilkan data dosen penguji skripsi terfilter sesuai peran.
     */
    public function index(Request $request)
    {
        $user = $request->user();

        if ($user->role === 'admin') {
            $query = DosenPengujiSkripsi::with(['dosen', 'ujianSkripsi.sempro.skripsi.mahasiswa']);
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

            $penguji = DosenPengujiSkripsi::with(['dosen', 'ujianSkripsi.sempro.skripsi'])
                ->whereHas('ujianSkripsi.sempro.skripsi', function ($q) use ($mahasiswa) {
                    $q->where('id_mahasiswa', $mahasiswa->id_mahasiswa);
                })
                ->get();

            return response()->json($penguji);
        }

        if ($user->role === 'dosen') {
            $dosen = $user->dosen;
            if (!$dosen) {
                return response()->json(['message' => 'Profil dosen tidak ditemukan.'], 404);
            }

            $penguji = DosenPengujiSkripsi::with(['dosen', 'ujianSkripsi.sempro.skripsi'])
                ->where(function ($query) use ($dosen) {
                    $query->where('id_dosen', $dosen->id_dosen)
                          ->orWhereHas('ujianSkripsi.sempro.skripsi.dosenPembimbing', function ($q) use ($dosen) {
                              $q->where('id_dosen', $dosen->id_dosen);
                          });
                })
                ->get();

            return response()->json($penguji);
        }

        return response()->json([
            'message' => 'Akses ditolak. Anda tidak memiliki izin untuk mengakses fitur ini.'
        ], 403);
    }

    /**
     * Penetapan Dewan Penguji Ujian Skripsi (Khusus Administrator).
     */
    public function store(Request $request)
    {
        $user = $request->user();
        if ($user->role !== 'admin') {
            return response()->json([
                'message' => 'Akses ditolak. Penetapan dosen penguji sidang skripsi hanya dapat dilakukan oleh Administrator.'
            ], 403);
        }

        $validatedData = $request->validate([
            'id_dosen'         => 'required|exists:dosen,id_dosen',
            'id_ujian_skripsi' => 'required|exists:ujian_skripsi,id_ujian_skripsi',
            'peran'            => 'required|in:ketua,anggota',
        ]);

        // Cek Unique Constraint: Pastikan dosen belum terdaftar di ujian ini
        $exists = DosenPengujiSkripsi::where('id_dosen', $validatedData['id_dosen'])
            ->where('id_ujian_skripsi', $validatedData['id_ujian_skripsi'])
            ->first();

        if ($exists) {
            return response()->json([
                'message' => 'Dosen ini sudah terdaftar sebagai penguji pada ujian skripsi ini',
                'errors'  => [
                    'id_dosen' => ['Dosen ini sudah terdaftar sebagai penguji pada ujian skripsi ini'],
                ],
            ], 422);
        }

        $penguji = DosenPengujiSkripsi::create($validatedData);

        return response()->json([
            'message' => 'Data dosen penguji skripsi berhasil ditambahkan',
            'data'    => $penguji->load(['dosen', 'ujianSkripsi'])
        ], 201);
    }

    /**
     * Tampilkan detail satu penugasan penguji skripsi.
     */
    public function show(Request $request, $id)
    {
        $user = $request->user();
        $penguji = DosenPengujiSkripsi::with(['dosen', 'ujianSkripsi.sempro.skripsi.mahasiswa'])->find($id);

        if (!$penguji) {
            return response()->json(['message' => 'Data penguji skripsi tidak ditemukan'], 404);
        }

        if ($user->role === 'admin') {
            return response()->json($penguji);
        }

        if ($user->role === 'mahasiswa') {
            $mahasiswa = $user->mahasiswa;
            if (!$mahasiswa || (int) $penguji->ujianSkripsi?->sempro?->skripsi?->id_mahasiswa !== (int) $mahasiswa->id_mahasiswa) {
                return response()->json([
                    'message' => 'Akses ditolak. Anda tidak berhak melihat data penguji ujian skripsi ini.'
                ], 403);
            }
            return response()->json($penguji);
        }

        if ($user->role === 'dosen') {
            $dosen = $user->dosen;
            $isPenguji = $dosen && (int) $penguji->id_dosen === (int) $dosen->id_dosen;
            $isPembimbing = $dosen && $penguji->ujianSkripsi?->sempro?->skripsi?->dosenPembimbing->contains('id_dosen', $dosen->id_dosen);

            if (!$isPenguji && !$isPembimbing) {
                return response()->json([
                    'message' => 'Akses ditolak. Anda bukan penguji atau pembimbing pada ujian skripsi ini.'
                ], 403);
            }
            return response()->json($penguji);
        }

        return response()->json([
            'message' => 'Akses ditolak. Anda tidak memiliki izin untuk melihat data ini.'
        ], 403);
    }

    /**
     * Perbarui data penugasan penguji skripsi (Khusus Administrator).
     */
    public function update(Request $request, $id)
    {
        $user = $request->user();
        if ($user->role !== 'admin') {
            return response()->json([
                'message' => 'Akses ditolak. Mengubah data penguji sidang skripsi hanya diizinkan untuk Administrator.'
            ], 403);
        }

        $penguji = DosenPengujiSkripsi::find($id);

        if (!$penguji) {
            return response()->json(['message' => 'Data penguji skripsi tidak ditemukan'], 404);
        }

        $validatedData = $request->validate([
            'id_dosen'         => 'sometimes|required|exists:dosen,id_dosen',
            'id_ujian_skripsi' => 'sometimes|required|exists:ujian_skripsi,id_ujian_skripsi',
            'peran'            => 'sometimes|required|in:ketua,anggota',
        ]);

        if ($request->has('id_dosen') || $request->has('id_ujian_skripsi')) {
            $checkDosenId = $validatedData['id_dosen'] ?? $penguji->id_dosen;
            $checkUjianId = $validatedData['id_ujian_skripsi'] ?? $penguji->id_ujian_skripsi;

            $exists = DosenPengujiSkripsi::where('id_dosen', $checkDosenId)
                ->where('id_ujian_skripsi', $checkUjianId)
                ->where('id_penguji_skripsi', '!=', $id)
                ->first();

            if ($exists) {
                return response()->json([
                    'message' => 'Dosen ini sudah terdaftar sebagai penguji pada ujian skripsi ini',
                    'errors'  => [
                        'id_dosen' => ['Dosen ini sudah terdaftar sebagai penguji pada ujian skripsi ini'],
                    ],
                ], 422);
            }
        }

        $penguji->update($validatedData);

        return response()->json([
            'message' => 'Data penguji skripsi berhasil diperbarui',
            'data'    => $penguji->load(['dosen', 'ujianSkripsi'])
        ]);
    }

    /**
     * Hapus penugasan penguji skripsi (Khusus Administrator).
     */
    public function destroy(Request $request, $id)
    {
        $user = $request->user();
        if ($user->role !== 'admin') {
            return response()->json([
                'message' => 'Akses ditolak. Menghapus penugasan penguji skripsi hanya diizinkan untuk Administrator.'
            ], 403);
        }

        $penguji = DosenPengujiSkripsi::find($id);

        if (!$penguji) {
            return response()->json(['message' => 'Data penguji skripsi tidak ditemukan'], 404);
        }

        $penguji->delete();

        return response()->json(['message' => 'Data penguji skripsi berhasil dihapus']);
    }
}