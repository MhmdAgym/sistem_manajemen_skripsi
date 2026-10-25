<?php

namespace App\Http\Controllers;

use App\Models\Sempro;
use App\Models\Skripsi;
use Illuminate\Http\Request;

class SemproController extends Controller
{
    /**
     * Tampilkan data pendaftaran sempro terfilter sesuai peran dan kepemilikan.
     */
    public function index(Request $request)
    {
        $user = $request->user();

        if ($user->role === 'admin') {
            $query = Sempro::with(['skripsi.mahasiswa', 'pengujiSempro.dosen']);
            if ($request->filled('id_skripsi')) {
                $query->where('id_skripsi', $request->query('id_skripsi'));
            }
            return response()->json($query->get());
        }

        if ($user->role === 'mahasiswa') {
            $mahasiswa = $user->mahasiswa;
            if (!$mahasiswa) {
                return response()->json(['message' => 'Profil mahasiswa tidak ditemukan.'], 404);
            }

            $sempro = Sempro::with(['skripsi', 'pengujiSempro.dosen'])
                ->whereHas('skripsi', function ($q) use ($mahasiswa) {
                    $q->where('id_mahasiswa', $mahasiswa->id_mahasiswa);
                })
                ->get();

            return response()->json($sempro);
        }

        if ($user->role === 'dosen') {
            $dosen = $user->dosen;
            if (!$dosen) {
                return response()->json(['message' => 'Profil dosen tidak ditemukan.'], 404);
            }

            // Dosen melihat sempro di mana mereka menjadi penguji atau pembimbing skripsi terkait
            $sempro = Sempro::with(['skripsi.mahasiswa', 'pengujiSempro.dosen'])
                ->where(function ($query) use ($dosen) {
                    $query->whereHas('pengujiSempro', function ($q) use ($dosen) {
                        $q->where('id_dosen', $dosen->id_dosen);
                    })->orWhereHas('skripsi.dosenPembimbing', function ($q) use ($dosen) {
                        $q->where('id_dosen', $dosen->id_dosen);
                    });
                })
                ->get();

            return response()->json($sempro);
        }

        return response()->json([
            'message' => 'Akses ditolak. Anda tidak memiliki izin untuk mengakses fitur ini.'
        ], 403);
    }

    /**
     * Pendaftaran / Penjadwalan Seminar Proposal.
     */
    public function store(Request $request)
    {
        $user = $request->user();

        $validatedData = $request->validate([
            'id_skripsi'     => 'required|exists:skripsi,id_skripsi',
            'tanggal_sempro' => 'required|date',
            'status_sempro'  => 'sometimes|required|in:lulus,tidak lulus',
        ]);

        if ($user->role === 'mahasiswa') {
            $mahasiswa = $user->mahasiswa;
            if (!$mahasiswa) {
                return response()->json(['message' => 'Profil mahasiswa tidak ditemukan.'], 404);
            }

            $skripsi = Skripsi::where('id_skripsi', $validatedData['id_skripsi'])
                ->where('id_mahasiswa', $mahasiswa->id_mahasiswa)
                ->first();

            if (!$skripsi) {
                return response()->json([
                    'message' => 'Akses ditolak. Anda tidak berhak mendaftarkan seminar proposal untuk skripsi ini.'
                ], 403);
            }

            // Mahasiswa tidak berwenang langsung menentukan status lulus
            $validatedData['status_sempro'] = 'tidak lulus';
        } elseif ($user->role === 'admin') {
            if (!isset($validatedData['status_sempro'])) {
                $validatedData['status_sempro'] = 'tidak lulus';
            }
        } else {
            return response()->json([
                'message' => 'Akses ditolak. Peran Anda tidak memiliki kewenangan untuk mendaftarkan seminar proposal.'
            ], 403);
        }

        // Cegah pendaftaran sempro ganda untuk skripsi yang sama
        $exists = Sempro::where('id_skripsi', $validatedData['id_skripsi'])->first();
        if ($exists) {
            return response()->json([
                'message' => 'Seminar proposal untuk skripsi ini sudah terdaftar.',
                'errors'  => [
                    'id_skripsi' => ['Skripsi ini sudah memiliki jadwal pendaftaran seminar proposal.'],
                ],
            ], 422);
        }

        $sempro = Sempro::create($validatedData);

        return response()->json([
            'message' => 'Data pendaftaran sempro berhasil ditambahkan',
            'data'    => $sempro->load('skripsi')
        ], 201);
    }

