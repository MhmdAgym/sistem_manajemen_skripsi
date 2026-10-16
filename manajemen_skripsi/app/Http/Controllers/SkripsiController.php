<?php

namespace App\Http\Controllers;

use App\Models\Skripsi;
use Illuminate\Http\Request;

class SkripsiController extends Controller
{
    public function index()
    {
        // Menampilkan data skripsi beserta data mahasiswanya
        $skripsi = Skripsi::with('mahasiswa')->get();
        return response()->json($skripsi);
    }

    public function store(Request $request)
    {
        $validatedData = $request->validate([
            'id_mahasiswa' => 'required|exists:mahasiswa,id_mahasiswa',
            'judul'        => 'required|string|max:255',
            'status_judul' => 'nullable|string|max:255', // Opsional, karena di database sudah default 'diajukan'
        ]);

        // Jika status_judul tidak diisi, set otomatis menjadi 'diajukan' sebelum disimpan
        if (!isset($validatedData['status_judul'])) {
            $validatedData['status_judul'] = 'diajukan';
        }

        $skripsi = Skripsi::create($validatedData);

        return response()->json([
            'message' => 'Data skripsi berhasil ditambahkan',
            'data'    => $skripsi
        ], 201);
    }

    public function show($id)
    {
        $skripsi = Skripsi::with('mahasiswa')->find($id);

        if (!$skripsi) {
            return response()->json(['message' => 'Data skripsi tidak ditemukan'], 404);
        }

        return response()->json($skripsi);
    }

    public function update(Request $request, $id)
    {
        $skripsi = Skripsi::find($id);

        if (!$skripsi) {
            return response()->json(['message' => 'Data skripsi tidak ditemukan'], 404);
        }

        $validatedData = $request->validate([
            'id_mahasiswa' => 'sometimes|required|exists:mahasiswa,id_mahasiswa',
            'judul'        => 'sometimes|required|string|max:255',
            'status_judul' => 'sometimes|required|string|max:255',
        ]);

        $skripsi->update($validatedData);

        return response()->json([
            'message' => 'Data skripsi berhasil diperbarui',
            'data'    => $skripsi
        ]);
    }

    public function destroy($id)
    {
        $skripsi = Skripsi::find($id);

        if (!$skripsi) {
            return response()->json(['message' => 'Data skripsi tidak ditemukan'], 404);
        }

        $skripsi->delete();

        return response()->json(['message' => 'Data skripsi berhasil dihapus']);
    }
}