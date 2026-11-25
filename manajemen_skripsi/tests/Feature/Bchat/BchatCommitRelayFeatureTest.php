<?php

declare(strict_types=1);

namespace Tests\Feature\Bchat;

use App\Events\BchatCommitPublished;
use App\Models\BchatCommit;
use App\Models\BchatCommitAck;
use App\Models\BchatKeyPackage;
use App\Models\BchatMessage;
use App\Models\BchatRoom;
use App\Models\BchatRoomMember;
use App\Models\BchatRoomSession;
use App\Models\BchatUserDevice;
use App\Models\Dosen;
use App\Models\Mahasiswa;
use App\Models\PenugasanPembimbing;
use App\Models\Skripsi;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * PENGUJIAN INTEGRASI TERISOLASI B-CHAT OPSI B — COMMIT RELAY (GERBANG 2)
 *
 * Menguji integrasi nyata pada database manajemen_skripsi_test:
 * - 10 Skenario Wajib alur Commit Relay, Two-Phase Epoch, ACK, Reconnect, Idempotensi, & History.
 * - 4 Skenario Pengujian Proteksi Keamanan & Invariant.
 */
class BchatCommitRelayFeatureTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        $activeDb = DB::connection()->getDatabaseName();
        $this->assertEquals(
            'manajemen_skripsi_test',
            $activeDb,
            'CRITICAL SECURITY: Test suite B-CHAT HANYA boleh berjalan pada database manajemen_skripsi_test.'
        );

        if (Schema::hasTable('bchat_commits')) {
            if (!Schema::hasColumn('bchat_commits', 'key_package_id')) {
                $migration = require database_path('migrations/2026_11_20_000026_create_bchat_commits_table.php');
                $migration->down();
                $migration->up();
            }
        } else {
            $migration = require database_path('migrations/2026_11_20_000026_create_bchat_commits_table.php');
            $migration->up();
        }
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function createDevice(User $user, ?array $keyPair = null, string $name = 'Test Device'): array
    {
        if (!$keyPair) {
            $keyPair = sodium_crypto_sign_keypair();
        }
        $pubKey = sodium_crypto_sign_publickey($keyPair);
        $privKey = sodium_crypto_sign_secretkey($keyPair);

        $device = BchatUserDevice::create([
            'user_id'                => $user->id,
            'device_uuid'            => (string) Str::uuid(),
            'device_name'            => $name,
            'public_key'             => bin2hex($pubKey),
            'public_key_fingerprint' => hash('sha256', $pubKey),
            'status'                 => 'ACTIVE',
            'is_trusted'             => 1,
            'trusted_at'             => now(),
        ]);

        return [
            'device'  => $device,
            'pubKey'  => $pubKey,
            'privKey' => $privKey,
        ];
    }

    private function createKeyPackage(User $user, BchatUserDevice $device): BchatKeyPackage
    {
        return BchatKeyPackage::create([
            'user_id'          => $user->id,
            'device_id'        => $device->id,
            'key_package_hash' => hash('sha256', Str::random(32)),
            'key_package_data' => random_bytes(64),
            'is_consumed'      => false,
            'consumed_at'      => null,
        ]);
    }

    private function setupActiveRoomWithMembers(): array
    {
        $userMhs = User::factory()->create([
            'role'        => 'mahasiswa',
            'nomor_induk' => (string) rand(1000000000, 9999999999),
        ]);
        $userDsn = User::factory()->create([
            'role'        => 'dosen',
            'nomor_induk' => (string) rand(1000000000, 9999999999),
        ]);

        $mhs = Mahasiswa::create([
            'user_id'        => $userMhs->id,
            'NIM'            => $userMhs->nomor_induk,
            'nama_mahasiswa' => 'Mahasiswa Test ' . Str::random(4),
            'prodi'          => 'Informatika',
        ]);

        $skripsi = Skripsi::create([
            'id_mahasiswa' => $mhs->id_mahasiswa,
            'judul'        => 'Proposal E2EE Feature Test ' . Str::random(5),
            'status_judul' => 'diajukan',
        ]);

        $dosen = Dosen::create([
            'user_id'    => $userDsn->id,
            'NIDN'       => $userDsn->nomor_induk,
            'nama_dosen' => 'Dosen Pembimbing Test ' . Str::random(4),
            'prodi'      => 'Informatika',
        ]);

        $penugasan = PenugasanPembimbing::create([
            'skripsi_id'       => $skripsi->id_skripsi,
            'tahap'            => 'proposal',
            'pembimbing_ke'    => 1,
            'dosen_id'         => $dosen->id_dosen,
            'status_penugasan' => 'AKTIF',
            'tanggal_mulai'    => now()->toDateString(),
        ]);

        $room = BchatRoom::create([
            'room_uuid'               => (string) Str::uuid(),
            'penugasan_pembimbing_id' => $penugasan->id,
            'tahap'                   => 'proposal',
            'pembimbing_ke'           => 1,
            'status'                  => 'ACTIVE',
            'current_epoch'           => 1,
            'current_sequence'        => 10,
        ]);

        BchatRoomMember::create([
            'room_id'   => $room->id,
            'user_id'   => $userMhs->id,
            'role'      => 'mahasiswa',
            'is_active' => true,
        ]);

        BchatRoomMember::create([
            'room_id'   => $room->id,
            'user_id'   => $userDsn->id,
            'role'      => 'dosen_pembimbing',
            'is_active' => true,
        ]);

        $session = BchatRoomSession::create([
            'session_uuid'       => (string) Str::uuid(),
            'room_id'            => $room->id,
            'generation_number'  => 1,
            'status'             => 'ACTIVE',
            'start_epoch'        => 1,
            'start_sequence'     => 1,
            'current_sequence'   => 10,
            'created_by_user_id' => $userMhs->id,
            'activated_at'       => now(),
        ]);

        $devMhs = $this->createDevice($userMhs, null, 'Mahasiswa Device 4');
        $devChrome = $this->createDevice($userDsn, null, 'Dosen Chrome Device 3');
        $devEdge = $this->createDevice($userDsn, null, 'Dosen Edge Device 5');

        return [
            'room'      => $room,
            'session'   => $session,
            'userMhs'   => $userMhs,
            'userDsn'   => $userDsn,
            'devMhs'    => $devMhs,
            'devChrome' => $devChrome,
            'devEdge'   => $devEdge,
        ];
    }

    // =========================================================================
    // 10 SKENARIO WAJIB GERBANG 2: PENGUJIAN TERPADU
    // =========================================================================

    /**
     * Skenario 1: Commit diterbitkan tanpa langsung menaikkan current_epoch
     */
    public function test_skenario_1_commit_published_without_premature_epoch_advance(): void
    {
        Event::fake([BchatCommitPublished::class]);
        $ctx = $this->setupActiveRoomWithMembers();
        Sanctum::actingAs($ctx['userMhs']);

        $kpEdge = $this->createKeyPackage($ctx['userDsn'], $ctx['devEdge']['device']);

        $response = $this->postJson("/api/bchat/rooms/{$ctx['room']->room_uuid}/commits", [
            'device_uuid'         => $ctx['devMhs']['device']->device_uuid,
            'from_epoch'          => 1,
            'to_epoch'            => 2,
            'commit_wire_base64'  => base64_encode(random_bytes(64)),
            'added_device_uuid'   => $ctx['devEdge']['device']->device_uuid,
            'key_package_hash'    => $kpEdge->key_package_hash,
            'welcome_wire_base64' => base64_encode(random_bytes(64)),
        ]);

        $response->assertStatus(201);
        $response->assertJson([
            'success' => true,
            'data'    => [
                'from_epoch' => '1',
                'to_epoch'   => '2',
                'status'     => 'PENDING_ACKS',
            ],
        ]);

        // Bukti DB Pengujian: room.current_epoch TETAP 1!
        $this->assertEquals(1, $ctx['room']->fresh()->current_epoch);

        // Bukti DB Pengujian: status commit PENDING_ACKS
        $commit = BchatCommit::where('room_id', $ctx['room']->id)->first();
        $this->assertNotNull($commit);
        $this->assertEquals('PENDING_ACKS', $commit->status);

        // Bukti DB Pengujian: KeyPackage terkonsumsi atomik
        $this->assertTrue((bool) $kpEdge->fresh()->is_consumed);
        $this->assertNotNull($kpEdge->fresh()->consumed_at);

        Event::assertDispatched(BchatCommitPublished::class);
    }

    /**
     * Skenario 2: Perangkat lama belum ACK: transisi tetap tertahan
     */
    public function test_skenario_2_existing_device_not_acked_transition_remains_pending(): void
    {
        $ctx = $this->setupActiveRoomWithMembers();
        Sanctum::actingAs($ctx['userMhs']);

        $kpEdge = $this->createKeyPackage($ctx['userDsn'], $ctx['devEdge']['device']);
        $pubRes = $this->postJson("/api/bchat/rooms/{$ctx['room']->room_uuid}/commits", [
            'device_uuid'         => $ctx['devMhs']['device']->device_uuid,
            'from_epoch'          => 1,
            'to_epoch'            => 2,
            'commit_wire_base64'  => base64_encode(random_bytes(64)),
            'added_device_uuid'   => $ctx['devEdge']['device']->device_uuid,
            'key_package_hash'    => $kpEdge->key_package_hash,
            'welcome_wire_base64' => base64_encode(random_bytes(64)),
        ]);
        $commitUuid = $pubRes->json('data.commit_uuid');

        // Edge (perangkat baru) mengirim WELCOME_ACK duluan, tapi Chrome (lama) BELUM ACK
        Sanctum::actingAs($ctx['userDsn']);
        $canonicalEdge = "WELCOME_ACK:{$ctx['room']->room_uuid}:{$ctx['session']->session_uuid}:{$commitUuid}:{$ctx['devEdge']['device']->device_uuid}:2";
        $sigEdge = sodium_crypto_sign_detached($canonicalEdge, $ctx['devEdge']['privKey']);

        $ackRes = $this->postJson("/api/bchat/rooms/{$ctx['room']->room_uuid}/commits/{$commitUuid}/welcome-ack", [
            'device_uuid' => $ctx['devEdge']['device']->device_uuid,
            'acked_epoch' => 2,
            'signature'   => bin2hex($sigEdge),
        ]);
        $ackRes->assertStatus(200);
        $ackRes->assertJson(['is_complete' => false]);

        // Transisi tetap tertahan di PENDING_ACKS dan epoch tetap 1!
        $this->assertEquals(1, $ctx['room']->fresh()->current_epoch);
        $this->assertEquals('PENDING_ACKS', BchatCommit::where('commit_uuid', $commitUuid)->value('status'));

        // Pesan aplikasi tetap diblokir
        Sanctum::actingAs($ctx['userMhs']);
        $dummyPayload = random_bytes(32);
        $msgRes = $this->postJson("/api/bchat/rooms/{$ctx['room']->room_uuid}/messages", [
            'device_uuid'         => $ctx['devMhs']['device']->device_uuid,
            'message_uuid'        => (string) Str::uuid(),
            'epoch'               => 1,
            'generation'          => 1,
            'content_type'        => 1,
            'ciphertext_payload'  => base64_encode($dummyPayload),
            'payload_hash_sha256' => hash('sha256', $dummyPayload),
            'client_sent_at'      => now()->toIso8601String(),
        ]);
        $msgRes->assertStatus(409);
        $msgRes->assertJson(['error_code' => 'ROOM_TRANSITIONING_EPOCH']);
    }

    /**
     * Skenario 3: Perangkat baru belum WELCOME_ACK: transisi tetap tertahan
     */
    public function test_skenario_3_new_device_not_welcome_acked_transition_remains_pending(): void
    {
        $ctx = $this->setupActiveRoomWithMembers();
        Sanctum::actingAs($ctx['userMhs']);

        $kpEdge = $this->createKeyPackage($ctx['userDsn'], $ctx['devEdge']['device']);
        $pubRes = $this->postJson("/api/bchat/rooms/{$ctx['room']->room_uuid}/commits", [
            'device_uuid'         => $ctx['devMhs']['device']->device_uuid,
            'from_epoch'          => 1,
            'to_epoch'            => 2,
            'commit_wire_base64'  => base64_encode(random_bytes(64)),
            'added_device_uuid'   => $ctx['devEdge']['device']->device_uuid,
            'key_package_hash'    => $kpEdge->key_package_hash,
            'welcome_wire_base64' => base64_encode(random_bytes(64)),
        ]);
        $commitUuid = $pubRes->json('data.commit_uuid');

        // Chrome (lama) mengirim COMMIT_ACK, tapi Edge (baru) BELUM kirim WELCOME_ACK
        Sanctum::actingAs($ctx['userDsn']);
        $canonicalChrome = "COMMIT_ACK:{$ctx['room']->room_uuid}:{$ctx['session']->session_uuid}:{$commitUuid}:{$ctx['devChrome']['device']->device_uuid}:2";
        $sigChrome = sodium_crypto_sign_detached($canonicalChrome, $ctx['devChrome']['privKey']);

        $ackRes = $this->postJson("/api/bchat/rooms/{$ctx['room']->room_uuid}/commits/{$commitUuid}/ack", [
            'device_uuid' => $ctx['devChrome']['device']->device_uuid,
            'acked_epoch' => 2,
            'signature'   => bin2hex($sigChrome),
        ]);
        $ackRes->assertStatus(200);
        $ackRes->assertJson(['is_complete' => false]);

        // Transisi tetap tertahan di PENDING_ACKS dan epoch tetap 1!
        $this->assertEquals(1, $ctx['room']->fresh()->current_epoch);
        $this->assertEquals('PENDING_ACKS', BchatCommit::where('commit_uuid', $commitUuid)->value('status'));
    }

    /**
     * Skenario 4: Kedua jenis ACK lengkap: epoch baru aktif
     */
    public function test_skenario_4_both_acks_complete_advances_epoch_to_active(): void
    {
        $ctx = $this->setupActiveRoomWithMembers();
        Sanctum::actingAs($ctx['userMhs']);

        $kpEdge = $this->createKeyPackage($ctx['userDsn'], $ctx['devEdge']['device']);
        $pubRes = $this->postJson("/api/bchat/rooms/{$ctx['room']->room_uuid}/commits", [
            'device_uuid'         => $ctx['devMhs']['device']->device_uuid,
            'from_epoch'          => 1,
            'to_epoch'            => 2,
            'commit_wire_base64'  => base64_encode(random_bytes(64)),
            'added_device_uuid'   => $ctx['devEdge']['device']->device_uuid,
            'key_package_hash'    => $kpEdge->key_package_hash,
            'welcome_wire_base64' => base64_encode(random_bytes(64)),
        ]);
        $commitUuid = $pubRes->json('data.commit_uuid');

        Sanctum::actingAs($ctx['userDsn']);

        // 1. Chrome COMMIT_ACK
        $canonicalChrome = "COMMIT_ACK:{$ctx['room']->room_uuid}:{$ctx['session']->session_uuid}:{$commitUuid}:{$ctx['devChrome']['device']->device_uuid}:2";
        $sigChrome = sodium_crypto_sign_detached($canonicalChrome, $ctx['devChrome']['privKey']);
        $this->postJson("/api/bchat/rooms/{$ctx['room']->room_uuid}/commits/{$commitUuid}/ack", [
            'device_uuid' => $ctx['devChrome']['device']->device_uuid,
            'acked_epoch' => 2,
            'signature'   => bin2hex($sigChrome),
        ]);

        // 2. Edge WELCOME_ACK
        $canonicalEdge = "WELCOME_ACK:{$ctx['room']->room_uuid}:{$ctx['session']->session_uuid}:{$commitUuid}:{$ctx['devEdge']['device']->device_uuid}:2";
        $sigEdge = sodium_crypto_sign_detached($canonicalEdge, $ctx['devEdge']['privKey']);
        $ackRes2 = $this->postJson("/api/bchat/rooms/{$ctx['room']->room_uuid}/commits/{$commitUuid}/welcome-ack", [
            'device_uuid' => $ctx['devEdge']['device']->device_uuid,
            'acked_epoch' => 2,
            'signature'   => bin2hex($sigEdge),
        ]);

        $ackRes2->assertStatus(200);
        $ackRes2->assertJson([
            'is_complete' => true,
            'status'      => 'ACTIVE',
        ]);

        // Verifikasi mutasi state database pengujian
        $this->assertEquals(2, $ctx['room']->fresh()->current_epoch);
        $commit = BchatCommit::where('commit_uuid', $commitUuid)->first();
        $this->assertEquals('ACTIVE', $commit->status);
        $this->assertNotNull($commit->transition_completed_at);
    }

    /**
     * Skenario 5: Perangkat lama dan baru dapat bertukar pesan setelah aktivasi
     */
    public function test_skenario_5_old_and_new_devices_exchange_messages_after_activation(): void
    {
        $ctx = $this->setupActiveRoomWithMembers();
        Sanctum::actingAs($ctx['userMhs']);

        // Jalankan alur aktivasi lengkap -> Epoch 2
        $kpEdge = $this->createKeyPackage($ctx['userDsn'], $ctx['devEdge']['device']);
        $pubRes = $this->postJson("/api/bchat/rooms/{$ctx['room']->room_uuid}/commits", [
            'device_uuid'         => $ctx['devMhs']['device']->device_uuid,
            'from_epoch'          => 1,
            'to_epoch'            => 2,
            'commit_wire_base64'  => base64_encode(random_bytes(64)),
            'added_device_uuid'   => $ctx['devEdge']['device']->device_uuid,
            'key_package_hash'    => $kpEdge->key_package_hash,
            'welcome_wire_base64' => base64_encode(random_bytes(64)),
        ]);
        $commitUuid = $pubRes->json('data.commit_uuid');

        Sanctum::actingAs($ctx['userDsn']);
        $sigChrome = sodium_crypto_sign_detached("COMMIT_ACK:{$ctx['room']->room_uuid}:{$ctx['session']->session_uuid}:{$commitUuid}:{$ctx['devChrome']['device']->device_uuid}:2", $ctx['devChrome']['privKey']);
        $this->postJson("/api/bchat/rooms/{$ctx['room']->room_uuid}/commits/{$commitUuid}/ack", [
            'device_uuid' => $ctx['devChrome']['device']->device_uuid,
            'acked_epoch' => 2,
            'signature'   => bin2hex($sigChrome),
        ]);

        $sigEdge = sodium_crypto_sign_detached("WELCOME_ACK:{$ctx['room']->room_uuid}:{$ctx['session']->session_uuid}:{$commitUuid}:{$ctx['devEdge']['device']->device_uuid}:2", $ctx['devEdge']['privKey']);
        $this->postJson("/api/bchat/rooms/{$ctx['room']->room_uuid}/commits/{$commitUuid}/welcome-ack", [
            'device_uuid' => $ctx['devEdge']['device']->device_uuid,
            'acked_epoch' => 2,
            'signature'   => bin2hex($sigEdge),
        ]);

        $this->assertEquals(2, $ctx['room']->fresh()->current_epoch);

        // A. Edge (perangkat baru) mengirim pesan di Epoch 2
        $edgePayload = random_bytes(48);
        $edgeMsgUuid = (string) Str::uuid();
        $msgResEdge = $this->postJson("/api/bchat/rooms/{$ctx['room']->room_uuid}/messages", [
            'device_uuid'         => $ctx['devEdge']['device']->device_uuid,
            'message_uuid'        => $edgeMsgUuid,
            'epoch'               => 2,
            'generation'          => 1,
            'content_type'        => 1,
            'ciphertext_payload'  => base64_encode($edgePayload),
            'payload_hash_sha256' => hash('sha256', $edgePayload),
            'client_sent_at'      => now()->toIso8601String(),
        ]);
        $msgResEdge->assertStatus(201);
        $this->assertDatabaseHas('bchat_messages', [
            'room_id'      => $ctx['room']->id,
            'sender_id'    => $ctx['userDsn']->id,
            'message_uuid' => $edgeMsgUuid,
            'epoch'        => 2,
        ]);

        // B. Mahasiswa membalas di Epoch 2
        Sanctum::actingAs($ctx['userMhs']);
        $mhsPayload = random_bytes(48);
        $mhsMsgUuid = (string) Str::uuid();
        $msgResMhs = $this->postJson("/api/bchat/rooms/{$ctx['room']->room_uuid}/messages", [
            'device_uuid'         => $ctx['devMhs']['device']->device_uuid,
            'message_uuid'        => $mhsMsgUuid,
            'epoch'               => 2,
            'generation'          => 1,
            'content_type'        => 1,
            'ciphertext_payload'  => base64_encode($mhsPayload),
            'payload_hash_sha256' => hash('sha256', $mhsPayload),
            'client_sent_at'      => now()->toIso8601String(),
        ]);
        $msgResMhs->assertStatus(201);
        $this->assertDatabaseHas('bchat_messages', [
            'room_id'      => $ctx['room']->id,
            'sender_id'    => $ctx['userMhs']->id,
            'message_uuid' => $mhsMsgUuid,
            'epoch'        => 2,
        ]);

        // C. Mencoba mengirim dengan epoch lama (1) DITOLAK dengan EPOCH_MISMATCH
        $oldEpochPayload = random_bytes(32);
        $msgResOld = $this->postJson("/api/bchat/rooms/{$ctx['room']->room_uuid}/messages", [
            'device_uuid'         => $ctx['devMhs']['device']->device_uuid,
            'message_uuid'        => (string) Str::uuid(),
            'epoch'               => 1,
            'generation'          => 1,
            'content_type'        => 1,
            'ciphertext_payload'  => base64_encode($oldEpochPayload),
            'payload_hash_sha256' => hash('sha256', $oldEpochPayload),
            'client_sent_at'      => now()->toIso8601String(),
        ]);
        $msgResOld->assertStatus(422);
        $msgResOld->assertJson(['error_code' => 'EPOCH_MISMATCH']);
    }

    /**
     * Skenario 6: Salah satu perangkat offline: transisi tidak boleh difinalisasi secara prematur
     */
    public function test_skenario_6_device_offline_prevents_premature_transition_finalization(): void
    {
        $ctx = $this->setupActiveRoomWithMembers();
        Sanctum::actingAs($ctx['userMhs']);

        $kpEdge = $this->createKeyPackage($ctx['userDsn'], $ctx['devEdge']['device']);
        $pubRes = $this->postJson("/api/bchat/rooms/{$ctx['room']->room_uuid}/commits", [
            'device_uuid'         => $ctx['devMhs']['device']->device_uuid,
            'from_epoch'          => 1,
            'to_epoch'            => 2,
            'commit_wire_base64'  => base64_encode(random_bytes(64)),
            'added_device_uuid'   => $ctx['devEdge']['device']->device_uuid,
            'key_package_hash'    => $kpEdge->key_package_hash,
            'welcome_wire_base64' => base64_encode(random_bytes(64)),
        ]);
        $commitUuid = $pubRes->json('data.commit_uuid');

        // Chrome mengirim COMMIT_ACK, tetapi Edge dalam kondisi OFFLINE (tidak merespons)
        Sanctum::actingAs($ctx['userDsn']);
        $sigChrome = sodium_crypto_sign_detached("COMMIT_ACK:{$ctx['room']->room_uuid}:{$ctx['session']->session_uuid}:{$commitUuid}:{$ctx['devChrome']['device']->device_uuid}:2", $ctx['devChrome']['privKey']);
        $this->postJson("/api/bchat/rooms/{$ctx['room']->room_uuid}/commits/{$commitUuid}/ack", [
            'device_uuid' => $ctx['devChrome']['device']->device_uuid,
            'acked_epoch' => 2,
            'signature'   => bin2hex($sigChrome),
        ]);

        // Simulasi waktu berlalu 15 menit saat Edge offline
        Carbon::setTestNow(now()->addMinutes(15));

        // Verifikasi tidak ada finalisasi prematur otomatis
        $commit = BchatCommit::where('commit_uuid', $commitUuid)->first();
        $this->assertEquals('PENDING_ACKS', $commit->status);
        $this->assertNull($commit->transition_completed_at);
        $this->assertEquals(1, $ctx['room']->fresh()->current_epoch);

        // Pengiriman pesan tetap diblokir
        Sanctum::actingAs($ctx['userMhs']);
        $dummyPayload = random_bytes(32);
        $msgRes = $this->postJson("/api/bchat/rooms/{$ctx['room']->room_uuid}/messages", [
            'device_uuid'         => $ctx['devMhs']['device']->device_uuid,
            'message_uuid'        => (string) Str::uuid(),
            'epoch'               => 1,
            'generation'          => 1,
            'content_type'        => 1,
            'ciphertext_payload'  => base64_encode($dummyPayload),
            'payload_hash_sha256' => hash('sha256', $dummyPayload),
            'client_sent_at'      => now()->toIso8601String(),
        ]);
        $msgRes->assertStatus(409);
        $msgRes->assertJson(['error_code' => 'ROOM_TRANSITIONING_EPOCH']);
    }

    /**
     * Skenario 7: Perangkat kembali online dan menyelesaikan proses yang tertunda
     */
    public function test_skenario_7_device_comes_online_and_completes_pending_transition(): void
    {
        $ctx = $this->setupActiveRoomWithMembers();
        Sanctum::actingAs($ctx['userMhs']);

        $kpEdge = $this->createKeyPackage($ctx['userDsn'], $ctx['devEdge']['device']);
        $pubRes = $this->postJson("/api/bchat/rooms/{$ctx['room']->room_uuid}/commits", [
            'device_uuid'         => $ctx['devMhs']['device']->device_uuid,
            'from_epoch'          => 1,
            'to_epoch'            => 2,
            'commit_wire_base64'  => base64_encode(random_bytes(64)),
            'added_device_uuid'   => $ctx['devEdge']['device']->device_uuid,
            'key_package_hash'    => $kpEdge->key_package_hash,
            'welcome_wire_base64' => base64_encode(random_bytes(64)),
        ]);
        $commitUuid = $pubRes->json('data.commit_uuid');

        // Chrome sudah ACK lebih awal
        Sanctum::actingAs($ctx['userDsn']);
        $sigChrome = sodium_crypto_sign_detached("COMMIT_ACK:{$ctx['room']->room_uuid}:{$ctx['session']->session_uuid}:{$commitUuid}:{$ctx['devChrome']['device']->device_uuid}:2", $ctx['devChrome']['privKey']);
        $this->postJson("/api/bchat/rooms/{$ctx['room']->room_uuid}/commits/{$commitUuid}/ack", [
            'device_uuid' => $ctx['devChrome']['device']->device_uuid,
            'acked_epoch' => 2,
            'signature'   => bin2hex($sigChrome),
        ]);

        // Edge kembali online: query pending commit dari endpoint
        $queryRes = $this->getJson("/api/bchat/rooms/{$ctx['room']->room_uuid}/commits?device_uuid={$ctx['devEdge']['device']->device_uuid}");
        $queryRes->assertStatus(200);
        $this->assertEquals('PENDING_ACKS', $queryRes->json('data.0.status'));

        // Edge menyelesaikan proses yang tertunda dengan mengirimkan WELCOME_ACK
        $sigEdge = sodium_crypto_sign_detached("WELCOME_ACK:{$ctx['room']->room_uuid}:{$ctx['session']->session_uuid}:{$commitUuid}:{$ctx['devEdge']['device']->device_uuid}:2", $ctx['devEdge']['privKey']);
        $ackRes = $this->postJson("/api/bchat/rooms/{$ctx['room']->room_uuid}/commits/{$commitUuid}/welcome-ack", [
            'device_uuid' => $ctx['devEdge']['device']->device_uuid,
            'acked_epoch' => 2,
            'signature'   => bin2hex($sigEdge),
        ]);

        $ackRes->assertStatus(200);
        $ackRes->assertJson(['is_complete' => true, 'status' => 'ACTIVE']);

        // Transisi sukses dan pesan kini dapat mengalir
        $this->assertEquals(2, $ctx['room']->fresh()->current_epoch);
    }

    /**
     * Skenario 8: Reload browser tidak merusak GroupState atau status transisi
     */
    public function test_skenario_8_browser_reload_preserves_state_and_allows_reconnect(): void
    {
        $ctx = $this->setupActiveRoomWithMembers();
        Sanctum::actingAs($ctx['userMhs']);

        // Jalankan transisi penuh ke Epoch 2
        $kpEdge = $this->createKeyPackage($ctx['userDsn'], $ctx['devEdge']['device']);
        $pubRes = $this->postJson("/api/bchat/rooms/{$ctx['room']->room_uuid}/commits", [
            'device_uuid'         => $ctx['devMhs']['device']->device_uuid,
            'from_epoch'          => 1,
            'to_epoch'            => 2,
            'commit_wire_base64'  => base64_encode(random_bytes(64)),
            'added_device_uuid'   => $ctx['devEdge']['device']->device_uuid,
            'key_package_hash'    => $kpEdge->key_package_hash,
            'welcome_wire_base64' => base64_encode(random_bytes(64)),
        ]);
        $commitUuid = $pubRes->json('data.commit_uuid');

        Sanctum::actingAs($ctx['userDsn']);
        $sigChrome = sodium_crypto_sign_detached("COMMIT_ACK:{$ctx['room']->room_uuid}:{$ctx['session']->session_uuid}:{$commitUuid}:{$ctx['devChrome']['device']->device_uuid}:2", $ctx['devChrome']['privKey']);
        $this->postJson("/api/bchat/rooms/{$ctx['room']->room_uuid}/commits/{$commitUuid}/ack", [
            'device_uuid' => $ctx['devChrome']['device']->device_uuid,
            'acked_epoch' => 2,
            'signature'   => bin2hex($sigChrome),
        ]);

        $sigEdge = sodium_crypto_sign_detached("WELCOME_ACK:{$ctx['room']->room_uuid}:{$ctx['session']->session_uuid}:{$commitUuid}:{$ctx['devEdge']['device']->device_uuid}:2", $ctx['devEdge']['privKey']);
        $this->postJson("/api/bchat/rooms/{$ctx['room']->room_uuid}/commits/{$commitUuid}/welcome-ack", [
            'device_uuid' => $ctx['devEdge']['device']->device_uuid,
            'acked_epoch' => 2,
            'signature'   => bin2hex($sigEdge),
        ]);

        // Simulasi Reload Browser: Fetch ulang room info & commit history
        $roomInfo = $this->getJson("/api/bchat/rooms/{$ctx['room']->room_uuid}");
        $roomInfo->assertStatus(200);
        $this->assertEquals(2, $roomInfo->json('data.current_epoch'));

        $commitsList = $this->getJson("/api/bchat/rooms/{$ctx['room']->room_uuid}/commits?device_uuid={$ctx['devEdge']['device']->device_uuid}&since_epoch=0");
        $commitsList->assertStatus(200);
        $this->assertEquals('ACTIVE', $commitsList->json('data.0.status'));
        $this->assertEquals('2', $commitsList->json('data.0.to_epoch'));
    }

    /**
     * Skenario 9: ACK duplikat dan retry tidak menghasilkan transisi ganda
     */
    public function test_skenario_9_duplicate_ack_and_retry_idempotent_no_double_transition(): void
    {
        $ctx = $this->setupActiveRoomWithMembers();
        Sanctum::actingAs($ctx['userMhs']);

        $kpEdge = $this->createKeyPackage($ctx['userDsn'], $ctx['devEdge']['device']);
        $pubRes = $this->postJson("/api/bchat/rooms/{$ctx['room']->room_uuid}/commits", [
            'device_uuid'         => $ctx['devMhs']['device']->device_uuid,
            'from_epoch'          => 1,
            'to_epoch'            => 2,
            'commit_wire_base64'  => base64_encode(random_bytes(64)),
            'added_device_uuid'   => $ctx['devEdge']['device']->device_uuid,
            'key_package_hash'    => $kpEdge->key_package_hash,
            'welcome_wire_base64' => base64_encode(random_bytes(64)),
        ]);
        $commitUuid = $pubRes->json('data.commit_uuid');

        Sanctum::actingAs($ctx['userDsn']);
        $sigChrome = sodium_crypto_sign_detached("COMMIT_ACK:{$ctx['room']->room_uuid}:{$ctx['session']->session_uuid}:{$commitUuid}:{$ctx['devChrome']['device']->device_uuid}:2", $ctx['devChrome']['privKey']);
        $sigEdge = sodium_crypto_sign_detached("WELCOME_ACK:{$ctx['room']->room_uuid}:{$ctx['session']->session_uuid}:{$commitUuid}:{$ctx['devEdge']['device']->device_uuid}:2", $ctx['devEdge']['privKey']);

        // Pengiriman ACK pertama (normal)
        $this->postJson("/api/bchat/rooms/{$ctx['room']->room_uuid}/commits/{$commitUuid}/ack", [
            'device_uuid' => $ctx['devChrome']['device']->device_uuid,
            'acked_epoch' => 2,
            'signature'   => bin2hex($sigChrome),
        ]);
        $this->postJson("/api/bchat/rooms/{$ctx['room']->room_uuid}/commits/{$commitUuid}/welcome-ack", [
            'device_uuid' => $ctx['devEdge']['device']->device_uuid,
            'acked_epoch' => 2,
            'signature'   => bin2hex($sigEdge),
        ]);

        $this->assertEquals(2, $ctx['room']->fresh()->current_epoch);

        // Simulasi Retry / Duplicate ACK dari Chrome
        $dupResChrome = $this->postJson("/api/bchat/rooms/{$ctx['room']->room_uuid}/commits/{$commitUuid}/ack", [
            'device_uuid' => $ctx['devChrome']['device']->device_uuid,
            'acked_epoch' => 2,
            'signature'   => bin2hex($sigChrome),
        ]);
        $dupResChrome->assertStatus(200);
        $dupResChrome->assertJson(['is_complete' => true]);

        // Simulasi Retry / Duplicate WELCOME_ACK dari Edge
        $dupResEdge = $this->postJson("/api/bchat/rooms/{$ctx['room']->room_uuid}/commits/{$commitUuid}/welcome-ack", [
            'device_uuid' => $ctx['devEdge']['device']->device_uuid,
            'acked_epoch' => 2,
            'signature'   => bin2hex($sigEdge),
        ]);
        $dupResEdge->assertStatus(200);
        $dupResEdge->assertJson(['is_complete' => true]);

        // Verifikasi tidak ada penambahan ACK ganda di DB
        $commit = BchatCommit::where('commit_uuid', $commitUuid)->first();
        $ackCount = BchatCommitAck::where('commit_id', $commit->id)->count();
        // Inisiator (Mhs) + Chrome + Edge = tepat 3 rekaman ACK
        $this->assertEquals(3, $ackCount);

        // Epoch tetap 2 (tidak melompat ke 3 atau seterusnya)
        $this->assertEquals(2, $ctx['room']->fresh()->current_epoch);
    }

    /**
     * Skenario 10: Riwayat pesan lama tetap dapat diakses sesuai kemampuan kriptografis perangkat
     */
    public function test_skenario_10_legacy_messages_accessible_according_to_device_crypto_capabilities(): void
    {
        $ctx = $this->setupActiveRoomWithMembers();
        Sanctum::actingAs($ctx['userMhs']);

        // Buat 3 pesan di Epoch 1 sebelum transisi
        for ($i = 1; $i <= 3; $i++) {
            BchatMessage::create([
                'room_id'             => $ctx['room']->id,
                'session_id'          => $ctx['session']->id,
                'sender_id'           => $ctx['userMhs']->id,
                'device_id'           => $ctx['devMhs']['device']->id,
                'message_uuid'        => (string) Str::uuid(),
                'server_sequence'     => $i,
                'epoch'               => 1,
                'generation'          => 1,
                'content_type'        => 1,
                'ciphertext_payload'  => base64_encode("Ciphertext Epoch 1 #{$i}"),
                'payload_hash_sha256' => hash('sha256', "Ciphertext Epoch 1 #{$i}"),
                'client_sent_at'      => now(),
                'server_received_at'  => now(),
            ]);
        }

        // Lakukan transisi ke Epoch 2
        $kpEdge = $this->createKeyPackage($ctx['userDsn'], $ctx['devEdge']['device']);
        $pubRes = $this->postJson("/api/bchat/rooms/{$ctx['room']->room_uuid}/commits", [
            'device_uuid'         => $ctx['devMhs']['device']->device_uuid,
            'from_epoch'          => 1,
            'to_epoch'            => 2,
            'commit_wire_base64'  => base64_encode(random_bytes(64)),
            'added_device_uuid'   => $ctx['devEdge']['device']->device_uuid,
            'key_package_hash'    => $kpEdge->key_package_hash,
            'welcome_wire_base64' => base64_encode(random_bytes(64)),
        ]);
        $commitUuid = $pubRes->json('data.commit_uuid');

        Sanctum::actingAs($ctx['userDsn']);
        $sigChrome = sodium_crypto_sign_detached("COMMIT_ACK:{$ctx['room']->room_uuid}:{$ctx['session']->session_uuid}:{$commitUuid}:{$ctx['devChrome']['device']->device_uuid}:2", $ctx['devChrome']['privKey']);
        $this->postJson("/api/bchat/rooms/{$ctx['room']->room_uuid}/commits/{$commitUuid}/ack", [
            'device_uuid' => $ctx['devChrome']['device']->device_uuid,
            'acked_epoch' => 2,
            'signature'   => bin2hex($sigChrome),
        ]);
        $sigEdge = sodium_crypto_sign_detached("WELCOME_ACK:{$ctx['room']->room_uuid}:{$ctx['session']->session_uuid}:{$commitUuid}:{$ctx['devEdge']['device']->device_uuid}:2", $ctx['devEdge']['privKey']);
        $this->postJson("/api/bchat/rooms/{$ctx['room']->room_uuid}/commits/{$commitUuid}/welcome-ack", [
            'device_uuid' => $ctx['devEdge']['device']->device_uuid,
            'acked_epoch' => 2,
            'signature'   => bin2hex($sigEdge),
        ]);

        // Buat 1 pesan baru di Epoch 2
        $newPayload = random_bytes(32);
        $this->postJson("/api/bchat/rooms/{$ctx['room']->room_uuid}/messages", [
            'device_uuid'         => $ctx['devEdge']['device']->device_uuid,
            'message_uuid'        => (string) Str::uuid(),
            'epoch'               => 2,
            'generation'          => 1,
            'content_type'        => 1,
            'ciphertext_payload'  => base64_encode($newPayload),
            'payload_hash_sha256' => hash('sha256', $newPayload),
            'client_sent_at'      => now()->toIso8601String(),
        ]);

        // Verifikasi endpoint riwayat pesan mengembalikan seluruh pesan (3 pesan Epoch 1 + 1 pesan Epoch 2)
        $messagesRes = $this->getJson("/api/bchat/rooms/{$ctx['room']->room_uuid}/messages");
        $messagesRes->assertStatus(200);
        $retrieved = $messagesRes->json('data');
        $this->assertCount(4, $retrieved);
        $epochs = array_column($retrieved, 'epoch');
        $this->assertContains(1, $epochs);
        $this->assertContains(2, $epochs);
    }

    // =========================================================================
    // SKENARIO PENGUJIAN PROTEKSI KEAMANAN & INVARIANT
    // =========================================================================

    /**
     * Security 1: KeyPackage milik pengguna nonanggota ditolak
     */
    public function test_security_keypackage_of_non_room_member_is_rejected(): void
    {
        $ctx = $this->setupActiveRoomWithMembers();
        Sanctum::actingAs($ctx['userMhs']);

        $outsiderUser = User::factory()->create(['role' => 'mahasiswa', 'nomor_induk' => '9999999999']);
        $outsiderDev = $this->createDevice($outsiderUser, null, 'Outsider Device');
        $outsiderKp = $this->createKeyPackage($outsiderUser, $outsiderDev['device']);

        $res = $this->postJson("/api/bchat/rooms/{$ctx['room']->room_uuid}/commits", [
            'device_uuid'         => $ctx['devMhs']['device']->device_uuid,
            'from_epoch'          => 1,
            'to_epoch'            => 2,
            'commit_wire_base64'  => base64_encode(random_bytes(64)),
            'added_device_uuid'   => $outsiderDev['device']->device_uuid,
            'key_package_hash'    => $outsiderKp->key_package_hash,
            'welcome_wire_base64' => base64_encode(random_bytes(64)),
        ]);

        $res->assertStatus(403);
        $res->assertJson([
            'success'    => false,
            'error_code' => 'ADDED_DEVICE_NOT_ROOM_MEMBER',
        ]);

        $this->assertFalse((bool) $outsiderKp->fresh()->is_consumed);
    }

    /**
     * Security 2: KeyPackage yang sudah dikonsumsi tidak dapat digunakan ulang
     */
    public function test_security_already_consumed_keypackage_is_rejected(): void
    {
        $ctx = $this->setupActiveRoomWithMembers();
        Sanctum::actingAs($ctx['userMhs']);

        $kpEdge = $this->createKeyPackage($ctx['userDsn'], $ctx['devEdge']['device']);
        $kpEdge->is_consumed = true;
        $kpEdge->consumed_at = now()->subHour();
        $kpEdge->save();

        $res = $this->postJson("/api/bchat/rooms/{$ctx['room']->room_uuid}/commits", [
            'device_uuid'         => $ctx['devMhs']['device']->device_uuid,
            'from_epoch'          => 1,
            'to_epoch'            => 2,
            'commit_wire_base64'  => base64_encode(random_bytes(64)),
            'added_device_uuid'   => $ctx['devEdge']['device']->device_uuid,
            'key_package_hash'    => $kpEdge->key_package_hash,
            'welcome_wire_base64' => base64_encode(random_bytes(64)),
        ]);

        $res->assertStatus(409);
        $res->assertJson([
            'success'    => false,
            'error_code' => 'KEY_PACKAGE_ALREADY_CONSUMED',
        ]);
    }

    /**
     * Security 3: Pemisahan ACK: Welcome ACK tidak dapat menggantikan Commit ACK dan sebaliknya
     */
    public function test_security_welcome_ack_and_commit_ack_separation_is_enforced(): void
    {
        $ctx = $this->setupActiveRoomWithMembers();
        Sanctum::actingAs($ctx['userMhs']);

        $kpEdge = $this->createKeyPackage($ctx['userDsn'], $ctx['devEdge']['device']);
        $pubRes = $this->postJson("/api/bchat/rooms/{$ctx['room']->room_uuid}/commits", [
            'device_uuid'         => $ctx['devMhs']['device']->device_uuid,
            'from_epoch'          => 1,
            'to_epoch'            => 2,
            'commit_wire_base64'  => base64_encode(random_bytes(64)),
            'added_device_uuid'   => $ctx['devEdge']['device']->device_uuid,
            'key_package_hash'    => $kpEdge->key_package_hash,
            'welcome_wire_base64' => base64_encode(random_bytes(64)),
        ]);
        $commitUuid = $pubRes->json('data.commit_uuid');

        Sanctum::actingAs($ctx['userDsn']);

        // A. Perangkat baru (Edge) mencoba mengirim COMMIT_ACK -> DITOLAK
        $sigEdge = sodium_crypto_sign_detached("payload", $ctx['devEdge']['privKey']);
        $resEdgeWrong = $this->postJson("/api/bchat/rooms/{$ctx['room']->room_uuid}/commits/{$commitUuid}/ack", [
            'device_uuid' => $ctx['devEdge']['device']->device_uuid,
            'acked_epoch' => 2,
            'signature'   => bin2hex($sigEdge),
        ]);
        $resEdgeWrong->assertStatus(422);
        $resEdgeWrong->assertJson([
            'success'    => false,
            'error_code' => 'INVALID_ACK_TYPE_FOR_DEVICE',
        ]);

        // B. Perangkat lama (Chrome) mencoba mengirim WELCOME_ACK -> DITOLAK
        $sigChrome = sodium_crypto_sign_detached("payload", $ctx['devChrome']['privKey']);
        $resChromeWrong = $this->postJson("/api/bchat/rooms/{$ctx['room']->room_uuid}/commits/{$commitUuid}/welcome-ack", [
            'device_uuid' => $ctx['devChrome']['device']->device_uuid,
            'acked_epoch' => 2,
            'signature'   => bin2hex($sigChrome),
        ]);
        $resChromeWrong->assertStatus(422);
        $resChromeWrong->assertJson([
            'success'    => false,
            'error_code' => 'DEVICE_NOT_ADDED_RECIPIENT',
        ]);
    }

    /**
     * Security 4: Transisi ganda diblokir saat transisi sedang PENDING_ACKS
     */
    public function test_security_concurrent_or_double_transition_is_blocked(): void
    {
        $ctx = $this->setupActiveRoomWithMembers();
        Sanctum::actingAs($ctx['userMhs']);

        $kpEdge1 = $this->createKeyPackage($ctx['userDsn'], $ctx['devEdge']['device']);
        $this->postJson("/api/bchat/rooms/{$ctx['room']->room_uuid}/commits", [
            'device_uuid'         => $ctx['devMhs']['device']->device_uuid,
            'from_epoch'          => 1,
            'to_epoch'            => 2,
            'commit_wire_base64'  => base64_encode(random_bytes(64)),
            'added_device_uuid'   => $ctx['devEdge']['device']->device_uuid,
            'key_package_hash'    => $kpEdge1->key_package_hash,
            'welcome_wire_base64' => base64_encode(random_bytes(64)),
        ]);

        // Mencoba membuat transisi commit kedua saat yang pertama masih PENDING_ACKS
        $kpEdge2 = $this->createKeyPackage($ctx['userDsn'], $ctx['devEdge']['device']);
        $res2 = $this->postJson("/api/bchat/rooms/{$ctx['room']->room_uuid}/commits", [
            'device_uuid'         => $ctx['devMhs']['device']->device_uuid,
            'from_epoch'          => 1,
            'to_epoch'            => 2,
            'commit_wire_base64'  => base64_encode(random_bytes(64)),
            'added_device_uuid'   => $ctx['devEdge']['device']->device_uuid,
            'key_package_hash'    => $kpEdge2->key_package_hash,
            'welcome_wire_base64' => base64_encode(random_bytes(64)),
        ]);

        $res2->assertStatus(409);
        $res2->assertJson([
            'success'    => false,
            'error_code' => 'TRANSITION_ALREADY_PENDING',
        ]);
    }
}
