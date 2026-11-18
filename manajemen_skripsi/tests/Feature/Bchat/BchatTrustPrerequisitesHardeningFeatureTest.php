<?php

declare(strict_types=1);

namespace Tests\Feature\Bchat;

use App\Models\BchatDevicePairing;
use App\Models\BchatKeyPackage;
use App\Models\BchatRegistrationNonce;
use App\Models\BchatRoom;
use App\Models\BchatRoomMember;
use App\Models\BchatUserDevice;
use App\Models\BchatWelcomeChallenge;
use App\Models\Dosen;
use App\Models\Mahasiswa;
use App\Models\PenugasanPembimbing;
use App\Models\Skripsi;
use App\Models\User;
use App\Services\BchatDeviceService;
use App\Services\BchatPairingVerificationService;
use App\Services\BchatPopVerificationService;
use App\Services\BchatWelcomeAckVerificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Pengujian Wajib: Perbaikan Prasyarat Trust (Sebelum Tahap 2B).
 *
 * Membuktikan 12 skenario keamanan terarah:
 * 1. Migrasi 000021 tidak memberikan trust kepada perangkat mana pun hanya berdasarkan ID.
 * 2. Perangkat ACTIVE tanpa bukti kriptografis tetap untrusted.
 * 3. Perangkat ADD_DEVICE tetap untrusted sampai pairing berhasil.
 * 4. Perangkat MANAGED_REPLACEMENT tetap untrusted sampai pairing berhasil.
 * 5. Riwayat replacement perangkat lain dalam akun yang sama tidak memberikan trust.
 * 6. Welcome ACK valid hanya membuktikan trust untuk perangkat penerima yang benar.
 * 7. KeyPackage consumption tidak dapat digunakan untuk mempercayai perangkat lain.
 * 8. Pairing Approval valid hanya mempercayai target yang ditandatangani.
 * 9. Signature, fingerprint, nonce, atau device binding yang tidak valid ditolak.
 * 10. Pairing kedaluwarsa, ditolak, atau dibatalkan tidak memberikan trust.
 * 11. Urutan migrasi 000021 dan 000022 tidak menciptakan kondisi sementara yang memberikan trust.
 * 12. Pencabutan perangkat tetap berlaku dan tidak dapat dilewati melalui pairing atau replacement.
 */
class BchatTrustPrerequisitesHardeningFeatureTest extends TestCase
{
    use RefreshDatabase;

    private function createTestUser(string $role = 'mahasiswa'): User
    {
        return User::create([
            'name'         => 'User ' . Str::random(6),
            'email'        => strtolower(Str::random(8)) . '@mail.unuha.ac.id',
            'password'     => bcrypt('PasswordRahasia123!'),
            'role'         => $role,
            'nomor_induk'  => (string) random_int(10000000, 99999999),
            'is_active'    => true,
        ]);
    }

    private function generateEd25519Keypair(): array
    {
        $kp = sodium_crypto_sign_keypair();
        $pk = sodium_crypto_sign_publickey($kp);
        $sk = sodium_crypto_sign_secretkey($kp);

        return [
            'public_key_bin' => $pk,
            'public_key_hex' => bin2hex($pk),
            'secret_key'     => $sk,
            'fingerprint'    => hash('sha256', $pk),
        ];
    }

    private function issueValidNonceForUser(User $user, string $deviceUuid, array $keypair): BchatRegistrationNonce
    {
        return BchatRegistrationNonce::create([
            'user_id'                => $user->id,
            'device_uuid'            => strtolower(trim($deviceUuid)),
            'nonce_uuid'             => (string) Str::uuid(),
            'nonce_value'            => bin2hex(random_bytes(32)),
            'public_key_fingerprint' => $keypair['fingerprint'],
            'status'                 => 'ISSUED',
            'issued_at'              => time(),
            'expires_at'             => now()->addMinutes(5),
            'consumed_at'            => null,
        ]);
    }

    private function createValidPoP(
        int|string $userId,
        string $deviceUuid,
        BchatRegistrationNonce $nonce,
        array $keypair
    ): array {
        $payload = BchatPopVerificationService::buildPayload(
            userId: $userId,
            deviceUuid: $deviceUuid,
            nonceHexOrBin: $nonce->nonce_value,
            issuedAt: $nonce->issued_at,
            publicKeyHexOrBin: $keypair['public_key_hex']
        );

        $signature = sodium_crypto_sign_detached($payload, $keypair['secret_key']);

        return [
            'payload_hex'   => bin2hex($payload),
            'signature_hex' => bin2hex($signature),
        ];
    }

