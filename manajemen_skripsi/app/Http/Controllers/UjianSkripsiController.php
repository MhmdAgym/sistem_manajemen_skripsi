<?php

namespace App\Http\Controllers;

use App\Models\UjianSkripsi;
use Illuminate\Http\Request;

class UjianSkripsiController extends Controller
{
    public function index()
    {
        // Menampilkan data ujian skripsi beserta detail sempronya
        $ujian = UjianSkripsi::with('sempro')->get();
        return response()->json($ujian);
    }

    public function store(Request $request)
    {
        $validatedData = $request->validate([
            'id_sempro'     => 'required|exists:sempro,id_sempro',
            'tanggal_ujian' => 'required|date',
            'status_ujian'  => 'required|in:lulus,tidak lulus',
        ]);

        $ujian = UjianSkripsi::create($validatedData);

        return response()->json([
            'message' => 'Data ujian skripsi berhasil ditambahkan',
            'data'    => $ujian
        ], 201);
    }

    public function show($id)
    {
        $ujian = UjianSkripsi::with('sempro')->find($id);

        if (!$ujian) {
            return response()->json(['message' => 'Data ujian skripsi tidak ditemukan'], 404);
        }

        return response()->json($ujian);
    }

    public function update(Request $request, $id)
    {
        $ujian = UjianSkripsi::find($id);

        if (!$ujian) {
            return response()->json(['message' => 'Data ujian skripsi tidak ditemukan'], 404);
        }

        $validatedData = $request->validate([
            'id_sempro'     => 'sometimes|required|exists:sempro,id_sempro',
            'tanggal_ujian' => 'sometimes|required|date',
            'status_ujian'  => 'sometimes|required|in:lulus,tidak lulus',
        ]);

        $ujian->update($validatedData);

        return response()->json([
            'message' => 'Data ujian skripsi berhasil diperbarui',
            'data'    => $ujian
        ]);
    }

    public function destroy($id)
    {
        $ujian = UjianSkripsi::find($id);

        if (!$ujian) {
            return response()->json(['message' => 'Data ujian skripsi tidak ditemukan'], 404);
        }

        $ujian->delete();

        return response()->json(['message' => 'Data ujian skripsi berhasil dihapus']);
    }
}
