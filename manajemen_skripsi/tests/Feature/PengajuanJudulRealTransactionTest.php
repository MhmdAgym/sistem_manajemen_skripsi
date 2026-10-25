<?php

namespace Tests\Feature;

use App\Models\Dosen;
use App\Models\DosenPA;
use App\Models\Mahasiswa;
use App\Models\PengajuanJudul;
use App\Models\PengajuanJudulLog;
use App\Models\PenugasanKaprodi;
use App\Models\Skripsi;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Suite pengujian real commit & real rollback tanpa pembungkus DatabaseTransactions.
 *
 * Tujuan:
 * 1. Menguji commit fisik ke engine MySQL dan memastikan kode pasca-commit benar-benar berjalan.
 * 2. Menguji rollback fisik ke engine MySQL saat terjadi kegagalan audit log.
 * 3. Menguji penanganan kegagalan filesystem (throw exception maupun return false).
 * 4. Menguji sanitasi pesan respons error 500 untuk mencegah kebocoran informasi database.
 * 5. Mengimplementasikan self-cleaning fixtures di tearDown() tanpa menyentuh data lain.
 */
class PengajuanJudulRealTransactionTest extends TestCase
{
    // Catatan: TIDAK menggunakan DatabaseTransactions!
    // Setiap transaksi DB::transaction() di controller adalah root transaction murni.

    protected array $createdUserIds = [];
    protected array $createdMahasiswaIds = [];
    protected array $createdDosenIds = [];
    protected array $createdSkripsiIds = [];
    protected array $createdPengajuanIds = [];
    protected array $createdDosenPaIds = [];
    protected array $createdPenugasanKaprodiIds = [];
    protected array $createdFilePaths = [];
    protected array $cleanupAuditReport = [];

    protected function setUp(): void
    {
        parent::setUp();

        // Verifikasi ketat target database adalah manajemen_skripsi_test
        $activeDb = DB::connection()->getDatabaseName();
        $this->assertEquals('manajemen_skripsi_test', $activeDb, 'CRITICAL: Suite real transaction HANYA boleh berjalan pada manajemen_skripsi_test.');

        // Isolasi storage disk public pada disk virtual in-memory untuk seluruh suite pengujian
        Storage::fake('public');
    }

    protected function tearDown(): void
    {
        $mainTestStatus = 'UNKNOWN';
        $mainTestStatusDetail = 'Uninitialized';
        try {
            if (method_exists($this, 'status')) {
                $statusObj = $this->status();
                if (is_object($statusObj)) {
                    if (method_exists($statusObj, 'asString')) {
                        $mainTestStatusDetail = $statusObj->asString();
                    }
                    if (method_exists($statusObj, 'isFailure') && method_exists($statusObj, 'isError') && ($statusObj->isFailure() || $statusObj->isError())) {
                        $mainTestStatus = 'FAILED';
                    } elseif (method_exists($statusObj, 'isSuccess') && $statusObj->isSuccess()) {
                        $mainTestStatus = 'PASSED';
                    } else {
                        $mainTestStatus = 'UNKNOWN';
                    }
                } else {
                    $mainTestStatus = 'UNKNOWN';
                    $mainTestStatusDetail = 'status() did not return an object';
                }
            } else {
                $mainTestStatus = 'UNKNOWN';
                $mainTestStatusDetail = 'status() method does not exist';
            }
        } catch (\Throwable $statusEx) {
            $mainTestStatus = 'UNKNOWN';
            $mainTestStatusDetail = 'Exception while inspecting status: ' . $statusEx->getMessage();
        }

        $this->cleanupAuditReport['main_test_status'] = $mainTestStatus;
        $this->cleanupAuditReport['main_test_status_detail'] = $mainTestStatusDetail;

        $cleanupAnomalies = [];

        // Eksekusi pembersihan fixture berantai 2 fase
        try {
            $this->executeTwoPhaseChainedCleanup($cleanupAnomalies);
        } catch (\Throwable $clEx) {
            $cleanupAnomalies[] = 'Exception tak terduga pada executeTwoPhaseChainedCleanup: ' . $clEx->getMessage();
        }

        // Pelaporan pra-teardown terlindungi
        try {
            $this->processReportingPreTeardown($cleanupAnomalies);
        } catch (\Throwable $preRepEx) {
            $cleanupAnomalies[] = 'Kegagalan pelaporan pra-teardown: ' . $preRepEx->getMessage();
        }

        // Eksekusi parent::tearDown() dengan penanganan exception dan proteksi reporter darurat
        $parentTeardownException = null;
        try {
            parent::tearDown();
        } catch (\Throwable $ptdEx) {
            $parentTeardownException = $ptdEx;
            $cleanupAnomalies[] = 'Parent tearDown melempar exception: ' . $ptdEx->getMessage();

            // Proteksi reporter darurat: dibungkus try/catch mandiri agar tidak menimpa exception parent
            try {
                $this->safeEmergencyLogPostTeardown('Parent tearDown failure: ' . $ptdEx->getMessage());
            } catch (\Throwable $loggerEx) {
                // Catat hanya ke properti memori, DILARANG melempar exception keluar
                $this->cleanupAuditReport['post_teardown_logger_failure'] = $loggerEx->getMessage();
            }
        }

        // -------------------------------------------------------------------
        // PRIORITAS EXCEPTION PASCA-TEARDOWN
        // -------------------------------------------------------------------

        // Prioritas 4: Jika parent::tearDown() melempar exception, prioritaskan untuk dilempar
        if ($parentTeardownException !== null) {
            throw $parentTeardownException;
        }

        // Prioritas 1 & 2: Evaluasi anomali cleanup terhadap status tes utama
        if (!empty($cleanupAnomalies)) {
            // Jika tes utama terbukti FAILED: JANGAN melempar exception baru agar pesan error tes utama tetap murni
            if ($mainTestStatus === 'FAILED') {
                return;
            }

            // Jika tes utama PASSED: Melempar RuntimeException adalah wajib agar runner menandai residu cleanup
            if ($mainTestStatus === 'PASSED') {
                throw new \RuntimeException(
                    "Pembersihan fixture gagal dengan anomali (tes utama PASSED):\n"
                    . $this->formatAnomaliesSummary($cleanupAnomalies)
                );
            }

            // Jika tes utama UNKNOWN: Kebijakan konservatif, lempar RuntimeException dengan label UNKNOWN eksplisit
            throw new \RuntimeException(
                "Pembersihan fixture gagal dengan anomali (status tes utama UNKNOWN / tidak dapat dipastikan):\n"
                . $this->formatAnomaliesSummary($cleanupAnomalies)
            );
        }
    }