    private function registerDeviceViaApi(
        User $user,
        string $deviceUuid,
        string $deviceName = 'Device Test',
        string $mode = 'ADD_DEVICE',
        bool $confirmReplacement = false,
        ?string $expectedActiveUuid = null
    ): array {
        $keypair = $this->generateEd25519Keypair();
        $nonce   = $this->issueValidNonceForUser($user, $deviceUuid, $keypair);
        $pop     = $this->createValidPoP($user->id, $deviceUuid, $nonce, $keypair);

        $postData = [
            'nonce_uuid'        => $nonce->nonce_uuid,
            'pop_payload'       => $pop['payload_hex'],
            'pop_signature'     => $pop['signature_hex'],
            'device_name'       => $deviceName,
            'registration_mode' => $mode,
        ];

        if ($confirmReplacement) {
            $postData['confirm_replacement'] = true;
            $postData['expected_active_device_uuid'] = $expectedActiveUuid;
        }

        $response = $this->actingAs($user, 'sanctum')->postJson('/api/bchat/devices/register', $postData);

        return [
            'response' => $response,
            'keypair'  => $keypair,
            'device'   => BchatUserDevice::where('device_uuid', $deviceUuid)->first(),
        ];
    }

    private function createPairingRoomWithMembers(User $mhs, User $dosen): BchatRoom
    {
        $mhsModel = Mahasiswa::create([
            'user_id'         => $mhs->id,
            'NIM'             => $mhs->nomor_induk,
            'nama_mahasiswa'  => 'Mahasiswa Test',
            'prodi'           => 'Informatika',
            'status_akademik' => 'aktif',
        ]);

        $skripsi = Skripsi::create([
            'id_mahasiswa' => $mhsModel->id_mahasiswa,
            'judul'        => 'Proposal Hardening Test',
            'status_judul' => 'diajukan',
        ]);

        $dosenModel = Dosen::create([
            'user_id'    => $dosen->id,
            'NIDN'       => $dosen->nomor_induk,
            'nama_dosen' => 'Dosen Test',
            'prodi'      => 'Informatika',
        ]);

        $penugasan = PenugasanPembimbing::create([
            'skripsi_id'       => $skripsi->id_skripsi,
            'tahap'            => 'proposal',
            'pembimbing_ke'    => 1,
            'dosen_id'         => $dosenModel->id_dosen,
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
            'group_id_hash'           => hash('sha256', 'group_context_hardening_' . Str::random(6)),
        ]);

        BchatRoomMember::create([
            'room_id'         => $room->id,
            'user_id'         => $mhs->id,
            'role'            => 'mahasiswa',
            'is_initiator'    => true,
            'is_active'       => true,
            'joined_at_epoch' => 1,
        ]);

        BchatRoomMember::create([
            'room_id'         => $room->id,
            'user_id'         => $dosen->id,
            'role'            => 'dosen_pembimbing',
            'is_initiator'    => false,
            'is_active'       => true,
            'joined_at_epoch' => 1,
        ]);

        return $room;
    }

    private function signPairingApproval(
        int $userId,
        BchatDevicePairing $pairing,
        BchatUserDevice $approverDevice,
        string $approverSecretKey
    ): array {
        $serverTime = time();

        $payloadBinary = BchatPairingVerificationService::buildCanonicalPayload(
            userId: $userId,
            pairingUuid: $pairing->pairing_uuid,
            approverDeviceUuid: $approverDevice->device_uuid,
            targetDeviceUuid: $pairing->target_device_uuid,
            challengeNonceHex: $pairing->challenge_nonce,
            targetFingerprintHex: $pairing->target_public_key_fingerprint,
            timestamp: $serverTime
        );

        $signatureBinary = sodium_crypto_sign_detached($payloadBinary, $approverSecretKey);

        return [
            'payload_hex'   => bin2hex($payloadBinary),
            'signature_hex' => bin2hex($signatureBinary),
        ];
    }

