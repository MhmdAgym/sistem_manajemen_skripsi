<?php

declare(strict_types=1);

namespace Tests\Feature\Bchat;

use App\Models\BchatKeyPackage;
use App\Models\BchatRegistrationNonce;
use App\Models\BchatStepUpToken;
use App\Models\BchatUserDevice;
use App\Models\BchatWelcomeChallenge;
use App\Models\User;
use App\Services\BchatPopVerificationService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * B-CHAT-22B Phase 5B.3C - Multi-Process Concurrency Test Suite.
 *
 * Menguji perilaku race conditions, penguncian hierarkis pesimistik,
 * konsistensi slot perangkat tunggal, serialisasi Managed Replacement,
 * lock wait timeout (1205), dan pembuktian deadlock murni InnoDB (1213)
 * menggunakan proses PHP CLI terisolasi secara nyata di sistem operasi Windows.
 */
class BchatDeviceConcurrencyTest extends TestCase
{
    /**
     * Manifest ID fixture yang dicatat secara eksplisit untuk menjamin
     * cleanup terarah dari level terbawah ke level teratas (bottom-up).
     *
     * @var array<string, array<int, int>>
     */
    protected array $manifest = [
        'users'  => [],
        'nonces' => [],
    ];

    /**
     * Daftar proses Symfony aktif untuk penanganan graceful termination.
     *
     * @var array<int, Process>
     */
    protected array $activeProcesses = [];

    /**
     * Daftar direktori barrier sementara yang dialokasikan dalam test run.
     *
     * @var array<int, string>
     */
    protected array $barrierDirs = [];

    protected function setUp(): void
    {
        parent::setUp();

        $activeDb = DB::connection()->getDatabaseName();
        $this->assertEquals(
            'manajemen_skripsi_test',
            $activeDb,
            'CRITICAL SECURITY: Test suite Concurrency B-CHAT HANYA boleh berjalan pada database manajemen_skripsi_test.'
        );

        $this->manifest = [
            'users'  => [],
            'nonces' => [],
        ];
        $this->activeProcesses = [];
        $this->barrierDirs = [];
    }

    protected function tearDown(): void
    {
        // 1. Hentikan seluruh proses child yang masih aktif sebelum cleanup
        $this->stopActiveProcesses();

        // 2. Bersihkan direktori barrier sementara
        foreach ($this->barrierDirs as $dir) {
            if (file_exists($dir)) {
                File::deleteDirectory($dir);
            }
        }
        $this->barrierDirs = [];

        // 3. Jalankan pembersihan database bottom-up berdasarkan manifest fixture ID
        try {
            $this->performBottomUpCleanup();
        } finally {
            parent::tearDown();
        }
    }

    /**
     * Menghentikan seluruh child process yang masih aktif secara paksa dan aman.
     */
    protected function stopActiveProcesses(): void
    {
        foreach ($this->activeProcesses as $process) {
            if ($process instanceof Process && $process->isRunning()) {
                $process->stop(1.0);
            }
        }
        $this->activeProcesses = [];
    }

    /**
     * Mempublikasikan file secara atomik melalui temporary file dan rename().
     * Menggunakan pola fail-closed: jika file tujuan sudah ada, publikasi kedua dianggap kesalahan.
     * Tidak pernah melakukan unlink($filePath) sebelum rename().
     */
    protected function atomicWrite(string $filePath, string $content): void
    {
        $dir = dirname($filePath);
        $tmpFile = $dir . DIRECTORY_SEPARATOR . 'tmp_' . uniqid((string) getmypid(), true) . '_' . bin2hex(random_bytes(4)) . '.tmp';

        try {
            $bytes = file_put_contents($tmpFile, $content);
            if ($bytes === false || $bytes !== strlen($content)) {
                throw new RuntimeException("Gagal menulis konten lengkap ke file sementara: {$tmpFile}");
            }

            // Pola fail-closed: jika file tujuan sudah ada, publikasi kedua dianggap kesalahan (overwrite dilarang)
            if (file_exists($filePath)) {
                throw new RuntimeException("Publikasi atomik ditolak: File tujuan '{$filePath}' sudah ada (fail-closed, overwrite dilarang).");
            }

            // Publikasikan secara atomik tanpa menghapus tujuan sebelumnya
            if (!@rename($tmpFile, $filePath)) {
                throw new RuntimeException("Gagal mempublikasikan file secara atomik via rename() ke '{$filePath}'.");
            }
        } catch (\Throwable $e) {
            // Bersihkan HANYA file sementara saat terjadi kegagalan
            if (file_exists($tmpFile)) {
                @unlink($tmpFile);
            }
            throw $e;
        }
    }

