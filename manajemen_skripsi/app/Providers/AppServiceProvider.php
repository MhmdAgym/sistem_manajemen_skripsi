<?php

namespace App\Providers;

use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Database\Events\ConnectionEstablished;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Pasang guard koneksi sedini mungkin pada fase registrasi provider
        Event::listen(ConnectionEstablished::class, function (ConnectionEstablished $event) {
            $connection = $event->connection;
            $isTestingEnv = app()->environment('testing') || app()->runningUnitTests();

            // Guard hanya aktif dan berlaku fail-closed saat berada di lingkungan testing
            if (!$isTestingEnv) {
                return;
            }

            $driver = (string) $connection->getConfig('driver');
            $database = (string) $connection->getConfig('database');
            $username = (string) $connection->getConfig('username');
            $host = (string) $connection->getConfig('host');
            $rawPort = $connection->getConfig('port');

            // 1. Jalur SQLite: HANYA izinkan in-memory (:memory:)
            if ($driver === 'sqlite') {
                if ($database === ':memory:') {
                    return;
                }
                throw new \RuntimeException(
                    "SECURITY VIOLATION: File-based SQLite database [{$database}] is prohibited in testing mode."
                );
            }

            // 2. Jalur MySQL / MariaDB: Validasi Ketat Dua Tingkat
            if ($driver === 'mysql' || $driver === 'mariadb') {
                // Tier 1: Validasi Konfigurasi Klien (Sebelum Query/DDL Aplikasi)
                if ($database !== 'manajemen_skripsi_test') {
                    throw new \RuntimeException(
                        "SECURITY VIOLATION: Testing attempted connection to unauthorized database [{$database}]. Only 'manajemen_skripsi_test' is permitted."
                    );
                }

                if ($username !== 'unuha_tester') {
                    throw new \RuntimeException(
                        "SECURITY VIOLATION: Testing attempted connection with unauthorized user [{$username}]. Only 'unuha_tester' is permitted."
                    );
                }

                if ($host !== '127.0.0.1' && $host !== 'localhost') {
                    throw new \RuntimeException(
                        "SECURITY VIOLATION: Testing attempted connection to unauthorized host [{$host}]. Only local loopback (127.0.0.1/localhost) is permitted."
                    );
                }

                // Normalisasi dan verifikasi port: tangani nilai kosong secara eksplisit, hanya izinkan 3306
                $port = (string) ($rawPort !== null && $rawPort !== '' ? $rawPort : '3306');
                if ($port !== '3306') {
                    throw new \RuntimeException(
                        "SECURITY VIOLATION: Testing attempted connection to unauthorized port [{$port}]. Only port 3306 is permitted."
                    );
                }

                // Tier 2: Validasi Sesi Server Aktif (Identitas Otentikasi Nyata)
                try {
                    $session = $connection->selectOne("SELECT DATABASE() as active_db, CURRENT_USER() as auth_user");
                } catch (\Throwable $e) {
                    throw new \RuntimeException(
                        "SECURITY VIOLATION: Failed to verify MySQL session identity: " . $e->getMessage()
                    );
                }

                if (!$session || empty($session->active_db) || empty($session->auth_user)) {
                    throw new \RuntimeException(
                        "SECURITY VIOLATION: Server session identity returned empty or null values."
                    );
                }

                if ($session->active_db !== 'manajemen_skripsi_test') {
                    throw new \RuntimeException(
                        "SECURITY VIOLATION: Active server database is [{$session->active_db}]. Expected 'manajemen_skripsi_test'."
                    );
                }

                // Strictly periksa 'unuha_tester@localhost', tolak wildcard
                if ($session->auth_user !== 'unuha_tester@localhost') {
                    throw new \RuntimeException(
                        "SECURITY VIOLATION: Authenticated MySQL user is [{$session->auth_user}]. Expected 'unuha_tester@localhost'."
                    );
                }

                return;
            }

            // 3. Tolak seluruh driver lainnya di lingkungan testing
            throw new \RuntimeException(
                "SECURITY VIOLATION: Database driver [{$driver}] is not allowed in testing mode."
            );
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        ResetPassword::toMailUsing(function (object $notifiable, string $token) {
            $url = config('app.frontend_url')."/password-reset/$token?email={$notifiable->getEmailForPasswordReset()}";

            return (new MailMessage)
                ->subject('Reset Password - Sistem Manajemen Skripsi')
                ->view('emails.reset-password', [
                    'user' => $notifiable,
                    'url' => $url,
                ]);
        });

        VerifyEmail::toMailUsing(function (object $notifiable, string $url) {
            return (new MailMessage)
                ->subject('Verifikasi Alamat Email - Sistem Manajemen Skripsi')
                ->view('emails.verify-email', [
                    'user' => $notifiable,
                    'url' => $url,
                ]);
        });
    }
}
