<?php

namespace App\Services;

use App\Models\BchatProvisioningOutbox;
use App\Models\BchatRoom;
use App\Models\BchatRoomMember;
use App\Models\Dosen;
use App\Models\Mahasiswa;
use App\Models\PenugasanPembimbing;
use App\Models\Skripsi;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PDOException;
use Throwable;

class BchatProvisioningWorkerService
{
    /**
     * Identitas unik proses worker (format: worker:{hostname}:{pid}:{random_hex}).
     */
    protected string $workerIdentity;

    public function __construct(?string $workerIdentity = null)
    {
        if ($workerIdentity !== null) {
            $this->workerIdentity = $workerIdentity;
        } else {
            $hostname = substr(preg_replace('/[^a-zA-Z0-9_-]/', '', gethostname() ?: 'host'), 0, 20);
            $pid = getmypid() ?: mt_rand(1000, 9999);
            $hex = bin2hex(random_bytes(4));
            $this->workerIdentity = "worker:{$hostname}:{$pid}:{$hex}";
        }
    }

    /**
     * Dapatkan identitas worker saat ini.
     */
    public function getWorkerIdentity(): string
    {
        return $this->workerIdentity;
    }

    /**
     * Set identitas worker (berguna untuk pengujian terisolasi).
     */
    public function setWorkerIdentity(string $identity): void
    {
        $this->workerIdentity = $identity;
    }

    /**
     * Hitung jadwal backoff deterministik tanpa jitter berdasarkan nilai attempts setelah inkremen.
     *
     * 1 => 10 detik
     * 2 => 20 detik
     * 3 => 40 detik
     * 4 => 80 detik
     * 5 => 160 detik
     * >= 6 => null (Terminal FAILED)
     */
    public function calculateBackoffDelay(int $attemptsAfterIncrement): ?int
    {
        return match ($attemptsAfterIncrement) {
            1 => 10,
            2 => 20,
            3 => 40,
            4 => 80,
            5 => 160,
            default => null,
        };
    }

    /**
     * Evaluasi kepatuhan lease 30 detik menggunakan presisi mikrodetik UTC.
     *
     * Arah selisih: dari locked_at menuju nowUtc.
     * Rentang valid: 0 <= elapsedMicroseconds <= 30.000.000.
     */
    public function isLeaseValid(BchatProvisioningOutbox $outbox, ?Carbon $nowUtc = null): bool
    {
        if (empty($outbox->locked_at)) {
            return false;
        }

        $now = $nowUtc ?: Carbon::now('UTC');

        try {
            $lockedAt = $outbox->locked_at instanceof Carbon
                ? $outbox->locked_at->copy()->setTimezone('UTC')
                : Carbon::parse($outbox->locked_at, 'UTC');
        } catch (Throwable) {
            return false;
        }

        $elapsedMicroseconds = $lockedAt->diffInMicroseconds($now, false);

        return $elapsedMicroseconds >= 0 && $elapsedMicroseconds <= 30_000_000;
    }

    /**
     * FASE 1: Klaim Event PENDING Secara Atomik (Transaksi T1).
     *
     * Mengambil 1 event PENDING jatuh tempo dengan 'FOR UPDATE SKIP LOCKED',
     * memutasikan status ke PROCESSING, mengisi lease & token, lalu langsung commit.
     */
    public function claimNextPendingEvent(?string $connectionName = null): ?BchatProvisioningOutbox
    {
        $connection = $connectionName ?: config('database.default');

        return DB::connection($connection)->transaction(function () use ($connection) {
            $nowUtcFormatted = Carbon::now('UTC')->format('Y-m-d H:i:s.u');

            $outbox = BchatProvisioningOutbox::on($connection)
                ->where('status', 'PENDING')
                ->where('event_type', 'PROPOSAL_ROOM_PROVISION')
                ->where(function ($query) use ($nowUtcFormatted) {
                    $query->whereNull('next_attempt_at')
                        ->orWhere('next_attempt_at', '<=', $nowUtcFormatted);
                })
                ->where('attempts', '<', 6)
                ->orderBy('next_attempt_at', 'asc')
                ->orderBy('id', 'asc')
                ->lock('for update skip locked')
                ->first();

            if (! $outbox) {
                return null;
            }

            $now = Carbon::now('UTC');
            $outbox->status = 'PROCESSING';
            $outbox->locked_at = $now;
            $outbox->locked_by = $this->workerIdentity;
            $outbox->generation_token = $outbox->generation_token + 1;
            $outbox->updated_at = $now;
            $outbox->save();

            return $outbox;
        });
    }

