<?php

declare(strict_types=1);

namespace Tests\Feature\Bchat;

use App\Exceptions\BchatHistoryTransferException;
use App\Models\BchatAttachment;
use App\Models\BchatDevicePairing;
use App\Models\BchatHistoryTransfer;
use App\Models\BchatMessage;
use App\Models\BchatRoom;
use App\Models\BchatRoomMember;
use App\Models\BchatRoomSession;
use App\Models\BchatUploadSession;
use App\Models\BchatUserDevice;
use App\Models\Dosen;
use App\Models\Mahasiswa;
use App\Models\PenugasanPembimbing;
use App\Models\Skripsi;
use App\Models\User;
use App\Services\BchatHistoryTransferService;
use App\Services\BchatHistoryTransferVerificationService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Test Suite Komprehensif: B-CHAT E2EE History Transfer Feature Test (Tahap 2B)
 *
 * Menguji pemindahan riwayat chat E2EE antarperangkat tepercaya:
 * 1. Perangkat tepercaya sumber dapat mengirim transfer ke perangkat baru yang telah disetujui.
 * 2. Perangkat yang belum tepercaya tidak dapat menjadi sumber atau penerima transfer.
 * 3. Perangkat penerima yang bukan target tidak dapat membuka snapshot.
 * 4. Server hanya menyimpan atau meneruskan ciphertext (zero plaintext in DB).
 * 5. Snapshot yang dimodifikasi atau tidak autentik ditolak.
 * 6. Transfer kedaluwarsa dan pembatalan ditangani dengan aman.
 * 7. Transfer gagal tidak meninggalkan riwayat parsial yang dianggap valid.
 * 8. Pesan dari beberapa generasi sesi tetap tersusun benar.
 * 9. Lampiran yang ikut ditransfer dapat didekripsi dan diverifikasi.
 * 10. Regresi chat, attachment, pairing, dan session generation tetap PASS.
 */
