<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StorePenugasanPembimbingAdminRequest;
use App\Http\Requests\Admin\UpdatePenugasanPembimbingAdminRequest;
use App\Models\PenugasanPembimbing;
use App\Models\PenugasanPembimbingLog;
use App\Models\Skripsi;
use App\Models\SkSkripsiDokumen;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PenugasanPembimbingAdminController extends Controller
{
    /**
     * [ADMIN] Menampilkan daftar seluruh penugasan pembimbing (proposal dan skripsi).
     * Mendukung filter tahap, status_penugasan, dosen_id, dan skripsi_id.
     */
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', PenugasanPembimbing::class);

        $query = PenugasanPembimbing::with([
            'skripsi.mahasiswa.user',
            'dosen.user',
            'skDokumen',
            'pengajuanDospem',
            'logs',
        ]);

        if ($request->filled('tahap')) {
            $tahap = $request->query('tahap');
            if (in_array($tahap, ['proposal', 'skripsi'], true)) {
                $query->where('tahap', $tahap);
            }
        }

        if ($request->filled('status')) {
            $status = $request->query('status');
            if (in_array($status, ['AKTIF', 'DIGANTI', 'DIBATALKAN'], true)) {
                $query->where('status_penugasan', $status);
            }
        }

        if ($request->filled('dosen_id')) {
            $query->where('dosen_id', $request->query('dosen_id'));
        }

        if ($request->filled('skripsi_id')) {
            $query->where('skripsi_id', $request->query('skripsi_id'));
        }

        $penugasan = $query->orderBy('created_at', 'desc')->get();

        return response()->json([
            'status' => 'success',
            'data'   => $penugasan,
        ]);
    }

    /**
     * [ADMIN] Menampilkan detail lengkap satu penugasan pembimbing dan riwayat auditnya.
     */
    public function show(Request $request, int|string $id): JsonResponse
    {
        $penugasan = PenugasanPembimbing::with([
            'skripsi.mahasiswa.user',
            'dosen.user',
            'skDokumen',
            'pengajuanDospem',
            'digantikanOleh.dosen',
            'menggantikan.dosen',
            'logs.user',
        ])->findOrFail($id);

        $this->authorize('view', $penugasan);

        return response()->json([
            'status' => 'success',
            'data'   => $penugasan,
        ]);
    }

    /**
     * [ADMIN] Menerbitkan penugasan Dosen Pembimbing Skripsi berdasarkan SK resmi yang telah diverifikasi sah.
     *
     * Invarian Integritas:
     * - SK rujukan wajib berstatus 'TERVERIFIKASI'.
     * - Dosen yang ditugaskan wajib sesuai dengan urutan slot pembimbing pada lembar SK yang disahkan (dosen_1_usulan_id untuk Pembimbing 1, dosen_2_usulan_id untuk Pembimbing 2).
     * - Maksimal tepat 1 dosen aktif per slot kombinasi tahap dan nomor pembimbing (MySQL active_slot).
     * - Dosen yang sama tidak boleh merangkap sebagai Pembimbing 1 sekaligus Pembimbing 2 pada skripsi yang sama.
     * - Operasi atomik: row locking pada dokumen SK dan skripsi induk, serta pencatatan audit log mutasi.
     */
    public function store(StorePenugasanPembimbingAdminRequest $request): JsonResponse
    {
        $this->authorize('create', PenugasanPembimbing::class);

        $validated = $request->validated();
        $user = $request->user();

        $penugasanBaru = DB::transaction(function () use ($validated, $user) {
            // 1. Lock baris dokumen SK rujukan
            $lockedDokumen = SkSkripsiDokumen::where('id', $validated['sk_dokumen_id'])
                ->lockForUpdate()
                ->firstOrFail();

            if ($lockedDokumen->status_dokumen !== 'TERVERIFIKASI') {
                throw new \DomainException('Penugasan pembimbing skripsi hanya dapat diterbitkan berdasarkan dokumen SK yang telah berstatus TERVERIFIKASI.', 422);
            }

            // 2. Lock baris skripsi induk
            $lockedSkripsi = Skripsi::where('id_skripsi', $lockedDokumen->skripsi_id)
                ->lockForUpdate()
                ->firstOrFail();

            $pembimbingKe = (int) $validated['pembimbing_ke'];
            $dosenId = (int) $validated['dosen_id'];

            // 3. Validasi ordinal matching kesesuaian dosen terhadap nomor slot SK sah
            if ($pembimbingKe === 1) {
                if ($dosenId !== (int) $lockedDokumen->dosen_1_usulan_id) {
                    throw new \DomainException('Dosen yang dipilih tidak sesuai dengan Dosen Pembimbing 1 yang tercantum pada dokumen SK terverifikasi.', 422);
                }
            } elseif ($pembimbingKe === 2) {
                if (!$lockedDokumen->dosen_2_usulan_id) {
                    throw new \DomainException('Dokumen SK ini tidak menetapkan Dosen Pembimbing 2.', 422);
                }
                if ($dosenId !== (int) $lockedDokumen->dosen_2_usulan_id) {
                    throw new \DomainException('Dosen yang dipilih tidak sesuai dengan Dosen Pembimbing 2 yang tercantum pada dokumen SK terverifikasi.', 422);
                }
            } else {
                throw new \DomainException('Nomor urut pembimbing hanya diperbolehkan 1 atau 2.', 422);
            }

            // 4. Cegah duplikasi penugasan aktif pada posisi pembimbing yang sama
            $slotTerisi = PenugasanPembimbing::where('skripsi_id', $lockedSkripsi->id_skripsi)
                ->where('tahap', 'skripsi')
                ->where('pembimbing_ke', $pembimbingKe)
                ->where('status_penugasan', 'AKTIF')
                ->exists();

            if ($slotTerisi) {
                throw new \DomainException("Skripsi ini sudah memiliki Pembimbing {$pembimbingKe} yang berstatus aktif. Lakukan penonaktifan terlebih dahulu jika ingin mengganti.", 409);
            }

            // 5. Cegah dosen yang sama merangkap Pembimbing 1 dan 2 sekaligus pada skripsi ini
            $dosenSudahAktif = PenugasanPembimbing::where('skripsi_id', $lockedSkripsi->id_skripsi)
                ->where('tahap', 'skripsi')
                ->where('dosen_id', $dosenId)
                ->where('status_penugasan', 'AKTIF')
                ->exists();

            if ($dosenSudahAktif) {
                throw new \DomainException('Dosen ini sudah ditugaskan sebagai pembimbing aktif pada skripsi ini.', 422);
            }

            // 6. Buat record penugasan pembimbing resmi
            $penugasan = PenugasanPembimbing::create([
                'skripsi_id'          => $lockedSkripsi->id_skripsi,
                'tahap'               => 'skripsi',
                'pembimbing_ke'       => $pembimbingKe,
                'dosen_id'            => $dosenId,
                'sk_dokumen_id'       => $lockedDokumen->id,
                'pengajuan_dospem_id' => null,
                'status_penugasan'    => 'AKTIF',
                'tanggal_mulai'       => $validated['tanggal_mulai'],
                'tanggal_selesai'     => $validated['tanggal_selesai'] ?? null,
                'digantikan_oleh_id'  => null,
            ]);

            // 7. Catat riwayat audit mutasi append-only
            PenugasanPembimbingLog::create([
                'penugasan_pembimbing_id' => $penugasan->id,
                'user_id'                 => $user->id,
                'aktor_nama'              => $user->name,
                'aktor_role'              => 'admin',
                'tindakan'                => 'PENETAPAN_AWAL',
                'status_sebelum'          => null,
                'status_sesudah'          => 'AKTIF',
                'snapshot_dosen_lama_id'  => null,
                'snapshot_dosen_baru_id'  => $dosenId,
                'catatan'                 => "Penetapan Pembimbing {$pembimbingKe} resmi berdasarkan SK nomor: {$lockedDokumen->nomor_sk}",
                'created_at'              => now(),
            ]);

            return $penugasan;
        });

        return response()->json([
            'status'  => 'success',
            'message' => 'Penugasan Dosen Pembimbing Skripsi berhasil diterbitkan dan dicatat.',
            'data'    => $penugasanBaru->load(['skripsi.mahasiswa.user', 'dosen.user', 'skDokumen', 'logs']),
        ], 201);
    }

    /**
     * [ADMIN] Memperbarui status penugasan pembimbing (misal penonaktifan atau pembatalan).
     */
    public function update(UpdatePenugasanPembimbingAdminRequest $request, int|string $id): JsonResponse
    {
        $validated = $request->validated();
        $user = $request->user();

        $penugasanUpdated = DB::transaction(function () use ($id, $validated, $user) {
            $lockedPenugasan = PenugasanPembimbing::where('id', $id)
                ->lockForUpdate()
                ->firstOrFail();

            $this->authorize('update', $lockedPenugasan);

            $statusSebelum = $lockedPenugasan->status_penugasan;
            $statusSesudah = $validated['status_penugasan'];

            if ($statusSebelum === $statusSesudah) {
                return $lockedPenugasan;
            }

            $tanggalSelesai = $validated['tanggal_selesai'] ?? null;
            if (!$tanggalSelesai && in_array($statusSesudah, ['DIGANTI', 'DIBATALKAN'], true)) {
                $tanggalSelesai = now()->toDateString();
            }

            $lockedPenugasan->update([
                'status_penugasan' => $statusSesudah,
                'tanggal_selesai'  => $tanggalSelesai,
            ]);

            $tindakan = match ($statusSesudah) {
                'DIBATALKAN' => 'PEMBATALAN_PENUGASAN',
                'DIGANTI'    => 'PERGANTIAN_PEMBIMBING',
                'AKTIF'      => 'PENGAKTIFAN_KEMBALI',
            };

            PenugasanPembimbingLog::create([
                'penugasan_pembimbing_id' => $lockedPenugasan->id,
                'user_id'                 => $user->id,
                'aktor_nama'              => $user->name,
                'aktor_role'              => 'admin',
                'tindakan'                => $tindakan,
                'status_sebelum'          => $statusSebelum,
                'status_sesudah'          => $statusSesudah,
                'snapshot_dosen_lama_id'  => $lockedPenugasan->dosen_id,
                'snapshot_dosen_baru_id'  => $lockedPenugasan->dosen_id,
                'catatan'                 => $validated['alasan'] ?? "Status penugasan diubah menjadi {$statusSesudah} oleh Administrator.",
                'created_at'              => now(),
            ]);

            return $lockedPenugasan;
        });

        return response()->json([
            'status'  => 'success',
            'message' => "Status penugasan pembimbing berhasil diperbarui menjadi {$penugasanUpdated->status_penugasan}.",
            'data'    => $penugasanUpdated->fresh(['skripsi.mahasiswa.user', 'dosen.user', 'skDokumen', 'logs']),
        ]);
    }
}