    /**
     * Menjalankan pembersihan fixture dua fase:
     * Fase 1: Discovery total target ID (eksplisit + relasional) tanpa mutasi.
     * Fase 2: Chained circuit breaker cleanup (child-to-parent) dengan verifikasi residu.
     */
    private function executeTwoPhaseChainedCleanup(array &$cleanupAnomalies): void
    {
        // -------------------------------------------------------------------
        // FASE 1: DISCOVERY TOTAL (READ-ONLY)
        // -------------------------------------------------------------------

        // 1. Pengajuan Judul Discovery
        $targetPengajuanIds = $this->createdPengajuanIds;
        $discoveryPengajuanOk = true;
        if (!empty($this->createdMahasiswaIds)) {
            try {
                $relationalPengajuanIds = DB::table('pengajuan_judul')
                    ->whereIn('mahasiswa_id', $this->createdMahasiswaIds)
                    ->pluck('id')
                    ->all();
                $targetPengajuanIds = array_values(array_unique(array_merge($targetPengajuanIds, $relationalPengajuanIds)));
                $this->cleanupAuditReport['pengajuan_judul_discovery'] = [
                    'status' => 'DISCOVERY_SUCCEEDED',
                    'count'  => count($targetPengajuanIds),
                ];
            } catch (\Throwable $e) {
                $discoveryPengajuanOk = false;
                $cleanupAnomalies[] = 'Discovery pengajuan_judul via mahasiswa_id gagal: ' . $e->getMessage();
                $this->cleanupAuditReport['pengajuan_judul_discovery'] = [
                    'status' => 'DISCOVERY_FAILED',
                    'error'  => $e->getMessage(),
                ];
            }
        } else {
            $this->cleanupAuditReport['pengajuan_judul_discovery'] = [
                'status' => empty($targetPengajuanIds) ? 'DISCOVERY_NOT_APPLICABLE' : 'DISCOVERY_SUCCEEDED_EXPLICIT_ONLY',
                'count'  => count($targetPengajuanIds),
            ];
        }

        // 2. Pengajuan Judul Logs Discovery
        $targetLogIds = [];
        $discoveryLogsOk = true;
        if (!empty($targetPengajuanIds)) {
            try {
                $targetLogIds = DB::table('pengajuan_judul_logs')
                    ->whereIn('pengajuan_judul_id', $targetPengajuanIds)
                    ->pluck('id')
                    ->all();
                $this->cleanupAuditReport['pengajuan_judul_logs_discovery'] = [
                    'status' => 'DISCOVERY_SUCCEEDED',
                    'count'  => count($targetLogIds),
                ];
            } catch (\Throwable $e) {
                $discoveryLogsOk = false;
                $cleanupAnomalies[] = 'Discovery pengajuan_judul_logs via pengajuan_judul_id gagal: ' . $e->getMessage();
                $this->cleanupAuditReport['pengajuan_judul_logs_discovery'] = [
                    'status' => 'DISCOVERY_FAILED',
                    'error'  => $e->getMessage(),
                ];
            }
        } else {
            $this->cleanupAuditReport['pengajuan_judul_logs_discovery'] = [
                'status' => 'DISCOVERY_SUCCEEDED_ZERO_TARGETS',
                'count'  => 0,
            ];
        }

        // 3. Skripsi Discovery
        $targetSkripsiIds = $this->createdSkripsiIds;
        $discoverySkripsiOk = true;
        if (!empty($this->createdMahasiswaIds)) {
            try {
                $relationalSkripsiIds = DB::table('skripsi')
                    ->whereIn('id_mahasiswa', $this->createdMahasiswaIds)
                    ->pluck('id_skripsi')
                    ->all();
                $targetSkripsiIds = array_values(array_unique(array_merge($targetSkripsiIds, $relationalSkripsiIds)));
                $this->cleanupAuditReport['skripsi_discovery'] = [
                    'status' => 'DISCOVERY_SUCCEEDED',
                    'count'  => count($targetSkripsiIds),
                ];
            } catch (\Throwable $e) {
                $discoverySkripsiOk = false;
                $cleanupAnomalies[] = 'Discovery skripsi via id_mahasiswa gagal: ' . $e->getMessage();
                $this->cleanupAuditReport['skripsi_discovery'] = [
                    'status' => 'DISCOVERY_FAILED',
                    'error'  => $e->getMessage(),
                ];
            }
        } else {
            $this->cleanupAuditReport['skripsi_discovery'] = [
                'status' => empty($targetSkripsiIds) ? 'DISCOVERY_NOT_APPLICABLE' : 'DISCOVERY_SUCCEEDED_EXPLICIT_ONLY',
                'count'  => count($targetSkripsiIds),
            ];
        }

        // 4. Dosen PA Discovery
        $targetDosenPaIds = $this->createdDosenPaIds;
        $discoveryDosenPaOk = true;
        if (!empty($targetSkripsiIds) || !empty($this->createdDosenIds)) {
            try {
                $query = DB::table('dosen_pa');
                if (!empty($targetSkripsiIds) && !empty($this->createdDosenIds)) {
                    $query->where(function ($q) use ($targetSkripsiIds) {
                        $q->whereIn('id_skripsi', $targetSkripsiIds)
                          ->orWhereIn('id_dosen', $this->createdDosenIds);
                    });
                } elseif (!empty($targetSkripsiIds)) {
                    $query->whereIn('id_skripsi', $targetSkripsiIds);
                } else {
                    $query->whereIn('id_dosen', $this->createdDosenIds);
                }
                $relationalDosenPaIds = $query->pluck('id_dosen_pa')->all();
                $targetDosenPaIds = array_values(array_unique(array_merge($targetDosenPaIds, $relationalDosenPaIds)));
                $this->cleanupAuditReport['dosen_pa_discovery'] = [
                    'status' => 'DISCOVERY_SUCCEEDED',
                    'count'  => count($targetDosenPaIds),
                ];
            } catch (\Throwable $e) {
                $discoveryDosenPaOk = false;
                $cleanupAnomalies[] = 'Discovery dosen_pa via skripsi/dosen gagal: ' . $e->getMessage();
                $this->cleanupAuditReport['dosen_pa_discovery'] = [
                    'status' => 'DISCOVERY_FAILED',
                    'error'  => $e->getMessage(),
                ];
            }
        } else {
            $this->cleanupAuditReport['dosen_pa_discovery'] = [
                'status' => empty($targetDosenPaIds) ? 'DISCOVERY_NOT_APPLICABLE' : 'DISCOVERY_SUCCEEDED_EXPLICIT_ONLY',
                'count'  => count($targetDosenPaIds),
            ];
        }

        // 5. Penugasan Kaprodi Discovery (BLOCKER 1 FIX)
        // Inisialisasi dari ID eksplisit terlebih dahulu
        $targetPenugasanIds = $this->createdPenugasanKaprodiIds;
        $discoveryPenugasanOk = true;
        if (!empty($this->createdDosenIds)) {
            try {
                $relationalPenugasanIds = DB::table('penugasan_kaprodi')
                    ->whereIn('dosen_id', $this->createdDosenIds)
                    ->pluck('id')
                    ->all();
                $targetPenugasanIds = array_values(array_unique(array_merge($targetPenugasanIds, $relationalPenugasanIds)));
                $this->cleanupAuditReport['penugasan_kaprodi_discovery'] = [
                    'status' => 'DISCOVERY_SUCCEEDED',
                    'count'  => count($targetPenugasanIds),
                ];
            } catch (\Throwable $e) {
                $discoveryPenugasanOk = false;
                $cleanupAnomalies[] = 'Discovery penugasan_kaprodi via dosen_id gagal: ' . $e->getMessage();
                $this->cleanupAuditReport['penugasan_kaprodi_discovery'] = [
                    'status' => 'DISCOVERY_FAILED',
                    'error'  => $e->getMessage(),
                ];
            }
        } else {
            $this->cleanupAuditReport['penugasan_kaprodi_discovery'] = [
                'status' => empty($targetPenugasanIds) ? 'DISCOVERY_NOT_APPLICABLE' : 'DISCOVERY_SUCCEEDED_EXPLICIT_ONLY',
                'count'  => count($targetPenugasanIds),
            ];
        }

        // 6. Root User Discovery (Eksplisit + Verifikasi Profil Mahasiswa & Dosen)
        $targetUserIds = $this->createdUserIds;
        $discoveryUsersOk = true;
        try {
            $profileUserIds = [];
            if (!empty($this->createdMahasiswaIds)) {
                $profileUserIds = array_merge(
                    $profileUserIds,
                    DB::table('mahasiswa')->whereIn('id_mahasiswa', $this->createdMahasiswaIds)->pluck('user_id')->all()
                );
            }
            if (!empty($this->createdDosenIds)) {
                $profileUserIds = array_merge(
                    $profileUserIds,
                    DB::table('dosen')->whereIn('id_dosen', $this->createdDosenIds)->pluck('user_id')->all()
                );
            }
            $targetUserIds = array_values(array_unique(array_merge($targetUserIds, array_filter($profileUserIds))));
            $this->cleanupAuditReport['users_discovery'] = [
                'status' => 'DISCOVERY_SUCCEEDED',
                'count'  => count($targetUserIds),
            ];
        } catch (\Throwable $e) {
            $discoveryUsersOk = false;
            $cleanupAnomalies[] = 'Discovery users via profil mahasiswa/dosen gagal: ' . $e->getMessage();
            $this->cleanupAuditReport['users_discovery'] = [
                'status' => 'DISCOVERY_FAILED',
                'error'  => $e->getMessage(),
            ];
        }

        // -------------------------------------------------------------------
        // FASE 2: CHAINED CIRCUIT BREAKER CLEANUP
        // -------------------------------------------------------------------

        // Langkah 1: pengajuan_judul_logs
        $isLogClean = false;
        if (!$discoveryLogsOk) {
            $cleanupAnomalies[] = 'Pembersihan pengajuan_judul_logs dilewati karena discovery gagal.';
            $this->cleanupAuditReport['pengajuan_judul_logs'] = ['status' => 'CLEANUP_SKIPPED_DISCOVERY_FAILED'];
        } else {
            $isLogClean = $this->safeCleanupTable('pengajuan_judul_logs', 'id', $targetLogIds, $cleanupAnomalies);
        }

        // Langkah 2: pengajuan_judul
        $isPengajuanClean = false;
        if (!$isLogClean) {
            $cleanupAnomalies[] = 'Pembersihan pengajuan_judul DIBLOKIR oleh circuit breaker karena log belum bersih.';
            $this->cleanupAuditReport['pengajuan_judul'] = ['status' => 'BLOCKED_BY_LOG_NOT_CLEAN'];
        } elseif (!$discoveryPengajuanOk) {
            $cleanupAnomalies[] = 'Pembersihan pengajuan_judul dilewati karena discovery pengajuan gagal.';
            $this->cleanupAuditReport['pengajuan_judul'] = ['status' => 'CLEANUP_SKIPPED_DISCOVERY_FAILED'];
        } else {
            $isPengajuanClean = $this->safeCleanupTable('pengajuan_judul', 'id', $targetPengajuanIds, $cleanupAnomalies);
        }

        // Langkah 3: dosen_pa
        $isDosenPaClean = false;
        if (!$discoveryDosenPaOk) {
            $cleanupAnomalies[] = 'Pembersihan dosen_pa dilewati karena discovery dosen_pa gagal.';
            $this->cleanupAuditReport['dosen_pa'] = ['status' => 'CLEANUP_SKIPPED_DISCOVERY_FAILED'];
        } else {
            $isDosenPaClean = $this->safeCleanupTable('dosen_pa', 'id_dosen_pa', $targetDosenPaIds, $cleanupAnomalies);
        }

        // Langkah 4: skripsi
        $isSkripsiClean = false;
        if (!$isPengajuanClean) {
            $cleanupAnomalies[] = 'Pembersihan skripsi DIBLOKIR oleh circuit breaker karena pengajuan_judul belum bersih.';
            $this->cleanupAuditReport['skripsi'] = ['status' => 'BLOCKED_BY_PENGAJUAN_NOT_CLEAN'];
        } elseif (!$isDosenPaClean) {
            $cleanupAnomalies[] = 'Pembersihan skripsi DIBLOKIR oleh circuit breaker karena dosen_pa belum bersih.';
            $this->cleanupAuditReport['skripsi'] = ['status' => 'BLOCKED_BY_DOSEN_PA_NOT_CLEAN'];
        } elseif (!$discoverySkripsiOk) {
            $cleanupAnomalies[] = 'Pembersihan skripsi dilewati karena discovery skripsi gagal.';
            $this->cleanupAuditReport['skripsi'] = ['status' => 'CLEANUP_SKIPPED_DISCOVERY_FAILED'];
        } else {
            $isSkripsiClean = $this->safeCleanupTable('skripsi', 'id_skripsi', $targetSkripsiIds, $cleanupAnomalies);
        }

        // Langkah 5: penugasan_kaprodi (BLOCKER 1 FIX)
        $isPenugasanClean = false;
        if (!$discoveryPenugasanOk) {
            // Hapus ID eksplisit jika ada untuk meminimalkan residu
            if (!empty($targetPenugasanIds)) {
                $this->safeCleanupTable('penugasan_kaprodi', 'id', $targetPenugasanIds, $cleanupAnomalies);
            }
            $cleanupAnomalies[] = 'Penugasan Kaprodi discovery relasional gagal; status tidak bersih sehingga memblokir parent dosen.';
            $this->cleanupAuditReport['penugasan_kaprodi'] = ['status' => 'PARTIAL_OR_UNVERIFIED_DISCOVERY_FAILED'];
            $isPenugasanClean = false;
        } else {
            $isPenugasanClean = $this->safeCleanupTable('penugasan_kaprodi', 'id', $targetPenugasanIds, $cleanupAnomalies);
        }

        // Langkah 6: mahasiswa
        $isMahasiswaClean = false;
        if (!$isPengajuanClean) {
            $cleanupAnomalies[] = 'Pembersihan mahasiswa DIBLOKIR oleh circuit breaker karena pengajuan_judul belum bersih.';
            $this->cleanupAuditReport['mahasiswa'] = ['status' => 'BLOCKED_BY_PENGAJUAN_NOT_CLEAN'];
        } elseif (!$isSkripsiClean) {
            $cleanupAnomalies[] = 'Pembersihan mahasiswa DIBLOKIR oleh circuit breaker karena skripsi belum bersih.';
            $this->cleanupAuditReport['mahasiswa'] = ['status' => 'BLOCKED_BY_SKRIPSI_NOT_CLEAN'];
        } else {
            $isMahasiswaClean = $this->safeCleanupTable('mahasiswa', 'id_mahasiswa', $this->createdMahasiswaIds, $cleanupAnomalies);
        }

        // Langkah 7: dosen
        $isDosenClean = false;
        if (!$isDosenPaClean) {
            $cleanupAnomalies[] = 'Pembersihan dosen DIBLOKIR oleh circuit breaker karena dosen_pa belum bersih.';
            $this->cleanupAuditReport['dosen'] = ['status' => 'BLOCKED_BY_DOSEN_PA_NOT_CLEAN'];
        } elseif (!$isPenugasanClean) {
            $cleanupAnomalies[] = 'Pembersihan dosen DIBLOKIR oleh circuit breaker karena penugasan_kaprodi belum bersih.';
            $this->cleanupAuditReport['dosen'] = ['status' => 'BLOCKED_BY_PENUGASAN_NOT_CLEAN'];
        } else {
            $isDosenClean = $this->safeCleanupTable('dosen', 'id_dosen', $this->createdDosenIds, $cleanupAnomalies);
        }

        // Langkah 8: users
        $isUsersClean = false;
        if (!$isMahasiswaClean) {
            $cleanupAnomalies[] = 'Pembersihan users DIBLOKIR oleh circuit breaker karena profil mahasiswa belum bersih.';
            $this->cleanupAuditReport['users'] = ['status' => 'BLOCKED_BY_MAHASISWA_NOT_CLEAN'];
        } elseif (!$isDosenClean) {
            $cleanupAnomalies[] = 'Pembersihan users DIBLOKIR oleh circuit breaker karena profil dosen belum bersih.';
            $this->cleanupAuditReport['users'] = ['status' => 'BLOCKED_BY_DOSEN_NOT_CLEAN'];
        } elseif (!$discoveryUsersOk) {
            $cleanupAnomalies[] = 'Pembersihan users DIBLOKIR oleh circuit breaker karena discovery profil users gagal.';
            $this->cleanupAuditReport['users'] = ['status' => 'BLOCKED_BY_DISCOVERY_USERS_FAILED'];
        } else {
            $isUsersClean = $this->safeCleanupTable('users', 'id', $targetUserIds, $cleanupAnomalies);
        }

        // Langkah 9: Berkas Storage
        $this->safeCleanupStorageFiles($cleanupAnomalies);
    }

