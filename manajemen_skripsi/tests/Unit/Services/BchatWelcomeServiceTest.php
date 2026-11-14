<?php

namespace Tests\Unit\Services;

use App\Exceptions\BchatWelcomeException;
use App\Models\BchatKeyPackage;
use App\Models\BchatRoom;
use App\Models\BchatRoomMember;
use App\Models\BchatUserDevice;
use App\Models\BchatWelcomeChallenge;
use App\Models\Dosen;
use App\Models\Mahasiswa;
use App\Models\PenugasanPembimbing;
use App\Models\Skripsi;
use App\Models\User;
use App\Services\BchatWelcomeService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class BchatWelcomeServiceTest extends TestCase
{
    use DatabaseTransactions;

    private BchatWelcomeService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $activeDb = DB::connection()->getDatabaseName();
        $this->assertEquals(
            'manajemen_skripsi_test',
            $activeDb,
            'CRITICAL SECURITY: Test suite B-CHAT HANYA boleh berjalan pada database manajemen_skripsi_test.'
        );

        $this->service = new BchatWelcomeService();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow(); // Reset Carbon time travel
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
        $pubKey = sodium_crypto_sign_publickey($keypair);

        return BchatUserDevice::create([
            'user_id'                => $user->id,
            'device_uuid'            => (string) Str::uuid(),
            'device_name'            => 'Test Browser Device',
            'key_version'            => 1,
            'public_key'             => bin2hex($pubKey),
            'public_key_fingerprint' => hash('sha256', $pubKey),
            'status'                 => $status,
            'last_active_at'         => now(),
        ]);
    }

    private function createTestRoomWithMembers(
        User $initiator,
        User $recipient,
        string $assignmentStatus = 'AKTIF',
        string $roomStatus = 'PENDING_WELCOME_ACK'
    ): BchatRoom {
        $mhs = Mahasiswa::create([
            'user_id'        => $initiator->id,
            'NIM'            => $initiator->nomor_induk,
            'nama_mahasiswa' => 'Mahasiswa Test ' . Str::random(4),
            'prodi'          => 'Informatika',
        ]);

        $skripsi = Skripsi::create([
            'id_mahasiswa' => $mhs->id_mahasiswa,
            'judul'        => 'Proposal E2EE Test ' . Str::random(5),
            'status_judul' => 'diajukan',
        ]);

        $dosen = Dosen::create([
            'user_id'    => $recipient->id,
            'NIDN'       => $recipient->nomor_induk,
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
            'current_epoch'           => 0,
            'group_id_hash'           => null,
        ]);

        BchatRoomMember::create([
            'room_id'         => $room->id,
            'user_id'         => $initiator->id,
            'role'            => 'mahasiswa',
            'is_initiator'    => false,
            'is_active'       => false,
            'joined_at_epoch' => 0,
        ]);

        BchatRoomMember::create([
            'room_id'         => $room->id,
            'user_id'         => $recipient->id,
            'role'            => 'dosen_pembimbing',
            'is_initiator'    => false,
            'is_active'       => false,
            'joined_at_epoch' => 0,
        ]);

        return $room;
    }

    private function createTestKeyPackage(
        User $user,
        BchatUserDevice $device,
        bool $isConsumed = false
    ): BchatKeyPackage {
        return BchatKeyPackage::create([
            'user_id'          => $user->id,
            'device_id'        => $device->id,
            'key_package_hash' => hash('sha256', Str::random(32)),
            'key_package_data' => 'mls_key_package_binary_' . Str::random(32),
            'is_consumed'      => $isConsumed,
            'consumed_at'      => $isConsumed ? now()->subMinute() : null,
        ]);
    }

    // =========================================================================
    // 1. UNIT TEST PUBLIKASI WELCOME (PUBLISH WELCOME)
    // =========================================================================

    /**
     * 1. Publikasi Welcome sukses menyimpan record dan mengonsumsi KeyPackage.
     */
    public function test_01_publish_welcome_success_stores_record_and_marks_key_package_consumed(): void
    {
        $initiator = $this->createTestUser('mahasiswa');
        $recipient = $this->createTestUser('dosen');
        $initDev = $this->createTestDevice($initiator);
        $recipDev = $this->createTestDevice($recipient);
        $room = $this->createTestRoomWithMembers($initiator, $recipient);
        $keyPackage = $this->createTestKeyPackage($recipient, $recipDev);

        $groupIdHash = hash('sha256', 'mls_group_context_test');
        $rawWelcome = 'encrypted_hpke_welcome_payload_data';
        $base64Welcome = base64_encode($rawWelcome);

        $result = $this->service->publishWelcome(
            initiatorUser: $initiator,
            roomUuid: $room->room_uuid,
            payloadData: [
                'recipient_device_uuid' => $recipDev->device_uuid,
                'key_package_hash'      => $keyPackage->key_package_hash,
                'group_id_hash'         => $groupIdHash,
                'epoch'                 => 1,
                'welcome_message'       => $base64Welcome,
            ]
        );

        $this->assertEquals('success', $result['status']);
        $this->assertEquals($room->room_uuid, $result['room_uuid']);
        $this->assertEquals($recipient->id, $result['recipient_user_id']);
        $this->assertEquals($recipDev->device_uuid, $result['recipient_device_uuid']);
        $this->assertEquals(1, $result['epoch']);
        $this->assertEquals(1, $result['challenge_generation']);

        // Verifikasi row bchat_welcome_challenges di DB
        $challenge = BchatWelcomeChallenge::where('welcome_operation_uuid', $result['welcome_operation_uuid'])->first();
        $this->assertNotNull($challenge);
        $this->assertEquals($room->id, $challenge->room_id);
        $this->assertEquals($recipient->id, $challenge->recipient_user_id);
        $this->assertEquals($recipDev->id, $challenge->recipient_device_id);
        $this->assertEquals('ACTIVE', $challenge->operation_status);
        $this->assertEquals('ISSUED', $challenge->challenge_status);
        $this->assertEquals(1, $challenge->challenge_generation);
        $this->assertEquals($rawWelcome, $challenge->welcome_message);
        $this->assertEquals($groupIdHash, $challenge->group_id_hash);

        // Verifikasi KeyPackage dikonsumsi
        $keyPackage->refresh();
        $this->assertTrue($keyPackage->is_consumed);
        $this->assertNotNull($keyPackage->consumed_at);
    }

    /**
     * 2. Welcome tepat 262.144 byte (256 KB) diterima.
     */
    public function test_02_publish_welcome_accepts_payload_exactly_262144_bytes(): void
    {
        $initiator = $this->createTestUser('mahasiswa');
        $recipient = $this->createTestUser('dosen');
        $this->createTestDevice($initiator);
        $recipDev = $this->createTestDevice($recipient);
        $room = $this->createTestRoomWithMembers($initiator, $recipient);
        $keyPackage = $this->createTestKeyPackage($recipient, $recipDev);

        $exactPayload = str_repeat('A', 262144);
        $base64Welcome = base64_encode($exactPayload);

        $result = $this->service->publishWelcome(
            initiatorUser: $initiator,
            roomUuid: $room->room_uuid,
            payloadData: [
                'recipient_device_uuid' => $recipDev->device_uuid,
                'key_package_hash'      => $keyPackage->key_package_hash,
                'group_id_hash'         => hash('sha256', 'group_exact_256k'),
                'epoch'                 => 1,
                'welcome_message'       => $base64Welcome,
            ]
        );

        $this->assertEquals('success', $result['status']);
    }

    /**
     * 3. Welcome 262.145 byte ditolak dengan PAYLOAD_SIZE_EXCEEDED (HTTP 422).
     */
    public function test_03_publish_welcome_rejects_payload_262145_bytes(): void
    {
        $initiator = $this->createTestUser('mahasiswa');
        $recipient = $this->createTestUser('dosen');
        $this->createTestDevice($initiator);
        $recipDev = $this->createTestDevice($recipient);
        $room = $this->createTestRoomWithMembers($initiator, $recipient);
        $keyPackage = $this->createTestKeyPackage($recipient, $recipDev);

        $oversizedPayload = str_repeat('B', 262145);
        $base64Welcome = base64_encode($oversizedPayload);

        $this->expectException(BchatWelcomeException::class);
        $this->expectExceptionMessage('Ukuran payload Welcome message setelah didekode melebihi batas maksimum 262.144 byte.');

        try {
            $this->service->publishWelcome(
                initiatorUser: $initiator,
                roomUuid: $room->room_uuid,
                payloadData: [
                    'recipient_device_uuid' => $recipDev->device_uuid,
                    'key_package_hash'      => $keyPackage->key_package_hash,
                    'group_id_hash'         => hash('sha256', 'group_overflow'),
                    'epoch'                 => 1,
                    'welcome_message'       => $base64Welcome,
                ]
            );
        } catch (BchatWelcomeException $e) {
            $this->assertEquals('PAYLOAD_SIZE_EXCEEDED', $e->getErrorCode());
            $this->assertEquals(422, $e->getHttpStatus());
            throw $e;
        }
    }

    /**
     * 4. Base64 malformed ditolak dengan INVALID_BASE64_ENCODING (HTTP 422).
     */
    public function test_04_publish_welcome_rejects_malformed_base64(): void
    {
        $initiator = $this->createTestUser('mahasiswa');
        $recipient = $this->createTestUser('dosen');
        $this->createTestDevice($initiator);
        $recipDev = $this->createTestDevice($recipient);
        $room = $this->createTestRoomWithMembers($initiator, $recipient);
        $keyPackage = $this->createTestKeyPackage($recipient, $recipDev);

        $this->expectException(BchatWelcomeException::class);

        try {
            $this->service->publishWelcome(
                initiatorUser: $initiator,
                roomUuid: $room->room_uuid,
                payloadData: [
                    'recipient_device_uuid' => $recipDev->device_uuid,
                    'key_package_hash'      => $keyPackage->key_package_hash,
                    'group_id_hash'         => hash('sha256', 'group_bad_b64'),
                    'epoch'                 => 1,
                    'welcome_message'       => '!!!NOT_VALID_BASE64_CHARACTERS@@@',
                ]
            );
        } catch (BchatWelcomeException $e) {
            $this->assertContains($e->getErrorCode(), ['INVALID_BASE64_ENCODING', 'NON_CANONICAL_BASE64']);
            $this->assertEquals(422, $e->getHttpStatus());
            throw $e;
        }
    }

    /**
     * 5. Base64 nonkanonis ditolak dengan NON_CANONICAL_BASE64 (HTTP 422).
     */
    public function test_05_publish_welcome_rejects_non_canonical_base64(): void
    {
        $initiator = $this->createTestUser('mahasiswa');
        $recipient = $this->createTestUser('dosen');
        $this->createTestDevice($initiator);
        $recipDev = $this->createTestDevice($recipient);
        $room = $this->createTestRoomWithMembers($initiator, $recipient);
        $keyPackage = $this->createTestKeyPackage($recipient, $recipDev);

        // Base64 dengan whitespace atau trailing newline tidak kanonis
        $nonCanonicalBase64 = base64_encode('test_message') . "\n";

        $this->expectException(BchatWelcomeException::class);

        try {
            $this->service->publishWelcome(
                initiatorUser: $initiator,
                roomUuid: $room->room_uuid,
                payloadData: [
                    'recipient_device_uuid' => $recipDev->device_uuid,
                    'key_package_hash'      => $keyPackage->key_package_hash,
                    'group_id_hash'         => hash('sha256', 'group_non_canonical'),
                    'epoch'                 => 1,
                    'welcome_message'       => $nonCanonicalBase64,
                ]
            );
        } catch (BchatWelcomeException $e) {
            $this->assertEquals('NON_CANONICAL_BASE64', $e->getErrorCode());
            $this->assertEquals(422, $e->getHttpStatus());
            throw $e;
        }
    }

    /**
     * 6. KeyPackage milik user lain ditolak dengan KEY_PACKAGE_USER_MISMATCH (HTTP 422).
     */
    public function test_06_publish_welcome_rejects_key_package_owned_by_another_user(): void
    {
        $initiator = $this->createTestUser('mahasiswa');
        $recipient = $this->createTestUser('dosen');
        $otherUser = $this->createTestUser('dosen');
        $this->createTestDevice($initiator);
        $recipDev = $this->createTestDevice($recipient);
        $otherDev = $this->createTestDevice($otherUser);
        $room = $this->createTestRoomWithMembers($initiator, $recipient);

        // KeyPackage milik otherUser
        $foreignKeyPackage = $this->createTestKeyPackage($otherUser, $otherDev);

        $this->expectException(BchatWelcomeException::class);

        try {
            $this->service->publishWelcome(
                initiatorUser: $initiator,
                roomUuid: $room->room_uuid,
                payloadData: [
                    'recipient_device_uuid' => $recipDev->device_uuid,
                    'key_package_hash'      => $foreignKeyPackage->key_package_hash,
                    'group_id_hash'         => hash('sha256', 'group_foreign'),
                    'epoch'                 => 1,
                    'welcome_message'       => base64_encode('welcome_data'),
                ]
            );
        } catch (BchatWelcomeException $e) {
            $this->assertEquals('KEY_PACKAGE_USER_MISMATCH', $e->getErrorCode());
            $this->assertEquals(422, $e->getHttpStatus());
            throw $e;
        }
    }

    /**
     * 7. KeyPackage milik device lain ditolak dengan KEY_PACKAGE_DEVICE_MISMATCH (HTTP 422).
     */
    public function test_07_publish_welcome_rejects_key_package_bound_to_different_device(): void
    {
        $initiator = $this->createTestUser('mahasiswa');
        $recipient = $this->createTestUser('dosen');
        $this->createTestDevice($initiator);
        $recipDevOld = $this->createTestDevice($recipient, status: 'REVOKED');
        $recipDevActive = $this->createTestDevice($recipient, status: 'ACTIVE');
        $room = $this->createTestRoomWithMembers($initiator, $recipient);

        // KeyPackage terikat ke perangkat lama (recipDevOld), tapi request publikasi menargetkan perangkat aktif (recipDevActive)
        $keyPackageOldDev = $this->createTestKeyPackage($recipient, $recipDevOld);

        $this->expectException(BchatWelcomeException::class);

        try {
            $this->service->publishWelcome(
                initiatorUser: $initiator,
                roomUuid: $room->room_uuid,
                payloadData: [
                    'recipient_device_uuid' => $recipDevActive->device_uuid,
                    'key_package_hash'      => $keyPackageOldDev->key_package_hash,
                    'group_id_hash'         => hash('sha256', 'group_dev_mismatch'),
                    'epoch'                 => 1,
                    'welcome_message'       => base64_encode('welcome_data'),
                ]
            );
        } catch (BchatWelcomeException $e) {
            $this->assertEquals('KEY_PACKAGE_DEVICE_MISMATCH', $e->getErrorCode());
            $this->assertEquals(422, $e->getHttpStatus());
            throw $e;
        }
    }

    /**
     * 8. KeyPackage yang sudah dikonsumsi ditolak dengan KEY_PACKAGE_ALREADY_CONSUMED (HTTP 409).
     */
    public function test_08_publish_welcome_rejects_key_package_already_consumed(): void
    {
        $initiator = $this->createTestUser('mahasiswa');
        $recipient = $this->createTestUser('dosen');
        $this->createTestDevice($initiator);
        $recipDev = $this->createTestDevice($recipient);
        $room = $this->createTestRoomWithMembers($initiator, $recipient);

        $consumedKeyPackage = $this->createTestKeyPackage($recipient, $recipDev, isConsumed: true);

        $this->expectException(BchatWelcomeException::class);

        try {
            $this->service->publishWelcome(
                initiatorUser: $initiator,
                roomUuid: $room->room_uuid,
                payloadData: [
                    'recipient_device_uuid' => $recipDev->device_uuid,
                    'key_package_hash'      => $consumedKeyPackage->key_package_hash,
                    'group_id_hash'         => hash('sha256', 'group_consumed'),
                    'epoch'                 => 1,
                    'welcome_message'       => base64_encode('welcome_data'),
                ]
            );
        } catch (BchatWelcomeException $e) {
            $this->assertEquals('KEY_PACKAGE_ALREADY_CONSUMED', $e->getErrorCode());
            $this->assertEquals(409, $e->getHttpStatus());
            throw $e;
        }
    }

    /**
     * 9. Assignment tidak aktif ditolak dengan ASSIGNMENT_INACTIVE (HTTP 403).
     */
    public function test_09_publish_welcome_rejects_when_assignment_is_inactive(): void
    {
        $initiator = $this->createTestUser('mahasiswa');
        $recipient = $this->createTestUser('dosen');
        $this->createTestDevice($initiator);
        $recipDev = $this->createTestDevice($recipient);
        $room = $this->createTestRoomWithMembers($initiator, $recipient, assignmentStatus: 'DIBATALKAN');
        $keyPackage = $this->createTestKeyPackage($recipient, $recipDev);

        $this->expectException(BchatWelcomeException::class);

        try {
            $this->service->publishWelcome(
                initiatorUser: $initiator,
                roomUuid: $room->room_uuid,
                payloadData: [
                    'recipient_device_uuid' => $recipDev->device_uuid,
                    'key_package_hash'      => $keyPackage->key_package_hash,
                    'group_id_hash'         => hash('sha256', 'group_inactive_assign'),
                    'epoch'                 => 1,
                    'welcome_message'       => base64_encode('welcome_data'),
                ]
            );
        } catch (BchatWelcomeException $e) {
            $this->assertEquals('ASSIGNMENT_INACTIVE', $e->getErrorCode());
            $this->assertEquals(403, $e->getHttpStatus());
            throw $e;
        }
    }

    /**
     * 10. Publikasi Welcome ganda dalam room ditolak dengan ACTIVE_WELCOME_OPERATION_EXISTS (HTTP 409).
     */
    public function test_10_publish_welcome_rejects_duplicate_active_welcome_in_room(): void
    {
        $initiator = $this->createTestUser('mahasiswa');
        $recipient = $this->createTestUser('dosen');
        $this->createTestDevice($initiator);
        $recipDev = $this->createTestDevice($recipient);
        $room = $this->createTestRoomWithMembers($initiator, $recipient);
        $keyPackage1 = $this->createTestKeyPackage($recipient, $recipDev);
        $keyPackage2 = $this->createTestKeyPackage($recipient, $recipDev);

        // Publikasi pertama sukses
        $this->service->publishWelcome(
            initiatorUser: $initiator,
            roomUuid: $room->room_uuid,
            payloadData: [
                'recipient_device_uuid' => $recipDev->device_uuid,
                'key_package_hash'      => $keyPackage1->key_package_hash,
                'group_id_hash'         => hash('sha256', 'group_first'),
                'epoch'                 => 1,
                'welcome_message'       => base64_encode('welcome_data_first'),
            ]
        );

        // Publikasi kedua pada room yang sama harus ditolak
        $this->expectException(BchatWelcomeException::class);

        try {
            $this->service->publishWelcome(
                initiatorUser: $initiator,
                roomUuid: $room->room_uuid,
                payloadData: [
                    'recipient_device_uuid' => $recipDev->device_uuid,
                    'key_package_hash'      => $keyPackage2->key_package_hash,
                    'group_id_hash'         => hash('sha256', 'group_second'),
                    'epoch'                 => 1,
                    'welcome_message'       => base64_encode('welcome_data_second'),
                ]
            );
        } catch (BchatWelcomeException $e) {
            $this->assertEquals('ACTIVE_WELCOME_OPERATION_EXISTS', $e->getErrorCode());
            $this->assertEquals(409, $e->getHttpStatus());
            throw $e;
        }
    }

    /**
     * 11. Initiator ditandai is_initiator=true tanpa mengaktifkan anggota (kedua anggota tetap is_active=false).
     */
    public function test_11_publish_welcome_updates_initiator_flag_without_activating_members(): void
    {
        $initiator = $this->createTestUser('mahasiswa');
        $recipient = $this->createTestUser('dosen');
        $this->createTestDevice($initiator);
        $recipDev = $this->createTestDevice($recipient);
        $room = $this->createTestRoomWithMembers($initiator, $recipient);
        $keyPackage = $this->createTestKeyPackage($recipient, $recipDev);

        $this->service->publishWelcome(
            initiatorUser: $initiator,
            roomUuid: $room->room_uuid,
            payloadData: [
                'recipient_device_uuid' => $recipDev->device_uuid,
                'key_package_hash'      => $keyPackage->key_package_hash,
                'group_id_hash'         => hash('sha256', 'group_init_check'),
                'epoch'                 => 1,
                'welcome_message'       => base64_encode('welcome_data'),
            ]
        );

        $initMember = BchatRoomMember::where('room_id', $room->id)->where('user_id', $initiator->id)->first();
        $recipMember = BchatRoomMember::where('room_id', $room->id)->where('user_id', $recipient->id)->first();

        $this->assertTrue((bool) $initMember->is_initiator);
        $this->assertFalse((bool) $initMember->is_active);
        $this->assertFalse((bool) $recipMember->is_initiator);
        $this->assertFalse((bool) $recipMember->is_active);
    }

    /**
     * 12. Room tetap berstatus PENDING_WELCOME_ACK dengan current_epoch=0.
     */
    public function test_12_publish_welcome_room_remains_pending_welcome_ack_and_epoch_0(): void
    {
        $initiator = $this->createTestUser('mahasiswa');
        $recipient = $this->createTestUser('dosen');
        $this->createTestDevice($initiator);
        $recipDev = $this->createTestDevice($recipient);
        $room = $this->createTestRoomWithMembers($initiator, $recipient);
        $keyPackage = $this->createTestKeyPackage($recipient, $recipDev);

        $groupIdHash = hash('sha256', 'group_epoch_check');

        $this->service->publishWelcome(
            initiatorUser: $initiator,
            roomUuid: $room->room_uuid,
            payloadData: [
                'recipient_device_uuid' => $recipDev->device_uuid,
                'key_package_hash'      => $keyPackage->key_package_hash,
                'group_id_hash'         => $groupIdHash,
                'epoch'                 => 1,
                'welcome_message'       => base64_encode('welcome_data'),
            ]
        );

        $room->refresh();
        $this->assertEquals('PENDING_WELCOME_ACK', $room->status);
        $this->assertEquals(0, $room->current_epoch);
        $this->assertEquals($groupIdHash, $room->group_id_hash);
    }

    // =========================================================================
    // 2. UNIT TEST PERMINTAAN & ROTASI CHALLENGE (ISSUE OR ROTATE CHALLENGE)
    // =========================================================================

    /**
     * 13. Permintaan challenge pertama kali berhasil diterbitkan (Generasi 1, ISSUED).
     */
    public function test_13_issue_or_rotate_challenge_first_generation_success(): void
    {
        $initiator = $this->createTestUser('mahasiswa');
        $recipient = $this->createTestUser('dosen');
        $this->createTestDevice($initiator);
        $recipDev = $this->createTestDevice($recipient);
        $room = $this->createTestRoomWithMembers($initiator, $recipient);
        $keyPackage = $this->createTestKeyPackage($recipient, $recipDev);

        $rawWelcome = 'binary_welcome_message_payload';
        $this->service->publishWelcome(
            initiatorUser: $initiator,
            roomUuid: $room->room_uuid,
            payloadData: [
                'recipient_device_uuid' => $recipDev->device_uuid,
                'key_package_hash'      => $keyPackage->key_package_hash,
                'group_id_hash'         => hash('sha256', 'group_challenge_first'),
                'epoch'                 => 1,
                'welcome_message'       => base64_encode($rawWelcome),
            ]
        );

        $challengeResult = $this->service->issueOrRotateChallenge(
            requestingUser: $recipient,
            roomUuid: $room->room_uuid
        );

        $this->assertEquals('success', $challengeResult['status']);
        $this->assertEquals($room->room_uuid, $challengeResult['room_uuid']);
        $this->assertEquals($rawWelcome, base64_decode($challengeResult['welcome_message']));
        $this->assertEquals(1, $challengeResult['challenge_generation']);
        $this->assertEquals(1, $challengeResult['epoch']);
        $this->assertEquals(64, strlen($challengeResult['challenge_nonce']));
        $this->assertTrue(Str::isUuid($challengeResult['challenge_uuid']));
        $this->assertGreaterThan(280, $challengeResult['ttl_remaining_seconds']);
    }

    /**
     * 14. Request challenge ulang dengan TTL > 60 detik mengembalikan challenge yang sama (idempotent).
     */
    public function test_14_request_challenge_within_ttl_greater_than_60s_returns_identical_challenge(): void
    {
        $initiator = $this->createTestUser('mahasiswa');
        $recipient = $this->createTestUser('dosen');
        $this->createTestDevice($initiator);
        $recipDev = $this->createTestDevice($recipient);
        $room = $this->createTestRoomWithMembers($initiator, $recipient);
        $keyPackage = $this->createTestKeyPackage($recipient, $recipDev);

        $this->service->publishWelcome(
            initiatorUser: $initiator,
            roomUuid: $room->room_uuid,
            payloadData: [
                'recipient_device_uuid' => $recipDev->device_uuid,
                'key_package_hash'      => $keyPackage->key_package_hash,
                'group_id_hash'         => hash('sha256', 'group_ttl_test'),
                'epoch'                 => 1,
                'welcome_message'       => base64_encode('welcome_data'),
            ]
        );

        $firstChallenge = $this->service->issueOrRotateChallenge($recipient, $room->room_uuid);

        // Waktu maju 60 detik (Sisa TTL = 240 detik > 60 detik)
        Carbon::setTestNow(now()->addSeconds(60));

        $secondChallenge = $this->service->issueOrRotateChallenge($recipient, $room->room_uuid);

        $this->assertEquals($firstChallenge['challenge_uuid'], $secondChallenge['challenge_uuid']);
        $this->assertEquals($firstChallenge['challenge_nonce'], $secondChallenge['challenge_nonce']);
        $this->assertEquals($firstChallenge['challenge_generation'], $secondChallenge['challenge_generation']);
        $this->assertEquals(1, $secondChallenge['challenge_generation']);
    }

    /**
     * 15. Request challenge ulang saat TTL antara 1-60 detik TETAP mengembalikan challenge yang sama.
     */
    public function test_15_request_challenge_within_ttl_1_to_60s_returns_identical_challenge(): void
    {
        $initiator = $this->createTestUser('mahasiswa');
        $recipient = $this->createTestUser('dosen');
        $this->createTestDevice($initiator);
        $recipDev = $this->createTestDevice($recipient);
        $room = $this->createTestRoomWithMembers($initiator, $recipient);
        $keyPackage = $this->createTestKeyPackage($recipient, $recipDev);

        $this->service->publishWelcome(
            initiatorUser: $initiator,
            roomUuid: $room->room_uuid,
            payloadData: [
                'recipient_device_uuid' => $recipDev->device_uuid,
                'key_package_hash'      => $keyPackage->key_package_hash,
                'group_id_hash'         => hash('sha256', 'group_ttl_1_60'),
                'epoch'                 => 1,
                'welcome_message'       => base64_encode('welcome_data'),
            ]
        );

        $firstChallenge = $this->service->issueOrRotateChallenge($recipient, $room->room_uuid);

        // Waktu maju 4 menit 30 detik (Sisa TTL = 30 detik, berada pada rentang 1-60 detik)
        Carbon::setTestNow(now()->addSeconds(270));

        $secondChallenge = $this->service->issueOrRotateChallenge($recipient, $room->room_uuid);

        // Tetap identik, rotasi prematur dilarang
        $this->assertEquals($firstChallenge['challenge_uuid'], $secondChallenge['challenge_uuid']);
        $this->assertEquals($firstChallenge['challenge_nonce'], $secondChallenge['challenge_nonce']);
        $this->assertEquals($firstChallenge['challenge_generation'], $secondChallenge['challenge_generation']);
        $this->assertEquals(1, $secondChallenge['challenge_generation']);
    }

    /**
     * 16. Challenge yang sudah kedaluwarsa dirotasi in-place dengan nonce dan challenge_uuid baru.
     */
    public function test_16_request_challenge_when_expired_rotates_in_place(): void
    {
        $initiator = $this->createTestUser('mahasiswa');
        $recipient = $this->createTestUser('dosen');
        $this->createTestDevice($initiator);
        $recipDev = $this->createTestDevice($recipient);
        $room = $this->createTestRoomWithMembers($initiator, $recipient);
        $keyPackage = $this->createTestKeyPackage($recipient, $recipDev);

        $this->service->publishWelcome(
            initiatorUser: $initiator,
            roomUuid: $room->room_uuid,
            payloadData: [
                'recipient_device_uuid' => $recipDev->device_uuid,
                'key_package_hash'      => $keyPackage->key_package_hash,
                'group_id_hash'         => hash('sha256', 'group_rotation'),
                'epoch'                 => 1,
                'welcome_message'       => base64_encode('welcome_data'),
            ]
        );

        $firstChallenge = $this->service->issueOrRotateChallenge($recipient, $room->room_uuid);

        // Waktu maju melewati 5 menit (5 menit 2 detik) -> Benar-benar expired
        Carbon::setTestNow(now()->addMinutes(5)->addSeconds(2));

        $rotatedChallenge = $this->service->issueOrRotateChallenge($recipient, $room->room_uuid);

        // Generasi bertambah 1
        $this->assertEquals(2, $rotatedChallenge['challenge_generation']);
        // Nonce dan UUID berganti
        $this->assertNotEquals($firstChallenge['challenge_uuid'], $rotatedChallenge['challenge_uuid']);
        $this->assertNotEquals($firstChallenge['challenge_nonce'], $rotatedChallenge['challenge_nonce']);
        // Operasi UUID tetap sama
        $this->assertEquals($firstChallenge['welcome_operation_uuid'], $rotatedChallenge['welcome_operation_uuid']);
        // TTL diperbarui menjadi ~300 detik
        $this->assertGreaterThan(290, $rotatedChallenge['ttl_remaining_seconds']);
    }

    /**
     * 17. Rotasi berulang menaikkan nomor generasi secara monotonik (1 -> 2 -> 3).
     */
    public function test_17_challenge_rotation_increments_generation_by_exactly_one(): void
    {
        $initiator = $this->createTestUser('mahasiswa');
        $recipient = $this->createTestUser('dosen');
        $this->createTestDevice($initiator);
        $recipDev = $this->createTestDevice($recipient);
        $room = $this->createTestRoomWithMembers($initiator, $recipient);
        $keyPackage = $this->createTestKeyPackage($recipient, $recipDev);

        $this->service->publishWelcome(
            initiatorUser: $initiator,
            roomUuid: $room->room_uuid,
            payloadData: [
                'recipient_device_uuid' => $recipDev->device_uuid,
                'key_package_hash'      => $keyPackage->key_package_hash,
                'group_id_hash'         => hash('sha256', 'group_gen_mono'),
                'epoch'                 => 1,
                'welcome_message'       => base64_encode('welcome_data'),
            ]
        );

        $gen1 = $this->service->issueOrRotateChallenge($recipient, $room->room_uuid);
        $this->assertEquals(1, $gen1['challenge_generation']);

        Carbon::setTestNow(now()->addMinutes(6));
        $gen2 = $this->service->issueOrRotateChallenge($recipient, $room->room_uuid);
        $this->assertEquals(2, $gen2['challenge_generation']);

        Carbon::setTestNow(now()->addMinutes(6));
        $gen3 = $this->service->issueOrRotateChallenge($recipient, $room->room_uuid);
        $this->assertEquals(3, $gen3['challenge_generation']);
    }

    /**
     * 18. Rotasi challenge mempertahankan metadata operasi induk tanpa perubahan.
     */
    public function test_18_challenge_rotation_preserves_immutable_parent_operation_metadata(): void
    {
        $initiator = $this->createTestUser('mahasiswa');
        $recipient = $this->createTestUser('dosen');
        $this->createTestDevice($initiator);
        $recipDev = $this->createTestDevice($recipient);
        $room = $this->createTestRoomWithMembers($initiator, $recipient);
        $keyPackage = $this->createTestKeyPackage($recipient, $recipDev);

        $rawWelcome = 'immutable_hpke_binary_payload';
        $groupIdHash = hash('sha256', 'immutable_group_id');

        $this->service->publishWelcome(
            initiatorUser: $initiator,
            roomUuid: $room->room_uuid,
            payloadData: [
                'recipient_device_uuid' => $recipDev->device_uuid,
                'key_package_hash'      => $keyPackage->key_package_hash,
                'group_id_hash'         => $groupIdHash,
                'epoch'                 => 1,
                'welcome_message'       => base64_encode($rawWelcome),
            ]
        );

        $beforeRotation = $this->service->issueOrRotateChallenge($recipient, $room->room_uuid);

        Carbon::setTestNow(now()->addMinutes(6));
        $afterRotation = $this->service->issueOrRotateChallenge($recipient, $room->room_uuid);

        $this->assertEquals($beforeRotation['welcome_operation_uuid'], $afterRotation['welcome_operation_uuid']);
        $this->assertEquals($beforeRotation['welcome_message'], $afterRotation['welcome_message']);
        $this->assertEquals($beforeRotation['group_id_hash'], $afterRotation['group_id_hash']);
        $this->assertEquals($beforeRotation['epoch'], $afterRotation['epoch']);
        $this->assertEquals(1, $afterRotation['epoch']);
    }

    /**
     * 19. Rotasi challenge menolak overflow generasi di atas 4.294.967.295.
     */
    public function test_19_challenge_rotation_rejects_generation_overflow_above_uint32_max(): void
    {
        $initiator = $this->createTestUser('mahasiswa');
        $recipient = $this->createTestUser('dosen');
        $this->createTestDevice($initiator);
        $recipDev = $this->createTestDevice($recipient);
        $room = $this->createTestRoomWithMembers($initiator, $recipient);
        $keyPackage = $this->createTestKeyPackage($recipient, $recipDev);

        $this->service->publishWelcome(
            initiatorUser: $initiator,
            roomUuid: $room->room_uuid,
            payloadData: [
                'recipient_device_uuid' => $recipDev->device_uuid,
                'key_package_hash'      => $keyPackage->key_package_hash,
                'group_id_hash'         => hash('sha256', 'group_overflow_test'),
                'epoch'                 => 1,
                'welcome_message'       => base64_encode('welcome_data'),
            ]
        );

        $challenge = BchatWelcomeChallenge::where('room_id', $room->id)->first();
        $challenge->challenge_generation = 4294967295; // Nilai maksimum uint32
        $challenge->save();

        Carbon::setTestNow(now()->addMinutes(6));

        $this->expectException(BchatWelcomeException::class);

        try {
            $this->service->issueOrRotateChallenge($recipient, $room->room_uuid);
        } catch (BchatWelcomeException $e) {
            $this->assertEquals('CHALLENGE_GENERATION_OVERFLOW', $e->getErrorCode());
            $this->assertEquals(409, $e->getHttpStatus());
            throw $e;
        }
    }

    /**
     * 20. Permintaan challenge oleh inisiator atau pengguna non-penerima ditolak (HTTP 403).
     */
    public function test_20_request_challenge_by_initiator_or_non_recipient_rejected(): void
    {
        $initiator = $this->createTestUser('mahasiswa');
        $recipient = $this->createTestUser('dosen');
        $otherUser = $this->createTestUser('mahasiswa');
        $this->createTestDevice($initiator);
        $recipDev = $this->createTestDevice($recipient);
        $this->createTestDevice($otherUser);
        $room = $this->createTestRoomWithMembers($initiator, $recipient);
        $keyPackage = $this->createTestKeyPackage($recipient, $recipDev);

        $this->service->publishWelcome(
            initiatorUser: $initiator,
            roomUuid: $room->room_uuid,
            payloadData: [
                'recipient_device_uuid' => $recipDev->device_uuid,
                'key_package_hash'      => $keyPackage->key_package_hash,
                'group_id_hash'         => hash('sha256', 'group_auth_test'),
                'epoch'                 => 1,
                'welcome_message'       => base64_encode('welcome_data'),
            ]
        );

        // 1. Inisiator mencoba meminta challenge
        try {
            $this->service->issueOrRotateChallenge($initiator, $room->room_uuid);
            $this->fail('Inisiator seharusnya ditolak saat meminta challenge.');
        } catch (BchatWelcomeException $e) {
            $this->assertEquals('INITIATOR_NOT_ALLOWED_CHALLENGE', $e->getErrorCode());
            $this->assertEquals(403, $e->getHttpStatus());
        }

        // 2. Pengguna lain bukan anggota mencoba meminta challenge
        try {
            $this->service->issueOrRotateChallenge($otherUser, $room->room_uuid);
            $this->fail('Pengguna non-anggota seharusnya ditolak.');
        } catch (BchatWelcomeException $e) {
            $this->assertEquals('UNAUTHORIZED_NOT_MEMBER', $e->getErrorCode());
            $this->assertEquals(403, $e->getHttpStatus());
        }
    }

    /**
     * 21. Permintaan challenge ditolak jika perangkat recipient berstatus REVOKED (HTTP 422).
     */
    public function test_21_request_challenge_rejected_if_recipient_device_revoked(): void
    {
        $initiator = $this->createTestUser('mahasiswa');
        $recipient = $this->createTestUser('dosen');
        $this->createTestDevice($initiator);
        $recipDev = $this->createTestDevice($recipient);
        $room = $this->createTestRoomWithMembers($initiator, $recipient);
        $keyPackage = $this->createTestKeyPackage($recipient, $recipDev);

        $this->service->publishWelcome(
            initiatorUser: $initiator,
            roomUuid: $room->room_uuid,
            payloadData: [
                'recipient_device_uuid' => $recipDev->device_uuid,
                'key_package_hash'      => $keyPackage->key_package_hash,
                'group_id_hash'         => hash('sha256', 'group_revoked_dev'),
                'epoch'                 => 1,
                'welcome_message'       => base64_encode('welcome_data'),
            ]
        );

        // Cabut status aktif perangkat penerima
        $recipDev->status = 'REVOKED';
        $recipDev->revoked_at = now();
        $recipDev->save();

        $this->expectException(BchatWelcomeException::class);

        try {
            $this->service->issueOrRotateChallenge($recipient, $room->room_uuid);
        } catch (BchatWelcomeException $e) {
            $this->assertEquals('RECIPIENT_DEVICE_NOT_ACTIVE', $e->getErrorCode());
            $this->assertEquals(422, $e->getHttpStatus());
            throw $e;
        }
    }

    /**
     * 22. Transaksi gagal tidak meninggalkan mutasi parsial (rollback atomik).
     */
    public function test_22_transaction_rollback_leaves_no_partial_mutations_on_failure(): void
    {
        $initiator = $this->createTestUser('mahasiswa');
        $recipient = $this->createTestUser('dosen');
        $this->createTestDevice($initiator);
        $recipDev = $this->createTestDevice($recipient);
        $room = $this->createTestRoomWithMembers($initiator, $recipient);
        $keyPackage = $this->createTestKeyPackage($recipient, $recipDev);

        // Kirim epoch yang salah (epoch 2) untuk memicu rollback
        try {
            $this->service->publishWelcome(
                initiatorUser: $initiator,
                roomUuid: $room->room_uuid,
                payloadData: [
                    'recipient_device_uuid' => $recipDev->device_uuid,
                    'key_package_hash'      => $keyPackage->key_package_hash,
                    'group_id_hash'         => hash('sha256', 'group_fail_rollback'),
                    'epoch'                 => 2, // Salah, harus 1
                    'welcome_message'       => base64_encode('welcome_data'),
                ]
            );
            $this->fail('Harus gagal karena epoch tidak valid.');
        } catch (BchatWelcomeException $e) {
            $this->assertEquals('INVALID_EPOCH', $e->getErrorCode());
        }

        // Buktikan tidak ada baris bchat_welcome_challenges yang tercipta
        $this->assertEquals(0, BchatWelcomeChallenge::where('room_id', $room->id)->count());

        // Buktikan KeyPackage tetap belum dikonsumsi
        $keyPackage->refresh();
        $this->assertFalse($keyPackage->is_consumed);
        $this->assertNull($keyPackage->consumed_at);

        // Buktikan initiator tidak ditandai is_initiator
        $initMember = BchatRoomMember::where('room_id', $room->id)->where('user_id', $initiator->id)->first();
        $this->assertFalse((bool) $initMember->is_initiator);
    }
}

