<?php

/**
 * B-CHAT-22B Phase 5B.3C - Standalone Concurrency Worker Script.
 *
 * Worker CLI mandiri untuk mengeksekusi skenario konkurensi multi-proses
 * dengan koneksi database MySQL terisolasi.
 *
 * Penggunaan:
 *   php.exe DeviceConcurrencyWorker.php --scenario=C01 --barrier-dir=/path --worker-id=1 --config-file=/path/cfg.json
 */

declare(strict_types=1);

// Pastikan lingkungan aplikasi diset ke testing sebelum bootstrap
putenv('APP_ENV=testing');
$_ENV['APP_ENV'] = 'testing';
$_SERVER['APP_ENV'] = 'testing';

// Resolve base path manajemen_skripsi
$basePath = realpath(__DIR__ . '/../../../../');
if ($basePath === false) {
    fwrite(STDERR, "CRITICAL: Unable to resolve application base path.\n");
    exit(99);
}

require_once $basePath . '/vendor/autoload.php';

/** @var \Illuminate\Foundation\Application $app */
$app = require_once $basePath . '/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Exceptions\BchatDeviceException;
use App\Models\BchatUserDevice;
use App\Models\User;
use App\Services\BchatDeviceService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

// Verifikasi keamanan database pengujian secara mutlak
$activeDb = DB::connection()->getDatabaseName();
if ($activeDb !== 'manajemen_skripsi_test') {
    fwrite(STDERR, "CRITICAL SECURITY VIOLATION: Active database is '{$activeDb}', expected 'manajemen_skripsi_test'. Aborting worker.\n");
    exit(98);
}

/**
 * Mempublikasikan file secara atomik melalui temporary file dan rename().
 * Menggunakan pola fail-closed: jika file tujuan sudah ada, publikasi kedua dianggap kesalahan.
 * Tidak pernah melakukan unlink($filePath) sebelum rename().
 */
function workerAtomicWrite(string $filePath, string $content): void
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
    } catch (Throwable $e) {
        // Bersihkan HANYA file sementara saat terjadi kegagalan
        if (file_exists($tmpFile)) {
            @unlink($tmpFile);
        }
        throw $e;
    }
}

// Parsing opsi argumen baris perintah
$options = getopt('', [
    'scenario:',
    'barrier-dir:',
    'worker-id:',
    'config-file:',
]);

$scenario   = $options['scenario'] ?? null;
$barrierDir = $options['barrier-dir'] ?? null;
$workerId   = isset($options['worker-id']) ? (int) $options['worker-id'] : 0;
$configFile = $options['config-file'] ?? null;

if (!$scenario || !$barrierDir || $workerId <= 0 || !$configFile || !file_exists($configFile)) {
    fwrite(STDERR, "CRITICAL: Invalid worker CLI arguments or missing config file.\n");
    exit(97);
}

$configRaw = file_get_contents($configFile);
$config = json_decode($configRaw ?: '', true);
if (!is_array($config)) {
    fwrite(STDERR, "CRITICAL: Config file could not be parsed as JSON.\n");
    exit(96);
}

$readyFile   = $barrierDir . DIRECTORY_SEPARATOR . "child_{$workerId}.ready";
$releaseFile = $barrierDir . DIRECTORY_SEPARATOR . "barrier.released";
$resultFile  = $barrierDir . DIRECTORY_SEPARATOR . "child_{$workerId}.result.json";
$errFile     = $barrierDir . DIRECTORY_SEPARATOR . "child_{$workerId}.err";