    /**
     * Menghapus record pada tabel target berdasarkan daftar ID dan memverifikasi residu bernilai 0.
     */
    private function safeCleanupTable(string $table, string $pkColumn, array $ids, array &$anomalies): bool
    {
        $uniqueIds = array_values(array_unique(array_filter($ids)));
        if (empty($uniqueIds)) {
            $this->cleanupAuditReport[$table] = [
                'status' => 'DISCOVERY_SUCCEEDED_ZERO_TARGETS',
                'count'  => 0,
            ];
            return true;
        }

        try {
            DB::table($table)->whereIn($pkColumn, $uniqueIds)->delete();

            // Verifikasi residu pasca-delete
            $remaining = DB::table($table)->whereIn($pkColumn, $uniqueIds)->count();
            if ($remaining === 0) {
                $this->cleanupAuditReport[$table] = [
                    'status' => 'DELETE_SUCCEEDED_VERIFIED',
                    'count'  => count($uniqueIds),
                ];
                return true;
            }

            $anomalies[] = "Tabel {$table} masih memiliki {$remaining} record residu setelah operasi delete.";
            $this->cleanupAuditReport[$table] = [
                'status'  => 'DELETE_FAILED_RESIDUE_DETECTED',
                'residue' => $remaining,
            ];
            return false;
        } catch (\Throwable $e) {
            // Operasi delete melempar exception: WAJIB dicatat sebagai anomali kegagalan operasi
            $residueCount = null;
            $residueCheckStatus = 'RESIDUE_CHECK_FAILED_UNKNOWN';
            $residueCheckError = null;

            try {
                $residueCount = DB::table($table)->whereIn($pkColumn, $uniqueIds)->count();
                if ($residueCount === 0) {
                    $residueCheckStatus = 'ZERO_RESIDUE_AFTER_DELETE_ERROR';
                } else {
                    $residueCheckStatus = 'RESIDUE_STILL_PRESENT';
                }
            } catch (\Throwable $rcEx) {
                $residueCheckStatus = 'RESIDUE_CHECK_FAILED_UNKNOWN';
                $residueCheckError = $rcEx->getMessage();
            }

            $anomalies[] = "Operasi delete pada tabel {$table} gagal (exception: " . $e->getMessage()
                . ", status residu: {$residueCheckStatus}"
                . ($residueCount !== null ? ", jumlah: {$residueCount}" : '')
                . ($residueCheckError !== null ? ", error verifikasi: {$residueCheckError}" : '')
                . ')';

            $this->cleanupAuditReport[$table] = [
                'status'               => 'DELETE_OPERATION_FAILED',
                'operation_error'      => $e->getMessage(),
                'residue_check_status' => $residueCheckStatus,
                'residue_count'        => $residueCount,
                'residue_check_error'  => $residueCheckError,
            ];

            // Selalu kembalikan false agar circuit breaker memblokir parent tabel
            return false;
        }
    }

