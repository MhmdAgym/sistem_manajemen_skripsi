<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\BchatKeyPackageException;
use App\Models\BchatKeyPackage;
use App\Models\BchatRoom;
use App\Models\BchatRoomMember;
use App\Models\BchatUserDevice;
use App\Models\BchatWelcomeChallenge;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

class BchatKeyPackageService
{
    /**
     * Batasan batch pengunggahan KeyPackage.
     */
    public const MIN_BATCH_SIZE = 1;
    public const MAX_BATCH_SIZE = 10;

    /**
     * Batasan ukuran payload KeyPackage.
     */
    public const MAX_KEY_PACKAGE_BYTES = 65536; // 64 KiB biner
    public const MAX_PACKAGE_BASE64_CHARS = 87384; // ceil(65536 / 3) * 4
    public const MAX_TOTAL_REQUEST_BYTES = 1048576; // 1 MiB

    /**
     * Mengunggah batch KeyPackage MLS publik untuk perangkat aktif pengguna.
     * Operasi bersifat all-or-nothing (atomik) dengan hierarki penguncian:
     * 1. users (berdasarkan ID)
     * 2. bchat_user_devices (berdasarkan user_id & device_uuid)
     * 3. bchat_key_packages (pengecekan keunikan hash)
     *
     * @param User $user Pengguna terotentikasi via Sanctum
     * @param string $deviceUuid UUID v4 perangkat target
     * @param array<int, array<string, mixed>> $packages Daftar KeyPackage yang diunggah
     * @return array<string, mixed> Metadata hasil pengunggahan
     *
     * @throws BchatKeyPackageException Jika validasi gagal, terjadi konflik, atau kesalahan transaksi
     */
    public function uploadKeyPackages(User $user, string $deviceUuid, array $packages): array
    {
        // 1. Validasi batas ukuran total payload string Base64
        $totalBytes = 0;
        foreach ($packages as $pkg) {
            if (isset($pkg['key_package_data']) && is_string($pkg['key_package_data'])) {
                $totalBytes += strlen($pkg['key_package_data']);
            }
        }

        if ($totalBytes > self::MAX_TOTAL_REQUEST_BYTES) {
            throw new BchatKeyPackageException(
                'Total ukuran data KeyPackage melebihi batas 1 MiB.',
                'REQUEST_SIZE_EXCEEDED',
                422
            );
        }

        // 2. Validasi jumlah batch KeyPackage
        $packageCount = count($packages);
        if ($packageCount < self::MIN_BATCH_SIZE) {
            throw new BchatKeyPackageException(
                'Minimal harus mengunggah 1 KeyPackage.',
                'INVALID_PACKAGE_COUNT',
                422
            );
        }

        if ($packageCount > self::MAX_BATCH_SIZE) {
            throw new BchatKeyPackageException(
                'Maksimal hanya dapat mengunggah 10 KeyPackage per batch.',
                'PACKAGE_COUNT_EXCEEDED',
                422
            );
        }

        // 3. Validasi format Device UUID
        $cleanDeviceUuid = strtolower(trim($deviceUuid));
        if (!Str::isUuid($cleanDeviceUuid)) {
            throw new BchatKeyPackageException(
                'Format Device UUID harus berupa UUID v4 yang valid.',
                'INVALID_DEVICE_UUID',
                422
            );
        }

        // 4. Validasi struktur tiap paket, keunikan in-batch, Base64 kanonikal, ukuran biner, dan hash SHA-256
        $seenHashes = [];
        $preparedPackages = [];

        foreach ($packages as $index => $pkg) {
            if (!is_array($pkg)) {
                throw new BchatKeyPackageException(
                    "Item paket pada indeks {$index} harus berupa array/objek valid.",
                    'INVALID_PACKAGE_FORMAT',
                    422
                );
            }

            $hash = strtolower(trim((string) ($pkg['key_package_hash'] ?? '')));
            if (!preg_match('/^[0-9a-f]{64}$/', $hash)) {
                throw new BchatKeyPackageException(
                    "Format hash pada paket indeks {$index} harus berupa 64 karakter lowercase hex.",
                    'INVALID_HASH_FORMAT',
                    422
                );
            }

            // Deteksi duplikasi hash dalam batch yang sama
            if (isset($seenHashes[$hash])) {
                throw new BchatKeyPackageException(
                    'Terdapat hash KeyPackage duplikat dalam batch pengunggahan.',
                    'DUPLICATE_KEY_PACKAGE_IN_BATCH',
                    422
                );
            }
            $seenHashes[$hash] = true;

            $rawBase64 = (string) ($pkg['key_package_data'] ?? '');
            if ($rawBase64 === '') {
                throw new BchatKeyPackageException(
                    "Data KeyPackage pada indeks {$index} tidak boleh kosong.",
                    'EMPTY_KEY_PACKAGE_DATA',
                    422
                );
            }

            if (strlen($rawBase64) > self::MAX_PACKAGE_BASE64_CHARS) {
                throw new BchatKeyPackageException(
                    "Ukuran string Base64 paket pada indeks {$index} melebihi batas maksimum 87.384 karakter.",
                    'KEY_PACKAGE_SIZE_EXCEEDED',
                    422
                );
            }

            // Dekode Base64 secara ketat (RFC 4648)
            $decodedBinary = base64_decode($rawBase64, true);
            if ($decodedBinary === false) {
                throw new BchatKeyPackageException(
                    "Format Base64 pada paket indeks {$index} tidak valid.",
                    'INVALID_BASE64_ENCODING',
                    422
                );
            }

            // Validasi kanonisitas encoding Base64
            if (base64_encode($decodedBinary) !== $rawBase64) {
                throw new BchatKeyPackageException(
                    "Encoding Base64 pada paket indeks {$index} tidak kanonis.",
                    'NON_CANONICAL_BASE64',
                    422
                );
            }

            $binaryLength = strlen($decodedBinary);
            if ($binaryLength === 0) {
                throw new BchatKeyPackageException(
                    "Data biner KeyPackage pada indeks {$index} kosong.",
                    'EMPTY_KEY_PACKAGE_DATA',
                    422
                );
            }

            if ($binaryLength > self::MAX_KEY_PACKAGE_BYTES) {
                throw new BchatKeyPackageException(
                    "Ukuran biner KeyPackage pada indeks {$index} melebihi batas 65.536 byte (64 KiB).",
                    'KEY_PACKAGE_SIZE_EXCEEDED',
                    422
                );
            }

            // Verifikasi integritas hash SHA-256
            $calculatedHash = hash('sha256', $decodedBinary);
            if (!hash_equals($calculatedHash, $hash)) {
                throw new BchatKeyPackageException(
                    "Hash SHA-256 hasil decoding tidak cocok dengan key_package_hash pada indeks {$index}.",
                    'KEY_PACKAGE_HASH_MISMATCH',
                    422
                );
            }

            $preparedPackages[] = [
                'hash'   => $hash,
                'binary' => $decodedBinary,
                'data'   => $rawBase64,
            ];
        }

        // 5. Transaksi basis data dengan penguncian hierarki dan penanganan konkurensi (maks 3 retry)
        try {
            return DB::transaction(function () use ($user, $cleanDeviceUuid, $preparedPackages) {
                // Hierarki 1: Kunci baris user berdasarkan ID
                $lockedUser = User::where('id', $user->id)->lockForUpdate()->first();
                if (!$lockedUser) {
                    throw new BchatKeyPackageException(
                        'Pengguna tidak ditemukan.',
                        'USER_NOT_FOUND',
                        404
                    );
                }

                // Hierarki 2: Kunci bchat_user_devices milik pengguna yang berstatus ACTIVE
                $device = BchatUserDevice::where('user_id', $user->id)
                    ->where('device_uuid', $cleanDeviceUuid)
                    ->lockForUpdate()
                    ->first();

                if (!$device || $device->status !== 'ACTIVE') {
                    throw new BchatKeyPackageException(
                        'Perangkat aktif tidak ditemukan untuk pengguna ini.',
                        'ACTIVE_DEVICE_NOT_FOUND',
                        422
                    );
                }

                if (!$device->is_trusted) {
                    throw new BchatKeyPackageException(
                        'Perangkat belum berstatus tepercaya. Lakukan pairing terlebih dahulu sebelum mengunggah KeyPackage.',
                        'DEVICE_NOT_TRUSTED',
                        403
                    );
                }

                // Hierarki 3: Kunci bchat_key_packages untuk memeriksa apakah hash sudah terdaftar sebelumnya
                $hashes = array_column($preparedPackages, 'hash');
                $existing = BchatKeyPackage::whereIn('key_package_hash', $hashes)
                    ->lockForUpdate()
                    ->first();

                if ($existing) {
                    throw new BchatKeyPackageException(
                        'Satu atau lebih KeyPackage sudah pernah didaftarkan sebelumnya.',
                        'KEY_PACKAGE_ALREADY_EXISTS',
                        409
                    );
                }

                // Insert atomik batch KeyPackage
                $now = now();
                $rows = [];
                foreach ($preparedPackages as $pkg) {
                    $rows[] = [
                        'user_id'          => $user->id,
                        'device_id'        => $device->id,
                        'key_package_hash' => $pkg['hash'],
                        'key_package_data' => $pkg['binary'],
                        'is_consumed'      => false,
                        'consumed_at'      => null,
                        'created_at'       => $now,
                        'updated_at'       => $now,
                    ];
                }

                BchatKeyPackage::insert($rows);

                // Hitung sisa stok paket aktif untuk perangkat ini dalam transaksi yang sama
                $activePoolCount = BchatKeyPackage::where('device_id', $device->id)
                    ->where('is_consumed', false)
                    ->count();

                return [
                    'uploaded_count'            => count($rows),
                    'device_uuid'               => $cleanDeviceUuid,
                    'active_pool_count'         => $activePoolCount,
                    'remaining_active_packages' => $activePoolCount,
                ];
            }, 3);
        } catch (BchatKeyPackageException $e) {
            throw $e;
        } catch (QueryException $e) {
            $sqlState = (string) $e->getCode();
            $errorCode = $e->errorInfo[1] ?? 0;
            $errorMessage = $e->getMessage();

            // MySQL 1062: Duplicate entry
            if ($errorCode === 1062 || $sqlState === '23000') {
                if (
                    str_contains($errorMessage, 'key_package_hash')
                    || str_contains($errorMessage, 'bchat_key_packages_key_package_hash_unique')
                ) {
                    throw new BchatKeyPackageException(
                        'KeyPackage hash sudah terdaftar sebelumnya.',
                        'KEY_PACKAGE_ALREADY_EXISTS',
                        409,
                        $e
                    );
                }

                throw new BchatKeyPackageException(
                    'Pelanggaran constraint unik basis data saat mengunggah KeyPackage.',
                    'UNIQUE_CONSTRAINT_VIOLATION',
                    409,
                    $e
                );
            }

            // Deadlock / Lock wait timeout: MySQL 1205, 1213, SQLSTATE 40001
            if (in_array($errorCode, [1205, 1213], true) || $sqlState === '40001') {
                throw new BchatKeyPackageException(
                    'Terjadi konflik konkurensi kunci basis data. Silakan coba kembali.',
                    'CONCURRENCY_LOCK_CONFLICT',
                    409,
                    $e
                );
            }

            throw new BchatKeyPackageException(
                'Terjadi kesalahan query basis data: ' . $e->getMessage(),
                'DATABASE_ERROR',
                500,
                $e
            );
        } catch (Throwable $e) {
            throw new BchatKeyPackageException(
                'Gagal memproses pengunggahan KeyPackage: ' . $e->getMessage(),
                'KEY_PACKAGE_UPLOAD_FAILED',
                500,
                $e
            );
        }
    }

