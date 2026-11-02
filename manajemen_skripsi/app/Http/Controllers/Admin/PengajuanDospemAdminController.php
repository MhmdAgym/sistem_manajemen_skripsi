<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Dosen;
use App\Models\DosenPembimbing;
use App\Models\PengajuanDospem;
use App\Models\PengajuanDospemLog;
use App\Models\PengajuanJudul;
use App\Models\PengajuanJudulLog;
use App\Models\Skripsi;
use App\Services\AcademicNotificationDispatcher;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class PengajuanDospemAdminController extends Controller
{
    /**
     * [ADMIN] Daftar antrean dan riwayat pengajuan Dospem.
     */
    public function index(Request $request): JsonResponse
    {
        $query = PengajuanDospem::with(['mahasiswa.user', 'skripsi', 'dosen', 'logs']);

        // Default tahap ke 'proposal' untuk ruang lingkup Tahap 4B
        $tahap = $request->query('tahap', 'proposal');
        if (in_array($tahap, ['proposal', 'skripsi'], true)) {
            $query->where('tahap', $tahap);
        }

        // Filter status jika disertakan
        if ($request->filled('status')) {
            $status = $request->query('status');
            if (in_array($status, ['menunggu_penetapan', 'ditetapkan', 'ditolak'], true)) {
                $query->where('status', $status);
            }
        }

        // Pencarian berdasarkan nama mahasiswa, NIM, atau judul skripsi
        if ($request->filled('search')) {
            $search = '%' . trim((string) $request->query('search')) . '%';
            $query->where(function ($q) use ($search) {
                $q->whereHas('mahasiswa', function ($m) use ($search) {
                    $m->where('nama_mahasiswa', 'like', $search)
                      ->orWhere('NIM', 'like', $search);
                })->orWhereHas('skripsi', function ($s) use ($search) {
                    $s->where('judul', 'like', $search);
                });
            });
        }

        $pengajuan = $query->latest()->get();

        return response()->json([
            'status' => 'success',
            'data'   => $pengajuan,
        ]);
    }

    /**
     * [ADMIN] Opsi seluruh dosen untuk pemilihan penetapan Dospem Proposal.
     *
     * Catatan Integritas:
     * - Tabel dosen tidak memiliki kolom prodi, sehingga daftar dosen disajikan keseluruhan.
     * - Disertai indikator relasi penugasan teramati pada tabel dosen_pembimbing sebagai informasi
     *   beban dosen tanpa pembatasan kuota otomatis.
     */
    public function dosenOptions(): JsonResponse
    {
        $dosenList = Dosen::with(['dosenPembimbing.skripsi'])
            ->orderBy('nama_dosen', 'asc')
            ->get()
            ->map(function ($d) {
                // Hitung mahasiswa unik yang pernah memiliki relasi pembimbing dengan dosen ini
                $uniqueMahasiswaCount = $d->dosenPembimbing
                    ? $d->dosenPembimbing->pluck('skripsi.id_mahasiswa')->filter()->unique()->count()
                    : 0;

                return [
                    'id_dosen'                  => $d->id_dosen,
                    'nama_dosen'                => $d->nama_dosen,
                    'NIDN'                      => $d->NIDN,
                    'jumlah_penugasan_teramati' => $uniqueMahasiswaCount,
                    'keterangan_indikator'      => 'Jumlah relasi mahasiswa bimbingan teramati pada tabel pembimbing (indikator beban tanpa pembatasan kuota otomatis).',
                ];
            });

        return response()->json([
            'status' => 'success',
            'data'   => $dosenList,
        ]);
    }

    /**
     * [ADMIN] Detail satu pengajuan Dospem beserta log audit lengkap.
     */
    public function show($id): JsonResponse
    {
        $pengajuan = PengajuanDospem::with(['mahasiswa.user', 'skripsi', 'dosen', 'logs'])->find($id);

        if (!$pengajuan) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Data pengajuan Dosen Pembimbing tidak ditemukan.',
            ], 404);
        }

        return response()->json([
            'status' => 'success',
            'data'   => $pengajuan,
        ]);
    }

    /**
     * [ADMIN] Tetapkan tepat satu Dosen Pembimbing Proposal untuk pengajuan yang menunggu penetapan.
     *
     * Operasi atomik:
     * - Lock row pengajuan dan lock row skripsi induk.
     * - Validasi status awal harus 'menunggu_penetapan'.
     * - Validasi persetujuan judul skripsi tetap sah.
     * - Cegah duplikasi penugasan aktif.
     * - Update status pengajuan menjadi 'ditetapkan'.
     * - Insert penugasan baru ke tabel dosen_pembimbing (skema kompatibel).
     * - Tulis log audit append-only.
     */
    public function tetapkan(Request $request, $id): JsonResponse
    {
        $validated = $request->validate([
            'id_dosen'      => 'required|integer|exists:dosen,id_dosen',
            'catatan_admin' => 'nullable|string|max:1000',
        ]);

        $user = $request->user();

        try {
            DB::transaction(function () use ($id, $validated, $user, &$updatedPengajuan) {
                // 1. Lock pengajuan untuk serialisasi concurrency
                $lockedPengajuan = PengajuanDospem::where('id', $id)
                    ->lockForUpdate()
                    ->first();

                if (!$lockedPengajuan) {
                    throw new \DomainException('Data pengajuan Dosen Pembimbing tidak ditemukan.', 404);
                }

                if ($lockedPengajuan->status !== 'menunggu_penetapan') {
                    throw new \DomainException("Pengajuan ini tidak berada dalam status menunggu penetapan. Status saat ini: {$lockedPengajuan->status}.", 409);
                }

                if ($lockedPengajuan->tahap !== 'proposal') {
                    throw new \DomainException('Fitur penetapan ini hanya berlaku untuk tahap proposal pada rilis ini.', 422);
                }

                // 2. Lock baris induk skripsi
                $lockedSkripsi = Skripsi::where('id_skripsi', $lockedPengajuan->skripsi_id)
                    ->lockForUpdate()
                    ->first();

                if (!$lockedSkripsi || (int) $lockedSkripsi->id_mahasiswa !== (int) $lockedPengajuan->mahasiswa_id) {
                    throw new \DomainException('Data skripsi tidak konsisten dengan profil mahasiswa pengaju.', 422);
                }

                // 3. Verifikasi prasyarat akademik (judul harus disetujui Kaprodi)
                if ($lockedSkripsi->status_judul !== 'disetujui') {
                    throw new \DomainException('Judul skripsi belum berstatus disetujui.', 422);
                }

                $pengajuanJudulResmi = PengajuanJudul::where('skripsi_id', $lockedSkripsi->id_skripsi)
                    ->where('mahasiswa_id', $lockedPengajuan->mahasiswa_id)
                    ->where('status', 'Disetujui Kaprodi')
                    ->latest()
                    ->first();

                if (!$pengajuanJudulResmi) {
                    throw new \DomainException('Pengajuan judul resmi yang disetujui Kaprodi tidak ditemukan.', 422);
                }

                // 4. Verifikasi bahwa belum ada dosen pembimbing yang terdaftar untuk skripsi ini
                if (DosenPembimbing::where('id_skripsi', $lockedSkripsi->id_skripsi)->exists()) {
                    throw new \DomainException('Skripsi ini sudah memiliki Dosen Pembimbing yang terdaftar pada sistem.', 409);
                }

                // 5. Ambil data dosen terpilih
                $dosen = Dosen::find($validated['id_dosen']);
                if (!$dosen) {
                    throw new \DomainException('Data dosen yang dipilih tidak ditemukan.', 422);
                }

                $statusSebelum = $lockedPengajuan->status;
                $catatanAdmin = $validated['catatan_admin'] ?? null;

                // 6. Update pengajuan_dospem menjadi ditetapkan
                $lockedPengajuan->update([
                    'status'        => 'ditetapkan',
                    'dosen_id'      => $dosen->id_dosen,
                    'catatan_admin' => $catatanAdmin,
                ]);

                // 7. Simpan record penugasan operasional ke tabel dosen_pembimbing
                DosenPembimbing::create([
                    'id_skripsi' => $lockedSkripsi->id_skripsi,
                    'id_dosen'   => $dosen->id_dosen,
                ]);

                // 8. Tulis log audit penetapan append-only
                $log = PengajuanDospemLog::create([
                    'pengajuan_dospem_id' => $lockedPengajuan->id,
                    'user_id'             => $user->id,
                    'aktor_nama'          => $user->name,
                    'aktor_role'          => 'admin',
                    'aktor_nomor_induk'   => $user->nomor_induk,
                    'tahap'               => $lockedPengajuan->tahap,
                    'tindakan'            => 'tetapkan',
                    'status_sebelum'      => $statusSebelum,
                    'status_sesudah'      => 'ditetapkan',
                    'catatan'             => $catatanAdmin,
                    'snapshot_dosen_id'   => $dosen->id_dosen,
                    'snapshot_dosen_nama' => $dosen->nama_dosen,
                ]);

                app(AcademicNotificationDispatcher::class)
                    ->dispatchEvt10AdminTetapkanDospem($lockedPengajuan, $log, $dosen);

                $updatedPengajuan = $lockedPengajuan;
            });
        } catch (\Throwable $e) {
            if ($e instanceof \DomainException) {
                $code = in_array($e->getCode(), [404, 409, 422], true) ? $e->getCode() : 422;
                return response()->json([
                    'status'  => 'error',
                    'message' => $e->getMessage(),
                ], $code);
            }

            Log::error('Kegagalan internal saat Admin menetapkan Dospem Proposal: ' . $e->getMessage(), [
                'exception'    => $e,
                'user_id'      => $user->id,
                'pengajuan_id' => $id,
            ]);

            return response()->json([
                'status'  => 'error',
                'message' => 'Terjadi kesalahan pada server saat memproses penetapan Dosen Pembimbing. Silakan coba lagi.',
            ], 500);
        }

        return response()->json([
            'status'  => 'success',
            'message' => 'Dosen Pembimbing Proposal berhasil ditetapkan.',
            'data'    => $updatedPengajuan->fresh(['mahasiswa.user', 'skripsi', 'dosen', 'logs']),
        ], 200);
    }

    /**
     * [ADMIN] Tolak pengajuan Dospem Proposal yang masih menunggu penetapan.
     */
    public function status(Request $request, $id): JsonResponse
    {
        $validated = $request->validate([
            'status'        => 'required|in:ditolak',
            'catatan_admin' => 'required|string|max:1000',
        ]);

        $user = $request->user();

        try {
            DB::transaction(function () use ($id, $validated, $user, &$updatedPengajuan) {
                $lockedPengajuan = PengajuanDospem::where('id', $id)
                    ->lockForUpdate()
                    ->first();

                if (!$lockedPengajuan) {
                    throw new \DomainException('Data pengajuan Dosen Pembimbing tidak ditemukan.', 404);
                }

                if ($lockedPengajuan->status !== 'menunggu_penetapan') {
                    throw new \DomainException("Hanya pengajuan berstatus menunggu penetapan yang dapat ditolak. Status saat ini: {$lockedPengajuan->status}.", 409);
                }

                $statusSebelum = $lockedPengajuan->status;
                $catatanAdmin = $validated['catatan_admin'];

                $lockedPengajuan->update([
                    'status'        => 'ditolak',
                    'catatan_admin' => $catatanAdmin,
                ]);

                $log = PengajuanDospemLog::create([
                    'pengajuan_dospem_id' => $lockedPengajuan->id,
                    'user_id'             => $user->id,
                    'aktor_nama'          => $user->name,
                    'aktor_role'          => 'admin',
                    'aktor_nomor_induk'   => $user->nomor_induk,
                    'tahap'               => $lockedPengajuan->tahap,
                    'tindakan'            => 'tolak',
                    'status_sebelum'      => $statusSebelum,
                    'status_sesudah'      => 'ditolak',
                    'catatan'             => $catatanAdmin,
                    'snapshot_dosen_id'   => null,
                    'snapshot_dosen_nama' => null,
                ]);

                app(AcademicNotificationDispatcher::class)
                    ->dispatchEvt11AdminTolakDospem($lockedPengajuan, $log);

                $updatedPengajuan = $lockedPengajuan;
            });
        } catch (\Throwable $e) {
            if ($e instanceof \DomainException) {
                $code = in_array($e->getCode(), [404, 409, 422], true) ? $e->getCode() : 422;
                return response()->json([
                    'status'  => 'error',
                    'message' => $e->getMessage(),
                ], $code);
            }

            Log::error('Kegagalan internal saat Admin menolak pengajuan Dospem Proposal: ' . $e->getMessage(), [
                'exception'    => $e,
                'user_id'      => $user->id,
                'pengajuan_id' => $id,
            ]);

            return response()->json([
                'status'  => 'error',
                'message' => 'Terjadi kesalahan pada server saat memproses penolakan pengajuan. Silakan coba lagi.',
            ], 500);
        }

        return response()->json([
            'status'  => 'success',
            'message' => 'Pengajuan Dosen Pembimbing Proposal berhasil ditolak.',
            'data'    => $updatedPengajuan->fresh(['mahasiswa.user', 'skripsi', 'dosen', 'logs']),
        ], 200);
    }
}
