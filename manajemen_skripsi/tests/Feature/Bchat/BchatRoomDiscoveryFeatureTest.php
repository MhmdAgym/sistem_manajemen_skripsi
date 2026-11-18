<?php

declare(strict_types=1);

namespace Tests\Feature\Bchat;

use App\Models\BchatRoom;
use App\Models\BchatRoomMember;
use App\Models\BchatUserDevice;
use App\Models\BchatWelcomeChallenge;
use App\Models\Dosen;
use App\Models\Mahasiswa;
use App\Models\PenugasanPembimbing;
use App\Models\Skripsi;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Feature Test untuk Room Discovery & Role Metadata API (B-CHAT-22B Tahap 2C.1).
 *
 * Menguji endpoint:
 * - GET /api/bchat/rooms
 * - GET /api/bchat/rooms/{roomUuid}
 *
 * Cakupan: Otorisasi Sanctum, isolasi keanggotaan, penapisan status lifecycle,
 * hak akses arsip vs dosen pengganti, sanitasi material rahasia,
 * serialisasi desimal string epoch, action hint deterministik, dan zero-side effect.
 */
class BchatRoomDiscoveryFeatureTest extends TestCase
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
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // =========================================================================
    // FIXTURE HELPERS
    // =========================================================================

    private function createTestUser(string $role = 'mahasiswa'): User
    {
        return User::factory()->create([
            'role'        => $role,
            'nomor_induk' => (string) rand(1000000000, 9999999999),
        ]);
    }

    private function createTestDevice(User $user, string $status = 'ACTIVE'): BchatUserDevice
    {
        $keypair = sodium_crypto_sign_keypair();
        $publicKey = sodium_crypto_sign_publickey($keypair);

        return BchatUserDevice::create([
            'user_id'                => $user->id,
            'device_uuid'            => (string) Str::uuid(),
            'device_name'            => 'Feature Test Browser Device',
            'key_version'            => 1,
            'public_key'             => bin2hex($publicKey),
            'public_key_fingerprint' => hash('sha256', $publicKey),
            'status'                 => $status,
            'is_trusted'             => ($status === 'ACTIVE'),
            'trusted_at'             => ($status === 'ACTIVE') ? now() : null,
            'last_active_at'         => now(),
        ]);
    }

    private function createTestRoomWithMembers(
        User $mahasiswaUser,
        User $dosenUser,
        string $assignmentStatus = 'AKTIF',
        string $roomStatus = 'PENDING_WELCOME_ACK',
        bool $mhsIsActive = false,
        bool $dosIsActive = false,
        bool $mhsIsInitiator = false,
        bool $dosIsInitiator = false
    ): array {
        $mhs = Mahasiswa::create([
            'user_id'        => $mahasiswaUser->id,
            'NIM'            => $mahasiswaUser->nomor_induk,
            'nama_mahasiswa' => 'Mahasiswa Test ' . Str::random(4),
            'prodi'          => 'Informatika',
        ]);

        $skripsi = Skripsi::create([
            'id_mahasiswa' => $mhs->id_mahasiswa,
            'judul'        => 'Proposal E2EE Discovery Test ' . Str::random(5),
            'status_judul' => 'diajukan',
        ]);

        $dosen = Dosen::create([
            'user_id'    => $dosenUser->id,
            'NIDN'       => $dosenUser->nomor_induk,
            'nama_dosen' => 'Dosen Pembimbing Test ' . Str::random(4),
            'prodi'      => 'Informatika',
        ]);

        $penugasan = PenugasanPembimbing::create([
            'skripsi_id'       => $skripsi->id_skripsi,
            'tahap'            => 'proposal',
            'pembimbing_ke'    => 1,
            'dosen_id'         => $dosen->id_dosen,
            'status_penugasan' => $assignmentStatus,
            'tanggal_mulai'    => now()->toDateString(),
        ]);

        $room = BchatRoom::create([
            'room_uuid'               => (string) Str::uuid(),
            'penugasan_pembimbing_id' => $penugasan->id,
            'tahap'                   => 'proposal',
            'pembimbing_ke'           => 1,
            'current_sequence'        => 0,
            'status'                  => $roomStatus,
            'current_epoch'           => ($roomStatus === 'ACTIVE') ? 1 : 0,
            'group_id_hash'           => hash('sha256', 'group_context_discovery'),
        ]);

        $mhsMember = BchatRoomMember::create([
            'room_id'         => $room->id,
            'user_id'         => $mahasiswaUser->id,
            'role'            => 'mahasiswa',
            'is_initiator'    => $mhsIsInitiator,
            'is_active'       => $mhsIsActive,
            'joined_at_epoch' => ($roomStatus === 'ACTIVE') ? 1 : 0,
        ]);

        $dosMember = BchatRoomMember::create([
            'room_id'         => $room->id,
            'user_id'         => $dosenUser->id,
            'role'            => 'dosen_pembimbing',
            'is_initiator'    => $dosIsInitiator,
            'is_active'       => $dosIsActive,
            'joined_at_epoch' => ($roomStatus === 'ACTIVE') ? 1 : 0,
        ]);

        return [
            'room'      => $room,
            'penugasan' => $penugasan,
            'mhsMember' => $mhsMember,
            'dosMember' => $dosMember,
        ];
    }

    private function createTestChallenge(
        BchatRoom $room,
        User $recipient,
        BchatUserDevice $recipientDevice,
        int $epoch = 1
    ): BchatWelcomeChallenge {
        return BchatWelcomeChallenge::create([
            'welcome_operation_uuid' => (string) Str::uuid(),
            'room_id'                => $room->id,
            'recipient_user_id'      => $recipient->id,
            'recipient_device_id'    => $recipientDevice->id,
            'welcome_message'        => 'encrypted_mls_welcome_payload_binary',
            'group_id_hash'          => $room->group_id_hash ?? hash('sha256', 'group_id'),
            'epoch'                  => $epoch,
            'operation_status'       => 'ACTIVE',
            'challenge_uuid'         => (string) Str::uuid(),
            'challenge_nonce'        => bin2hex(random_bytes(32)),
            'challenge_generation'   => 1,
            'challenge_status'       => 'ISSUED',
            'expires_at'             => now()->addMinutes(5),
        ]);
    }

    // =========================================================================
    // TEST CASES (17 Skenario Uji Wajib)
    // =========================================================================

    /**
     * 1. Pengguna tanpa otentikasi (Guest) ditolak dengan HTTP 401.
     */
    public function test_01_guest_request_is_rejected_with_http_401(): void
    {
        $dummyUuid = (string) Str::uuid();

        $responseIndex = $this->getJson('/api/bchat/rooms');
        $responseIndex->assertStatus(401);

        $responseShow = $this->getJson("/api/bchat/rooms/{$dummyUuid}");
        $responseShow->assertStatus(401);
    }

    /**
     * 2. Mahasiswa hanya melihat room di mana dirinya terdaftar sebagai anggota.
     */
    public function test_02_mahasiswa_only_sees_rooms_where_they_are_a_member(): void
    {
        $mhs1 = $this->createTestUser('mahasiswa');
        $dos1 = $this->createTestUser('dosen');
        $data1 = $this->createTestRoomWithMembers($mhs1, $dos1);

        $mhs2 = $this->createTestUser('mahasiswa');
        $dos2 = $this->createTestUser('dosen');
        $data2 = $this->createTestRoomWithMembers($mhs2, $dos2);

        Sanctum::actingAs($mhs1);

        $response = $this->getJson('/api/bchat/rooms');

        $response->assertStatus(200);
        $response->assertJson([
            'status'  => 'success',
            'message' => 'Daftar ruang obrolan berhasil diambil.',
        ]);

        $rooms = $response->json('data');
        $this->assertCount(1, $rooms);
        $this->assertEquals($data1['room']->room_uuid, $rooms[0]['room_uuid']);
        $this->assertNotEquals($data2['room']->room_uuid, $rooms[0]['room_uuid']);
    }

    /**
     * 3. Dosen hanya melihat room yang menjadi membership miliknya.
     */
    public function test_03_dosen_only_sees_rooms_where_they_are_a_member(): void
    {
        $mhs1 = $this->createTestUser('mahasiswa');
        $dos1 = $this->createTestUser('dosen');
        $data1 = $this->createTestRoomWithMembers($mhs1, $dos1);

        $mhs2 = $this->createTestUser('mahasiswa');
        $dos2 = $this->createTestUser('dosen');
        $data2 = $this->createTestRoomWithMembers($mhs2, $dos2);

        Sanctum::actingAs($dos1);

        $response = $this->getJson('/api/bchat/rooms');

        $response->assertStatus(200);
        $rooms = $response->json('data');
        $this->assertCount(1, $rooms);
        $this->assertEquals($data1['room']->room_uuid, $rooms[0]['room_uuid']);
    }

    /**
     * 4. Pengguna non-anggota ditolak saat membuka detail room (HTTP 403 / FORBIDDEN).
     */
    public function test_04_nonmember_cannot_open_detail_returns_403(): void
    {
        $mhs = $this->createTestUser('mahasiswa');
        $dosen = $this->createTestUser('dosen');
        $stranger = $this->createTestUser('mahasiswa');
        $data = $this->createTestRoomWithMembers($mhs, $dosen);

        Sanctum::actingAs($stranger);

        $response = $this->getJson("/api/bchat/rooms/{$data['room']->room_uuid}");

        $response->assertStatus(403);
        $response->assertJson([
            'status'     => 'error',
            'error_code' => 'FORBIDDEN',
        ]);
    }

    /**
     * 5. Room SUSPENDED tidak muncul di daftar dan ditolak di detail (HTTP 403).
     */
    public function test_05_suspended_room_does_not_appear_in_list_or_detail(): void
    {
        $mhs = $this->createTestUser('mahasiswa');
        $dosen = $this->createTestUser('dosen');
        $data = $this->createTestRoomWithMembers($mhs, $dosen, 'AKTIF', 'SUSPENDED');

        Sanctum::actingAs($mhs);

        $responseList = $this->getJson('/api/bchat/rooms');
        $responseList->assertStatus(200);
        $this->assertCount(0, $responseList->json('data'));

        $responseDetail = $this->getJson("/api/bchat/rooms/{$data['room']->room_uuid}");
        $responseDetail->assertStatus(403);
    }

    /**
     * 6. Room TERMINATED tidak muncul di daftar dan ditolak di detail (HTTP 403).
     */
    public function test_06_terminated_room_does_not_appear_in_list_or_detail(): void
    {
        $mhs = $this->createTestUser('mahasiswa');
        $dosen = $this->createTestUser('dosen');
        $data = $this->createTestRoomWithMembers($mhs, $dosen, 'AKTIF', 'TERMINATED');

        Sanctum::actingAs($mhs);

        $responseList = $this->getJson('/api/bchat/rooms');
        $responseList->assertStatus(200);
        $this->assertCount(0, $responseList->json('data'));

        $responseDetail = $this->getJson("/api/bchat/rooms/{$data['room']->room_uuid}");
        $responseDetail->assertStatus(403);
    }

    /**
     * 7. Room PENDING_OFFICIAL_SK tidak muncul di daftar dan ditolak di detail (HTTP 403).
     */
    public function test_07_pending_official_sk_room_does_not_appear_in_list_or_detail(): void
    {
        $mhs = $this->createTestUser('mahasiswa');
        $dosen = $this->createTestUser('dosen');
        $data = $this->createTestRoomWithMembers($mhs, $dosen, 'AKTIF', 'PENDING_OFFICIAL_SK');

        Sanctum::actingAs($mhs);

        $responseList = $this->getJson('/api/bchat/rooms');
        $responseList->assertStatus(200);
        $this->assertCount(0, $responseList->json('data'));

        $responseDetail = $this->getJson("/api/bchat/rooms/{$data['room']->room_uuid}");
        $responseDetail->assertStatus(403);
    }

    /**
     * 8. Room yang penugasannya DIBATALKAN tidak muncul di daftar dan ditolak di detail (HTTP 403).
     */
    public function test_08_cancelled_assignment_room_does_not_appear_in_list_or_detail(): void
    {
        $mhs = $this->createTestUser('mahasiswa');
        $dosen = $this->createTestUser('dosen');
        $data = $this->createTestRoomWithMembers($mhs, $dosen, 'DIBATALKAN', 'PENDING_WELCOME_ACK');

        Sanctum::actingAs($mhs);

        $responseList = $this->getJson('/api/bchat/rooms');
        $responseList->assertStatus(200);
        $this->assertCount(0, $responseList->json('data'));

        $responseDetail = $this->getJson("/api/bchat/rooms/{$data['room']->room_uuid}");
        $responseDetail->assertStatus(403);
    }

    /**
     * 9. Anggota lama yang sah dapat melihat room ARCHIVED_READONLY.
     */
    public function test_09_legitimate_former_members_can_access_archived_readonly_room(): void
    {
        $mhs = $this->createTestUser('mahasiswa');
        $dosen = $this->createTestUser('dosen');
        $data = $this->createTestRoomWithMembers(
            $mhs,
            $dosen,
            'DIGANTI',
            'ARCHIVED_READONLY',
            true,
            true,
            true,
            false
        );

        Sanctum::actingAs($dosen);

        $responseList = $this->getJson('/api/bchat/rooms');
        $responseList->assertStatus(200);
        $this->assertCount(1, $responseList->json('data'));
        $this->assertEquals($data['room']->room_uuid, $responseList->json('data.0.room_uuid'));

        $responseDetail = $this->getJson("/api/bchat/rooms/{$data['room']->room_uuid}");
        $responseDetail->assertStatus(200);
        $responseDetail->assertJson([
            'status' => 'success',
            'data'   => [
                'room_uuid' => $data['room']->room_uuid,
                'status'    => 'ARCHIVED_READONLY',
            ],
        ]);
    }

    /**
     * 10. Dosen pengganti tidak dapat melihat room lama yang diarsipkan.
     */
    public function test_10_replacement_lecturer_cannot_see_old_room(): void
    {
        $mhs = $this->createTestUser('mahasiswa');
        $dosenOld = $this->createTestUser('dosen');
        $dosenNew = $this->createTestUser('dosen');

        // Room lama diarsipkan untuk Dosen Lama
        $oldData = $this->createTestRoomWithMembers(
            $mhs,
            $dosenOld,
            'DIGANTI',
            'ARCHIVED_READONLY',
            true,
            true,
            true,
            false
        );

        // Dosen Baru bertindak pada penugasan baru
        Sanctum::actingAs($dosenNew);

        // Dosen Baru tidak melihat room lama di list
        $responseList = $this->getJson('/api/bchat/rooms');
        $responseList->assertStatus(200);
        $this->assertCount(0, $responseList->json('data'));

        // Dosen Baru diblokir dengan 403 jika mencoba mengakses room lama
        $responseDetail = $this->getJson("/api/bchat/rooms/{$oldData['room']->room_uuid}");
        $responseDetail->assertStatus(403);
    }

    /**
     * 11. Metadata yang dikembalikan tidak membocorkan material kriptografi atau rahasia internal.
     */
    public function test_11_metadata_does_not_leak_cryptographic_secrets(): void
    {
        $mhs = $this->createTestUser('mahasiswa');
        $dosen = $this->createTestUser('dosen');
        $device = $this->createTestDevice($dosen, 'ACTIVE');
        $data = $this->createTestRoomWithMembers($mhs, $dosen);
        $challenge = $this->createTestChallenge($data['room'], $dosen, $device);

        Sanctum::actingAs($mhs);

        $response = $this->getJson("/api/bchat/rooms/{$data['room']->room_uuid}");
        $response->assertStatus(200);

        $body = $response->getContent();

        // Verifikasi tidak ada secret binary, nonce, signature, atau token yang bocor
        $this->assertStringNotContainsString($challenge->welcome_message, $body);
        $this->assertStringNotContainsString($challenge->challenge_nonce, $body);
        $this->assertStringNotContainsString('encrypted_mls_welcome', $body);
        $this->assertStringNotContainsString('public_key', $body);
        $this->assertStringNotContainsString('private_key', $body);
        $this->assertStringNotContainsString('ack_signature', $body);
        $this->assertStringNotContainsString('ack_payload_hash', $body);
        $this->assertStringNotContainsString('password', $body);
    }

    /**
     * 12. Nilai current_epoch diserialisasi sebagai decimal string.
     */
    public function test_12_current_epoch_is_serialized_as_decimal_string(): void
    {
        $mhs = $this->createTestUser('mahasiswa');
        $dosen = $this->createTestUser('dosen');
        $data = $this->createTestRoomWithMembers($mhs, $dosen);

        Sanctum::actingAs($mhs);

        $response = $this->getJson("/api/bchat/rooms/{$data['room']->room_uuid}");
        $response->assertStatus(200);

        $epoch = $response->json('data.current_epoch');
        $this->assertIsString($epoch);
        $this->assertEquals('0', $epoch);
    }

    /**
     * 13. partner.has_active_device akurat sesuai status perangkat aktif partner.
     */
    public function test_13_partner_has_active_device_reflects_actual_device_status(): void
    {
        $mhs = $this->createTestUser('mahasiswa');
        $dosen = $this->createTestUser('dosen');
        $data = $this->createTestRoomWithMembers($mhs, $dosen);

        Sanctum::actingAs($mhs);

        // Sebelum dosen memiliki device
        $response1 = $this->getJson("/api/bchat/rooms/{$data['room']->room_uuid}");
        $response1->assertStatus(200);
        $this->assertFalse($response1->json('data.partner.has_active_device'));

        // Setelah dosen mendaftarkan device ACTIVE
        $this->createTestDevice($dosen, 'ACTIVE');

        $response2 = $this->getJson("/api/bchat/rooms/{$data['room']->room_uuid}");
        $response2->assertStatus(200);
        $this->assertTrue($response2->json('data.partner.has_active_device'));
    }

    /**
     * 14. Mahasiswa menjadi inisiator default sebelum Welcome dipublikasikan.
     */
    public function test_14_mahasiswa_is_default_initiator_before_welcome_published(): void
    {
        $mhs = $this->createTestUser('mahasiswa');
        $dosen = $this->createTestUser('dosen');
        $data = $this->createTestRoomWithMembers($mhs, $dosen);

        Sanctum::actingAs($mhs);

        $response = $this->getJson("/api/bchat/rooms/{$data['room']->room_uuid}");
        $response->assertStatus(200);

        $response->assertJson([
            'status' => 'success',
            'data'   => [
                'action_hint' => [
                    'action_required' => true,
                    'action_type'     => 'PUBLISH_WELCOME',
                ],
            ],
        ]);
    }

    /**
     * 15. Dosen menjadi penerima default sebelum Welcome dipublikasikan.
     */
    public function test_15_dosen_is_default_recipient_before_welcome_published(): void
    {
        $mhs = $this->createTestUser('mahasiswa');
        $dosen = $this->createTestUser('dosen');
        $data = $this->createTestRoomWithMembers($mhs, $dosen);

        Sanctum::actingAs($dosen);

        $response = $this->getJson("/api/bchat/rooms/{$data['room']->room_uuid}");
        $response->assertStatus(200);

        $response->assertJson([
            'status' => 'success',
            'data'   => [
                'action_hint' => [
                    'action_required' => false,
                    'action_type'     => 'AWAIT_PARTNER_WELCOME',
                ],
            ],
        ]);
    }

    /**
     * 16. Action hint mengikuti operasi Welcome aktif yang aktual.
     */
    public function test_16_action_hint_reflects_actual_active_welcome_operation(): void
    {
        $mhs = $this->createTestUser('mahasiswa');
        $dosen = $this->createTestUser('dosen');
        $device = $this->createTestDevice($dosen, 'ACTIVE');
        $data = $this->createTestRoomWithMembers($mhs, $dosen);
        $this->createTestChallenge($data['room'], $dosen, $device);

        // Dosen (penerima tantangan) wajib mengambil challenge dan kirim ACK
        Sanctum::actingAs($dosen);
        $resDosen = $this->getJson("/api/bchat/rooms/{$data['room']->room_uuid}");
        $resDosen->assertStatus(200);
        $resDosen->assertJson([
            'data' => [
                'action_hint' => [
                    'action_required' => true,
                    'action_type'     => 'FETCH_CHALLENGE_AND_ACK',
                ],
            ],
        ]);

        // Mahasiswa (inisiator) menunggu konfirmasi ACK rekan
        Sanctum::actingAs($mhs);
        $resMhs = $this->getJson("/api/bchat/rooms/{$data['room']->room_uuid}");
        $resMhs->assertStatus(200);
        $resMhs->assertJson([
            'data' => [
                'action_hint' => [
                    'action_required' => false,
                    'action_type'     => 'AWAIT_PARTNER_ACK',
                ],
            ],
        ]);
    }

    /**
     * 17. Permintaan GET bersifat murni read-only dan tidak memutasi database.
     */
    public function test_17_get_requests_have_zero_side_effects_on_database(): void
    {
        $mhs = $this->createTestUser('mahasiswa');
        $dosen = $this->createTestUser('dosen');
        $data = $this->createTestRoomWithMembers($mhs, $dosen);

        Sanctum::actingAs($mhs);

        $this->getJson('/api/bchat/rooms')->assertStatus(200);
        $this->getJson("/api/bchat/rooms/{$data['room']->room_uuid}")->assertStatus(200);

        // Verifikasi tidak ada perubahan is_initiator di database
        $mhsMemberFresh = BchatRoomMember::find($data['mhsMember']->id);
        $dosMemberFresh = BchatRoomMember::find($data['dosMember']->id);
        $roomFresh = BchatRoom::find($data['room']->id);

        $this->assertFalse((bool) $mhsMemberFresh->is_initiator);
        $this->assertFalse((bool) $dosMemberFresh->is_initiator);
        $this->assertFalse((bool) $mhsMemberFresh->is_active);
        $this->assertFalse((bool) $dosMemberFresh->is_active);
        $this->assertEquals('PENDING_WELCOME_ACK', $roomFresh->status);
        $this->assertEquals(0, $roomFresh->current_epoch);
    }

    /**
     * 18. Ringkasan room menyertakan identitas unik mahasiswa (user_id, mahasiswa_id, nim)
     *     dari relasi resmi sehingga dua mahasiswa bernama sama tidak tertukar dan isolasi dosen terjaga.
     */
    public function test_18_room_summary_exposes_unique_student_identity_without_swapping_same_name_advisees(): void
    {
        $dosenUser = User::factory()->create([
            'role'        => 'dosen',
            'name'        => 'Dr. Pembimbing Utama',
            'nomor_induk' => '0411223301',
        ]);
        $dosen = Dosen::create([
            'user_id'    => $dosenUser->id,
            'NIDN'       => '0411223301',
            'nama_dosen' => 'Dr. Pembimbing Utama',
            'prodi'      => 'Informatika',
        ]);

        // Mahasiswa 1: bernama "Budi Santoso", NIM 210401005
        $mhsUser1 = User::factory()->create([
            'role'        => 'mahasiswa',
            'name'        => 'Budi Santoso',
            'nomor_induk' => '210401005',
        ]);
        $mhs1 = Mahasiswa::create([
            'user_id'        => $mhsUser1->id,
            'NIM'            => '210401005',
            'nama_mahasiswa' => 'Budi Santoso',
            'prodi'          => 'Informatika',
        ]);
        $skripsi1 = Skripsi::create([
            'id_mahasiswa' => $mhs1->id_mahasiswa,
            'judul'        => 'Proposal Budi Santoso Pertama',
            'status_judul' => 'diajukan',
        ]);
        $penugasan1 = PenugasanPembimbing::create([
            'skripsi_id'       => $skripsi1->id_skripsi,
            'tahap'            => 'proposal',
            'pembimbing_ke'    => 1,
            'dosen_id'         => $dosen->id_dosen,
            'status_penugasan' => 'AKTIF',
            'tanggal_mulai'    => now()->toDateString(),
        ]);
        $room1 = BchatRoom::create([
            'room_uuid'               => (string) Str::uuid(),
            'penugasan_pembimbing_id' => $penugasan1->id,
            'tahap'                   => 'proposal',
            'pembimbing_ke'           => 1,
            'current_sequence'        => 0,
            'status'                  => 'ACTIVE',
            'current_epoch'           => 1,
            'group_id_hash'           => hash('sha256', 'group_budi_1'),
        ]);
        BchatRoomMember::create([
            'room_id'         => $room1->id,
            'user_id'         => $mhsUser1->id,
            'role'            => 'mahasiswa',
            'is_initiator'    => true,
            'is_active'       => true,
            'joined_at_epoch' => 1,
        ]);
        BchatRoomMember::create([
            'room_id'         => $room1->id,
            'user_id'         => $dosenUser->id,
            'role'            => 'dosen_pembimbing',
            'is_initiator'    => false,
            'is_active'       => true,
            'joined_at_epoch' => 1,
        ]);

        // Mahasiswa 2: juga bernama persis "Budi Santoso", NIM 210401099
        $mhsUser2 = User::factory()->create([
            'role'        => 'mahasiswa',
            'name'        => 'Budi Santoso',
            'nomor_induk' => '210401099',
        ]);
        $mhs2 = Mahasiswa::create([
            'user_id'        => $mhsUser2->id,
            'NIM'            => '210401099',
            'nama_mahasiswa' => 'Budi Santoso',
            'prodi'          => 'Informatika',
        ]);
        $skripsi2 = Skripsi::create([
            'id_mahasiswa' => $mhs2->id_mahasiswa,
            'judul'        => 'Proposal Budi Santoso Kedua',
            'status_judul' => 'diajukan',
        ]);
        $penugasan2 = PenugasanPembimbing::create([
            'skripsi_id'       => $skripsi2->id_skripsi,
            'tahap'            => 'proposal',
            'pembimbing_ke'    => 1,
            'dosen_id'         => $dosen->id_dosen,
            'status_penugasan' => 'AKTIF',
            'tanggal_mulai'    => now()->toDateString(),
        ]);
        $room2 = BchatRoom::create([
            'room_uuid'               => (string) Str::uuid(),
            'penugasan_pembimbing_id' => $penugasan2->id,
            'tahap'                   => 'proposal',
            'pembimbing_ke'           => 1,
            'current_sequence'        => 0,
            'status'                  => 'ACTIVE',
            'current_epoch'           => 1,
            'group_id_hash'           => hash('sha256', 'group_budi_2'),
        ]);
        BchatRoomMember::create([
            'room_id'         => $room2->id,
            'user_id'         => $mhsUser2->id,
            'role'            => 'mahasiswa',
            'is_initiator'    => true,
            'is_active'       => true,
            'joined_at_epoch' => 1,
        ]);
        BchatRoomMember::create([
            'room_id'         => $room2->id,
            'user_id'         => $dosenUser->id,
            'role'            => 'dosen_pembimbing',
            'is_initiator'    => false,
            'is_active'       => true,
            'joined_at_epoch' => 1,
        ]);

        // Mahasiswa 3: milik dosen lain (tidak boleh muncul pada daftar dosenUser)
        $otherMhs = $this->createTestUser('mahasiswa');
        $otherDosen = $this->createTestUser('dosen');
        $otherData = $this->createTestRoomWithMembers($otherMhs, $otherDosen);

        Sanctum::actingAs($dosenUser);

        $response = $this->getJson('/api/bchat/rooms');
        $response->assertStatus(200);

        $rooms = collect($response->json('data'));
        $this->assertCount(2, $rooms);
        $this->assertFalse($rooms->contains('room_uuid', $otherData['room']->room_uuid));

        $summary1 = $rooms->firstWhere('room_uuid', $room1->room_uuid);
        $summary2 = $rooms->firstWhere('room_uuid', $room2->room_uuid);

        $this->assertNotNull($summary1);
        $this->assertNotNull($summary2);

        // Pastikan id_mahasiswa berbeda dengan users.id atau dapat dibedakan secara eksplisit
        $this->assertSame($mhsUser1->id, $summary1['partner']['user_id']);
        $this->assertSame($mhs1->id_mahasiswa, $summary1['partner']['mahasiswa_id']);
        $this->assertSame('210401005', $summary1['partner']['nim']);
        $this->assertSame('Budi Santoso', $summary1['partner']['name']);
        $this->assertSame('mahasiswa', $summary1['partner']['role']);

        $this->assertSame($mhsUser2->id, $summary2['partner']['user_id']);
        $this->assertSame($mhs2->id_mahasiswa, $summary2['partner']['mahasiswa_id']);
        $this->assertSame('210401099', $summary2['partner']['nim']);
        $this->assertSame('Budi Santoso', $summary2['partner']['name']);
        $this->assertSame('mahasiswa', $summary2['partner']['role']);

        $this->assertNotSame($summary1['partner']['user_id'], $summary2['partner']['user_id']);
        $this->assertNotSame($summary1['partner']['mahasiswa_id'], $summary2['partner']['mahasiswa_id']);
        $this->assertNotSame($summary1['partner']['nim'], $summary2['partner']['nim']);
    }

    /**
     * 19. Memastikan tidak terjadi N+1 query pada relasi mahasiswa ketika jumlah ruang bertambah.
     *     Query ke tabel mahasiswa dibatch melalui eager-loading dan tidak bertambah satu per ruang.
     */
    public function test_19_rooms_list_has_no_n_plus_one_query_for_student_relations(): void
    {
        $dosenUser = $this->createTestUser('dosen');
        $dosen = Dosen::create([
            'user_id'    => $dosenUser->id,
            'NIDN'       => $dosenUser->nomor_induk,
            'nama_dosen' => 'Dr. Dosen Tester N+1',
            'prodi'      => 'Informatika',
        ]);

        // Buat 3 mahasiswa dan 3 ruang berbeda untuk dosen yang sama
        for ($i = 1; $i <= 3; $i++) {
            $mhsUser = $this->createTestUser('mahasiswa');
            $mhs = Mahasiswa::create([
                'user_id'        => $mhsUser->id,
                'NIM'            => $mhsUser->nomor_induk,
                'nama_mahasiswa' => "Advisee {$i}",
                'prodi'          => 'Informatika',
            ]);
            $skripsi = Skripsi::create([
                'id_mahasiswa' => $mhs->id_mahasiswa,
                'judul'        => "Skripsi Advisee {$i}",
                'status_judul' => 'diajukan',
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
                'current_sequence'        => 0,
                'status'                  => 'ACTIVE',
                'current_epoch'           => 1,
                'group_id_hash'           => hash('sha256', "group_nplusone_{$i}"),
            ]);
            BchatRoomMember::create([
                'room_id'         => $room->id,
                'user_id'         => $mhsUser->id,
                'role'            => 'mahasiswa',
                'is_initiator'    => true,
                'is_active'       => true,
                'joined_at_epoch' => 1,
            ]);
            BchatRoomMember::create([
                'room_id'         => $room->id,
                'user_id'         => $dosenUser->id,
                'role'            => 'dosen_pembimbing',
                'is_initiator'    => false,
                'is_active'       => true,
                'joined_at_epoch' => 1,
            ]);
        }

        Sanctum::actingAs($dosenUser);

        $executedQueries = [];
        DB::listen(function ($query) use (&$executedQueries) {
            $executedQueries[] = [
                'sql'      => $query->sql,
                'bindings' => $query->bindings,
                'time'     => $query->time,
            ];
        });

        $response = $this->getJson('/api/bchat/rooms');
        $response->assertStatus(200);

        $data = $response->json('data');
        $this->assertCount(3, $data);

        // Ambil semua query yang mengakses tabel mahasiswa
        $mahasiswaQueries = array_filter($executedQueries, function ($q) {
            return stripos($q['sql'], '`mahasiswa`') !== false || stripos($q['sql'], ' mahasiswa ') !== false;
        });

        // Dengan eager-loading batch (WHERE IN), query ke tabel mahasiswa maksimal 2
        // (1 batch untuk penugasanPembimbing.skripsi.mahasiswa, 1 batch untuk members.user.mahasiswa).
        // JIKA terjadi N+1, jumlah query akan bertambah menjadi minimal 3 hingga 6 query (1 atau 2 query per ruang).
        $this->assertLessThanOrEqual(
            2,
            count($mahasiswaQueries),
            'Terdeteksi N+1: Query ke tabel mahasiswa melebihi batch eager-loading (' . count($mahasiswaQueries) . ' queries ditemukan untuk 3 ruang).'
        );

        // Pastikan setiap item respons memiliki struktur partner lengkap
        foreach ($data as $item) {
            $this->assertNotNull($item['partner']['user_id']);
            $this->assertNotNull($item['partner']['mahasiswa_id']);
            $this->assertNotNull($item['partner']['nim']);
            $this->assertEquals('mahasiswa', $item['partner']['role']);
        }
    }
}

