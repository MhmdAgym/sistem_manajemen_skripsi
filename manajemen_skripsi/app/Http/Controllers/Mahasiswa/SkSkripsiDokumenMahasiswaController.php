<?php

namespace App\Http\Controllers\Mahasiswa;

use App\Http\Controllers\Controller;
use App\Http\Requests\Mahasiswa\StoreSkDokumenMahasiswaRequest;
use App\Http\Requests\Mahasiswa\UpdateSkDokumenMahasiswaRequest;
use App\Models\Mahasiswa;
use App\Models\Skripsi;
use App\Models\SkSkripsiDokumen;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

class SkSkripsiDokumenMahasiswaController extends Controller
{
    /**
     * [MAHASISWA] Menampilkan riwayat seluruh unggahan dokumen SK milik mahasiswa yang login.
     * Menerapkan Policy viewAny dan query scoping terisolasi ke mahasiswa_id milik akun login.
     */
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', SkSkripsiDokumen::class);

        $user = $request->user();
        $mahasiswa = Mahasiswa::where('user_id', $user->id)->first();

        if (!$mahasiswa) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Profil mahasiswa tidak ditemukan untuk akun ini.',
            ], 404);
        }

        $dokumenList = SkSkripsiDokumen::where('mahasiswa_id', $mahasiswa->id_mahasiswa)
            ->with(['skripsi', 'dosen1Usulan', 'dosen2Usulan', 'admin', 'indukDokumen'])
            ->orderBy('versi_ke', 'desc')
            ->get();

        return response()->json([
            'status' => 'success',
            'data'   => $dokumenList,
        ]);
    }

    /**
     * [MAHASISWA] Unggah dokumen SK fisik awal dari tata usaha fakultas.
     * Menerapkan Policy create, verifikasi kepemilikan skripsi yang disetujui, dan transaksi atomik.
     */
    public function store(StoreSkDokumenMahasiswaRequest $request): JsonResponse
    {
        $this->authorize('create', SkSkripsiDokumen::class);

        $user = $request->user();
        $mahasiswa = Mahasiswa::where('user_id', $user->id)->first();

        if (!$mahasiswa) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Profil mahasiswa tidak ditemukan untuk akun ini.',
            ], 404);
        }

        // 1. Validasi keberadaan dan kelayakan skripsi mahasiswa
        $skripsi = Skripsi::where('id_mahasiswa', $mahasiswa->id_mahasiswa)->first();

        if (!$skripsi) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Data skripsi Anda belum tersedia di sistem. Hubungi Administrator.',
            ], 422);
        }

        if ($skripsi->status_judul !== 'disetujui') {
            return response()->json([
                'status'  => 'error',
                'message' => 'Judul skripsi Anda belum disetujui oleh Program Studi. Pengunggahan SK belum diizinkan.',
            ], 422);
        }

        // 2. Cegah duplikasi berkas aktif yang belum selesai diproses atau sudah sah
        $dokumenTerverifikasi = SkSkripsiDokumen::where('skripsi_id', $skripsi->id_skripsi)
            ->where('status_dokumen', 'TERVERIFIKASI')
            ->exists();

        if ($dokumenTerverifikasi) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Skripsi Anda sudah memiliki dokumen SK yang terverifikasi sah.',
            ], 409);
        }

        $dokumenMenunggu = SkSkripsiDokumen::where('skripsi_id', $skripsi->id_skripsi)
            ->where('status_dokumen', 'MENUNGGU_PEMERIKSAAN')
            ->exists();

        if ($dokumenMenunggu) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Anda masih memiliki berkas SK yang sedang menunggu pemeriksaan oleh Administrator.',
            ], 409);
        }

        $validated = $request->validated();
        $file = $request->file('file_sk');

        // 3. Inspeksi fisik berkas dan penyimpanan privat dengan UUID unik
        $sha256 = hash_file('sha256', $file->getRealPath());
        $fileSizeBytes = $file->getSize();
        $fileUuid = (string) Str::uuid();
        $filename = 'sk_' . $skripsi->id_skripsi . '_' . $fileUuid . '.pdf';
        $storagePath = $file->storeAs('sk_skripsi', $filename, 'local');

        // 4. Transaksi atomik pembuatan draf dokumen SK
        try {
            $dokumenBaru = DB::transaction(function () use ($skripsi, $mahasiswa, $validated, $storagePath, $sha256, $fileSizeBytes) {
                // Kunci baris skripsi induk untuk serialisasi
                $lockedSkripsi = Skripsi::where('id_skripsi', $skripsi->id_skripsi)
                    ->lockForUpdate()
                    ->firstOrFail();

                // Nonaktifkan status is_terkini dokumen terdahulu jika ada
                SkSkripsiDokumen::where('skripsi_id', $lockedSkripsi->id_skripsi)->update(['is_terkini' => false]);

                $maxVersi = SkSkripsiDokumen::where('skripsi_id', $lockedSkripsi->id_skripsi)->max('versi_ke') ?? 0;
                $versiKe = $maxVersi + 1;

                return SkSkripsiDokumen::create([
                    'skripsi_id'        => $lockedSkripsi->id_skripsi,
                    'mahasiswa_id'      => $mahasiswa->id_mahasiswa,
                    'versi_ke'          => $versiKe,
                    'is_terkini'        => true,
                    'induk_dokumen_id'  => null,
                    'nomor_sk'          => $validated['nomor_sk'],
                    'tanggal_sk'        => $validated['tanggal_sk'],
                    'file_path'         => $storagePath,
                    'file_hash_sha256'  => $sha256,
                    'file_size_bytes'   => $fileSizeBytes,
                    'status_dokumen'    => 'MENUNGGU_PEMERIKSAAN',
                    'admin_id'          => null,
                    'diperiksa_at'      => null,
                    'catatan_admin'     => null,
                    'dosen_1_usulan_id' => $validated['dosen_1_usulan_id'],
                    'dosen_2_usulan_id' => $validated['dosen_2_usulan_id'] ?? null,
                ]);
            });
        } catch (\Throwable $e) {
            // Hapus berkas fisik jika transaksi database gagal
            Storage::disk('local')->delete($storagePath);
            throw $e;
        }

        // Pasca-commit: transaksi database telah sukses dan berkas fisik aman terlindungi
        try {
            $dokumenBaru->load(['skripsi', 'dosen1Usulan', 'dosen2Usulan']);

            return response()->json([
                'status'  => 'success',
                'message' => 'Dokumen SK Pembimbing Skripsi berhasil diunggah dan sedang menunggu verifikasi Administrator.',
                'data'    => $dokumenBaru,
            ], 201);
        } catch (\Throwable $e) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Dokumen SK berhasil diunggah dan disimpan, namun terjadi kendala saat memuat data respon. Silakan muat ulang halaman.',
            ], 500);
        }
    }

    /**
     * [MAHASISWA] Menampilkan detail satu dokumen SK milik mahasiswa.
     */
    public function show(Request $request, int|string $id): JsonResponse
    {
        $dokumen = SkSkripsiDokumen::with(['skripsi', 'dosen1Usulan', 'dosen2Usulan', 'admin', 'indukDokumen'])
            ->findOrFail($id);

        $this->authorize('view', $dokumen);

        return response()->json([
            'status' => 'success',
            'data'   => $dokumen,
        ]);
    }

    /**
     * [MAHASISWA] Mengajukan revisi berkas SK Pembimbing Skripsi sebagai record versi baru.
     * Menerapkan Policy update, row locking pada skripsi dan dokumen lama, penonaktifan is_terkini
     * dokumen lama (demote to archive), penautan silsilah induk_dokumen_id, dan transaksi atomik.
     */
    public function update(UpdateSkDokumenMahasiswaRequest $request, int|string $id): JsonResponse
    {
        $dokumen = SkSkripsiDokumen::findOrFail($id);
        $this->authorize('update', $dokumen);

        // 1. Verifikasi kelayakan dokumen sebelum menyentuh filesystem:
        //    Revisi hanya sah jika diajukan dari dokumen versi aktif terkini
        if (!$dokumen->is_terkini) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Revisi hanya dapat diajukan berdasarkan dokumen SK versi aktif terkini.',
            ], 422);
        }

        //    Revisi hanya sah jika dokumen berstatus PERLU_PERBAIKAN
        if ($dokumen->status_dokumen !== 'PERLU_PERBAIKAN') {
            return response()->json([
                'status'  => 'error',
                'message' => "Revisi dokumen hanya diizinkan untuk dokumen yang berstatus PERLU_PERBAIKAN. Status dokumen saat ini: {$dokumen->status_dokumen}.",
            ], 422);
        }

        //    Wajibkan file scan PDF baru pada setiap pengajuan revisi
        if (!$request->hasFile('file_sk') || !$request->file('file_sk')->isValid()) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Berkas scan PDF lembar SK revisi wajib diunggah.',
            ], 422);
        }

        $validated = $request->validated();
        $file = $request->file('file_sk');

        // 2. Simpan berkas fisik baru dengan UUID unik sebelum transaksi database
        $sha256 = hash_file('sha256', $file->getRealPath());
        $fileSizeBytes = $file->getSize();
        $fileUuid = (string) Str::uuid();
        $filename = 'sk_' . $dokumen->skripsi_id . '_' . $fileUuid . '.pdf';
        $storagePath = $file->storeAs('sk_skripsi', $filename, 'local');

        if (!$storagePath) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Gagal menyimpan berkas fisik revisi ke penyimpanan sistem.',
            ], 500);
        }

        // 3. Transaksi atomik pembuatan versi baru dan pengarsipan versi lama
        // Batas try-catch ini HANYA mencakup eksekusi transaksi database.
        // Berkas fisik baru hanya dihapus jika transaksi database gagal atau dibatalkan.
        try {
            $dokumenBaru = DB::transaction(function () use ($dokumen, $validated, $storagePath, $sha256, $fileSizeBytes) {
                // Kunci baris induk skripsi untuk serialisasi penentuan versi
                $lockedSkripsi = Skripsi::where('id_skripsi', $dokumen->skripsi_id)
                    ->lockForUpdate()
                    ->firstOrFail();

                // Kunci baris dokumen yang direvisi
                $lockedDokumen = SkSkripsiDokumen::where('id', $dokumen->id)
                    ->lockForUpdate()
                    ->firstOrFail();

                // Verifikasi ulang kondisi konkurensi pasca-locking
                if (!$lockedDokumen->is_terkini) {
                    throw new \DomainException('Dokumen ini sudah tidak berstatus terkini karena telah direvisi oleh transaksi lain.', 409);
                }

                if ($lockedDokumen->status_dokumen !== 'PERLU_PERBAIKAN') {
                    throw new \DomainException("Revisi dokumen hanya diizinkan jika berstatus PERLU_PERBAIKAN. Status saat ini: {$lockedDokumen->status_dokumen}.", 422);
                }

                // Tentukan nomor versi baru: max(versi_ke) + 1 untuk skripsi ini
                $maxVersi = SkSkripsiDokumen::where('skripsi_id', $lockedSkripsi->id_skripsi)->max('versi_ke') ?? $lockedDokumen->versi_ke;
                $versiBaru = max((int) $lockedDokumen->versi_ke, (int) $maxVersi) + 1;

                // Nonaktifkan flag is_terkini pada versi lama tanpa menghapus atau mengubah metadata historis lainnya
                $lockedDokumen->update([
                    'is_terkini' => false,
                ]);

                // Buat record versi baru dengan induk_dokumen_id menunjuk ke versi sebelumnya
                return SkSkripsiDokumen::create([
                    'skripsi_id'        => $lockedSkripsi->id_skripsi,
                    'mahasiswa_id'      => $lockedDokumen->mahasiswa_id,
                    'versi_ke'          => $versiBaru,
                    'is_terkini'        => true,
                    'induk_dokumen_id'  => $lockedDokumen->id,
                    'nomor_sk'          => $validated['nomor_sk'],
                    'tanggal_sk'        => $validated['tanggal_sk'],
                    'file_path'         => $storagePath,
                    'file_hash_sha256'  => $sha256,
                    'file_size_bytes'   => $fileSizeBytes,
                    'status_dokumen'    => 'MENUNGGU_PEMERIKSAAN',
                    'admin_id'          => null,
                    'diperiksa_at'      => null,
                    'catatan_admin'     => null,
                    'dosen_1_usulan_id' => $validated['dosen_1_usulan_id'],
                    'dosen_2_usulan_id' => $validated['dosen_2_usulan_id'] ?? null,
                ]);
            });
        } catch (\DomainException $e) {
            Storage::disk('local')->delete($storagePath);
            return response()->json([
                'status'  => 'error',
                'message' => $e->getMessage(),
            ], $e->getCode() ?: 422);
        } catch (\Illuminate\Database\QueryException $e) {
            Storage::disk('local')->delete($storagePath);
            return response()->json([
                'status'  => 'error',
                'message' => 'Terjadi konflik versi dokumen SK pada sistem basis data. Silakan muat ulang halaman.',
            ], 409);
        } catch (\Throwable $e) {
            Storage::disk('local')->delete($storagePath);
            throw $e;
        }

        // 4. Proses pasca-commit (transaksi basis data telah commit penuh)
        // Berkas fisik baru DILINDUNGI dan DILARANG dihapus jika load() atau pembentukan response gagal.
        try {
            $dokumenBaru->load(['skripsi', 'dosen1Usulan', 'dosen2Usulan', 'indukDokumen']);

            return response()->json([
                'status'  => 'success',
                'message' => 'Revisi dokumen SK Pembimbing Skripsi berhasil diajukan sebagai versi baru dan sedang menunggu verifikasi Administrator.',
                'data'    => $dokumenBaru,
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Revisi dokumen SK berhasil disimpan, namun terjadi kendala saat memuat data respon. Silakan muat ulang halaman.',
            ], 500);
        }
    }

    /**
     * [MAHASISWA] Mengunduh berkas fisik scan PDF dari storage privat dengan otorisasi.
     */
    public function download(Request $request, int|string $id): StreamedResponse
    {
        $dokumen = SkSkripsiDokumen::findOrFail($id);
        $this->authorize('view', $dokumen);

        if (!Storage::disk('local')->exists($dokumen->file_path)) {
            abort(404, 'Berkas fisik SK tidak ditemukan di storage privat.');
        }

        $safeNomorSk = preg_replace('/[^A-Za-z0-9_\-]/', '_', $dokumen->nomor_sk);
        return Storage::disk('local')->download($dokumen->file_path, "SK_Pembimbing_{$safeNomorSk}.pdf");
    }
}

