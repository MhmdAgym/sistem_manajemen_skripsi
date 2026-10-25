<?php

namespace App\Http\Controllers;

use App\Models\Bimbingan;
use App\Models\DosenPembimbing;
use App\Models\Skripsi;
use Illuminate\Http\Request;

class BimbinganController extends Controller
{
    /**
     * Tampilkan data bimbingan terfilter sesuai kepemilikan dan kewenangan peran.
     */
    public function index(Request $request)
    {
        $user = $request->user();

        if ($user->role === 'admin') {
            $query = Bimbingan::with(['skripsi.mahasiswa']);
            if ($request->has('id_skripsi')) {
                $query->where('id_skripsi', $request->query('id_skripsi'));
            }
            return response()->json($query->get());
        }

        if ($user->role === 'mahasiswa') {
            $mahasiswa = $user->mahasiswa;
            if (!$mahasiswa) {
                return response()->json(['message' => 'Profil mahasiswa tidak ditemukan.'], 404);
            }

            $bimbingan = Bimbingan::with('skripsi')
                ->whereHas('skripsi', function ($q) use ($mahasiswa) {
                    $q->where('id_mahasiswa', $mahasiswa->id_mahasiswa);
                })
                ->get();

            return response()->json($bimbingan);
        }

        if ($user->role === 'dosen') {
            $dosen = $user->dosen;
            if (!$dosen) {
                return response()->json(['message' => 'Profil dosen tidak ditemukan.'], 404);
            }

            // Dosen hanya melihat bimbingan dari skripsi di mana dosen bersangkutan menjadi pembimbing
            $bimbingan = Bimbingan::with(['skripsi.mahasiswa'])
                ->whereHas('skripsi.dosenPembimbing', function ($q) use ($dosen) {
                    $q->where('id_dosen', $dosen->id_dosen);
                })
                ->get();

            return response()->json($bimbingan);
        }

        return response()->json([
            'message' => 'Akses ditolak. Anda tidak memiliki izin untuk mengakses fitur ini.'
        ], 403);
    }