    /**
     * Mengambil satu KeyPackage tertua yang belum dikonsumsi milik mitra bimbingan (partner).
     * Operasi bersifat murni READ-ONLY (tanpa reservasi, tanpa pengubahan status konsumsi).
     *
     * @param User $user Pengguna pemanggil (inisiator bimbingan)
     * @param string $roomUuid UUID v4 ruang obrolan bimbingan
     * @return array<string, string> Data paket kunci mitra terenkode Base64
     *
     * @throws BchatKeyPackageException Jika otorisasi atau validasi kondisi ruang obrolan gagal
     */
    public function fetchPartnerKeyPackage(User $user, string $roomUuid): array
    {
        // 1. Validasi format Room UUID
        $cleanRoomUuid = strtolower(trim($roomUuid));
        if (!Str::isUuid($cleanRoomUuid)) {
            throw new BchatKeyPackageException(
                'Format Room UUID harus berupa UUID v4 yang valid.',
                'INVALID_ROOM_UUID',
                422
            );
        }

        // 2. Cari room beserta relasi keanggotaan dan penugasan akademik
        $room = BchatRoom::with(['members', 'penugasanPembimbing'])
            ->where('room_uuid', $cleanRoomUuid)
            ->first();

        if (!$room) {
            throw new BchatKeyPackageException(
                'Ruang obrolan tidak ditemukan.',
                'ROOM_NOT_FOUND',
                404
            );
        }

        // 3. Verifikasi keanggotaan pemanggil dalam ruang obrolan
        $members = $room->members;
        $callerMember = $members->firstWhere('user_id', $user->id);
        if (!$callerMember) {
            throw new BchatKeyPackageException(
                'Pengguna bukan anggota dari ruang obrolan ini.',
                'UNAUTHORIZED_NOT_MEMBER',
                403
            );
        }

        // 4. Verifikasi status keaktifan penugasan akademik induk
        $penugasan = $room->penugasanPembimbing;
        if (!$penugasan || $penugasan->status_penugasan !== 'AKTIF') {
            throw new BchatKeyPackageException(
                'Penugasan pembimbing akademik untuk ruang obrolan ini tidak aktif.',
                'ASSIGNMENT_INACTIVE',
                403
            );
        }

        // 5. Verifikasi status lifecycle room (diizinkan pada PENDING_WELCOME_ACK atau room ACTIVE dengan sesi PENDING)
        $hasPendingSession = $room->sessions()->where('status', 'PENDING')->exists();
        if ($room->status !== 'PENDING_WELCOME_ACK' && !($room->status === 'ACTIVE' && $hasPendingSession)) {
            throw new BchatKeyPackageException(
                'Pengambilan KeyPackage partner hanya diizinkan pada ruang obrolan berstatus PENDING_WELCOME_ACK atau memiliki sesi generasi baru berstatus PENDING.',
                'INVALID_ROOM_STATUS',
                409
            );
        }

        // 6. Verifikasi keanggotaan pairwise (harus tepat dua anggota)
        if ($members->count() !== 2) {
            throw new BchatKeyPackageException(
                'Ruang obrolan pairwise wajib memiliki tepat dua anggota.',
                'INVALID_ROOM_MEMBERSHIP',
                422
            );
        }

        $partnerMember = $members->first(fn ($m) => (int) $m->user_id !== (int) $user->id);
        if (!$partnerMember) {
            throw new BchatKeyPackageException(
                'Anggota mitra bimbingan tidak ditemukan dalam ruang obrolan.',
                'PARTNER_NOT_FOUND',
                422
            );
        }

        // 7. Verifikasi ketiadaan operasi Welcome yang sedang aktif untuk sesi terkait
        $activeChallengeQuery = BchatWelcomeChallenge::where('room_id', $room->id)
            ->where('operation_status', 'ACTIVE');
        if ($hasPendingSession) {
            $pendingSession = $room->sessions()->where('status', 'PENDING')->first();
            $activeChallengeQuery->where('session_id', $pendingSession?->id);
        }
        $activeChallenge = $activeChallengeQuery->first();

        if ($activeChallenge) {
            throw new BchatKeyPackageException(
                'Operasi Welcome sedang aktif pada ruang obrolan ini.',
                'WELCOME_OPERATION_ALREADY_ACTIVE',
                409
            );
        }

        // 8. Otorisasi peran inisiator: pada inisiasi proposal, mahasiswa adalah inisiator default
        // Dosen sebagai penerima default dilarang mengambil paket sebelum Welcome diterbitkan
        if ($callerMember->role !== 'mahasiswa') {
            throw new BchatKeyPackageException(
                'Hanya inisiator ruang obrolan (mahasiswa) yang diizinkan mengambil KeyPackage partner.',
                'NOT_ROOM_INITIATOR',
                403
            );
        }

        // 8b. Verifikasi perangkat aktif milik pemanggil (inisiator)
        $callerDeviceQuery = BchatUserDevice::where('user_id', $user->id);
        $requestDeviceUuid = request()->header('X-Bchat-Device-Uuid');
        if ($requestDeviceUuid !== null && trim($requestDeviceUuid) !== '') {
            $callerDeviceQuery->where('device_uuid', strtolower(trim($requestDeviceUuid)));
        }
        $callerDevice = $callerDeviceQuery->where('status', 'ACTIVE')->first();

        if (!$callerDevice && BchatUserDevice::where('user_id', $user->id)->exists()) {
            throw new BchatKeyPackageException(
                'Perangkat pemanggil tidak aktif atau telah dicabut (REVOKED).',
                'CALLER_DEVICE_NOT_ACTIVE',
                403
            );
        }

        if ($callerDevice && !$callerDevice->is_trusted) {
            throw new BchatKeyPackageException(
                'Perangkat pemanggil belum berstatus tepercaya. Lakukan pairing terlebih dahulu.',
                'DEVICE_NOT_TRUSTED',
                403
            );
        }

        // 9. Verifikasi ketersediaan perangkat aktif dan tepercaya milik mitra
        $partnerId = (int) $partnerMember->user_id;
        $partnerDevice = BchatUserDevice::where('user_id', $partnerId)
            ->where('status', 'ACTIVE')
            ->where('is_trusted', true)
            ->whereHas('keyPackages', fn ($q) => $q->where('is_consumed', false))
            ->first()
            ?? BchatUserDevice::where('user_id', $partnerId)
                ->where('status', 'ACTIVE')
                ->where('is_trusted', true)
                ->first()
            ?? BchatUserDevice::where('user_id', $partnerId)
                ->where('status', 'ACTIVE')
                ->first();

        if (!$partnerDevice) {
            throw new BchatKeyPackageException(
                'Perangkat aktif mitra bimbingan tidak ditemukan.',
                'PARTNER_DEVICE_NOT_ACTIVE',
                422
            );
        }

        // 10. Ambil satu KeyPackage tertua yang belum dikonsumsi milik mitra (read-only)
        $keyPackage = BchatKeyPackage::where('user_id', $partnerId)
            ->where('device_id', $partnerDevice->id)
            ->where('is_consumed', false)
            ->orderBy('id', 'asc')
            ->first();

        if (!$keyPackage) {
            throw new BchatKeyPackageException(
                'Tidak ada KeyPackage tersedia untuk perangkat mitra.',
                'KEY_PACKAGE_UNAVAILABLE',
                404
            );
        }

        // 11. Normalisasi data biner/string ke Base64 kanonikal untuk respons API
        $rawBlob = $keyPackage->key_package_data;
        if (is_resource($rawBlob)) {
            $rawBlob = (string) stream_get_contents($rawBlob);
        }
        $rawBlob = (string) $rawBlob;

        if (hash_equals(hash('sha256', $rawBlob), (string) $keyPackage->key_package_hash)) {
            $base64Data = base64_encode($rawBlob);
        } elseif (($decoded = base64_decode($rawBlob, true)) !== false && hash_equals(hash('sha256', $decoded), (string) $keyPackage->key_package_hash)) {
            $base64Data = $rawBlob;
        } else {
            $base64Data = base64_encode($rawBlob);
        }

        // Menjaga privasi: tidak mengekspos primary key internal basis data
        return [
            'recipient_user_id'     => (string) $partnerId,
            'recipient_device_uuid' => (string) $partnerDevice->device_uuid,
            'key_package_hash'      => (string) $keyPackage->key_package_hash,
            'key_package_data'      => $base64Data,
        ];
    }

