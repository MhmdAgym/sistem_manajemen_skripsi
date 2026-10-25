<?php

namespace App\Http\Controllers;

use App\Models\DosenPembimbing;
use App\Models\Skripsi;
use Illuminate\Http\Request;

class DosenPembimbingController extends Controller
{
    /**
     * Tampilkan daftar penugasan Dosen Pembimbing terfilter sesuai peran.
     */
    public function index(Request $request)
    {
        $user = $request->user();

        if ($user->role === 'admin') {
            $query = DosenPembimbing::with(['skripsi.mahasiswa', 'dosen']);
            if ($request->has('id_skripsi')) {
                $query->where('id_skripsi', $request->query('id_skripsi'));
            }
            if ($request->has('id_dosen')) {
                $query->where('id_dosen', $request->query('id_dosen'));
            }
            return response()->json($query->get());
        }

        if ($user->role === 'dosen') {
            $dosen = $user->dosen;
            if (!$dosen) {
                return response()->json(['message' => 'Profil dosen tidak ditemukan.'], 404);
            }

            $pembimbing = DosenPembimbing::with(['skripsi.mahasiswa', 'dosen'])
                ->where('id_dosen', $dosen->id_dosen)
                ->get();

            return response()->json($pembimbing);
        }

        if ($user->role === 'mahasiswa') {
            $mahasiswa = $user->mahasiswa;
            if (!$mahasiswa) {
                return response()->json(['message' => 'Profil mahasiswa tidak ditemukan.'], 404);
            }

            $pembimbing = DosenPembimbing::with(['skripsi', 'dosen'])
                ->whereHas('skripsi', function ($q) use ($mahasiswa) {
                    $q->where('id_mahasiswa', $mahasiswa->id_mahasiswa);
                })
                ->get();

            return response()->json($pembimbing);
        }

        return response()->json([
            'message' => 'Akses ditolak. Anda tidak memiliki izin untuk mengakses fitur ini.'
        ], 403);
    }

    /**
     * Penetapan Dosen Pembimbing (Khusus Administrator).
     */
    public function store(Request $request)
    {
        $user = $request->user();
        if ($user->role !== 'admin') {
            return response()->json([
                'message' => 'Akses ditolak. Fitur ini hanya dapat diakses oleh Administrator.'
            ], 403);
        }

        $validatedData = $request->validate([
            'id_skripsi' => 'required|exists:skripsi,id_skripsi',
            'id_dosen'   => 'required|exists:dosen,id_dosen',
        ]);

        $skripsi = Skripsi::find($validatedData['id_skripsi']);
        if (!$skripsi) {
            return response()->json([
                'message' => 'Data skripsi tidak ditemukan.',
            ], 404);
        }

        // Cegah penugasan duplikat untuk dosen yang sama pada skripsi yang sama
        $exists = DosenPembimbing::where('id_skripsi', $validatedData['id_skripsi'])
            ->where('id_dosen', $validatedData['id_dosen'])
            ->first();

        if ($exists) {
            return response()->json([
                'message' => 'Dosen tersebut sudah ditugaskan sebagai pembimbing pada skripsi ini.',
                'errors'  => [
                    'id_dosen' => ['Dosen tersebut sudah ditugaskan sebagai pembimbing pada skripsi ini.'],
                ],
            ], 422);
        }

        $pembimbing = DosenPembimbing::create($validatedData);

        return response()->json([
            'message' => 'Dosen Pembimbing berhasil ditetapkan',
            'data'    => $pembimbing->load(['skripsi', 'dosen'])
        ], 201);
    }

    /**
     * Lihat detail satu penugasan Dosen Pembimbing.
     */
    public function show(Request $request, $id)
    {
        $user = $request->user();
        $pembimbing = DosenPembimbing::with(['skripsi.mahasiswa', 'dosen'])->find($id);

        if (!$pembimbing) {
            return response()->json(['message' => 'Data Dosen Pembimbing tidak ditemukan'], 404);
        }

        if ($user->role === 'admin') {
            return response()->json($pembimbing);
        }

        if ($user->role === 'dosen') {
            $dosen = $user->dosen;
            if (!$dosen || (int) $pembimbing->id_dosen !== (int) $dosen->id_dosen) {
                return response()->json([
                    'message' => 'Akses ditolak. Anda tidak memiliki izin untuk melihat data penugasan pembimbing ini.'
                ], 403);
            }
            return response()->json($pembimbing);
        }

        if ($user->role === 'mahasiswa') {
            $mahasiswa = $user->mahasiswa;
            if (!$mahasiswa || (int) $pembimbing->skripsi?->id_mahasiswa !== (int) $mahasiswa->id_mahasiswa) {
                return response()->json([
                    'message' => 'Akses ditolak. Anda tidak memiliki izin untuk melihat data penugasan pembimbing ini.'
                ], 403);
            }
            return response()->json($pembimbing);
        }

        return response()->json([
            'message' => 'Akses ditolak. Anda tidak memiliki izin untuk melihat data penugasan pembimbing ini.'
        ], 403);
    }

    /**
     * Perbarui data penugasan Dosen Pembimbing (Khusus Administrator).
     */
    public function update(Request $request, $id)
    {
        $user = $request->user();
        if ($user->role !== 'admin') {
            return response()->json([
                'message' => 'Akses ditolak. Fitur ini hanya dapat diakses oleh Administrator.'
            ], 403);
        }

        $pembimbing = DosenPembimbing::find($id);

        if (!$pembimbing) {
            return response()->json(['message' => 'Data Dosen Pembimbing tidak ditemukan'], 404);
        }

        $validatedData = $request->validate([
            'id_skripsi' => 'sometimes|required|exists:skripsi,id_skripsi',
            'id_dosen'   => 'sometimes|required|exists:dosen,id_dosen',
        ]);

        $targetSkripsi = $validatedData['id_skripsi'] ?? $pembimbing->id_skripsi;
        $targetDosen   = $validatedData['id_dosen'] ?? $pembimbing->id_dosen;

        // Cegah konflik duplikasi dengan penugasan lain
        $exists = DosenPembimbing::where('id_skripsi', $targetSkripsi)
            ->where('id_dosen', $targetDosen)
            ->where('id_pembimbing', '!=', $id)
            ->first();

        if ($exists) {
            return response()->json([
                'message' => 'Dosen tersebut sudah ditugaskan sebagai pembimbing pada skripsi ini.',
                'errors'  => [
                    'id_dosen' => ['Dosen tersebut sudah ditugaskan sebagai pembimbing pada skripsi ini.'],
                ],
            ], 422);
        }

        $pembimbing->update($validatedData);

        return response()->json([
            'message' => 'Data Dosen Pembimbing berhasil diperbarui',
            'data'    => $pembimbing->load(['skripsi', 'dosen'])
        ]);
    }

    /**
     * Hapus data penugasan Dosen Pembimbing (Khusus Administrator).
     */
    public function destroy(Request $request, $id)
    {
        $user = $request->user();
        if ($user->role !== 'admin') {
            return response()->json([
                'message' => 'Akses ditolak. Fitur ini hanya dapat diakses oleh Administrator.'
            ], 403);
        }

        $pembimbing = DosenPembimbing::find($id);

        if (!$pembimbing) {
            return response()->json(['message' => 'Data Dosen Pembimbing tidak ditemukan'], 404);
        }

        $pembimbing->delete();

        return response()->json(['message' => 'Data Dosen Pembimbing berhasil dihapus']);
    }
}