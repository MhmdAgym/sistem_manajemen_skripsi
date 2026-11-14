<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\VerifySkDokumenAdminRequest;
use App\Models\SkSkripsiDokumen;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class SkSkripsiDokumenAdminController extends Controller
{
    /**
     * [ADMIN] Menampilkan daftar antrean dan riwayat seluruh dokumen SK mahasiswa.
     * Mendukung filter status_dokumen dan pencarian nama/NIM/nomor SK.
     */
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', SkSkripsiDokumen::class);

        $query = SkSkripsiDokumen::with([
            'mahasiswa.user',
            'skripsi',
            'dosen1Usulan',
            'dosen2Usulan',
            'admin',
            'penugasanPembimbing.dosen',
        ]);

        // Filter berdasarkan status dokumen
        if ($request->filled('status')) {
            $status = $request->query('status');
            if (in_array($status, ['MENUNGGU_PEMERIKSAAN', 'PERLU_PERBAIKAN', 'DITOLAK', 'TERVERIFIKASI', 'DIBATALKAN'], true)) {
                $query->where('status_dokumen', $status);
            }
        }

        // Pencarian berdasarkan nama mahasiswa, NIM, atau nomor SK
        if ($request->filled('search')) {
            $search = '%' . trim((string) $request->query('search')) . '%';
            $query->where(function ($q) use ($search) {
                $q->where('nomor_sk', 'like', $search)
                  ->orWhereHas('mahasiswa', function ($m) use ($search) {
                      $m->where('nama_mahasiswa', 'like', $search)
                        ->orWhere('NIM', 'like', $search);
                  });
            });
        }

        $dokumen = $query->orderBy('created_at', 'desc')->get();

        return response()->json([
            'status' => 'success',
            'data'   => $dokumen,
        ]);
    }

    /**
     * [ADMIN] Menampilkan detail lengkap satu dokumen SK untuk pemeriksaan administratif.
     */
    public function show(Request $request, int|string $id): JsonResponse
    {
        $dokumen = SkSkripsiDokumen::with([
            'mahasiswa.user',
            'skripsi',
            'dosen1Usulan',
            'dosen2Usulan',
            'admin',
            'indukDokumen',
            'dokumenRevisi',
            'penugasanPembimbing.dosen',
        ])->findOrFail($id);

        $this->authorize('view', $dokumen);

        return response()->json([
            'status' => 'success',
            'data'   => $dokumen,
        ]);
    }

    /**
     * [ADMIN] Memverifikasi, meminta perbaikan, atau menolak dokumen SK mahasiswa.
     * Menerapkan Policy verify, penguncian baris (row locking), dan pencatatan audit pemeriksa.
     */
    public function verify(VerifySkDokumenAdminRequest $request, int|string $id): JsonResponse
    {
        $user = $request->user();
        $validated = $request->validated();

        $dokumenTerverifikasi = DB::transaction(function () use ($request, $id, $validated, $user) {
            $lockedDokumen = SkSkripsiDokumen::where('id', $id)
                ->lockForUpdate()
                ->firstOrFail();

            // Evaluasi Policy verify secara spesifik
            $this->authorize('verify', $lockedDokumen);

            $lockedDokumen->update([
                'status_dokumen' => $validated['status_dokumen'],
                'catatan_admin'  => $validated['catatan_admin'] ?? null,
                'admin_id'       => $user->id,
                'diperiksa_at'   => now(),
            ]);

            return $lockedDokumen;
        });

        $pesan = match ($validated['status_dokumen']) {
            'TERVERIFIKASI'   => 'Dokumen SK Pembimbing Skripsi berhasil diverifikasi dan disahkan.',
            'PERLU_PERBAIKAN' => 'Dokumen SK ditandai memerlukan perbaikan oleh mahasiswa.',
            'DITOLAK'         => 'Dokumen SK resmi ditolak.',
        };

        return response()->json([
            'status'  => 'success',
            'message' => $pesan,
            'data'    => $dokumenTerverifikasi->fresh(['mahasiswa.user', 'skripsi', 'admin', 'dosen1Usulan', 'dosen2Usulan']),
        ]);
    }

    /**
     * [ADMIN] Mengunduh berkas fisik scan PDF dari storage privat.
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