    /**
     * Membersihkan fixture data pengujian secara ketat dari daun ke akar (bottom-up).
     */
    protected function performBottomUpCleanup(): void
    {
        if (empty($this->manifest['users'])) {
            return;
        }

        $userIds = array_values(array_unique($this->manifest['users']));

        // Temukan seluruh device milik user manifest
        $deviceIds = BchatUserDevice::whereIn('user_id', $userIds)->pluck('id')->all();

        if (!empty($deviceIds)) {
            // Level 1: Welcome challenges terkait device
            BchatWelcomeChallenge::whereIn('recipient_device_id', $deviceIds)->delete();

            // Level 2: Key packages terkait device
            BchatKeyPackage::whereIn('device_id', $deviceIds)->delete();

            // Level 3: User devices
            BchatUserDevice::whereIn('id', $deviceIds)->delete();
        }

        // Level 4: Registration nonces
        BchatRegistrationNonce::whereIn('user_id', $userIds)->delete();

        // Level 5: Step up tokens
        BchatStepUpToken::whereIn('user_id', $userIds)->delete();

        // Level 6: Users
        User::whereIn('id', $userIds)->delete();
    }

    /**
     * Helper untuk membuat User uji dengan pencatatan eksplisit ke manifest.
     */
    private function createTestUser(string $password = 'RahasiaBchat123!'): User
    {
        $user = User::factory()->create([
            'password' => Hash::make($password),
        ]);

        $this->manifest['users'][] = (int) $user->id;

        return $user;
    }

    /**
     * Helper untuk menghasilkan keypair Ed25519 kriptografis asli.
     *
     * @return array{secret_key: string, public_key: string, public_key_hex: string, fingerprint: string}
     */
    private function generateEd25519Keypair(): array
    {
        $keypair = sodium_crypto_sign_keypair();
        $secretKey = sodium_crypto_sign_secretkey($keypair);
        $publicKey = sodium_crypto_sign_publickey($keypair);

        return [
            'secret_key'     => $secretKey,
            'public_key'     => $publicKey,
            'public_key_hex' => bin2hex($publicKey),
            'fingerprint'    => hash('sha256', $publicKey),
        ];
    }

    /**
     * Helper untuk menerbitkan Registration Nonce valid ke basis data uji.
     */
    private function issueValidNonceForUser(
        User $user,
        string $deviceUuid,
        array $keypair,
        ?int $issuedAt = null
    ): BchatRegistrationNonce {
        $serverIssuedAt = $issuedAt ?? time();

        $nonce = BchatRegistrationNonce::create([
            'user_id'                => $user->id,
            'device_uuid'            => strtolower(trim($deviceUuid)),
            'nonce_uuid'             => (string) Str::uuid(),
            'nonce_value'            => bin2hex(random_bytes(32)),
            'public_key_fingerprint' => $keypair['fingerprint'],
            'status'                 => 'ISSUED',
            'issued_at'              => $serverIssuedAt,
            'expires_at'             => now()->addMinutes(5),
            'consumed_at'            => null,
        ]);

        $this->manifest['nonces'][] = (int) $nonce->id;

        return $nonce;
    }

    /**
     * Helper untuk membangun PoP payload 121-byte dan detached signature 64-byte Ed25519 sah.
     *
     * @return array{payload: string, payload_hex: string, signature: string, signature_hex: string}
     */
    private function createValidPoP(
        int|string $userId,
        string $deviceUuid,
        BchatRegistrationNonce $nonce,
        array $keypair,
        ?int $timestamp = null
    ): array {
        $ts = $timestamp ?? $nonce->issued_at;

        $payload = BchatPopVerificationService::buildPayload(
            userId: $userId,
            deviceUuid: $deviceUuid,
            nonceHexOrBin: $nonce->nonce_value,
            issuedAt: $ts,
            publicKeyHexOrBin: $keypair['public_key_hex']
        );

        $signature = sodium_crypto_sign_detached($payload, $keypair['secret_key']);

        return [
            'payload'       => $payload,
            'payload_hex'   => bin2hex($payload),
            'signature'     => $signature,
            'signature_hex' => bin2hex($signature),
        ];
    }