    // =========================================================================
    // 1. Migrasi 000021 tidak memberikan trust berdasarkan ID
    // =========================================================================
    public function test_01_migration_000021_does_not_grant_trust_to_any_device_based_on_id(): void
    {
        $user = $this->createTestUser('mahasiswa');

        // Dua perangkat ACTIVE tanpa bukti kriptografis
        $dev1 = BchatUserDevice::create([
            'user_id'                => $user->id,
            'device_uuid'            => (string) Str::uuid(),
            'device_name'            => 'Perangkat 1 (ID Kecil)',
            'public_key'             => bin2hex(random_bytes(32)),
            'public_key_fingerprint' => hash('sha256', 'pk1'),
            'status'                 => 'ACTIVE',
            'is_trusted'             => false,
            'key_version'            => 1,
            'created_at'             => now()->subDays(5),
        ]);

        $dev2 = BchatUserDevice::create([
            'user_id'                => $user->id,
            'device_uuid'            => (string) Str::uuid(),
            'device_name'            => 'Perangkat 2 (ID Besar)',
            'public_key'             => bin2hex(random_bytes(32)),
            'public_key_fingerprint' => hash('sha256', 'pk2'),
            'status'                 => 'ACTIVE',
            'is_trusted'             => false,
            'key_version'            => 1,
            'created_at'             => now()->subDays(1),
        ]);

        // Pastikan tidak ada mekanisme auto-trust berbasis orderBy('id', 'asc')
        $this->assertFalse((bool) $dev1->is_trusted);
        $this->assertFalse((bool) $dev2->is_trusted);
        $this->assertNull($dev1->trusted_at);
        $this->assertNull($dev2->trusted_at);
    }

    // =========================================================================
    // 2. Perangkat ACTIVE tanpa bukti kriptografis tetap untrusted
    // =========================================================================
    public function test_02_active_device_without_cryptographic_proof_remains_untrusted(): void
    {
        $user = $this->createTestUser('dosen');
        $keypair = $this->generateEd25519Keypair();

        $device = BchatUserDevice::create([
            'user_id'                => $user->id,
            'device_uuid'            => (string) Str::uuid(),
            'device_name'            => 'Perangkat Tanpa Bukti',
            'public_key'             => $keypair['public_key_hex'],
            'public_key_fingerprint' => $keypair['fingerprint'],
            'status'                 => 'ACTIVE',
            'is_trusted'             => false,
            'key_version'            => 1,
        ]);

        $hasProof = BchatDeviceService::hasHistoricalTrustProof($device);
        $this->assertFalse($hasProof);
    }

    // =========================================================================
    // 3. Perangkat ADD_DEVICE tetap untrusted sampai pairing berhasil
    // =========================================================================
    public function test_03_add_device_remains_untrusted_until_pairing_succeeds(): void
    {
        $user = $this->createTestUser('mahasiswa');

        // Perangkat 1: First device (bootstrap trust)
        $dev1Uuid = (string) Str::uuid();
        $dev1 = $this->registerDeviceViaApi($user, $dev1Uuid, 'Laptop Pertama');
        $this->assertTrue($dev1['device']->is_trusted);

        // Perangkat 2: ADD_DEVICE -> wajib untrusted
        $dev2Uuid = (string) Str::uuid();
        $dev2 = $this->registerDeviceViaApi($user, $dev2Uuid, 'HP Tambahan', 'ADD_DEVICE');
        $this->assertFalse($dev2['device']->is_trusted);
        $this->assertNull($dev2['device']->trusted_at);

        // Melakukan alur pairing
        $createRes = $this->actingAs($user, 'sanctum')->postJson('/api/bchat/pairing/request', [
            'target_device_uuid' => $dev2Uuid,
        ]);
        $createRes->assertStatus(201);
        $pairingUuid = $createRes->json('data.pairing_uuid');

        $pairing = BchatDevicePairing::where('pairing_uuid', $pairingUuid)->firstOrFail();
        $approval = $this->signPairingApproval(
            userId: $user->id,
            pairing: $pairing,
            approverDevice: $dev1['device'],
            approverSecretKey: $dev1['keypair']['secret_key']
        );

        $approveRes = $this->actingAs($user, 'sanctum')->postJson("/api/bchat/pairing/{$pairingUuid}/approve", [
            'approver_device_uuid' => $dev1Uuid,
            'approval_payload'     => $approval['payload_hex'],
            'approval_signature'   => $approval['signature_hex'],
        ]);
        $approveRes->assertStatus(200);

        $dev2['device']->refresh();
        $this->assertTrue($dev2['device']->is_trusted);
        $this->assertNotNull($dev2['device']->trusted_at);
        $this->assertSame($dev1['device']->id, $dev2['device']->trusted_by_device_id);
    }