    /**
     * Tambah catatan bimbingan baru dengan validasi kepemilikan dan relasi pembimbing.
     */
    public function store(Request $request)
    {
        $user = $request->user();

        $validatedData = $request->validate([
            'id_skripsi' => 'sometimes|required|exists:skripsi,id_skripsi',
            'tanggal'    => 'required|date',
            'catatan'    => 'required|string',
        ]);

        $targetSkripsiId = null;

        if ($user->role === 'mahasiswa') {
            $mahasiswa = $user->mahasiswa;
            if (!$mahasiswa) {
                return response()->json(['message' => 'Profil mahasiswa tidak ditemukan.'], 404);
            }

            // Jika mahasiswa mengirim id_skripsi, wajib skripsi miliknya sendiri
            if (!empty($validatedData['id_skripsi'])) {
                $skripsi = Skripsi::where('id_skripsi', $validatedData['id_skripsi'])
                    ->where('id_mahasiswa', $mahasiswa->id_mahasiswa)
                    ->first();

                if (!$skripsi) {
                    return response()->json([
                        'message' => 'Akses ditolak. Anda tidak berhak menambahkan bimbingan untuk skripsi ini.'
                    ], 403);
                }
                $targetSkripsiId = $skripsi->id_skripsi;
            } else {
                $skripsi = $mahasiswa->latestSkripsi ?? $mahasiswa->skripsi()->first();
                if (!$skripsi) {
                    return response()->json([
                        'message' => 'Anda belum memiliki skripsi yang terdaftar.'
                    ], 422);
                }
                $targetSkripsiId = $skripsi->id_skripsi;
            }

            // Validasi apakah skripsi sudah memiliki dosen pembimbing
            if (!$skripsi->dosenPembimbing()->exists()) {
                return response()->json([
                    'message' => 'Skripsi belum memiliki Dosen Pembimbing yang ditetapkan. Hubungi Administrator.'
                ], 422);
            }
        } elseif ($user->role === 'dosen') {
            $dosen = $user->dosen;
            if (!$dosen) {
                return response()->json(['message' => 'Profil dosen tidak ditemukan.'], 404);
            }

            if (empty($validatedData['id_skripsi'])) {
                return response()->json([
                    'message' => 'Parameter id_skripsi wajib disertakan oleh Dosen.',
                    'errors'  => ['id_skripsi' => ['Parameter id_skripsi wajib disertakan.']]
                ], 422);
            }

            // Pastikan dosen login adalah pembimbing sah untuk skripsi tersebut
            $isPembimbing = DosenPembimbing::where('id_skripsi', $validatedData['id_skripsi'])
                ->where('id_dosen', $dosen->id_dosen)
                ->exists();

            if (!$isPembimbing) {
                return response()->json([
                    'message' => 'Akses ditolak. Anda bukan Dosen Pembimbing untuk skripsi ini.'
                ], 403);
            }

            $targetSkripsiId = $validatedData['id_skripsi'];
        } elseif ($user->role === 'admin') {
            if (empty($validatedData['id_skripsi'])) {
                return response()->json([
                    'message' => 'Parameter id_skripsi wajib disertakan.',
                    'errors'  => ['id_skripsi' => ['Parameter id_skripsi wajib disertakan.']]
                ], 422);
            }
            $targetSkripsiId = $validatedData['id_skripsi'];
        } else {
            return response()->json([
                'message' => 'Akses ditolak. Anda tidak memiliki izin untuk mengakses fitur ini.'
            ], 403);
        }

        $bimbingan = Bimbingan::create([
            'id_skripsi' => $targetSkripsiId,
            'tanggal'    => $validatedData['tanggal'],
            'catatan'    => $validatedData['catatan'],
        ]);

        return response()->json([
            'message' => 'Data bimbingan berhasil ditambahkan',
            'data'    => $bimbingan->load('skripsi')
        ], 201);
    }

    /**
     * Lihat detail satu catatan bimbingan.
     */
    public function show(Request $request, $id)
    {
        $user = $request->user();
        $bimbingan = Bimbingan::with(['skripsi.mahasiswa', 'skripsi.dosenPembimbing'])->find($id);

        if (!$bimbingan) {
            return response()->json(['message' => 'Data bimbingan tidak ditemukan'], 404);
        }

        if ($user->role === 'admin') {
            return response()->json($bimbingan);
        }

        if ($user->role === 'mahasiswa') {
            $mahasiswa = $user->mahasiswa;
            if (!$mahasiswa || (int) $bimbingan->skripsi?->id_mahasiswa !== (int) $mahasiswa->id_mahasiswa) {
                return response()->json([
                    'message' => 'Akses ditolak. Anda tidak memiliki izin untuk melihat bimbingan ini.'
                ], 403);
            }
            return response()->json($bimbingan);
        }

        if ($user->role === 'dosen') {
            $dosen = $user->dosen;
            $isPembimbing = $dosen && $bimbingan->skripsi?->dosenPembimbing->contains('id_dosen', $dosen->id_dosen);

            if (!$isPembimbing) {
                return response()->json([
                    'message' => 'Akses ditolak. Anda bukan Dosen Pembimbing untuk skripsi ini.'
                ], 403);
            }
            return response()->json($bimbingan);
        }

        return response()->json([
            'message' => 'Akses ditolak. Anda tidak memiliki izin untuk melihat bimbingan ini.'
        ], 403);
    }