    /**
     * Membersihkan berkas storage yang dicatat secara eksplisit dan memverifikasi tidak ada yang tertinggal.
     */
    private function safeCleanupStorageFiles(array &$anomalies): void
    {
        $uniquePaths = array_values(array_unique(array_filter($this->createdFilePaths)));
        if (empty($uniquePaths)) {
            $this->cleanupAuditReport['storage_files'] = [
                'status' => 'NO_FILES_TO_CLEAN',
                'count'  => 0,
            ];
            return;
        }

        $failedPaths = [];
        foreach ($uniquePaths as $path) {
            try {
                if (Storage::disk('public')->exists($path)) {
                    Storage::disk('public')->delete($path);
                }
                if (Storage::disk('public')->exists($path)) {
                    $failedPaths[] = $path;
                }
            } catch (\Throwable $e) {
                $failedPaths[] = $path . ' (' . $e->getMessage() . ')';
            }
        }

        if (!empty($failedPaths)) {
            $anomalies[] = 'Gagal menghapus berkas storage: ' . implode(', ', $failedPaths);
            $this->cleanupAuditReport['storage_files'] = [
                'status'  => 'STORAGE_RESIDUE_DETECTED',
                'residue' => $failedPaths,
            ];
        } else {
            $this->cleanupAuditReport['storage_files'] = [
                'status' => 'STORAGE_ALL_DELETED',
                'count'  => count($uniquePaths),
            ];
        }
    }

    /**
     * Melakukan pelaporan hasil audit cleanup sebelum parent::tearDown() dieksekusi.
     */
    private function processReportingPreTeardown(array $anomalies): void
    {
        if (empty($anomalies)) {
            return;
        }

        $summary = $this->formatAnomaliesSummary($anomalies);

        // 1. Catat ke Laravel Log jika container masih ada
        try {
            Log::error("Anomali cleanup PengajuanJudulRealTransactionTest:\n" . $summary, [
                'audit_report' => $this->cleanupAuditReport,
            ]);
            $this->cleanupAuditReport['laravel_log_written'] = true;
        } catch (\Throwable $logEx) {
            $this->cleanupAuditReport['laravel_log_written'] = false;
            $this->cleanupAuditReport['laravel_log_error'] = $logEx->getMessage();
        }

        // 2. Tulis ke file log darurat native PHP
        $this->safeEmergencyLogPostTeardown("Pre-Teardown Anomali:\n" . $summary);
    }

    /**
     * Menulis log darurat menggunakan fungsi native PHP (bebas dari dependensi container Laravel).
     */
    private function safeEmergencyLogPostTeardown(string $message): void
    {
        $logDir = dirname(__DIR__, 2) . '/storage/logs';
        $logFile = $logDir . '/cleanup_emergency.log';
        $timestamp = date('Y-m-d H:i:s');
        $formattedEntry = "[{$timestamp}] " . $message . PHP_EOL;

        // Tulis ke file log fisik
        $bytesWritten = @file_put_contents($logFile, $formattedEntry, FILE_APPEND | LOCK_EX);
        if ($bytesWritten !== false && $bytesWritten > 0) {
            $this->cleanupAuditReport['emergency_log_file_written'] = true;
        } else {
            $this->cleanupAuditReport['emergency_log_file_written'] = false;
        }

        // Tulis ke STDERR jika stream tersedia
        if (defined('STDERR') && is_resource(STDERR)) {
            $stderrBytes = @fwrite(STDERR, $formattedEntry);
            $this->cleanupAuditReport['stderr_written'] = ($stderrBytes !== false && $stderrBytes > 0);
        }
    }

