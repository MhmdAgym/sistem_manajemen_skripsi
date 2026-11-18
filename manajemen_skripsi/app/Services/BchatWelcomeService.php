<?php

namespace App\Services;

use App\Exceptions\BchatWelcomeException;
use App\Models\BchatKeyPackage;
use App\Models\BchatRoom;
use App\Models\BchatRoomMember;
use App\Models\BchatUserDevice;
use App\Models\BchatWelcomeChallenge;
use App\Models\PenugasanPembimbing;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

class BchatWelcomeService
{
    /**
     * Batas ukuran maksimum payload biner Welcome message terenkripsi HPKE (256 KB = 262.144 byte).
     */
    public const MAX_WELCOME_MESSAGE_BYTES = 262144;

    /**
     * Masa berlaku (TTL) tantangan Welcome Challenge generasi aktif (5 menit).
     */
    public const CHALLENGE_TTL_MINUTES = 5;

    /**
     * Target epoch wajib untuk Welcome pertama pembentukan grup MLS (tepat 1).
     */
    public const TARGET_EPOCH = 1;

    /**
     * Batas maksimum nomor generasi tantangan (uint32 maximum: 4.294.967.295).
     */
    public const MAX_GENERATION = 4294967295;

    /**
     * Memublikasikan paket Welcome MLS ke dalam ruang bimbingan oleh inisiator.
     *
     * @param User $initiatorUser Pengguna inisiator pembentuk grup MLS
     * @param string $roomUuid UUID publik ruang obrolan (36 karakter)
     * @param array<string, mixed> $payloadData Data input publikasi (recipient_device_uuid, key_package_hash, group_id_hash, epoch, welcome_message)
     * @return array<string, mixed> Metadata konfirmasi publikasi Welcome
     *
     * @throws BchatWelcomeException Jika validasi, otorisasi, integritas, atau konkurensi gagal
     */
    public function publishWelcome(
        User $initiatorUser,
        string $roomUuid,
        array $payloadData
    ): array {
        // ---------------------------------------------------------------------
        // 1. VALIDASI FORMAT DASAR PARAMETER INPUT
        // ---------------------------------------------------------------------
        $cleanRoomUuid = strtolower(trim($roomUuid));
        if (!Str::isUuid($cleanRoomUuid)) {
            throw new BchatWelcomeException(
                'Format Room UUID tidak valid (wajib berupa UUID v4 36 karakter).',
                'INVALID_ROOM_UUID',
                422
            );
        }

        $recipientDeviceUuid = strtolower(trim((string) ($payloadData['recipient_device_uuid'] ?? '')));
        if (!Str::isUuid($recipientDeviceUuid)) {
            throw new BchatWelcomeException(
                'Format Recipient Device UUID tidak valid (wajib berupa UUID v4 36 karakter).',
                'INVALID_DEVICE_UUID',
                422
            );
        }

        $keyPackageHash = strtolower(trim((string) ($payloadData['key_package_hash'] ?? '')));
        if (strlen($keyPackageHash) !== 64 || !ctype_xdigit($keyPackageHash)) {
            throw new BchatWelcomeException(
                'KeyPackage hash tidak valid (wajib berupa string heksadesimal 64 karakter).',
                'INVALID_KEY_PACKAGE_HASH',
                422
            );
        }

        $groupIdHash = strtolower(trim((string) ($payloadData['group_id_hash'] ?? '')));
        if (strlen($groupIdHash) !== 64 || !ctype_xdigit($groupIdHash)) {
            throw new BchatWelcomeException(
                'Group ID hash tidak valid (wajib berupa string heksadesimal 64 karakter).',
                'INVALID_GROUP_ID_HASH',
                422
            );
        }

        $epoch = $payloadData['epoch'] ?? null;
        if ($epoch !== self::TARGET_EPOCH && $epoch !== (string) self::TARGET_EPOCH) {
            throw new BchatWelcomeException(
                'Epoch target Welcome pertama wajib tepat bernilai 1.',
                'INVALID_EPOCH',
                422
            );
        }

        $welcomeMessageBase64 = $payloadData['welcome_message'] ?? null;
        if (!is_string($welcomeMessageBase64) || $welcomeMessageBase64 === '') {
            throw new BchatWelcomeException(
                'Welcome message payload wajib diisi.',
                'MISSING_WELCOME_MESSAGE',
                422
            );
        }

        // Dekode Base64 secara ketat (RFC 4648)
        $binaryPayload = base64_decode($welcomeMessageBase64, true);
        if ($binaryPayload === false) {
            throw new BchatWelcomeException(
                'Format Base64 Welcome message tidak valid.',
                'INVALID_BASE64_ENCODING',
                422
            );
        }

        // Validasi kanonisitas encoding Base64
        if (base64_encode($binaryPayload) !== $welcomeMessageBase64) {
            throw new BchatWelcomeException(
                'Encoding Base64 Welcome message tidak kanonis.',
                'NON_CANONICAL_BASE64',
                422
            );
        }

        $payloadLength = strlen($binaryPayload);
        if ($payloadLength === 0) {
            throw new BchatWelcomeException(
                'Payload Welcome message tidak boleh kosong.',
                'EMPTY_PAYLOAD',
                422
            );
        }

        if ($payloadLength > self::MAX_WELCOME_MESSAGE_BYTES) {
            throw new BchatWelcomeException(
                'Ukuran payload Welcome message setelah didekode melebihi batas maksimum 262.144 byte.',
                'PAYLOAD_SIZE_EXCEEDED',
                422
            );
        }

        // ---------------------------------------------------------------------
        // 2. DISCOVERY & VALIDASI ENTITAS SEBELUM PENGUNCIAN HIERARKI
        // ---------------------------------------------------------------------
        $preRoom = BchatRoom::with(['members', 'penugasanPembimbing'])
            ->where('room_uuid', $cleanRoomUuid)
            ->first();

        if (!$preRoom) {
            throw new BchatWelcomeException(
                'Ruang obrolan tidak ditemukan.',
                'ROOM_NOT_FOUND',
                404
            );
        }

        if ($preRoom->status !== 'PENDING_WELCOME_ACK') {
            throw new BchatWelcomeException(
                'Publikasi Welcome hanya diizinkan pada room berstatus PENDING_WELCOME_ACK.',
                'INVALID_ROOM_STATUS',
                409
            );
        }

        if ((int) $preRoom->current_epoch !== 0) {
            throw new BchatWelcomeException(
                'Epoch ruang obrolan harus bernilai 0 sebelum Welcome ACK diverifikasi.',
                'INVALID_ROOM_EPOCH',
                409
            );
        }

        // Validasi penugasan akademik induk
        $penugasan = $preRoom->penugasanPembimbing;
        if (!$penugasan || $penugasan->status_penugasan !== 'AKTIF') {
            throw new BchatWelcomeException(
                'Penugasan pembimbing akademik untuk ruang obrolan ini tidak aktif.',
                'ASSIGNMENT_INACTIVE',
                403
            );
        }

        // Validasi keanggotaan room (harus tepat 2 anggota pairwise)
        $members = $preRoom->members;
        if ($members->count() !== 2) {
            throw new BchatWelcomeException(
                'Ruang obrolan pairwise wajib memiliki tepat dua anggota.',
                'INVALID_ROOM_MEMBERSHIP',
                422
            );
        }

        $initiatorMember = $members->firstWhere('user_id', $initiatorUser->id);
        if (!$initiatorMember) {
            throw new BchatWelcomeException(
                'Pengguna bukan anggota dari ruang obrolan ini.',
                'UNAUTHORIZED_NOT_MEMBER',
                403
            );
        }

        $recipientMember = $members->first(fn ($m) => (int) $m->user_id !== (int) $initiatorUser->id);
        if (!$recipientMember) {
            throw new BchatWelcomeException(
                'Anggota penerima Welcome tidak ditemukan dalam ruang obrolan.',
                'RECIPIENT_NOT_FOUND',
                422
            );
        }

        if ((bool) $initiatorMember->is_active || (bool) $recipientMember->is_active) {
            throw new BchatWelcomeException(
                'Anggota ruang obrolan sudah aktif.',
                'ROOM_ALREADY_ACTIVE',
                409
            );
        }

        $initiatorId = (int) $initiatorUser->id;
        $recipientId = (int) $recipientMember->user_id;

        // Pengecekan awal operasi aktif dalam room (berlaku untuk seluruh room)
        $existingActiveOp = BchatWelcomeChallenge::where('room_id', $preRoom->id)
            ->where('operation_status', 'ACTIVE')
            ->first();

        if ($existingActiveOp) {
            throw new BchatWelcomeException(
                'Operasi Welcome aktif sudah ada dalam room ini.',
                'ACTIVE_WELCOME_OPERATION_EXISTS',
                409
            );
        }

        // Pengecekan perangkat inisiator dan penerima
        $initiatorDeviceQuery = BchatUserDevice::where('user_id', $initiatorId);
        $requestDeviceUuid = request()->header('X-Bchat-Device-Uuid');
        if ($requestDeviceUuid !== null && trim($requestDeviceUuid) !== '') {
            $initiatorDeviceQuery->where('device_uuid', strtolower(trim($requestDeviceUuid)));
        }
        $initiatorDevice = $initiatorDeviceQuery->where('status', 'ACTIVE')->first();

        if (!$initiatorDevice) {
            throw new BchatWelcomeException(
                'Perangkat inisiator tidak aktif atau belum terdaftar.',
                'INITIATOR_DEVICE_NOT_ACTIVE',
                403
            );
        }

        if (!$initiatorDevice->is_trusted) {
            throw new BchatWelcomeException(
                'Perangkat inisiator belum berstatus tepercaya. Lakukan pairing terlebih dahulu.',
                'DEVICE_NOT_TRUSTED',
                403
            );
        }

        $recipientDevice = BchatUserDevice::where('user_id', $recipientId)
            ->where('device_uuid', $recipientDeviceUuid)
            ->where('status', 'ACTIVE')
            ->first();

        if (!$recipientDevice) {
            throw new BchatWelcomeException(
                'Perangkat penerima tidak ditemukan atau tidak berstatus aktif.',
                'RECIPIENT_DEVICE_NOT_ACTIVE',
                422
            );
        }

        // Pengecekan KeyPackage
        $keyPackage = BchatKeyPackage::where('key_package_hash', $keyPackageHash)->first();
        if (!$keyPackage) {
            throw new BchatWelcomeException(
                'KeyPackage tidak ditemukan.',
                'KEY_PACKAGE_NOT_FOUND',
                404
            );
        }

        if ((int) $keyPackage->user_id !== $recipientId) {
            throw new BchatWelcomeException(
                'KeyPackage bukan milik anggota penerima Welcome.',
                'KEY_PACKAGE_USER_MISMATCH',
                422
            );
        }

        if ((int) $keyPackage->device_id !== (int) $recipientDevice->id) {
            throw new BchatWelcomeException(
                'KeyPackage tidak terikat pada perangkat penerima yang dituju.',
                'KEY_PACKAGE_DEVICE_MISMATCH',
                422
            );
        }

        if ((bool) $keyPackage->is_consumed) {
            throw new BchatWelcomeException(
                'KeyPackage yang ditentukan sudah pernah digunakan.',
                'KEY_PACKAGE_ALREADY_CONSUMED',
                409
            );
        }

        // ---------------------------------------------------------------------
        // 3. EKSEKUSI TRANSAKSI ATOMIK DENGAN HIERARCHICAL LOCKING
        //    Hierarki: users (id ASC) -> penugasan_pembimbing -> rooms -> welcome_challenges (id ASC) -> user_devices (id ASC) -> key_packages -> room_members (id ASC)
        // ---------------------------------------------------------------------
        $userIds = [$initiatorId, $recipientId];
        sort($userIds);

        $penugasanId = (int) $penugasan->id;
        $roomId = (int) $preRoom->id;
        $deviceIds = [$initiatorDevice->id, $recipientDevice->id];
        sort($deviceIds);

        $keyPackageId = (int) $keyPackage->id;
        $memberIds = [$initiatorMember->id, $recipientMember->id];
        sort($memberIds);

        try {
            return DB::transaction(function () use (
                $userIds,
                $penugasanId,
                $roomId,
                $deviceIds,
                $keyPackageId,
                $memberIds,
                $initiatorId,
                $recipientId,
                $recipientDevice,
                $binaryPayload,
                $groupIdHash
            ) {
                // A. Kunci Level 1: users (id ASC)
                User::whereIn('id', $userIds)
                    ->orderBy('id', 'asc')
                    ->lockForUpdate()
                    ->get();

                // B. Kunci Level 2: penugasan_pembimbing
                $lockedPenugasan = PenugasanPembimbing::where('id', $penugasanId)
                    ->lockForUpdate()
                    ->firstOrFail();

                if ($lockedPenugasan->status_penugasan !== 'AKTIF') {
                    throw new BchatWelcomeException(
                        'Penugasan pembimbing akademik untuk ruang obrolan ini tidak aktif.',
                        'ASSIGNMENT_INACTIVE',
                        403
                    );
                }

                // C. Kunci Level 3: rooms
                $lockedRoom = BchatRoom::where('id', $roomId)
                    ->lockForUpdate()
                    ->firstOrFail();

                // Revalidasi status room pasca-lock
                if ($lockedRoom->status !== 'PENDING_WELCOME_ACK') {
                    throw new BchatWelcomeException(
                        'Status room berubah dan bukan PENDING_WELCOME_ACK.',
                        'INVALID_ROOM_STATUS',
                        409
                    );
                }

                if ((int) $lockedRoom->current_epoch !== 0) {
                    throw new BchatWelcomeException(
                        'Epoch room harus 0 saat publikasi Welcome.',
                        'INVALID_ROOM_EPOCH',
                        409
                    );
                }

                // D. Kunci Level 4: welcome_challenges (id ASC)
                $lockedActiveOp = BchatWelcomeChallenge::where('room_id', $lockedRoom->id)
                    ->where('operation_status', 'ACTIVE')
                    ->orderBy('id', 'asc')
                    ->lockForUpdate()
                    ->first();

                if ($lockedActiveOp) {
                    throw new BchatWelcomeException(
                        'Operasi Welcome aktif sudah ada dalam room ini.',
                        'ACTIVE_WELCOME_OPERATION_EXISTS',
                        409
                    );
                }

                // E. Kunci Level 5: user_devices (id ASC)
                $lockedDevices = BchatUserDevice::whereIn('id', $deviceIds)
                    ->orderBy('id', 'asc')
                    ->lockForUpdate()
                    ->get();

                $lockedInitDev = $lockedDevices->firstWhere('id', $deviceIds[0] === $recipientDevice->id ? $deviceIds[1] : $deviceIds[0]);
                $lockedRecipDev = $lockedDevices->firstWhere('id', $recipientDevice->id);

                if (!$lockedRecipDev || $lockedRecipDev->status !== 'ACTIVE' || (int) $lockedRecipDev->user_id !== $recipientId) {
                    throw new BchatWelcomeException(
                        'Perangkat penerima tidak aktif atau tidak valid.',
                        'RECIPIENT_DEVICE_NOT_ACTIVE',
                        422
                    );
                }

                // F. Kunci Level 6: key_packages
                $lockedKeyPkg = BchatKeyPackage::where('id', $keyPackageId)
                    ->lockForUpdate()
                    ->firstOrFail();

                if ((bool) $lockedKeyPkg->is_consumed) {
                    throw new BchatWelcomeException(
                        'KeyPackage sudah pernah dikonsumsi.',
                        'KEY_PACKAGE_ALREADY_CONSUMED',
                        409
                    );
                }

                if ((int) $lockedKeyPkg->device_id !== (int) $lockedRecipDev->id || (int) $lockedKeyPkg->user_id !== $recipientId) {
                    throw new BchatWelcomeException(
                        'KeyPackage tidak cocok dengan perangkat penerima.',
                        'KEY_PACKAGE_MISMATCH',
                        422
                    );
                }

                // G. Kunci Level 7: room_members (id ASC)
                $lockedMembers = BchatRoomMember::whereIn('id', $memberIds)
                    ->orderBy('id', 'asc')
                    ->lockForUpdate()
                    ->get();

                $lockedInitMember = $lockedMembers->firstWhere('user_id', $initiatorId);
                $lockedRecipMember = $lockedMembers->firstWhere('user_id', $recipientId);

                if ((bool) $lockedInitMember->is_active || (bool) $lockedRecipMember->is_active) {
                    throw new BchatWelcomeException(
                        'Anggota room sudah aktif.',
                        'ROOM_ALREADY_ACTIVE',
                        409
                    );
                }

                // -------------------------------------------------------------
                // MUTASI ATOMIK
                // -------------------------------------------------------------
                // 1. Konsumsi KeyPackage penerima
                $lockedKeyPkg->is_consumed = true;
                $lockedKeyPkg->consumed_at = now();
                $lockedKeyPkg->save();

                // 2. Tandai peran inisiator (anggota tetap non-aktif sampai ACK sah)
                $lockedInitMember->is_initiator = true;
                $lockedInitMember->is_active = false;
                $lockedInitMember->save();

                // 2.1 Kaitkan atau buat Sesi Generasi 1 berstatus PENDING
                $session = \App\Models\BchatRoomSession::where('room_id', $lockedRoom->id)
                    ->whereIn('status', ['PENDING', 'ACTIVE'])
                    ->latest('generation_number')
                    ->first();

                if (!$session) {
                    $session = \App\Models\BchatRoomSession::create([
                        'session_uuid'       => (string) Str::uuid(),
                        'room_id'            => $lockedRoom->id,
                        'generation_number'  => 1,
                        'status'             => 'PENDING',
                        'group_id_hash'      => $groupIdHash,
                        'start_epoch'        => 1,
                        'start_sequence'     => 1,
                        'current_sequence'   => 0,
                        'created_by_user_id' => $initiatorId,
                    ]);
                }

                // 3. Buat operasi Welcome baru dan tantangan generasi 1
                $welcomeOpUuid = (string) Str::uuid();
                $challengeUuid = (string) Str::uuid();
                $challengeNonce = bin2hex(random_bytes(32));

                $welcomeChallenge = BchatWelcomeChallenge::create([
                    'welcome_operation_uuid' => $welcomeOpUuid,
                    'room_id'                => $lockedRoom->id,
                    'session_id'             => $session->id,
                    'recipient_user_id'      => $recipientId,
                    'recipient_device_id'    => $lockedRecipDev->id,
                    'welcome_message'        => $binaryPayload,
                    'group_id_hash'          => $groupIdHash,
                    'epoch'                  => self::TARGET_EPOCH,
                    'operation_status'       => 'ACTIVE',
                    'challenge_uuid'         => $challengeUuid,
                    'challenge_nonce'        => $challengeNonce,
                    'challenge_generation'   => 1,
                    'challenge_status'       => 'ISSUED',
                    'expires_at'             => now()->addMinutes(self::CHALLENGE_TTL_MINUTES),
                    'consumed_at'            => null,
                ]);

                // 4. Update group_id_hash pada room (room tetap PENDING_WELCOME_ACK, current_epoch tetap 0)
                $lockedRoom->group_id_hash = $groupIdHash;
                $lockedRoom->save();

                return [
                    'status'                 => 'success',
                    'welcome_operation_uuid' => $welcomeChallenge->welcome_operation_uuid,
                    'room_uuid'              => $lockedRoom->room_uuid,
                    'recipient_user_id'      => (string) $recipientId,
                    'recipient_device_uuid'  => $lockedRecipDev->device_uuid,
                    'epoch'                  => (string) self::TARGET_EPOCH,
                    'challenge_generation'   => 1,
                    'created_at'             => $welcomeChallenge->created_at?->toIso8601String() ?? now()->toIso8601String(),
                ];
            }, 3);
        } catch (BchatWelcomeException $e) {
            throw $e;
        } catch (QueryException $e) {
            $errorCode = $e->errorInfo[1] ?? null;
            $sqlState = $e->errorInfo[0] ?? null;

            if ($errorCode === 1205 || $errorCode === 1213 || $sqlState === '40001') {
                throw new BchatWelcomeException(
                    'Terjadi konflik konkurensi pada transaksi publikasi Welcome. Silakan coba kembali.',
                    'CONCURRENCY_LOCK_CONFLICT',
                    409,
                    $e
                );
            }

            if ($errorCode === 1062 || $sqlState === '23000') {
                $message = $e->getMessage();
                if (str_contains($message, 'uk_challenge_active_slot')) {
                    throw new BchatWelcomeException(
                        'Operasi Welcome aktif sudah ada dalam room ini.',
                        'ACTIVE_WELCOME_OPERATION_EXISTS',
                        409,
                        $e
                    );
                }
                if (str_contains($message, 'uk_challenge_nonce')) {
                    throw new BchatWelcomeException(
                        'Benturan challenge nonce terdeteksi. Silakan coba kembali.',
                        'CHALLENGE_NONCE_COLLISION',
                        409,
                        $e
                    );
                }
                throw new BchatWelcomeException(
                    'Benturan data unik terdeteksi pada database.',
                    'DUPLICATE_DATA_CONFLICT',
                    409,
                    $e
                );
            }

            throw new BchatWelcomeException(
                'Terjadi kesalahan basis data pada publikasi Welcome.',
                'DATABASE_ERROR',
                500,
                $e
            );
        } catch (Throwable $e) {
            throw new BchatWelcomeException(
                'Terjadi kesalahan internal yang tidak terduga saat memublikasikan Welcome.',
                'INTERNAL_ERROR',
                500,
                $e
            );
        }
    }

