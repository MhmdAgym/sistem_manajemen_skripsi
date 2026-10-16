<?php

namespace App\Http\Controllers;

use App\Models\NilaiSkripsi;
use Illuminate\Http\Request;

class NilaiSkripsiController extends Controller
{
    public function index()
    {
        // Menampilkan data nilai skripsi beserta detail ujian skripsi dan dosen
        $nilai = NilaiSkripsi::with(['ujianSkripsi', 'dosen'])->get();
        return response()->json($nilai);
    }

    public function store(Request $request)
    {
        $validatedData = $request->validate([
            'id_ujian_skripsi' => 'required|exists:ujian_skripsi,id_ujian_skripsi',
            'id_dosen'         => 'required|exists:dosen,id_dosen',
            'nilai'            => 'required|numeric',
            'catatan'          => 'nullable|string',
        ]);

        $nilai = NilaiSkripsi::create($validatedData);

        return response()->json([
            'message' => 'Data nilai skripsi berhasil ditambahkan',
            'data'    => $nilai
        ], 201);
    }

    public function show($id)
    {
        $nilai = NilaiSkripsi::with(['ujianSkripsi', 'dosen'])->find($id);

        if (!$nilai) {
            return response()->json(['message' => 'Data nilai skripsi tidak ditemukan'], 404);
        }

        return response()->json($nilai);
    }

    public function update(Request $request, $id)
    {
        $nilai = NilaiSkripsi::find($id);

        if (!$nilai) {
            return response()->json(['message' => 'Data nilai skripsi tidak ditemukan'], 404);
        }

        $validatedData = $request->validate([
            'id_ujian_skripsi' => 'sometimes|required|exists:ujian_skripsi,id_ujian_skripsi',
            'id_dosen'         => 'sometimes|required|exists:dosen,id_dosen',
            'nilai'            => 'sometimes|required|numeric',
            'catatan'          => 'nullable|string',
        ]);

        $nilai->update($validatedData);

        return response()->json([
            'message' => 'Data nilai skripsi berhasil diperbarui',
            'data'    => $nilai
        ]);
    }

    public function destroy($id)
    {
        $nilai = NilaiSkripsi::find($id);

        if (!$nilai) {
            return response()->json(['message' => 'Data nilai skripsi tidak ditemukan'], 404);
        }

        $nilai->delete();

        return response()->json(['message' => 'Data nilai skripsi berhasil dihapus']);
    }
}