    /**
     * Tampilkan detail satu pendaftaran sempro.
     */
    public function show(Request $request, $id)
    {
        $user = $request->user();
        $sempro = Sempro::with(['skripsi.mahasiswa', 'pengujiSempro.dosen'])->find($id);

        if (!$sempro) {
            return response()->json(['message' => 'Data sempro tidak ditemukan'], 404);
        }

        if ($user->role === 'admin') {
            return response()->json($sempro);
        }

        if ($user->role === 'mahasiswa') {
            $mahasiswa = $user->mahasiswa;
            if (!$mahasiswa || (int) $sempro->skripsi?->id_mahasiswa !== (int) $mahasiswa->id_mahasiswa) {
                return response()->json([
                    'message' => 'Akses ditolak. Anda tidak berhak melihat data seminar proposal ini.'
                ], 403);
            }
            return response()->json($sempro);
        }

        if ($user->role === 'dosen') {
            $dosen = $user->dosen;
            $isPenguji = $dosen && $sempro->pengujiSempro->contains('id_dosen', $dosen->id_dosen);
            $isPembimbing = $dosen && $sempro->skripsi?->dosenPembimbing->contains('id_dosen', $dosen->id_dosen);

            if (!$isPenguji && !$isPembimbing) {
                return response()->json([
                    'message' => 'Akses ditolak. Anda bukan penguji atau pembimbing pada seminar proposal ini.'
                ], 403);
            }
            return response()->json($sempro);
        }

        return response()->json([
            'message' => 'Akses ditolak. Anda tidak memiliki izin untuk melihat data ini.'
        ], 403);
    }

    /**
     * Perbarui data sempro (Khusus Administrator).
     */
    public function update(Request $request, $id)
    {
        $user = $request->user();
        if ($user->role !== 'admin') {
            return response()->json([
                'message' => 'Akses ditolak. Mengubah status seminar proposal hanya diizinkan untuk Administrator.'
            ], 403);
        }

        $sempro = Sempro::find($id);

        if (!$sempro) {
            return response()->json(['message' => 'Data sempro tidak ditemukan'], 404);
        }

        $validatedData = $request->validate([
            'id_skripsi'     => 'sometimes|required|exists:skripsi,id_skripsi',
            'tanggal_sempro' => 'sometimes|required|date',
            'status_sempro'  => 'sometimes|required|in:lulus,tidak lulus',
        ]);

        if (isset($validatedData['id_skripsi'])) {
            $exists = Sempro::where('id_skripsi', $validatedData['id_skripsi'])
                ->where('id_sempro', '!=', $id)
                ->first();

            if ($exists) {
                return response()->json([
                    'message' => 'Skripsi tersebut sudah terdaftar pada seminar proposal lain.',
                    'errors'  => [
                        'id_skripsi' => ['Skripsi tersebut sudah memiliki pendaftaran sempro lain.'],
                    ],
                ], 422);
            }
        }

        $sempro->update($validatedData);

        return response()->json([
            'message' => 'Data sempro berhasil diperbarui',
            'data'    => $sempro->load('skripsi')
        ]);
    }

    /**
     * Hapus data sempro (Khusus Administrator).
     */
    public function destroy(Request $request, $id)
    {
        $user = $request->user();
        if ($user->role !== 'admin') {
            return response()->json([
                'message' => 'Akses ditolak. Menghapus data seminar proposal hanya diizinkan untuk Administrator.'
            ], 403);
        }

        $sempro = Sempro::find($id);

        if (!$sempro) {
            return response()->json(['message' => 'Data sempro tidak ditemukan'], 404);
        }

        $sempro->delete();

        return response()->json(['message' => 'Data sempro berhasil dihapus']);
    }
}