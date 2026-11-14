<?php

namespace App\Services;

use App\Models\BchatProvisioningOutbox;
use App\Models\PenugasanPembimbing;
use DomainException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use InvalidArgumentException;
use LogicException;

class BchatProvisioningOutboxService
{
    /**
     * Catat event transactional outbox untuk provisioning room E2EE bimbingan proposal.
     *
     * Wajib dieksekusi di dalam transaksi database akademik yang aktif.
     * Idempoten: Pemanggilan berulang pada penugasan yang sama akan mengembalikan event yang sudah ada,
     * kecuali jika event berstatus FAILED (di mana rekonsiliasi manual/replay administratif diwajibkan).
     *
     * @param  PenugasanPembimbing  $penugasan
     * @return BchatProvisioningOutbox
     *
     * @throws LogicException
     * @throws InvalidArgumentException
     * @throws DomainException
     * @throws QueryException
     */
    public function recordProposalProvisioningEvent(PenugasanPembimbing $penugasan): BchatProvisioningOutbox
    {
        // 1. Guard Transaksi Aktif & Koneksi Database
        $this->assertActiveTransactionOnSameConnection($penugasan);

        // 2. Domain Invariant Guards
        $this->assertValidProposalAssignment($penugasan);

        // 3. Pre-check Idempotensi Berdasarkan Relasi Unik (penugasan_pembimbing_id, event_type)
        // Gunakan locking/current read untuk membaca data paling mutakhir di level REPEATABLE READ
        $existingEvent = BchatProvisioningOutbox::on($penugasan->getConnectionName())
            ->where('penugasan_pembimbing_id', $penugasan->id)
            ->where('event_type', 'PROPOSAL_ROOM_PROVISION')
            ->lockForUpdate()
            ->first();

        if ($existingEvent) {
            return $this->handleExistingEvent($existingEvent);
        }

        // 4. Susun Payload Minimal (Reference Architecture)
        $payload = [
            'schema_version' => 1,
        ];

        // 5. Buat UUID Event Menggunakan CSPRNG (RFC 4122 v4)
        $eventUuid = (string) Str::uuid();

        // 6. Persistensi Event Outbox ke Database
        try {
            return BchatProvisioningOutbox::on($penugasan->getConnectionName())->create([
                'event_uuid'              => $eventUuid,
                'penugasan_pembimbing_id' => $penugasan->id,
                'event_type'              => 'PROPOSAL_ROOM_PROVISION',
                'payload'                 => $payload,
                'status'                  => 'PENDING',
                'attempts'                => 0,
                'generation_token'        => 1,
            ]);
        } catch (QueryException $e) {
            // 7. Penanganan Concurrency Duplicate Key Hanya untuk Constraint uk_outbox_penugasan_event
            if ($this->isAssignmentEventDuplicateKeyViolation($e)) {
                // Locking/current read untuk membaca state terkini committed row (menghindari snapshot usang pada REPEATABLE READ)
                $concurrentEvent = BchatProvisioningOutbox::on($penugasan->getConnectionName())
                    ->where('penugasan_pembimbing_id', $penugasan->id)
                    ->where('event_type', 'PROPOSAL_ROOM_PROVISION')
                    ->lockForUpdate()
                    ->first();

                if ($concurrentEvent) {
                    return $this->handleExistingEvent($concurrentEvent);
                }

                // Jika event tidak ditemukan (misalnya transaksi paralel pemenang melakukan rollback),
                // lempar exception kegagalan integritas konkurensi (jangan mengembalikan event palsu/kosong)
                throw new DomainException(
                    "Terdeteksi duplikasi antrean outbox pada penugasan [{$penugasan->id}], namun baris pemenang tidak dapat dimuat melalui locking read.",
                    409,
                    $e
                );
            }

            // Lempar kembali seluruh integrity conflict lain (UUID conflict, FK violation, dll)
            throw $e;
        }
    }

    /**
     * Pastikan transaksi aktif berjalan pada koneksi database yang sama dengan penugasan dan outbox.
     *
     * @param  PenugasanPembimbing  $penugasan
     * @throws LogicException
     */
    protected function assertActiveTransactionOnSameConnection(PenugasanPembimbing $penugasan): void
    {
        $penugasanConnection = $penugasan->getConnection();
        $outboxModel = new BchatProvisioningOutbox();
        $outboxConnection = $outboxModel->getConnection();

        // 1. Validasi kedua model terikat ke koneksi yang sama
        if ($penugasanConnection->getName() !== $outboxConnection->getName()) {
            throw new LogicException(
                "Inkonsistensi koneksi: PenugasanPembimbing menggunakan koneksi [{$penugasanConnection->getName()}], " .
                "sedangkan BchatProvisioningOutbox menggunakan koneksi [{$outboxConnection->getName()}]."
            );
        }

        // 2. Validasi koneksi transaksi default DB juga identik dengan koneksi penugasan
        $defaultConnection = \Illuminate\Support\Facades\DB::connection();
        if ($defaultConnection->getName() !== $penugasanConnection->getName()) {
            throw new LogicException(
                "Inkonsistensi koneksi: Transaksi DB::connection() berada pada [{$defaultConnection->getName()}], " .
                "sedangkan model penugasan berada pada koneksi [{$penugasanConnection->getName()}]."
            );
        }

        // 3. Validasi level transaksi aktif (> 0)
        if ($penugasanConnection->transactionLevel() === 0) {
            throw new LogicException(
                "Pencatatan event outbox provisioning wajib dieksekusi di dalam transaksi database akademik yang aktif pada koneksi [{$penugasanConnection->getName()}]."
            );
        }
    }

