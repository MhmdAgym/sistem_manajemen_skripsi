<?php

namespace App\Http\Controllers;

use App\Models\Dosen;
use Illuminate\Http\Request;

class DosenController extends Controller
{
    // Tampilkan semua data dosen beserta data usernya
    public function index()
    {
        $dosen = Dosen::with('user')->get();
        return response()->json($dosen);
    }

    // Simpan data dosen baru
    public function store(Request $request)
    {
        $validatedData = $request->validate([
            'NIDN'       => 'required|string|max:20|unique:dosen,NIDN',
            'user_id'    => 'required|exists:users,id',
            'nama_dosen' => 'required|string|max:255',
        ]);

        $dosen = Dosen::create($validatedData);

        return response()->json([
            'message' => 'Data dosen berhasil ditambahkan',
            'data'    => $dosen
        ], 201);
    }

    // Tampilkan detail satu dosen
    public function show($id)
    {
        $dosen = Dosen::with('user')->find($id);

        if (!$dosen) {
            return response()->json(['message' => 'Data dosen tidak ditemukan'], 404);
        }

        return response()->json($dosen);
    }

    // Update data dosen
    public function update(Request $request, $id)
    {
        $dosen = Dosen::find($id);

        if (!$dosen) {
            return response()->json(['message' => 'Data dosen tidak ditemukan'], 404);
        }

        $validatedData = $request->validate([
            'NIDN'       => 'sometimes|required|string|max:20|unique:dosen,NIDN,' . $id . ',id_dosen',
            'user_id'    => 'sometimes|required|exists:users,id',
            'nama_dosen' => 'sometimes|required|string|max:255',
        ]);

        $dosen->update($validatedData);

        return response()->json([
            'message' => 'Data dosen berhasil diperbarui',
            'data'    => $dosen
        ]);
    }

    // Hapus data dosen
    public function destroy($id)
    {
        $dosen = Dosen::find($id);

        if (!$dosen) {
            return response()->json(['message' => 'Data dosen tidak ditemukan'], 404);
        }

        $dosen->delete();

        return response()->json(['message' => 'Data dosen berhasil dihapus']);
    }
}