<?php

namespace App\Http\Controllers;

use App\Models\DosenPembimbing;
use Illuminate\Http\Request;

class DosenPembimbingController extends Controller
{
    public function index()
    {
        // Menampilkan data Dosen Pembimbing beserta detail skripsi dan dosennya
        $pembimbing = DosenPembimbing::with(['skripsi', 'dosen'])->get();
        return response()->json($pembimbing);
    }

    public function store(Request $request)
    {
        $validatedData = $request->validate([
            'id_skripsi' => 'required|exists:skripsi,id_skripsi',
            'id_dosen'   => 'required|exists:dosen,id_dosen',
        ]);

        $pembimbing = DosenPembimbing::create($validatedData);

        return response()->json([
            'message' => 'Dosen Pembimbing berhasil ditetapkan',
            'data'    => $pembimbing
        ], 201);
    }

    public function show($id)
    {
        $pembimbing = DosenPembimbing::with(['skripsi', 'dosen'])->find($id);

        if (!$pembimbing) {
            return response()->json(['message' => 'Data Dosen Pembimbing tidak ditemukan'], 404);
        }

        return response()->json($pembimbing);
    }

    public function update(Request $request, $id)
    {
        $pembimbing = DosenPembimbing::find($id);

        if (!$pembimbing) {
            return response()->json(['message' => 'Data Dosen Pembimbing tidak ditemukan'], 404);
        }

        $validatedData = $request->validate([
            'id_skripsi' => 'sometimes|required|exists:skripsi,id_skripsi',
            'id_dosen'   => 'sometimes|required|exists:dosen,id_dosen',
        ]);

        $pembimbing->update($validatedData);

        return response()->json([
            'message' => 'Data Dosen Pembimbing berhasil diperbarui',
            'data'    => $pembimbing
        ]);
    }

    public function destroy($id)
    {
        $pembimbing = DosenPembimbing::find($id);

        if (!$pembimbing) {
            return response()->json(['message' => 'Data Dosen Pembimbing tidak ditemukan'], 404);
        }

        $pembimbing->delete();

        return response()->json(['message' => 'Data Dosen Pembimbing berhasil dihapus']);
    }
}