    /**
     * Memformat daftar anomali menjadi string ringkas untuk pelaporan dan exception.
     */
    private function formatAnomaliesSummary(array $anomalies): string
    {
        $lines = [];
        foreach ($anomalies as $index => $anomaly) {
            $lines[] = ' [' . ($index + 1) . '] ' . $anomaly;
        }
        return implode(PHP_EOL, $lines);
    }

    // -----------------------------------------------------------------------
    // HELPERS FIXTURE DENGAN TRACKING MANDIRI
    // -----------------------------------------------------------------------

    private function buatMahasiswa(string $suffix, string $prodi = 'Informatika'): array
    {
        $cleanSuffix = str_pad(substr(preg_replace('/\D/', '', $suffix), -2), 2, '0', STR_PAD_LEFT);
        $attempts = 0;
        do {
            $nim = '9' . sprintf('%07d', random_int(0, 9999999)) . $cleanSuffix;
            $exists = DB::table('users')->where('nomor_induk', $nim)->exists()
                   || DB::table('mahasiswa')->where('NIM', $nim)->exists();
            $attempts++;
        } while ($exists && $attempts < 10);

        if ($exists) {
            throw new \RuntimeException("Gagal menghasilkan NIM unik 10-digit setelah {$attempts} percobaan.");
        }

        $user = User::factory()->create([
            'role'        => 'mahasiswa',
            'nomor_induk' => $nim,
            'name'        => 'Mhs ' . $nim,
        ]);
        $this->createdUserIds[] = $user->id;

        $mahasiswa = Mahasiswa::create([
            'user_id'        => $user->id,
            'NIM'            => $nim,
            'nama_mahasiswa' => 'Mhs ' . $nim,
            'prodi'          => $prodi,
        ]);
        $this->createdMahasiswaIds[] = $mahasiswa->id_mahasiswa;

        return compact('user', 'mahasiswa');
    }

    private function buatDosen(string $suffix): array
    {
        $cleanSuffix = str_pad(substr(preg_replace('/\D/', '', $suffix), -2), 2, '0', STR_PAD_LEFT);
        $attempts = 0;
        do {
            $nidn = '8' . sprintf('%07d', random_int(0, 9999999)) . $cleanSuffix;
            $exists = DB::table('users')->where('nomor_induk', $nidn)->exists()
                   || DB::table('dosen')->where('NIDN', $nidn)->exists();
            $attempts++;
        } while ($exists && $attempts < 10);

        if ($exists) {
            throw new \RuntimeException("Gagal menghasilkan NIDN unik 10-digit setelah {$attempts} percobaan.");
        }

        $user = User::factory()->create([
            'role'        => 'dosen',
            'nomor_induk' => $nidn,
            'name'        => 'Dosen ' . $nidn,
        ]);
        $this->createdUserIds[] = $user->id;

        $dosen = Dosen::create([
            'user_id'    => $user->id,
            'NIDN'       => $nidn,
            'nama_dosen' => 'Dosen ' . $nidn,
        ]);
        $this->createdDosenIds[] = $dosen->id_dosen;

        return compact('user', 'dosen');
    }

    private function buatSkripsi(Mahasiswa $mhs): Skripsi
    {
        $skripsi = Skripsi::create([
            'id_mahasiswa' => $mhs->id_mahasiswa,
            'judul'        => 'Judul Skripsi ' . $mhs->NIM,
            'status_judul' => 'diajukan',
        ]);
        $this->createdSkripsiIds[] = $skripsi->id_skripsi;

        return $skripsi;
    }

    private function buatDosenPA(Dosen $dosen, Skripsi $skripsi): DosenPA
    {
        $dosenPa = DosenPA::create([
            'id_dosen'   => $dosen->id_dosen,
            'id_skripsi' => $skripsi->id_skripsi,
        ]);
        $this->createdDosenPaIds[] = $dosenPa->id_dosen_pa;

        return $dosenPa;
    }

    private function buatPenugasanKaprodi(
        Dosen $dosen,
        string $prodi = 'Informatika',
        bool $isAktif = true
    ): PenugasanKaprodi {
        $penugasan = PenugasanKaprodi::create([
            'dosen_id'        => $dosen->id_dosen,
            'prodi'           => $prodi,
            'tanggal_mulai'   => now()->subMonth()->toDateString(),
            'tanggal_selesai' => null,
            'is_aktif'        => $isAktif,
        ]);
        $this->createdPenugasanKaprodiIds[] = $penugasan->id;

        return $penugasan;
    }

    // -----------------------------------------------------------------------
    // PENGUJIAN COMMIT & ROLLBACK NYATA
    // -----------------------------------------------------------------------

    /**
     * 1. Commit Nyata: Revisi berhasil meng-commit perubahan ke database MySQL,
     * dan berkas lama dihapus pasca-commit.
     */
    public function test_real_commit_updates_database_and_deletes_old_file_post_commit(): void
    {
        $mhs = $this->buatMahasiswa('01');
        $dsn = $this->buatDosen('01');
        $skripsi = $this->buatSkripsi($mhs['mahasiswa']);
        $this->buatDosenPA($dsn['dosen'], $skripsi);

        // Buat berkas lama di storage fake
        $oldFileContent = 'BERKAS PROPOSAL LAMA REVISI';
        $oldPath = 'pengajuan-judul/real_old_file_' . uniqid() . '.pdf';
        Storage::disk('public')->put($oldPath, $oldFileContent);
        $this->createdFilePaths[] = $oldPath;

        $pengajuan = PengajuanJudul::create([
            'skripsi_id'   => $skripsi->id_skripsi,
            'mahasiswa_id' => $mhs['mahasiswa']->id_mahasiswa,
            'dosen_pa_id'  => $dsn['user']->id,
            'judul'        => 'Judul Awal Pra-Revisi',
            'deskripsi'    => 'Deskripsi Awal',
            'nama_file'    => 'old.pdf',
            'ukuran_file'  => '100 KB',
            'file_path'    => $oldPath,
            'status'       => 'Revisi Dosen PA',
        ]);
        $this->createdPengajuanIds[] = $pengajuan->id;

        // Mahasiswa kirim revisi dengan berkas baru
        $newFile = UploadedFile::fake()->create('real_new_proposal.pdf', 300, 'application/pdf');

        $response = $this->actingAs($mhs['user'], 'sanctum')
            ->postJson("/api/mahasiswa/pengajuan-judul/{$pengajuan->id}/revisi", [
                'judul'             => 'Judul Hasil Perbaikan Nyata',
                'deskripsi'         => 'Deskripsi Baru',
                'file_judul'        => $newFile,
                'catatan_mahasiswa' => 'Perbaikan bab 1 selesai.',
            ]);

        $response->assertStatus(200);
        $response->assertJsonFragment(['status' => 'success']);

        // Verifikasi fisik di MySQL: Status berubah menjadi Menunggu Verifikasi Dosen PA
        $freshPengajuan = PengajuanJudul::find($pengajuan->id);
        $this->assertEquals('Menunggu Verifikasi Dosen PA', $freshPengajuan->status);
        $this->assertEquals('Judul Hasil Perbaikan Nyata', $freshPengajuan->judul);
        $this->assertNotEquals($oldPath, $freshPengajuan->file_path);

        $newPath = $freshPengajuan->file_path;
        $this->createdFilePaths[] = $newPath;

        // Verifikasi berkas baru ada di disk dan berkas lama telah terhapus
        Storage::disk('public')->assertExists($newPath);
        Storage::disk('public')->assertMissing($oldPath);
    }

