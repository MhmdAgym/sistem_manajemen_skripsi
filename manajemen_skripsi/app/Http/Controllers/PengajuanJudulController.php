<?php

namespace App\Http\Controllers;

use App\Models\PengajuanJudul;
use App\Models\Mahasiswa;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class PengajuanJudulController extends Controller
{
    /**
     * [MAHASISWA] Riwayat pengajuan judul milik mahasiswa yang sedang login.
     *
     * Middleware auth:sanctum + role:mahasiswa menjamin $request->user() tidak null.
     * Query difilter berdasarkan id_mahasiswa milik user yang login (ownership).
     */
    public function index(Request $request)
    {
        $user = $request->user();

        $mahasiswa = Mahasiswa::where('user_id', $user->id)->first();

        if (!$mahasiswa) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Profil mahasiswa tidak ditemukan untuk akun ini. Hubungi Administrator.',
            ], 404);
        }

        $riwayat = PengajuanJudul::with(['dosenPa'])
            ->where('mahasiswa_id', $mahasiswa->id_mahasiswa)
            ->latest()
            ->get();

        return response()->json([
            'status' => 'success',
            'data'   => $riwayat,
        ]);
    }

    /**
     * [MAHASISWA] Kirim pengajuan judul baru.
     *
     * Alur verifikasi backend (tidak menerima ID dari frontend):
     * 1. Identifikasi mahasiswa dari user yang login.
     * 2. Cari skripsi aktif + Dosen PA via relasi DB.
     * 3. Blokir jika profil/skripsi/dosen PA tidak ada.
     * 4. Upload file, lalu simpan ke DB dalam blok try-catch.
     * 5. Jika DB gagal, hapus file yang sudah terupload.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'judul'      => 'required|string|max:255',
            'deskripsi'  => 'required|string',
            'file_judul' => 'required|file|mimes:pdf,doc,docx|max:5120',
        ]);

        $user = $request->user(); // Dijamin tidak null oleh middleware

        // 1. Identifikasi mahasiswa dari session user yang login
        $mahasiswa = Mahasiswa::where('user_id', $user->id)->first();

        if (!$mahasiswa) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Profil mahasiswa tidak ditemukan. Hubungi Administrator.',
            ], 422);
        }

        // 2. Cari skripsi aktif + rantai relasi ke Dosen PA
        //    Rantai: skripsi → dosen_pa (id_skripsi) → dosen (id_dosen) → users (user_id)
        $activeSkripsi = $mahasiswa->skripsi()
            ->with(['dosenPA.dosen'])
            ->orderByDesc('id_skripsi')
            ->first();

        if (!$activeSkripsi) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Data skripsi Anda belum tersedia. Hubungi Administrator.',
            ], 422);
        }

        // 3. Resolve user_id Dosen PA — dari data backend, BUKAN dari frontend
        $dosenPaRecord = $activeSkripsi->dosenPA;

        if (!$dosenPaRecord || !$dosenPaRecord->dosen) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Dosen Pembimbing Akademik (PA) Anda belum ditetapkan oleh Administrator. Silakan hubungi Admin terlebih dahulu.',
            ], 422);
        }

        $dosenPaUserId = $dosenPaRecord->dosen->user_id; // FK ke users.id

        if (!$dosenPaUserId) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Akun Dosen PA tidak ditemukan. Hubungi Administrator.',
            ], 422);
        }

        // 4. Upload file terlebih dahulu
        $uploadedFile = $request->file('file_judul');
        $namaFileAsli = $uploadedFile->getClientOriginalName();

        $bytes = $uploadedFile->getSize();
        $ukuranFile = $bytes >= 1048576
            ? number_format($bytes / 1048576, 2) . ' MB'
            : number_format($bytes / 1024, 1) . ' KB';

        $filePath = $uploadedFile->store('pengajuan-judul', 'public');

        // 5. Simpan ke database — hapus file jika DB gagal
        try {
            $pengajuan = PengajuanJudul::create([
                'mahasiswa_id'  => $mahasiswa->id_mahasiswa, // PK dari tabel mahasiswa
                'dosen_pa_id'   => $dosenPaUserId,           // user_id dari tabel users
                'judul'         => $validated['judul'],
                'deskripsi'     => $validated['deskripsi'],
                'nama_file'     => $namaFileAsli,
                'ukuran_file'   => $ukuranFile,
                'file_path'     => $filePath,
                'status'        => 'Menunggu Verifikasi Dosen PA',
                'catatan_dosen' => null,
            ]);
        } catch (\Throwable $e) {
            // Bersihkan file yang sudah terupload agar tidak orphan
            Storage::disk('public')->delete($filePath);

            return response()->json([
                'status'  => 'error',
                'message' => 'Gagal menyimpan pengajuan ke database. Silakan coba lagi.',
            ], 500);
        }

        return response()->json([
            'status'  => 'success',
            'message' => 'Pengajuan judul berhasil dikirim ke Dosen PA.',
            'data'    => $pengajuan,
        ], 201);
    }

    /**
     * [DOSEN PA] Daftar pengajuan judul mahasiswa bimbingan dosen yang login.
     *
     * Filter ketat: hanya tampilkan pengajuan dengan dosen_pa_id = users.id dosen yang login.
     * Middleware role:dosen menjamin hanya akun dosen yang bisa mengakses.
     */
    public function daftarVerifikasiDosen(Request $request)
    {
        $dosenUser = $request->user(); // Dijamin tidak null oleh middleware

        $pengajuan = PengajuanJudul::with(['mahasiswa.user'])
            ->where('dosen_pa_id', $dosenUser->id) // Filter kepemilikan ketat
            ->latest()
            ->get();

        return response()->json([
            'status' => 'success',
            'data'   => $pengajuan,
        ]);
    }

    /**
     * [DOSEN PA] Verifikasi pengajuan judul (Setujui / Revisi / Tolak).
     *
     * Keamanan ganda: query memfilter berdasarkan dosen_pa_id = user yang login,
     * sehingga dosen tidak bisa memverifikasi pengajuan milik dosen lain
     * meski mengganti {id} pada URL.
     */
    public function verifikasiDosen(Request $request, $id)
    {
        $validated = $request->validate([
            'status'        => 'required|in:Disetujui Dosen PA,Revisi,Ditolak',
            'catatan_dosen' => 'nullable|string|max:1000',
        ]);

        $dosenUser = $request->user(); // Dijamin tidak null oleh middleware

        // Cari pengajuan DAN pastikan milik dosen yang login (ownership check)
        $pengajuan = PengajuanJudul::where('id', $id)
            ->where('dosen_pa_id', $dosenUser->id)
            ->first();

        if (!$pengajuan) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Pengajuan tidak ditemukan atau Anda tidak memiliki akses untuk memverifikasinya.',
            ], 404);
        }

        $pengajuan->update([
            'status'        => $validated['status'],
            'catatan_dosen' => $validated['catatan_dosen'],
        ]);

        return response()->json([
            'status'  => 'success',
            'message' => 'Status pengajuan judul berhasil diperbarui.',
            'data'    => $pengajuan->fresh(['mahasiswa.user', 'dosenPa']),
        ]);
    }

    /**
     * [ADMIN] Seluruh data pengajuan dari semua mahasiswa.
     *
     * Middleware auth:sanctum + admin menjamin hanya admin yang bisa akses.
     */
    public function semuaPengajuan()
    {
        $semua = PengajuanJudul::with(['mahasiswa.user', 'dosenPa'])
            ->latest()
            ->get();

        return response()->json([
            'status' => 'success',
            'data'   => $semua,
        ]);
    }
}