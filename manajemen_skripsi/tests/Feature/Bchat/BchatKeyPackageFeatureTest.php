<?php

declare(strict_types=1);

namespace Tests\Feature\Bchat;

use App\Exceptions\BchatKeyPackageException;
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
use App\Services\BchatKeyPackageService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Feature Test untuk KeyPackage Management API (B-CHAT-22B Tahap 2C.3B).
 *
 * Menguji endpoint:
 * - POST /api/bchat/key-packages (Upload KeyPackages)
 * - GET  /api/bchat/rooms/{roomUuid}/partner-key-package (Fetch Partner KeyPackage)
 *
 * Mencakup 29 skenario komprehensif:
 * - 14 skenario upload: otorisasi, batch atomik, device binding, validasi RFC 4648 Base64, SHA-256 hash, batas ukuran, dan penanganan duplikasi.
 * - 13 skenario fetch: otorisasi Sanctum & inisiator, status room & assignment, device aktif, ketersediaan stok, invarian read-only, dan sanitasi ID internal.
 * - 2 skenario integrasi lifecycle: Managed Replacement device transition dan key consumption progression.
 */
class BchatKeyPackageFeatureTest extends TestCase
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
    // HELPER FIXTURES & DATA GENERATORS
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
            'judul'        => 'Proposal E2EE KeyPackage Test ' . Str::random(5),
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
            'group_id_hash'           => hash('sha256', 'group_context_keypkg'),
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

    private function generateKeyPackageData(int $size = 256): array
    {
        $binary = random_bytes($size);
        $base64 = base64_encode($binary);
        $hash = hash('sha256', $binary);

        return [
            'binary'           => $binary,
            'key_package_data' => $base64,
            'key_package_hash' => $hash,
        ];
    }

    private function createActiveKeyPackage(
        User $user,
        BchatUserDevice $device,
        bool $isConsumed = false,
        ?string $hash = null,
        ?string $binary = null
    ): BchatKeyPackage {
        $bin = $binary ?? random_bytes(256);
        $h = $hash ?? hash('sha256', $bin);

        return BchatKeyPackage::create([
            'user_id'          => $user->id,
            'device_id'        => $device->id,
            'key_package_hash' => $h,
            'key_package_data' => $bin,
            'is_consumed'      => $isConsumed,
            'consumed_at'      => $isConsumed ? now() : null,
        ]);
    }

    // =========================================================================
    // BAGIAN 1: PENGUJIAN ENDPOINT UPLOAD (14 SKENARIO)
    // =========================================================================

    /**
     * 1. Guest ditolak 401 saat mengunggah KeyPackage.
     */
    public function test_upload_key_packages_guest_is_rejected_with_401(): void
    {
        $response = $this->postJson('/api/bchat/key-packages', [
            'device_uuid' => (string) Str::uuid(),
            'packages'    => [
                [
                    'key_package_hash' => hash('sha256', 'test'),
                    'key_package_data' => base64_encode('test'),
                ],
            ],
        ]);

        $response->assertStatus(401);
    }

    /**
     * 2. Upload batch valid mendapat 201, data tersimpan atomik, dan hitungan pool aktif akurat.
     */
    public function test_upload_key_packages_valid_batch_returns_201_and_stores_packages(): void
    {
        $user = $this->createTestUser();
        $device = $this->createTestDevice($user);

        $pkg1 = $this->generateKeyPackageData();
        $pkg2 = $this->generateKeyPackageData();

        Sanctum::actingAs($user);

        $response = $this->postJson('/api/bchat/key-packages', [
            'device_uuid' => $device->device_uuid,
            'packages'    => [
                [
                    'key_package_hash' => $pkg1['key_package_hash'],
                    'key_package_data' => $pkg1['key_package_data'],
                ],
                [
                    'key_package_hash' => $pkg2['key_package_hash'],
                    'key_package_data' => $pkg2['key_package_data'],
                ],
            ],
        ]);

        $response->assertStatus(201);
        $response->assertJson([
            'status'  => 'success',
            'message' => 'KeyPackage berhasil diunggah.',
            'data'    => [
                'uploaded_count'    => 2,
                'device_uuid'       => $device->device_uuid,
                'active_pool_count' => 2,
            ],
        ]);

        $this->assertDatabaseHas('bchat_key_packages', [
            'user_id'          => $user->id,
            'device_id'        => $device->id,
            'key_package_hash' => $pkg1['key_package_hash'],
            'is_consumed'      => false,
        ]);

        $this->assertDatabaseHas('bchat_key_packages', [
            'user_id'          => $user->id,
            'device_id'        => $device->id,
            'key_package_hash' => $pkg2['key_package_hash'],
            'is_consumed'      => false,
        ]);
    }

    /**
     * 3. User ID selalu berasal dari token Sanctum pengguna yang login, mengabaikan manipulasi payload.
     */
    public function test_upload_key_packages_user_id_is_always_sourced_from_sanctum_user(): void
    {
        $user = $this->createTestUser();
        $spoofedUser = $this->createTestUser();
        $device = $this->createTestDevice($user);

        $pkg = $this->generateKeyPackageData();

        Sanctum::actingAs($user);

        $response = $this->postJson('/api/bchat/key-packages', [
            'user_id'     => $spoofedUser->id, // Percobaan injeksi user_id
            'device_uuid' => $device->device_uuid,
            'packages'    => [
                [
                    'key_package_hash' => $pkg['key_package_hash'],
                    'key_package_data' => $pkg['key_package_data'],
                ],
            ],
        ]);

        $response->assertStatus(201);

        $this->assertDatabaseHas('bchat_key_packages', [
            'user_id'          => $user->id,
            'device_id'        => $device->id,
            'key_package_hash' => $pkg['key_package_hash'],
        ]);

        $this->assertDatabaseMissing('bchat_key_packages', [
            'user_id'          => $spoofedUser->id,
            'key_package_hash' => $pkg['key_package_hash'],
        ]);
    }

    /**
     * 4. Device milik user lain ditolak 422 ACTIVE_DEVICE_NOT_FOUND.
     */
    public function test_upload_key_packages_device_owned_by_another_user_is_rejected_with_422(): void
    {
        $userA = $this->createTestUser();
        $userB = $this->createTestUser();
        $deviceB = $this->createTestDevice($userB);

        $pkg = $this->generateKeyPackageData();

        Sanctum::actingAs($userA);

        $response = $this->postJson('/api/bchat/key-packages', [
            'device_uuid' => $deviceB->device_uuid,
            'packages'    => [
                [
                    'key_package_hash' => $pkg['key_package_hash'],
                    'key_package_data' => $pkg['key_package_data'],
                ],
            ],
        ]);

        $response->assertStatus(422);
        $response->assertJson([
            'status'     => 'error',
            'error_code' => 'ACTIVE_DEVICE_NOT_FOUND',
        ]);
    }

    /**
     * 5. Device berstatus REVOKED ditolak 422 ACTIVE_DEVICE_NOT_FOUND.
     */
    public function test_upload_key_packages_revoked_device_is_rejected_with_422(): void
    {
        $user = $this->createTestUser();
        $revokedDevice = $this->createTestDevice($user, 'REVOKED');

        $pkg = $this->generateKeyPackageData();

        Sanctum::actingAs($user);

        $response = $this->postJson('/api/bchat/key-packages', [
            'device_uuid' => $revokedDevice->device_uuid,
            'packages'    => [
                [
                    'key_package_hash' => $pkg['key_package_hash'],
                    'key_package_data' => $pkg['key_package_data'],
                ],
            ],
        ]);

        $response->assertStatus(422);
        $response->assertJson([
            'status'     => 'error',
            'error_code' => 'ACTIVE_DEVICE_NOT_FOUND',
        ]);
    }

    /**
     * 6. Format Base64 tidak valid ditolak 422 INVALID_BASE64_ENCODING.
     */
    public function test_upload_key_packages_invalid_base64_is_rejected_with_422(): void
    {
        $user = $this->createTestUser();
        $device = $this->createTestDevice($user);

        Sanctum::actingAs($user);

        $response = $this->postJson('/api/bchat/key-packages', [
            'device_uuid' => $device->device_uuid,
            'packages'    => [
                [
                    'key_package_hash' => str_repeat('a', 64),
                    'key_package_data' => 'invalid_base64_!@#$',
                ],
            ],
        ]);

        $response->assertStatus(422);
        $response->assertJson([
            'status'     => 'error',
            'error_code' => 'INVALID_BASE64_ENCODING',
        ]);
    }

    /**
     * 7. Encoding Base64 non-canonical ditolak 422 NON_CANONICAL_BASE64.
     */
    public function test_upload_key_packages_non_canonical_base64_is_rejected_with_422(): void
    {
        $user = $this->createTestUser();
        $device = $this->createTestDevice($user);

        // 'QUK=' didekode menjadi 'AB', tetapi encoding kanonikal dari 'AB' adalah 'QUI='
        $nonCanonicalB64 = 'QUK=';
        $hash = hash('sha256', 'AB');

        Sanctum::actingAs($user);

        $response = $this->postJson('/api/bchat/key-packages', [
            'device_uuid' => $device->device_uuid,
            'packages'    => [
                [
                    'key_package_hash' => $hash,
                    'key_package_data' => $nonCanonicalB64,
                ],
            ],
        ]);

        $response->assertStatus(422);
        $response->assertJson([
            'status'     => 'error',
            'error_code' => 'NON_CANONICAL_BASE64',
        ]);
    }

    /**
     * 8. Ukuran paket biner melebihi batas 64 KiB (65.536 byte) ditolak 422 KEY_PACKAGE_SIZE_EXCEEDED.
     */
    public function test_upload_key_packages_exceeding_64_kib_is_rejected_with_422(): void
    {
        $user = $this->createTestUser();
        $device = $this->createTestDevice($user);

        // 65.537 byte: menghasilkan 87.384 karakter Base64 (lolos FormRequest max:87384, ditolak service)
        $oversizedBinary = str_repeat('A', 65537);
        $b64 = base64_encode($oversizedBinary);
        $hash = hash('sha256', $oversizedBinary);

        Sanctum::actingAs($user);

        $response = $this->postJson('/api/bchat/key-packages', [
            'device_uuid' => $device->device_uuid,
            'packages'    => [
                [
                    'key_package_hash' => $hash,
                    'key_package_data' => $b64,
                ],
            ],
        ]);

        $response->assertStatus(422);
        $response->assertJson([
            'status'     => 'error',
            'error_code' => 'KEY_PACKAGE_SIZE_EXCEEDED',
        ]);
    }

    /**
     * 9. Hash SHA-256 yang dihitung tidak cocok dengan key_package_hash ditolak 422 KEY_PACKAGE_HASH_MISMATCH.
     */
    public function test_upload_key_packages_sha256_hash_mismatch_is_rejected_with_422(): void
    {
        $user = $this->createTestUser();
        $device = $this->createTestDevice($user);
        $pkg = $this->generateKeyPackageData();
        $wrongHash = hash('sha256', 'completely_different_payload');

        Sanctum::actingAs($user);

        $response = $this->postJson('/api/bchat/key-packages', [
            'device_uuid' => $device->device_uuid,
            'packages'    => [
                [
                    'key_package_hash' => $wrongHash,
                    'key_package_data' => $pkg['key_package_data'],
                ],
            ],
        ]);

        $response->assertStatus(422);
        $response->assertJson([
            'status'     => 'error',
            'error_code' => 'KEY_PACKAGE_HASH_MISMATCH',
        ]);
    }

    /**
     * 10. Duplikat hash dalam batch yang sama ditolak 422 DUPLICATE_KEY_PACKAGE_IN_BATCH.
     */
    public function test_upload_key_packages_duplicate_hash_in_batch_is_rejected_with_422(): void
    {
        $user = $this->createTestUser();
        $device = $this->createTestDevice($user);
        $pkg = $this->generateKeyPackageData();

        Sanctum::actingAs($user);

        $response = $this->postJson('/api/bchat/key-packages', [
            'device_uuid' => $device->device_uuid,
            'packages'    => [
                [
                    'key_package_hash' => $pkg['key_package_hash'],
                    'key_package_data' => $pkg['key_package_data'],
                ],
                [
                    'key_package_hash' => $pkg['key_package_hash'],
                    'key_package_data' => $pkg['key_package_data'],
                ],
            ],
        ]);

        $response->assertStatus(422);
        $response->assertJson([
            'status'     => 'error',
            'error_code' => 'DUPLICATE_KEY_PACKAGE_IN_BATCH',
        ]);
    }

    /**
     * 11. Hash yang sudah terdaftar di database ditolak 409 KEY_PACKAGE_ALREADY_EXISTS.
     */
    public function test_upload_key_packages_already_existing_hash_in_db_is_rejected_with_409(): void
    {
        $user = $this->createTestUser();
        $device = $this->createTestDevice($user);
        $pkgExisting = $this->generateKeyPackageData();

        // Simpan paket pertama ke basis data
        $this->createActiveKeyPackage($user, $device, false, $pkgExisting['key_package_hash'], $pkgExisting['binary']);

        Sanctum::actingAs($user);

        // Unggah paket dengan hash yang sama
        $response = $this->postJson('/api/bchat/key-packages', [
            'device_uuid' => $device->device_uuid,
            'packages'    => [
                [
                    'key_package_hash' => $pkgExisting['key_package_hash'],
                    'key_package_data' => $pkgExisting['key_package_data'],
                ],
            ],
        ]);

        $response->assertStatus(409);
        $response->assertJson([
            'status'     => 'error',
            'error_code' => 'KEY_PACKAGE_ALREADY_EXISTS',
        ]);
    }

    /**
     * 12. Batch yang gagal tidak meninggalkan insert parsial (rollback atomik total).
     */
    public function test_upload_key_packages_batch_failure_rolls_back_atomically_without_partial_insert(): void
    {
        $user = $this->createTestUser();
        $device = $this->createTestDevice($user);

        $pkg1 = $this->generateKeyPackageData();
        $pkg2Existing = $this->generateKeyPackageData();

        // Pra-simpan paket kedua di database untuk memicu konflik 409
        $this->createActiveKeyPackage($user, $device, false, $pkg2Existing['key_package_hash'], $pkg2Existing['binary']);

        Sanctum::actingAs($user);

        // Unggah batch: paket 1 baru, paket 2 duplikat DB -> seluruh batch harus di-rollback
        $response = $this->postJson('/api/bchat/key-packages', [
            'device_uuid' => $device->device_uuid,
            'packages'    => [
                [
                    'key_package_hash' => $pkg1['key_package_hash'],
                    'key_package_data' => $pkg1['key_package_data'],
                ],
                [
                    'key_package_hash' => $pkg2Existing['key_package_hash'],
                    'key_package_data' => $pkg2Existing['key_package_data'],
                ],
            ],
        ]);

        $response->assertStatus(409);

        // Pastikan paket 1 TIDAK pernah masuk ke basis data
        $this->assertDatabaseMissing('bchat_key_packages', [
            'key_package_hash' => $pkg1['key_package_hash'],
        ]);
    }

    /**
     * 13. Batch lebih dari 10 paket ditolak 422 oleh validasi request.
     */
    public function test_upload_key_packages_batch_exceeding_10_packages_is_rejected_with_422(): void
    {
        $user = $this->createTestUser();
        $device = $this->createTestDevice($user);

        $packages = [];
        for ($i = 0; $i < 11; $i++) {
            $packages[] = $this->generateKeyPackageData();
        }

        Sanctum::actingAs($user);

        $response = $this->postJson('/api/bchat/key-packages', [
            'device_uuid' => $device->device_uuid,
            'packages'    => array_map(fn ($p) => [
                'key_package_hash' => $p['key_package_hash'],
                'key_package_data' => $p['key_package_data'],
            ], $packages),
        ]);

        $response->assertStatus(422);
    }

    /**
     * 14. Request dengan total ukuran payload melebihi 1 MiB ditolak 422 REQUEST_SIZE_EXCEEDED.
     */
    public function test_upload_key_packages_total_request_exceeding_1_mib_is_rejected_with_422(): void
    {
        $user = $this->createTestUser();
        $device = $this->createTestDevice($user);

        // Pengujian langsung ke domain service untuk memverifikasi batas transport 1 MiB
        $service = app(BchatKeyPackageService::class);

        $oversizedPackages = [
            [
                'key_package_hash' => str_repeat('a', 64),
                'key_package_data' => str_repeat('A', 1048577),
            ],
        ];

        $this->expectException(BchatKeyPackageException::class);
        try {
            $service->uploadKeyPackages($user, $device->device_uuid, $oversizedPackages);
        } catch (BchatKeyPackageException $e) {
            $this->assertEquals('REQUEST_SIZE_EXCEEDED', $e->getErrorCode());
            $this->assertEquals(422, $e->getHttpStatus());
            throw $e;
        }
    }

    // =========================================================================
    // BAGIAN 2: PENGUJIAN ENDPOINT FETCH PARTNER KEYPACKAGE (13 SKENARIO)
    // =========================================================================

    /**
     * 15. Guest ditolak 401 saat mencoba mengambil KeyPackage partner.
     */
    public function test_fetch_partner_key_package_guest_is_rejected_with_401(): void
    {
        $response = $this->getJson('/api/bchat/rooms/' . Str::uuid() . '/partner-key-package');
        $response->assertStatus(401);
    }

    /**
     * 16. Non-member ditolak 403 UNAUTHORIZED_NOT_MEMBER.
     */
    public function test_fetch_partner_key_package_non_member_is_rejected_with_403(): void
    {
        $mhs = $this->createTestUser('mahasiswa');
        $dos = $this->createTestUser('dosen');
        $outsider = $this->createTestUser('mahasiswa');
        $roomData = $this->createTestRoomWithMembers($mhs, $dos);

        Sanctum::actingAs($outsider);

        $response = $this->getJson("/api/bchat/rooms/{$roomData['room']->room_uuid}/partner-key-package");
        $response->assertStatus(403);
        $response->assertJson([
            'status'     => 'error',
            'error_code' => 'UNAUTHORIZED_NOT_MEMBER',
        ]);
    }

    /**
     * 17. Dosen penerima dilarang fetch sebelum Welcome diterbitkan (403 NOT_ROOM_INITIATOR).
     */
    public function test_fetch_partner_key_package_dosen_recipient_is_rejected_with_403_before_welcome(): void
    {
        $mhs = $this->createTestUser('mahasiswa');
        $dos = $this->createTestUser('dosen');
        $roomData = $this->createTestRoomWithMembers($mhs, $dos);

        Sanctum::actingAs($dos);

        $response = $this->getJson("/api/bchat/rooms/{$roomData['room']->room_uuid}/partner-key-package");
        $response->assertStatus(403);
        $response->assertJson([
            'status'     => 'error',
            'error_code' => 'NOT_ROOM_INITIATOR',
        ]);
    }

    /**
     * 18. Mahasiswa inisiator berhasil fetch KeyPackage dosen mitra (HTTP 200).
     */
    public function test_fetch_partner_key_package_mahasiswa_initiator_can_fetch_dosen_package_successfully(): void
    {
        $mhs = $this->createTestUser('mahasiswa');
        $dos = $this->createTestUser('dosen');
        $roomData = $this->createTestRoomWithMembers($mhs, $dos);
        $dosDevice = $this->createTestDevice($dos);
        $pkg = $this->generateKeyPackageData();
        $this->createActiveKeyPackage($dos, $dosDevice, false, $pkg['key_package_hash'], $pkg['binary']);

        Sanctum::actingAs($mhs);

        $response = $this->getJson("/api/bchat/rooms/{$roomData['room']->room_uuid}/partner-key-package");
        $response->assertStatus(200);
        $response->assertJson([
            'status'  => 'success',
            'message' => 'KeyPackage partner berhasil diambil.',
            'data'    => [
                'recipient_user_id'     => (string) $dos->id,
                'recipient_device_uuid' => $dosDevice->device_uuid,
                'key_package_hash'      => $pkg['key_package_hash'],
                'key_package_data'      => $pkg['key_package_data'],
            ],
        ]);
    }

    /**
     * 19. Penugasan pembimbing yang tidak aktif ditolak 403 ASSIGNMENT_INACTIVE.
     */
    public function test_fetch_partner_key_package_inactive_assignment_is_rejected_with_403(): void
    {
        $mhs = $this->createTestUser('mahasiswa');
        $dos = $this->createTestUser('dosen');
        $roomData = $this->createTestRoomWithMembers($mhs, $dos, 'DIBATALKAN');

        Sanctum::actingAs($mhs);

        $response = $this->getJson("/api/bchat/rooms/{$roomData['room']->room_uuid}/partner-key-package");
        $response->assertStatus(403);
        $response->assertJson([
            'status'     => 'error',
            'error_code' => 'ASSIGNMENT_INACTIVE',
        ]);
    }

    /**
     * 20. Ruang obrolan berstatus ACTIVE ditolak 409 INVALID_ROOM_STATUS.
     */
    public function test_fetch_partner_key_package_active_room_is_rejected_with_409(): void
    {
        $mhs = $this->createTestUser('mahasiswa');
        $dos = $this->createTestUser('dosen');
        $roomData = $this->createTestRoomWithMembers($mhs, $dos, 'AKTIF', 'ACTIVE', true, true);

        Sanctum::actingAs($mhs);

        $response = $this->getJson("/api/bchat/rooms/{$roomData['room']->room_uuid}/partner-key-package");
        $response->assertStatus(409);
        $response->assertJson([
            'status'     => 'error',
            'error_code' => 'INVALID_ROOM_STATUS',
        ]);
    }

    /**
     * 21. Adanya operasi Welcome yang sedang aktif ditolak 409 WELCOME_OPERATION_ALREADY_ACTIVE.
     */
    public function test_fetch_partner_key_package_active_welcome_operation_is_rejected_with_409(): void
    {
        $mhs = $this->createTestUser('mahasiswa');
        $dos = $this->createTestUser('dosen');
        $roomData = $this->createTestRoomWithMembers($mhs, $dos);
        $dosDevice = $this->createTestDevice($dos);

        BchatWelcomeChallenge::create([
            'welcome_operation_uuid' => (string) Str::uuid(),
            'room_id'                => $roomData['room']->id,
            'recipient_user_id'      => $dos->id,
            'recipient_device_id'    => $dosDevice->id,
            'welcome_message'        => 'dummy_binary',
            'group_id_hash'          => hash('sha256', 'group_ctx'),
            'epoch'                  => 1,
            'operation_status'       => 'ACTIVE',
            'challenge_uuid'         => (string) Str::uuid(),
            'challenge_nonce'        => bin2hex(random_bytes(32)),
            'challenge_generation'   => 1,
            'challenge_status'       => 'ISSUED',
            'expires_at'             => now()->addMinutes(5),
        ]);

        Sanctum::actingAs($mhs);

        $response = $this->getJson("/api/bchat/rooms/{$roomData['room']->room_uuid}/partner-key-package");
        $response->assertStatus(409);
        $response->assertJson([
            'status'     => 'error',
            'error_code' => 'WELCOME_OPERATION_ALREADY_ACTIVE',
        ]);
    }

    /**
     * 22. Mitra tanpa perangkat aktif ditolak 422 PARTNER_DEVICE_NOT_ACTIVE.
     */
    public function test_fetch_partner_key_package_partner_without_active_device_is_rejected_with_422(): void
    {
        $mhs = $this->createTestUser('mahasiswa');
        $dos = $this->createTestUser('dosen');
        $roomData = $this->createTestRoomWithMembers($mhs, $dos);

        Sanctum::actingAs($mhs);

        $response = $this->getJson("/api/bchat/rooms/{$roomData['room']->room_uuid}/partner-key-package");
        $response->assertStatus(422);
        $response->assertJson([
            'status'     => 'error',
            'error_code' => 'PARTNER_DEVICE_NOT_ACTIVE',
        ]);
    }

    /**
     * 23. Stok paket kunci kosong menghasilkan 404 KEY_PACKAGE_UNAVAILABLE.
     */
    public function test_fetch_partner_key_package_empty_stock_returns_404(): void
    {
        $mhs = $this->createTestUser('mahasiswa');
        $dos = $this->createTestUser('dosen');
        $roomData = $this->createTestRoomWithMembers($mhs, $dos);
        $this->createTestDevice($dos); // Perangkat aktif ada, tetapi tidak memiliki KeyPackage

        Sanctum::actingAs($mhs);

        $response = $this->getJson("/api/bchat/rooms/{$roomData['room']->room_uuid}/partner-key-package");
        $response->assertStatus(404);
        $response->assertJson([
            'status'     => 'error',
            'error_code' => 'KEY_PACKAGE_UNAVAILABLE',
        ]);
    }

    /**
     * 24. Operasi fetch murni read-only dan TIDAK mengubah status is_consumed pada KeyPackage.
     */
    public function test_fetch_partner_key_package_does_not_modify_is_consumed_flag(): void
    {
        $mhs = $this->createTestUser('mahasiswa');
        $dos = $this->createTestUser('dosen');
        $roomData = $this->createTestRoomWithMembers($mhs, $dos);
        $dosDevice = $this->createTestDevice($dos);
        $pkg = $this->generateKeyPackageData();
        $createdPkg = $this->createActiveKeyPackage($dos, $dosDevice, false, $pkg['key_package_hash'], $pkg['binary']);

        Sanctum::actingAs($mhs);

        $response = $this->getJson("/api/bchat/rooms/{$roomData['room']->room_uuid}/partner-key-package");
        $response->assertStatus(200);

        $createdPkg->refresh();
        $this->assertFalse((bool) $createdPkg->is_consumed, 'Fetch KeyPackage HARUS murni read-only dan tidak mengubah is_consumed.');
    }

    /**
     * 25. Operasi fetch murni read-only dan TIDAK mengisi atau mengubah consumed_at timestamp.
     */
    public function test_fetch_partner_key_package_does_not_modify_consumed_at_timestamp(): void
    {
        $mhs = $this->createTestUser('mahasiswa');
        $dos = $this->createTestUser('dosen');
        $roomData = $this->createTestRoomWithMembers($mhs, $dos);
        $dosDevice = $this->createTestDevice($dos);
        $pkg = $this->generateKeyPackageData();
        $createdPkg = $this->createActiveKeyPackage($dos, $dosDevice, false, $pkg['key_package_hash'], $pkg['binary']);

        Sanctum::actingAs($mhs);

        $response = $this->getJson("/api/bchat/rooms/{$roomData['room']->room_uuid}/partner-key-package");
        $response->assertStatus(200);

        $createdPkg->refresh();
        $this->assertNull($createdPkg->consumed_at, 'Fetch KeyPackage HARUS murni read-only dan tidak mengisi consumed_at.');
    }

    /**
     * 26. Respons fetch TIDAK mengekspos ID internal (primary key) tabel basis data.
     */
    public function test_fetch_partner_key_package_response_does_not_expose_internal_primary_keys(): void
    {
        $mhs = $this->createTestUser('mahasiswa');
        $dos = $this->createTestUser('dosen');
        $roomData = $this->createTestRoomWithMembers($mhs, $dos);
        $dosDevice = $this->createTestDevice($dos);
        $pkg = $this->generateKeyPackageData();
        $this->createActiveKeyPackage($dos, $dosDevice, false, $pkg['key_package_hash'], $pkg['binary']);

        Sanctum::actingAs($mhs);

        $response = $this->getJson("/api/bchat/rooms/{$roomData['room']->room_uuid}/partner-key-package");
        $response->assertStatus(200);

        $data = $response->json('data');
        $this->assertArrayNotHasKey('id', $data);
        $this->assertArrayNotHasKey('device_id', $data);
        $this->assertArrayNotHasKey('room_id', $data);
        $this->assertArrayNotHasKey('user_id', $data);
    }

    /**
     * 27. Field recipient_user_id dikirimkan secara aman sebagai string desimal.
     */
    public function test_fetch_partner_key_package_recipient_user_id_is_serialized_as_decimal_string(): void
    {
        $mhs = $this->createTestUser('mahasiswa');
        $dos = $this->createTestUser('dosen');
        $roomData = $this->createTestRoomWithMembers($mhs, $dos);
        $dosDevice = $this->createTestDevice($dos);
        $pkg = $this->generateKeyPackageData();
        $this->createActiveKeyPackage($dos, $dosDevice, false, $pkg['key_package_hash'], $pkg['binary']);

        Sanctum::actingAs($mhs);

        $response = $this->getJson("/api/bchat/rooms/{$roomData['room']->room_uuid}/partner-key-package");
        $response->assertStatus(200);

        $recipientUserId = $response->json('data.recipient_user_id');
        $this->assertIsString($recipientUserId);
        $this->assertEquals((string) $dos->id, $recipientUserId);
        $this->assertMatchesRegularExpression('/^[0-9]+$/', $recipientUserId);
    }

    // =========================================================================
    // BAGIAN 3: PENGUJIAN INTEGRASI LIFECYCLE (2 SKENARIO)
    // =========================================================================

    /**
     * 28. Managed Replacement perangkat: KeyPackage perangkat lama REVOKED diabaikan,
     * dan fetch memilih KeyPackage milik perangkat baru yang ACTIVE.
     */
    public function test_lifecycle_managed_replacement_renders_old_device_packages_unfetchable(): void
    {
        $mhs = $this->createTestUser('mahasiswa');
        $dos = $this->createTestUser('dosen');
        $roomData = $this->createTestRoomWithMembers($mhs, $dos);

        // Perangkat lama direvoke (simulasi Managed Replacement pada BchatDeviceService)
        $oldDevice = $this->createTestDevice($dos, 'REVOKED');
        $pkgOld = $this->generateKeyPackageData();
        $this->createActiveKeyPackage($dos, $oldDevice, false, $pkgOld['key_package_hash'], $pkgOld['binary']);

        // Perangkat baru aktif terdaftar
        $newDevice = $this->createTestDevice($dos, 'ACTIVE');
        $pkgNew = $this->generateKeyPackageData();
        $this->createActiveKeyPackage($dos, $newDevice, false, $pkgNew['key_package_hash'], $pkgNew['binary']);

        Sanctum::actingAs($mhs);

        $response = $this->getJson("/api/bchat/rooms/{$roomData['room']->room_uuid}/partner-key-package");
        $response->assertStatus(200);

        // Paket yang diambil HARUS terikat pada perangkat baru ACTIVE, bukan perangkat lama
        $this->assertEquals($newDevice->device_uuid, $response->json('data.recipient_device_uuid'));
        $this->assertEquals($pkgNew['key_package_hash'], $response->json('data.key_package_hash'));
    }

    /**
     * 29. KeyPackage yang telah dikonsumsi (is_consumed=true) dilewati,
     * dan fetch mengambil paket berikutnya yang masih tersedia (is_consumed=false) secara id ASC.
     */
    public function test_lifecycle_consumed_key_package_cannot_be_fetched_again_and_advances_to_next(): void
    {
        $mhs = $this->createTestUser('mahasiswa');
        $dos = $this->createTestUser('dosen');
        $roomData = $this->createTestRoomWithMembers($mhs, $dos);
        $dosDevice = $this->createTestDevice($dos);

        $pkg1Consumed = $this->generateKeyPackageData();
        $pkg2Available = $this->generateKeyPackageData();

        // Paket 1 diset sudah dikonsumsi
        $this->createActiveKeyPackage($dos, $dosDevice, true, $pkg1Consumed['key_package_hash'], $pkg1Consumed['binary']);
        // Paket 2 belum dikonsumsi
        $this->createActiveKeyPackage($dos, $dosDevice, false, $pkg2Available['key_package_hash'], $pkg2Available['binary']);

        Sanctum::actingAs($mhs);

        $response = $this->getJson("/api/bchat/rooms/{$roomData['room']->room_uuid}/partner-key-package");
        $response->assertStatus(200);

        // Harus mengambil paket 2 (tertua yang belum dikonsumsi)
        $this->assertEquals($pkg2Available['key_package_hash'], $response->json('data.key_package_hash'));
    }
}

