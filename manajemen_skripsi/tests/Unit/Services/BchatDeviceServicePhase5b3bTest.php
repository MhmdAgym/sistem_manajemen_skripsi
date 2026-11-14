<?php

namespace Tests\Unit\Services;

use App\Exceptions\BchatDeviceException;
use App\Models\BchatKeyPackage;
use App\Models\BchatRegistrationNonce;
use App\Models\BchatRoom;
use App\Models\BchatUserDevice;
use App\Models\BchatWelcomeChallenge;
use App\Models\Dosen;
use App\Models\Mahasiswa;
use App\Models\PenugasanPembimbing;
use App\Models\Skripsi;
use App\Models\User;
use App\Services\BchatDeviceService;
use App\Services\BchatPopVerificationService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

class BchatDeviceServicePhase5b3bTest extends TestCase
{
    use DatabaseTransactions;

    private BchatDeviceService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $activeDb = DB::connection()->getDatabaseName();
        $this->assertEquals(
            'manajemen_skripsi_test',
            $activeDb,
            'CRITICAL SECURITY: Test suite B-CHAT HANYA boleh berjalan pada database manajemen_skripsi_test.'
        );

        $this->service = new BchatDeviceService();
    }

    /**
     * Helper untuk membuat user uji dengan password yang diketahui.
     */
    private function createTestUser(string $password = 'RahasiaBchat123!'): User
    {
        return User::factory()->create([
            'password' => Hash::make($password),
        ]);
    }

    /**
     * Helper untuk membuat pasangan kunci Ed25519 kriptografis nyata.
     */
    private function generateEd25519Keypair(): array
    {
        $keypair = sodium_crypto_sign_keypair();
        $secretKey = sodium_crypto_sign_secretkey($keypair);
        $publicKey = sodium_crypto_sign_publickey($keypair);

        return [
            'secret_key'     => $secretKey,
            'public_key'     => $publicKey,
            'public_key_hex' => bin2hex($publicKey),
            'fingerprint'    => hash('sha256', $publicKey),
        ];
    }

    /**
     * Helper untuk menerbitkan registration nonce valid di database.
     */
    private function issueValidNonceForUser(
        User $user,
        string $deviceUuid,
        array $keypair,
        ?int $issuedAt = null
    ): BchatRegistrationNonce {
        $serverIssuedAt = $issuedAt ?? time();

        return BchatRegistrationNonce::create([
            'user_id'                => $user->id,
            'device_uuid'            => strtolower(trim($deviceUuid)),
            'nonce_uuid'             => (string) Str::uuid(),
            'nonce_value'            => bin2hex(random_bytes(32)),
            'public_key_fingerprint' => $keypair['fingerprint'],
            'status'                 => 'ISSUED',
            'issued_at'              => $serverIssuedAt,
            'expires_at'             => now()->addMinutes(5),
            'consumed_at'            => null,
        ]);
    }

    /**
     * Helper untuk membuat PoP payload 121-byte dan detached signature 64-byte sah.
     */
    private function createValidPoP(
        int|string $userId,
        string $deviceUuid,
        BchatRegistrationNonce $nonce,
        array $keypair,
        ?int $timestamp = null
    ): array {
        $ts = $timestamp ?? $nonce->issued_at;

        $payload = BchatPopVerificationService::buildPayload(
            userId: $userId,
            deviceUuid: $deviceUuid,
            nonceHexOrBin: $nonce->nonce_value,
            issuedAt: $ts,
            publicKeyHexOrBin: $keypair['public_key_hex']
        );

        $signature = sodium_crypto_sign_detached($payload, $keypair['secret_key']);

        return [
            'payload'   => $payload,
            'signature' => $signature,
        ];
    }

    /**
     * Helper untuk membuat entitas relasi akademik lengkap hingga BchatRoom.
     */
    private function createTestRoom(string $status = 'PENDING_WELCOME_ACK'): BchatRoom
    {
        $userMhs = User::factory()->create(['role' => 'mahasiswa', 'nomor_induk' => (string) rand(1000000000, 9999999999)]);
        $mhs = Mahasiswa::create([
            'user_id'        => $userMhs->id,
            'NIM'            => $userMhs->nomor_induk,
            'nama_mahasiswa' => 'Mahasiswa Test Room ' . Str::random(4),
            'prodi'          => 'Informatika',
        ]);

        $skripsi = Skripsi::create([
            'id_mahasiswa' => $mhs->id_mahasiswa,
            'judul'        => 'Judul Skripsi Test Room ' . Str::random(5),
            'status_judul' => 'diajukan',
        ]);

        $userDosen = User::factory()->create(['role' => 'dosen', 'nomor_induk' => (string) rand(1000000000, 9999999999)]);
        $dosen = Dosen::create([
            'user_id'    => $userDosen->id,
            'NIDN'       => $userDosen->nomor_induk,
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

        return BchatRoom::create([
            'room_uuid'               => (string) Str::uuid(),
            'penugasan_pembimbing_id' => $penugasan->id,
            'tahap'                   => 'proposal',
            'pembimbing_ke'           => 1,
            'current_sequence'        => 0,
            'status'                  => $status,
            'current_epoch'           => 0,
            'group_id_hash'           => hash('sha256', Str::random(32)),
        ]);
    }

    /**
     * Helper untuk membuat WelcomeChallenge terkait room dan perangkat tertentu.
     */
    private function createTestWelcomeChallenge(
        BchatRoom $room,
        User $recipientUser,
        BchatUserDevice $recipientDevice,
        string $operationStatus = 'ACTIVE',
        string $challengeStatus = 'ISSUED'
    ): BchatWelcomeChallenge {
        return BchatWelcomeChallenge::create([
            'welcome_operation_uuid' => (string) Str::uuid(),
            'room_id'                => $room->id,
            'recipient_user_id'      => $recipientUser->id,
            'recipient_device_id'    => $recipientDevice->id,
            'welcome_message'        => 'encrypted_hpke_test_bytes_' . Str::random(32),
            'group_id_hash'          => hash('sha256', Str::random(32)),
            'epoch'                  => 1,
            'operation_status'       => $operationStatus,
            'challenge_uuid'         => (string) Str::uuid(),
            'challenge_nonce'        => bin2hex(random_bytes(32)),
            'challenge_generation'   => 1,
            'challenge_status'       => $challengeStatus,
            'expires_at'             => now()->addMinutes(5),
            'consumed_at'            => null,
        ]);
    }

    /**
     * Helper untuk membuat KeyPackage terkait perangkat tertentu.
     */
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
            'consumed_at'      => $isConsumed ? now()->subHour() : null,
        ]);
    }

    // =========================================================================
    // 1. REGISTRASI PERANGKAT PERTAMA (FIRST REGISTRATION)
    // =========================================================================

    /**
     * 1. Registrasi perangkat pertama berhasil.
     */
    public function test_first_device_registration_succeeds(): void
    {
        $user = $this->createTestUser();
        $deviceUuid = (string) Str::uuid();
        $keypair = $this->generateEd25519Keypair();

        $nonce = $this->issueValidNonceForUser($user, $deviceUuid, $keypair);
        $pop = $this->createValidPoP($user->id, $deviceUuid, $nonce, $keypair);

        $result = $this->service->registerDevice(
            user: $user,
            nonceUuid: $nonce->nonce_uuid,
            payloadInput: $pop['payload'],
            signatureInput: $pop['signature'],
            deviceName: 'Chrome on Linux'
        );

        $this->assertIsArray($result);
        $this->assertEquals($user->id, BchatUserDevice::find($result['device_id'])->user_id);
        $this->assertEquals(strtolower($deviceUuid), $result['device_uuid']);
        $this->assertEquals('Chrome on Linux', $result['device_name']);
        $this->assertEquals('ACTIVE', $result['status']);
        $this->assertFalse($result['is_replacement']);
        $this->assertNull($result['revoked_device_id']);
        $this->assertEquals($keypair['public_key_hex'], $result['public_key']);
        $this->assertEquals($keypair['fingerprint'], $result['public_key_fingerprint']);

        // Pastikan nonce dikonsumsi secara atomik
        $nonce->refresh();
        $this->assertEquals('CONSUMED', $nonce->status);
        $this->assertNotNull($nonce->consumed_at);
    }

    /**
     * 2. Key version pertama bernilai 1.
     */
    public function test_first_device_registration_has_key_version_1(): void
    {
        $user = $this->createTestUser();
        $deviceUuid = (string) Str::uuid();
        $keypair = $this->generateEd25519Keypair();

        $nonce = $this->issueValidNonceForUser($user, $deviceUuid, $keypair);
        $pop = $this->createValidPoP($user->id, $deviceUuid, $nonce, $keypair);

        $result = $this->service->registerDevice(
            user: $user,
            nonceUuid: $nonce->nonce_uuid,
            payloadInput: $pop['payload'],
            signatureInput: $pop['signature']
        );

        $this->assertEquals(1, $result['key_version']);

        $device = BchatUserDevice::find($result['device_id']);
        $this->assertEquals(1, $device->key_version);
    }

    /**
     * 3. Registrasi ulang UUID yang sama menaikkan key version secara monotonik.
     */
    public function test_reregistration_same_device_uuid_increments_key_version(): void
    {
        $user = $this->createTestUser();
        $deviceUuid = (string) Str::uuid();

        // Registrasi 1
        $keypair1 = $this->generateEd25519Keypair();
        $nonce1 = $this->issueValidNonceForUser($user, $deviceUuid, $keypair1);
        $pop1 = $this->createValidPoP($user->id, $deviceUuid, $nonce1, $keypair1);
        $result1 = $this->service->registerDevice($user, $nonce1->nonce_uuid, $pop1['payload'], $pop1['signature']);
        $this->assertEquals(1, $result1['key_version']);

        // Registrasi 2 dengan device_uuid yang sama (namun keypair baru & nonce baru)
        $keypair2 = $this->generateEd25519Keypair();
        $nonce2 = $this->issueValidNonceForUser($user, $deviceUuid, $keypair2);
        $pop2 = $this->createValidPoP($user->id, $deviceUuid, $nonce2, $keypair2);
        $result2 = $this->service->registerDevice($user, $nonce2->nonce_uuid, $pop2['payload'], $pop2['signature'], confirmReplacement: true, expectedActiveDeviceUuid: $deviceUuid);

        $this->assertEquals(2, $result2['key_version']);
        $this->assertTrue($result2['is_replacement']);
        $this->assertEquals($result1['device_id'], $result2['revoked_device_id']);
    }

    // =========================================================================
    // 2. MANAGED REPLACEMENT (PENGGANTIAN PERANGKAT AKTIF)
    // =========================================================================

    /**
     * 4. Managed Replacement berhasil.
     */
    public function test_managed_replacement_succeeds(): void
    {
        $user = $this->createTestUser();

        // Setup perangkat lama ACTIVE
        $oldUuid = (string) Str::uuid();
        $oldKeypair = $this->generateEd25519Keypair();
        $oldNonce = $this->issueValidNonceForUser($user, $oldUuid, $oldKeypair);
        $oldPoP = $this->createValidPoP($user->id, $oldUuid, $oldNonce, $oldKeypair);
        $oldDeviceResult = $this->service->registerDevice($user, $oldNonce->nonce_uuid, $oldPoP['payload'], $oldPoP['signature']);

        // Registrasi perangkat baru
        $newUuid = (string) Str::uuid();
        $newKeypair = $this->generateEd25519Keypair();
        $newNonce = $this->issueValidNonceForUser($user, $newUuid, $newKeypair);
        $newPoP = $this->createValidPoP($user->id, $newUuid, $newNonce, $newKeypair);

        $newDeviceResult = $this->service->registerDevice(
            user: $user,
            nonceUuid: $newNonce->nonce_uuid,
            payloadInput: $newPoP['payload'],
            signatureInput: $newPoP['signature'],
            deviceName: 'Firefox on Android',
            confirmReplacement: true,
            expectedActiveDeviceUuid: $oldUuid
        );

        $this->assertTrue($newDeviceResult['is_replacement']);
        $this->assertEquals($oldDeviceResult['device_id'], $newDeviceResult['revoked_device_id']);
        $this->assertEquals('ACTIVE', $newDeviceResult['status']);

        // Verifikasi hanya ada 1 perangkat ACTIVE di database
        $activeDevices = BchatUserDevice::where('user_id', $user->id)->active()->get();
        $this->assertCount(1, $activeDevices);
        $this->assertEquals($newDeviceResult['device_id'], $activeDevices->first()->id);
    }

    /**
     * 5. Perangkat lama menjadi REVOKED.
     */
    public function test_managed_replacement_revokes_old_active_device(): void
    {
        $user = $this->createTestUser();
        $oldUuid = (string) Str::uuid();
        $oldKeypair = $this->generateEd25519Keypair();
        $oldNonce = $this->issueValidNonceForUser($user, $oldUuid, $oldKeypair);
        $oldPoP = $this->createValidPoP($user->id, $oldUuid, $oldNonce, $oldKeypair);
        $oldResult = $this->service->registerDevice($user, $oldNonce->nonce_uuid, $oldPoP['payload'], $oldPoP['signature']);

        // Replacement
        $newUuid = (string) Str::uuid();
        $newKeypair = $this->generateEd25519Keypair();
        $newNonce = $this->issueValidNonceForUser($user, $newUuid, $newKeypair);
        $newPoP = $this->createValidPoP($user->id, $newUuid, $newNonce, $newKeypair);
        $this->service->registerDevice($user, $newNonce->nonce_uuid, $newPoP['payload'], $newPoP['signature'], confirmReplacement: true, expectedActiveDeviceUuid: $oldUuid);

        $oldDevice = BchatUserDevice::find($oldResult['device_id']);
        $this->assertEquals('REVOKED', $oldDevice->status);
        $this->assertNotNull($oldDevice->revoked_at);
        $this->assertEquals('MANAGED_REPLACEMENT', $oldDevice->revocation_reason);
    }

    /**
     * 6. Public key dan identitas lama tetap dipertahankan (tidak ditimpa atau dihapus).
     */
    public function test_managed_replacement_preserves_old_device_identity_and_public_key(): void
    {
        $user = $this->createTestUser();
        $oldUuid = (string) Str::uuid();
        $oldKeypair = $this->generateEd25519Keypair();
        $oldNonce = $this->issueValidNonceForUser($user, $oldUuid, $oldKeypair);
        $oldPoP = $this->createValidPoP($user->id, $oldUuid, $oldNonce, $oldKeypair);
        $oldResult = $this->service->registerDevice($user, $oldNonce->nonce_uuid, $oldPoP['payload'], $oldPoP['signature']);

        // Replacement
        $newUuid = (string) Str::uuid();
        $newKeypair = $this->generateEd25519Keypair();
        $newNonce = $this->issueValidNonceForUser($user, $newUuid, $newKeypair);
        $newPoP = $this->createValidPoP($user->id, $newUuid, $newNonce, $newKeypair);
        $this->service->registerDevice($user, $newNonce->nonce_uuid, $newPoP['payload'], $newPoP['signature'], confirmReplacement: true, expectedActiveDeviceUuid: $oldUuid);

        $oldDevice = BchatUserDevice::find($oldResult['device_id']);
        $this->assertEquals(strtolower($oldUuid), $oldDevice->device_uuid);
        $this->assertEquals($oldKeypair['public_key_hex'], $oldDevice->public_key);
        $this->assertEquals($oldKeypair['fingerprint'], $oldDevice->public_key_fingerprint);
    }

    /**
     * 7. KeyPackage lama yang belum dikonsumsi diinvalidasi.
     */
    public function test_managed_replacement_invalidates_unconsumed_key_packages_of_old_device(): void
    {
        $user = $this->createTestUser();
        $oldUuid = (string) Str::uuid();
        $oldKeypair = $this->generateEd25519Keypair();
        $oldNonce = $this->issueValidNonceForUser($user, $oldUuid, $oldKeypair);
        $oldPoP = $this->createValidPoP($user->id, $oldUuid, $oldNonce, $oldKeypair);
        $oldResult = $this->service->registerDevice($user, $oldNonce->nonce_uuid, $oldPoP['payload'], $oldPoP['signature']);

        $oldDevice = BchatUserDevice::find($oldResult['device_id']);
        $kp1 = $this->createTestKeyPackage($user, $oldDevice, isConsumed: false);
        $kp2 = $this->createTestKeyPackage($user, $oldDevice, isConsumed: false);

        // Replacement
        $newUuid = (string) Str::uuid();
        $newKeypair = $this->generateEd25519Keypair();
        $newNonce = $this->issueValidNonceForUser($user, $newUuid, $newKeypair);
        $newPoP = $this->createValidPoP($user->id, $newUuid, $newNonce, $newKeypair);
        $this->service->registerDevice($user, $newNonce->nonce_uuid, $newPoP['payload'], $newPoP['signature'], confirmReplacement: true, expectedActiveDeviceUuid: $oldUuid);

        $kp1->refresh();
        $kp2->refresh();
        $this->assertTrue($kp1->is_consumed);
        $this->assertNotNull($kp1->consumed_at);
        $this->assertTrue($kp2->is_consumed);
        $this->assertNotNull($kp2->consumed_at);
    }

    /**
     * 8. KeyPackage yang sudah dikonsumsi tidak diubah.
     */
    public function test_managed_replacement_does_not_modify_already_consumed_key_packages(): void
    {
        $user = $this->createTestUser();
        $oldUuid = (string) Str::uuid();
        $oldKeypair = $this->generateEd25519Keypair();
        $oldNonce = $this->issueValidNonceForUser($user, $oldUuid, $oldKeypair);
        $oldPoP = $this->createValidPoP($user->id, $oldUuid, $oldNonce, $oldKeypair);
        $oldResult = $this->service->registerDevice($user, $oldNonce->nonce_uuid, $oldPoP['payload'], $oldPoP['signature']);

        $oldDevice = BchatUserDevice::find($oldResult['device_id']);
        $kpConsumed = $this->createTestKeyPackage($user, $oldDevice, isConsumed: true);
        $originalConsumedAt = $kpConsumed->consumed_at;

        // Replacement
        $newUuid = (string) Str::uuid();
        $newKeypair = $this->generateEd25519Keypair();
        $newNonce = $this->issueValidNonceForUser($user, $newUuid, $newKeypair);
        $newPoP = $this->createValidPoP($user->id, $newUuid, $newNonce, $newKeypair);
        $this->service->registerDevice($user, $newNonce->nonce_uuid, $newPoP['payload'], $newPoP['signature'], confirmReplacement: true, expectedActiveDeviceUuid: $oldUuid);

        $kpConsumed->refresh();
        $this->assertTrue($kpConsumed->is_consumed);
        $this->assertEquals($originalConsumedAt->timestamp, $kpConsumed->consumed_at->timestamp);
    }

    /**
     * 9. Challenge perangkat lama dibatalkan dengan status yang tepat.
     */
    public function test_managed_replacement_cancels_active_challenges_of_old_device(): void
    {
        $user = $this->createTestUser();
        $oldUuid = (string) Str::uuid();
        $oldKeypair = $this->generateEd25519Keypair();
        $oldNonce = $this->issueValidNonceForUser($user, $oldUuid, $oldKeypair);
        $oldPoP = $this->createValidPoP($user->id, $oldUuid, $oldNonce, $oldKeypair);
        $oldResult = $this->service->registerDevice($user, $oldNonce->nonce_uuid, $oldPoP['payload'], $oldPoP['signature']);

        $oldDevice = BchatUserDevice::find($oldResult['device_id']);
        $room = $this->createTestRoom();
        $challenge = $this->createTestWelcomeChallenge($room, $user, $oldDevice, 'ACTIVE', 'ISSUED');

        // Replacement
        $newUuid = (string) Str::uuid();
        $newKeypair = $this->generateEd25519Keypair();
        $newNonce = $this->issueValidNonceForUser($user, $newUuid, $newKeypair);
        $newPoP = $this->createValidPoP($user->id, $newUuid, $newNonce, $newKeypair);
        $this->service->registerDevice($user, $newNonce->nonce_uuid, $newPoP['payload'], $newPoP['signature'], confirmReplacement: true, expectedActiveDeviceUuid: $oldUuid);

        $challenge->refresh();
        $this->assertEquals('REVOKED_SUPERSEDED', $challenge->operation_status);
        $this->assertEquals('EXPIRED', $challenge->challenge_status);
    }

    /**
     * 10. Challenge perangkat lain tidak berubah.
     */
    public function test_managed_replacement_does_not_cancel_challenges_of_other_devices(): void
    {
        $userA = $this->createTestUser();
        $userB = $this->createTestUser();

        // Perangkat User A
        $uuidA = (string) Str::uuid();
        $keyA = $this->generateEd25519Keypair();
        $nonceA = $this->issueValidNonceForUser($userA, $uuidA, $keyA);
        $popA = $this->createValidPoP($userA->id, $uuidA, $nonceA, $keyA);
        $resA = $this->service->registerDevice($userA, $nonceA->nonce_uuid, $popA['payload'], $popA['signature']);
        $devA = BchatUserDevice::find($resA['device_id']);

        // Perangkat User B
        $uuidB = (string) Str::uuid();
        $keyB = $this->generateEd25519Keypair();
        $nonceB = $this->issueValidNonceForUser($userB, $uuidB, $keyB);
        $popB = $this->createValidPoP($userB->id, $uuidB, $nonceB, $keyB);
        $resB = $this->service->registerDevice($userB, $nonceB->nonce_uuid, $popB['payload'], $popB['signature']);
        $devB = BchatUserDevice::find($resB['device_id']);

        $room = $this->createTestRoom();
        $challengeB = $this->createTestWelcomeChallenge($room, $userB, $devB, 'ACTIVE', 'ISSUED');

        // User A melakukan replacement ke perangkat baru
        $uuidA2 = (string) Str::uuid();
        $keyA2 = $this->generateEd25519Keypair();
        $nonceA2 = $this->issueValidNonceForUser($userA, $uuidA2, $keyA2);
        $popA2 = $this->createValidPoP($userA->id, $uuidA2, $nonceA2, $keyA2);
        $this->service->registerDevice($userA, $nonceA2->nonce_uuid, $popA2['payload'], $popA2['signature'], confirmReplacement: true, expectedActiveDeviceUuid: $uuidA);

        // Challenge milik User B / Device B tidak boleh terpengaruh
        $challengeB->refresh();
        $this->assertEquals('ACTIVE', $challengeB->operation_status);
        $this->assertEquals('ISSUED', $challengeB->challenge_status);
    }

    /**
     * 11. Room tetap berstatus awal (tidak otomatis menjadi ACTIVE).
     */
    public function test_managed_replacement_preserves_room_status(): void
    {
        $user = $this->createTestUser();
        $oldUuid = (string) Str::uuid();
        $oldKeypair = $this->generateEd25519Keypair();
        $oldNonce = $this->issueValidNonceForUser($user, $oldUuid, $oldKeypair);
        $oldPoP = $this->createValidPoP($user->id, $oldUuid, $oldNonce, $oldKeypair);
        $oldResult = $this->service->registerDevice($user, $oldNonce->nonce_uuid, $oldPoP['payload'], $oldPoP['signature']);

        $oldDevice = BchatUserDevice::find($oldResult['device_id']);
        $room = $this->createTestRoom('PENDING_WELCOME_ACK');
        $this->createTestWelcomeChallenge($room, $user, $oldDevice, 'ACTIVE', 'ISSUED');

        // Replacement
        $newUuid = (string) Str::uuid();
        $newKeypair = $this->generateEd25519Keypair();
        $newNonce = $this->issueValidNonceForUser($user, $newUuid, $newKeypair);
        $newPoP = $this->createValidPoP($user->id, $newUuid, $newNonce, $newKeypair);
        $this->service->registerDevice($user, $newNonce->nonce_uuid, $newPoP['payload'], $newPoP['signature'], confirmReplacement: true, expectedActiveDeviceUuid: $oldUuid);

        $room->refresh();
        $this->assertEquals('PENDING_WELCOME_ACK', $room->status);
    }

    // =========================================================================
    // 3. PENGUJIAN VALIDASI NONCE & ATURAN KEAMANAN
    // =========================================================================

    /**
     * 12. Nonce tidak ditemukan menghasilkan 404.
     */
    public function test_registration_with_non_existent_nonce_uuid_rejected_404(): void
    {
        $user = $this->createTestUser();
        $keypair = $this->generateEd25519Keypair();
        $deviceUuid = (string) Str::uuid();

        $dummyPayload = str_repeat('a', 121);
        $dummySig = str_repeat('b', 64);

        $this->expectException(BchatDeviceException::class);
        $this->expectExceptionCode(404);

        try {
            $this->service->registerDevice($user, (string) Str::uuid(), $dummyPayload, $dummySig);
        } catch (BchatDeviceException $e) {
            $this->assertEquals('REGISTRATION_NONCE_NOT_FOUND', $e->getErrorCode());
            $this->assertEquals(404, $e->getHttpStatus());
            throw $e;
        }
    }

    /**
     * 13. Nonce milik user lain ditolak dengan 403.
     */
    public function test_registration_with_other_user_nonce_rejected_403(): void
    {
        $userA = $this->createTestUser();
        $userB = $this->createTestUser();
        $deviceUuid = (string) Str::uuid();
        $keypair = $this->generateEd25519Keypair();

        $nonceB = $this->issueValidNonceForUser($userB, $deviceUuid, $keypair);
        $pop = $this->createValidPoP($userA->id, $deviceUuid, $nonceB, $keypair);

        $this->expectException(BchatDeviceException::class);
        $this->expectExceptionCode(403);

        try {
            // User A mencoba mengonsumsi nonce milik User B
            $this->service->registerDevice($userA, $nonceB->nonce_uuid, $pop['payload'], $pop['signature']);
        } catch (BchatDeviceException $e) {
            $this->assertEquals('NONCE_USER_MISMATCH', $e->getErrorCode());
            $this->assertEquals(403, $e->getHttpStatus());
            throw $e;
        }
    }

    /**
     * 14. Device UUID mismatch antara nonce dan payload ditolak.
     */
    public function test_registration_with_device_uuid_mismatch_rejected(): void
    {
        $user = $this->createTestUser();
        $deviceUuidNonce = (string) Str::uuid();
        $deviceUuidPoP = (string) Str::uuid();
        $keypair = $this->generateEd25519Keypair();

        $nonce = $this->issueValidNonceForUser($user, $deviceUuidNonce, $keypair);
        // Buat PoP dengan UUID berbeda dari nonce
        $pop = $this->createValidPoP($user->id, $deviceUuidPoP, $nonce, $keypair);

        $this->expectException(BchatDeviceException::class);

        try {
            $this->service->registerDevice($user, $nonce->nonce_uuid, $pop['payload'], $pop['signature']);
        } catch (BchatDeviceException $e) {
            $this->assertEquals('DEVICE_UUID_MISMATCH', $e->getErrorCode());
            $this->assertEquals(401, $e->getHttpStatus());
            throw $e;
        }
    }

    /**
     * 15. Nonce expired ditolak dengan 403.
     */
    public function test_registration_with_expired_nonce_rejected_403(): void
    {
        $user = $this->createTestUser();
        $deviceUuid = (string) Str::uuid();
        $keypair = $this->generateEd25519Keypair();

        $nonce = $this->issueValidNonceForUser($user, $deviceUuid, $keypair);
        $nonce->update(['expires_at' => now()->subMinute()]);

        $pop = $this->createValidPoP($user->id, $deviceUuid, $nonce, $keypair);

        $this->expectException(BchatDeviceException::class);
        $this->expectExceptionCode(403);

        try {
            $this->service->registerDevice($user, $nonce->nonce_uuid, $pop['payload'], $pop['signature']);
        } catch (BchatDeviceException $e) {
            $this->assertEquals('NONCE_EXPIRED', $e->getErrorCode());
            $this->assertEquals(403, $e->getHttpStatus());
            throw $e;
        }
    }

    /**
     * 16. Nonce yang sudah dikonsumsi (consumed) ditolak dengan 403.
     */
    public function test_registration_with_already_consumed_nonce_rejected_403(): void
    {
        $user = $this->createTestUser();
        $deviceUuid = (string) Str::uuid();
        $keypair = $this->generateEd25519Keypair();

        $nonce = $this->issueValidNonceForUser($user, $deviceUuid, $keypair);
        $nonce->update(['status' => 'CONSUMED', 'consumed_at' => now()->subMinute()]);

        $pop = $this->createValidPoP($user->id, $deviceUuid, $nonce, $keypair);

        $this->expectException(BchatDeviceException::class);
        $this->expectExceptionCode(403);

        try {
            $this->service->registerDevice($user, $nonce->nonce_uuid, $pop['payload'], $pop['signature']);
        } catch (BchatDeviceException $e) {
            $this->assertEquals('NONCE_ALREADY_CONSUMED', $e->getErrorCode());
            $this->assertEquals(403, $e->getHttpStatus());
            throw $e;
        }
    }

    // =========================================================================
    // 4. PENGUJIAN VALIDASI POP KRIPTOGRAFIS
    // =========================================================================

    /**
     * 17. Payload PoP tidak valid (panjang salah atau magic header salah) ditolak.
     */
    public function test_registration_with_invalid_pop_payload_rejected(): void
    {
        $user = $this->createTestUser();
        $deviceUuid = (string) Str::uuid();
        $keypair = $this->generateEd25519Keypair();

        $nonce = $this->issueValidNonceForUser($user, $deviceUuid, $keypair);
        $pop = $this->createValidPoP($user->id, $deviceUuid, $nonce, $keypair);

        // Uji panjang salah (120 byte)
        $shortPayload = substr($pop['payload'], 0, 120);

        $this->expectException(BchatDeviceException::class);
        $this->expectExceptionCode(422);

        try {
            $this->service->registerDevice($user, $nonce->nonce_uuid, $shortPayload, $pop['signature']);
        } catch (BchatDeviceException $e) {
            $this->assertEquals('INVALID_POP_PAYLOAD_LENGTH', $e->getErrorCode());
            $this->assertEquals(422, $e->getHttpStatus());
            throw $e;
        }
    }

    /**
     * 18. Signature Ed25519 tidak valid ditolak dengan 401.
     */
    public function test_registration_with_invalid_signature_rejected_401(): void
    {
        $user = $this->createTestUser();
        $deviceUuid = (string) Str::uuid();
        $keypair = $this->generateEd25519Keypair();

        $nonce = $this->issueValidNonceForUser($user, $deviceUuid, $keypair);
        $pop = $this->createValidPoP($user->id, $deviceUuid, $nonce, $keypair);

        // Rusak 1 byte signature
        $corruptSig = $pop['signature'];
        $corruptSig[0] = $corruptSig[0] === "\x00" ? "\xFF" : "\x00";

        $this->expectException(BchatDeviceException::class);
        $this->expectExceptionCode(401);

        try {
            $this->service->registerDevice($user, $nonce->nonce_uuid, $pop['payload'], $corruptSig);
        } catch (BchatDeviceException $e) {
            $this->assertEquals('SIGNATURE_VERIFICATION_FAILED', $e->getErrorCode());
            $this->assertEquals(401, $e->getHttpStatus());
            throw $e;
        }
    }

    /**
     * 19. Fingerprint mismatch ditolak dengan 401.
     */
    public function test_registration_with_fingerprint_mismatch_rejected_401(): void
    {
        $user = $this->createTestUser();
        $deviceUuid = (string) Str::uuid();
        $keypairA = $this->generateEd25519Keypair();
        $keypairB = $this->generateEd25519Keypair();

        // Nonce diikat ke keypairA
        $nonce = $this->issueValidNonceForUser($user, $deviceUuid, $keypairA);

        // PoP dibuat dan ditandatangani oleh keypairB
        $popB = $this->createValidPoP($user->id, $deviceUuid, $nonce, $keypairB);

        $this->expectException(BchatDeviceException::class);
        $this->expectExceptionCode(401);

        try {
            $this->service->registerDevice($user, $nonce->nonce_uuid, $popB['payload'], $popB['signature']);
        } catch (BchatDeviceException $e) {
            $this->assertEquals('FINGERPRINT_MISMATCH', $e->getErrorCode());
            $this->assertEquals(401, $e->getHttpStatus());
            throw $e;
        }
    }

    /**
     * 20. Timestamp mismatch ditolak dengan 401.
     */
    public function test_registration_with_timestamp_mismatch_rejected_401(): void
    {
        $user = $this->createTestUser();
        $deviceUuid = (string) Str::uuid();
        $keypair = $this->generateEd25519Keypair();

        $nonce = $this->issueValidNonceForUser($user, $deviceUuid, $keypair, issuedAt: 1700000000);

        // PoP ditandatangani dengan timestamp berbeda (1700000500)
        $pop = $this->createValidPoP($user->id, $deviceUuid, $nonce, $keypair, timestamp: 1700000500);

        $this->expectException(BchatDeviceException::class);
        $this->expectExceptionCode(401);

        try {
            $this->service->registerDevice($user, $nonce->nonce_uuid, $pop['payload'], $pop['signature']);
        } catch (BchatDeviceException $e) {
            $this->assertEquals('TIMESTAMP_MISMATCH', $e->getErrorCode());
            $this->assertEquals(401, $e->getHttpStatus());
            throw $e;
        }
    }

    /**
     * 21. Public key yang sudah terdaftar ditolak dengan kode konflik 409.
     */
    public function test_registration_with_already_registered_public_key_rejected_409(): void
    {
        $userA = $this->createTestUser();
        $userB = $this->createTestUser();
        $deviceUuidA = (string) Str::uuid();
        $deviceUuidB = (string) Str::uuid();

        // Kunci publik yang sama
        $sharedKeypair = $this->generateEd25519Keypair();

        // User A mendaftar lebih dulu
        $nonceA = $this->issueValidNonceForUser($userA, $deviceUuidA, $sharedKeypair);
        $popA = $this->createValidPoP($userA->id, $deviceUuidA, $nonceA, $sharedKeypair);
        $this->service->registerDevice($userA, $nonceA->nonce_uuid, $popA['payload'], $popA['signature']);

        // User B mencoba menggunakan public key yang persis sama
        $nonceB = $this->issueValidNonceForUser($userB, $deviceUuidB, $sharedKeypair);
        $popB = $this->createValidPoP($userB->id, $deviceUuidB, $nonceB, $sharedKeypair);

        $this->expectException(BchatDeviceException::class);
        $this->expectExceptionCode(409);

        try {
            $this->service->registerDevice($userB, $nonceB->nonce_uuid, $popB['payload'], $popB['signature']);
        } catch (BchatDeviceException $e) {
            $this->assertEquals('PUBLIC_KEY_ALREADY_IN_USE', $e->getErrorCode());
            $this->assertEquals(409, $e->getHttpStatus());
            throw $e;
        }
    }

    // =========================================================================
    // 5. ATOMIC ROLLBACK ON TRANSACTION FAILURE
    // =========================================================================

    /**
     * 22. Kegagalan di tengah transaksi menyebabkan rollback seluruh mutasi secara atomik.
     */
    public function test_atomic_rollback_on_transaction_failure(): void
    {
        $user = $this->createTestUser();

        // Siapkan perangkat lama ACTIVE
        $oldUuid = (string) Str::uuid();
        $oldKeypair = $this->generateEd25519Keypair();
        $oldNonce = $this->issueValidNonceForUser($user, $oldUuid, $oldKeypair);
        $oldPoP = $this->createValidPoP($user->id, $oldUuid, $oldNonce, $oldKeypair);
        $oldResult = $this->service->registerDevice($user, $oldNonce->nonce_uuid, $oldPoP['payload'], $oldPoP['signature']);

        $oldDevice = BchatUserDevice::find($oldResult['device_id']);
        $oldKp = $this->createTestKeyPackage($user, $oldDevice, isConsumed: false);
        $room = $this->createTestRoom();
        $challenge = $this->createTestWelcomeChallenge($room, $user, $oldDevice, 'ACTIVE', 'ISSUED');

        // Siapkan registrasi baru
        $newUuid = (string) Str::uuid();
        $newKeypair = $this->generateEd25519Keypair();
        $newNonce = $this->issueValidNonceForUser($user, $newUuid, $newKeypair);
        $newPoP = $this->createValidPoP($user->id, $newUuid, $newNonce, $newKeypair);

        // Pasang failure injection pada model event BchatUserDevice menggunakan isolated event dispatcher
        $originalDispatcher = BchatUserDevice::getEventDispatcher();
        $isolatedDispatcher = clone $originalDispatcher;
        $injectedMessage = 'Simulated catastrophic DB error during new device persistence.';

        $isolatedDispatcher->listen('eloquent.creating: ' . BchatUserDevice::class, function () use ($injectedMessage) {
            throw new \RuntimeException($injectedMessage);
        });

        BchatUserDevice::setEventDispatcher($isolatedDispatcher);

        $caughtException = null;
        try {
            $this->service->registerDevice($user, $newNonce->nonce_uuid, $newPoP['payload'], $newPoP['signature']);
        } catch (\Throwable $e) {
            $caughtException = $e;
        } finally {
            BchatUserDevice::setEventDispatcher($originalDispatcher);
        }

        $this->assertNotNull($caughtException, 'Operasi harus melempar exception karena disimulasikan gagal.');

        // Verifikasi SELURUH state database mengalami rollback sempurna:
        // A. Perangkat lama tetap ACTIVE
        $oldDevice->refresh();
        $this->assertEquals('ACTIVE', $oldDevice->status);
        $this->assertNull($oldDevice->revoked_at);

        // B. KeyPackage lama tetap is_consumed = false
        $oldKp->refresh();
        $this->assertFalse($oldKp->is_consumed);
        $this->assertNull($oldKp->consumed_at);

        // C. Welcome Challenge tetap ACTIVE & ISSUED
        $challenge->refresh();
        $this->assertEquals('ACTIVE', $challenge->operation_status);
        $this->assertEquals('ISSUED', $challenge->challenge_status);

        // D. Nonce baru tetap ISSUED & consumed_at null
        $newNonce->refresh();
        $this->assertEquals('ISSUED', $newNonce->status);
        $this->assertNull($newNonce->consumed_at);

        // E. Perangkat baru TIDAK dibuat di database
        $this->assertDatabaseMissing('bchat_user_devices', [
            'user_id'     => $user->id,
            'device_uuid' => strtolower(trim($newUuid)),
        ]);
    }
}