    /**
     * FASE 3: Transaksi Provisioning & Deep Verification (Transaksi T2).
     *
     * Menjalankan fencing guard, penanganan locked_at NULL, dual lease check,
     * deep verification akademik (termasuk validasi akun User & role),
     * deep verification room existing dengan row locking hierarkis terurut,
     * pembuatan room & member baru,
     * serta pemisahan failure Kondisi A (terminal dalam T2) vs Kondisi B (rollback T2 + guarded T_FAIL).
     *
     * @param BchatProvisioningOutbox $claimedOutbox Hasil claim T1
     * @param int $expectedToken Token generasi yang diharapkan setelah claim T1
     * @param string|null $connectionName Nama koneksi database
     * @return bool True jika provisioning sukses/idempoten, False jika gagal (terminal atau transient)
     */
    public function processClaimedEvent(
        BchatProvisioningOutbox $claimedOutbox,
        int $expectedToken,
        ?string $connectionName = null
    ): bool {
        $connection = $connectionName ?: config('database.default');

        DB::connection($connection)->beginTransaction();

        try {
            // 1. Lock Row Outbox untuk T2
            $outbox = BchatProvisioningOutbox::on($connection)
                ->where('id', $claimedOutbox->id)
                ->lockForUpdate()
                ->first();

            if (! $outbox) {
                DB::connection($connection)->rollBack();
                return false;
            }

            // 2. Fencing Guard
            if (
                $outbox->status !== 'PROCESSING' ||
                (int) $outbox->generation_token !== $expectedToken ||
                $outbox->locked_by !== $this->workerIdentity
            ) {
                DB::connection($connection)->rollBack();
                return false;
            }

            // 3. Pemeriksaan locked_at NULL dilakukan SEBELUM Carbon::parse()
            if (empty($outbox->locked_at)) {
                DB::connection($connection)->rollBack();
                $this->handleTechnicalFailure(
                    $claimedOutbox->id,
                    $expectedToken,
                    'LOCKED_AT_NULL',
                    'Outbox locked_at is NULL at start of T2; cannot calculate lease duration',
                    $connection
                );
                return false;
            }

            try {
                $lockedAtUtc = Carbon::parse($outbox->locked_at, 'UTC');
            } catch (Throwable $e) {
                DB::connection($connection)->rollBack();
                $this->handleTechnicalFailure(
                    $claimedOutbox->id,
                    $expectedToken,
                    'LOCKED_AT_INVALID_FORMAT',
                    'Outbox locked_at cannot be parsed as UTC datetime: ' . $e->getMessage(),
                    $connection
                );
                return false;
            }

            // 4. Lease Check #1 (Awal T2)
            $elapsedInitial = $lockedAtUtc->diffInMicroseconds(Carbon::now('UTC'), false);
            if ($elapsedInitial < 0 || $elapsedInitial > 30_000_000) {
                DB::connection($connection)->rollBack();
                $this->handleTechnicalFailure(
                    $claimedOutbox->id,
                    $expectedToken,
                    'LEASE_EXPIRED_INITIAL',
                    'Worker lease expired or clock drift at start of T2: ' . $elapsedInitial . ' us',
                    $connection
                );
                return false;
            }

            // 5. Repeatable Deep Verification Akademik di T2
            $penugasan = PenugasanPembimbing::on($connection)
                ->where('id', $outbox->penugasan_pembimbing_id)
                ->lockForUpdate()
                ->first();

            if (! $penugasan) {
                return $this->failTerminalConditionA(
                    $outbox,
                    'ASSIGNMENT_NOT_FOUND',
                    'Penugasan pembimbing not found in database',
                    $connection
                );
            }

            if ($penugasan->status_penugasan !== 'AKTIF') {
                return $this->failTerminalConditionA(
                    $outbox,
                    'ASSIGNMENT_INACTIVE',
                    'Penugasan pembimbing is not AKTIF: ' . $penugasan->status_penugasan,
                    $connection
                );
            }

            if ($penugasan->tahap !== 'proposal' || (int) $penugasan->pembimbing_ke !== 1) {
                return $this->failTerminalConditionA(
                    $outbox,
                    'INVALID_STAGE_OR_ORDINAL',
                    'Penugasan is not proposal pembimbing 1: tahap=' . $penugasan->tahap . ', ke=' . $penugasan->pembimbing_ke,
                    $connection
                );
            }

            $skripsi = Skripsi::on($connection)->where('id_skripsi', $penugasan->skripsi_id)->first();
            if (! $skripsi) {
                return $this->failTerminalConditionA(
                    $outbox,
                    'SKRIPSI_NOT_FOUND',
                    'Parent skripsi not found for penugasan',
                    $connection
                );
            }

            $mahasiswa = Mahasiswa::on($connection)->where('id_mahasiswa', $skripsi->id_mahasiswa)->first();
            if (! $mahasiswa || empty($mahasiswa->user_id)) {
                return $this->failTerminalConditionA(
                    $outbox,
                    'MAHASISWA_USER_NOT_FOUND',
                    'Mahasiswa record or user_id not found for skripsi',
                    $connection
                );
            }

            $mahasiswaUser = User::on($connection)->where('id', $mahasiswa->user_id)->first();
            if (! $mahasiswaUser) {
                return $this->failTerminalConditionA(
                    $outbox,
                    'MAHASISWA_USER_RECORD_NOT_FOUND',
                    'User record for mahasiswa not found in users table',
                    $connection
                );
            }

            $dosen = Dosen::on($connection)->where('id_dosen', $penugasan->dosen_id)->first();
            if (! $dosen || empty($dosen->user_id)) {
                return $this->failTerminalConditionA(
                    $outbox,
                    'DOSEN_USER_NOT_FOUND',
                    'Dosen record or user_id not found for penugasan',
                    $connection
                );
            }

            $dosenUser = User::on($connection)->where('id', $dosen->user_id)->first();
            if (! $dosenUser) {
                return $this->failTerminalConditionA(
                    $outbox,
                    'DOSEN_USER_RECORD_NOT_FOUND',
                    'User record for dosen not found in users table',
                    $connection
                );
            }

            if ((int) $mahasiswaUser->id === (int) $dosenUser->id) {
                return $this->failTerminalConditionA(
                    $outbox,
                    'IDENTICAL_USER_IDENTITIES',
                    'Mahasiswa user_id cannot be identical to dosen user_id',
                    $connection
                );
            }

            if ($mahasiswaUser->role !== 'mahasiswa') {
                return $this->failTerminalConditionA(
                    $outbox,
                    'MAHASISWA_ROLE_MISMATCH',
                    'User record role for mahasiswa is not mahasiswa: ' . $mahasiswaUser->role,
                    $connection
                );
            }

            if ($dosenUser->role !== 'dosen') {
                return $this->failTerminalConditionA(
                    $outbox,
                    'DOSEN_ROLE_MISMATCH',
                    'User record role for dosen is not dosen: ' . $dosenUser->role,
                    $connection
                );
            }

            // 6. Deep Verification Room Existing atau Pembuatan Room Baru
            $existingRoom = BchatRoom::on($connection)
                ->where('penugasan_pembimbing_id', $penugasan->id)
                ->lockForUpdate()
                ->first();

            if ($existingRoom) {
                // Audit Integritas Room Existing
                if ((int) $existingRoom->penugasan_pembimbing_id !== (int) $penugasan->id ||
                    $existingRoom->tahap !== 'proposal' ||
                    (int) $existingRoom->pembimbing_ke !== 1
                ) {
                    return $this->failTerminalConditionA(
                        $outbox,
                        'ROOM_INTEGRITY_CONFLICT',
                        'Room penugasan, stage, or ordinal mismatch with assignment',
                        $connection
                    );
                }

                if (in_array($existingRoom->status, ['SUSPENDED', 'ARCHIVED_READONLY', 'TERMINATED'], true)) {
                    return $this->failTerminalConditionA(
                        $outbox,
                        'ROOM_TERMINAL_STATE_CONFLICT',
                        'Existing room is in irreversible terminal state: ' . $existingRoom->status,
                        $connection
                    );
                }

                $members = BchatRoomMember::on($connection)
                    ->where('room_id', $existingRoom->id)
                    ->orderBy('id', 'asc')
                    ->lockForUpdate()
                    ->get();

                if ($members->count() !== 2) {
                    return $this->failTerminalConditionA(
                        $outbox,
                        'ROOM_INTEGRITY_CONFLICT',
                        'Existing room member count is not exactly 2: ' . $members->count(),
                        $connection
                    );
                }

                $mhsMember = $members->firstWhere('role', 'mahasiswa');
                $dosMember = $members->firstWhere('role', 'dosen_pembimbing');

                if (! $mhsMember || ! $dosMember) {
                    return $this->failTerminalConditionA(
                        $outbox,
                        'ROOM_INTEGRITY_CONFLICT',
                        'Existing room must contain exactly one mahasiswa and one dosen_pembimbing',
                        $connection
                    );
                }

                if ((int) $mhsMember->user_id !== (int) $mahasiswaUser->id ||
                    (int) $dosMember->user_id !== (int) $dosenUser->id
                ) {
                    return $this->failTerminalConditionA(
                        $outbox,
                        'ROOM_INTEGRITY_CONFLICT',
                        'Room member user_id does not match active academic assignment',
                        $connection
                    );
                }

                if ($existingRoom->status === 'PENDING_WELCOME_ACK') {
                    if ($mhsMember->is_active || $dosMember->is_active) {
                        return $this->failTerminalConditionA(
                            $outbox,
                            'MEMBER_STATUS_CORRUPTED',
                            'Both members must be inactive in PENDING_WELCOME_ACK room',
                            $connection
                        );
                    }
                } elseif (in_array($existingRoom->status, ['ACTIVE', 'PENDING_REKEYING'], true)) {
                    if (! $mhsMember->is_active || ! $dosMember->is_active) {
                        return $this->failTerminalConditionA(
                            $outbox,
                            'MEMBER_STATUS_CORRUPTED',
                            'Both members must be active in ' . $existingRoom->status . ' room',
                            $connection
                        );
                    }
                } else {
                    return $this->failTerminalConditionA(
                        $outbox,
                        'ROOM_INTEGRITY_CONFLICT',
                        'Existing room status unrecognized or invalid: ' . $existingRoom->status,
                        $connection
                    );
                }

                // Room existing sah dan konsisten (Idempoten)
            } else {
                // Pembuatan Room Baru & Dua Anggota Awal
                $newRoom = new BchatRoom();
                $newRoom->setConnection($connection);
                $newRoom->room_uuid = (string) Str::uuid();
                $newRoom->penugasan_pembimbing_id = $penugasan->id;
                $newRoom->tahap = 'proposal';
                $newRoom->pembimbing_ke = 1;
                $newRoom->current_sequence = 0;
                $newRoom->status = 'PENDING_WELCOME_ACK';
                $newRoom->current_epoch = 0;
                $newRoom->save();

                // Mahasiswa: is_initiator = false, is_active = false
                $newMhsMember = new BchatRoomMember();
                $newMhsMember->setConnection($connection);
                $newMhsMember->room_id = $newRoom->id;
                $newMhsMember->user_id = $mahasiswaUser->id;
                $newMhsMember->role = 'mahasiswa';
                $newMhsMember->is_initiator = false;
                $newMhsMember->is_active = false;
                $newMhsMember->joined_at_epoch = 0;
                $newMhsMember->save();

                // Dosen Pembimbing: is_initiator = false, is_active = false
                $newDosMember = new BchatRoomMember();
                $newDosMember->setConnection($connection);
                $newDosMember->room_id = $newRoom->id;
                $newDosMember->user_id = $dosenUser->id;
                $newDosMember->role = 'dosen_pembimbing';
                $newDosMember->is_initiator = false;
                $newDosMember->is_active = false;
                $newDosMember->joined_at_epoch = 0;
                $newDosMember->save();
            }

            // 7. Mutasi Status Outbox Menjadi COMPLETED
            $completedNow = Carbon::now('UTC');
            $outbox->status = 'COMPLETED';
            $outbox->completed_at = $completedNow;
            $outbox->locked_at = null;
            $outbox->locked_by = null;
            $outbox->last_error_code = null;
            $outbox->last_error = null;
            $outbox->updated_at = $completedNow;
            $outbox->save();

            // 8. Lease Check #2 (Pre-Commit T2 — Operasi Terakhir Sebelum Commit)
            $preCommitNowUtc = Carbon::now('UTC');
            $elapsedFinal = $lockedAtUtc->diffInMicroseconds($preCommitNowUtc, false);
            if ($elapsedFinal < 0 || $elapsedFinal > 30_000_000) {
                DB::connection($connection)->rollBack();
                $this->handleTechnicalFailure(
                    $claimedOutbox->id,
                    $expectedToken,
                    'LEASE_EXPIRED_PRE_COMMIT',
                    'Worker lease expired before commit in T2: ' . $elapsedFinal . ' us',
                    $connection
                );
                return false;
            }

            // 9. Commit T2
            DB::connection($connection)->commit();
            return true;

        } catch (Throwable $e) {
            try {
                DB::connection($connection)->rollBack();
            } catch (Throwable) {
                // Abaikan exception rollback jika koneksi terputus
            }

            $errorCode = $this->classifyTechnicalErrorCode($e);
            $this->handleTechnicalFailure(
                $claimedOutbox->id,
                $expectedToken,
                $errorCode,
                $e->getMessage(),
                $connection
            );
            return false;
        }
    }