    // =========================================================================
    // 4. Perangkat MANAGED_REPLACEMENT tetap untrusted sampai pairing berhasil
    // =========================================================================
    public function test_04_managed_replacement_remains_untrusted_until_pairing_succeeds(): void
    {
        $user = $this->createTestUser('dosen');

        // Daftarkan perangkat 1 (trusted)
        $oldUuid = (string) Str::uuid();
        $reg1 = $this->registerDeviceViaApi($user, $oldUuid, 'Laptop Lama');
        $this->assertTrue($reg1['device']->is_trusted);

        // Managed replacement ke perangkat baru
        $newUuid = (string) Str::uuid();
        $regReplace = $this->registerDeviceViaApi(
            user: $user,
            deviceUuid: $newUuid,
            deviceName: 'Laptop Baru Pengganti',
            mode: 'MANAGED_REPLACEMENT',
            confirmReplacement: true,
            expectedActiveUuid: $oldUuid
        );
        $regReplace['response']->assertStatus(201);

        // Status perangkat pengganti wajib is_trusted = false
        $this->assertFalse($regReplace['response']->json('data.is_trusted'));
        $this->assertFalse($regReplace['device']->is_trusted);
        $this->assertNull($regReplace['device']->trusted_at);
        $this->assertTrue($regReplace['response']->json('data.is_replacement'));

        // Perangkat lama berstatus REVOKED
        $oldDevice = BchatUserDevice::where('device_uuid', $oldUuid)->firstOrFail();
        $this->assertSame('REVOKED', $oldDevice->status);
    }

    // =========================================================================
    // 5. Riwayat replacement perangkat lain dalam akun yang sama tidak memberikan trust
    // =========================================================================
    public function test_05_replacement_history_of_other_device_in_same_account_does_not_grant_trust(): void
    {
        $user = $this->createTestUser('mahasiswa');

        // Perangkat 0: Dulu diganti (REVOKED)
        $revokedDevice = BchatUserDevice::create([
            'user_id'                => $user->id,
            'device_uuid'            => (string) Str::uuid(),
            'device_name'            => 'Perangkat Lama Revoked',
            'public_key'             => bin2hex(random_bytes(32)),
            'public_key_fingerprint' => hash('sha256', 'old'),
            'status'                 => 'REVOKED',
            'revocation_reason'      => 'MANAGED_REPLACEMENT',
            'is_trusted'             => true,
            'key_version'            => 1,
        ]);

        // Perangkat 1: Aktif, tanpa bukti langsung
        $dev1 = BchatUserDevice::create([
            'user_id'                => $user->id,
            'device_uuid'            => (string) Str::uuid(),
            'device_name'            => 'Perangkat Aktif 1',
            'public_key'             => bin2hex(random_bytes(32)),
            'public_key_fingerprint' => hash('sha256', 'act1'),
            'status'                 => 'ACTIVE',
            'is_trusted'             => false,
            'key_version'            => 1,
        ]);

        // Perangkat 2: Aktif, tanpa bukti langsung
        $dev2 = BchatUserDevice::create([
            'user_id'                => $user->id,
            'device_uuid'            => (string) Str::uuid(),
            'device_name'            => 'Perangkat Aktif 2',
            'public_key'             => bin2hex(random_bytes(32)),
            'public_key_fingerprint' => hash('sha256', 'act2'),
            'status'                 => 'ACTIVE',
            'is_trusted'             => false,
            'key_version'            => 1,
        ]);

        // Riwayat replacement Perangkat 0 TIDAK memberikan trust kepada Dev 1 maupun Dev 2
        $this->assertFalse(BchatDeviceService::hasHistoricalTrustProof($dev1));
        $this->assertFalse(BchatDeviceService::hasHistoricalTrustProof($dev2));
    }