    /**
     * 2. Rollback Nyata: Kegagalan audit log membatalkan seluruh mutasi ke database MySQL,
     * berkas baru dibersihkan, dan berkas lama tetap utuh di disk.
     */
    public function test_real_rollback_retains_old_file_and_cleans_new_file_on_audit_failure(): void
    {
        $mhs = $this->buatMahasiswa('02');
        $dsn = $this->buatDosen('02');
        $skripsi = $this->buatSkripsi($mhs['mahasiswa']);
        $this->buatDosenPA($dsn['dosen'], $skripsi);

        $oldPath = 'pengajuan-judul/real_safe_old_' . uniqid() . '.pdf';
        Storage::disk('public')->put($oldPath, 'BERKAS LAMA YANG HARUS AMAN');
        $this->createdFilePaths[] = $oldPath;

        $pengajuan = PengajuanJudul::create([
            'skripsi_id'   => $skripsi->id_skripsi,
            'mahasiswa_id' => $mhs['mahasiswa']->id_mahasiswa,
            'dosen_pa_id'  => $dsn['user']->id,
            'judul'        => 'Judul Pra-Rollback',
            'deskripsi'    => 'D',
            'nama_file'    => 'old.pdf',
            'ukuran_file'  => '100 KB',
            'file_path'    => $oldPath,
            'status'       => 'Revisi Dosen PA',
        ]);
        $this->createdPengajuanIds[] = $pengajuan->id;

        // Injeksi kegagalan audit log
        PengajuanJudulLog::creating(function () {
            throw new \RuntimeException("SIMULASI ERROR AUDIT LOG REAL ROLLBACK");
        });

        try {
            $newFile = UploadedFile::fake()->create('orphan_candidate.pdf', 300, 'application/pdf');

            $response = $this->actingAs($mhs['user'], 'sanctum')
                ->postJson("/api/mahasiswa/pengajuan-judul/{$pengajuan->id}/revisi", [
                    'judul'      => 'Judul Yang Gagal',
                    'file_judul' => $newFile,
                ]);

            $response->assertStatus(500);
            $response->assertJsonFragment(['status' => 'error']);
        } finally {
            PengajuanJudulLog::flushEventListeners();
        }

        // Verifikasi fisik di MySQL: Data tidak berubah (status tetap Revisi Dosen PA, path tetap oldPath)
        $freshPengajuan = PengajuanJudul::find($pengajuan->id);
        $this->assertEquals('Revisi Dosen PA', $freshPengajuan->status);
        $this->assertEquals($oldPath, $freshPengajuan->file_path);

        // Verifikasi berkas lama TETAP ADA di storage
        Storage::disk('public')->assertExists($oldPath);
    }

    /**
     * 3. Kegagalan Filesystem Pasca-Commit: Jika Storage::delete($oldFilePath) mengembalikan false,
     * transaksi database tetap sah (commit) dan respons tetap 200 Success.
     */
    public function test_old_file_delete_failure_post_commit_does_not_abort_database_success(): void
    {
        $mhs = $this->buatMahasiswa('03');
        $dsn = $this->buatDosen('03');
        $skripsi = $this->buatSkripsi($mhs['mahasiswa']);
        $this->buatDosenPA($dsn['dosen'], $skripsi);

        // Path lama yang sengaja TIDAK dibuat di storage (sehingga delete mengembalikan false/tidak ada)
        $nonExistentOldPath = 'pengajuan-judul/ghost_old_file_' . uniqid() . '.pdf';

        $pengajuan = PengajuanJudul::create([
            'skripsi_id'   => $skripsi->id_skripsi,
            'mahasiswa_id' => $mhs['mahasiswa']->id_mahasiswa,
            'dosen_pa_id'  => $dsn['user']->id,
            'judul'        => 'Judul Pra-Revisi',
            'deskripsi'    => 'D',
            'nama_file'    => 'ghost.pdf',
            'ukuran_file'  => '100 KB',
            'file_path'    => $nonExistentOldPath,
            'status'       => 'Revisi Dosen PA',
        ]);
        $this->createdPengajuanIds[] = $pengajuan->id;

        $newFile = UploadedFile::fake()->create('valid_new_doc.pdf', 250, 'application/pdf');

        $response = $this->actingAs($mhs['user'], 'sanctum')
            ->postJson("/api/mahasiswa/pengajuan-judul/{$pengajuan->id}/revisi", [
                'judul'      => 'Judul Perbaikan Valid',
                'file_judul' => $newFile,
            ]);

        // Respons tetap harus 200 Success
        $response->assertStatus(200);

        $freshPengajuan = PengajuanJudul::find($pengajuan->id);
        $this->assertEquals('Menunggu Verifikasi Dosen PA', $freshPengajuan->status);
        $this->assertEquals('Judul Perbaikan Valid', $freshPengajuan->judul);
        $this->createdFilePaths[] = $freshPengajuan->file_path;

        Storage::disk('public')->assertExists($freshPengajuan->file_path);
    }

    /**
     * 4. Keamanan Informasi Error 500: Respons HTTP 500 tidak boleh membocorkan detail SQL / tabel.
     */
    public function test_internal_database_error_does_not_leak_sql_details_in_500_response(): void
    {
        $mhs = $this->buatMahasiswa('04');
        $dsn = $this->buatDosen('04');
        $skripsi = $this->buatSkripsi($mhs['mahasiswa']);
        $this->buatDosenPA($dsn['dosen'], $skripsi);

        $pengajuan = PengajuanJudul::create([
            'skripsi_id'   => $skripsi->id_skripsi,
            'mahasiswa_id' => $mhs['mahasiswa']->id_mahasiswa,
            'dosen_pa_id'  => $dsn['user']->id,
            'judul'        => 'Judul Test Error 500',
            'deskripsi'    => 'D',
            'nama_file'    => 'test.pdf',
            'ukuran_file'  => '100 KB',
            'file_path'    => 'p/test.pdf',
            'status'       => 'Revisi Dosen PA',
        ]);
        $this->createdPengajuanIds[] = $pengajuan->id;

        // Injeksi exception yang menyerupai PDOException / SQL detail
        PengajuanJudulLog::creating(function () {
            throw new \PDOException("SQLSTATE[42S02]: Base table or view not found: 1146 Table 'manajemen_skripsi_test.secret_table' doesn't exist");
        });

        try {
            $response = $this->actingAs($mhs['user'], 'sanctum')
                ->postJson("/api/mahasiswa/pengajuan-judul/{$pengajuan->id}/revisi", [
                    'judul' => 'Judul Baru Yang Memicu SQL Error',
                ]);

            $response->assertStatus(500);

            // Verifikasi tidak ada SQLSTATE, nama tabel, atau error mentah di respons
            $content = $response->getContent();
            $this->assertStringNotContainsString('SQLSTATE', $content);
            $this->assertStringNotContainsString('secret_table', $content);
            $this->assertStringNotContainsString('Base table or view not found', $content);

            // Verifikasi respons menggunakan pesan generik aman
            $response->assertJsonFragment([
                'status'  => 'error',
                'message' => 'Terjadi kesalahan pada server saat memproses perbaikan pengajuan. Silakan coba lagi.',
            ]);
        } finally {
            PengajuanJudulLog::flushEventListeners();
        }
    }