    /**
     * Menjalankan dua child process secara asynchronous menggunakan file-based barrier.
     *
     * Memeriksa secara ketat:
     * - Timeout eksekusi dan non-premature exit.
     * - Exit code = 0 (worker menangani domain error ke JSON).
     * - File error child_{n}.err.
     * - Struktur JSON result valid.
     *
     * @param string $scenario Nama skenario ('C01', 'C02', 'C03', 'C04', 'C06A', 'C06B1')
     * @param array<string, mixed> $worker1Config Konfigurasi worker 1
     * @param array<string, mixed> $worker2Config Konfigurasi worker 2
     * @param int $timeoutSeconds Batas waktu maksimal eksekusi worker
     * @return array{worker1: array<string, mixed>, worker2: array<string, mixed>, process1: Process, process2: Process}
     */
    protected function runScenarioProcesses(
        string $scenario,
        array $worker1Config,
        array $worker2Config,
        int $timeoutSeconds = 15
    ): array {
        $barrierUuid = (string) Str::uuid();
        $barrierDir = storage_path("framework/testing/barriers/{$barrierUuid}");
        if (!file_exists($barrierDir)) {
            mkdir($barrierDir, 0777, true);
        }
        $this->barrierDirs[] = $barrierDir;

        $config1File = $barrierDir . DIRECTORY_SEPARATOR . 'config_1.json';
        $config2File = $barrierDir . DIRECTORY_SEPARATOR . 'config_2.json';
        $this->atomicWrite($config1File, json_encode($worker1Config, JSON_PRETTY_PRINT));
        $this->atomicWrite($config2File, json_encode($worker2Config, JSON_PRETTY_PRINT));

        $phpBinary = 'D:\\Aplikasi\\Xampp\\php\\php.exe';
        $workerScript = base_path('tests/Feature/Bchat/Support/DeviceConcurrencyWorker.php');

        $process1 = new Process([
            $phpBinary,
            $workerScript,
            '--scenario=' . $scenario,
            '--barrier-dir=' . $barrierDir,
            '--worker-id=1',
            '--config-file=' . $config1File,
        ]);

        $process2 = new Process([
            $phpBinary,
            $workerScript,
            '--scenario=' . $scenario,
            '--barrier-dir=' . $barrierDir,
            '--worker-id=2',
            '--config-file=' . $config2File,
        ]);

        $this->activeProcesses[] = $process1;
        $this->activeProcesses[] = $process2;

        // 1. Jalankan kedua child secara non-blocking / asynchronous
        $process1->start();
        $process2->start();

        $readyFile1 = $barrierDir . DIRECTORY_SEPARATOR . 'child_1.ready';
        $readyFile2 = $barrierDir . DIRECTORY_SEPARATOR . 'child_2.ready';

        // 2. Tunggu kedua worker memberikan sinyal READY (tanpa DB transaction/lock)
        $startWaitReady = microtime(true);
        while ((!file_exists($readyFile1) || !file_exists($readyFile2)) && (microtime(true) - $startWaitReady) < 8.0) {
            if (!$process1->isRunning() && !file_exists($readyFile1)) {
                $this->stopActiveProcesses();
                $errFile1 = $barrierDir . DIRECTORY_SEPARATOR . 'child_1.err';
                $errContent1 = file_exists($errFile1) ? file_get_contents($errFile1) : 'none';
                $this->fail("Child 1 terminated prematurely before READY (Exit: {$process1->getExitCode()}).\nSTDOUT: {$process1->getOutput()}\nSTDERR: {$process1->getErrorOutput()}\nERR FILE: {$errContent1}");
            }
            if (!$process2->isRunning() && !file_exists($readyFile2)) {
                $this->stopActiveProcesses();
                $errFile2 = $barrierDir . DIRECTORY_SEPARATOR . 'child_2.err';
                $errContent2 = file_exists($errFile2) ? file_get_contents($errFile2) : 'none';
                $this->fail("Child 2 terminated prematurely before READY (Exit: {$process2->getExitCode()}).\nSTDOUT: {$process2->getOutput()}\nSTDERR: {$process2->getErrorOutput()}\nERR FILE: {$errContent2}");
            }
            usleep(5000); // 5 ms
        }

        $this->assertFileExists($readyFile1, "Worker 1 gagal memberikan sinyal READY dalam batas waktu.");
        $this->assertFileExists($readyFile2, "Worker 2 gagal memberikan sinyal READY dalam batas waktu.");

        // 3. Lepaskan barrier secara atomik (publikasikan barrier.released via atomicWrite)
        $releaseFile = $barrierDir . DIRECTORY_SEPARATOR . 'barrier.released';
        $this->atomicWrite($releaseFile, json_encode([
            'released_at' => microtime(true),
        ], JSON_PRETTY_PRINT));

        // 4. Tunggu kedua worker menyelesaikan eksekusi mereka dengan timeout guard
        $startWaitTerm = microtime(true);
        $p1TimedOut = false;
        $p2TimedOut = false;
        while (($process1->isRunning() || $process2->isRunning()) && (microtime(true) - $startWaitTerm) < $timeoutSeconds) {
            usleep(10000); // 10 ms
        }

        // Hentikan proses jika masih ada yang menggantung dan tandai flag timeout
        if ($process1->isRunning()) {
            $p1TimedOut = true;
            $process1->stop(1.0);
        }
        if ($process2->isRunning()) {
            $p2TimedOut = true;
            $process2->stop(1.0);
        }

        $p1Stdout = $process1->getOutput();
        $p1Stderr = $process1->getErrorOutput();
        $p1Exit = $process1->getExitCode();

        $p2Stdout = $process2->getOutput();
        $p2Stderr = $process2->getErrorOutput();
        $p2Exit = $process2->getExitCode();

        $errFile1 = $barrierDir . DIRECTORY_SEPARATOR . 'child_1.err';
        $errContent1 = file_exists($errFile1) ? file_get_contents($errFile1) : null;

        $errFile2 = $barrierDir . DIRECTORY_SEPARATOR . 'child_2.err';
        $errContent2 = file_exists($errFile2) ? file_get_contents($errFile2) : null;

        // Pastikan tidak ada proses yang mengalami timeout
        if ($p1TimedOut || $p2TimedOut) {
            $diag = "Skenario {$scenario} mengalami timeout ({$timeoutSeconds}s).\n";
            $diag .= "Child 1 TimedOut=" . ($p1TimedOut ? 'YES' : 'NO') . " Exit={$p1Exit}\nStdout: {$p1Stdout}\nStderr: {$p1Stderr}\nErrFile: {$errContent1}\n";
            $diag .= "Child 2 TimedOut=" . ($p2TimedOut ? 'YES' : 'NO') . " Exit={$p2Exit}\nStdout: {$p2Stdout}\nStderr: {$p2Stderr}\nErrFile: {$errContent2}\n";
            $this->fail($diag);
        }

        // Periksa file error
        if ($errContent1 !== null && trim($errContent1) !== '') {
            $this->fail("Child 1 menghasilkan error file:\n{$errContent1}\nStdout: {$p1Stdout}\nStderr: {$p1Stderr}");
        }
        if ($errContent2 !== null && trim($errContent2) !== '') {
            $this->fail("Child 2 menghasilkan error file:\n{$errContent2}\nStdout: {$p2Stdout}\nStderr: {$p2Stderr}");
        }

        // Periksa exit code kedua child (harus 0 karena worker menangani domain exception)
        if ($p1Exit !== 0) {
            $this->fail("Child 1 exit dengan kode non-zero ({$p1Exit}).\nStdout: {$p1Stdout}\nStderr: {$p1Stderr}");
        }
        if ($p2Exit !== 0) {
            $this->fail("Child 2 exit dengan kode non-zero ({$p2Exit}).\nStdout: {$p2Stdout}\nStderr: {$p2Stderr}");
        }

        // 5. Baca dan validasi hasil eksekusi JSON
        $resultFile1 = $barrierDir . DIRECTORY_SEPARATOR . 'child_1.result.json';
        $resultFile2 = $barrierDir . DIRECTORY_SEPARATOR . 'child_2.result.json';

        $this->assertFileExists($resultFile1, "Child 1 tidak menghasilkan file result JSON.\nStdout: {$p1Stdout}\nStderr: {$p1Stderr}");
        $this->assertFileExists($resultFile2, "Child 2 tidak menghasilkan file result JSON.\nStdout: {$p2Stdout}\nStderr: {$p2Stderr}");

        $result1 = json_decode((string) file_get_contents($resultFile1), true);
        $result2 = json_decode((string) file_get_contents($resultFile2), true);

        $this->assertIsArray($result1, "Child 1 menghasilkan JSON result yang tidak valid.\nRaw: " . file_get_contents($resultFile1));
        $this->assertIsArray($result2, "Child 2 menghasilkan JSON result yang tidak valid.\nRaw: " . file_get_contents($resultFile2));

        $this->assertArrayHasKey('success', $result1, "Result Child 1 tidak memiliki kunci 'success'.");
        $this->assertArrayHasKey('http_status', $result1, "Result Child 1 tidak memiliki kunci 'http_status'.");
        $this->assertArrayHasKey('success', $result2, "Result Child 2 tidak memiliki kunci 'success'.");
        $this->assertArrayHasKey('http_status', $result2, "Result Child 2 tidak memiliki kunci 'http_status'.");

        return [
            'worker1'  => $result1,
            'worker2'  => $result2,
            'process1' => $process1,
            'process2' => $process2,
        ];
    }