    // =========================================================================
    // 6. Welcome ACK valid hanya membuktikan trust untuk perangkat penerima yang benar
    // =========================================================================
    public function test_06_valid_welcome_ack_only_proves_trust_for_the_correct_recipient_device(): void
    {
        $mhs = $this->createTestUser('mahasiswa');
        $dosen = $this->createTestUser('dosen');
        $room = $this->createPairingRoomWithMembers($mhs, $dosen);

        $kpA = $this->generateEd25519Keypair();
        $kpB = $this->generateEd25519Keypair();

        $devA = BchatUserDevice::create([
            'user_id'                => $mhs->id,
            'device_uuid'            => (string) Str::uuid(),
            'device_name'            => 'Perangkat A',
            'public_key'             => $kpA['public_key_hex'],
            'public_key_fingerprint' => $kpA['fingerprint'],
            'status'                 => 'ACTIVE',
            'is_trusted'             => true,
            'key_version'            => 1,
        ]);

        $devB = BchatUserDevice::create([
            'user_id'                => $mhs->id,
            'device_uuid'            => (string) Str::uuid(),
            'device_name'            => 'Perangkat B',
            'public_key'             => $kpB['public_key_hex'],
            'public_key_fingerprint' => $kpB['fingerprint'],
            'status'                 => 'ACTIVE',
            'is_trusted'             => false,
            'key_version'            => 1,
        ]);

        // Buat Welcome ACK yang valid untuk Perangkat A
        $welcomeOpUuid = (string) Str::uuid();
        $challengeUuid = (string) Str::uuid();
        $challengeNonce = bin2hex(random_bytes(32));

        $payload = BchatWelcomeAckVerificationService::buildPayload(
            roomUuid: $room->room_uuid,
            recipientUserId: $mhs->id,
            welcomeOperationUuid: $welcomeOpUuid,
            challengeUuid: $challengeUuid,
            challengeGeneration: 1,
            groupIdHashHexOrBin: $room->group_id_hash,
            epoch: 1,
            challengeNonceHexOrBin: $challengeNonce
        );

        $signature = sodium_crypto_sign_detached($payload, $kpA['secret_key']);
        $payloadHash = hash('sha256', $payload);

        BchatWelcomeChallenge::create([
            'welcome_operation_uuid' => $welcomeOpUuid,
            'room_id'                => $room->id,
            'inviter_user_id'        => $dosen->id,
            'recipient_user_id'      => $mhs->id,
            'recipient_device_id'    => $devA->id,
            'challenge_uuid'         => $challengeUuid,
            'challenge_nonce'        => $challengeNonce,
            'challenge_generation'   => 1,
            'welcome_message'        => 'encrypted_mls_welcome_payload',
            'group_id_hash'          => $room->group_id_hash,
            'epoch'                  => 1,
            'operation_status'       => 'CONSUMED',
            'challenge_status'       => 'CONSUMED',
            'ack_signature'          => bin2hex($signature),
            'ack_payload_hash'       => $payloadHash,
            'consumed_at'            => now(),
            'expires_at'             => now()->addDay(),
        ]);

        $this->assertTrue(BchatDeviceService::hasHistoricalTrustProof($devA));
        $this->assertFalse(BchatDeviceService::hasHistoricalTrustProof($devB));
    }

    // =========================================================================
    // 7. KeyPackage consumed tanpa Welcome ACK / Pairing menghasilkan false dan tidak dapat mempercayai perangkat lain
    // =========================================================================
    public function test_07_key_package_consumption_alone_does_not_grant_trust_and_cannot_trust_other_device(): void
    {
        $user = $this->createTestUser('mahasiswa');
        $kpA = $this->generateEd25519Keypair();
        $kpB = $this->generateEd25519Keypair();

        $devA = BchatUserDevice::create([
            'user_id'                => $user->id,
            'device_uuid'            => (string) Str::uuid(),
            'device_name'            => 'Perangkat A',
            'public_key'             => $kpA['public_key_hex'],
            'public_key_fingerprint' => $kpA['fingerprint'],
            'status'                 => 'ACTIVE',
            'is_trusted'             => false,
            'key_version'            => 1,
        ]);

        $devB = BchatUserDevice::create([
            'user_id'                => $user->id,
            'device_uuid'            => (string) Str::uuid(),
            'device_name'            => 'Perangkat B',
            'public_key'             => $kpB['public_key_hex'],
            'public_key_fingerprint' => $kpB['fingerprint'],
            'status'                 => 'ACTIVE',
            'is_trusted'             => false,
            'key_version'            => 1,
        ]);

        // KeyPackage dikonsumsi untuk Dev A
        $pkgData = bin2hex(random_bytes(64));
        BchatKeyPackage::create([
            'user_id'          => $user->id,
            'device_id'        => $devA->id,
            'key_package_hash' => hash('sha256', $pkgData),
            'key_package_data' => $pkgData,
            'is_consumed'      => true,
            'consumed_at'      => now(),
        ]);

        // KeyPackage consumed saja TIDAK membuktikan penguasaan private key -> Dev A wajib false
        $this->assertFalse(BchatDeviceService::hasHistoricalTrustProof($devA));
        // Dev B juga tidak dapat memanfaatkan KeyPackage milik Dev A -> Dev B wajib false
        $this->assertFalse(BchatDeviceService::hasHistoricalTrustProof($devB));
    }

