<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StorePenugasanKaprodiRequest;
use App\Http\Requests\Admin\UpdatePenugasanKaprodiRequest;
use App\Models\Dosen;
use App\Models\PenugasanKaprodi;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class PenugasanKaprodiController extends Controller
{
    /**
     * Hitung status operasional berbasis tanggal dan flag is_aktif.
     */
    private function hitungStatusOperasional(PenugasanKaprodi $penugasan, string $today): string
    {
        if (!$penugasan->is_aktif) {
            return 'Nonaktif';
        }

        $mulai = $penugasan->tanggal_mulai?->toDateString();
        $selesai = $penugasan->tanggal_selesai?->toDateString();

        if ($mulai && $mulai > $today) {
            return 'Belum Berlaku';
        }

        if ($selesai && $selesai < $today) {
            return 'Kedaluwarsa';
        }

        return 'Aktif';
    }

    /**
     * [ADMIN] Daftar seluruh riwayat dan status penugasan Kaprodi.
     */
    public function index(Request $request): JsonResponse
    {
        $today = now()->toDateString();
        $query = PenugasanKaprodi::with(['dosen.user']);

        if ($request->filled('prodi')) {
            $query->where('prodi', $request->query('prodi'));
        }

        if ($request->has('is_aktif')) {
            $isAktif = filter_var($request->query('is_aktif'), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
            if ($isAktif !== null) {
                $query->where('is_aktif', $isAktif);
            }
        }

        if ($request->filled('search')) {
            $search = '%' . $request->query('search') . '%';
            $query->whereHas('dosen', function ($q) use ($search) {
                $q->where('nama_dosen', 'like', $search)
                  ->orWhere('NIDN', 'like', $search);
            });
        }

        $penugasanList = $query->orderBy('is_aktif', 'desc')
            ->orderBy('tanggal_mulai', 'desc')
            ->get()
            ->map(function ($item) use ($today) {
                $item->status_operasional = $this->hitungStatusOperasional($item, $today);
                return $item;
            });

        return response()->json([
            'status' => 'success',
            'data'   => $penugasanList,
        ]);
    }

    /**
     * [ADMIN] Opsi dosen untuk dropdown penugasan (menggunakan id_dosen sebagai referensi).
     */
    public function dosenOptions(): JsonResponse
    {
        $dosen = Dosen::select('id_dosen', 'nama_dosen', 'NIDN')
            ->orderBy('nama_dosen', 'asc')
            ->get();

        return response()->json([
            'status' => 'success',
            'data'   => $dosen,
        ]);
    }

    /**
     * Jalankan operasi penugasan Kaprodi dengan proteksi MySQL Named Lock berbasis prodi.
     * Mengembalikan response HTTP 503 jika gagal memperoleh kunci (timeout / antrean konkurensi / error).
     */
    private function withNamedLock(string $prodi, \Closure $callback): JsonResponse
    {
        $lockKey = 'penugasan_kaprodi_lock_' . md5($prodi);
        $lockAcquired = false;

        // Coba peroleh lock sesi MySQL dengan batas waktu 3 detik
        try {
            $lockRow = DB::selectOne("SELECT GET_LOCK(?, 3) as lock_status", [$lockKey]);
            $lockStatus = $lockRow ? (int) $lockRow->lock_status : 0;

            if ($lockStatus === 1) {
                $lockAcquired = true;
            } else {
                return response()->json([
                    'status'  => 'error',
                    'message' => "Sistem sedang memproses penugasan untuk program studi {$prodi}. Silakan coba beberapa saat lagi.",
                ], 503);
            }
        } catch (\Throwable $e) {
            // Fail-closed untuk seluruh driver database: jangan pernah menjalankan callback tanpa named lock
            Log::error("Gagal menjalankan GET_LOCK untuk penugasan Kaprodi [prodi: {$prodi}]: " . $e->getMessage());
            return response()->json([
                'status'  => 'error',
                'message' => "Terjadi kendala saat mengamankan antrean penugasan Kaprodi. Silakan coba beberapa saat lagi.",
            ], 503);
        }

        try {
            return $callback();
        } finally {
            if ($lockAcquired) {
                try {
                    $releaseRow = DB::selectOne("SELECT RELEASE_LOCK(?) as rel_status", [$lockKey]);
                    $releaseStatus = ($releaseRow && property_exists($releaseRow, 'rel_status') && $releaseRow->rel_status !== null)
                        ? (int) $releaseRow->rel_status
                        : null;

                    if ($releaseStatus === 1) {
                        // Pelepasan lock terkonfirmasi berhasil
                    } elseif ($releaseStatus === 0) {
                        Log::warning("Gagal melepaskan named lock [{$lockKey}]: Kunci tidak dimiliki oleh sesi atau koneksi saat ini.");
                    } else {
                        Log::warning("Gagal melepaskan named lock [{$lockKey}]: Kunci tidak ditemukan atau respons database tidak valid (status: " . ($releaseStatus ?? 'NULL') . ").");
                    }
                } catch (\Throwable $e) {
                    // Tangkap exception agar tidak menutupi exception asli dari callback/transaksi
                    Log::warning("Exception saat melepaskan named lock [{$lockKey}]: " . $e->getMessage());
                }
            }
        }
    }

    /**
     * [ADMIN] Tambah penugasan Kaprodi baru dengan perlindungan konkurensi dan pencegahan konflik.
     */
    public function store(StorePenugasanKaprodiRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $today = now()->toDateString();
        $prodi = $validated['prodi'];

        $isTargetAktif = ($validated['is_aktif'] ?? true)
            && $validated['tanggal_mulai'] <= $today
            && (empty($validated['tanggal_selesai']) || $validated['tanggal_selesai'] >= $today);

        return $this->withNamedLock($prodi, function () use ($validated, $prodi, $isTargetAktif, $today) {
            $result = DB::transaction(function () use ($validated, $prodi, $isTargetAktif, $today) {
                // Periksa penugasan aktif eksisting dengan lockForUpdate
                $existingActive = PenugasanKaprodi::where('prodi', $prodi)
                    ->where('is_aktif', true)
                    ->where('tanggal_mulai', '<=', $today)
                    ->where(function ($q) use ($today) {
                        $q->whereNull('tanggal_selesai')
                          ->orWhere('tanggal_selesai', '>=', $today);
                    })
                    ->lockForUpdate()
                    ->get();

                if ($isTargetAktif && $existingActive->isNotEmpty()) {
                    if (!empty($validated['gantikan_penugasan_aktif'])) {
                        foreach ($existingActive as $old) {
                            $safeEndDate = $today;
                            if ($old->tanggal_mulai && $old->tanggal_mulai->toDateString() > $today) {
                                $safeEndDate = $old->tanggal_mulai->toDateString();
                            }
                            $old->update([
                                'is_aktif'        => false,
                                'tanggal_selesai' => $safeEndDate,
                            ]);
                        }
                    } else {
                        return [
                            'status_code' => 409,
                            'payload'     => [
                                'status'  => 'error',
                                'message' => "Program studi {$prodi} telah memiliki Kaprodi aktif. Nonaktifkan Kaprodi sebelumnya terlebih dahulu atau beri konfirmasi penggantian.",
                            ],
                        ];
                    }
                }

                $penugasan = PenugasanKaprodi::create([
                    'dosen_id'        => $validated['dosen_id'],
                    'prodi'           => $validated['prodi'],
                    'tanggal_mulai'   => $validated['tanggal_mulai'],
                    'tanggal_selesai' => $validated['tanggal_selesai'] ?? null,
                    'is_aktif'        => $validated['is_aktif'] ?? true,
                ]);

                return [
                    'status_code' => 201,
                    'payload'     => [
                        'status'  => 'success',
                        'message' => 'Penugasan Kaprodi berhasil ditambahkan.',
                        'data'    => $penugasan->load(['dosen.user']),
                    ],
                ];
            });

            return response()->json($result['payload'], $result['status_code']);
        });
    }

    /**
     * [ADMIN] Detail satu penugasan Kaprodi.
     */
    public function show($id): JsonResponse
    {
        $penugasan = PenugasanKaprodi::with(['dosen.user'])->find($id);

        if (!$penugasan) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Penugasan Kaprodi tidak ditemukan.',
            ], 404);
        }

        $today = now()->toDateString();
        $penugasan->status_operasional = $this->hitungStatusOperasional($penugasan, $today);

        return response()->json([
            'status' => 'success',
            'data'   => $penugasan,
        ]);
    }

    /**
     * [ADMIN] Perbarui data penugasan Kaprodi.
     */
    public function update(UpdatePenugasanKaprodiRequest $request, $id): JsonResponse
    {
        $penugasan = PenugasanKaprodi::find($id);

        if (!$penugasan) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Penugasan Kaprodi tidak ditemukan.',
            ], 404);
        }

        $validated = $request->validated();
        $today = now()->toDateString();
        $targetProdi = $validated['prodi'] ?? $penugasan->prodi;
        $targetMulai = $validated['tanggal_mulai'] ?? $penugasan->tanggal_mulai?->toDateString();
        $targetSelesai = array_key_exists('tanggal_selesai', $validated)
            ? $validated['tanggal_selesai']
            : $penugasan->tanggal_selesai?->toDateString();
        $targetIsAktif = array_key_exists('is_aktif', $validated)
            ? (bool) $validated['is_aktif']
            : (bool) $penugasan->is_aktif;

        $isTargetAktif = $targetIsAktif
            && $targetMulai <= $today
            && (empty($targetSelesai) || $targetSelesai >= $today);

        return $this->withNamedLock($targetProdi, function () use ($penugasan, $validated, $targetProdi, $isTargetAktif, $today) {
            $result = DB::transaction(function () use ($penugasan, $validated, $targetProdi, $isTargetAktif, $today) {
                // Periksa konflik dengan rekaman aktif LAIN di prodi yang sama
                $existingActiveOther = PenugasanKaprodi::where('prodi', $targetProdi)
                    ->where('id', '!=', $penugasan->id)
                    ->where('is_aktif', true)
                    ->where('tanggal_mulai', '<=', $today)
                    ->where(function ($q) use ($today) {
                        $q->whereNull('tanggal_selesai')
                          ->orWhere('tanggal_selesai', '>=', $today);
                    })
                    ->lockForUpdate()
                    ->get();

                if ($isTargetAktif && $existingActiveOther->isNotEmpty()) {
                    if (!empty($validated['gantikan_penugasan_aktif'])) {
                        foreach ($existingActiveOther as $old) {
                            $safeEndDate = $today;
                            if ($old->tanggal_mulai && $old->tanggal_mulai->toDateString() > $today) {
                                $safeEndDate = $old->tanggal_mulai->toDateString();
                            }
                            $old->update([
                                'is_aktif'        => false,
                                'tanggal_selesai' => $safeEndDate,
                            ]);
                        }
                    } else {
                        return [
                            'status_code' => 409,
                            'payload'     => [
                                'status'  => 'error',
                                'message' => "Program studi {$targetProdi} telah memiliki Kaprodi aktif lain. Nonaktifkan Kaprodi tersebut atau beri konfirmasi penggantian.",
                            ],
                        ];
                    }
                }

                $penugasan->update($validated);

                return [
                    'status_code' => 200,
                    'payload'     => [
                        'status'  => 'success',
                        'message' => 'Penugasan Kaprodi berhasil diperbarui.',
                        'data'    => $penugasan->fresh(['dosen.user']),
                    ],
                ];
            });

            return response()->json($result['payload'], $result['status_code']);
        });
    }

    /**
     * [ADMIN] Nonaktifkan penugasan Kaprodi secara aman (Idempoten & mempertahankan riwayat).
     */
    public function nonaktifkan($id): JsonResponse
    {
        $penugasan = PenugasanKaprodi::find($id);

        if (!$penugasan) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Penugasan Kaprodi tidak ditemukan.',
            ], 404);
        }

        $today = now()->toDateString();

        return $this->withNamedLock($penugasan->prodi, function () use ($penugasan, $today) {
            DB::transaction(function () use ($penugasan, $today) {
                $safeEndDate = $penugasan->tanggal_selesai?->toDateString();
                if (empty($safeEndDate) || $safeEndDate > $today) {
                    $safeEndDate = $today;
                }

                $penugasan->update([
                    'is_aktif'        => false,
                    'tanggal_selesai' => $safeEndDate,
                ]);
            });

            return response()->json([
                'status'  => 'success',
                'message' => 'Penugasan Kaprodi berhasil dinonaktifkan.',
                'data'    => $penugasan->fresh(['dosen.user']),
            ]);
        });
    }
}
