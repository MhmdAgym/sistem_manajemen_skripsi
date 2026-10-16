<?php

namespace App\Http\Controllers;

use App\Models\Mahasiswa;
use Illuminate\Http\Request;

class MahasiswaController extends Controller
{
    // Tampilkan semua data mahasiswa beserta data usernya
    public function index()
    {
        $mahasiswa = Mahasiswa::with('user')->get();
        return response()->json($mahasiswa);
    }

    // Simpan data mahasiswa baru
    public function store(Request $request)
    {
        $validatedData = $request->validate([
            'NIM'            => 'required|string|max:20|unique:mahasiswa,NIM',
            'user_id'        => 'required|exists:users,id',
            'nama_mahasiswa' => 'required|string|max:255',
            'prodi'          => 'required|string|max:255',
        ]);

        $mahasiswa = Mahasiswa::create($validatedData);

        return response()->json([
            'message' => 'Data mahasiswa berhasil ditambahkan',
            'data'    => $mahasiswa
        ], 201);
    }

    // Tampilkan detail satu mahasiswa
    public function show($id)
    {
        $mahasiswa = Mahasiswa::with('user')->find($id);

        if (!$mahasiswa) {
            return response()->json(['message' => 'Data mahasiswa tidak ditemukan'], 404);
        }

        return response()->json($mahasiswa);
    }

    // Update data mahasiswa
    public function update(Request $request, $id)
    {
        $mahasiswa = Mahasiswa::find($id);

        if (!$mahasiswa) {
            return response()->json(['message' => 'Data mahasiswa tidak ditemukan'], 404);
        }

        $validatedData = $request->validate([
            'NIM'            => 'sometimes|required|string|max:20|unique:mahasiswa,NIM,' . $id . ',id_mahasiswa',
            'user_id'        => 'sometimes|required|exists:users,id',
            'nama_mahasiswa' => 'sometimes|required|string|max:255',
            'prodi'          => 'sometimes|required|string|max:255',
        ]);

        $mahasiswa->update($validatedData);

        return response()->json([
            'message' => 'Data mahasiswa berhasil diperbarui',
            'data'    => $mahasiswa
        ]);
    }

    // Hapus data mahasiswa
    public function destroy($id)
    {
        $mahasiswa = Mahasiswa::find($id);

        if (!$mahasiswa) {
            return response()->json(['message' => 'Data mahasiswa tidak ditemukan'], 404);
        }

        $mahasiswa->delete();

        return response()->json(['message' => 'Data mahasiswa berhasil dihapus']);
    }
}