try {
    // -------------------------------------------------------------------------
    // TAHAP 1: PERSIAPAN DALAM MEMORI (TANPA TRANSAKSI DB / ROW LOCK)
    // -------------------------------------------------------------------------
    $service = new BchatDeviceService();

    // -------------------------------------------------------------------------
    // TAHAP 2: SIGNAL READY SECARA ATOMIK KE PARENT
    // -------------------------------------------------------------------------
    workerAtomicWrite($readyFile, json_encode([
        'pid'       => getmypid(),
        'worker_id' => $workerId,
        'scenario'  => $scenario,
        'status'    => 'READY',
        'ready_at'  => microtime(true),
    ], JSON_PRETTY_PRINT));

    // -------------------------------------------------------------------------
    // TAHAP 3: MENUNGGU RELEASE MARKER DARI PARENT (POLLING INTERVAL 5ms)
    //          DILARANG membuka transaksi atau menahan lock selama fase ini!
    // -------------------------------------------------------------------------
    $startWait = microtime(true);
    $released = false;
    while ((microtime(true) - $startWait) < 10.0) {
        if (file_exists($releaseFile)) {
            $released = true;
            break;
        }
        usleep(5000); // 5 milidetik
    }

    if (!$released) {
        throw new RuntimeException("Worker {$workerId} timed out waiting for barrier release.");
    }

    // -------------------------------------------------------------------------
    // TAHAP 4: EKSEKUSI TARGET SKENARIO KONKURENSI
    // -------------------------------------------------------------------------
    if (in_array($scenario, ['C01', 'C02', 'C03', 'C04'], true)) {
        $user = User::findOrFail((int) $config['user_id']);
        $nonceUuid = (string) $config['nonce_uuid'];
        $payloadHex = (string) $config['payload_hex'];
        $signatureHex = (string) $config['signature_hex'];
        $deviceName = (string) ($config['device_name'] ?? ('Worker Device ' . $workerId));

        $payloadBinary = hex2bin($payloadHex);
        $signatureBinary = hex2bin($signatureHex);

        if ($payloadBinary === false || $signatureBinary === false) {
            throw new InvalidArgumentException("Worker {$workerId} received invalid hex payload or signature.");
        }

        $startTime = microtime(true);
        $confirmReplacement = (bool) ($config['confirm_replacement'] ?? false);
        $expectedActiveDeviceUuid = isset($config['expected_active_device_uuid']) ? (string) $config['expected_active_device_uuid'] : null;

        try {
            $resultData = $service->registerDevice(
                user: $user,
                nonceUuid: $nonceUuid,
                payloadInput: $payloadBinary,
                signatureInput: $signatureBinary,
                deviceName: $deviceName,
                confirmReplacement: $confirmReplacement,
                expectedActiveDeviceUuid: $expectedActiveDeviceUuid
            );
            $durationMs = (microtime(true) - $startTime) * 1000;

            workerAtomicWrite($resultFile, json_encode([
                'pid'           => getmypid(),
                'worker_id'     => $workerId,
                'scenario'      => $scenario,
                'success'       => true,
                'http_status'   => 201,
                'error_code'    => null,
                'error_message' => null,
                'data'          => $resultData,
                'duration_ms'   => round($durationMs, 2),
            ], JSON_PRETTY_PRINT));
            exit(0);
        } catch (BchatDeviceException $e) {
            // Khusus skenario Managed Replacement konkuren (C02 / C04):
            // Ketika worker kalah dalam perebutan registrasi awal, worker menerima REPLACEMENT_CONFIRMATION_REQUIRED (409).
            // Sesuai alur resmi protokol klien B-CHAT, worker mengonfirmasi penggantian dengan menyertakan expected_active_device_uuid.
            if (in_array($scenario, ['C02', 'C04'], true) && $e->getErrorCode() === 'REPLACEMENT_CONFIRMATION_REQUIRED') {
                $activeDev = BchatUserDevice::where('user_id', $user->id)->where('status', 'ACTIVE')->first();
                if ($activeDev) {
                    try {
                        $resultData = $service->registerDevice(
                            user: $user,
                            nonceUuid: $nonceUuid,
                            payloadInput: $payloadBinary,
                            signatureInput: $signatureBinary,
                            deviceName: $deviceName,
                            confirmReplacement: true,
                            expectedActiveDeviceUuid: $activeDev->device_uuid
                        );
                        $durationMs = (microtime(true) - $startTime) * 1000;
                        workerAtomicWrite($resultFile, json_encode([
                            'pid'           => getmypid(),
                            'worker_id'     => $workerId,
                            'scenario'      => $scenario,
                            'success'       => true,
                            'http_status'   => 201,
                            'error_code'    => null,
                            'error_message' => null,
                            'data'          => $resultData,
                            'duration_ms'   => round($durationMs, 2),
                        ], JSON_PRETTY_PRINT));
                        exit(0);
                    } catch (Throwable $retryException) {
                        // Jika retry penggantian tetap gagal, biarkan penanganan exception asli berlanjut
                    }
                }
            }

            $durationMs = (microtime(true) - $startTime) * 1000;
            workerAtomicWrite($resultFile, json_encode([
                'pid'           => getmypid(),
                'worker_id'     => $workerId,
                'scenario'      => $scenario,
                'success'       => false,
                'http_status'   => $e->getHttpStatus(),
                'error_code'    => $e->getErrorCode(),
                'error_message' => $e->getMessage(),
                'duration_ms'   => round($durationMs, 2),
            ], JSON_PRETTY_PRINT));
            exit(0);
        } catch (QueryException $e) {
            $durationMs = (microtime(true) - $startTime) * 1000;
            workerAtomicWrite($resultFile, json_encode([
                'pid'           => getmypid(),
                'worker_id'     => $workerId,
                'scenario'      => $scenario,
                'success'       => false,
                'http_status'   => 500,
                'error_code'    => 'QUERY_EXCEPTION',
                'db_error_code' => $e->errorInfo[1] ?? null,
                'sql_state'     => $e->getCode(),
                'error_message' => $e->getMessage(),
                'duration_ms'   => round($durationMs, 2),
            ], JSON_PRETTY_PRINT));
            exit(0);
        }
    } elseif ($scenario === 'C06A') {
        // Skenario Lock Wait Timeout MySQL 1205 melalui BchatDeviceService::registerDevice()
        $userId = (int) $config['user_id'];

        if ($workerId === 1) {
            // Worker 1: Membuka transaksi independen dan mengunci row User target
            DB::beginTransaction();
            try {
                // Timeout Worker 1 cukup besar agar lock tidak lepas sebelum Worker 2 selesai mencoba
                DB::statement("SET innodb_lock_wait_timeout = 20");
                User::where('id', $userId)->lockForUpdate()->first();

                // Beritahu Worker 2 bahwa lock User sudah berhasil dipegang
                workerAtomicWrite(
                    $barrierDir . DIRECTORY_SEPARATOR . 'worker1_holding_lock.ready',
                    json_encode(['held' => true, 'timestamp' => microtime(true)], JSON_PRETTY_PRINT)
                );

                // Mempertahankan lock selama 6 detik untuk mencakup seluruh percobaan
                // transaksi service Worker 2, termasuk retry DB::transaction(..., 3)
                sleep(6);

                workerAtomicWrite($resultFile, json_encode([
                    'pid'         => getmypid(),
                    'worker_id'   => 1,
                    'scenario'    => 'C06A',
                    'success'     => true,
                    'http_status' => 200,
                    'action'      => 'HELD_AND_RELEASED_LOCK',
                ], JSON_PRETTY_PRINT));
                exit(0);
            } catch (Throwable $e) {
                workerAtomicWrite($resultFile, json_encode([
                    'pid'           => getmypid(),
                    'worker_id'     => 1,
                    'scenario'      => 'C06A',
                    'success'       => false,
                    'http_status'   => 500,
                    'error_message' => $e->getMessage(),
                ], JSON_PRETTY_PRINT));
                exit(1);
            } finally {
                // Pelepasan transaksi melalui finally, baik sukses maupun gagal
                DB::rollBack();
            }
        } else {
            // Worker 2: Menunggu marker Worker 1 bahwa lock sudah dipegang
            $waitMarker = $barrierDir . DIRECTORY_SEPARATOR . 'worker1_holding_lock.ready';
            $waitStart = microtime(true);
            while (!file_exists($waitMarker) && (microtime(true) - $waitStart) < 8.0) {
                usleep(5000);
            }

            if (!file_exists($waitMarker)) {
                throw new RuntimeException("Worker 2 timed out waiting for Worker 1 to acquire row lock.");
            }

            // Atur lock wait timeout sesi Worker 2 menjadi 1 detik
            DB::statement("SET innodb_lock_wait_timeout = 1");

            $user = User::findOrFail($userId);
            $nonceUuid = (string) $config['nonce_uuid'];
            $payloadHex = (string) $config['payload_hex'];
            $signatureHex = (string) $config['signature_hex'];
            $deviceName = (string) ($config['device_name'] ?? 'Worker 2 Timeout Device');

            $payloadBinary = hex2bin($payloadHex);
            $signatureBinary = hex2bin($signatureHex);

            if ($payloadBinary === false || $signatureBinary === false) {
                throw new InvalidArgumentException("Worker 2 received invalid hex payload or signature.");
            }

            $startTime = microtime(true);
            try {
                // Panggil BchatDeviceService::registerDevice() secara langsung!
                // Service akan mencoba User::where('id', $user->id)->lockForUpdate() di baris 388,
                // terbentur lock Worker 1, timeout 1 detik per retry (3x retry), dan akhirnya
                // menangkap MySQL Error 1205 dan melempar BchatDeviceException asli (HTTP 409).
                $resultData = $service->registerDevice(
                    user: $user,
                    nonceUuid: $nonceUuid,
                    payloadInput: $payloadBinary,
                    signatureInput: $signatureBinary,
                    deviceName: $deviceName
                );
                $durationMs = (microtime(true) - $startTime) * 1000;

                workerAtomicWrite($resultFile, json_encode([
                    'pid'           => getmypid(),
                    'worker_id'     => 2,
                    'scenario'      => 'C06A',
                    'success'       => true,
                    'http_status'   => 201,
                    'error_code'    => null,
                    'error_message' => null,
                    'data'          => $resultData,
                    'duration_ms'   => round($durationMs, 2),
                ], JSON_PRETTY_PRINT));
                exit(0);
            } catch (BchatDeviceException $e) {
                // Menangkap BchatDeviceException asli dari BchatDeviceService
                $durationMs = (microtime(true) - $startTime) * 1000;
                workerAtomicWrite($resultFile, json_encode([
                    'pid'           => getmypid(),
                    'worker_id'     => 2,
                    'scenario'      => 'C06A',
                    'success'       => false,
                    'http_status'   => $e->getHttpStatus(),
                    'error_code'    => $e->getErrorCode(),
                    'error_message' => $e->getMessage(),
                    'duration_ms'   => round($durationMs, 2),
                ], JSON_PRETTY_PRINT));
                exit(0);
            } catch (Throwable $e) {
                $durationMs = (microtime(true) - $startTime) * 1000;
                workerAtomicWrite($resultFile, json_encode([
                    'pid'           => getmypid(),
                    'worker_id'     => 2,
                    'scenario'      => 'C06A',
                    'success'       => false,
                    'http_status'   => 500,
                    'error_code'    => 'UNEXPECTED_EXCEPTION',
                    'error_message' => $e->getMessage(),
                    'duration_ms'   => round($durationMs, 2),
                ], JSON_PRETTY_PRINT));
                exit(1);
            }
        }
    } elseif ($scenario === 'C06B1') {
        // Skenario Deadlock Nyata InnoDB 1213 pada dua record User riil
        $userAId = (int) $config['user_a_id'];
        $userBId = (int) $config['user_b_id'];

        if ($workerId === 1) {
            DB::beginTransaction();
            try {
                DB::statement("SET innodb_lock_wait_timeout = 10");
                // Langkah 1: Kunci User A
                User::where('id', $userAId)->lockForUpdate()->first();

                // Beritahu Worker 2 bahwa Worker 1 telah mengunci User A
                workerAtomicWrite(
                    $barrierDir . DIRECTORY_SEPARATOR . 'step1_worker1.ready',
                    json_encode(['held' => true, 'timestamp' => microtime(true)], JSON_PRETTY_PRINT)
                );

                // Tunggu Worker 2 mengunci User B
                $step1Worker2Marker = $barrierDir . DIRECTORY_SEPARATOR . 'step1_worker2.ready';
                $waitStart = microtime(true);
                while (!file_exists($step1Worker2Marker) && (microtime(true) - $waitStart) < 5.0) {
                    usleep(5000);
                }

                // Jeda sinkronisasi mikro agar kedua koneksi siap di barrier cross-lock
                usleep(10000);

                // Langkah 2: Coba kunci User B (yang dipegang Worker 2)
                User::where('id', $userBId)->lockForUpdate()->first();
                DB::commit();

                workerAtomicWrite($resultFile, json_encode([
                    'pid'         => getmypid(),
                    'worker_id'   => 1,
                    'scenario'    => 'C06B1',
                    'status'      => 'WINNER',
                    'success'     => true,
                    'http_status' => 200,
                ], JSON_PRETTY_PRINT));
                exit(0);
            } catch (QueryException $e) {
                DB::rollBack();
                $errorCode = $e->errorInfo[1] ?? null;
                $sqlState = (string) $e->getCode();
                $isDeadlock = ($errorCode === 1213 || $sqlState === '40001' || str_contains($e->getMessage(), 'Deadlock'));

                workerAtomicWrite($resultFile, json_encode([
                    'pid'           => getmypid(),
                    'worker_id'     => 1,
                    'scenario'      => 'C06B1',
                    'status'        => $isDeadlock ? 'DEADLOCK_VICTIM' : 'QUERY_FAILED',
                    'success'       => false,
                    'http_status'   => $isDeadlock ? 409 : 500,
                    'error_code'    => $isDeadlock ? 'DEADLOCK_1213' : 'QUERY_EXCEPTION',
                    'db_error_code' => $errorCode,
                    'sql_state'     => $sqlState,
                    'error_message' => $e->getMessage(),
                ], JSON_PRETTY_PRINT));
                exit(0);
            } catch (Throwable $e) {
                DB::rollBack();
                workerAtomicWrite($resultFile, json_encode([
                    'pid'           => getmypid(),
                    'worker_id'     => 1,
                    'scenario'      => 'C06B1',
                    'status'        => 'FATAL_ERROR',
                    'success'       => false,
                    'http_status'   => 500,
                    'error_message' => $e->getMessage(),
                ], JSON_PRETTY_PRINT));
                exit(1);
            }
        } else {
            // Worker 2
            DB::beginTransaction();
            try {
                DB::statement("SET innodb_lock_wait_timeout = 10");
                // Langkah 1: Kunci User B
                User::where('id', $userBId)->lockForUpdate()->first();

                // Beritahu Worker 1 bahwa Worker 2 telah mengunci User B
                workerAtomicWrite(
                    $barrierDir . DIRECTORY_SEPARATOR . 'step1_worker2.ready',
                    json_encode(['held' => true, 'timestamp' => microtime(true)], JSON_PRETTY_PRINT)
                );

                // Tunggu Worker 1 mengunci User A
                $step1Worker1Marker = $barrierDir . DIRECTORY_SEPARATOR . 'step1_worker1.ready';
                $waitStart = microtime(true);
                while (!file_exists($step1Worker1Marker) && (microtime(true) - $waitStart) < 5.0) {
                    usleep(5000);
                }

                // Jeda sinkronisasi mikro agar kedua koneksi siap di barrier cross-lock
                usleep(10000);

                // Langkah 2: Coba kunci User A (yang dipegang Worker 1)
                User::where('id', $userAId)->lockForUpdate()->first();
                DB::commit();

                workerAtomicWrite($resultFile, json_encode([
                    'pid'         => getmypid(),
                    'worker_id'   => 2,
                    'scenario'    => 'C06B1',
                    'status'      => 'WINNER',
                    'success'     => true,
                    'http_status' => 200,
                ], JSON_PRETTY_PRINT));
                exit(0);
            } catch (QueryException $e) {
                DB::rollBack();
                $errorCode = $e->errorInfo[1] ?? null;
                $sqlState = (string) $e->getCode();
                $isDeadlock = ($errorCode === 1213 || $sqlState === '40001' || str_contains($e->getMessage(), 'Deadlock'));

                workerAtomicWrite($resultFile, json_encode([
                    'pid'           => getmypid(),
                    'worker_id'     => 2,
                    'scenario'      => 'C06B1',
                    'status'        => $isDeadlock ? 'DEADLOCK_VICTIM' : 'QUERY_FAILED',
                    'success'       => false,
                    'http_status'   => $isDeadlock ? 409 : 500,
                    'error_code'    => $isDeadlock ? 'DEADLOCK_1213' : 'QUERY_EXCEPTION',
                    'db_error_code' => $errorCode,
                    'sql_state'     => $sqlState,
                    'error_message' => $e->getMessage(),
                ], JSON_PRETTY_PRINT));
                exit(0);
            } catch (Throwable $e) {
                DB::rollBack();
                workerAtomicWrite($resultFile, json_encode([
                    'pid'           => getmypid(),
                    'worker_id'     => 2,
                    'scenario'      => 'C06B1',
                    'status'        => 'FATAL_ERROR',
                    'success'       => false,
                    'http_status'   => 500,
                    'error_message' => $e->getMessage(),
                ], JSON_PRETTY_PRINT));
                exit(1);
            }
        }
    } else {
        throw new InvalidArgumentException("Unknown scenario: {$scenario}");
    }
} catch (Throwable $t) {
    workerAtomicWrite($errFile, $t->getMessage() . "\n" . $t->getTraceAsString());
    fwrite(STDERR, "Worker {$workerId} encountered unhandled exception: " . $t->getMessage() . "\n");
    exit(1);
}