    /**
     * Validasi domain invariant penugasan pembimbing proposal.
     *
     * @param  PenugasanPembimbing  $penugasan
     * @throws InvalidArgumentException
     */
    protected function assertValidProposalAssignment(PenugasanPembimbing $penugasan): void
    {
        if (!$penugasan->exists || empty($penugasan->id)) {
            throw new InvalidArgumentException('Penugasan pembimbing belum disimpan ke database.');
        }

        if ($penugasan->tahap !== 'proposal') {
            throw new InvalidArgumentException("Penugasan pembimbing bukan tahap proposal (tahap saat ini: [{$penugasan->tahap}]).");
        }

        if ((int) $penugasan->pembimbing_ke !== 1) {
            throw new InvalidArgumentException("Nomor pembimbing proposal wajib bernilai 1 (nilai saat ini: [{$penugasan->pembimbing_ke}]).");
        }

        if ($penugasan->status_penugasan !== 'AKTIF') {
            throw new InvalidArgumentException("Penugasan pembimbing harus berstatus AKTIF (status saat ini: [{$penugasan->status_penugasan}]).");
        }

        if (empty($penugasan->skripsi_id) || empty($penugasan->dosen_id)) {
            throw new InvalidArgumentException('Penugasan pembimbing tidak memiliki relasi skripsi atau dosen yang valid.');
        }
    }

    /**
     * Tangani event outbox yang sudah ada berdasarkan lifecycle statusnya.
     *
     * @param  BchatProvisioningOutbox  $existingEvent
     * @return BchatProvisioningOutbox
     * @throws DomainException
     */
    protected function handleExistingEvent(BchatProvisioningOutbox $existingEvent): BchatProvisioningOutbox
    {
        switch ($existingEvent->status) {
            case 'PENDING':
            case 'PROCESSING':
            case 'COMPLETED':
                Log::info("Event outbox provisioning proposal sudah ada dengan status [{$existingEvent->status}] untuk penugasan [{$existingEvent->penugasan_pembimbing_id}].", [
                    'outbox_id'   => $existingEvent->id,
                    'event_uuid'  => $existingEvent->event_uuid,
                    'status'      => $existingEvent->status,
                ]);
                return $existingEvent;

            case 'FAILED':
                throw new DomainException(
                    "Event provisioning proposal untuk penugasan [{$existingEvent->penugasan_pembimbing_id}] berstatus FAILED. " .
                    "Pembuatan ulang otomatis dilarang; intervensi replay administratif diperlukan.",
                    409
                );

            default:
                throw new DomainException(
                    "Event provisioning proposal untuk penugasan [{$existingEvent->penugasan_pembimbing_id}] memiliki status tidak dikenal [{$existingEvent->status}].",
                    500
                );
        }
    }

    /**
     * Periksa apakah QueryException merupakan pelanggaran duplicate key khusus pada uk_outbox_penugasan_event.
     *
     * Persyaratan ketat:
     * - Hanya MySQL error code 1062 (ER_DUP_ENTRY) yang diakui.
     * - SQLSTATE 23000 saja TIDAK boleh dianggap sebagai duplicate key tanpa error code 1062.
     * - Nama constraint dalam pesan error wajib mengandung 'uk_outbox_penugasan_event'.
     *
     * @param  QueryException  $e
     * @return bool
     */
    protected function isAssignmentEventDuplicateKeyViolation(QueryException $e): bool
    {
        $errorCode = (int) ($e->errorInfo[1] ?? 0);

        // Hanya MySQL error code 1062 (ER_DUP_ENTRY) yang diakui sebagai duplicate key
        if ($errorCode !== 1062) {
            return false;
        }

        $message = $e->getMessage();

        // Pastikan nama constraint yang dilanggar spesifik uk_outbox_penugasan_event
        return str_contains($message, 'uk_outbox_penugasan_event')
            || str_contains($message, "'uk_outbox_penugasan_event'")
            || str_contains($message, 'bchat_provisioning_outbox.uk_outbox_penugasan_event');
    }
}