    /**
     * Skenario C-01: Dua request konkuren memakai registration nonce yang sama.
     *
     * Ekspektasi:
     * - Tepat satu request SUKSES (HTTP 201 Created), perangkat berstatus ACTIVE.
     * - Tepat satu request DITOLAK (HTTP 403 Forbidden), error code NONCE_ALREADY_CONSUMED.
     * - Kondisi DB: Nonce berstatus CONSUMED, tepat 1 perangkat dibuat.
     */
    public function test_c01_concurrent_same_nonce_competition_produces_one_success_and_one_consumed_rejection(): void
    {
        $user = $this->createTestUser();
        $deviceUuid = (string) Str::uuid();
        $keypair = $this->generateEd25519Keypair();

        $nonce = $this->issueValidNonceForUser($user, $deviceUuid, $keypair);
        $pop = $this->createValidPoP($user->id, $deviceUuid, $nonce, $keypair);

        $worker1Config = [
            'user_id'       => $user->id,
            'nonce_uuid'    => $nonce->nonce_uuid,
            'payload_hex'   => $pop['payload_hex'],
            'signature_hex' => $pop['signature_hex'],
            'device_name'   => 'Device C01 Worker 1',
        ];

        $worker2Config = [
            'user_id'       => $user->id,
            'nonce_uuid'    => $nonce->nonce_uuid,
            'payload_hex'   => $pop['payload_hex'],
            'signature_hex' => $pop['signature_hex'],
            'device_name'   => 'Device C01 Worker 2',
        ];

        $results = $this->runScenarioProcesses('C01', $worker1Config, $worker2Config);

        $w1 = $results['worker1'];
        $w2 = $results['worker2'];

        $this->assertNotNull($w1, 'Worker 1 harus mengembalikan result JSON.');
        $this->assertNotNull($w2, 'Worker 2 harus mengembalikan result JSON.');

        $w1Success = $w1['success'] === true && $w1['http_status'] === 201;
        $w2Success = $w2['success'] === true && $w2['http_status'] === 201;

        // Tepat 1 child berhasil dan tepat 1 child gagal
        $this->assertTrue(
            ($w1Success && !$w2Success) || (!$w1Success && $w2Success),
            'Tepat satu worker harus berhasil (HTTP 201) dan satu worker harus gagal.'
        );

        $loser = $w1Success ? $w2 : $w1;
        $this->assertEquals(403, $loser['http_status'], 'Worker yang kalah harus menerima HTTP 403.');
        $this->assertEquals('NONCE_ALREADY_CONSUMED', $loser['error_code'], 'Error code harus NONCE_ALREADY_CONSUMED.');

        // Verifikasi kondisi akhir basis data
        $nonce->refresh();
        $this->assertEquals('CONSUMED', $nonce->status);
        $this->assertNotNull($nonce->consumed_at);

        $devices = BchatUserDevice::where('user_id', $user->id)->get();
        $this->assertCount(1, $devices, 'Hanya boleh ada 1 perangkat yang berhasil terbuat.');
        $this->assertEquals('ACTIVE', $devices->first()->status);
    }

