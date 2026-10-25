<?php

namespace App\Http\Controllers;

use App\Models\DosenPA;
use Illuminate\Http\Request;

class DosenPAController extends Controller
{
    private function checkAdmin(Request $request)
    {
        $user = $request->user();
        if (!$user || $user->role !== 'admin') {
            return response()->json(['message' => 'Hanya admin yang memiliki izin mengakses endpoint ini.'], 403);
        }
        return null;
    }

    public function index(Request $request)
    {
        if ($deny = $this->checkAdmin($request)) return $deny;

        // Menampilkan data Dosen PA beserta detail dosen dan skripsinya
        $dosen_pa = DosenPA::with(['dosen', 'skripsi'])->get();
        return response()->json($dosen_pa);
    }

    public function store(Request $request)
    {
        if ($deny = $this->checkAdmin($request)) return $deny;

        $validatedData = $request->validate([
            'id_dosen'   => 'required|exists:dosen,id_dosen',
            'id_skripsi' => 'required|exists:skripsi,id_skripsi',
        ]);

        // Opsional: Cek apakah skripsi ini sudah memiliki Dosen PA agar tidak ganda
        $exists = DosenPA::where('id_skripsi', $request->id_skripsi)->first();
        if ($exists) {
            return response()->json(['message' => 'Skripsi ini sudah memiliki Dosen PA'], 400);
        }

        $dosen_pa = DosenPA::create($validatedData);

        return response()->json([
            'message' => 'Dosen PA berhasil ditetapkan pada skripsi',
            'data'    => $dosen_pa
        ], 201);
    }

    public function show(Request $request, $id)
    {
        if ($deny = $this->checkAdmin($request)) return $deny;

        $dosen_pa = DosenPA::with(['dosen', 'skripsi'])->find($id);

        if (!$dosen_pa) {
            return response()->json(['message' => 'Data Dosen PA tidak ditemukan'], 404);
        }

        return response()->json($dosen_pa);
    }

    public function update(Request $request, $id)
    {
        if ($deny = $this->checkAdmin($request)) return $deny;

        $dosen_pa = DosenPA::find($id);

        if (!$dosen_pa) {
            return response()->json(['message' => 'Data Dosen PA tidak ditemukan'], 404);
        }

        $validatedData = $request->validate([
            'id_dosen'   => 'sometimes|required|exists:dosen,id_dosen',
            'id_skripsi' => 'sometimes|required|exists:skripsi,id_skripsi',
        ]);

        $dosen_pa->update($validatedData);

        return response()->json([
            'message' => 'Data Dosen PA berhasil diperbarui',
            'data'    => $dosen_pa
        ]);
    }

    public function destroy(Request $request, $id)
    {
        if ($deny = $this->checkAdmin($request)) return $deny;

        $dosen_pa = DosenPA::find($id);

        if (!$dosen_pa) {
            return response()->json(['message' => 'Data Dosen PA tidak ditemukan'], 404);
        }

        $dosen_pa->delete();

        return response()->json(['message' => 'Data Dosen PA berhasil dihapus']);
    }
}