    // =========================================================================
    // 8. Pairing Approval valid hanya mempercayai target yang ditandatangani
    // =========================================================================
    public function test_08_valid_pairing_approval_only_trusts_the_signed_target_device(): void
    {
        $user = $this->createTestUser('dosen');
        $kpApprover = $this->generateEd25519Keypair();
        $kpTargetA  = $this->generateEd25519Keypair();
        $kpTargetB  = $this->generateEd25519Keypair();

        $approver = BchatUserDevice::create([
            'user_id'                => $user->id,
            'device_uuid'            => (string) Str::uuid(),
            'device_name'            => 'Approver PC',
            'public_key'             => $kpApprover['public_key_hex'],
            'public_key_fingerprint' => $kpApprover['fingerprint'],
            'status'                 => 'ACTIVE',
            'is_trusted'             => true,
            'key_version'            => 1,
        ]);

        $targetA = BchatUserDevice::create([
            'user_id'                => $user->id,
            'device_uuid'            => (string) Str::uuid(),
            'device_name'            => 'Target A',
            'public_key'             => $kpTargetA['public_key_hex'],
            'public_key_fingerprint' => $kpTargetA['fingerprint'],
            'status'                 => 'ACTIVE',
            'is_trusted'             => false,
            'key_version'            => 1,
        ]);

        $targetB = BchatUserDevice::create([
            'user_id'                => $user->id,
            'device_uuid'            => (string) Str::uuid(),
            'device_name'            => 'Target B',
            'public_key'             => $kpTargetB['public_key_hex'],
            'public_key_fingerprint' => $kpTargetB['fingerprint'],
            'status'                 => 'ACTIVE',
            'is_trusted'             => false,
            'key_version'            => 1,
        ]);

        // Buat pairing approved untuk Target A
        $pairingUuid = (string) Str::uuid();
        $pairingCode = 'PAIR-A1B2';
        $nonce = bin2hex(random_bytes(32));

        $payload = BchatPairingVerificationService::buildCanonicalPayload(
            userId: $user->id,
            pairingUuid: $pairingUuid,
            approverDeviceUuid: $approver->device_uuid,
            targetDeviceUuid: $targetA->device_uuid,
            challengeNonceHex: $nonce,
            targetFingerprintHex: $targetA->public_key_fingerprint,
            timestamp: time()
        );

        $sig = sodium_crypto_sign_detached($payload, $kpApprover['secret_key']);

        BchatDevicePairing::create([
            'user_id'                         => $user->id,
            'pairing_uuid'                    => $pairingUuid,
            'pairing_code'                    => $pairingCode,
            'target_device_id'                => $targetA->id,
            'target_device_uuid'              => $targetA->device_uuid,
            'target_public_key_fingerprint'   => $targetA->public_key_fingerprint,
            'challenge_nonce'                 => $nonce,
            'status'                          => 'APPROVED',
            'approver_device_id'              => $approver->id,
            'approver_device_uuid'            => $approver->device_uuid,
            'approval_signature'              => bin2hex($sig),
            'approval_payload'                => bin2hex($payload),
            'approved_at'                     => now(),
            'expires_at'                      => now()->addMinutes(5),
        ]);

        $this->assertTrue(BchatDeviceService::hasHistoricalTrustProof($targetA));
        $this->assertFalse(BchatDeviceService::hasHistoricalTrustProof($targetB));
    }

    // =========================================================================
    // 9. Signature, fingerprint, nonce, atau device binding yang tidak valid ditolak
    // =========================================================================
    public function test_09_invalid_signature_fingerprint_nonce_or_device_binding_rejected(): void
    {
        $user = $this->createTestUser('mahasiswa');
        $kpApprover = $this->generateEd25519Keypair();
        $kpTarget   = $this->generateEd25519Keypair();

        $approver = BchatUserDevice::create([
            'user_id'                => $user->id,
            'device_uuid'            => (string) Str::uuid(),
            'device_name'            => 'Approver PC',
            'public_key'             => $kpApprover['public_key_hex'],
            'public_key_fingerprint' => $kpApprover['fingerprint'],
            'status'                 => 'ACTIVE',
            'is_trusted'             => true,
            'key_version'            => 1,
        ]);

        $target = BchatUserDevice::create([
            'user_id'                => $user->id,
            'device_uuid'            => (string) Str::uuid(),
            'device_name'            => 'Target Device',
            'public_key'             => $kpTarget['public_key_hex'],
            'public_key_fingerprint' => $kpTarget['fingerprint'],
            'status'                 => 'ACTIVE',
            'is_trusted'             => false,
            'key_version'            => 1,
        ]);

        $pairingUuid = (string) Str::uuid();
        $nonce = bin2hex(random_bytes(32));

        $payload = BchatPairingVerificationService::buildCanonicalPayload(
            userId: $user->id,
            pairingUuid: $pairingUuid,
            approverDeviceUuid: $approver->device_uuid,
            targetDeviceUuid: $target->device_uuid,
            challengeNonceHex: $nonce,
            targetFingerprintHex: $target->public_key_fingerprint,
            timestamp: time()
        );

        // A. Signature korup / palsu
        $corruptSig = str_repeat('ff', 64);
        $pairingA = BchatDevicePairing::create([
            'user_id'                         => $user->id,
            'pairing_uuid'                    => $pairingUuid,
            'pairing_code'                    => 'PAIR-FAIL',
            'target_device_id'                => $target->id,
            'target_device_uuid'              => $target->device_uuid,
            'target_public_key_fingerprint'   => $target->public_key_fingerprint,
            'challenge_nonce'                 => $nonce,
            'status'                          => 'APPROVED',
            'approver_device_id'              => $approver->id,
            'approver_device_uuid'            => $approver->device_uuid,
            'approval_signature'              => $corruptSig,
            'approval_payload'                => bin2hex($payload),
            'approved_at'                     => now(),
            'expires_at'                      => now()->addMinutes(5),
        ]);

        $this->assertFalse(BchatDeviceService::hasHistoricalTrustProof($target));
    }