    /**
     * Skenario C-02: Dua registrasi konkuren pada satu user dengan nonce berbeda.
     *
     * Ekspektasi:
     * - Keduanya terserialisasi oleh lock users.
     * - Child pertama membuat perangkat ACTIVE.
     * - Child kedua mengeksekusi Managed Replacement atomik.
     * - Kondisi DB: Tepat 1 perangkat berstatus ACTIVE, tepat 1 perangkat berstatus REVOKED
     *   dengan revocation_reason = MANAGED_REPLACEMENT.
     */
    public function test_c02_concurrent_dual_registration_on_single_user_maintains_single_active_device_invariant(): void
    {
        $user = $this->createTestUser();

        $deviceUuid1 = (string) Str::uuid();
        $keypair1 = $this->generateEd25519Keypair();
        $nonce1 = $this->issueValidNonceForUser($user, $deviceUuid1, $keypair1);
        $pop1 = $this->createValidPoP($user->id, $deviceUuid1, $nonce1, $keypair1);

        $deviceUuid2 = (string) Str::uuid();
        $keypair2 = $this->generateEd25519Keypair();
        $nonce2 = $this->issueValidNonceForUser($user, $deviceUuid2, $keypair2);
        $pop2 = $this->createValidPoP($user->id, $deviceUuid2, $nonce2, $keypair2);

        $worker1Config = [
            'user_id'       => $user->id,
            'nonce_uuid'    => $nonce1->nonce_uuid,
            'payload_hex'   => $pop1['payload_hex'],
            'signature_hex' => $pop1['signature_hex'],
            'device_name'   => 'Device C02 Worker 1',
        ];

        $worker2Config = [
            'user_id'       => $user->id,
            'nonce_uuid'    => $nonce2->nonce_uuid,
            'payload_hex'   => $pop2['payload_hex'],
            'signature_hex' => $pop2['signature_hex'],
            'device_name'   => 'Device C02 Worker 2',
        ];

        $results = $this->runScenarioProcesses('C02', $worker1Config, $worker2Config);

        $w1 = $results['worker1'];
        $w2 = $results['worker2'];

        $this->assertNotNull($w1, 'Worker 1 harus mengembalikan result JSON.');
        $this->assertNotNull($w2, 'Worker 2 harus mengembalikan result JSON.');

        // Kedua worker harus sukses mendaftar (yang kedua me-revoke yang pertama)
        $this->assertTrue($w1['success'], 'Worker 1 harus berhasil.');
        $this->assertTrue($w2['success'], 'Worker 2 harus berhasil.');

        // Verifikasi invariant Single Active Device di basis data
        $devices = BchatUserDevice::where('user_id', $user->id)->get();
        $this->assertCount(2, $devices, 'Total 2 perangkat harus tercatat di DB.');

        $activeDevices = $devices->where('status', 'ACTIVE');
        $revokedDevices = $devices->where('status', 'REVOKED');

        $this->assertCount(1, $activeDevices, 'Tepat 1 perangkat harus berstatus ACTIVE.');
        $this->assertCount(1, $revokedDevices, 'Tepat 1 perangkat harus berstatus REVOKED.');

        $revoked = $revokedDevices->first();
        $this->assertEquals('MANAGED_REPLACEMENT', $revoked->revocation_reason, 'Alasan pencabutan harus MANAGED_REPLACEMENT.');
        $this->assertNotNull($revoked->revoked_at);
    }

