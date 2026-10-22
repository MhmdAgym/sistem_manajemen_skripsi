<?php

namespace App\Http\Controllers;

use App\Models\PengajuanJudul;
use App\Models\PengajuanJudulLog;
use App\Models\Mahasiswa;
use App\Models\Skripsi;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
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

        $riwayat = PengajuanJudul::with(['dosenPa', 'skripsi', 'logs'])
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
     * 2. Cari kandidat skripsi milik mahasiswa secara eksplisit (Anti-Ambigu).
     * 3. Blokir jika profil / skripsi / dosen PA tidak ada, atau jika skripsi ambigu (>1).
     * 4. Upload berkas dokumen sementara.
     * 5. Transaksi atomik: Kunci baris induk skripsi (lockForUpdate) untuk mutual exclusion.
     * 6. Periksa status judul final dan pengajuan aktif di dalam penguncian transaksi.
     * 7. Buat pengajuan + log audit atomik; bersihkan berkas jika transaksi gagal.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'judul'      => 'required|string|max:255',
            'deskripsi'  => 'required|string',
            'file_judul' => 'required|file|mimes:pdf,doc,docx|max:5120',
        ]);

        $user = $request->user();

        // 1. Identifikasi mahasiswa dari session user yang login
        $mahasiswa = Mahasiswa::where('user_id', $user->id)->first();

        if (!$mahasiswa) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Profil mahasiswa tidak ditemukan. Hubungi Administrator.',
            ], 422);
        }

        // 2. Cari kandidat skripsi milik mahasiswa secara eksplisit (Anti-Ambigu)
        $kandidatSkripsi = $mahasiswa->skripsi()
            ->with(['dosenPA.dosen'])
            ->get();

        if ($kandidatSkripsi->isEmpty()) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Data skripsi Anda belum tersedia. Hubungi Administrator.',
            ], 422);
        }

        if ($kandidatSkripsi->count() > 1) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Ditemukan lebih dari satu data skripsi aktif untuk akun Anda. Harap hubungi Administrator untuk resolusi data.',
            ], 422);
        }

        $activeSkripsi = $kandidatSkripsi->first();

        // 3. Resolve user_id Dosen PA dari data relasi backend
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

        // 4. Upload file dokumen sementara
        $uploadedFile = $request->file('file_judul');
        $namaFileAsli = $uploadedFile->getClientOriginalName();

        $bytes = $uploadedFile->getSize();
        $ukuranFile = $bytes >= 1048576
            ? number_format($bytes / 1048576, 2) . ' MB'
            : number_format($bytes / 1024, 1) . ' KB';

        $filePath = $uploadedFile->store('pengajuan-judul', 'public');

        // 5. Simpan ke database beserta log audit dalam transaksi atomik dengan penguncian baris induk
        try {
            DB::transaction(function () use ($mahasiswa, $activeSkripsi, $dosenPaUserId, $validated, $namaFileAsli, $ukuranFile, $filePath, $user, &$pengajuan) {
                // Kunci baris induk skripsi untuk mencegah dua submit simultan
                $lockedSkripsi = Skripsi::where('id_skripsi', $activeSkripsi->id_skripsi)
                    ->lockForUpdate()
                    ->first();

                if (!$lockedSkripsi || $lockedSkripsi->id_mahasiswa !== $mahasiswa->id_mahasiswa) {
                    throw new \DomainException('Data skripsi Anda tidak valid atau tidak konsisten.');
                }

                // Validasi di dalam lock: Apakah skripsi sudah memiliki judul yang disetujui secara final?
                if ($lockedSkripsi->status_judul === 'disetujui' || PengajuanJudul::where('skripsi_id', $lockedSkripsi->id_skripsi)->where('status', 'Disetujui Kaprodi')->exists()) {
                    throw new \DomainException('Skripsi Anda sudah memiliki judul yang disetujui secara final.');
                }

                // Validasi di dalam lock: Apakah masih ada pengajuan aktif yang belum selesai diproses?
                $hasActive = PengajuanJudul::where('skripsi_id', $lockedSkripsi->id_skripsi)
                    ->whereIn('status', [
                        'Menunggu Verifikasi Dosen PA',
                        'Menunggu Persetujuan Kaprodi',
                        'Revisi Dosen PA',
                        'Revisi Kaprodi',
                        'Revisi',
                    ])
                    ->exists();

                if ($hasActive) {
                    throw new \DomainException('Masih terdapat pengajuan judul yang aktif dan sedang dalam proses. Harap selesaikan pengajuan tersebut terlebih dahulu.');
                }

                $pengajuan = PengajuanJudul::create([
                    'skripsi_id'    => $lockedSkripsi->id_skripsi,
                    'mahasiswa_id'  => $mahasiswa->id_mahasiswa,
                    'dosen_pa_id'   => $dosenPaUserId,
                    'judul'         => $validated['judul'],
                    'deskripsi'     => $validated['deskripsi'],
                    'nama_file'     => $namaFileAsli,
                    'ukuran_file'   => $ukuranFile,
                    'file_path'     => $filePath,
                    'status'        => 'Menunggu Verifikasi Dosen PA',
                    'catatan_dosen' => null,
                ]);

                PengajuanJudulLog::create([
                    'pengajuan_judul_id' => $pengajuan->id,
                    'user_id'            => $user->id,
                    'aktor_nama'         => $user->name,
                    'aktor_role'         => 'mahasiswa',
                    'aktor_nomor_induk'  => $mahasiswa->NIM ?? $user->nomor_induk,
                    'tahap'              => 'pengajuan_awal',
                    'tindakan'           => 'submit',
                    'status_sebelum'     => null,
                    'status_sesudah'     => 'Menunggu Verifikasi Dosen PA',
                    'catatan'            => null,
                    'snapshot_judul'     => $pengajuan->judul,
                    'snapshot_file_path' => $filePath,
                ]);
            });
        } catch (\Throwable $e) {
            // Bersihkan berkas baru yang sempat terunggah jika transaksi gagal
            if ($filePath) {
                try {
                    $deleted = Storage::disk('public')->delete($filePath);
                    if (!$deleted) {
                        Log::warning("Storage::delete mengembalikan false saat membersihkan upload baru [{$filePath}].");
                    }
                } catch (\Throwable $cleanEx) {
                    Log::error("Exception saat membersihkan berkas upload pengajuan baru [{$filePath}]: " . $cleanEx->getMessage());
                }
            }

            if ($e instanceof \DomainException) {
                return response()->json([
                    'status'  => 'error',
                    'message' => $e->getMessage(),
                ], 422);
            }

            Log::error("Kegagalan internal saat menyimpan pengajuan judul: " . $e->getMessage(), [
                'exception' => $e,
                'user_id'   => $user->id,
            ]);

            return response()->json([
                'status'  => 'error',
                'message' => 'Gagal menyimpan pengajuan ke database. Silakan coba lagi.',
            ], 500);
        }

        return response()->json([
            'status'  => 'success',
            'message' => 'Pengajuan judul berhasil dikirim ke Dosen PA.',
            'data'    => $pengajuan->fresh(['dosenPa', 'skripsi', 'logs']),
        ], 201);
    }

    /**
     * [MAHASISWA] Kirim perbaikan pengajuan judul pasca-revisi.
     *
     * Aturan:
     * - Hanya pengajuan dengan status 'Revisi Dosen PA', 'Revisi Kaprodi', atau 'Revisi' yang dapat diperbaiki.
     * - Status kembali ke 'Menunggu Verifikasi Dosen PA' (alur harus melalui Dosen PA kembali).
     * - Mengunggah file baru (opsional/jika ada) dan menghapus file lama pasca-commit.
     * - Dicatat dalam audit trail tahap 'revisi_mahasiswa' dan tindakan 'resubmit'.
     */
    public function revisi(Request $request, $id)
    {
        $validated = $request->validate([
            'judul'             => 'sometimes|required|string|max:255',
            'deskripsi'         => 'sometimes|required|string',
            'file_judul'        => 'nullable|file|mimes:pdf,doc,docx|max:5120',
            'catatan_mahasiswa' => 'nullable|string|max:1000',
        ]);

        $user = $request->user();
        $mahasiswa = Mahasiswa::where('user_id', $user->id)->first();

        if (!$mahasiswa) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Profil mahasiswa tidak ditemukan.',
            ], 422);
        }

        $pengajuan = PengajuanJudul::where('id', $id)
            ->where('mahasiswa_id', $mahasiswa->id_mahasiswa)
            ->first();

        if (!$pengajuan) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Pengajuan tidak ditemukan atau bukan milik Anda.',
            ], 404);
        }

        // Validasi: Hanya pengajuan dalam status revisi yang boleh diperbaiki
        if (!in_array($pengajuan->status, ['Revisi Dosen PA', 'Revisi Kaprodi', 'Revisi'], true)) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Hanya pengajuan dengan status revisi yang dapat diajukan ulang. Status saat ini: ' . $pengajuan->status,
            ], 422);
        }

        $newFilePath = null;
        $newNamaFile = null;
        $newUkuranFile = null;

        if ($request->hasFile('file_judul')) {
            $uploadedFile = $request->file('file_judul');
            $newNamaFile = $uploadedFile->getClientOriginalName();
            $bytes = $uploadedFile->getSize();
            $newUkuranFile = $bytes >= 1048576
                ? number_format($bytes / 1048576, 2) . ' MB'
                : number_format($bytes / 1024, 1) . ' KB';
            $newFilePath = $uploadedFile->store('pengajuan-judul', 'public');
        }

        $oldFilePath = null;

        try {
            DB::transaction(function () use ($pengajuan, $user, $mahasiswa, $validated, $request, $newFilePath, $newNamaFile, $newUkuranFile, &$updatedPengajuan, &$oldFilePath) {
                // Kunci baris pengajuan dan verifikasi ulang kepemilikan
                $locked = PengajuanJudul::where('id', $pengajuan->id)
                    ->where('mahasiswa_id', $mahasiswa->id_mahasiswa)
                    ->lockForUpdate()
                    ->first();

                if (!$locked) {
                    throw new \DomainException("Pengajuan tidak ditemukan atau bukan milik Anda.");
                }

                if (!in_array($locked->status, ['Revisi Dosen PA', 'Revisi Kaprodi', 'Revisi'], true)) {
                    throw new \DomainException("Status pengajuan telah berubah. Status saat ini: " . $locked->status);
                }

                $statusSebelum = $locked->status;
                $oldFilePath = $locked->file_path; // Ambil path lama dari baris terkunci

                $updateData = [
                    'status'        => 'Menunggu Verifikasi Dosen PA', // Kembali ke antrean Dosen PA!
                    'catatan_dosen' => null,
                ];

                if ($request->has('judul')) {
                    $updateData['judul'] = $validated['judul'];
                }
                if ($request->has('deskripsi')) {
                    $updateData['deskripsi'] = $validated['deskripsi'];
                }
                if ($newFilePath) {
                    $updateData['nama_file']   = $newNamaFile;
                    $updateData['ukuran_file'] = $newUkuranFile;
                    $updateData['file_path']   = $newFilePath;
                }

                $locked->update($updateData);

                PengajuanJudulLog::create([
                    'pengajuan_judul_id' => $locked->id,
                    'user_id'            => $user->id,
                    'aktor_nama'         => $user->name,
                    'aktor_role'         => 'mahasiswa',
                    'aktor_nomor_induk'  => $mahasiswa->NIM ?? $user->nomor_induk,
                    'tahap'              => 'revisi_mahasiswa',
                    'tindakan'           => 'resubmit',
                    'status_sebelum'     => $statusSebelum,
                    'status_sesudah'     => 'Menunggu Verifikasi Dosen PA',
                    'catatan'            => $validated['catatan_mahasiswa'] ?? null,
                    'snapshot_judul'     => $locked->judul,
                    'snapshot_file_path' => $locked->file_path,
                ]);

                $updatedPengajuan = $locked;
            });
        } catch (\Throwable $e) {
            // JIKA TRANSAKSI GAGAL / ROLLBACK:
            // Bersihkan HANYA berkas baru yang diunggah. Berkas lama tetap dipertahankan.
            if ($newFilePath) {
                try {
                    $deleted = Storage::disk('public')->delete($newFilePath);
                    if (!$deleted) {
                        Log::warning("Storage::delete mengembalikan false saat membersihkan berkas baru pasca-rollback [{$newFilePath}].");
                    }
                } catch (\Throwable $cleanEx) {
                    Log::error("Exception saat membersihkan berkas baru pasca-rollback [{$newFilePath}]: " . $cleanEx->getMessage());
                }
            }

            if ($e instanceof \DomainException) {
                return response()->json([
                    'status'  => 'error',
                    'message' => $e->getMessage(),
                ], 422);
            }

            Log::error("Kegagalan internal saat memperbarui pengajuan judul pasca-revisi: " . $e->getMessage(), [
                'exception' => $e,
                'user_id'   => $user->id,
            ]);

            return response()->json([
                'status'  => 'error',
                'message' => 'Terjadi kesalahan pada server saat memproses perbaikan pengajuan. Silakan coba lagi.',
            ], 500);
        }

        // JIKA TRANSAKSI BERHASIL KEMBALI:
        // Hapus berkas lama hanya jika berkas baru benar-benar menggantikannya dan path berbeda
        if ($newFilePath && $oldFilePath && $oldFilePath !== $newFilePath) {
            try {
                $deletedOld = Storage::disk('public')->delete($oldFilePath);
                if (!$deletedOld) {
                    Log::warning("Storage::delete mengembalikan false saat menghapus berkas revisi lama [{$oldFilePath}]. Berkas mungkin sudah tidak ada.");
                }
            } catch (\Throwable $fileEx) {
                Log::warning("Exception saat menghapus berkas revisi lama pasca-commit [{$oldFilePath}]: " . $fileEx->getMessage());
            }
        }

        return response()->json([
            'status'  => 'success',
            'message' => 'Pengajuan perbaikan judul berhasil dikirim kembali ke Dosen PA.',
            'data'    => $updatedPengajuan->fresh(['dosenPa', 'skripsi', 'logs']),
        ]);
    }

    /**
     * [DOSEN PA] Daftar pengajuan judul mahasiswa bimbingan dosen yang login.
     *
     * Filter ketat: hanya tampilkan pengajuan dengan dosen_pa_id = users.id dosen yang login.
     */
    public function daftarVerifikasiDosen(Request $request)
    {
        $dosenUser = $request->user();

        $pengajuan = PengajuanJudul::with(['mahasiswa.user', 'skripsi', 'logs'])
            ->where('dosen_pa_id', $dosenUser->id)
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
     * Aturan:
     * - Keputusan Dosen PA:
     *   - Setujui -> Menunggu Persetujuan Kaprodi
     *   - Revisi  -> Revisi Dosen PA (wajib catatan)
     *   - Tolak   -> Ditolak Dosen PA (wajib catatan)
     * - Validasi status awal: Harus 'Menunggu Verifikasi Dosen PA'.
     * - Tidak mengubah judul final skripsi.
     * - Berjalan dalam transaksi atomik dengan pessimistic locking + audit logging.
     */
    public function verifikasiDosen(Request $request, $id)
    {
        $validated = $request->validate([
            'status'        => 'required|in:Menunggu Persetujuan Kaprodi,Revisi Dosen PA,Ditolak Dosen PA,Disetujui Dosen PA,Revisi,Ditolak',
            'catatan_dosen' => 'nullable|string|max:1000',
        ]);

        $dosenUser = $request->user();

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

        // Pemetaan kompatibilitas status
        $targetStatus = match ($validated['status']) {
            'Disetujui Dosen PA' => 'Menunggu Persetujuan Kaprodi',
            'Revisi'             => 'Revisi Dosen PA',
            'Ditolak'            => 'Ditolak Dosen PA',
            default              => $validated['status'],
        };

        // Validasi: Catatan wajib untuk tindakan revisi atau penolakan
        if (in_array($targetStatus, ['Revisi Dosen PA', 'Ditolak Dosen PA'], true) && empty(trim((string)$request->input('catatan_dosen')))) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Catatan dosen wajib diisi untuk tindakan revisi atau penolakan.',
                'errors'  => ['catatan_dosen' => ['Catatan dosen wajib diisi untuk tindakan revisi atau penolakan.']],
            ], 422);
        }

        // Validasi status awal: Harus 'Menunggu Verifikasi Dosen PA'
        if ($pengajuan->status !== 'Menunggu Verifikasi Dosen PA') {
            return response()->json([
                'status'  => 'error',
                'message' => 'Pengajuan tidak berada dalam tahap Menunggu Verifikasi Dosen PA. Status saat ini: ' . $pengajuan->status,
            ], 422);
        }

        $catatanDosen = $validated['catatan_dosen'] ?? null;

        try {
            DB::transaction(function () use ($pengajuan, $dosenUser, $targetStatus, $catatanDosen, &$updatedPengajuan) {
                $locked = PengajuanJudul::where('id', $pengajuan->id)->lockForUpdate()->first();

                if (!$locked || $locked->status !== 'Menunggu Verifikasi Dosen PA') {
                    throw new \DomainException("Status pengajuan telah berubah.");
                }

                $statusSebelum = $locked->status;
                $locked->update([
                    'status'        => $targetStatus,
                    'catatan_dosen' => $catatanDosen,
                ]);

                $dosenProfile = $dosenUser->dosen;

                PengajuanJudulLog::create([
                    'pengajuan_judul_id' => $locked->id,
                    'user_id'            => $dosenUser->id,
                    'aktor_nama'         => $dosenUser->name,
                    'aktor_role'         => 'dosen_pa',
                    'aktor_nomor_induk'  => $dosenProfile?->NIDN ?? $dosenUser->nomor_induk,
                    'tahap'              => 'verifikasi_dosen_pa',
                    'tindakan'           => match ($targetStatus) {
                        'Menunggu Persetujuan Kaprodi' => 'setujui_dosen_pa',
                        'Revisi Dosen PA'              => 'revisi_dosen_pa',
                        'Ditolak Dosen PA'             => 'tolak_dosen_pa',
                    },
                    'status_sebelum'     => $statusSebelum,
                    'status_sesudah'     => $targetStatus,
                    'catatan'            => $catatanDosen,
                    'snapshot_judul'     => $locked->judul,
                    'snapshot_file_path' => $locked->file_path,
                ]);

                $updatedPengajuan = $locked;
            });
        } catch (\Throwable $e) {
            if ($e instanceof \DomainException) {
                return response()->json([
                    'status'  => 'error',
                    'message' => $e->getMessage(),
                ], 422);
            }

            Log::error("Kegagalan internal saat verifikasi pengajuan judul oleh Dosen PA: " . $e->getMessage(), [
                'exception'    => $e,
                'user_id'      => $dosenUser->id,
                'pengajuan_id' => $id,
            ]);

            return response()->json([
                'status'  => 'error',
                'message' => 'Terjadi kesalahan pada server saat memproses verifikasi pengajuan. Silakan coba lagi.',
            ], 500);
        }

        return response()->json([
            'status'  => 'success',
            'message' => 'Status pengajuan judul berhasil diperbarui.',
            'data'    => $updatedPengajuan->fresh(['mahasiswa.user', 'dosenPa', 'skripsi', 'logs']),
        ]);
    }

    /**
     * [ADMIN] Seluruh data pengajuan dari semua mahasiswa.
     */
    public function semuaPengajuan()
    {
        $semua = PengajuanJudul::with(['mahasiswa.user', 'dosenPa', 'skripsi', 'logs'])
            ->latest()
            ->get();

        return response()->json([
            'status' => 'success',
            'data'   => $semua,
        ]);
    }
}