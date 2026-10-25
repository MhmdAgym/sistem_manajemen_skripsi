<?php

namespace App\Http\Controllers;

use App\Models\Sempro;
use App\Models\UjianSkripsi;
use Illuminate\Http\Request;

class UjianSkripsiController extends Controller
{
    /**
     * Tampilkan data ujian skripsi terfilter sesuai peran.
     */
    public function index(Request $request)
    {
        $user = $request->user();

        if ($user->role === 'admin') {
            $query = UjianSkripsi::with(['sempro.skripsi.mahasiswa', 'dosenPengujiSkripsi.dosen']);
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

            $ujian = UjianSkripsi::with(['sempro.skripsi', 'dosenPengujiSkripsi.dosen'])
                ->whereHas('sempro.skripsi', function ($q) use ($mahasiswa) {
                    $q->where('id_mahasiswa', $mahasiswa->id_mahasiswa);
                })
                ->get();

            return response()->json($ujian);
        }

        if ($user->role === 'dosen') {
            $dosen = $user->dosen;
            if (!$dosen) {
                return response()->json(['message' => 'Profil dosen tidak ditemukan.'], 404);
            }

            // Dosen melihat ujian skripsi di mana mereka menjadi dewan penguji atau pembimbing skripsi terkait
            $ujian = UjianSkripsi::with(['sempro.skripsi.mahasiswa', 'dosenPengujiSkripsi.dosen'])
                ->where(function ($query) use ($dosen) {
                    $query->whereHas('dosenPengujiSkripsi', function ($q) use ($dosen) {
                        $q->where('id_dosen', $dosen->id_dosen);
                    })->orWhereHas('sempro.skripsi.dosenPembimbing', function ($q) use ($dosen) {
                        $q->where('id_dosen', $dosen->id_dosen);
                    });
                })
                ->get();

            return response()->json($ujian);
        }

        return response()->json([
            'message' => 'Akses ditolak. Anda tidak memiliki izin untuk mengakses fitur ini.'
        ], 403);
    }

    /**
     * Penjadwalan Ujian Skripsi / Sidang Munaqasyah (Khusus Administrator).
     */
    public function store(Request $request)
    {
        $user = $request->user();
        if ($user->role !== 'admin') {
            return response()->json([
                'message' => 'Akses ditolak. Pendaftaran dan penjadwalan ujian skripsi hanya dapat dilakukan oleh Administrator.'
            ], 403);
        }

        $validatedData = $request->validate([
            'id_sempro'     => 'required|exists:sempro,id_sempro',
            'tanggal_ujian' => 'required|date',
            'status_ujian'  => 'sometimes|required|in:lulus,tidak lulus',
        ]);

        if (!isset($validatedData['status_ujian'])) {
            $validatedData['status_ujian'] = 'tidak lulus';
        }

        // Validasi Alur Akademik: Mahasiswa harus sudah dinyatakan lulus Seminar Proposal
        $sempro = Sempro::find($validatedData['id_sempro']);
        if (!$sempro || $sempro->status_sempro !== 'lulus') {
            return response()->json([
                'message' => 'Seminar proposal belum dinyatakan lulus. Ujian skripsi tidak dapat dijadwalkan.',
                'errors'  => [
                    'id_sempro' => ['Status seminar proposal harus lulus sebelum menjadwalkan ujian skripsi.'],
                ],
            ], 422);
        }

        // Cegah pendaftaran ujian skripsi ganda untuk sempro yang sama
        $exists = UjianSkripsi::where('id_sempro', $validatedData['id_sempro'])->first();
        if ($exists) {
            return response()->json([
                'message' => 'Ujian skripsi untuk seminar proposal ini sudah terdaftar.',
                'errors'  => [
                    'id_sempro' => ['Seminar proposal ini sudah memiliki jadwal ujian skripsi terdaftar.'],
                ],
            ], 422);
        }

        $ujian = UjianSkripsi::create($validatedData);

        return response()->json([
            'message' => 'Data ujian skripsi berhasil ditambahkan',
            'data'    => $ujian->load('sempro')
        ], 201);
    }

