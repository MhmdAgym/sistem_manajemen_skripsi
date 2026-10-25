<?php

namespace App\Http\Controllers;

use App\Models\Dosen;
use App\Models\Mahasiswa;
use App\Models\Skripsi;
use Illuminate\Http\Request;

class SkripsiController extends Controller
{
    /**
     * Menampilkan daftar skripsi sesuai kewenangan:
     * - Admin: semua skripsi
     * - Mahasiswa: skripsi miliknya saja
     * - Dosen: skripsi di mana dosen menjadi PA atau Pembimbing
     */
    public function index(Request $request)
    {
        $user = $request->user();
        if (!$user) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        if ($user->role === 'admin') {
            $skripsi = Skripsi::with('mahasiswa')->get();
            return response()->json($skripsi);
        }

        if ($user->role === 'mahasiswa') {
            $mahasiswa = Mahasiswa::where('user_id', $user->id)->first();
            if (!$mahasiswa) {
                return response()->json([]);
            }
            $skripsi = Skripsi::where('id_mahasiswa', $mahasiswa->id_mahasiswa)->with('mahasiswa')->get();
            return response()->json($skripsi);
        }

        if ($user->role === 'dosen') {
            $dosen = Dosen::where('user_id', $user->id)->first();
            if (!$dosen) {
                return response()->json([]);
            }
            $skripsi = Skripsi::with('mahasiswa')
                ->where(function ($query) use ($dosen) {
                    $query->whereHas('dosenPA', fn($q) => $q->where('id_dosen', $dosen->id_dosen))
                          ->orWhereHas('dosenPembimbing', fn($q) => $q->where('id_dosen', $dosen->id_dosen));
                })
                ->get();
            return response()->json($skripsi);
        }

        return response()->json(['message' => 'Anda tidak memiliki hak akses untuk melihat daftar skripsi.'], 403);
    }

    /**
     * Tambah data skripsi baru (Hanya untuk Admin).
     */
    public function store(Request $request)
    {
        $user = $request->user();
        if (!$user || $user->role !== 'admin') {
            return response()->json([
                'message' => 'Hanya admin yang memiliki izin menambahkan data skripsi.'
            ], 403);
        }

        $validatedData = $request->validate([
            'id_mahasiswa' => 'required|exists:mahasiswa,id_mahasiswa',
            'judul'        => 'required|string|max:255',
            'status_judul' => 'nullable|string|max:255',
        ]);

        if (!isset($validatedData['status_judul'])) {
            $validatedData['status_judul'] = 'Belum Mengajukan';
        }

        $skripsi = Skripsi::create($validatedData);

        return response()->json([
            'message' => 'Data skripsi berhasil ditambahkan',
            'data'    => $skripsi
        ], 201);
    }

    /**
     * Tampilkan detail satu skripsi (Admin, Mahasiswa pemilik, atau Dosen PA/Pembimbing terkait).
     */
    public function show(Request $request, $id)
    {
        $user = $request->user();
        if (!$user) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        $skripsi = Skripsi::with(['mahasiswa', 'dosenPA.dosen', 'dosenPembimbing.dosen'])->find($id);

        if (!$skripsi) {
            return response()->json(['message' => 'Data skripsi tidak ditemukan'], 404);
        }

        if ($user->role !== 'admin') {
            $isOwner = ($user->role === 'mahasiswa' && $skripsi->mahasiswa && $skripsi->mahasiswa->user_id === $user->id);
            $isAssignedDosen = false;

            if ($user->role === 'dosen') {
                $dosen = Dosen::where('user_id', $user->id)->first();
                if ($dosen) {
                    $isPA = $skripsi->dosenPA && $skripsi->dosenPA->id_dosen == $dosen->id_dosen;
                    $isPembimbing = $skripsi->dosenPembimbing && $skripsi->dosenPembimbing->contains('id_dosen', $dosen->id_dosen);
                    $isAssignedDosen = $isPA || $isPembimbing;
                }
            }

            if (!$isOwner && !$isAssignedDosen) {
                return response()->json([
                    'message' => 'Anda tidak memiliki hak akses untuk melihat data skripsi ini.'
                ], 403);
            }
        }

        return response()->json($skripsi);
    }

    /**
     * Update data skripsi (Hanya untuk Admin).
     * BATAS WORKFLOW: status_judul TIDAK DAPAT diubah secara langsung via CRUD generik ini.
     * Perubahan status judul harus melalui workflow resmi verifikasi Dosen PA dan persetujuan Kaprodi.
     */
    public function update(Request $request, $id)
    {
        $user = $request->user();
        if (!$user || $user->role !== 'admin') {
            return response()->json([
                'message' => 'Hanya admin yang memiliki izin mengubah data skripsi.'
            ], 403);
        }

        $skripsi = Skripsi::find($id);

        if (!$skripsi) {
            return response()->json(['message' => 'Data skripsi tidak ditemukan'], 404);
        }

        // Perlindungan Batas Workflow Status Judul:
        // Tolak setiap upaya memodifikasi status_judul langsung melalui CRUD umum
        if ($request->has('status_judul') && $request->input('status_judul') !== $skripsi->status_judul) {
            return response()->json([
                'message' => 'Status judul skripsi tidak dapat diubah melalui endpoint ini. Perubahan status judul harus melalui alur resmi verifikasi Dosen PA dan persetujuan Kaprodi.',
                'errors'  => [
                    'status_judul' => ['Status judul skripsi tidak dapat diubah melalui endpoint ini. Perubahan status judul harus melalui alur resmi verifikasi Dosen PA dan persetujuan Kaprodi.']
                ]
            ], 422);
        }

        $validatedData = $request->validate([
            'id_mahasiswa' => 'sometimes|required|exists:mahasiswa,id_mahasiswa',
            'judul'        => 'sometimes|required|string|max:255',
            'status_judul' => 'sometimes|nullable|string|max:255',
        ]);

        // Cegah perpindahan kepemilikan skripsi atau mutasi status_judul
        unset($validatedData['id_mahasiswa']);
        unset($validatedData['status_judul']);

        $skripsi->update($validatedData);

        return response()->json([
            'message' => 'Data skripsi berhasil diperbarui',
            'data'    => $skripsi
        ]);
    }

    /**
     * Hapus data skripsi (Hanya untuk Admin).
     */
    public function destroy(Request $request, $id)
    {
        $user = $request->user();
        if (!$user || $user->role !== 'admin') {
            return response()->json([
                'message' => 'Hanya admin yang memiliki izin menghapus data skripsi.'
            ], 403);
        }

        $skripsi = Skripsi::find($id);

        if (!$skripsi) {
            return response()->json(['message' => 'Data skripsi tidak ditemukan'], 404);
        }

        $skripsi->delete();

        return response()->json(['message' => 'Data skripsi berhasil dihapus']);
    }
}