    /**
     * Penanganan Kondisi A: Kegagalan Bisnis Permanen.
     *
     * Berjalan di dalam transaksi T2 yang masih memegang lock outbox.
     * Mengubah outbox menjadi FAILED, mengisi failed_at & error, membersihkan lock,
     * lalu langsung commit T2 secara atomik (tanpa menambah attempts).
     */
    protected function failTerminalConditionA(
        BchatProvisioningOutbox $outbox,
        string $errorCode,
        string $errorMessage,
        string $connection
    ): bool {
        $now = Carbon::now('UTC');
        $outbox->status = 'FAILED';
        $outbox->failed_at = $now;
        $outbox->last_error_code = $errorCode;
        $outbox->last_error = Str::limit($errorMessage, 1000);
        $outbox->locked_at = null;
        $outbox->locked_by = null;
        $outbox->updated_at = $now;
        $outbox->save();

        DB::connection($connection)->commit();
        return false;
    }

    /**
     * Penanganan Kondisi B: Kegagalan Teknis / Lease Expired / locked_at NULL.
     *
     * Berjalan di dalam transaksi baru (T_FAIL) terpisah setelah T2 di-rollback.
     * Mengunci kembali baris outbox, memvalidasi fencing token & worker identity,
     * menaikkan attempts, menerapkan backoff terkunci, atau mengubah menjadi FAILED jika attempts >= 6.
     */
    public function handleTechnicalFailure(
        int $outboxId,
        int $expectedToken,
        string $errorCode,
        string $errorMessage,
        ?string $connectionName = null
    ): void {
        $connection = $connectionName ?: config('database.default');

        try {
            DB::connection($connection)->transaction(function () use ($connection, $outboxId, $expectedToken, $errorCode, $errorMessage) {
                $outbox = BchatProvisioningOutbox::on($connection)
                    ->where('id', $outboxId)
                    ->lockForUpdate()
                    ->first();

                if (! $outbox) {
                    return;
                }

                // Fencing Check di T_FAIL: pastikan worker masih memegang hak recovery
                if (
                    $outbox->status !== 'PROCESSING' ||
                    (int) $outbox->generation_token !== $expectedToken ||
                    $outbox->locked_by !== $this->workerIdentity
                ) {
                    return;
                }

                $newAttempts = $outbox->attempts + 1;
                $now = Carbon::now('UTC');

                if ($newAttempts < 6) {
                    $delay = $this->calculateBackoffDelay($newAttempts);
                    $outbox->status = 'PENDING';
                    $outbox->attempts = $newAttempts;
                    $outbox->next_attempt_at = $delay ? $now->copy()->addSeconds($delay) : null;
                    $outbox->locked_at = null;
                    $outbox->locked_by = null;
                    $outbox->last_error_code = $errorCode;
                    $outbox->last_error = Str::limit($errorMessage, 1000);
                    $outbox->generation_token = $outbox->generation_token + 1;
                    $outbox->updated_at = $now;
                } else {
                    $outbox->status = 'FAILED';
                    $outbox->attempts = $newAttempts;
                    $outbox->failed_at = $now;
                    $outbox->next_attempt_at = null;
                    $outbox->locked_at = null;
                    $outbox->locked_by = null;
                    $outbox->last_error_code = $errorCode ?: 'MAX_ATTEMPTS_EXHAUSTED';
                    $outbox->last_error = Str::limit($errorMessage, 1000);
                    $outbox->generation_token = $outbox->generation_token + 1;
                    $outbox->updated_at = $now;
                }

                $outbox->save();
            });
        } catch (Throwable) {
            // Kegagalan pada T_FAIL diserahkan kepada Reaper
        }
    }

