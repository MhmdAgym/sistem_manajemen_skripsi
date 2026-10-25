<?php

namespace App\Http\Controllers;

use App\Models\PengujiSempro;
use Illuminate\Http\Request;

class PengujiSemproController extends Controller
{
    /**
     * Tampilkan data penguji sempro terfilter sesuai peran.
     */
    public function index(Request $request)
    {
        $user = $request->user();

        if ($user->role === 'admin') {
            $query = PengujiSempro::with(['dosen', 'sempro.skripsi.mahasiswa']);
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

            $penguji = PengujiSempro::with(['dosen', 'sempro.skripsi'])
                ->whereHas('sempro.skripsi', function ($q) use ($mahasiswa) {
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

            $penguji = PengujiSempro::with(['dosen', 'sempro.skripsi'])
                ->where(function ($query) use ($dosen) {
                    $query->where('id_dosen', $dosen->id_dosen)
                          ->orWhereHas('sempro.skripsi.dosenPembimbing', function ($q) use ($dosen) {
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
     * Penetapan Dosen Penguji Seminar Proposal (Khusus Administrator).
     */
    public function store(Request $request)
    {
        $user = $request->user();
        if ($user->role !== 'admin') {
            return response()->json([
                'message' => 'Akses ditolak. Penetapan dosen penguji hanya dapat dilakukan oleh Administrator.'
            ], 403);
        }

        $validatedData = $request->validate([
            'id_dosen'  => 'required|exists:dosen,id_dosen',
            'id_sempro' => 'required|exists:sempro,id_sempro',
            'peran'     => 'required|in:penguji 1,penguji 2',
        ]);

        // Cek apakah dosen sudah terdaftar pada sempro ini
        $existsDosen = PengujiSempro::where('id_sempro', $validatedData['id_sempro'])
            ->where('id_dosen', $validatedData['id_dosen'])
            ->first();

        if ($existsDosen) {
            return response()->json([
                'message' => 'Dosen tersebut sudah terdaftar sebagai penguji pada seminar proposal ini.',
                'errors'  => [
                    'id_dosen' => ['Dosen tersebut sudah terdaftar sebagai penguji pada sempro ini.'],
                ],
            ], 422);
        }

        // Cek apakah posisi peran penguji sudah terisi pada sempro ini
        $existsPeran = PengujiSempro::where('id_sempro', $validatedData['id_sempro'])
            ->where('peran', $validatedData['peran'])
            ->first();

        if ($existsPeran) {
            return response()->json([
                'message' => "Posisi {$validatedData['peran']} pada seminar proposal ini sudah terisi.",
                'errors'  => [
                    'peran' => ["Posisi {$validatedData['peran']} sudah terisi."],
                ],
            ], 422);
        }

        $penguji = PengujiSempro::create($validatedData);

        return response()->json([
            'message' => 'Data penguji sempro berhasil ditambahkan',
            'data'    => $penguji->load(['dosen', 'sempro'])
        ], 201);
    }

    /**
     * Tampilkan detail satu data penguji sempro.
     */
    public function show(Request $request, $id)
    {
        $user = $request->user();
        $penguji = PengujiSempro::with(['dosen', 'sempro.skripsi.mahasiswa'])->find($id);

        if (!$penguji) {
            return response()->json(['message' => 'Data penguji sempro tidak ditemukan'], 404);
        }

        if ($user->role === 'admin') {
            return response()->json($penguji);
        }

        if ($user->role === 'mahasiswa') {
            $mahasiswa = $user->mahasiswa;
            if (!$mahasiswa || (int) $penguji->sempro?->skripsi?->id_mahasiswa !== (int) $mahasiswa->id_mahasiswa) {
                return response()->json([
                    'message' => 'Akses ditolak. Anda tidak berhak melihat data penguji seminar proposal ini.'
                ], 403);
            }
            return response()->json($penguji);
        }

        if ($user->role === 'dosen') {
            $dosen = $user->dosen;
            $isPenguji = $dosen && (int) $penguji->id_dosen === (int) $dosen->id_dosen;
            $isPembimbing = $dosen && $penguji->sempro?->skripsi?->dosenPembimbing->contains('id_dosen', $dosen->id_dosen);

            if (!$isPenguji && !$isPembimbing) {
                return response()->json([
                    'message' => 'Akses ditolak. Anda bukan penguji atau pembimbing pada seminar proposal ini.'
                ], 403);
            }
            return response()->json($penguji);
        }

        return response()->json([
            'message' => 'Akses ditolak. Anda tidak memiliki izin untuk melihat data ini.'
        ], 403);
    }

    /**
     * Perbarui data penguji sempro (Khusus Administrator).
     */
    public function update(Request $request, $id)
    {
        $user = $request->user();
        if ($user->role !== 'admin') {
            return response()->json([
                'message' => 'Akses ditolak. Mengubah data dosen penguji hanya diizinkan untuk Administrator.'
            ], 403);
        }

        $penguji = PengujiSempro::find($id);

        if (!$penguji) {
            return response()->json(['message' => 'Data penguji sempro tidak ditemukan'], 404);
        }

        $validatedData = $request->validate([
            'id_dosen'  => 'sometimes|required|exists:dosen,id_dosen',
            'id_sempro' => 'sometimes|required|exists:sempro,id_sempro',
            'peran'     => 'sometimes|required|in:penguji 1,penguji 2',
        ]);

        $targetDosen  = $validatedData['id_dosen'] ?? $penguji->id_dosen;
        $targetSempro = $validatedData['id_sempro'] ?? $penguji->id_sempro;
        $targetPeran  = $validatedData['peran'] ?? $penguji->peran;

        // Cek duplikasi dosen
        $existsDosen = PengujiSempro::where('id_sempro', $targetSempro)
            ->where('id_dosen', $targetDosen)
            ->where('id_penguji_sempro', '!=', $id)
            ->first();

        if ($existsDosen) {
            return response()->json([
                'message' => 'Dosen tersebut sudah terdaftar sebagai penguji pada seminar proposal ini.',
                'errors'  => [
                    'id_dosen' => ['Dosen tersebut sudah terdaftar sebagai penguji pada sempro ini.'],
                ],
            ], 422);
        }

        // Cek duplikasi posisi peran
        $existsPeran = PengujiSempro::where('id_sempro', $targetSempro)
            ->where('peran', $targetPeran)
            ->where('id_penguji_sempro', '!=', $id)
            ->first();

        if ($existsPeran) {
            return response()->json([
                'message' => "Posisi {$targetPeran} pada seminar proposal ini sudah terisi.",
                'errors'  => [
                    'peran' => ["Posisi {$targetPeran} sudah terisi."],
                ],
            ], 422);
        }

        $penguji->update($validatedData);

        return response()->json([
            'message' => 'Data penguji sempro berhasil diperbarui',
            'data'    => $penguji->load(['dosen', 'sempro'])
        ]);
    }

    /**
     * Hapus penugasan penguji sempro (Khusus Administrator).
     */
    public function destroy(Request $request, $id)
    {
        $user = $request->user();
        if ($user->role !== 'admin') {
            return response()->json([
                'message' => 'Akses ditolak. Menghapus data dosen penguji hanya diizinkan untuk Administrator.'
            ], 403);
        }

        $penguji = PengujiSempro::find($id);

        if (!$penguji) {
            return response()->json(['message' => 'Data penguji sempro tidak ditemukan'], 404);
        }

        $penguji->delete();

        return response()->json(['message' => 'Data penguji sempro berhasil dihapus']);
    }
}