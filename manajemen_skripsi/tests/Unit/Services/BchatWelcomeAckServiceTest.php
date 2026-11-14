<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Events\BchatRoomActivated;
use App\Exceptions\BchatWelcomeException;
use App\Models\BchatRoom;
use App\Models\BchatRoomMember;
use App\Models\BchatUserDevice;
use App\Models\BchatWelcomeChallenge;
use App\Models\Dosen;
use App\Models\Mahasiswa;
use App\Models\PenugasanPembimbing;
use App\Models\Skripsi;
use App\Models\User;
use App\Services\BchatWelcomeAckService;
use App\Services\BchatWelcomeAckVerificationService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Unit Test komprehensif untuk BchatWelcomeAckService (Tahap 2B.3).
 *
 * Menguji seluruh kontrak teknis aktivasi atomik, Zero Trust 158-byte,
 * hierarchical locking, penegakan identitas, dan replay protection.
 */
class BchatWelcomeAckServiceTest extends TestCase
{
    use DatabaseTransactions;

    private BchatWelcomeAckService $service;
    private BchatWelcomeAckVerificationService $verifier;

    protected function setUp(): void
    {
        parent::setUp();

        $activeDb = DB::connection()->getDatabaseName();
        $this->assertEquals(
            'manajemen_skripsi_test',
            $activeDb,
            'CRITICAL SECURITY: Test suite B-CHAT HANYA boleh berjalan pada database manajemen_skripsi_test.'
        );

        $this->verifier = new BchatWelcomeAckVerificationService();
        $this->service = new BchatWelcomeAckService($this->verifier);
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

    private function createTestDevice(User $user, string $status = 'ACTIVE'): array
    {
        $keypair = sodium_crypto_sign_keypair();
        $secretKey = sodium_crypto_sign_secretkey($keypair);
        $publicKey = sodium_crypto_sign_publickey($keypair);

        $device = BchatUserDevice::create([
            'user_id'                => $user->id,
            'device_uuid'            => (string) Str::uuid(),
            'device_name'            => 'Test Device',
            'key_version'            => 1,
            'public_key'             => bin2hex($publicKey),
            'public_key_fingerprint' => hash('sha256', $publicKey),
            'status'                 => $status,
            'last_active_at'         => now(),
        ]);

        return [
            'device'    => $device,
            'keypair'   => $keypair,
            'secretKey' => $secretKey,
            'publicKey' => $publicKey,
        ];
    }

    private function createTestRoomWithMembers(
        User $initiator,
        User $recipient,
        string $assignmentStatus = 'AKTIF',
        string $roomStatus = 'PENDING_WELCOME_ACK',
        int $epoch = 0
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
            'current_epoch'           => $epoch,
            'group_id_hash'           => hash('sha256', 'group_context_test'),
        ]);

