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
        $validateConnection = function ($connection) {
            $driver = (string) $connection->getConfig('driver');
            $database = (string) $connection->getConfig('database');
            $username = (string) $connection->getConfig('username');
            $host = (string) $connection->getConfig('host');
            $rawPort = $connection->getConfig('port');
            $port = (string) ($rawPort !== null && $rawPort !== '' ? $rawPort : '3306');

            // Evaluasi Konteks Pengujian: 5 indikator komprehensif
            $isTestingContext = (
                defined('STRICT_TEST_DATABASE_ISOLATION') ||
                app()->environment('testing') ||
                app()->runningUnitTests() ||
                $database === 'manajemen_skripsi_test' ||
                $username === 'unuha_tester'
            );

            if ($isTestingContext) {
                // ========================================================
                // JALUR 1: KONEKSI LINGKUNGAN PENGUJIAN (STRICT FAIL-CLOSED)
                // ========================================================
                if ($driver === 'sqlite') {
                    if ($database === ':memory:') {
                        return;
                    }
                    throw new \RuntimeException(
                        "SECURITY VIOLATION: File-based SQLite database [{$database}] is prohibited in testing mode."
                    );
                }

                if ($driver !== 'mysql' && $driver !== 'mariadb') {
                    throw new \RuntimeException(
                        "SECURITY VIOLATION: Database driver [{$driver}] is not allowed in testing mode."
                    );
                }

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

                // Strictly periksa 'unuha_tester@localhost' atau 'unuha_tester@127.0.0.1'
                if ($session->auth_user !== 'unuha_tester@localhost' && $session->auth_user !== 'unuha_tester@127.0.0.1') {
                    throw new \RuntimeException(
                        "SECURITY VIOLATION: Authenticated MySQL user is [{$session->auth_user}]. Expected 'unuha_tester@localhost'."
                    );
                }

                return;
            }

            // ========================================================
            // JALUR 2: KONEKSI OPERASIONAL (ANTI-KONTAMINASI SILANG)
            // ========================================================
            if ($database === 'manajemen_skripsi_test') {
                throw new \RuntimeException(
                    "SECURITY VIOLATION: Operational application attempted connection to test database [manajemen_skripsi_test]."
                );
            }

            if ($username === 'unuha_tester') {
                throw new \RuntimeException(
                    "SECURITY VIOLATION: Operational application attempted connection using test user [unuha_tester]."
                );
            }
        };

        // Pasang guard koneksi pada event ConnectionEstablished
        Event::listen(ConnectionEstablished::class, function (ConnectionEstablished $event) use ($validateConnection) {
            $validateConnection($event->connection);
        });

        // Periksa koneksi yang mungkin sudah aktif sebelumnya
        if (app()->bound('db')) {
            foreach (\Illuminate\Support\Facades\DB::getConnections() as $existingConnection) {
                $validateConnection($existingConnection);
            }
        }
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