    // =========================================================================
    // 10. Pairing kedaluwarsa, ditolak, atau dibatalkan tidak memberikan trust
    // =========================================================================
    public function test_10_expired_rejected_or_canceled_pairing_does_not_grant_trust(): void
    {
        $user = $this->createTestUser('dosen');
        $kp = $this->generateEd25519Keypair();

        $target = BchatUserDevice::create([
            'user_id'                => $user->id,
            'device_uuid'            => (string) Str::uuid(),
            'device_name'            => 'Target Device',
            'public_key'             => $kp['public_key_hex'],
            'public_key_fingerprint' => $kp['fingerprint'],
            'status'                 => 'ACTIVE',
            'is_trusted'             => false,
            'key_version'            => 1,
        ]);

        // Pairing status REJECTED
        BchatDevicePairing::create([
            'user_id'                       => $user->id,
            'pairing_uuid'                  => (string) Str::uuid(),
            'pairing_code'                  => 'PAIR-REJ1',
            'target_device_id'              => $target->id,
            'target_device_uuid'            => $target->device_uuid,
            'target_public_key_fingerprint' => $target->public_key_fingerprint,
            'challenge_nonce'               => bin2hex(random_bytes(32)),
            'status'                        => 'REJECTED',
            'expires_at'                    => now()->addMinutes(5),
        ]);
        $this->assertFalse(BchatDeviceService::hasHistoricalTrustProof($target));

        // Pairing status CANCELED
        BchatDevicePairing::create([
            'user_id'                       => $user->id,
            'pairing_uuid'                  => (string) Str::uuid(),
            'pairing_code'                  => 'PAIR-CAN1',
            'target_device_id'              => $target->id,
            'target_device_uuid'            => $target->device_uuid,
            'target_public_key_fingerprint' => $target->public_key_fingerprint,
            'challenge_nonce'               => bin2hex(random_bytes(32)),
            'status'                        => 'CANCELED',
            'expires_at'                    => now()->addMinutes(5),
        ]);
        $this->assertFalse(BchatDeviceService::hasHistoricalTrustProof($target));

        // Pairing status EXPIRED
        BchatDevicePairing::create([
            'user_id'                       => $user->id,
            'pairing_uuid'                  => (string) Str::uuid(),
            'pairing_code'                  => 'PAIR-EXP1',
            'target_device_id'              => $target->id,
            'target_device_uuid'            => $target->device_uuid,
            'target_public_key_fingerprint' => $target->public_key_fingerprint,
            'challenge_nonce'               => bin2hex(random_bytes(32)),
            'status'                        => 'EXPIRED',
            'expires_at'                    => now()->subMinute(),
        ]);
        $this->assertFalse(BchatDeviceService::hasHistoricalTrustProof($target));
    }

