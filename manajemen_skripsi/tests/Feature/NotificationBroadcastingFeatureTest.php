<?php

namespace Tests\Feature;

use App\Events\AcademicNotificationBroadcasted;
use App\Models\User;
use App\Services\NotificationService;
use Illuminate\Broadcasting\BroadcastEvent;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class NotificationBroadcastingFeatureTest extends TestCase
{
    use DatabaseTransactions;

    protected NotificationService $service;

    protected function setUp(): void
    {
        parent::setUp();
        config(['broadcasting.default' => 'reverb']);
        require base_path('routes/channels.php');
        $this->service = new NotificationService();
    }

    private function buatUser(string $role = 'mahasiswa'): User
    {
        return User::factory()->create([
            'role'        => $role,
            'nomor_induk' => 'BROADCAST_' . uniqid(),
            'name'        => 'User ' . uniqid(),
        ]);
    }

    // =======================================================================
    // 1. OTORISASI PRIVATE CHANNEL & ANTI-IDOR (/broadcasting/auth)
    // =======================================================================

    public function test_01_tamu_tidak_dapat_mengakses_endpoint_broadcasting_auth(): void
    {
        $response = $this->postJson('/broadcasting/auth', [
            'channel_name' => 'private-users.1.notifications',
            'socket_id'    => '1234.5678',
        ]);

        $response->assertStatus(401);
    }

    public function test_02_pengguna_terautentikasi_berhasil_diotorisasi_pada_private_channel_miliknya(): void
    {
        $user = $this->buatUser('mahasiswa');

        $response = $this->actingAs($user, 'sanctum')->postJson('/broadcasting/auth', [
            'channel_name' => "private-users.{$user->id}.notifications",
            'socket_id'    => '1234.5678',
        ]);

        $response->assertStatus(200);
        $this->assertArrayHasKey('auth', $response->json());
    }

    public function test_03_pengguna_tidak_dapat_berlangganan_ke_channel_milik_pengguna_lain_anti_idor(): void
    {
        $userA = $this->buatUser('mahasiswa');
        $userB = $this->buatUser('dosen');

        // User A mencoba mengotorisasi dirinya untuk channel User B
        $response = $this->actingAs($userA, 'sanctum')->postJson('/broadcasting/auth', [
            'channel_name' => "private-users.{$userB->id}.notifications",
            'socket_id'    => '1234.5678',
        ]);

        $response->assertStatus(403);
    }

    // =======================================================================
    // 2. STRUKTUR DAN PRIVASI PAYLOAD BROADCAST
    // =======================================================================

    public function test_04_payload_broadcast_tidak_mengandung_informasi_sensitif(): void
    {
        $user = $this->buatUser('mahasiswa');

        $stored = $this->service->send($user, 'pengajuan_judul', 'pengajuan_judul:1001:submit:log_1', [
            'title'    => 'Pengajuan Disetujui',
            'message'  => 'Dosen PA telah menyetujui judul Anda.',
            'url'      => '/dashboardmahasiswa/pengajuan_judul',
            'metadata' => ['id_pengajuan' => 1001],
        ]);

        $event = new AcademicNotificationBroadcasted($user->id, $stored);
        $payload = $event->broadcastWith();

        // Verifikasi field yang harus ada
        $this->assertEquals($stored->id, $payload['id']);
        $this->assertEquals('pengajuan_judul:1001:submit:log_1', $payload['event_identity']);
        $this->assertEquals('pengajuan_judul', $payload['event_type']);
        $this->assertEquals('Pengajuan Disetujui', $payload['title']);
        $this->assertEquals('Dosen PA telah menyetujui judul Anda.', $payload['message']);
        $this->assertEquals('/dashboardmahasiswa/pengajuan_judul', $payload['url']);
        $this->assertEquals(['id_pengajuan' => 1001], $payload['metadata']);
        $this->assertArrayHasKey('created_at', $payload);

        // Verifikasi tidak ada field sensitif
        $this->assertArrayNotHasKey('password', $payload);
        $this->assertArrayNotHasKey('remember_token', $payload);
        $this->assertArrayNotHasKey('email', $payload);
        $this->assertArrayNotHasKey('token', $payload);
    }

    public function test_05_event_broadcast_dispatched_dengan_identitas_dan_channel_yang_sesuai(): void
    {
        Event::fake([AcademicNotificationBroadcasted::class]);

        $user = $this->buatUser('mahasiswa');

        $stored = $this->service->send($user, 'pengajuan_judul', 'pengajuan_judul:1002:submit:log_1', [
            'title'   => 'Notif Uji',
            'message' => 'Pesan Uji',
            'url'     => '/dashboardmahasiswa',
        ]);

        Event::assertDispatched(AcademicNotificationBroadcasted::class, function ($event) use ($user, $stored) {
            $channels = $event->broadcastOn();
            return (int) $event->userId === (int) $user->id
                && $event->notificationId === $stored->id
                && $event->eventIdentity === 'pengajuan_judul:1002:submit:log_1'
                && $event->broadcastAs() === 'academic.notification'
                && count($channels) === 1
                && $channels[0]->name === "private-users.{$user->id}.notifications";
        });
    }

    // =======================================================================
    // 3. ATOMISITAS TRANSAKSI BASIS DATA & AFTER-COMMIT
    // =======================================================================

    public function test_06_properti_after_commit_bernilai_true_pada_event(): void
    {
        $user = $this->buatUser('mahasiswa');
        $stored = $this->service->send($user, 'pengajuan_judul', 'pengajuan_judul:1003:submit:log_1', [
            'title'   => 'Uji After Commit',
            'message' => 'Pesan',
            'url'     => '/',
        ]);

        $event = new AcademicNotificationBroadcasted($user->id, $stored);
        $this->assertTrue($event->afterCommit, 'Event broadcasting wajib memiliki property $afterCommit = true.');
    }

    public function test_06b_broadcast_event_job_mewarisi_properti_after_commit(): void
    {
        $user = $this->buatUser('mahasiswa');
        $stored = $this->service->send($user, 'pengajuan_judul', 'pengajuan_judul:1003b:job:log_1', [
            'title'   => 'Tes Job Broadcast',
            'message' => 'Pesan',
            'url'     => '/',
        ]);

        $event = new AcademicNotificationBroadcasted($user->id, $stored);
        $job = new BroadcastEvent($event);

        $this->assertTrue($job->afterCommit, 'Job BroadcastEvent harus mewarisi properti afterCommit dari event.');
    }

    public function test_07_rollback_transaksi_tidak_menghasilkan_record_database(): void
    {
        $user = $this->buatUser('mahasiswa');
        $eventKey = 'pengajuan_judul:1004:rollback:log_1';

        try {
            DB::transaction(function () use ($user, $eventKey) {
                $this->service->send($user, 'pengajuan_judul', $eventKey, [
                    'title'   => 'Notif Rollback',
                    'message' => 'Pesan',
                    'url'     => '/',
                ]);

                // Paksa transaksi gagal dan rollback
                throw new \Exception('Simulasi kegagalan transaksi alur bisnis.');
            });
        } catch (\Exception $e) {
            $this->assertEquals('Simulasi kegagalan transaksi alur bisnis.', $e->getMessage());
        }

        // Pastikan tidak ada notifikasi yang tersimpan di database
        $count = DatabaseNotification::where('notifiable_id', $user->id)->count();
        $this->assertEquals(0, $count);
    }

    // =======================================================================
    // 4. IDEMPOTENSI DAN PENGIRIMAN BERULANG
    // =======================================================================

    public function test_08_pengiriman_berulang_idempoten_tidak_membuat_record_duplikat_dan_tidak_rebroadcast(): void
    {
        $user = $this->buatUser('mahasiswa');
        $eventKey = 'pengajuan_judul:1005:retry:log_1';

        // Pengiriman pertama (fresh creation)
        $notif1 = $this->service->send($user, 'pengajuan_judul', $eventKey, [
            'title'   => 'Notif Asli',
            'message' => 'Pesan Asli',
            'url'     => '/',
        ]);

        $this->assertNotNull($notif1);

        // Simulasi pengiriman berulang: Kita pasang Event Fake untuk menangkap apakah event kedua di-dispatch
        Event::fake([AcademicNotificationBroadcasted::class]);

        $notif2 = $this->service->send($user, 'pengajuan_judul', $eventKey, [
            'title'   => 'Notif Asli',
            'message' => 'Pesan Asli',
            'url'     => '/',
        ]);

        $this->assertEquals($notif1->id, $notif2->id);

        // Jumlah baris di basis data tetap tepat 1
        $count = DatabaseNotification::where('notifiable_id', $user->id)->count();
        $this->assertEquals(1, $count);

        // Event broadcasting TIDAK di-dispatch ulang karena record bukan baru dibuat (idempotent bypass)
        Event::assertNotDispatched(AcademicNotificationBroadcasted::class);
    }
}