    /**
     * Rutinitas Reaper: Memulihkan event PROCESSING yang macet (> 60 detik).
     *
     * Menggunakan 'FOR UPDATE SKIP LOCKED', menghitung retry berdasarkan attempts setelah inkremen,
     * menerapkan backoff 10/20/40/80/160s, atau menandai FAILED pada percobaan ke-6.
     * Reaper TIDAK BISA mengambil baris yang masih dikunci oleh transaksi T2 aktif.
     */
    public function reapStalledEvents(?string $connectionName = null): int
    {
        $connection = $connectionName ?: config('database.default');

        return DB::connection($connection)->transaction(function () use ($connection) {
            $thresholdUtc = Carbon::now('UTC')->subSeconds(60)->format('Y-m-d H:i:s.u');

            $stalledEvents = BchatProvisioningOutbox::on($connection)
                ->where('status', 'PROCESSING')
                ->where('event_type', 'PROPOSAL_ROOM_PROVISION')
                ->where('locked_at', '<=', $thresholdUtc)
                ->orderBy('locked_at', 'asc')
                ->limit(10)
                ->lock('for update skip locked')
                ->get();

            $reapedCount = 0;

            foreach ($stalledEvents as $row) {
                $newAttempts = $row->attempts + 1;
                $now = Carbon::now('UTC');

                if ($newAttempts < 6) {
                    $delay = $this->calculateBackoffDelay($newAttempts);
                    $row->status = 'PENDING';
                    $row->attempts = $newAttempts;
                    $row->generation_token = $row->generation_token + 1;
                    $row->next_attempt_at = $delay ? $now->copy()->addSeconds($delay) : null;
                    $row->locked_at = null;
                    $row->locked_by = null;
                    $row->last_error_code = 'LEASE_TIMEOUT';
                    $row->last_error = 'Worker lease expired after 60s reaper threshold';
                    $row->updated_at = $now;
                } else {
                    $row->status = 'FAILED';
                    $row->attempts = $newAttempts;
                    $row->generation_token = $row->generation_token + 1;
                    $row->failed_at = $now;
                    $row->next_attempt_at = null;
                    $row->locked_at = null;
                    $row->locked_by = null;
                    $row->last_error_code = 'LEASE_TIMEOUT_EXHAUSTED';
                    $row->last_error = 'Maximum retry attempts reached via reaper timeouts';
                    $row->updated_at = $now;
                }

                $row->save();
                $reapedCount++;
            }

            return $reapedCount;
        });
    }

    /**
     * Memproses satu event outbox lengkap (T1 Claim -> T2 Provisioning).
     *
     * @return bool|null True jika sukses, False jika gagal, Null jika tidak ada event eligible.
     */
    public function processNext(?string $connectionName = null): ?bool
    {
        $claimed = $this->claimNextPendingEvent($connectionName);

        if (! $claimed) {
            return null;
        }

        return $this->processClaimedEvent(
            $claimed,
            (int) $claimed->generation_token,
            $connectionName
        );
    }

    /**
     * Klasifikasi error code teknis berdasarkan jenis Throwable.
     */
    protected function classifyTechnicalErrorCode(Throwable $e): string
    {
        if ($e instanceof QueryException) {
            $errorCode = $e->errorInfo[1] ?? 0;
            if ($errorCode === 1213) {
                return 'DEADLOCK';
            }
            if ($errorCode === 1205) {
                return 'LOCK_WAIT_TIMEOUT';
            }
            return 'QUERY_EXCEPTION';
        }

        if ($e instanceof PDOException) {
            return 'DATABASE_ERROR';
        }

        return 'TECHNICAL_FAILURE';
    }
}
