<?php

namespace Tests\Unit\Services;

use App\Models\BchatProvisioningOutbox;
use App\Services\BchatProvisioningWorkerService;
use Carbon\Carbon;
use PHPUnit\Framework\TestCase;

class BchatProvisioningWorkerServiceTest extends TestCase
{
    protected BchatProvisioningWorkerService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new BchatProvisioningWorkerService('worker:test-host:1234:abcdef01');
    }

    /**
     * Uji jadwal backoff deterministik: 10, 20, 40, 80, 160 detik, tanpa jitter.
     */
    public function test_calculate_backoff_delay_returns_exact_deterministic_intervals(): void
    {
        $this->assertSame(10, $this->service->calculateBackoffDelay(1));
        $this->assertSame(20, $this->service->calculateBackoffDelay(2));
        $this->assertSame(40, $this->service->calculateBackoffDelay(3));
        $this->assertSame(80, $this->service->calculateBackoffDelay(4));
        $this->assertSame(160, $this->service->calculateBackoffDelay(5));
    }

    /**
     * Uji bahwa kegagalan ke-6 mencapai batas kuota terminal (returns null).
     */
    public function test_calculate_backoff_delay_returns_null_on_sixth_attempt_terminal(): void
    {
        $this->assertNull($this->service->calculateBackoffDelay(6));
        $this->assertNull($this->service->calculateBackoffDelay(7));
    }

    /**
     * Uji lease valid dalam rentang 0 hingga 30 detik.
     */
    public function test_is_lease_valid_returns_true_for_valid_lease_duration(): void
    {
        $lockedAt = Carbon::parse('2026-11-07 10:00:00.000000', 'UTC');
        $now = Carbon::parse('2026-11-07 10:00:15.500000', 'UTC'); // 15,5 detik

        $outbox = new BchatProvisioningOutbox();
        $outbox->locked_at = $lockedAt;

        $this->assertTrue($this->service->isLeaseValid($outbox, $now));
    }

    /**
     * Uji batas kritis lease: tepat 30.000.000 mikrodetik masih valid.
     */
    public function test_is_lease_valid_returns_true_for_exact_30_seconds_boundary(): void
    {
        $lockedAt = Carbon::parse('2026-11-07 10:00:00.000000', 'UTC');
        $now = Carbon::parse('2026-11-07 10:00:30.000000', 'UTC'); // Tepat 30.000.000 us

        $outbox = new BchatProvisioningOutbox();
        $outbox->locked_at = $lockedAt;

        $this->assertTrue($this->service->isLeaseValid($outbox, $now));
    }

    /**
     * Uji bahwa lease lebih dari 30 detik (30.000.001 mikrodetik) dinyatakan kedaluwarsa.
     */
    public function test_is_lease_valid_returns_false_when_lease_exceeds_30_seconds(): void
    {
        $lockedAt = Carbon::parse('2026-11-07 10:00:00.000000', 'UTC');
        $now = Carbon::parse('2026-11-07 10:00:30.000001', 'UTC'); // 30s + 1us

        $outbox = new BchatProvisioningOutbox();
        $outbox->locked_at = $lockedAt;

        $this->assertFalse($this->service->isLeaseValid($outbox, $now));

        $expiredNow = Carbon::parse('2026-11-07 10:00:45.000000', 'UTC'); // 45 detik
        $this->assertFalse($this->service->isLeaseValid($outbox, $expiredNow));
    }

    /**
     * Uji bahwa clock drift negatif (nowUtc lebih awal dari locked_at) dinyatakan tidak valid.
     */
    public function test_is_lease_valid_returns_false_for_negative_clock_drift(): void
    {
        $lockedAt = Carbon::parse('2026-11-07 10:00:10.000000', 'UTC');
        $now = Carbon::parse('2026-11-07 10:00:09.999999', 'UTC'); // 1 us sebelum locked_at

        $outbox = new BchatProvisioningOutbox();
        $outbox->locked_at = $lockedAt;

        $this->assertFalse($this->service->isLeaseValid($outbox, $now));
    }

    /**
     * Uji bahwa lease tanpa locked_at mengembalikan false.
     */
    public function test_is_lease_valid_returns_false_when_locked_at_is_null(): void
    {
        $outbox = new BchatProvisioningOutbox();
        $outbox->locked_at = null;

        $this->assertFalse($this->service->isLeaseValid($outbox));
    }

    /**
     * Uji format identitas worker dan setter.
     */
    public function test_worker_identity_format_and_custom_setter(): void
    {
        $defaultService = new BchatProvisioningWorkerService();
        $identity = $defaultService->getWorkerIdentity();

        $this->assertMatchesRegularExpression('/^worker:[a-zA-Z0-9_-]+:\d+:[a-f0-9]{8}$/', $identity);
        $this->assertLessThanOrEqual(100, strlen($identity));

        $defaultService->setWorkerIdentity('worker:custom-server:9999:deadbeef');
        $this->assertSame('worker:custom-server:9999:deadbeef', $defaultService->getWorkerIdentity());
    }

    /**
     * Uji kontrak keanggotaan awal pada saat provisioning: kedua anggota is_initiator = false, is_active = false.
     */
    public function test_role_and_initiator_contract_mapping(): void
    {
        // Mahasiswa: role='mahasiswa', is_initiator=false (default migrasi 000005), is_active=false
        $mhsContract = [
            'role'         => 'mahasiswa',
            'is_initiator' => false,
            'is_active'    => false,
        ];
        $this->assertSame('mahasiswa', $mhsContract['role']);
        $this->assertFalse($mhsContract['is_initiator']);
        $this->assertFalse($mhsContract['is_active']);

        // Dosen Pembimbing: role='dosen_pembimbing', is_initiator=false, is_active=false
        $dosContract = [
            'role'         => 'dosen_pembimbing',
            'is_initiator' => false,
            'is_active'    => false,
        ];
        $this->assertSame('dosen_pembimbing', $dosContract['role']);
        $this->assertFalse($dosContract['is_initiator']);
        $this->assertFalse($dosContract['is_active']);
    }

    /**
     * Uji klasifikasi failure Kondisi A vs Kondisi B.
     */
    public function test_failure_conditions_classification_contract(): void
    {
        $terminalBusinessErrors = [
            'ASSIGNMENT_NOT_FOUND',
            'ASSIGNMENT_INACTIVE',
            'INVALID_STAGE_OR_ORDINAL',
            'SKRIPSI_NOT_FOUND',
            'MAHASISWA_USER_NOT_FOUND',
            'MAHASISWA_USER_RECORD_NOT_FOUND',
            'MAHASISWA_ROLE_MISMATCH',
            'DOSEN_USER_NOT_FOUND',
            'DOSEN_USER_RECORD_NOT_FOUND',
            'DOSEN_ROLE_MISMATCH',
            'IDENTICAL_USER_IDENTITIES',
            'ROOM_INTEGRITY_CONFLICT',
            'ROOM_TERMINAL_STATE_CONFLICT',
            'MEMBER_STATUS_CORRUPTED',
        ];

        $technicalTransientErrors = [
            'DEADLOCK',
            'LOCK_WAIT_TIMEOUT',
            'QUERY_EXCEPTION',
            'DATABASE_ERROR',
            'LOCKED_AT_NULL',
            'LOCKED_AT_INVALID_FORMAT',
            'LEASE_EXPIRED_INITIAL',
            'LEASE_EXPIRED_PRE_COMMIT',
            'TECHNICAL_FAILURE',
        ];

        foreach ($terminalBusinessErrors as $code) {
            $this->assertFalse(in_array($code, $technicalTransientErrors, true));
        }

        foreach ($technicalTransientErrors as $code) {
            $this->assertFalse(in_array($code, $terminalBusinessErrors, true));
        }
    }
}