    /**
     * Skenario C-03: Dua user berbeda mencoba mendaftarkan public key Ed25519 yang sama.
     *
     * Ekspektasi:
     * - Tepat satu request SUKSES (HTTP 201), mendaftarkan kunci.
     * - Tepat satu request DITOLAK (HTTP 409 Conflict), error code PUBLIC_KEY_ALREADY_IN_USE.
     * - Transaksi pihak yang kalah di-rollback penuh sehingga nonce pihak yang kalah TETAP berstatus ISSUED.
     */
    public function test_c03_concurrent_same_public_key_by_different_users_produces_one_success_and_one_conflict(): void
    {
        $userA = $this->createTestUser();
        $userB = $this->createTestUser();

        // Satu keypair Ed25519 yang sama dipakai bersaing oleh kedua user
        $sharedKeypair = $this->generateEd25519Keypair();

        $deviceUuidA = (string) Str::uuid();
        $nonceA = $this->issueValidNonceForUser($userA, $deviceUuidA, $sharedKeypair);
        $popA = $this->createValidPoP($userA->id, $deviceUuidA, $nonceA, $sharedKeypair);

        $deviceUuidB = (string) Str::uuid();
        $nonceB = $this->issueValidNonceForUser($userB, $deviceUuidB, $sharedKeypair);
        $popB = $this->createValidPoP($userB->id, $deviceUuidB, $nonceB, $sharedKeypair);

        $worker1Config = [
            'user_id'       => $userA->id,
            'nonce_uuid'    => $nonceA->nonce_uuid,
            'payload_hex'   => $popA['payload_hex'],
            'signature_hex' => $popA['signature_hex'],
            'device_name'   => 'Device C03 User A',
        ];

        $worker2Config = [
            'user_id'       => $userB->id,
            'nonce_uuid'    => $nonceB->nonce_uuid,
            'payload_hex'   => $popB['payload_hex'],
            'signature_hex' => $popB['signature_hex'],
            'device_name'   => 'Device C03 User B',
        ];

        $results = $this->runScenarioProcesses('C03', $worker1Config, $worker2Config);

        $w1 = $results['worker1'];
        $w2 = $results['worker2'];

        $this->assertNotNull($w1, 'Worker 1 harus mengembalikan result JSON.');
        $this->assertNotNull($w2, 'Worker 2 harus mengembalikan result JSON.');

        $w1Success = $w1['success'] === true && $w1['http_status'] === 201;
        $w2Success = $w2['success'] === true && $w2['http_status'] === 201;

        $this->assertTrue(
            ($w1Success && !$w2Success) || (!$w1Success && $w2Success),
            'Tepat satu registrasi harus berhasil dan satu registrasi harus ditolak konflik 409.'
        );

        $loser = $w1Success ? $w2 : $w1;
        $this->assertEquals(409, $loser['http_status'], 'Worker yang kalah harus menerima HTTP 409.');
        $this->assertEquals('PUBLIC_KEY_ALREADY_IN_USE', $loser['error_code'], 'Error code harus PUBLIC_KEY_ALREADY_IN_USE.');

        // Verifikasi bahwa nonce pihak yang kalah TETAP berstatus ISSUED
        $loserUser = $w1Success ? $userB : $userA;
        $loserNonce = $w1Success ? $nonceB : $nonceA;

        $loserNonce->refresh();
        $this->assertEquals(
            'ISSUED',
            $loserNonce->status,
            'Registration nonce milik pihak yang kalah harus tetap berstatus ISSUED karena transaksi di-rollback.'
        );
        $this->assertNull($loserNonce->consumed_at);

        // Verifikasi di basis data hanya ada 1 perangkat terdaftar dengan public key tersebut
        $deviceCount = BchatUserDevice::where('public_key', $sharedKeypair['public_key_hex'])->count();
        $this->assertEquals(1, $deviceCount, 'Hanya boleh ada 1 record perangkat di seluruh tabel dengan public key tersebut.');
    }

