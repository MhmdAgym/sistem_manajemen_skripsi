<?php

namespace App\Http\Controllers;

use App\Models\DosenPengujiSkripsi;
use Illuminate\Http\Request;

class DosenPengujiSkripsiController extends Controller
{
    public function index()
    {
        // Menampilkan data penguji skripsi beserta detail dosen dan ujian skripsinya
        $penguji = DosenPengujiSkripsi::with(['dosen', 'ujianSkripsi'])->get();
        return response()->json($penguji);
    }

    public function store(Request $request)
    {
        $validatedData = $request->validate([
            'id_dosen'         => 'required|exists:dosen,id_dosen',
            'id_ujian_skripsi' => 'required|exists:ujian_skripsi,id_ujian_skripsi',
            'peran'            => 'required|in:ketua,anggota',
        ]);

        // Cek Unique Constraint: Pastikan dosen belum terdaftar di ujian ini
        $exists = DosenPengujiSkripsi::where('id_dosen', $request->id_dosen)
                                     ->where('id_ujian_skripsi', $request->id_ujian_skripsi)
                                     ->first();
        if ($exists) {
            return response()->json(['message' => 'Dosen ini sudah terdaftar sebagai penguji pada ujian skripsi ini'], 400);
        }

        $penguji = DosenPengujiSkripsi::create($validatedData);

        return response()->json([
            'message' => 'Data dosen penguji skripsi berhasil ditambahkan',
            'data'    => $penguji
        ], 201);
    }

    public function show($id)
    {
        $penguji = DosenPengujiSkripsi::with(['dosen', 'ujianSkripsi'])->find($id);

        if (!$penguji) {
            return response()->json(['message' => 'Data penguji skripsi tidak ditemukan'], 404);
        }

        return response()->json($penguji);
    }

    public function update(Request $request, $id)
    {
        $penguji = DosenPengujiSkripsi::find($id);

        if (!$penguji) {
            return response()->json(['message' => 'Data penguji skripsi tidak ditemukan'], 404);
        }

        $validatedData = $request->validate([
            'id_dosen'         => 'sometimes|required|exists:dosen,id_dosen',
            'id_ujian_skripsi' => 'sometimes|required|exists:ujian_skripsi,id_ujian_skripsi',
            'peran'            => 'sometimes|required|in:ketua,anggota',
        ]);

        // Cek Unique Constraint jika id_dosen atau id_ujian_skripsi diubah
        if ($request->has('id_dosen') || $request->has('id_ujian_skripsi')) {
            $checkDosenId = $request->id_dosen ?? $penguji->id_dosen;
            $checkUjianId = $request->id_ujian_skripsi ?? $penguji->id_ujian_skripsi;

            $exists = DosenPengujiSkripsi::where('id_dosen', $checkDosenId)
                                         ->where('id_ujian_skripsi', $checkUjianId)
                                         ->where('id_penguji_skripsi', '!=', $id) // Abaikan data diri sendiri
                                         ->first();
            if ($exists) {
                return response()->json(['message' => 'Dosen ini sudah terdaftar sebagai penguji pada ujian skripsi ini'], 400);
            }
        }

        $penguji->update($validatedData);

        return response()->json([
            'message' => 'Data penguji skripsi berhasil diperbarui',
            'data'    => $penguji
        ]);
    }

    public function destroy($id)
    {
        $penguji = DosenPengujiSkripsi::find($id);

        if (!$penguji) {
            return response()->json(['message' => 'Data penguji skripsi tidak ditemukan'], 404);
        }

        $penguji->delete();

        return response()->json(['message' => 'Data penguji skripsi berhasil dihapus']);
    }
}