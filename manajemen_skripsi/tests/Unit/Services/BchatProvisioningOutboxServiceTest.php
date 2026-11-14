<?php

namespace Tests\Unit\Services;

use App\Models\BchatProvisioningOutbox;
use App\Models\PenugasanPembimbing;
use App\Services\BchatProvisioningOutboxService;
use DomainException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use LogicException;
use Tests\TestCase;

class BchatProvisioningOutboxServiceTest extends TestCase
{
    private BchatProvisioningOutboxService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new BchatProvisioningOutboxService();
    }

    /**
     * Service wajib menolak pemanggilan jika tidak berada dalam transaksi database aktif.
     */
    public function test_menolak_pemanggilan_tanpa_transaksi_aktif(): void
    {
        $penugasan = new PenugasanPembimbing();
        $penugasan->id = 1;
        $penugasan->exists = true;
        $penugasan->tahap = 'proposal';
        $penugasan->pembimbing_ke = 1;
        $penugasan->status_penugasan = 'AKTIF';
        $penugasan->skripsi_id = 10;
        $penugasan->dosen_id = 5;

        $this->assertEquals(0, DB::transactionLevel());

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Pencatatan event outbox provisioning wajib dieksekusi di dalam transaksi database akademik yang aktif');

        $this->service->recordProposalProvisioningEvent($penugasan);
    }

    /**
     * Service menolak penugasan yang belum tersimpan di database ($exists = false).
     */
    public function test_menolak_penugasan_yang_belum_tersimpan(): void
    {
        $penugasan = new PenugasanPembimbing();
        $penugasan->id = null;
        $penugasan->exists = false;

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Penugasan pembimbing belum disimpan ke database.');

        DB::transaction(function () use ($penugasan) {
            $this->service->recordProposalProvisioningEvent($penugasan);
        });
    }

    /**
     * Service menolak penugasan dengan tahap selain proposal.
     */
    public function test_menolak_tahap_selain_proposal(): void
    {
        $penugasan = new PenugasanPembimbing();
        $penugasan->id = 1;
        $penugasan->exists = true;
        $penugasan->tahap = 'skripsi';
        $penugasan->pembimbing_ke = 1;
        $penugasan->status_penugasan = 'AKTIF';
        $penugasan->skripsi_id = 10;
        $penugasan->dosen_id = 5;

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Penugasan pembimbing bukan tahap proposal');

        DB::transaction(function () use ($penugasan) {
            $this->service->recordProposalProvisioningEvent($penugasan);
        });
    }

    /**
     * Service menolak penugasan dengan nomor pembimbing selain 1.
     */
    public function test_menolak_nomor_pembimbing_bukan_1(): void
    {
        $penugasan = new PenugasanPembimbing();
        $penugasan->id = 1;
        $penugasan->exists = true;
        $penugasan->tahap = 'proposal';
        $penugasan->pembimbing_ke = 2;
        $penugasan->status_penugasan = 'AKTIF';
        $penugasan->skripsi_id = 10;
        $penugasan->dosen_id = 5;

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Nomor pembimbing proposal wajib bernilai 1');

        DB::transaction(function () use ($penugasan) {
            $this->service->recordProposalProvisioningEvent($penugasan);
        });
    }

    /**
     * Service menolak penugasan dengan status selain AKTIF.
     */
    public function test_menolak_status_penugasan_bukan_aktif(): void
    {
        $penugasan = new PenugasanPembimbing();
        $penugasan->id = 1;
        $penugasan->exists = true;
        $penugasan->tahap = 'proposal';
        $penugasan->pembimbing_ke = 1;
        $penugasan->status_penugasan = 'DIGANTI';
        $penugasan->skripsi_id = 10;
        $penugasan->dosen_id = 5;

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Penugasan pembimbing harus berstatus AKTIF');

        DB::transaction(function () use ($penugasan) {
            $this->service->recordProposalProvisioningEvent($penugasan);
        });
    }

    /**
     * Service menolak penugasan tanpa skripsi_id atau dosen_id.
     */
    public function test_menolak_penugasan_tanpa_skripsi_atau_dosen(): void
    {
        $penugasan = new PenugasanPembimbing();
        $penugasan->id = 1;
        $penugasan->exists = true;
        $penugasan->tahap = 'proposal';
        $penugasan->pembimbing_ke = 1;
        $penugasan->status_penugasan = 'AKTIF';
        $penugasan->skripsi_id = null;
        $penugasan->dosen_id = 5;

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Penugasan pembimbing tidak memiliki relasi skripsi atau dosen yang valid.');

        DB::transaction(function () use ($penugasan) {
            $this->service->recordProposalProvisioningEvent($penugasan);
        });
    }

    /**
     * Helper deteksi pelanggaran constraint unik hanya menyetujui uk_outbox_penugasan_event.
     */
    public function test_deteksi_hanya_menoleransi_constraint_penugasan_event(): void
    {
        $reflection = new \ReflectionMethod(BchatProvisioningOutboxService::class, 'isAssignmentEventDuplicateKeyViolation');
        $reflection->setAccessible(true);

        // Kasus 1: Duplicate key pada uk_outbox_penugasan_event (sah untuk idempotensi concurrency)
        $pdoException1 = new \PDOException("Duplicate entry '10-PROPOSAL_ROOM_PROVISION' for key 'bchat_provisioning_outbox.uk_outbox_penugasan_event'", 23000);
        $pdoException1->errorInfo = ['23000', 1062, "Duplicate entry '10-PROPOSAL_ROOM_PROVISION' for key 'bchat_provisioning_outbox.uk_outbox_penugasan_event'"];
        $queryException1 = new QueryException('mysql', 'INSERT INTO bchat_provisioning_outbox ...', [], $pdoException1);

        $this->assertTrue($reflection->invoke($this->service, $queryException1));

        // Kasus 2: Duplicate key pada uk_outbox_event_uuid (konflik integritas fatal, tidak boleh ditoleransi)
        $pdoException2 = new \PDOException("Duplicate entry 'uuid-123' for key 'bchat_provisioning_outbox.uk_outbox_event_uuid'", 23000);
        $pdoException2->errorInfo = ['23000', 1062, "Duplicate entry 'uuid-123' for key 'bchat_provisioning_outbox.uk_outbox_event_uuid'"];
        $queryException2 = new QueryException('mysql', 'INSERT INTO bchat_provisioning_outbox ...', [], $pdoException2);

        $this->assertFalse($reflection->invoke($this->service, $queryException2));

        // Kasus 3: Foreign key violation 1452 (tidak boleh ditoleransi)
        $pdoException3 = new \PDOException("Cannot add or update a child row: a foreign key constraint fails", 23000);
        $pdoException3->errorInfo = ['23000', 1452, "Cannot add or update a child row: a foreign key constraint fails"];
        $queryException3 = new QueryException('mysql', 'INSERT INTO bchat_provisioning_outbox ...', [], $pdoException3);

        $this->assertFalse($reflection->invoke($this->service, $queryException3));

        // Kasus 4: SQLSTATE 23000 tanpa MySQL error code 1062 (tidak boleh ditoleransi)
        $pdoException4 = new \PDOException("Integrity constraint violation: 19 UNIQUE constraint failed: uk_outbox_penugasan_event", 23000);
        $pdoException4->errorInfo = ['23000', 19, "Integrity constraint violation: 19 UNIQUE constraint failed: uk_outbox_penugasan_event"];
        $queryException4 = new QueryException('mysql', 'INSERT INTO bchat_provisioning_outbox ...', [], $pdoException4);

        $this->assertFalse($reflection->invoke($this->service, $queryException4));
    }
}