    /**
     * Perbarui catatan bimbingan.
     */
    public function update(Request $request, $id)
    {
        $user = $request->user();
        $bimbingan = Bimbingan::with(['skripsi.mahasiswa', 'skripsi.dosenPembimbing'])->find($id);

        if (!$bimbingan) {
            return response()->json(['message' => 'Data bimbingan tidak ditemukan'], 404);
        }

        if ($user->role === 'mahasiswa') {
            $mahasiswa = $user->mahasiswa;
            if (!$mahasiswa || (int) $bimbingan->skripsi?->id_mahasiswa !== (int) $mahasiswa->id_mahasiswa) {
                return response()->json([
                    'message' => 'Akses ditolak. Anda tidak berhak mengubah data bimbingan ini.'
                ], 403);
            }
        } elseif ($user->role === 'dosen') {
            $dosen = $user->dosen;
            $isPembimbing = $dosen && $bimbingan->skripsi?->dosenPembimbing->contains('id_dosen', $dosen->id_dosen);
            if (!$isPembimbing) {
                return response()->json([
                    'message' => 'Akses ditolak. Anda bukan Dosen Pembimbing untuk skripsi ini.'
                ], 403);
            }
        } elseif ($user->role !== 'admin') {
            return response()->json([
                'message' => 'Akses ditolak. Anda tidak memiliki izin untuk mengubah data bimbingan ini.'
            ], 403);
        }

        $validatedData = $request->validate([
            'tanggal'    => 'sometimes|required|date',
            'catatan'    => 'sometimes|required|string',
            'id_skripsi' => 'sometimes|required|exists:skripsi,id_skripsi',
        ]);

        // Cegah manipulasi foreign key skripsi oleh mahasiswa atau dosen
        if ($user->role !== 'admin' && isset($validatedData['id_skripsi'])) {
            if ((int) $validatedData['id_skripsi'] !== (int) $bimbingan->id_skripsi) {
                return response()->json([
                    'message' => 'Akses ditolak. Mengubah relasi skripsi pada bimbingan tidak diizinkan.'
                ], 403);
            }
        }

        $updateData = [];
        if (isset($validatedData['tanggal'])) {
            $updateData['tanggal'] = $validatedData['tanggal'];
        }
        if (isset($validatedData['catatan'])) {
            $updateData['catatan'] = $validatedData['catatan'];
        }
        if ($user->role === 'admin' && isset($validatedData['id_skripsi'])) {
            $updateData['id_skripsi'] = $validatedData['id_skripsi'];
        }

        $bimbingan->update($updateData);

        return response()->json([
            'message' => 'Data bimbingan berhasil diperbarui',
            'data'    => $bimbingan->load('skripsi')
        ]);
    }

    /**
     * Hapus catatan bimbingan.
     */
    public function destroy(Request $request, $id)
    {
        $user = $request->user();
        $bimbingan = Bimbingan::with(['skripsi.mahasiswa', 'skripsi.dosenPembimbing'])->find($id);

        if (!$bimbingan) {
            return response()->json(['message' => 'Data bimbingan tidak ditemukan'], 404);
        }

        if ($user->role === 'mahasiswa') {
            $mahasiswa = $user->mahasiswa;
            if (!$mahasiswa || (int) $bimbingan->skripsi?->id_mahasiswa !== (int) $mahasiswa->id_mahasiswa) {
                return response()->json([
                    'message' => 'Akses ditolak. Anda tidak berhak menghapus data bimbingan ini.'
                ], 403);
            }
        } elseif ($user->role === 'dosen') {
            $dosen = $user->dosen;
            $isPembimbing = $dosen && $bimbingan->skripsi?->dosenPembimbing->contains('id_dosen', $dosen->id_dosen);
            if (!$isPembimbing) {
                return response()->json([
                    'message' => 'Akses ditolak. Anda bukan Dosen Pembimbing untuk skripsi ini.'
                ], 403);
            }
        } elseif ($user->role !== 'admin') {
            return response()->json([
                'message' => 'Akses ditolak. Anda tidak memiliki izin untuk menghapus data bimbingan ini.'
            ], 403);
        }

        $bimbingan->delete();

        return response()->json(['message' => 'Data bimbingan berhasil dihapus']);
    }
}