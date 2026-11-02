<?php

namespace Tests\Feature;

use App\Notifications\AcademicNotification;
use App\Models\User;
use App\Services\NotificationService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Notifications\DatabaseNotification;
use Ramsey\Uuid\Uuid;
use Tests\TestCase;

class NotificationSecurityFeatureTest extends TestCase
{
    use DatabaseTransactions;

    protected NotificationService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new NotificationService();
    }

    private function buatUser(string $role = 'mahasiswa'): User
    {
        return User::factory()->create([
            'role'        => $role,
            'nomor_induk' => 'NOTIF_' . uniqid(),
            'name'        => 'User ' . uniqid(),
        ]);
    }

    // =======================================================================
    // 1. PENGUJIAN AUTENTIKASI (401 UNAUTHORIZED)
    // =======================================================================

    public function test_01_tamu_tidak_dapat_mengakses_seluruh_endpoint_notifikasi(): void
    {
        $dummyUuid = Uuid::uuid4()->toString();

        $this->getJson('/api/notifications')->assertStatus(401);
        $this->getJson('/api/notifications/unread-count')->assertStatus(401);
        $this->patchJson("/api/notifications/{$dummyUuid}/read")->assertStatus(401);
        $this->patchJson('/api/notifications/mark-all-read')->assertStatus(401);
    }

    // =======================================================================
    // 2. PENGUJIAN ISOLASI DATA DAN ANTI-IDOR
    // =======================================================================

    public function test_02_pengguna_hanya_melihat_notifikasi_miliknya_sendiri(): void
    {
        $userA = $this->buatUser('mahasiswa');
        $userB = $this->buatUser('dosen');

        // Kirim notifikasi ke User A
        $this->service->send(
            $userA,
            'pengajuan_judul',
            'pengajuan_judul:101:submit:log_1',
            [
                'title'   => 'Judul Anda Terkirim',
                'message' => 'Menunggu verifikasi Dosen PA.',
                'url'     => '/dashboardmahasiswa/pengajuan_judul',
            ]
        );

        // Kirim notifikasi ke User B
        $this->service->send(
            $userB,
            'pengajuan_judul',
            'pengajuan_judul:102:submit:log_2',
            [
                'title'   => 'Pengajuan Baru Masuk',
                'message' => 'Mahasiswa mengajukan judul baru.',
                'url'     => '/dashboarddosen/pembimbing_akademik',
            ]
        );

        // User A mengakses /api/notifications
        $responseA = $this->actingAs($userA, 'sanctum')->getJson('/api/notifications');
        $responseA->assertStatus(200);
        $dataA = $responseA->json('data');

        $this->assertCount(1, $dataA);
        $this->assertEquals('Judul Anda Terkirim', $dataA[0]['data']['title']);

        // User B mengakses /api/notifications
        $responseB = $this->actingAs($userB, 'sanctum')->getJson('/api/notifications');
        $responseB->assertStatus(200);
        $dataB = $responseB->json('data');

        $this->assertCount(1, $dataB);
        $this->assertEquals('Pengajuan Baru Masuk', $dataB[0]['data']['title']);
    }

    public function test_03_pengguna_a_tidak_dapat_menandai_dibaca_notifikasi_pengguna_b_anti_idor(): void
    {
        $userA = $this->buatUser('mahasiswa');
        $userB = $this->buatUser('dosen');

        $notifB = $this->service->send(
            $userB,
            'pengajuan_judul',
            'pengajuan_judul:200:submit:log_10',
            [
                'title'   => 'Notifikasi Rahasia Dosen',
                'message' => 'Hanya untuk dosen.',
                'url'     => '/dashboarddosen/pembimbing_akademik',
            ]
        );

        // User A mencoba menandai dibaca notifikasi milik User B
        $this->actingAs($userA, 'sanctum')
            ->patchJson("/api/notifications/{$notifB->id}/read")
            ->assertStatus(404)
            ->assertJson([
                'status'  => 'error',
                'message' => 'Notifikasi tidak ditemukan atau Anda tidak memiliki akses.',
            ]);

        // Verifikasi bahwa read_at pada notifikasi User B tetap NULL
        $this->assertNull($notifB->fresh()->read_at);
    }

    public function test_04_mark_all_read_pengguna_a_tidak_mengubah_notifikasi_pengguna_b(): void
    {
        $userA = $this->buatUser('mahasiswa');
        $userB = $this->buatUser('dosen');

        $this->service->send(
            $userA,
            'pengajuan_judul',
            'pengajuan_judul:301:submit:log_1',
            ['title' => 'Notif A1', 'message' => 'A1', 'url' => '/dashboardmahasiswa']
        );
        $this->service->send(
            $userA,
            'pengajuan_judul',
            'pengajuan_judul:301:revisi:log_2',
            ['title' => 'Notif A2', 'message' => 'A2', 'url' => '/dashboardmahasiswa']
        );

        $notifB = $this->service->send(
            $userB,
            'pengajuan_judul',
            'pengajuan_judul:302:submit:log_1',
            ['title' => 'Notif B', 'message' => 'B', 'url' => '/dashboarddosen']
        );

        // User A menandai semua notifikasinya dibaca
        $this->actingAs($userA, 'sanctum')
            ->patchJson('/api/notifications/mark-all-read')
            ->assertStatus(200)
            ->assertJson([
                'status' => 'success',
                'data'   => [
                    'updated_count' => 2,
                ],
            ]);

        // Pastikan unread count User A menjadi 0
        $this->actingAs($userA, 'sanctum')
            ->getJson('/api/notifications/unread-count')
            ->assertStatus(200)
            ->assertJsonPath('data.unread_count', 0);

        // Pastikan notifikasi User B TETAP unread
        $this->assertNull($notifB->fresh()->read_at);

        $this->actingAs($userB, 'sanctum')
            ->getJson('/api/notifications/unread-count')
            ->assertStatus(200)
            ->assertJsonPath('data.unread_count', 1);
    }

    // =======================================================================
    // 3. PENGUJIAN FUNGSIONAL DAN PAGINASI
    // =======================================================================

    public function test_05_paginasi_dan_pengurutan_stabil_berjalan_benar(): void
    {
        $user = $this->buatUser('mahasiswa');

        for ($i = 1; $i <= 5; $i++) {
            $n = $this->service->send(
                $user,
                'pengajuan_judul',
                "pengajuan_judul:400:test:log_{$i}",
                ['title' => "Notif {$i}", 'message' => "Pesan {$i}", 'url' => '/dashboardmahasiswa']
            );
            $n->update(['created_at' => now()->subMinutes(10 - $i)]);
        }

        // Minta per_page = 2
        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/notifications?per_page=2');

        $response->assertStatus(200)
            ->assertJsonPath('meta.total', 5)
            ->assertJsonPath('meta.per_page', 2)
            ->assertJsonPath('meta.current_page', 1)
            ->assertJsonPath('meta.last_page', 3);

        $data = $response->json('data');
        $this->assertCount(2, $data);
        // Notif terbaru harus berada di awal (Notif 5)
        $this->assertEquals('Notif 5', $data[0]['data']['title']);
        $this->assertEquals('Notif 4', $data[1]['data']['title']);
    }

    public function test_06_tandai_satu_notifikasi_dibaca_bersifat_idempoten(): void
    {
        $user = $this->buatUser('mahasiswa');

        $notif = $this->service->send(
            $user,
            'pengajuan_judul',
            'pengajuan_judul:500:submit:log_1',
            ['title' => 'Notif Tunggal', 'message' => 'Pesan', 'url' => '/dashboardmahasiswa']
        );

        $this->assertNull($notif->read_at);

        // Tandai dibaca pertama kali
        $res1 = $this->actingAs($user, 'sanctum')->patchJson("/api/notifications/{$notif->id}/read");
        $res1->assertStatus(200);
        $readAt1 = $res1->json('data.read_at');
        $this->assertNotNull($readAt1);

        // Tandai dibaca kedua kali (harus idempoten dan tidak error)
        $res2 = $this->actingAs($user, 'sanctum')->patchJson("/api/notifications/{$notif->id}/read");
        $res2->assertStatus(200);
        $this->assertEquals($readAt1, $res2->json('data.read_at'));
    }

    // =======================================================================
    // 4. PENGUJIAN IDEMPOTENSI DAN DETERMINISME UUID V5
    // =======================================================================

    public function test_07_uuid_v5_identik_untuk_event_dan_user_yang_sama(): void
    {
        $user = $this->buatUser('mahasiswa');
        $eventKey = 'pengajuan_judul:600:submit:log_1';

        $notifA = new AcademicNotification($user->id, 'pengajuan_judul', $eventKey, [
            'title' => 'Tes A', 'message' => 'M', 'url' => '/'
        ]);
        $notifB = new AcademicNotification($user->id, 'pengajuan_judul', $eventKey, [
            'title' => 'Tes B', 'message' => 'M', 'url' => '/'
        ]);

        $this->assertEquals($notifA->id, $notifB->id);
        $this->assertTrue(Uuid::isValid($notifA->id));
        $this->assertEquals(5, Uuid::fromString($notifA->id)->getFields()->getVersion());
    }

    public function test_08_uuid_v5_berbeda_untuk_penerima_yang_berbeda(): void
    {
        $user1 = $this->buatUser('mahasiswa');
        $user2 = $this->buatUser('dosen');
        $eventKey = 'pengajuan_judul:700:submit:log_1';

        $notif1 = new AcademicNotification($user1->id, 'pengajuan_judul', $eventKey, [
            'title' => 'Tes', 'message' => 'M', 'url' => '/'
        ]);
        $notif2 = new AcademicNotification($user2->id, 'pengajuan_judul', $eventKey, [
            'title' => 'Tes', 'message' => 'M', 'url' => '/'
        ]);

        $this->assertNotEquals($notif1->id, $notif2->id);
    }

    public function test_09_eksekusi_berulang_event_yang_sama_tidak_membuat_record_duplikat(): void
    {
        $user = $this->buatUser('mahasiswa');
        $eventKey = 'pengajuan_judul:800:setujui_dosen_pa:log_5';

        // Pengiriman pertama
        $stored1 = $this->service->send($user, 'pengajuan_judul', $eventKey, [
            'title' => 'Disetujui', 'message' => 'Pesan 1', 'url' => '/dashboardmahasiswa'
        ]);

        // Pengiriman kedua (simulasi worker retry atau concurrent dispatch)
        $stored2 = $this->service->send($user, 'pengajuan_judul', $eventKey, [
            'title' => 'Disetujui', 'message' => 'Pesan 1', 'url' => '/dashboardmahasiswa'
        ]);

        $this->assertEquals($stored1->id, $stored2->id);

        // Pastikan hanya ada tepat 1 baris di database untuk pengguna ini
        $totalNotif = DatabaseNotification::where('notifiable_id', $user->id)->count();
        $this->assertEquals(1, $totalNotif);
    }

    public function test_10_anomali_benturan_primary_key_dengan_payload_berbeda_melempar_exception(): void
    {
        $userA = $this->buatUser('mahasiswa');
        $userB = $this->buatUser('dosen');

        // Simpan notifikasi sah milik User A
        $notifA = $this->service->send($userA, 'pengajuan_judul', 'pengajuan_judul:900:submit:log_1', [
            'title' => 'Notif A', 'message' => 'M', 'url' => '/'
        ]);

        // Simulasikan anomali benturan: User B secara tidak wajar menghasilkan UUID yang sama dengan notifA
        // Kita panggil class Notification turunan yang memaksakan ID = notifA->id
        $mockNotification = new class($notifA->id) extends AcademicNotification {
            public function __construct(string $forcedId) {
                $this->id = $forcedId;
                $this->eventIdentity = 'pengajuan_judul:900:submit:log_1';
                $this->eventType = 'pengajuan_judul';
                $this->title = 'Fake';
                $this->message = 'Fake';
                $this->url = '/';
                $this->metadata = [];
            }
        };

        // Ketika service mencoba menangani duplicate key, harus mendeteksi bahwa user_id tidak cocok
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage("Benturan integritas notifikasi terdeteksi pada ID [{$notifA->id}]");

        // Panggil sendNotification via service untuk memicu validasi anomali
        $this->service->sendNotification($userB, $mockNotification);
    }

    public function test_11_validasi_url_menolak_path_eksternal_atau_protokol_arbitrary(): void
    {
        $user = $this->buatUser('mahasiswa');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("URL notifikasi harus berupa path relatif internal");

        new AcademicNotification($user->id, 'pengajuan_judul', 'pengajuan_judul:999:submit:log_1', [
            'title'   => 'Judul',
            'message' => 'Pesan',
            'url'     => 'https://malicious-site.com/phishing',
        ]);
    }
}