    /**
     * Mengambil tantangan Welcome yang ada atau merotasi tantangan in-place jika telah kedaluwarsa.
     *
     * @param User $requestingUser Pengguna anggota penerima (recipient)
     * @param string $roomUuid UUID publik ruang obrolan (36 karakter)
     * @return array<string, mixed> Data paket Welcome dan tantangan anti-replay aktif
     *
     * @throws BchatWelcomeException Jika validasi, otorisasi, integritas, atau konkurensi gagal
     */
    public function issueOrRotateChallenge(
        User $requestingUser,
        string $roomUuid
    ): array {
        // ---------------------------------------------------------------------
        // 1. VALIDASI FORMAT DASAR PARAMETER INPUT
        // ---------------------------------------------------------------------
        $cleanRoomUuid = strtolower(trim($roomUuid));
        if (!Str::isUuid($cleanRoomUuid)) {
            throw new BchatWelcomeException(
                'Format Room UUID tidak valid (wajib berupa UUID v4 36 karakter).',
                'INVALID_ROOM_UUID',
                422
            );
        }

        // ---------------------------------------------------------------------
        // 2. DISCOVERY & VALIDASI ENTITAS SEBELUM PENGUNCIAN HIERARKI
        // ---------------------------------------------------------------------
        $preRoom = BchatRoom::with(['members', 'penugasanPembimbing'])
            ->where('room_uuid', $cleanRoomUuid)
            ->first();

        if (!$preRoom) {
            throw new BchatWelcomeException(
                'Ruang obrolan tidak ditemukan.',
                'ROOM_NOT_FOUND',
                404
            );
        }

        // Validasi penugasan akademik induk
        $penugasan = $preRoom->penugasanPembimbing;
        if (!$penugasan || $penugasan->status_penugasan !== 'AKTIF') {
            throw new BchatWelcomeException(
                'Penugasan pembimbing akademik untuk ruang obrolan ini tidak aktif.',
                'ASSIGNMENT_INACTIVE',
                403
            );
        }

        if ($preRoom->status !== 'PENDING_WELCOME_ACK') {
            throw new BchatWelcomeException(
                'Permintaan challenge Welcome hanya diizinkan pada room berstatus PENDING_WELCOME_ACK.',
                'INVALID_ROOM_STATUS',
                409
            );
        }

        $members = $preRoom->members;
        if ($members->count() !== 2) {
            throw new BchatWelcomeException(
                'Ruang obrolan pairwise wajib memiliki tepat dua anggota.',
                'INVALID_ROOM_MEMBERSHIP',
                422
            );
        }

        $member = $members->firstWhere('user_id', $requestingUser->id);
        if (!$member) {
            throw new BchatWelcomeException(
                'Pengguna bukan anggota dari ruang obrolan ini.',
                'UNAUTHORIZED_NOT_MEMBER',
                403
            );
        }

        if ((bool) $member->is_initiator) {
            throw new BchatWelcomeException(
                'Inisiator tidak diperbolehkan meminta challenge Welcome.',
                'INITIATOR_NOT_ALLOWED_CHALLENGE',
                403
            );
        }

        $otherMember = $members->first(fn ($m) => (int) $m->user_id !== (int) $requestingUser->id);
        if (!$otherMember) {
            throw new BchatWelcomeException(
                'Anggota pasangan ruang obrolan tidak ditemukan.',
                'RECIPIENT_NOT_FOUND',
                422
            );
        }

        $requestingDeviceQuery = BchatUserDevice::where('user_id', $requestingUser->id);
        $requestDeviceUuid = request()->header('X-Bchat-Device-Uuid');
        if ($requestDeviceUuid !== null && trim($requestDeviceUuid) !== '') {
            $requestingDeviceQuery->where('device_uuid', strtolower(trim($requestDeviceUuid)));
        }
        $requestingDevice = $requestingDeviceQuery->where('status', 'ACTIVE')->first();

        if (!$requestingDevice) {
            throw new BchatWelcomeException(
                'Perangkat pemohon tidak aktif atau belum terdaftar.',
                'RECIPIENT_DEVICE_NOT_ACTIVE',
                422
            );
        }

        if (!$requestingDevice->is_trusted) {
            throw new BchatWelcomeException(
                'Perangkat pemohon belum berstatus tepercaya. Lakukan pairing terlebih dahulu.',
                'DEVICE_NOT_TRUSTED',
                403
            );
        }

        // Pengecekan operasi Welcome aktif untuk seluruh room
        $activeOp = BchatWelcomeChallenge::where('room_id', $preRoom->id)
            ->where('operation_status', 'ACTIVE')
            ->first();

        if (!$activeOp) {
            throw new BchatWelcomeException(
                'Tidak ada operasi Welcome aktif untuk ruang obrolan ini.',
                'NO_ACTIVE_WELCOME_OPERATION',
                404
            );
        }

        if ((int) $activeOp->recipient_user_id !== (int) $requestingUser->id) {
            throw new BchatWelcomeException(
                'Operasi Welcome aktif bukan ditujukan untuk pengguna ini.',
                'RECIPIENT_USER_MISMATCH',
                403
            );
        }

        if ((int) $activeOp->recipient_device_id !== (int) $requestingDevice->id) {
            throw new BchatWelcomeException(
                'Operasi Welcome aktif ditujukan untuk perangkat yang berbeda.',
                'RECIPIENT_DEVICE_MISMATCH',
                403
            );
        }

        // ---------------------------------------------------------------------
        // 3. EKSEKUSI TRANSAKSI ATOMIK DENGAN HIERARCHICAL LOCKING
        //    Hierarki: users (id ASC) -> penugasan_pembimbing -> rooms -> welcome_challenges (id ASC) -> user_devices
        // ---------------------------------------------------------------------
        $requestingUserId = (int) $requestingUser->id;
        $otherUserId = (int) $otherMember->user_id;
        $userIds = [$requestingUserId, $otherUserId];
        sort($userIds);

        $penugasanId = (int) $penugasan->id;
        $roomId = (int) $preRoom->id;

        try {
            return DB::transaction(function () use (
                $userIds,
                $requestingUserId,
                $penugasanId,
                $roomId,
                $activeOp
            ) {
                // A. Kunci Level 1: users (id ASC)
                User::whereIn('id', $userIds)
                    ->orderBy('id', 'asc')
                    ->lockForUpdate()
                    ->get();

                // B. Kunci Level 2: penugasan_pembimbing
                $lockedPenugasan = PenugasanPembimbing::where('id', $penugasanId)
                    ->lockForUpdate()
                    ->firstOrFail();

                if ($lockedPenugasan->status_penugasan !== 'AKTIF') {
                    throw new BchatWelcomeException(
                        'Penugasan pembimbing akademik untuk ruang obrolan ini tidak aktif.',
                        'ASSIGNMENT_INACTIVE',
                        403
                    );
                }

                // C. Kunci Level 3: rooms
                $lockedRoom = BchatRoom::where('id', $roomId)
                    ->lockForUpdate()
                    ->firstOrFail();

                if ($lockedRoom->status !== 'PENDING_WELCOME_ACK') {
                    throw new BchatWelcomeException(
                        'Status room berubah dan bukan PENDING_WELCOME_ACK.',
                        'INVALID_ROOM_STATUS',
                        409
                    );
                }

                // D. Kunci Level 4: welcome_challenges (id ASC)
                $lockedChallenge = BchatWelcomeChallenge::where('room_id', $lockedRoom->id)
                    ->where('operation_status', 'ACTIVE')
                    ->orderBy('id', 'asc')
                    ->lockForUpdate()
                    ->first();

                if (!$lockedChallenge) {
                    throw new BchatWelcomeException(
                        'Tidak ada operasi Welcome aktif saat kunci diperoleh.',
                        'NO_ACTIVE_WELCOME_OPERATION',
                        404
                    );
                }

                if ((int) $lockedChallenge->recipient_user_id !== $requestingUserId) {
                    throw new BchatWelcomeException(
                        'Operasi Welcome aktif bukan ditujukan untuk pengguna ini.',
                        'RECIPIENT_USER_MISMATCH',
                        403
                    );
                }

                // E. Kunci Level 5: user_devices
                $lockedDevice = BchatUserDevice::where('id', $lockedChallenge->recipient_device_id)
                    ->lockForUpdate()
                    ->first();

                if (!$lockedDevice || $lockedDevice->status !== 'ACTIVE' || (int) $lockedDevice->user_id !== $requestingUserId) {
                    throw new BchatWelcomeException(
                        'Perangkat penerima tidak aktif atau telah dicabut.',
                        'RECIPIENT_DEVICE_NOT_ACTIVE',
                        422
                    );
                }

                // -------------------------------------------------------------
                // EVALUASI KEDALUWARSA & ROTASI IN-PLACE
                // -------------------------------------------------------------
                $now = now();
                $expiresAt = $lockedChallenge->expires_at;

                // Aturan: Challenge hanya dirotasi jika waktu server benar-benar telah melewati expires_at.
                // Jika sisa TTL > 60 detik ATAU 1-60 detik: TETAP kembalikan challenge yang sama tanpa rotasi.
                $isExpired = $now->greaterThan($expiresAt);

                if ($isExpired) {
                    // Cek proteksi overflow uint32
                    if ($lockedChallenge->challenge_generation >= self::MAX_GENERATION) {
                        throw new BchatWelcomeException(
                            'Batas maksimum generasi tantangan tercapai (overflow guard).',
                            'CHALLENGE_GENERATION_OVERFLOW',
                            409
                        );
                    }

                    // Rotasi In-Place: naikkan generasi, terbitkan nonce & challenge UUID baru
                    $lockedChallenge->challenge_generation = $lockedChallenge->challenge_generation + 1;
                    $lockedChallenge->challenge_uuid = (string) Str::uuid();
                    $lockedChallenge->challenge_nonce = bin2hex(random_bytes(32));
                    $lockedChallenge->challenge_status = 'ISSUED';
                    $lockedChallenge->expires_at = $now->copy()->addMinutes(self::CHALLENGE_TTL_MINUTES);
                    $lockedChallenge->save();
                }

                $remainingTtl = max(0, (int) now()->diffInSeconds($lockedChallenge->expires_at, false));

                return [
                    'status'                 => 'success',
                    'welcome_operation_uuid' => $lockedChallenge->welcome_operation_uuid,
                    'room_uuid'              => $lockedRoom->room_uuid,
                    'welcome_message'        => base64_encode($lockedChallenge->welcome_message),
                    'group_id_hash'          => $lockedChallenge->group_id_hash,
                    'epoch'                  => (string) $lockedChallenge->epoch,
                    'challenge_uuid'         => $lockedChallenge->challenge_uuid,
                    'challenge_nonce'        => $lockedChallenge->challenge_nonce,
                    'challenge_generation'   => (int) $lockedChallenge->challenge_generation,
                    'expires_at'             => $lockedChallenge->expires_at->toIso8601String(),
                    'ttl_remaining_seconds'  => $remainingTtl,
                ];
            }, 3);
        } catch (BchatWelcomeException $e) {
            throw $e;
        } catch (QueryException $e) {
            $errorCode = $e->errorInfo[1] ?? null;
            $sqlState = $e->errorInfo[0] ?? null;

            if ($errorCode === 1205 || $errorCode === 1213 || $sqlState === '40001') {
                throw new BchatWelcomeException(
                    'Terjadi konflik konkurensi pada transaksi permintaan challenge. Silakan coba kembali.',
                    'CONCURRENCY_LOCK_CONFLICT',
                    409,
                    $e
                );
            }

            if ($errorCode === 1062 || $sqlState === '23000') {
                $message = $e->getMessage();
                if (str_contains($message, 'uk_challenge_nonce')) {
                    throw new BchatWelcomeException(
                        'Benturan challenge nonce terdeteksi. Silakan coba kembali.',
                        'CHALLENGE_NONCE_COLLISION',
                        409,
                        $e
                    );
                }
                throw new BchatWelcomeException(
                    'Benturan data unik terdeteksi pada database.',
                    'DUPLICATE_DATA_CONFLICT',
                    409,
                    $e
                );
            }

            throw new BchatWelcomeException(
                'Terjadi kesalahan basis data pada permintaan challenge.',
                'DATABASE_ERROR',
                500,
                $e
            );
        } catch (Throwable $e) {
            throw new BchatWelcomeException(
                'Terjadi kesalahan internal yang tidak terduga saat meminta challenge.',
                'INTERNAL_ERROR',
                500,
                $e
            );
        }
    }
}

