<?php

namespace App\Http\Controllers;

use App\Models\PengujiSempro;
use Illuminate\Http\Request;

class PengujiSemproController extends Controller
{
    public function index()
    {
        // Menampilkan data penguji sempro beserta detail dosen dan sempronya
        $penguji = PengujiSempro::with(['dosen', 'sempro'])->get();
        return response()->json($penguji);
    }

    public function store(Request $request)
    {
        $validatedData = $request->validate([
            'id_dosen'  => 'required|exists:dosen,id_dosen',
            'id_sempro' => 'required|exists:sempro,id_sempro',
            'peran'     => 'required|in:penguji 1,penguji 2',
        ]);

        $penguji = PengujiSempro::create($validatedData);

        return response()->json([
            'message' => 'Data penguji sempro berhasil ditambahkan',
            'data'    => $penguji
        ], 201);
    }

    public function show($id)
    {
        $penguji = PengujiSempro::with(['dosen', 'sempro'])->find($id);

        if (!$penguji) {
            return response()->json(['message' => 'Data penguji sempro tidak ditemukan'], 404);
        }

        return response()->json($penguji);
    }

    public function update(Request $request, $id)
    {
        $penguji = PengujiSempro::find($id);

        if (!$penguji) {
            return response()->json(['message' => 'Data penguji sempro tidak ditemukan'], 404);
        }

        $validatedData = $request->validate([
            'id_dosen'  => 'sometimes|required|exists:dosen,id_dosen',
            'id_sempro' => 'sometimes|required|exists:sempro,id_sempro',
            'peran'     => 'sometimes|required|in:penguji 1,penguji 2',
        ]);

        $penguji->update($validatedData);

        return response()->json([
            'message' => 'Data penguji sempro berhasil diperbarui',
            'data'    => $penguji
        ]);
    }

    public function destroy($id)
    {
        $penguji = PengujiSempro::find($id);

        if (!$penguji) {
            return response()->json(['message' => 'Data penguji sempro tidak ditemukan'], 404);
        }

        $penguji->delete();

        return response()->json(['message' => 'Data penguji sempro berhasil dihapus']);
    }
}