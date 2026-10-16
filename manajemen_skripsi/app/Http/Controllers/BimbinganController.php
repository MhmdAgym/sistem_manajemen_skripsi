<?php

namespace App\Http\Controllers;

use App\Models\Bimbingan;
use Illuminate\Http\Request;

class BimbinganController extends Controller
{
    public function index()
    {
        // Menampilkan data bimbingan beserta detail skripsinya
        $bimbingan = Bimbingan::with('skripsi')->get();
        return response()->json($bimbingan);
    }

    public function store(Request $request)
    {
        $validatedData = $request->validate([
            'id_skripsi' => 'required|exists:skripsi,id_skripsi',
            'tanggal'    => 'required|date',
            'catatan'    => 'required|string',
        ]);

        $bimbingan = Bimbingan::create($validatedData);

        return response()->json([
            'message' => 'Data bimbingan berhasil ditambahkan',
            'data'    => $bimbingan
        ], 201);
    }

    public function show($id)
    {
        $bimbingan = Bimbingan::with('skripsi')->find($id);

        if (!$bimbingan) {
            return response()->json(['message' => 'Data bimbingan tidak ditemukan'], 404);
        }

        return response()->json($bimbingan);
    }

    public function update(Request $request, $id)
    {
        $bimbingan = Bimbingan::find($id);

        if (!$bimbingan) {
            return response()->json(['message' => 'Data bimbingan tidak ditemukan'], 404);
        }

        $validatedData = $request->validate([
            'id_skripsi' => 'sometimes|required|exists:skripsi,id_skripsi',
            'tanggal'    => 'sometimes|required|date',
            'catatan'    => 'sometimes|required|string',
        ]);

        $bimbingan->update($validatedData);

        return response()->json([
            'message' => 'Data bimbingan berhasil diperbarui',
            'data'    => $bimbingan
        ]);
    }

    public function destroy($id)
    {
        $bimbingan = Bimbingan::find($id);

        if (!$bimbingan) {
            return response()->json(['message' => 'Data bimbingan tidak ditemukan'], 404);
        }

        $bimbingan->delete();

        return response()->json(['message' => 'Data bimbingan berhasil dihapus']);
    }
}