    /**
     * 5. Record Tidak Ditemukan Saat Penguncian: Menangani record null secara aman tanpa fatal error.
     */
    public function test_record_deleted_before_lock_returns_422_safely_without_null_pointer(): void
    {
        $mhs = $this->buatMahasiswa('05');

        // Panggil endpoint revisi dengan ID fiktif / sudah dihapus
        $response = $this->actingAs($mhs['user'], 'sanctum')
            ->postJson("/api/mahasiswa/pengajuan-judul/99999999/revisi", [
                'judul' => 'Revisi ID Tidak Ada',
            ]);

        // Ditolak dengan 404 (atau 422 di dalam lock), tanpa error 500
        $this->assertContains($response->status(), [404, 422]);
        $response->assertJsonFragment(['status' => 'error']);
    }

    /**
     * 6. Validasi Penguncian Induk Skripsi pada store(): Judul final disetujui memblokir store() di dalam transaksi.
     */
    public function test_store_inside_transaction_blocks_submission_if_final_title_already_approved(): void
    {
        $mhs = $this->buatMahasiswa('06');
        $dsn = $this->buatDosen('06');
        $skripsi = $this->buatSkripsi($mhs['mahasiswa']);
        $this->buatDosenPA($dsn['dosen'], $skripsi);

        // Set status judul skripsi menjadi disetujui
        $skripsi->update(['status_judul' => 'disetujui']);

        $file = UploadedFile::fake()->create('dokumen_final.pdf', 300, 'application/pdf');

        $response = $this->actingAs($mhs['user'], 'sanctum')
            ->postJson('/api/mahasiswa/pengajuan-judul', [
                'judul'      => 'Judul Mencoba Bypass Final',
                'deskripsi'  => 'D',
                'file_judul' => $file,
            ]);

        $response->assertStatus(422);
        $response->assertJsonFragment([
            'status'  => 'error',
            'message' => 'Skripsi Anda sudah memiliki judul yang disetujui secara final.',
        ]);
    }

    /**
     * 7. Sanitasi Error 500 Verifikasi Dosen PA:
     * Exception internal tidak boleh membocorkan SQLSTATE, query mentah, nama tabel, atau stack trace.
     */
    public function test_verifikasi_dosen_internal_database_error_does_not_leak_sql_details_in_500_response(): void
    {
        Log::spy();

        $mhs = $this->buatMahasiswa('07');
        $dsn = $this->buatDosen('07');
        $skripsi = $this->buatSkripsi($mhs['mahasiswa']);
        $this->buatDosenPA($dsn['dosen'], $skripsi);

        $pengajuan = PengajuanJudul::create([
            'skripsi_id'   => $skripsi->id_skripsi,
            'mahasiswa_id' => $mhs['mahasiswa']->id_mahasiswa,
            'dosen_pa_id'  => $dsn['user']->id,
            'judul'        => 'Pengajuan Uji Sanitasi PA',
            'deskripsi'    => 'D',
            'nama_file'    => 'pa.pdf',
            'ukuran_file'  => '100 KB',
            'file_path'    => 'p/pa.pdf',
            'status'       => 'Menunggu Verifikasi Dosen PA',
        ]);
        $this->createdPengajuanIds[] = $pengajuan->id;

        // Injeksi exception yang menyerupai PDOException / SQL detail
        PengajuanJudulLog::creating(function () {
            throw new \PDOException("SQLSTATE[42S02]: Base table or view not found: 1146 Table 'manajemen_skripsi_test.secret_pa_table' doesn't exist");
        });

        try {
            $response = $this->actingAs($dsn['user'], 'sanctum')
                ->patchJson("/api/dosen/pengajuan-judul/{$pengajuan->id}/verifikasi", [
                    'status'        => 'Menunggu Persetujuan Kaprodi',
                    'catatan_dosen' => 'Disetujui PA',
                ]);

            $response->assertStatus(500);

            // Verifikasi tidak ada SQLSTATE, nama tabel, atau error mentah di respons
            $content = $response->getContent();
            $this->assertStringNotContainsString('SQLSTATE', $content);
            $this->assertStringNotContainsString('secret_pa_table', $content);
            $this->assertStringNotContainsString('Base table or view not found', $content);

            // Verifikasi respons menggunakan pesan generik aman
            $response->assertJsonFragment([
                'status'  => 'error',
                'message' => 'Terjadi kesalahan pada server saat memproses verifikasi pengajuan. Silakan coba lagi.',
            ]);

            // Verifikasi rollback terjadi: status tetap awal dan log tidak tersimpan
            $this->assertDatabaseHas('pengajuan_judul', [
                'id'     => $pengajuan->id,
                'status' => 'Menunggu Verifikasi Dosen PA',
            ]);
            $this->assertDatabaseMissing('pengajuan_judul_logs', [
                'pengajuan_judul_id' => $pengajuan->id,
            ]);

            // Verifikasi server log mencatat detail error beserta konteks
            Log::shouldHaveReceived('error')
                ->once()
                ->withArgs(function ($message, $context) use ($dsn, $pengajuan) {
                    return str_contains($message, 'Kegagalan internal saat verifikasi pengajuan judul oleh Dosen PA')
                        && isset($context['exception'])
                        && ($context['user_id'] ?? null) === $dsn['user']->id
                        && ($context['pengajuan_id'] ?? null) == $pengajuan->id;
                });
        } finally {
            PengajuanJudulLog::flushEventListeners();
        }
    }

    /**
     * 8. Pemisahan DomainException pada Verifikasi Dosen PA:
     * Perubahan status yang terjadi di dalam transaksi mengembalikan HTTP 422, bukan HTTP 500.
     */
    public function test_verifikasi_dosen_concurrent_status_change_returns_422_domain_exception(): void
    {
        $mhs = $this->buatMahasiswa('08');
        $dsn = $this->buatDosen('08');
        $skripsi = $this->buatSkripsi($mhs['mahasiswa']);
        $this->buatDosenPA($dsn['dosen'], $skripsi);

        $pengajuan = PengajuanJudul::create([
            'skripsi_id'   => $skripsi->id_skripsi,
            'mahasiswa_id' => $mhs['mahasiswa']->id_mahasiswa,
            'dosen_pa_id'  => $dsn['user']->id,
            'judul'        => 'Pengajuan Uji Domain PA',
            'deskripsi'    => 'D',
            'nama_file'    => 'pa.pdf',
            'ukuran_file'  => '100 KB',
            'file_path'    => 'p/pa.pdf',
            'status'       => 'Menunggu Verifikasi Dosen PA',
        ]);
        $this->createdPengajuanIds[] = $pengajuan->id;

        // Simulasikan status telah berubah saat lock didapat di dalam transaksi
        PengajuanJudulLog::creating(function () {
            throw new \DomainException("Status pengajuan telah berubah.");
        });

        try {
            $response = $this->actingAs($dsn['user'], 'sanctum')
                ->patchJson("/api/dosen/pengajuan-judul/{$pengajuan->id}/verifikasi", [
                    'status'        => 'Menunggu Persetujuan Kaprodi',
                    'catatan_dosen' => 'Disetujui PA',
                ]);

            $response->assertStatus(422);
            $response->assertJsonFragment([
                'status'  => 'error',
                'message' => 'Status pengajuan telah berubah.',
            ]);
        } finally {
            PengajuanJudulLog::flushEventListeners();
        }
    }