    /**
     * Skenario C-04: Serialisasi Managed Replacement dan kenaikan key_version monotonik
     * untuk user dan device_uuid yang sama.
     *
     * Ekspektasi:
     * - Keduanya terserialisasi pada level penguncian users.
     * - Perangkat pertama dibuat dengan key_version = 1.
     * - Perangkat kedua me-revoke perangkat pertama (REVOKED / MANAGED_REPLACEMENT)
     *   dan membuat perangkat baru dengan device_uuid sama namun key_version = 2.
     */
    public function test_c04_concurrent_registration_same_user_and_device_uuid_serializes_and_increments_key_version_monotonically(): void
    {
        $user = $this->createTestUser();
        $deviceUuid = (string) Str::uuid();

        $keypair1 = $this->generateEd25519Keypair();
        $nonce1 = $this->issueValidNonceForUser($user, $deviceUuid, $keypair1);
        $pop1 = $this->createValidPoP($user->id, $deviceUuid, $nonce1, $keypair1);

        $keypair2 = $this->generateEd25519Keypair();
        $nonce2 = $this->issueValidNonceForUser($user, $deviceUuid, $keypair2);
        $pop2 = $this->createValidPoP($user->id, $deviceUuid, $nonce2, $keypair2);

        $worker1Config = [
            'user_id'       => $user->id,
            'nonce_uuid'    => $nonce1->nonce_uuid,
            'payload_hex'   => $pop1['payload_hex'],
            'signature_hex' => $pop1['signature_hex'],
            'device_name'   => 'Device C04 Worker 1',
        ];

        $worker2Config = [
            'user_id'       => $user->id,
            'nonce_uuid'    => $nonce2->nonce_uuid,
            'payload_hex'   => $pop2['payload_hex'],
            'signature_hex' => $pop2['signature_hex'],
            'device_name'   => 'Device C04 Worker 2',
        ];

        $results = $this->runScenarioProcesses('C04', $worker1Config, $worker2Config);

        $w1 = $results['worker1'];
        $w2 = $results['worker2'];

        $this->assertNotNull($w1, 'Worker 1 harus mengembalikan result JSON.');
        $this->assertNotNull($w2, 'Worker 2 harus mengembalikan result JSON.');
        $this->assertTrue($w1['success'], 'Worker 1 harus berhasil.');
        $this->assertTrue($w2['success'], 'Worker 2 harus berhasil.');

        // Verifikasi status dan key_version di basis data
        $devices = BchatUserDevice::where('user_id', $user->id)
            ->where('device_uuid', $deviceUuid)
            ->orderBy('key_version', 'asc')
            ->get();

        $this->assertCount(2, $devices, 'Harus ada tepat 2 perangkat terdaftar untuk device_uuid ini.');

        $v1 = $devices[0];
        $v2 = $devices[1];

        $this->assertEquals(1, $v1->key_version);
        $this->assertEquals('REVOKED', $v1->status);
        $this->assertEquals('MANAGED_REPLACEMENT', $v1->revocation_reason);

        $this->assertEquals(2, $v2->key_version);
        $this->assertEquals('ACTIVE', $v2->status);
    }

    /**
     * Skenario C-05: Device Registration bersamaan dengan Welcome Publication.
     *
     * Status: BLOCKED — Service Welcome Publication belum diimplementasikan di Phase 5B.
     */
    public function test_c05_registration_concurrent_with_welcome_publication_blocked(): void
    {
        $this->markTestSkipped('BLOCKED: Modul Welcome Publication belum diimplementasikan di Phase 5B. Skenario ditunda.');
    }

