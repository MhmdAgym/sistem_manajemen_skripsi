<?php

namespace App\Http\Controllers;

use App\Models\NilaiSempro;
use Illuminate\Http\Request;

class NilaiSemproController extends Controller
{
    public function index()
    {
        // Menampilkan data nilai sempro beserta detail relasinya
        $nilai = NilaiSempro::with(['sempro', 'pengujiSempro'])->get();
        return response()->json($nilai);
    }

    public function store(Request $request)
    {
        $validatedData = $request->validate([
            'id_sempro'         => 'required|exists:sempro,id_sempro',
            'tanggal_ujian'     => 'required|date',
            'status_ujian'      => 'required|in:lulus,tidak lulus',
            'id_penguji_sempro' => 'required|exists:penguji_sempro,id_penguji_sempro',
        ]);

        $nilai = NilaiSempro::create($validatedData);

        return response()->json([
            'message' => 'Data nilai sempro berhasil ditambahkan',
            'data'    => $nilai
        ], 201);
    }

    public function show($id)
    {
        $nilai = NilaiSempro::with(['sempro', 'pengujiSempro'])->find($id);

        if (!$nilai) {
            return response()->json(['message' => 'Data nilai sempro tidak ditemukan'], 404);
        }

        return response()->json($nilai);
    }

    public function update(Request $request, $id)
    {
        $nilai = NilaiSempro::find($id);

        if (!$nilai) {
            return response()->json(['message' => 'Data nilai sempro tidak ditemukan'], 404);
        }

        $validatedData = $request->validate([
            'id_sempro'         => 'sometimes|required|exists:sempro,id_sempro',
            'tanggal_ujian'     => 'sometimes|required|date',
            'status_ujian'      => 'sometimes|required|in:lulus,tidak lulus',
            'id_penguji_sempro' => 'sometimes|required|exists:penguji_sempro,id_penguji_sempro',
        ]);

        $nilai->update($validatedData);

        return response()->json([
            'message' => 'Data nilai sempro berhasil diperbarui',
            'data'    => $nilai
        ]);
    }

    public function destroy($id)
    {
        $nilai = NilaiSempro::find($id);

        if (!$nilai) {
            return response()->json(['message' => 'Data nilai sempro tidak ditemukan'], 404);
        }

        $nilai->delete();

        return response()->json(['message' => 'Data nilai sempro berhasil dihapus']);
    }
}