<?php

namespace App\Http\Controllers;

use App\Models\Sempro;
use Illuminate\Http\Request;

class SemproController extends Controller
{
    public function index()
    {
        // Menampilkan data sempro beserta detail skripsinya
        $sempro = Sempro::with('skripsi')->get();
        return response()->json($sempro);
    }

    public function store(Request $request)
    {
        $validatedData = $request->validate([
            'id_skripsi'     => 'required|exists:skripsi,id_skripsi',
            'tanggal_sempro' => 'required|date',
            'status_sempro'  => 'required|in:lulus,tidak lulus',
        ]);

        $sempro = Sempro::create($validatedData);

        return response()->json([
            'message' => 'Data pendaftaran sempro berhasil ditambahkan',
            'data'    => $sempro
        ], 201);
    }

    public function show($id)
    {
        $sempro = Sempro::with('skripsi')->find($id);

        if (!$sempro) {
            return response()->json(['message' => 'Data sempro tidak ditemukan'], 404);
        }

        return response()->json($sempro);
    }

    public function update(Request $request, $id)
    {
        $sempro = Sempro::find($id);

        if (!$sempro) {
            return response()->json(['message' => 'Data sempro tidak ditemukan'], 404);
        }

        $validatedData = $request->validate([
            'id_skripsi'     => 'sometimes|required|exists:skripsi,id_skripsi',
            'tanggal_sempro' => 'sometimes|required|date',
            'status_sempro'  => 'sometimes|required|in:lulus,tidak lulus',
        ]);

        $sempro->update($validatedData);

        return response()->json([
            'message' => 'Data sempro berhasil diperbarui',
            'data'    => $sempro
        ]);
    }

    public function destroy($id)
    {
        $sempro = Sempro::find($id);

        if (!$sempro) {
            return response()->json(['message' => 'Data sempro tidak ditemukan'], 404);
        }

        $sempro->delete();

        return response()->json(['message' => 'Data sempro berhasil dihapus']);
    }
}