    /**
     * Skenario C-06A: InnoDB Lock Wait Timeout (MySQL Error 1205) pemetaan ke BchatDeviceException HTTP 409.
     *
     * Ekspektasi:
     * - Worker 1 menahan lock baris User target secara independen selama 6 detik.
     * - Worker 2 dengan timeout 1 detik memanggil BchatDeviceService::registerDevice() secara langsung.
     * - Service mengalami Error 1205 saat lockForUpdate() baris User, menghabiskan retry DB::transaction,
     *   dan memetakannya menjadi BchatDeviceException asli (HTTP 409, CONCURRENCY_LOCK_CONFLICT).
     * - Nonce Worker 2 tetap berstatus ISSUED, tidak ada device baru terdaftar, dan lock Worker 1 lepas.
     */
    public function test_c06a_innodb_lock_wait_timeout_maps_to_concurrency_lock_conflict_409(): void
    {
        $user = $this->createTestUser();
        $deviceUuid = (string) Str::uuid();
        $keypair = $this->generateEd25519Keypair();

        // Terbitkan nonce dan siapkan PoP valid untuk Worker 2
        $nonce = $this->issueValidNonceForUser($user, $deviceUuid, $keypair);
        $pop = $this->createValidPoP($user->id, $deviceUuid, $nonce, $keypair);

        $worker1Config = [
            'user_id' => $user->id,
        ];

        $worker2Config = [
            'user_id'       => $user->id,
            'nonce_uuid'    => $nonce->nonce_uuid,
            'payload_hex'   => $pop['payload_hex'],
            'signature_hex' => $pop['signature_hex'],
            'device_name'   => 'Device C06A Timeout',
        ];

        // Timeout 20 detik agar cukup untuk Worker 1 menahan lock dan Worker 2 mencoba retry
        $results = $this->runScenarioProcesses('C06A', $worker1Config, $worker2Config, timeoutSeconds: 20);

        $w1 = $results['worker1'];
        $w2 = $results['worker2'];

        $this->assertNotNull($w1, 'Worker 1 harus mengembalikan result JSON.');
        $this->assertNotNull($w2, 'Worker 2 harus mengembalikan result JSON.');

        // Worker 1 harus berhasil menahan dan melepaskan lock
        $this->assertTrue($w1['success'], 'Worker 1 harus berhasil menahan dan melepaskan lock.');
        $this->assertEquals(200, $w1['http_status']);

        // Worker 2 harus gagal karena BchatDeviceException asli dari BchatDeviceService
        $this->assertFalse($w2['success'], 'Worker 2 harus gagal karena lock wait timeout pada service.');
        $this->assertEquals(409, $w2['http_status'], 'Service harus memetakan error 1205 ke HTTP 409.');
        $this->assertEquals('CONCURRENCY_LOCK_CONFLICT', $w2['error_code'], 'Error code harus CONCURRENCY_LOCK_CONFLICT.');

        // Verifikasi bahwa nonce Worker 2 TETAP berstatus ISSUED karena transaksi di-rollback penuh
        $nonce->refresh();
        $this->assertEquals('ISSUED', $nonce->status, 'Registration nonce harus tetap berstatus ISSUED.');
        $this->assertNull($nonce->consumed_at, 'consumed_at harus tetap NULL.');

        // Verifikasi bahwa tidak ada perangkat baru yang tersimpan untuk user ini
        $deviceCount = BchatUserDevice::where('user_id', $user->id)->count();
        $this->assertEquals(0, $deviceCount, 'Tidak boleh ada perangkat yang tersimpan akibat lock conflict.');
    }

    /**
     * Skenario C-06B.1: Pembuktian Nyata InnoDB Deadlock Detection (MySQL Error 1213 / SQLSTATE 40001).
     *
     * Ekspektasi:
     * - Dua koneksi independen mengunci dua baris User riil secara bersilangan.
     * - InnoDB Deadlock Detector memilih tepat satu transaksi sebagai victim dan me-rollback-nya.
     * - Tepat satu worker berstatus WINNER (commit berhasil), dan tepat satu worker berstatus DEADLOCK_VICTIM (1213).
     */
    public function test_c06b1_pure_innodb_engine_cross_row_deadlock_detection_1213(): void
    {
        $userA = $this->createTestUser();
        $userB = $this->createTestUser();

        $worker1Config = [
            'user_a_id' => $userA->id,
            'user_b_id' => $userB->id,
        ];

        $worker2Config = [
            'user_a_id' => $userA->id,
            'user_b_id' => $userB->id,
        ];

        $results = $this->runScenarioProcesses('C06B1', $worker1Config, $worker2Config, timeoutSeconds: 15);

        $w1 = $results['worker1'];
        $w2 = $results['worker2'];

        $this->assertNotNull($w1, 'Worker 1 harus mengembalikan result JSON.');
        $this->assertNotNull($w2, 'Worker 2 harus mengembalikan result JSON.');

        $w1Winner = ($w1['status'] ?? '') === 'WINNER';
        $w2Winner = ($w2['status'] ?? '') === 'WINNER';

        $w1Victim = ($w1['status'] ?? '') === 'DEADLOCK_VICTIM';
        $w2Victim = ($w2['status'] ?? '') === 'DEADLOCK_VICTIM';

        $this->assertTrue(
            ($w1Winner && $w2Victim) || ($w2Winner && $w1Victim),
            'Tepat satu worker harus menjadi WINNER dan tepat satu worker harus menjadi DEADLOCK_VICTIM.'
        );

        $victim = $w1Victim ? $w1 : $w2;
        $this->assertEquals(409, $victim['http_status']);
        $this->assertEquals(1213, $victim['db_error_code'], 'Database error code victim harus 1213.');
        $this->assertEquals('40001', $victim['sql_state'], 'SQLSTATE victim harus 40001 (Serialization failure).');
    }

    /**
     * Skenario C-06B.2: Pengujian pemetaan error deadlock service layer ke CONCURRENCY_LOCK_CONFLICT 409.
     *
     * Status: NOT YET COVERED — Dipertahankan tanpa modifikasi kode produksi.
     */
    public function test_c06b2_service_deadlock_error_mapping_not_yet_covered(): void
    {
        $this->markTestSkipped('NOT YET COVERED: Pengujian mapping 1213/40001 ke CONCURRENCY_LOCK_CONFLICT belum tercakup tanpa modifikasi kode produksi.');
    }
}
