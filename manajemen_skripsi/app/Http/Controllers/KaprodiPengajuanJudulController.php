<?php

namespace App\Http\Controllers;

use App\Models\Dosen;
use App\Models\PengajuanJudul;
use App\Models\PengajuanJudulLog;
use App\Models\PenugasanKaprodi;
use App\Models\Skripsi;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class KaprodiPengajuanJudulController extends Controller
{
    /**
     * Helper untuk memvalidasi dan mengambil penugasan Kaprodi aktif milik user login.
     * Mengembalikan array prodi yang ditugaskan, atau melempar response error jika tidak berwenang/konflik.
     */
    private function resolveActiveKaprodiProdis($user): array|\Illuminate\Http\JsonResponse
    {
        $dosen = $user->dosen;

        if (!$dosen) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Profil dosen tidak ditemukan untuk akun ini.',
            ], 403);
        }

        $today = now()->toDateString();

        // Cari penugasan aktif dosen yang bersangkutan
        $penugasanAktif = PenugasanKaprodi::where('dosen_id', $dosen->id_dosen)
            ->where('is_aktif', true)
            ->where('tanggal_mulai', '<=', $today)
            ->where(function ($q) use ($today) {
                $q->whereNull('tanggal_selesai')
                  ->orWhere('tanggal_selesai', '>=', $today);
            })
            ->get();

        if ($penugasanAktif->isEmpty()) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Akses ditolak. Anda tidak memiliki penugasan aktif sebagai Ketua Program Studi.',
            ], 403);
        }

        $prodis = $penugasanAktif->pluck('prodi')->unique()->toArray();

        // Periksa apakah ada konflik penugasan ganda aktif untuk program studi tersebut
        foreach ($prodis as $prodi) {
            $totalActiveForProdi = PenugasanKaprodi::where('prodi', $prodi)
                ->where('is_aktif', true)
                ->where('tanggal_mulai', '<=', $today)
                ->where(function ($q) use ($today) {
                    $q->whereNull('tanggal_selesai')
                      ->orWhere('tanggal_selesai', '>=', $today);
                })
                ->count();

            if ($totalActiveForProdi > 1) {
                return response()->json([
                    'status'  => 'error',
                    'message' => "Terdeteksi konflik penugasan Kaprodi ganda untuk program studi {$prodi}. Hubungi Administrator.",
                ], 409);
            }
        }

        return $prodis;
    }

    /**
     * [KAPRODI] Antrean pengajuan judul prodi.
     */
    public function index(Request $request)
    {
        $user = $request->user();
        $prodis = $this->resolveActiveKaprodiProdis($user);

        if ($prodis instanceof \Illuminate\Http\JsonResponse) {
            return $prodis;
        }

        $query = PengajuanJudul::with(['mahasiswa.user', 'skripsi', 'dosenPa', 'logs'])
            ->whereHas('mahasiswa', function ($q) use ($prodis) {
                $q->whereIn('prodi', $prodis);
            });

        // Filter berdasarkan status jika diminta, default ke 'Menunggu Persetujuan Kaprodi'
        if ($request->has('status')) {
            $query->where('status', $request->query('status'));
        } else {
            $query->where('status', 'Menunggu Persetujuan Kaprodi');
        }

        $antrean = $query->latest()->get();

        return response()->json([
            'status' => 'success',
            'data'   => $antrean,
        ]);
    }

    /**
     * [KAPRODI] Detail satu pengajuan judul.
     */
    public function show(Request $request, $id)
    {
        $user = $request->user();
        $prodis = $this->resolveActiveKaprodiProdis($user);

        if ($prodis instanceof \Illuminate\Http\JsonResponse) {
            return $prodis;
        }

        $pengajuan = PengajuanJudul::with(['mahasiswa.user', 'skripsi', 'dosenPa', 'logs'])->find($id);

        if (!$pengajuan || !in_array($pengajuan->mahasiswa?->prodi, $prodis, true)) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Pengajuan tidak ditemukan atau Anda tidak memiliki akses ke program studi ini.',
            ], 404);
        }

        return response()->json([
            'status' => 'success',
            'data'   => $pengajuan,
        ]);
    }

    /**
     * [KAPRODI] Keputusan persetujuan, revisi, atau penolakan pengajuan judul.
     */
    public function persetujuan(Request $request, $id)
    {
        $validated = $request->validate([
            'status'           => 'required|in:Disetujui Kaprodi,Revisi Kaprodi,Ditolak Kaprodi',
            'catatan_kaprodi'  => 'required_if:status,Revisi Kaprodi,Ditolak Kaprodi|nullable|string|max:1000',
        ]);

        $user = $request->user();
        $prodis = $this->resolveActiveKaprodiProdis($user);

        if ($prodis instanceof \Illuminate\Http\JsonResponse) {
            return $prodis;
        }

        $pengajuan = PengajuanJudul::with(['mahasiswa', 'skripsi'])->find($id);

        if (!$pengajuan || !in_array($pengajuan->mahasiswa?->prodi, $prodis, true)) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Pengajuan tidak ditemukan atau Anda tidak memiliki akses ke program studi ini.',
            ], 404);
        }

        // Validasi: Apakah pengajuan belum diverifikasi Dosen PA?
        if ($pengajuan->status === 'Menunggu Verifikasi Dosen PA') {
            return response()->json([
                'status'  => 'error',
                'message' => 'Pengajuan belum diverifikasi oleh Dosen PA.',
            ], 422);
        }

        // Validasi status awal: Harus 'Menunggu Persetujuan Kaprodi'
        if ($pengajuan->status !== 'Menunggu Persetujuan Kaprodi') {
            return response()->json([
                'status'  => 'error',
                'message' => 'Pengajuan tidak berada dalam antrean persetujuan Kaprodi. Status saat ini: ' . $pengajuan->status,
            ], 422);
        }

        $catatan = $validated['catatan_kaprodi'] ?? null;

        // Eksekusi atomik dalam transaksi database
        try {
            DB::transaction(function () use ($pengajuan, $user, $validated, $catatan, &$updatedPengajuan) {
                // 1. Pessimistic locking pada pengajuan
                $locked = PengajuanJudul::where('id', $pengajuan->id)->lockForUpdate()->first();

                if (!$locked || $locked->status !== 'Menunggu Persetujuan Kaprodi') {
                    throw new \DomainException("Status pengajuan telah berubah oleh transaksi lain.");
                }

                // 2. Pessimistic locking pada skripsi terkait
                $skripsi = Skripsi::where('id_skripsi', $locked->skripsi_id)->lockForUpdate()->first();

                if (!$skripsi || $skripsi->id_mahasiswa !== $locked->mahasiswa_id) {
                    throw new \DomainException("Data skripsi tidak konsisten dengan pengajuan.");
                }

                $statusSebelum = $locked->status;

                // 3. Update status pengajuan
                $locked->update([
                    'status'        => $validated['status'],
                    'catatan_dosen' => $catatan ?? $locked->catatan_dosen,
                ]);

                // 4. Jika disetujui Kaprodi, perbarui judul resmi skripsi dan status_judul secara atomik
                if ($validated['status'] === 'Disetujui Kaprodi') {
                    $skripsi->update([
                        'judul'        => $locked->judul,
                        'status_judul' => 'disetujui',
                    ]);
                }

                // 5. Catat log audit append-only
                $dosenProfile = $user->dosen;

                PengajuanJudulLog::create([
                    'pengajuan_judul_id' => $locked->id,
                    'user_id'            => $user->id,
                    'aktor_nama'         => $user->name,
                    'aktor_role'         => 'kaprodi',
                    'aktor_nomor_induk'  => $dosenProfile?->NIDN ?? $user->nomor_induk,
                    'tahap'              => 'persetujuan_kaprodi',
                    'tindakan'           => match ($validated['status']) {
                        'Disetujui Kaprodi' => 'setujui_kaprodi',
                        'Revisi Kaprodi'    => 'revisi_kaprodi',
                        'Ditolak Kaprodi'   => 'tolak_kaprodi',
                    },
                    'status_sebelum'     => $statusSebelum,
                    'status_sesudah'     => $validated['status'],
                    'catatan'            => $catatan,
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

            Log::error("Kegagalan internal saat persetujuan pengajuan judul oleh Kaprodi: " . $e->getMessage(), [
                'exception'    => $e,
                'user_id'      => $user->id,
                'pengajuan_id' => $id,
            ]);

            return response()->json([
                'status'  => 'error',
                'message' => 'Terjadi kesalahan pada server saat memproses persetujuan Kaprodi. Silakan coba lagi.',
            ], 500);
        }

        return response()->json([
            'status'  => 'success',
            'message' => 'Status pengajuan judul berhasil diperbarui oleh Kaprodi.',
            'data'    => $updatedPengajuan->fresh(['mahasiswa.user', 'dosenPa', 'skripsi', 'logs']),
        ]);
    }
}