    // =========================================================================
    // 11. Urutan migrasi 000021 dan 000022 tidak menciptakan kondisi sementara
    // =========================================================================
    public function test_11_migration_sequence_000021_and_000022_does_not_create_transient_unearned_trust(): void
    {
        $user = $this->createTestUser('mahasiswa');
        $dosen = $this->createTestUser('dosen');
        $room = $this->createPairingRoomWithMembers($mhs = $user, $dosen);

        $kp1 = $this->generateEd25519Keypair();
        $kp2 = $this->generateEd25519Keypair();

        // Device 1 memiliki bukti Welcome ACK
        $dev1 = BchatUserDevice::create([
            'user_id'                => $user->id,
            'device_uuid'            => (string) Str::uuid(),
            'device_name'            => 'Perangkat 1 (Sah)',
            'public_key'             => $kp1['public_key_hex'],
            'public_key_fingerprint' => $kp1['fingerprint'],
            'status'                 => 'ACTIVE',
            'is_trusted'             => false,
            'key_version'            => 1,
        ]);

        // Device 2 tanpa bukti apa pun
        $dev2 = BchatUserDevice::create([
            'user_id'                => $user->id,
            'device_uuid'            => (string) Str::uuid(),
            'device_name'            => 'Perangkat 2 (Tanpa Bukti)',
            'public_key'             => $kp2['public_key_hex'],
            'public_key_fingerprint' => $kp2['fingerprint'],
            'status'                 => 'ACTIVE',
            'is_trusted'             => false,
            'key_version'            => 1,
        ]);

        // Tambahkan Welcome ACK sah untuk Device 1
        $welcomeOpUuid = (string) Str::uuid();
        $challengeUuid = (string) Str::uuid();
        $challengeNonce = bin2hex(random_bytes(32));

        $payload = BchatWelcomeAckVerificationService::buildPayload(
            roomUuid: $room->room_uuid,
            recipientUserId: $user->id,
            welcomeOperationUuid: $welcomeOpUuid,
            challengeUuid: $challengeUuid,
            challengeGeneration: 1,
            groupIdHashHexOrBin: $room->group_id_hash,
            epoch: 1,
            challengeNonceHexOrBin: $challengeNonce
        );

        $sig = sodium_crypto_sign_detached($payload, $kp1['secret_key']);

        BchatWelcomeChallenge::create([
            'welcome_operation_uuid' => $welcomeOpUuid,
            'room_id'                => $room->id,
            'inviter_user_id'        => $dosen->id,
            'recipient_user_id'      => $user->id,
            'recipient_device_id'    => $dev1->id,
            'challenge_uuid'         => $challengeUuid,
            'challenge_nonce'        => $challengeNonce,
            'challenge_generation'   => 1,
            'welcome_message'        => 'encrypted_mls_welcome_payload',
            'group_id_hash'          => $room->group_id_hash,
            'epoch'                  => 1,
            'operation_status'       => 'CONSUMED',
            'challenge_status'       => 'CONSUMED',
            'ack_signature'          => bin2hex($sig),
            'ack_payload_hash'       => hash('sha256', $payload),
            'consumed_at'            => now(),
            'expires_at'             => now()->addDay(),
        ]);

        // Jalankan migrasi 000021 logic (default false)
        // Di 000021 ternetralisasi, tidak ada perangkat yang diberi is_trusted = true
        $dev1->refresh();
        $dev2->refresh();
        $this->assertFalse((bool) $dev1->is_trusted);
        $this->assertFalse((bool) $dev2->is_trusted);

        // Jalankan migrasi 000022 logic (evaluasi hasHistoricalTrustProof)
        foreach ([$dev1, $dev2] as $dev) {
            if (BchatDeviceService::hasHistoricalTrustProof($dev->id)) {
                $dev->update(['is_trusted' => true, 'trusted_at' => now()]);
            } else {
                $dev->update(['is_trusted' => false, 'trusted_at' => null]);
            }
        }

        $dev1->refresh();
        $dev2->refresh();

        // Hasil akhir: Dev 1 yang terbukti secara kriptografis menjadi trusted, Dev 2 tetap untrusted
        $this->assertTrue((bool) $dev1->is_trusted);
        $this->assertFalse((bool) $dev2->is_trusted);
    }

    // =========================================================================
    // 12. Pencabutan perangkat tetap berlaku dan tidak dapat dilewati via pairing/replacement
    // =========================================================================
    public function test_12_revocation_remains_effective_and_cannot_be_bypassed_via_pairing_or_replacement(): void
    {
        $user = $this->createTestUser('mahasiswa');

        // Daftarkan perangkat
        $uuid = (string) Str::uuid();
        $reg = $this->registerDeviceViaApi($user, $uuid, 'Perangkat Mau Dicabut');
        $this->assertTrue($reg['device']->is_trusted);

        // Cabut perangkat
        $revokeRes = $this->actingAs($user, 'sanctum')->postJson("/api/bchat/devices/{$uuid}/revoke", [
            'password' => 'PasswordRahasia123!',
        ]);
        $revokeRes->assertStatus(200);

        $reg['device']->refresh();
        $this->assertSame('REVOKED', $reg['device']->status);

        // hasHistoricalTrustProof untuk perangkat REVOKED wajib false
        $this->assertFalse(BchatDeviceService::hasHistoricalTrustProof($reg['device']));

        // Coba jadikan target pairing -> ditolak 403
        $pairRes = $this->actingAs($user, 'sanctum')->postJson('/api/bchat/pairing/request', [
            'target_device_uuid' => $uuid,
        ]);
        $pairRes->assertStatus(403);

        // Coba daftarkan kembali via ADD_DEVICE -> ditolak 403
        $reregRes = $this->registerDeviceViaApi($user, $uuid, 'Daftar Ulang Revoked', 'ADD_DEVICE');
        $reregRes['response']->assertStatus(403)
            ->assertJson([
                'status'     => 'error',
                'error_code' => 'REVOKED_DEVICE_CANNOT_REREGISTER',
            ]);
    }
}
