<?php

namespace App\Console\Commands;

use App\Services\BchatProvisioningWorkerService;
use Illuminate\Console\Command;
use Throwable;

class ProcessBchatProvisioningOutboxCommand extends Command
{
    /**
     * Nama dan tanda tangan perintah console.
     *
     * @var string
     */
    protected $signature = 'bchat:process-outbox
                            {--once : Jalankan reaper untuk event macet (>60 detik), lalu proses maksimal satu event eligible}
                            {--limit= : Jalankan reaper untuk event macet (>60 detik), lalu proses maksimal N event eligible}
                            {--daemon : Jalankan worker berkelanjutan dengan reaper berkala}';

    /**
     * Deskripsi perintah console.
     *
     * @var string
     */
    protected $description = 'Worker pemroses antrean transactional outbox provisioning room B-CHAT Proposal E2EE';

    /**
     * Eksekusi perintah console.
     */
    public function handle(BchatProvisioningWorkerService $workerService): int
    {
        $isOnce = (bool) $this->option('once');
        $isDaemon = (bool) $this->option('daemon');
        $rawLimit = $this->option('limit');
        $hasLimit = $rawLimit !== null;

        // Validasi Tunggal & Tidak Ambigu: Wajib memilih tepat SATU mode eksekusi
        $activeOptionsCount = ($isOnce ? 1 : 0) + ($isDaemon ? 1 : 0) + ($hasLimit ? 1 : 0);

        if ($activeOptionsCount === 0) {
            $this->error('Mode eksekusi tidak ditentukan. Harap pilih tepat salah satu opsi: --once, --limit=N, atau --daemon.');
            $this->line('Contoh penggunaan:');
            $this->line('  php artisan bchat:process-outbox --once');
            $this->line('  php artisan bchat:process-outbox --limit=10');
            $this->line('  php artisan bchat:process-outbox --daemon');
            return Command::INVALID;
        }

        if ($activeOptionsCount > 1) {
            $this->error('Kombinasi opsi saling bertentangan. Harap pilih tepat SATU mode eksekusi: --once, --limit=N, atau --daemon.');
            return Command::INVALID;
        }

        // Validasi Limit
        $limit = null;
        if ($hasLimit) {
            if (! ctype_digit((string) $rawLimit) || (int) $rawLimit <= 0) {
                $this->error('Nilai opsi --limit harus berupa bilangan bulat positif lebih besar dari 0.');
                return Command::INVALID;
            }
            $limit = (int) $rawLimit;
        } elseif ($isOnce) {
            $limit = 1;
        }

        $this->info("Menjalankan B-CHAT Provisioning Outbox Worker [{$workerService->getWorkerIdentity()}]");

        if ($isDaemon) {
            return $this->runDaemonLoop($workerService);
        }

        return $this->runBatch($workerService, $limit);
    }

    /**
     * Jalankan pemrosesan batch hingga sejumlah limit tertentu.
     */
    protected function runBatch(BchatProvisioningWorkerService $workerService, int $limit): int
    {
        $processed = 0;
        $successCount = 0;
        $failureCount = 0;

        // Jalankan reaper sebelum memproses batch untuk membebaskan job yang tertinggal
        try {
            $reaped = $workerService->reapStalledEvents();
            if ($reaped > 0) {
                $this->warn("Reaper memulihkan {$reaped} event yang tertahan melebihi 60 detik.");
            }
        } catch (Throwable $e) {
            $this->error("Kegagalan pada rutinitas reaper: {$e->getMessage()}");
        }

        while ($processed < $limit) {
            try {
                $result = $workerService->processNext();

                if ($result === null) {
                    $this->line('Antrean outbox kosong / tidak ada event PENDING yang eligible saat ini.');
                    break;
                }

                $processed++;
                if ($result === true) {
                    $successCount++;
                    $this->info("Event #{$processed} berhasil diproses (COMPLETED / Idempoten Sah).");
                } else {
                    $failureCount++;
                    $this->warn("Event #{$processed} gagal diproses (ditangani via terminal FAILED atau retry schedule).");
                }
            } catch (Throwable $e) {
                $processed++;
                $failureCount++;
                $this->error("Terjadi exception pada pemrosesan event #{$processed}: {$e->getMessage()}");
            }
        }

        $this->info("Batch selesai: {$processed} event diperiksa ({$successCount} sukses, {$failureCount} gagal/retry).");
        return Command::SUCCESS;
    }

    /**
     * Jalankan pemrosesan berkelanjutan (daemon loop).
     */
    protected function runDaemonLoop(BchatProvisioningWorkerService $workerService): int
    {
        $this->info('Memulai worker dalam mode DAEMON. Tekan Ctrl+C untuk menghentikan proses.');
        $lastReaperRun = 0;

        while (true) {
            // Jalankan reaper berkala setiap 60 detik
            $nowTimestamp = time();
            if ($nowTimestamp - $lastReaperRun >= 60) {
                try {
                    $reaped = $workerService->reapStalledEvents();
                    if ($reaped > 0) {
                        $this->warn("Reaper memulihkan {$reaped} event macet.");
                    }
                    $lastReaperRun = $nowTimestamp;
                } catch (Throwable $e) {
                    $this->error("Reaper error: {$e->getMessage()}");
                }
            }

            try {
                $result = $workerService->processNext();

                if ($result === null) {
                    sleep(2);
                    continue;
                }

                if ($result === true) {
                    $this->info('Event outbox berhasil diproses (COMPLETED).');
                } else {
                    $this->warn('Event outbox selesai diproses dengan status gagal/retry.');
                }
            } catch (Throwable $e) {
                $this->error("Daemon loop exception: {$e->getMessage()}");
                sleep(2);
            }
        }

        return Command::SUCCESS;
    }
}