        BchatRoomMember::create([
            'room_id'         => $room->id,
            'user_id'         => $initiator->id,
            'role'            => 'mahasiswa',
            'is_initiator'    => true,
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

    private function createTestChallenge(
        BchatRoom $room,
        User $recipient,
        BchatUserDevice $recipientDevice,
        string $operationStatus = 'ACTIVE',
        string $challengeStatus = 'ISSUED',
        ?Carbon $expiresAt = null
    ): BchatWelcomeChallenge {
        return BchatWelcomeChallenge::create([
            'welcome_operation_uuid' => (string) Str::uuid(),
            'room_id'                => $room->id,
            'recipient_user_id'      => $recipient->id,
            'recipient_device_id'    => $recipientDevice->id,
            'welcome_message'        => 'welcome_message_binary_encrypted_hpke',
            'group_id_hash'          => $room->group_id_hash ?? hash('sha256', 'group_context_test'),
            'epoch'                  => 1,
            'operation_status'       => $operationStatus,
            'challenge_uuid'         => (string) Str::uuid(),
            'challenge_nonce'        => bin2hex(random_bytes(32)),
            'challenge_generation'   => 1,
            'challenge_status'       => $challengeStatus,
            'expires_at'             => $expiresAt ?? now()->addMinutes(5),
            'consumed_at'            => null,
        ]);
    }

    private function generateValidSignature(
        BchatRoom $room,
        User $recipient,
        BchatWelcomeChallenge $challenge,
        string $secretKey
    ): string {
        $canonicalPayload = BchatWelcomeAckVerificationService::buildPayload(
            roomUuid: $room->room_uuid,
            recipientUserId: (int) $recipient->id,
            welcomeOperationUuid: $challenge->welcome_operation_uuid,
            challengeUuid: $challenge->challenge_uuid,
            challengeGeneration: (int) $challenge->challenge_generation,
            groupIdHashHexOrBin: $challenge->group_id_hash,
            epoch: 1,
            challengeNonceHexOrBin: $challenge->challenge_nonce
        );

        $sigBin = sodium_crypto_sign_detached($canonicalPayload, $secretKey);

        return base64_encode($sigBin);
    }

    // =========================================================================
    // 1. SKENARIO SUKSES
    // =========================================================================

    /**
     * 1. Welcome ACK berhasil mengaktifkan kedua anggota pairwise dan room ke epoch 1 secara atomik.
     */
    public function test_01_welcome_ack_successful_activates_both_members_and_room_at_epoch_1(): void
    {
        Event::fake([BchatRoomActivated::class]);

        $initiator = $this->createTestUser('mahasiswa');
        $recipient = $this->createTestUser('dosen');
        $deviceData = $this->createTestDevice($recipient);
        $room = $this->createTestRoomWithMembers($initiator, $recipient);
        $challenge = $this->createTestChallenge($room, $recipient, $deviceData['device']);

        $signatureBase64 = $this->generateValidSignature($room, $recipient, $challenge, $deviceData['secretKey']);

        $result = $this->service->processWelcomeAck(
            recipientUser: $recipient,
            roomUuid: $room->room_uuid,
            payloadData: [
                'challenge_uuid' => $challenge->challenge_uuid,
                'signature'      => $signatureBase64,
            ],
            ipAddress: '127.0.0.1',
            userAgent: 'PHPUnit Test Agent'
        );

        // Verifikasi struktur respons sukses
        $this->assertSame('success', $result['status']);
        $this->assertSame('ACTIVE', $result['room_status']);
        $this->assertSame('1', $result['current_epoch']);
        $this->assertSame($room->room_uuid, $result['room_uuid']);
        $this->assertSame($challenge->welcome_operation_uuid, $result['welcome_operation_uuid']);
        $this->assertSame($challenge->challenge_uuid, $result['challenge_uuid']);
        $this->assertNotEmpty($result['ack_payload_hash']);

        // Verifikasi mutasi basis data room
        $room->refresh();
        $this->assertSame('ACTIVE', $room->status);
        $this->assertSame(1, (int) $room->current_epoch);

        // Verifikasi mutasi anggota penerima (recipient)
        $recipMember = BchatRoomMember::where('room_id', $room->id)->where('user_id', $recipient->id)->first();
        $this->assertTrue((bool) $recipMember->is_active);
        $this->assertSame(1, (int) $recipMember->joined_at_epoch);
        $this->assertNotNull($recipMember->welcome_ack_at);

        // Verifikasi mutasi anggota inisiator (initiator)
        $initMember = BchatRoomMember::where('room_id', $room->id)->where('user_id', $initiator->id)->first();
        $this->assertTrue((bool) $initMember->is_active);
        $this->assertSame(1, (int) $initMember->joined_at_epoch);

        // Verifikasi mutasi tantangan (challenge)
        $challenge->refresh();
        $this->assertSame('CONSUMED', $challenge->operation_status);
        $this->assertSame('CONSUMED', $challenge->challenge_status);
        $this->assertNotNull($challenge->consumed_at);
        $this->assertSame($signatureBase64, $challenge->ack_signature);
        $this->assertSame('127.0.0.1', $challenge->ip_address);
        $this->assertSame('PHPUnit Test Agent', $challenge->user_agent);

        // Verifikasi event siaran after-commit terkirim
        Event::assertDispatched(BchatRoomActivated::class, function (BchatRoomActivated $event) use ($room) {
            return $event->roomUuid === $room->room_uuid
                && $event->currentEpoch === 1
                && $event->roomStatus === 'ACTIVE'
                && $event->afterCommit === true;
        });
    }

    // =========================================================================
    // 2. SKENARIO PENOLAKAN STATUS & ATRIBUT ROOM
    // =========================================================================

    /**
     * 2. Menolak ACK jika status room bukan PENDING_WELCOME_ACK.
     */
    public function test_02_welcome_ack_rejects_non_pending_room(): void
    {
        $initiator = $this->createTestUser('mahasiswa');
        $recipient = $this->createTestUser('dosen');
        $deviceData = $this->createTestDevice($recipient);
        $room = $this->createTestRoomWithMembers($initiator, $recipient, roomStatus: 'ACTIVE');
        $challenge = $this->createTestChallenge($room, $recipient, $deviceData['device']);

        $signatureBase64 = $this->generateValidSignature($room, $recipient, $challenge, $deviceData['secretKey']);

        $this->expectException(BchatWelcomeException::class);

        try {
            $this->service->processWelcomeAck(
                recipientUser: $recipient,
                roomUuid: $room->room_uuid,
                payloadData: [
                    'challenge_uuid' => $challenge->challenge_uuid,
                    'signature'      => $signatureBase64,
                ]
            );
        } catch (BchatWelcomeException $e) {
            $this->assertSame('INVALID_ROOM_STATUS', $e->getErrorCode());
            $this->assertSame(409, $e->getHttpStatus());
            throw $e;
        }
    }

    /**
     * 3. Menolak ACK jika current_epoch room bukan 0.
     */
    public function test_03_welcome_ack_rejects_non_zero_epoch(): void
    {
        $initiator = $this->createTestUser('mahasiswa');
        $recipient = $this->createTestUser('dosen');
        $deviceData = $this->createTestDevice($recipient);
        $room = $this->createTestRoomWithMembers($initiator, $recipient, epoch: 1);
        $challenge = $this->createTestChallenge($room, $recipient, $deviceData['device']);

        $signatureBase64 = $this->generateValidSignature($room, $recipient, $challenge, $deviceData['secretKey']);

        $this->expectException(BchatWelcomeException::class);

        try {
            $this->service->processWelcomeAck(
                recipientUser: $recipient,
                roomUuid: $room->room_uuid,
                payloadData: [
                    'challenge_uuid' => $challenge->challenge_uuid,
                    'signature'      => $signatureBase64,
                ]
            );
        } catch (BchatWelcomeException $e) {
            $this->assertSame('INVALID_ROOM_EPOCH', $e->getErrorCode());
            $this->assertSame(409, $e->getHttpStatus());
            throw $e;
        }
    }

    // =========================================================================
    // 3. SKENARIO PENOLAKAN PENUGASAN AKADEMIK
    // =========================================================================

    /**
     * 4. Menolak ACK jika penugasan akademik pembimbing tidak berstatus AKTIF.
     */
    public function test_04_welcome_ack_rejects_when_assignment_is_inactive(): void
    {
        $initiator = $this->createTestUser('mahasiswa');
        $recipient = $this->createTestUser('dosen');
        $deviceData = $this->createTestDevice($recipient);
        $room = $this->createTestRoomWithMembers($initiator, $recipient, assignmentStatus: 'DIBATALKAN');
        $challenge = $this->createTestChallenge($room, $recipient, $deviceData['device']);

        $signatureBase64 = $this->generateValidSignature($room, $recipient, $challenge, $deviceData['secretKey']);

        $this->expectException(BchatWelcomeException::class);

        try {
            $this->service->processWelcomeAck(
                recipientUser: $recipient,
                roomUuid: $room->room_uuid,
                payloadData: [
                    'challenge_uuid' => $challenge->challenge_uuid,
                    'signature'      => $signatureBase64,
                ]
            );
        } catch (BchatWelcomeException $e) {
            $this->assertSame('ASSIGNMENT_INACTIVE', $e->getErrorCode());
            $this->assertSame(403, $e->getHttpStatus());
            throw $e;
        }
    }

    // =========================================================================
    // 4. SKENARIO PENOLAKAN PERAN & IDENTITAS PEMOHON
    // =========================================================================

    /**
     * 5. Menolak ACK jika pemohon adalah inisiator room (bukan recipient).
     */
    public function test_05_welcome_ack_rejects_initiator_as_ack_submitter(): void
    {
        $initiator = $this->createTestUser('mahasiswa');
        $recipient = $this->createTestUser('dosen');
        $deviceData = $this->createTestDevice($recipient);
        $room = $this->createTestRoomWithMembers($initiator, $recipient);
        $challenge = $this->createTestChallenge($room, $recipient, $deviceData['device']);

        $signatureBase64 = $this->generateValidSignature($room, $recipient, $challenge, $deviceData['secretKey']);

        $this->expectException(BchatWelcomeException::class);

        try {
            $this->service->processWelcomeAck(
                recipientUser: $initiator, // Inisiator mencoba mengirim ACK
                roomUuid: $room->room_uuid,
                payloadData: [
                    'challenge_uuid' => $challenge->challenge_uuid,
                    'signature'      => $signatureBase64,
                ]
            );
        } catch (BchatWelcomeException $e) {
            $this->assertSame('INITIATOR_NOT_ALLOWED_ACK', $e->getErrorCode());
            $this->assertSame(403, $e->getHttpStatus());
            throw $e;
        }
    }

    /**
     * 6. Menolak ACK jika pemohon bukan anggota room.
     */
    public function test_06_welcome_ack_rejects_unauthorized_user(): void
    {
        $initiator = $this->createTestUser('mahasiswa');
        $recipient = $this->createTestUser('dosen');
        $stranger = $this->createTestUser('dosen');
        $deviceData = $this->createTestDevice($recipient);
        $room = $this->createTestRoomWithMembers($initiator, $recipient);
        $challenge = $this->createTestChallenge($room, $recipient, $deviceData['device']);

        $signatureBase64 = $this->generateValidSignature($room, $recipient, $challenge, $deviceData['secretKey']);

        $this->expectException(BchatWelcomeException::class);

        try {
            $this->service->processWelcomeAck(
                recipientUser: $stranger, // User luar
                roomUuid: $room->room_uuid,
                payloadData: [
                    'challenge_uuid' => $challenge->challenge_uuid,
                    'signature'      => $signatureBase64,
                ]
            );
        } catch (BchatWelcomeException $e) {
            $this->assertSame('UNAUTHORIZED_NOT_MEMBER', $e->getErrorCode());
            $this->assertSame(403, $e->getHttpStatus());
            throw $e;
        }
    }

    // =========================================================================
    // 5. SKENARIO PENOLAKAN TANTANGAN (CHALLENGE)
    // =========================================================================

    /**
     * 7. Menolak ACK jika challenge UUID yang dikirim klien tidak cocok dengan tantangan aktif.
     */
    public function test_07_welcome_ack_rejects_mismatched_challenge_uuid(): void
    {
        $initiator = $this->createTestUser('mahasiswa');
        $recipient = $this->createTestUser('dosen');
        $deviceData = $this->createTestDevice($recipient);
        $room = $this->createTestRoomWithMembers($initiator, $recipient);
        $challenge = $this->createTestChallenge($room, $recipient, $deviceData['device']);

        $signatureBase64 = $this->generateValidSignature($room, $recipient, $challenge, $deviceData['secretKey']);

        $this->expectException(BchatWelcomeException::class);

        try {
            $this->service->processWelcomeAck(
                recipientUser: $recipient,
                roomUuid: $room->room_uuid,
                payloadData: [
                    'challenge_uuid' => (string) Str::uuid(), // UUID acak tidak cocok
                    'signature'      => $signatureBase64,
                ]
            );
        } catch (BchatWelcomeException $e) {
            $this->assertSame('CHALLENGE_UUID_MISMATCH', $e->getErrorCode());
            $this->assertSame(409, $e->getHttpStatus());
            throw $e;
        }
    }

    /**
     * 8. Menolak ACK jika tantangan telah kedaluwarsa.
     */
    public function test_08_welcome_ack_rejects_expired_challenge(): void
    {
        $initiator = $this->createTestUser('mahasiswa');
        $recipient = $this->createTestUser('dosen');
        $deviceData = $this->createTestDevice($recipient);
        $room = $this->createTestRoomWithMembers($initiator, $recipient);
        $challenge = $this->createTestChallenge($room, $recipient, $deviceData['device'], expiresAt: now()->subMinute());

        $signatureBase64 = $this->generateValidSignature($room, $recipient, $challenge, $deviceData['secretKey']);

        $this->expectException(BchatWelcomeException::class);

        try {
            $this->service->processWelcomeAck(
                recipientUser: $recipient,
                roomUuid: $room->room_uuid,
                payloadData: [
                    'challenge_uuid' => $challenge->challenge_uuid,
                    'signature'      => $signatureBase64,
                ]
            );
        } catch (BchatWelcomeException $e) {
            $this->assertSame('CHALLENGE_EXPIRED', $e->getErrorCode());
            $this->assertSame(409, $e->getHttpStatus());
            throw $e;
        }
    }

    /**
     * 9. Menolak ACK jika status tantangan bukan ISSUED (misal sudah CONSUMED).
     */
    public function test_09_welcome_ack_rejects_already_consumed_challenge(): void
    {
        $initiator = $this->createTestUser('mahasiswa');
        $recipient = $this->createTestUser('dosen');
        $deviceData = $this->createTestDevice($recipient);
        $room = $this->createTestRoomWithMembers($initiator, $recipient);
        $challenge = $this->createTestChallenge($room, $recipient, $deviceData['device'], challengeStatus: 'CONSUMED');

        $signatureBase64 = $this->generateValidSignature($room, $recipient, $challenge, $deviceData['secretKey']);

        $this->expectException(BchatWelcomeException::class);

        try {
            $this->service->processWelcomeAck(
                recipientUser: $recipient,
                roomUuid: $room->room_uuid,
                payloadData: [
                    'challenge_uuid' => $challenge->challenge_uuid,
                    'signature'      => $signatureBase64,
                ]
            );
        } catch (BchatWelcomeException $e) {
            $this->assertSame('CHALLENGE_NOT_ISSUED', $e->getErrorCode());
            $this->assertSame(409, $e->getHttpStatus());
            throw $e;
        }
    }

    /**
     * 10. Menolak ACK jika perangkat penerima telah dicabut (REVOKED).
     */
    public function test_10_welcome_ack_rejects_revoked_recipient_device(): void
    {
        $initiator = $this->createTestUser('mahasiswa');
        $recipient = $this->createTestUser('dosen');
        $deviceData = $this->createTestDevice($recipient, status: 'REVOKED');
        $room = $this->createTestRoomWithMembers($initiator, $recipient);
        $challenge = $this->createTestChallenge($room, $recipient, $deviceData['device']);

        $signatureBase64 = $this->generateValidSignature($room, $recipient, $challenge, $deviceData['secretKey']);

        $this->expectException(BchatWelcomeException::class);

        try {
            $this->service->processWelcomeAck(
                recipientUser: $recipient,
                roomUuid: $room->room_uuid,
                payloadData: [
                    'challenge_uuid' => $challenge->challenge_uuid,
                    'signature'      => $signatureBase64,
                ]
            );
        } catch (BchatWelcomeException $e) {
            $this->assertSame('RECIPIENT_DEVICE_NOT_ACTIVE', $e->getErrorCode());
            $this->assertSame(422, $e->getHttpStatus());
            throw $e;
        }
    }

    // =========================================================================
    // 6. SKENARIO KRIPTOGRAFIS & VALIDASI FORMAT SIGNATURE
    // =========================================================================

    /**
     * 11. Menolak ACK jika signature Ed25519 tidak valid (kriptografi gagal).
     */
    public function test_11_welcome_ack_rejects_invalid_ed25519_signature(): void
    {
        $initiator = $this->createTestUser('mahasiswa');
        $recipient = $this->createTestUser('dosen');
        $deviceData = $this->createTestDevice($recipient);
        $room = $this->createTestRoomWithMembers($initiator, $recipient);
        $challenge = $this->createTestChallenge($room, $recipient, $deviceData['device']);

        // Bangkitkan keypair lain yang tidak terdaftar di perangkat
        $otherKeypair = sodium_crypto_sign_keypair();
        $otherSecretKey = sodium_crypto_sign_secretkey($otherKeypair);
        $invalidSigBase64 = $this->generateValidSignature($room, $recipient, $challenge, $otherSecretKey);

        $this->expectException(BchatWelcomeException::class);

        try {
            $this->service->processWelcomeAck(
                recipientUser: $recipient,
                roomUuid: $room->room_uuid,
                payloadData: [
                    'challenge_uuid' => $challenge->challenge_uuid,
                    'signature'      => $invalidSigBase64,
                ]
            );
        } catch (BchatWelcomeException $e) {
            $this->assertSame('SIGNATURE_VERIFICATION_FAILED', $e->getErrorCode());
            $this->assertSame(422, $e->getHttpStatus());
            throw $e;
        }
    }

    /**
     * 12. Menolak ACK jika format string signature bukan Base64 RFC 4648 kanonikal 64 byte.
     */
    public function test_12_welcome_ack_rejects_invalid_base64_format(): void
    {
        $initiator = $this->createTestUser('mahasiswa');
        $recipient = $this->createTestUser('dosen');
        $deviceData = $this->createTestDevice($recipient);
        $room = $this->createTestRoomWithMembers($initiator, $recipient);
        $challenge = $this->createTestChallenge($room, $recipient, $deviceData['device']);

        $this->expectException(BchatWelcomeException::class);

        try {
            $this->service->processWelcomeAck(
                recipientUser: $recipient,
                roomUuid: $room->room_uuid,
                payloadData: [
                    'challenge_uuid' => $challenge->challenge_uuid,
                    'signature'      => 'not_a_valid_base64_signature_string',
                ]
            );
        } catch (BchatWelcomeException $e) {
            $this->assertSame('INVALID_SIGNATURE_FORMAT', $e->getErrorCode());
            $this->assertSame(422, $e->getHttpStatus());
            throw $e;
        }
    }

    // =========================================================================
    // 7. SKENARIO INTEGRITAS TRANSAKSI, ATOMISITAS & REPLAY PROTECTION
    // =========================================================================

    /**
     * 13. Replay protection: Mengirimkan ulang ACK yang sama setelah sukses ditolak dengan HTTP 409.
     */
    public function test_13_replay_welcome_ack_rejected(): void
    {
        $initiator = $this->createTestUser('mahasiswa');
        $recipient = $this->createTestUser('dosen');
        $deviceData = $this->createTestDevice($recipient);
        $room = $this->createTestRoomWithMembers($initiator, $recipient);
        $challenge = $this->createTestChallenge($room, $recipient, $deviceData['device']);

        $signatureBase64 = $this->generateValidSignature($room, $recipient, $challenge, $deviceData['secretKey']);

        // Pemanggilan pertama sukses
        $this->service->processWelcomeAck(
            recipientUser: $recipient,
            roomUuid: $room->room_uuid,
            payloadData: [
                'challenge_uuid' => $challenge->challenge_uuid,
                'signature'      => $signatureBase64,
            ]
        );

        // Pemanggilan kedua dengan payload persis yang sama wajib ditolak
        $this->expectException(BchatWelcomeException::class);

        try {
            $this->service->processWelcomeAck(
                recipientUser: $recipient,
                roomUuid: $room->room_uuid,
                payloadData: [
                    'challenge_uuid' => $challenge->challenge_uuid,
                    'signature'      => $signatureBase64,
                ]
            );
        } catch (BchatWelcomeException $e) {
            // Room sudah ACTIVE atau challenge sudah tidak berstatus ISSUED
            $this->assertContains($e->getErrorCode(), ['INVALID_ROOM_STATUS', 'NO_ACTIVE_WELCOME_OPERATION']);
            $this->assertSame(409, $e->getHttpStatus());
            throw $e;
        }
    }

    /**
     * 14. Atomisitas & Rollback: Kegagalan verifikasi menjamin nol perubahan parsial.
     */
    public function test_14_verification_failure_leaves_zero_partial_mutations(): void
    {
        $initiator = $this->createTestUser('mahasiswa');
        $recipient = $this->createTestUser('dosen');
        $deviceData = $this->createTestDevice($recipient);
        $room = $this->createTestRoomWithMembers($initiator, $recipient);
        $challenge = $this->createTestChallenge($room, $recipient, $deviceData['device']);

        // Signature palsu
        $corruptSignature = base64_encode(random_bytes(64));

        try {
            $this->service->processWelcomeAck(
                recipientUser: $recipient,
                roomUuid: $room->room_uuid,
                payloadData: [
                    'challenge_uuid' => $challenge->challenge_uuid,
                    'signature'      => $corruptSignature,
                ]
            );
            $this->fail('Harus gagal karena signature tidak valid.');
        } catch (BchatWelcomeException $e) {
            $this->assertSame('SIGNATURE_VERIFICATION_FAILED', $e->getErrorCode());
        }

        // Pastikan room tetap PENDING_WELCOME_ACK epoch 0
        $room->refresh();
        $this->assertSame('PENDING_WELCOME_ACK', $room->status);
        $this->assertSame(0, (int) $room->current_epoch);

        // Pastikan kedua anggota tetap tidak aktif
        $recipMember = BchatRoomMember::where('room_id', $room->id)->where('user_id', $recipient->id)->first();
        $this->assertFalse((bool) $recipMember->is_active);
        $this->assertNull($recipMember->welcome_ack_at);

        $initMember = BchatRoomMember::where('room_id', $room->id)->where('user_id', $initiator->id)->first();
        $this->assertFalse((bool) $initMember->is_active);

        // Pastikan challenge tetap berstatus ISSUED
        $challenge->refresh();
        $this->assertSame('ISSUED', $challenge->challenge_status);
        $this->assertSame('ACTIVE', $challenge->operation_status);
        $this->assertNull($challenge->consumed_at);
    }
}