    /**
     * Mengambil satu KeyPackage aktif milik perangkat spesifik anggota room (untuk Multi-Device Commit Relay).
     */
    public function fetchDeviceKeyPackage(User $user, string $roomUuid, string $deviceUuid): array
    {
        $cleanRoomUuid = strtolower(trim($roomUuid));
        if (!Str::isUuid($cleanRoomUuid)) {
            throw new BchatKeyPackageException('Format Room UUID tidak valid.', 'INVALID_ROOM_UUID', 422);
        }

        $cleanDeviceUuid = strtolower(trim($deviceUuid));
        if (!Str::isUuid($cleanDeviceUuid)) {
            throw new BchatKeyPackageException('Format Device UUID tidak valid.', 'INVALID_DEVICE_UUID', 422);
        }

        $room = BchatRoom::where('room_uuid', $cleanRoomUuid)->first();
        if (!$room) {
            throw new BchatKeyPackageException('Ruang obrolan tidak ditemukan.', 'ROOM_NOT_FOUND', 404);
        }

        $isCallerMember = BchatRoomMember::where('room_id', $room->id)
            ->where('user_id', $user->id)
            ->where('is_active', true)
            ->exists();
        if (!$isCallerMember) {
            throw new BchatKeyPackageException('Pengguna bukan anggota aktif pada ruang obrolan ini.', 'UNAUTHORIZED_NOT_MEMBER', 403);
        }

        $targetDevice = BchatUserDevice::where('device_uuid', $cleanDeviceUuid)->first();
        if (!$targetDevice || $targetDevice->status !== 'ACTIVE' || !$targetDevice->is_trusted) {
            throw new BchatKeyPackageException('Perangkat target tidak aktif atau belum berstatus tepercaya.', 'DEVICE_NOT_TRUSTED', 403);
        }

        $isTargetMember = BchatRoomMember::where('room_id', $room->id)
            ->where('user_id', $targetDevice->user_id)
            ->where('is_active', true)
            ->exists();
        if (!$isTargetMember) {
            throw new BchatKeyPackageException('Pemilik perangkat target bukan anggota aktif ruang obrolan.', 'TARGET_DEVICE_NOT_ROOM_MEMBER', 403);
        }

        $kp = BchatKeyPackage::where('device_id', $targetDevice->id)
            ->where('is_consumed', false)
            ->orderBy('id', 'asc')
            ->first();

        if (!$kp) {
            throw new BchatKeyPackageException('Tidak ada KeyPackage aktif yang tersedia untuk perangkat ini.', 'NO_KEY_PACKAGE_AVAILABLE', 404);
        }

        $rawBlob = is_resource($kp->key_package_data) ? stream_get_contents($kp->key_package_data) : $kp->key_package_data;

        return [
            'device_uuid'      => $targetDevice->device_uuid,
            'user_id'          => (string) $targetDevice->user_id,
            'key_package_hash' => (string) $kp->key_package_hash,
            'key_package_data' => base64_encode((string) $rawBlob),
        ];
    }
}