    /**
     * 9. Sanitasi Error 500 Persetujuan Kaprodi:
     * Exception internal tidak boleh membocorkan SQLSTATE, query mentah, nama tabel, atau stack trace.
     */
    public function test_persetujuan_kaprodi_internal_database_error_does_not_leak_sql_details_in_500_response(): void
    {
        Log::spy();

        $mhs = $this->buatMahasiswa('09', 'Sains Pertanian');
        $dsnKaprodi = $this->buatDosen('09');
        $this->buatPenugasanKaprodi($dsnKaprodi['dosen'], 'Sains Pertanian', true);

        $skripsi = $this->buatSkripsi($mhs['mahasiswa']);

        $pengajuan = PengajuanJudul::create([
            'skripsi_id'   => $skripsi->id_skripsi,
            'mahasiswa_id' => $mhs['mahasiswa']->id_mahasiswa,
            'dosen_pa_id'  => $dsnKaprodi['user']->id,
            'judul'        => 'Pengajuan Uji Sanitasi Kaprodi',
            'deskripsi'    => 'D',
            'nama_file'    => 'kaprodi.pdf',
            'ukuran_file'  => '100 KB',
            'file_path'    => 'p/kaprodi.pdf',
            'status'       => 'Menunggu Persetujuan Kaprodi',
        ]);
        $this->createdPengajuanIds[] = $pengajuan->id;

        // Injeksi exception yang menyerupai PDOException / SQL detail
        PengajuanJudulLog::creating(function () {
            throw new \PDOException("SQLSTATE[42S02]: Base table or view not found: 1146 Table 'manajemen_skripsi_test.secret_kaprodi_table' doesn't exist");
        });

        try {
            $response = $this->actingAs($dsnKaprodi['user'], 'sanctum')
                ->patchJson("/api/kaprodi/pengajuan-judul/{$pengajuan->id}/persetujuan", [
                    'status' => 'Disetujui Kaprodi',
                ]);

            $response->assertStatus(500);

            // Verifikasi tidak ada SQLSTATE, nama tabel, atau error mentah di respons
            $content = $response->getContent();
            $this->assertStringNotContainsString('SQLSTATE', $content);
            $this->assertStringNotContainsString('secret_kaprodi_table', $content);
            $this->assertStringNotContainsString('Base table or view not found', $content);

            // Verifikasi respons menggunakan pesan generik aman
            $response->assertJsonFragment([
                'status'  => 'error',
                'message' => 'Terjadi kesalahan pada server saat memproses persetujuan Kaprodi. Silakan coba lagi.',
            ]);

            // Verifikasi rollback terjadi: status pengajuan tetap awal dan judul skripsi tidak berubah
            $this->assertDatabaseHas('pengajuan_judul', [
                'id'     => $pengajuan->id,
                'status' => 'Menunggu Persetujuan Kaprodi',
            ]);
            $this->assertDatabaseHas('skripsi', [
                'id_skripsi'   => $skripsi->id_skripsi,
                'judul'        => $skripsi->judul,
                'status_judul' => 'diajukan',
            ]);

            // Verifikasi server log mencatat detail error beserta konteks
            Log::shouldHaveReceived('error')
                ->once()
                ->withArgs(function ($message, $context) use ($dsnKaprodi, $pengajuan) {
                    return str_contains($message, 'Kegagalan internal saat persetujuan pengajuan judul oleh Kaprodi')
                        && isset($context['exception'])
                        && ($context['user_id'] ?? null) === $dsnKaprodi['user']->id
                        && ($context['pengajuan_id'] ?? null) == $pengajuan->id;
                });
        } finally {
            PengajuanJudulLog::flushEventListeners();
        }
    }

    /**
     * 10. Pemisahan DomainException pada Persetujuan Kaprodi (Status Berubah):
     * Perubahan status yang terjadi di dalam transaksi mengembalikan HTTP 422, bukan HTTP 500.
     */
    public function test_persetujuan_kaprodi_concurrent_status_change_returns_422_domain_exception(): void
    {
        $mhs = $this->buatMahasiswa('10', 'Sains Pertanian');
        $dsnKaprodi = $this->buatDosen('10');
        $this->buatPenugasanKaprodi($dsnKaprodi['dosen'], 'Sains Pertanian', true);

        $skripsi = $this->buatSkripsi($mhs['mahasiswa']);

        $pengajuan = PengajuanJudul::create([
            'skripsi_id'   => $skripsi->id_skripsi,
            'mahasiswa_id' => $mhs['mahasiswa']->id_mahasiswa,
            'dosen_pa_id'  => $dsnKaprodi['user']->id,
            'judul'        => 'Pengajuan Uji Domain Kaprodi',
            'deskripsi'    => 'D',
            'nama_file'    => 'kaprodi.pdf',
            'ukuran_file'  => '100 KB',
            'file_path'    => 'p/kaprodi.pdf',
            'status'       => 'Menunggu Persetujuan Kaprodi',
        ]);
        $this->createdPengajuanIds[] = $pengajuan->id;

        PengajuanJudulLog::creating(function () {
            throw new \DomainException("Status pengajuan telah berubah oleh transaksi lain.");
        });

        try {
            $response = $this->actingAs($dsnKaprodi['user'], 'sanctum')
                ->patchJson("/api/kaprodi/pengajuan-judul/{$pengajuan->id}/persetujuan", [
                    'status' => 'Disetujui Kaprodi',
                ]);

            $response->assertStatus(422);
            $response->assertJsonFragment([
                'status'  => 'error',
                'message' => 'Status pengajuan telah berubah oleh transaksi lain.',
            ]);
        } finally {
            PengajuanJudulLog::flushEventListeners();
        }
    }

    /**
     * 11. Pemisahan DomainException pada Persetujuan Kaprodi (Inkonsistensi Skripsi):
     * Inkonsistensi data skripsi dengan mahasiswa pengaju mengembalikan HTTP 422, bukan HTTP 500.
     */
    public function test_persetujuan_kaprodi_inconsistent_skripsi_returns_422_domain_exception(): void
    {
        $mhs1 = $this->buatMahasiswa('11', 'Sains Pertanian');
        $mhs2 = $this->buatMahasiswa('12', 'Sains Pertanian');
        $dsnKaprodi = $this->buatDosen('11');
        $this->buatPenugasanKaprodi($dsnKaprodi['dosen'], 'Sains Pertanian', true);

        // Skripsi milik mhs2, tetapi diajukan seolah-olah oleh mhs1
        $skripsiMhs2 = $this->buatSkripsi($mhs2['mahasiswa']);

        $pengajuan = PengajuanJudul::create([
            'skripsi_id'   => $skripsiMhs2->id_skripsi,
            'mahasiswa_id' => $mhs1['mahasiswa']->id_mahasiswa,
            'dosen_pa_id'  => $dsnKaprodi['user']->id,
            'judul'        => 'Pengajuan Skripsi Mismatch',
            'deskripsi'    => 'D',
            'nama_file'    => 'kaprodi.pdf',
            'ukuran_file'  => '100 KB',
            'file_path'    => 'p/kaprodi.pdf',
            'status'       => 'Menunggu Persetujuan Kaprodi',
        ]);
        $this->createdPengajuanIds[] = $pengajuan->id;

        $response = $this->actingAs($dsnKaprodi['user'], 'sanctum')
            ->patchJson("/api/kaprodi/pengajuan-judul/{$pengajuan->id}/persetujuan", [
                'status' => 'Disetujui Kaprodi',
            ]);

        $response->assertStatus(422);
        $response->assertJsonFragment([
            'status'  => 'error',
            'message' => 'Data skripsi tidak konsisten dengan pengajuan.',
        ]);
    }
}
