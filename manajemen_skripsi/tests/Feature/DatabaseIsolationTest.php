<?php

namespace Tests\Feature;

use Illuminate\Database\Connection;
use Illuminate\Database\Events\ConnectionEstablished;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class DatabaseIsolationTest extends TestCase
{
    /**
     * 1. Konfigurasi SQLite :memory: yang sah dapat digunakan tanpa hambatan guard.
     */
    public function test_valid_sqlite_in_memory_connection_is_allowed(): void
    {
        $connection = DB::build([
            'driver'   => 'sqlite',
            'database' => ':memory:',
        ]);
        $this->assertEquals('sqlite', $connection->getDriverName());
        $this->assertEquals(':memory:', $connection->getConfig('database'));

        // Query in-memory sederhana harus berhasil
        $result = $connection->selectOne('SELECT 1 as val');
        $this->assertEquals(1, $result->val);
    }

    /**
     * 2. Konfigurasi SQLite berbasis file ditolak sebelum file dibuat atau diakses.
     */
    public function test_file_based_sqlite_connection_is_rejected(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('SECURITY VIOLATION: File-based SQLite database');

        DB::build([
            'driver'   => 'sqlite',
            'database' => database_path('unauthorized_test.sqlite'),
        ]);
    }

    /**
     * 3. Konfigurasi MySQL dengan nama database tidak sah (misal database operasional)
     * ditolak pada Tier 1 sebelum koneksi fisik/PDO dibuka.
     */
    public function test_unauthorized_mysql_database_name_is_rejected_at_tier1(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage("SECURITY VIOLATION: Testing attempted connection to unauthorized database [manajemen_skripsi]");

        DB::build([
            'driver'   => 'mysql',
            'host'     => '127.0.0.1',
            'port'     => '3306',
            'database' => 'manajemen_skripsi',
            'username' => 'unuha_tester',
        ]);
    }

    /**
     * 4. Konfigurasi MySQL dengan username selain unuha_tester ditolak pada Tier 1
     * sebelum koneksi fisik dibuka.
     */
    public function test_unauthorized_mysql_username_is_rejected_at_tier1(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage("SECURITY VIOLATION: Testing attempted connection with unauthorized user [root]");

        DB::build([
            'driver'   => 'mysql',
            'host'     => '127.0.0.1',
            'port'     => '3306',
            'database' => 'manajemen_skripsi_test',
            'username' => 'root',
        ]);
    }

    /**
     * 5. Konfigurasi MySQL dengan host remote / tidak sah ditolak pada Tier 1
     * sebelum koneksi fisik dibuka.
     */
    public function test_unauthorized_mysql_host_is_rejected_at_tier1(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage("SECURITY VIOLATION: Testing attempted connection to unauthorized host [192.168.1.100]");

        DB::build([
            'driver'   => 'mysql',
            'host'     => '192.168.1.100',
            'port'     => '3306',
            'database' => 'manajemen_skripsi_test',
            'username' => 'unuha_tester',
        ]);
    }

    /**
     * 6. Konfigurasi MySQL dengan port tidak sah ditolak pada Tier 1
     * sebelum koneksi fisik dibuka.
     */
    public function test_unauthorized_mysql_port_is_rejected_at_tier1(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage("SECURITY VIOLATION: Testing attempted connection to unauthorized port [3307]");

        DB::build([
            'driver'   => 'mysql',
            'host'     => '127.0.0.1',
            'port'     => '3307',
            'database' => 'manajemen_skripsi_test',
            'username' => 'unuha_tester',
        ]);
    }

    /**
     * 7. Driver yang tidak diizinkan pada lingkungan testing (misal pgsql) ditolak seketika.
     */
    public function test_unauthorized_database_driver_is_rejected(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage("SECURITY VIOLATION: Database driver [pgsql] is not allowed in testing mode.");

        DB::build([
            'driver'   => 'pgsql',
            'database' => 'manajemen_skripsi_test',
        ]);
    }

    /**
     * 8. Tier 2: Jika konfigurasi lolos Tier 1 tetapi query verifikasi sesi gagal,
     * guard menolak koneksi secara fail-closed.
     */
    public function test_tier2_session_verification_failure_is_rejected(): void
    {
        $mockConnection = $this->createMock(Connection::class);
        $mockConnection->method('getConfig')->willReturnMap([
            ['driver', null, 'mysql'],
            ['database', null, 'manajemen_skripsi_test'],
            ['username', null, 'unuha_tester'],
            ['host', null, '127.0.0.1'],
            ['port', null, '3306'],
        ]);

        // Simulasikan kegagalan query server (misal network failure atau query error)
        $mockConnection->method('selectOne')
            ->willThrowException(new \PDOException("Server communication lost"));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage("SECURITY VIOLATION: Failed to verify MySQL session identity");

        Event::dispatch(new ConnectionEstablished($mockConnection));
    }

    /**
     * 9. Tier 2: Jika konfigurasi lolos Tier 1 tetapi DATABASE() server tidak cocok,
     * guard menolak koneksi.
     */
    public function test_tier2_active_database_mismatch_is_rejected(): void
    {
        $mockConnection = $this->createMock(Connection::class);
        $mockConnection->method('getConfig')->willReturnMap([
            ['driver', null, 'mysql'],
            ['database', null, 'manajemen_skripsi_test'],
            ['username', null, 'unuha_tester'],
            ['host', null, '127.0.0.1'],
            ['port', null, '3306'],
        ]);

        $mockConnection->method('selectOne')->willReturn((object) [
            'active_db' => 'manajemen_skripsi', // Sesi server tidak cocok dengan konfigurasi
            'auth_user' => 'unuha_tester@localhost',
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage("SECURITY VIOLATION: Active server database is [manajemen_skripsi]. Expected 'manajemen_skripsi_test'.");

        Event::dispatch(new ConnectionEstablished($mockConnection));
    }

    /**
     * 10. Tier 2: Jika konfigurasi lolos Tier 1 tetapi CURRENT_USER() server bukan unuha_tester@localhost
     * (misal menggunakan wildcard atau user lain), guard menolak koneksi.
     */
    public function test_tier2_authenticated_user_mismatch_is_rejected(): void
    {
        $mockConnection = $this->createMock(Connection::class);
        $mockConnection->method('getConfig')->willReturnMap([
            ['driver', null, 'mysql'],
            ['database', null, 'manajemen_skripsi_test'],
            ['username', null, 'unuha_tester'],
            ['host', null, '127.0.0.1'],
            ['port', null, '3306'],
        ]);

        $mockConnection->method('selectOne')->willReturn((object) [
            'active_db' => 'manajemen_skripsi_test',
            'auth_user' => 'unuha_tester@%', // Wildcard ditolak!
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage("SECURITY VIOLATION: Authenticated MySQL user is [unuha_tester@%]. Expected 'unuha_tester@localhost'.");

        Event::dispatch(new ConnectionEstablished($mockConnection));
    }

    /**
     * 11. Tier 2: Jika konfigurasi lolos Tier 1 dan identitas sesi server valid dan cocok,
     * koneksi diterima tanpa exception.
     */
    public function test_tier2_valid_session_identity_is_accepted(): void
    {
        $mockConnection = $this->createMock(Connection::class);
        $mockConnection->method('getConfig')->willReturnMap([
            ['driver', null, 'mysql'],
            ['database', null, 'manajemen_skripsi_test'],
            ['username', null, 'unuha_tester'],
            ['host', null, '127.0.0.1'],
            ['port', null, '3306'],
        ]);

        $mockConnection->method('selectOne')->willReturn((object) [
            'active_db' => 'manajemen_skripsi_test',
            'auth_user' => 'unuha_tester@localhost',
        ]);

        // Tidak boleh melempar exception
        Event::dispatch(new ConnectionEstablished($mockConnection));
        $this->assertTrue(true);
    }

    /**
     * 12. Verifikasi runtime MySQL riil jika dijalankan melalui runner phpunit.mysql.xml.
     * Jika dijalankan dengan runner SQLite standar, pengujian ini diskip secara aman.
     */
    public function test_real_mysql_connection_when_configured(): void
    {
        if (config('database.default') !== 'mysql') {
            $this->markTestSkipped('Test ini hanya aktif saat dijalankan melalui runner phpunit.mysql.xml.');
        }

        $this->assertEquals('testing', app()->environment());
        $this->assertEquals('mysql', config('database.default'));
        $this->assertEquals('manajemen_skripsi_test', config('database.connections.mysql.database'));
        $this->assertEquals('unuha_tester', config('database.connections.mysql.username'));

        // Koneksi riil harus lolos guard dan identitas sesi harus cocok
        $session = DB::selectOne("SELECT DATABASE() as active_db, CURRENT_USER() as auth_user");
        $this->assertEquals('manajemen_skripsi_test', $session->active_db);
        $this->assertEquals('unuha_tester@localhost', $session->auth_user);
    }
}