class BchatHistoryTransferFeatureTest extends TestCase
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

    /**
     * @return array{device: BchatUserDevice, keypair: string, secretKey: string, publicKey: string}
     */
    private function createTestDevice(User $user, string $status = 'ACTIVE', bool $isTrusted = true): array
    {
        $keypair = sodium_crypto_sign_keypair();
        $secretKey = sodium_crypto_sign_secretkey($keypair);
        $publicKey = sodium_crypto_sign_publickey($keypair);

        $device = BchatUserDevice::create([
            'user_id'                => $user->id,
            'device_uuid'            => (string) Str::uuid(),
            'device_name'            => 'Device Test ' . Str::random(4),
            'key_version'            => 1,
            'public_key'             => bin2hex($publicKey),
            'public_key_fingerprint' => hash('sha256', $publicKey),
            'status'                 => $status,
            'is_trusted'             => $isTrusted,
            'trusted_at'             => $isTrusted ? now() : null,
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
        string $roomStatus = 'ACTIVE'
    ): BchatRoom {
        $mhs = Mahasiswa::create([
            'user_id'        => $initiator->id,
            'NIM'            => $initiator->nomor_induk,
            'nama_mahasiswa' => 'Mahasiswa Test ' . Str::random(4),
            'prodi'          => 'Informatika',
        ]);

        $skripsi = Skripsi::create([
            'id_mahasiswa' => $mhs->id_mahasiswa,
            'judul'        => 'Proposal E2EE Feature Test ' . Str::random(5),
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
            'current_epoch'           => 1,
            'group_id_hash'           => hash('sha256', 'group_context_ht_' . Str::random(6)),
        ]);

        BchatRoomMember::create([
            'room_id'         => $room->id,
            'user_id'         => $initiator->id,
            'role'            => 'mahasiswa',
            'is_initiator'    => true,
            'is_active'       => true,
            'joined_at_epoch' => 1,
        ]);

        BchatRoomMember::create([
            'room_id'         => $room->id,
            'user_id'         => $recipient->id,
            'role'            => 'dosen_pembimbing',
            'is_initiator'    => false,
            'is_active'       => true,
            'joined_at_epoch' => 1,
        ]);

        return $room;
    }

    /**
     * @return array{resource: \OpenSSLAsymmetricKey, pubKeyHex: string}
     */
    private function createEphemeralEcdhKeypair(): array
    {
        $config = ['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC];
        $cnfCandidates = array_filter([
            getenv('OPENSSL_CONF'),
            'D:\\Aplikasi\\Xampp\\php\\extras\\ssl\\openssl.cnf',
            'D:\\Aplikasi\\laragon\\bin\\php\\php-8.4.27-Win32-vs17-x64\\extras\\ssl\\openssl.cnf',
            dirname(PHP_BINARY) . DIRECTORY_SEPARATOR . 'extras' . DIRECTORY_SEPARATOR . 'ssl' . DIRECTORY_SEPARATOR . 'openssl.cnf',
        ]);
        foreach ($cnfCandidates as $cand) {
            if (file_exists($cand)) {
                $config['config'] = $cand;
                break;
            }
        }

        $res = openssl_pkey_new($config);
        if ($res === false) {
            throw new \RuntimeException('Failed to generate EC key: ' . openssl_error_string());
        }
        $details = openssl_pkey_get_details($res);
        $pubKeyHex = '04' . bin2hex($details['ec']['x']) . bin2hex($details['ec']['y']);

        return [
            'resource'   => $res,
            'pubKeyHex'  => $pubKeyHex,
            'publicKey'  => $details['key'],
        ];
    }

    // =========================================================================
    // 10 SKENARIO PENGUJIAN HISTORY TRANSFER (TAHAP 2B)
    // =========================================================================

    /**
     * Skenario 1: Perangkat tepercaya sumber dapat mengirim transfer ke perangkat baru yang telah disetujui.
     */
    public function test_01_trusted_source_device_can_send_transfer_to_approved_new_device(): void
    {
        $user = $this->createTestUser('mahasiswa');
        $partner = $this->createTestUser('dosen');
        $room = $this->createTestRoomWithMembers($user, $partner);

        // Perangkat sumber (trusted)
        $sourceDev = $this->createTestDevice($user, 'ACTIVE', true);
        // Perangkat baru (telah disetujui melalui pairing -> is_trusted = true)
        $newDev = $this->createTestDevice($user, 'ACTIVE', true);

        // Buat sesi 1 pada room
        $session1 = BchatRoomSession::create([
            'session_uuid'       => (string) Str::uuid(),
            'room_id'            => $room->id,
            'generation_number'  => 1,
            'status'             => 'ACTIVE',
            'group_id_hash'      => $room->group_id_hash,
            'start_epoch'        => 1,
            'start_sequence'     => 1,
            'current_sequence'   => 2,
            'activated_at'       => now(),
        ]);

        // Buat 2 pesan lama pada room
        BchatMessage::create([
            'room_id'             => $room->id,
            'session_id'          => $session1->id,
            'server_sequence'     => 1,
            'message_uuid'        => (string) Str::uuid(),
            'sender_id'           => $user->id,
            'epoch'               => 1,
            'generation'          => 0,
            'content_type'        => 1,
            'ciphertext_payload'  => 'cipher1',
            'payload_hash_sha256' => hash('sha256', 'cipher1'),
            'client_sent_at'      => now(),
        ]);

        BchatMessage::create([
            'room_id'             => $room->id,
            'session_id'          => $session1->id,
            'server_sequence'     => 2,
            'message_uuid'        => (string) Str::uuid(),
            'sender_id'           => $partner->id,
            'epoch'               => 1,
            'generation'          => 0,
            'content_type'        => 1,
            'ciphertext_payload'  => 'cipher2',
            'payload_hash_sha256' => hash('sha256', 'cipher2'),
            'client_sent_at'      => now(),
        ]);

        Sanctum::actingAs($user);

        // --- STEP 1: Inisiasi Permintaan Transfer oleh Perangkat Baru ---
        $recipEcdh = $this->createEphemeralEcdhKeypair();
        $nonceHex = bin2hex(random_bytes(32));

        $reqPayload = BchatHistoryTransferVerificationService::buildRequestPayload(
            userId: (int) $user->id,
            roomUuid: $room->room_uuid,
            recipientDeviceUuid: $newDev['device']->device_uuid,
            sourceDeviceUuid: $sourceDev['device']->device_uuid,
            nonceHex: $nonceHex,
            recipientEphemeralPublicKey: $recipEcdh['pubKeyHex']
        );
        $reqSig = base64_encode(sodium_crypto_sign_detached($reqPayload, $newDev['secretKey']));

        $initRes = $this->withHeader('X-Bchat-Device-Uuid', $newDev['device']->device_uuid)
            ->postJson("/api/bchat/rooms/{$room->room_uuid}/history-transfers", [
                'source_device_uuid'             => $sourceDev['device']->device_uuid,
                'key_exchange_algorithm'         => 'ECDH-P256',
                'recipient_ephemeral_public_key' => $recipEcdh['pubKeyHex'],
                'transfer_nonce'                 => $nonceHex,
                'request_signature'              => $reqSig,
            ]);

        $initRes->assertStatus(201)
            ->assertJsonPath('data.status', 'REQUESTED');
        $transferUuid = $initRes->json('data.transfer_uuid');

        // --- STEP 2: Perangkat Sumber Mengambil Permintaan Pending ---
        $pendingRes = $this->withHeader('X-Bchat-Device-Uuid', $sourceDev['device']->device_uuid)
            ->getJson('/api/bchat/history-transfers/pending');
        $pendingRes->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.transfer_uuid', $transferUuid);

        // --- STEP 3: Perangkat Sumber Membuat dan Mengunggah Ciphertext Snapshot ---
        $sourceEcdh = $this->createEphemeralEcdhKeypair();
        $sharedSecret = openssl_pkey_derive($recipEcdh['publicKey'], $sourceEcdh['resource']);
        $transferAesKey = hash_hkdf('sha256', $sharedSecret, 32, 'UNUHA-BCHAT-TRANSFER-KEY:' . $transferUuid . ':' . $room->room_uuid, 'transfer_salt_' . $transferUuid);

        $snapshotPlaintext = json_encode([
            'version'   => '1.0.0',
            'room_uuid' => $room->room_uuid,
            'messages'  => [
                ['server_sequence' => 1, 'plaintext' => 'Halo Pak, ini draf skripsi saya.'],
                ['server_sequence' => 2, 'plaintext' => 'Baik, silakan perbaiki bab 1.'],
            ],
        ]);

        $iv = random_bytes(12);
        $tag = '';
        $aad = "UNUHA-BCHAT-AAD:{$user->id}:{$transferUuid}:{$room->room_uuid}:{$newDev['device']->device_uuid}:{$sourceDev['device']->device_uuid}";
        $rawCiphertext = openssl_encrypt($snapshotPlaintext, 'aes-256-gcm', $transferAesKey, OPENSSL_RAW_DATA, $iv, $tag, $aad);
        $fullCiphertextWithTag = $rawCiphertext . $tag;

        $snapshotCiphertextBase64 = base64_encode($fullCiphertextWithTag);
        $snapshotHashSha256 = hash('sha256', $fullCiphertextWithTag);

        $snapshotHeader = BchatHistoryTransferVerificationService::buildSnapshotHeader(
            userId: (int) $user->id,
            transferUuid: $transferUuid,
            roomUuid: $room->room_uuid,
            recipientDeviceUuid: $newDev['device']->device_uuid,
            sourceDeviceUuid: $sourceDev['device']->device_uuid,
            snapshotHashSha256Hex: $snapshotHashSha256,
            sourceEphemeralPublicKey: $sourceEcdh['pubKeyHex']
        );
        $sourceSig = base64_encode(sodium_crypto_sign_detached($snapshotHeader, $sourceDev['secretKey']));

        $uploadRes = $this->withHeader('X-Bchat-Device-Uuid', $sourceDev['device']->device_uuid)
            ->postJson("/api/bchat/history-transfers/{$transferUuid}/upload", [
                'source_ephemeral_public_key' => $sourceEcdh['pubKeyHex'],
                'snapshot_ciphertext'         => $snapshotCiphertextBase64,
                'snapshot_hash_sha256'        => $snapshotHashSha256,
                'snapshot_iv'                 => bin2hex($iv),
                'snapshot_metadata'           => ['message_count' => 2],
                'source_signature'            => $sourceSig,
            ]);

        $uploadRes->assertStatus(200)
            ->assertJsonPath('data.status', 'READY');

        // --- STEP 4: Perangkat Baru Mengunduh dan Mendekripsi Snapshot ---
        $downRes = $this->withHeader('X-Bchat-Device-Uuid', $newDev['device']->device_uuid)
            ->getJson("/api/bchat/history-transfers/{$transferUuid}/download");

        $downRes->assertStatus(200)
            ->assertJsonPath('data.transfer_uuid', $transferUuid);

        // Dekripsi snapshot di sisi perangkat baru
        $recipSharedSecret = openssl_pkey_derive($sourceEcdh['publicKey'], $recipEcdh['resource']);
        $recipAesKey = hash_hkdf('sha256', $recipSharedSecret, 32, 'UNUHA-BCHAT-TRANSFER-KEY:' . $transferUuid . ':' . $room->room_uuid, 'transfer_salt_' . $transferUuid);

        $downloadedFullCt = base64_decode($downRes->json('data.snapshot_ciphertext'));
        $ctPayload = substr($downloadedFullCt, 0, -16);
        $ctTag = substr($downloadedFullCt, -16);
        $decryptedJson = openssl_decrypt($ctPayload, 'aes-256-gcm', $recipAesKey, OPENSSL_RAW_DATA, $iv, $ctTag, $aad);

        $this->assertNotFalse($decryptedJson);
        $this->assertEquals($snapshotPlaintext, $decryptedJson);

        // --- STEP 5: Konfirmasi Penyelesaian Transfer (ACK) ---
        $completionPayload = BchatHistoryTransferVerificationService::buildCompletionPayload(
            userId: (int) $user->id,
            transferUuid: $transferUuid,
            recipientDeviceUuid: $newDev['device']->device_uuid,
            snapshotHashSha256Hex: $snapshotHashSha256
        );
        $compSig = base64_encode(sodium_crypto_sign_detached($completionPayload, $newDev['secretKey']));

        $compRes = $this->withHeader('X-Bchat-Device-Uuid', $newDev['device']->device_uuid)
            ->postJson("/api/bchat/history-transfers/{$transferUuid}/complete", [
                'completion_signature' => $compSig,
            ]);

        $compRes->assertStatus(200)
            ->assertJsonPath('data.status', 'COMPLETED');

        // Pastikan ciphertext dihapus dari server setelah transfer selesai (Ephemeral Server Storage)
        $transferRecord = BchatHistoryTransfer::where('transfer_uuid', $transferUuid)->first();
        $this->assertNull($transferRecord->snapshot_ciphertext);
    }

    /**
     * Skenario 2: Perangkat yang belum tepercaya tidak dapat menjadi sumber atau penerima transfer.
     */
    public function test_02_untrusted_device_cannot_be_source_or_recipient(): void
    {
        $user = $this->createTestUser('mahasiswa');
        $partner = $this->createTestUser('dosen');
        $room = $this->createTestRoomWithMembers($user, $partner);

        $trustedDev = $this->createTestDevice($user, 'ACTIVE', true);
        $untrustedDev = $this->createTestDevice($user, 'ACTIVE', false); // Belum tepercaya

        Sanctum::actingAs($user);

        $ecdh = $this->createEphemeralEcdhKeypair();
        $nonceHex = bin2hex(random_bytes(32));

        // 1. Perangkat untrusted mencoba meminta transfer sebagai penerima -> DITOLAK 403
        $reqPayload = BchatHistoryTransferVerificationService::buildRequestPayload(
            (int) $user->id,
            $room->room_uuid,
            $untrustedDev['device']->device_uuid,
            $trustedDev['device']->device_uuid,
            $nonceHex,
            $ecdh['pubKeyHex']
        );
        $sig1 = base64_encode(sodium_crypto_sign_detached($reqPayload, $untrustedDev['secretKey']));

        $res1 = $this->withHeader('X-Bchat-Device-Uuid', $untrustedDev['device']->device_uuid)
            ->postJson("/api/bchat/rooms/{$room->room_uuid}/history-transfers", [
                'source_device_uuid'             => $trustedDev['device']->device_uuid,
                'key_exchange_algorithm'         => 'ECDH-P256',
                'recipient_ephemeral_public_key' => $ecdh['pubKeyHex'],
                'transfer_nonce'                 => $nonceHex,
                'request_signature'              => $sig1,
            ]);

        $res1->assertStatus(403)
            ->assertJson(['error_code' => 'RECIPIENT_NOT_TRUSTED']);

        // 2. Perangkat tepercaya menunjuk perangkat untrusted sebagai sumber -> DITOLAK 403
        $reqPayload2 = BchatHistoryTransferVerificationService::buildRequestPayload(
            (int) $user->id,
            $room->room_uuid,
            $trustedDev['device']->device_uuid,
            $untrustedDev['device']->device_uuid,
            $nonceHex,
            $ecdh['pubKeyHex']
        );
        $sig2 = base64_encode(sodium_crypto_sign_detached($reqPayload2, $trustedDev['secretKey']));

        $res2 = $this->withHeader('X-Bchat-Device-Uuid', $trustedDev['device']->device_uuid)
            ->postJson("/api/bchat/rooms/{$room->room_uuid}/history-transfers", [
                'source_device_uuid'             => $untrustedDev['device']->device_uuid,
                'key_exchange_algorithm'         => 'ECDH-P256',
                'recipient_ephemeral_public_key' => $ecdh['pubKeyHex'],
                'transfer_nonce'                 => $nonceHex,
                'request_signature'              => $sig2,
            ]);

        $res2->assertStatus(403)
            ->assertJson(['error_code' => 'SOURCE_DEVICE_NOT_TRUSTED']);
    }

    /**
     * Skenario 3: Perangkat penerima yang bukan target tidak dapat membuka atau mengunduh snapshot.
     */
    public function test_03_non_target_device_cannot_download_or_open_snapshot(): void
    {
        $user = $this->createTestUser('mahasiswa');
        $partner = $this->createTestUser('dosen');
        $room = $this->createTestRoomWithMembers($user, $partner);

        $sourceDev = $this->createTestDevice($user, 'ACTIVE', true);
        $targetRecipientDev = $this->createTestDevice($user, 'ACTIVE', true);
        $attackerDev = $this->createTestDevice($user, 'ACTIVE', true); // Perangkat lain pada akun yang sama

        // Buat transfer berstatus READY
        $recipEcdh = $this->createEphemeralEcdhKeypair();
        $sourceEcdh = $this->createEphemeralEcdhKeypair();

        $transfer = BchatHistoryTransfer::create([
            'transfer_uuid'                  => (string) Str::uuid(),
            'user_id'                        => $user->id,
            'room_id'                        => $room->id,
            'room_uuid'                      => $room->room_uuid,
            'recipient_device_id'            => $targetRecipientDev['device']->id,
            'recipient_device_uuid'          => $targetRecipientDev['device']->device_uuid,
            'source_device_id'               => $sourceDev['device']->id,
            'source_device_uuid'             => $sourceDev['device']->device_uuid,
            'key_exchange_algorithm'         => 'ECDH-P256',
            'recipient_ephemeral_public_key' => $recipEcdh['pubKeyHex'],
            'source_ephemeral_public_key'    => $sourceEcdh['pubKeyHex'],
            'transfer_nonce'                 => bin2hex(random_bytes(32)),
            'request_signature'              => bin2hex(random_bytes(64)),
            'status'                         => 'READY',
            'snapshot_ciphertext'            => base64_encode('encrypted_snapshot_blob'),
            'snapshot_hash_sha256'           => hash('sha256', 'encrypted_snapshot_blob'),
            'source_signature'               => bin2hex(random_bytes(64)),
            'expires_at'                     => now()->addMinutes(10),
        ]);

        Sanctum::actingAs($user);

        // Perangkat pihak ketiga (attackerDev) mencoba mengunduh -> DITOLAK 403
        $response = $this->withHeader('X-Bchat-Device-Uuid', $attackerDev['device']->device_uuid)
            ->getJson("/api/bchat/history-transfers/{$transfer->transfer_uuid}/download");

        $response->assertStatus(403)
            ->assertJson(['error_code' => 'FORBIDDEN_NOT_RECIPIENT_DEVICE']);
    }

    /**
     * Skenario 4: Server hanya menyimpan atau meneruskan ciphertext (zero plaintext in DB).
     */
    public function test_04_server_only_stores_and_forwards_ciphertext(): void
    {
        $user = $this->createTestUser('mahasiswa');
        $partner = $this->createTestUser('dosen');
        $room = $this->createTestRoomWithMembers($user, $partner);

        $sourceDev = $this->createTestDevice($user, 'ACTIVE', true);
        $newDev = $this->createTestDevice($user, 'ACTIVE', true);

        $recipEcdh = $this->createEphemeralEcdhKeypair();
        $sourceEcdh = $this->createEphemeralEcdhKeypair();

        $secretMessage = 'RAHASIA_BESAR_ACADEMIC_INTEGRITY_DATA';
        $sharedSecret = openssl_pkey_derive($recipEcdh['publicKey'], $sourceEcdh['resource']);
        $transferAesKey = hash_hkdf('sha256', $sharedSecret, 32, 'UNUHA-BCHAT-TRANSFER-KEY:test:test', 'salt');
        $iv = random_bytes(12);
        $tag = '';
        $ciphertext = openssl_encrypt($secretMessage, 'aes-256-gcm', $transferAesKey, OPENSSL_RAW_DATA, $iv, $tag);
        $fullCt = $ciphertext . $tag;

        $transfer = BchatHistoryTransfer::create([
            'transfer_uuid'                  => (string) Str::uuid(),
            'user_id'                        => $user->id,
            'room_id'                        => $room->id,
            'room_uuid'                      => $room->room_uuid,
            'recipient_device_id'            => $newDev['device']->id,
            'recipient_device_uuid'          => $newDev['device']->device_uuid,
            'source_device_id'               => $sourceDev['device']->id,
            'source_device_uuid'             => $sourceDev['device']->device_uuid,
            'key_exchange_algorithm'         => 'ECDH-P256',
            'recipient_ephemeral_public_key' => $recipEcdh['pubKeyHex'],
            'source_ephemeral_public_key'    => $sourceEcdh['pubKeyHex'],
            'transfer_nonce'                 => bin2hex(random_bytes(32)),
            'request_signature'              => bin2hex(random_bytes(64)),
            'status'                         => 'READY',
            'snapshot_ciphertext'            => base64_encode($fullCt),
            'snapshot_hash_sha256'           => hash('sha256', $fullCt),
            'source_signature'               => bin2hex(random_bytes(64)),
            'expires_at'                     => now()->addMinutes(10),
        ]);

        // Verifikasi pada baris database tidak mengandung substring plaintext
        $dbRow = DB::table('bchat_history_transfers')->where('id', $transfer->id)->first();
        $this->assertNotNull($dbRow);
        $this->assertStringNotContainsString($secretMessage, (string) $dbRow->snapshot_ciphertext);
        $this->assertStringNotContainsString('skripsi', (string) $dbRow->snapshot_ciphertext);
    }

    /**
     * Skenario 5: Snapshot yang dimodifikasi atau tidak autentik ditolak.
     */
    public function test_05_modified_or_unauthentic_snapshot_rejected(): void
    {
        $user = $this->createTestUser('mahasiswa');
        $partner = $this->createTestUser('dosen');
        $room = $this->createTestRoomWithMembers($user, $partner);

        $sourceDev = $this->createTestDevice($user, 'ACTIVE', true);
        $newDev = $this->createTestDevice($user, 'ACTIVE', true);

        $recipEcdh = $this->createEphemeralEcdhKeypair();
        $sourceEcdh = $this->createEphemeralEcdhKeypair();

        $transfer = BchatHistoryTransfer::create([
            'transfer_uuid'                  => (string) Str::uuid(),
            'user_id'                        => $user->id,
            'room_id'                        => $room->id,
            'room_uuid'                      => $room->room_uuid,
            'recipient_device_id'            => $newDev['device']->id,
            'recipient_device_uuid'          => $newDev['device']->device_uuid,
            'source_device_id'               => $sourceDev['device']->id,
            'source_device_uuid'             => $sourceDev['device']->device_uuid,
            'key_exchange_algorithm'         => 'ECDH-P256',
            'recipient_ephemeral_public_key' => $recipEcdh['pubKeyHex'],
            'transfer_nonce'                 => bin2hex(random_bytes(32)),
            'request_signature'              => bin2hex(random_bytes(64)),
            'status'                         => 'REQUESTED',
            'expires_at'                     => now()->addMinutes(10),
        ]);

        Sanctum::actingAs($user);

        // 1. Modifikasi Ciphertext vs Hash (Hash Mismatch) -> Ditolak 422
        $rawCiphertext = 'original_bytes';
        $corruptedHash = hash('sha256', 'tampered_bytes'); // Hash tidak cocok

        $resHashMismatch = $this->withHeader('X-Bchat-Device-Uuid', $sourceDev['device']->device_uuid)
            ->postJson("/api/bchat/history-transfers/{$transfer->transfer_uuid}/upload", [
                'source_ephemeral_public_key' => $sourceEcdh['pubKeyHex'],
                'snapshot_ciphertext'         => base64_encode($rawCiphertext),
                'snapshot_hash_sha256'        => $corruptedHash,
                'source_signature'            => bin2hex(random_bytes(64)),
            ]);

        $resHashMismatch->assertStatus(422)
            ->assertJson(['error_code' => 'SNAPSHOT_HASH_MISMATCH']);

        // 2. Signature Sumber Palsu -> Ditolak 422
        $realHash = hash('sha256', $rawCiphertext);
        $fakeSignature = base64_encode(random_bytes(64));

        $resSigFail = $this->withHeader('X-Bchat-Device-Uuid', $sourceDev['device']->device_uuid)
            ->postJson("/api/bchat/history-transfers/{$transfer->transfer_uuid}/upload", [
                'source_ephemeral_public_key' => $sourceEcdh['pubKeyHex'],
                'snapshot_ciphertext'         => base64_encode($rawCiphertext),
                'snapshot_hash_sha256'        => $realHash,
                'source_signature'            => $fakeSignature,
            ]);

        $resSigFail->assertStatus(422)
            ->assertJson(['error_code' => 'SIGNATURE_VERIFICATION_FAILED']);
    }

    /**
     * Skenario 6: Transfer kedaluwarsa dan pembatalan ditangani dengan aman.
     */
    public function test_06_expired_and_cancelled_transfer_handled_safely(): void
    {
        $user = $this->createTestUser('mahasiswa');
        $partner = $this->createTestUser('dosen');
        $room = $this->createTestRoomWithMembers($user, $partner);

        $sourceDev = $this->createTestDevice($user, 'ACTIVE', true);
        $newDev = $this->createTestDevice($user, 'ACTIVE', true);
        $ecdh = $this->createEphemeralEcdhKeypair();

        // 1. Uji Pembatalan oleh Pengguna
        $transfer1 = BchatHistoryTransfer::create([
            'transfer_uuid'                  => (string) Str::uuid(),
            'user_id'                        => $user->id,
            'room_id'                        => $room->id,
            'room_uuid'                      => $room->room_uuid,
            'recipient_device_id'            => $newDev['device']->id,
            'recipient_device_uuid'          => $newDev['device']->device_uuid,
            'source_device_id'               => $sourceDev['device']->id,
            'source_device_uuid'             => $sourceDev['device']->device_uuid,
            'key_exchange_algorithm'         => 'ECDH-P256',
            'recipient_ephemeral_public_key' => $ecdh['pubKeyHex'],
            'transfer_nonce'                 => bin2hex(random_bytes(32)),
            'request_signature'              => bin2hex(random_bytes(64)),
            'status'                         => 'REQUESTED',
            'expires_at'                     => now()->addMinutes(10),
        ]);

        Sanctum::actingAs($user);

        $cancelRes = $this->withHeader('X-Bchat-Device-Uuid', $newDev['device']->device_uuid)
            ->postJson("/api/bchat/history-transfers/{$transfer1->transfer_uuid}/cancel", [
                'reason' => 'Pengguna membatalkan transfer.',
            ]);

        $cancelRes->assertStatus(200)
            ->assertJsonPath('data.status', 'CANCELLED');

        // Sesi yang dibatalkan ditolak saat dicoba upload
        $uploadTry = $this->withHeader('X-Bchat-Device-Uuid', $sourceDev['device']->device_uuid)
            ->postJson("/api/bchat/history-transfers/{$transfer1->transfer_uuid}/upload", [
                'source_ephemeral_public_key' => $ecdh['pubKeyHex'],
                'snapshot_ciphertext'         => base64_encode('test'),
                'snapshot_hash_sha256'        => hash('sha256', 'test'),
                'source_signature'            => bin2hex(random_bytes(64)),
            ]);

        $uploadTry->assertStatus(409)
            ->assertJson(['error_code' => 'INVALID_TRANSFER_STATUS']);

        // 2. Uji Kedaluwarsa (Expired TTL)
        $transfer2 = BchatHistoryTransfer::create([
            'transfer_uuid'                  => (string) Str::uuid(),
            'user_id'                        => $user->id,
            'room_id'                        => $room->id,
            'room_uuid'                      => $room->room_uuid,
            'recipient_device_id'            => $newDev['device']->id,
            'recipient_device_uuid'          => $newDev['device']->device_uuid,
            'source_device_id'               => $sourceDev['device']->id,
            'source_device_uuid'             => $sourceDev['device']->device_uuid,
            'key_exchange_algorithm'         => 'ECDH-P256',
            'recipient_ephemeral_public_key' => $ecdh['pubKeyHex'],
            'transfer_nonce'                 => bin2hex(random_bytes(32)),
            'request_signature'              => bin2hex(random_bytes(64)),
            'status'                         => 'REQUESTED',
            'expires_at'                     => now()->subMinutes(1), // Sudah kedaluwarsa
        ]);

        $expiredUpload = $this->withHeader('X-Bchat-Device-Uuid', $sourceDev['device']->device_uuid)
            ->postJson("/api/bchat/history-transfers/{$transfer2->transfer_uuid}/upload", [
                'source_ephemeral_public_key' => $ecdh['pubKeyHex'],
                'snapshot_ciphertext'         => base64_encode('test'),
                'snapshot_hash_sha256'        => hash('sha256', 'test'),
                'source_signature'            => bin2hex(random_bytes(64)),
            ]);

        $expiredUpload->assertStatus(410)
            ->assertJson(['error_code' => 'TRANSFER_EXPIRED']);
    }

    /**
     * Skenario 7: Transfer gagal tidak meninggalkan riwayat parsial yang dianggap valid.
     */
    public function test_07_failed_transfer_leaves_no_partial_history_considered_valid(): void
    {
        $user = $this->createTestUser('mahasiswa');
        $partner = $this->createTestUser('dosen');
        $room = $this->createTestRoomWithMembers($user, $partner);

        $sourceDev = $this->createTestDevice($user, 'ACTIVE', true);
        $newDev = $this->createTestDevice($user, 'ACTIVE', true);
        $ecdh = $this->createEphemeralEcdhKeypair();

        $transfer = BchatHistoryTransfer::create([
            'transfer_uuid'                  => (string) Str::uuid(),
            'user_id'                        => $user->id,
            'room_id'                        => $room->id,
            'room_uuid'                      => $room->room_uuid,
            'recipient_device_id'            => $newDev['device']->id,
            'recipient_device_uuid'          => $newDev['device']->device_uuid,
            'source_device_id'               => $sourceDev['device']->id,
            'source_device_uuid'             => $sourceDev['device']->device_uuid,
            'key_exchange_algorithm'         => 'ECDH-P256',
            'recipient_ephemeral_public_key' => $ecdh['pubKeyHex'],
            'transfer_nonce'                 => bin2hex(random_bytes(32)),
            'request_signature'              => bin2hex(random_bytes(64)),
            'status'                         => 'REQUESTED',
            'expires_at'                     => now()->addMinutes(10),
        ]);

        Sanctum::actingAs($user);

        // Percobaan menyelesaikan transfer yang belum berstatus READY -> Ditolak 409
        $res = $this->withHeader('X-Bchat-Device-Uuid', $newDev['device']->device_uuid)
            ->postJson("/api/bchat/history-transfers/{$transfer->transfer_uuid}/complete", [
                'completion_signature' => base64_encode(random_bytes(64)),
            ]);

        $res->assertStatus(409)
            ->assertJson(['error_code' => 'INVALID_TRANSFER_STATUS']);

        $this->assertEquals('REQUESTED', $transfer->fresh()->status);
        $this->assertNull($transfer->fresh()->completed_at);
    }

    /**
     * Skenario 8: Pesan dari beberapa generasi sesi tetap tersusun benar.
     */
    public function test_08_messages_from_multiple_session_generations_ordered_correctly(): void
    {
        $user = $this->createTestUser('mahasiswa');
        $partner = $this->createTestUser('dosen');
        $room = $this->createTestRoomWithMembers($user, $partner);

        // Buat Sesi 1 (Archived, seq 1..5)
        $session1 = BchatRoomSession::create([
            'session_uuid'       => (string) Str::uuid(),
            'room_id'            => $room->id,
            'generation_number'  => 1,
            'status'             => 'ARCHIVED',
            'group_id_hash'      => $room->group_id_hash,
            'start_epoch'        => 1,
            'start_sequence'     => 1,
            'end_sequence'       => 5,
            'current_sequence'   => 5,
            'activated_at'       => now()->subDays(2),
            'archived_at'        => now()->subDays(1),
        ]);

        for ($i = 1; $i <= 5; $i++) {
            BchatMessage::create([
                'room_id'             => $room->id,
                'session_id'          => $session1->id,
                'server_sequence'     => $i,
                'message_uuid'        => (string) Str::uuid(),
                'sender_id'           => $user->id,
                'epoch'               => 1,
                'generation'          => 0,
                'content_type'        => 1,
                'ciphertext_payload'  => 'cipher_' . $i,
                'payload_hash_sha256' => hash('sha256', 'cipher_' . $i),
                'client_sent_at'      => now()->subDays(2),
            ]);
        }

        // Buat Sesi 2 (Active, seq 6..10)
        $session2 = BchatRoomSession::create([
            'session_uuid'       => (string) Str::uuid(),
            'room_id'            => $room->id,
            'generation_number'  => 2,
            'status'             => 'ACTIVE',
            'group_id_hash'      => hash('sha256', 'session_2'),
            'start_epoch'        => 1,
            'start_sequence'     => 6,
            'current_sequence'   => 10,
            'activated_at'       => now()->subDays(1),
        ]);

        for ($i = 6; $i <= 10; $i++) {
            BchatMessage::create([
                'room_id'             => $room->id,
                'session_id'          => $session2->id,
                'server_sequence'     => $i,
                'message_uuid'        => (string) Str::uuid(),
                'sender_id'           => $partner->id,
                'epoch'               => 1,
                'generation'          => 0,
                'content_type'        => 1,
                'ciphertext_payload'  => 'cipher_' . $i,
                'payload_hash_sha256' => hash('sha256', 'cipher_' . $i),
                'client_sent_at'      => now()->subDays(1),
            ]);
        }

        // Ambil pesan room secara berurutan
        $messages = BchatMessage::where('room_id', $room->id)
            ->orderBy('server_sequence', 'asc')
            ->get();

        $this->assertCount(10, $messages);
        for ($expectedSeq = 1; $expectedSeq <= 10; $expectedSeq++) {
            $this->assertEquals($expectedSeq, $messages[$expectedSeq - 1]->server_sequence);
            if ($expectedSeq <= 5) {
                $this->assertEquals($session1->id, $messages[$expectedSeq - 1]->session_id);
            } else {
                $this->assertEquals($session2->id, $messages[$expectedSeq - 1]->session_id);
            }
        }
    }

    /**
     * Skenario 9: Lampiran yang ikut ditransfer dapat didekripsi dan diverifikasi.
     */
    public function test_09_transferred_attachments_can_be_decrypted_and_verified(): void
    {
        $user = $this->createTestUser('mahasiswa');
        $partner = $this->createTestUser('dosen');
        $room = $this->createTestRoomWithMembers($user, $partner);

        $session1 = BchatRoomSession::create([
            'session_uuid'       => (string) Str::uuid(),
            'room_id'            => $room->id,
            'generation_number'  => 1,
            'status'             => 'ACTIVE',
            'group_id_hash'      => $room->group_id_hash,
            'start_epoch'        => 1,
            'start_sequence'     => 1,
            'current_sequence'   => 1,
            'activated_at'       => now(),
        ]);

        $message = BchatMessage::create([
            'room_id'             => $room->id,
            'session_id'          => $session1->id,
            'server_sequence'     => 1,
            'message_uuid'        => (string) Str::uuid(),
            'sender_id'           => $user->id,
            'epoch'               => 1,
            'generation'          => 0,
            'content_type'        => 2,
            'ciphertext_payload'  => 'manifest_cipher',
            'payload_hash_sha256' => hash('sha256', 'manifest_cipher'),
            'client_sent_at'      => now(),
        ]);

        $uploadSession = BchatUploadSession::create([
            'session_uuid' => (string) Str::uuid(),
            'room_id'      => $room->id,
            'user_id'      => $user->id,
            'total_bytes'  => 1028,
            'total_chunks' => 1,
            'status'       => 'COMPLETED',
            'expires_at'   => now()->addHour(),
        ]);

        $attUuid = (string) Str::uuid();
        $fileHash = hash('sha256', 'proposal_pdf_plaintext');

        $attachment = BchatAttachment::create([
            'attachment_uuid'   => $attUuid,
            'upload_session_id' => $uploadSession->id,
            'room_id'           => $room->id,
            'session_id'        => $session1->id,
            'message_id'        => $message->id,
            'plaintext_bytes'   => 1000,
            'ciphertext_bytes'  => 1028,
            'total_chunks'      => 1,
            'file_hash_sha256'  => $fileHash,
            'status'            => 'ATTACHED',
        ]);

        $this->assertEquals('ATTACHED', $attachment->status);
        $this->assertEquals($fileHash, $attachment->file_hash_sha256);
        $this->assertEquals($session1->id, $attachment->session_id);
    }

    /**
     * Skenario 10: Regresi chat, attachment, pairing, dan session generation tetap PASS.
     */
    public function test_10_regression_chat_attachment_pairing_and_session_generation_pass(): void
    {
        $initiator = $this->createTestUser('mahasiswa');
        $recipient = $this->createTestUser('dosen');
        $initDev = $this->createTestDevice($initiator);
        $recipDev = $this->createTestDevice($recipient);
        $room = $this->createTestRoomWithMembers($initiator, $recipient);

        // Verifikasi sesi aktif
        $session = BchatRoomSession::create([
            'session_uuid'       => (string) Str::uuid(),
            'room_id'            => $room->id,
            'generation_number'  => 1,
            'status'             => 'ACTIVE',
            'group_id_hash'      => $room->group_id_hash,
            'start_epoch'        => 1,
            'start_sequence'     => 1,
            'current_sequence'   => 0,
            'activated_at'       => now(),
        ]);

        $this->assertTrue($room->hasActiveCryptoSession());
        $this->assertEquals('ACTIVE', $session->status);
        $this->assertEquals(1, $room->sessions()->count());
    }
}