    /**
     * Tampilkan detail satu data ujian skripsi.
     */
    public function show(Request $request, $id)
    {
        $user = $request->user();
        $ujian = UjianSkripsi::with(['sempro.skripsi.mahasiswa', 'dosenPengujiSkripsi.dosen'])->find($id);

        if (!$ujian) {
            return response()->json(['message' => 'Data ujian skripsi tidak ditemukan'], 404);
        }

        if ($user->role === 'admin') {
            return response()->json($ujian);
        }

        if ($user->role === 'mahasiswa') {
            $mahasiswa = $user->mahasiswa;
            if (!$mahasiswa || (int) $ujian->sempro?->skripsi?->id_mahasiswa !== (int) $mahasiswa->id_mahasiswa) {
                return response()->json([
                    'message' => 'Akses ditolak. Anda tidak berhak melihat data ujian skripsi ini.'
                ], 403);
            }
            return response()->json($ujian);
        }

        if ($user->role === 'dosen') {
            $dosen = $user->dosen;
            $isPenguji = $dosen && $ujian->dosenPengujiSkripsi->contains('id_dosen', $dosen->id_dosen);
            $isPembimbing = $dosen && $ujian->sempro?->skripsi?->dosenPembimbing->contains('id_dosen', $dosen->id_dosen);

            if (!$isPenguji && !$isPembimbing) {
                return response()->json([
                    'message' => 'Akses ditolak. Anda bukan dewan penguji atau pembimbing pada ujian skripsi ini.'
                ], 403);
            }
            return response()->json($ujian);
        }

        return response()->json([
            'message' => 'Akses ditolak. Anda tidak memiliki izin untuk melihat data ini.'
        ], 403);
    }

    /**
     * Perbarui data ujian skripsi (Khusus Administrator).
     */
    public function update(Request $request, $id)
    {
        $user = $request->user();
        if ($user->role !== 'admin') {
            return response()->json([
                'message' => 'Akses ditolak. Mengubah status ujian skripsi hanya diizinkan untuk Administrator.'
            ], 403);
        }

        $ujian = UjianSkripsi::find($id);

        if (!$ujian) {
            return response()->json(['message' => 'Data ujian skripsi tidak ditemukan'], 404);
        }

        $validatedData = $request->validate([
            'id_sempro'     => 'sometimes|required|exists:sempro,id_sempro',
            'tanggal_ujian' => 'sometimes|required|date',
            'status_ujian'  => 'sometimes|required|in:lulus,tidak lulus',
        ]);

        if (isset($validatedData['id_sempro'])) {
            $sempro = Sempro::find($validatedData['id_sempro']);
            if (!$sempro || $sempro->status_sempro !== 'lulus') {
                return response()->json([
                    'message' => 'Seminar proposal belum dinyatakan lulus. Ujian skripsi tidak dapat dialihkan.',
                    'errors'  => [
                        'id_sempro' => ['Status seminar proposal harus lulus.'],
                    ],
                ], 422);
            }

            $exists = UjianSkripsi::where('id_sempro', $validatedData['id_sempro'])
                ->where('id_ujian_skripsi', '!=', $id)
                ->first();

            if ($exists) {
                return response()->json([
                    'message' => 'Seminar proposal tersebut sudah memiliki ujian skripsi lain.',
                    'errors'  => [
                        'id_sempro' => ['Seminar proposal sudah terdaftar pada ujian skripsi lain.'],
                    ],
                ], 422);
            }
        }

        $ujian->update($validatedData);

        return response()->json([
            'message' => 'Data ujian skripsi berhasil diperbarui',
            'data'    => $ujian->load('sempro')
        ]);
    }

    /**
     * Hapus data ujian skripsi (Khusus Administrator).
     */
    public function destroy(Request $request, $id)
    {
        $user = $request->user();
        if ($user->role !== 'admin') {
            return response()->json([
                'message' => 'Akses ditolak. Menghapus data ujian skripsi hanya diizinkan untuk Administrator.'
            ], 403);
        }

        $ujian = UjianSkripsi::find($id);

        if (!$ujian) {
            return response()->json(['message' => 'Data ujian skripsi tidak ditemukan'], 404);
        }

        $ujian->delete();

        return response()->json(['message' => 'Data ujian skripsi berhasil dihapus']);
    }
}
