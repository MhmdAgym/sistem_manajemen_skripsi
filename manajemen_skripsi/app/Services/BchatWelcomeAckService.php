<?php

declare(strict_types=1);

namespace App\Services;

use App\Events\BchatRoomActivated;
use App\Exceptions\BchatWelcomeException;
use App\Models\BchatRoom;
use App\Models\BchatRoomMember;
use App\Models\BchatUserDevice;
use App\Models\BchatWelcomeChallenge;
use App\Models\PenugasanPembimbing;
use App\Models\User;
use App\Services\BchatWelcomeAckVerificationService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * Service mandiri penanganan Welcome ACK dan aktivasi atomik ruang obrolan B-CHAT E2EE.
 *
 * Mengimplementasikan kontrak definitif:
 * 1. Zero Trust: backend merakit sendiri buffer kanonikal 158-byte dari konteks server tepercaya.
 * 2. Hierarchical Locking strictly top-down:
 *    users (id ASC) -> penugasan_pembimbing (id) -> bchat_rooms (id) -> bchat_welcome_challenges (id) -> bchat_user_devices (id) -> bchat_room_members (id ASC)
 * 3. Transaksi atomik: konsumsi challenge, aktivasi kedua anggota pairwise, dan perubahan room ke ACTIVE epoch 1.
 * 4. After-commit thin signal broadcast via Reverb.
 */
class BchatWelcomeAckService
{
    /**
     * Target epoch mutlak Welcome pertama (RFC 9420).
     */
    public const TARGET_EPOCH = 1;

    public function __construct(
        protected BchatWelcomeAckVerificationService $verifier
    ) {
    }

    /**
     * Memproses Welcome ACK dari anggota penerima (recipient) dan mengaktifkan room secara atomik.
     *
     * @param User $recipientUser Pengguna yang terotentikasi via Sanctum (wajib pihak recipient)
     * @param string $roomUuid UUID publik ruang obrolan (36 karakter)
     * @param array<string, mixed> $payloadData Data input request (challenge_uuid, signature)
     * @param string|null $ipAddress Alamat IP klien pengirim ACK
     * @param string|null $userAgent User-Agent peramban klien pengirim ACK
     * @return array<string, mixed> Data konfirmasi aktivasi ruang obrolan
     *
     * @throws BchatWelcomeException Jika validasi, otorisasi, kriptografi, integritas, atau konkurensi gagal
     */
    public function processWelcomeAck(
        User $recipientUser,
        string $roomUuid,
        array $payloadData,
        ?string $ipAddress = null,
        ?string $userAgent = null
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

        $challengeUuid = strtolower(trim((string) ($payloadData['challenge_uuid'] ?? '')));
        if (!Str::isUuid($challengeUuid)) {
            throw new BchatWelcomeException(
                'Format Challenge UUID tidak valid (wajib berupa UUID v4 36 karakter).',
                'INVALID_CHALLENGE_UUID',
                422
            );
        }

        $signatureBase64 = (string) ($payloadData['signature'] ?? '');
        if ($signatureBase64 === '') {
            throw new BchatWelcomeException(
                'Signature digital Welcome ACK wajib diisi.',
                'MISSING_SIGNATURE',
                422
            );
        }

        // Strict decoding Base64 RFC 4648
        $signatureBinary = base64_decode($signatureBase64, true);
        if ($signatureBinary === false || strlen($signatureBinary) !== BchatWelcomeAckVerificationService::SIGNATURE_LENGTH || base64_encode($signatureBinary) !== $signatureBase64) {
            throw new BchatWelcomeException(
                'Signature digital harus berupa string Base64 RFC 4648 kanonikal tepat 64 byte.',
                'INVALID_SIGNATURE_FORMAT',
                422
            );
        }

        // ---------------------------------------------------------------------
        // 2. DISCOVERY & VALIDASI ENTITAS SEBELUM PENGUNCIAN HIERARKI (UNLOCKED)
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
                'Aktivasi Welcome ACK hanya diizinkan pada room berstatus PENDING_WELCOME_ACK.',
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

        $recipientMember = $members->firstWhere('user_id', $recipientUser->id);
        if (!$recipientMember) {
            throw new BchatWelcomeException(
                'Pengguna bukan anggota dari ruang obrolan ini.',
                'UNAUTHORIZED_NOT_MEMBER',
                403
            );
        }

        if ((bool) $recipientMember->is_initiator) {
            throw new BchatWelcomeException(
                'Inisiator tidak diperbolehkan mengirimkan Welcome ACK (hanya penerima Welcome yang berhak).',
                'INITIATOR_NOT_ALLOWED_ACK',
                403
            );
        }

        $initiatorMember = $members->first(fn ($m) => (int) $m->user_id !== (int) $recipientUser->id);
        if (!$initiatorMember || !(bool) $initiatorMember->is_initiator) {
            throw new BchatWelcomeException(
                'Konfigurasi inisiator ruang obrolan tidak valid.',
                'INVALID_ROOM_MEMBERSHIP',
                422
            );
        }

        if ((bool) $recipientMember->is_active || (bool) $initiatorMember->is_active) {
            throw new BchatWelcomeException(
                'Anggota ruang obrolan sudah berstatus aktif.',
                'ROOM_ALREADY_ACTIVE',
                409
            );
        }

        // Pengecekan awal operasi Welcome aktif
        $activeChallenge = BchatWelcomeChallenge::where('room_id', $preRoom->id)
            ->where('operation_status', 'ACTIVE')
            ->first();

        if (!$activeChallenge) {
            throw new BchatWelcomeException(
                'Tidak ada operasi Welcome aktif untuk ruang obrolan ini.',
                'NO_ACTIVE_WELCOME_OPERATION',
                404
            );
        }

        if ((int) $activeChallenge->recipient_user_id !== (int) $recipientUser->id) {
            throw new BchatWelcomeException(
                'Operasi Welcome aktif bukan ditujukan untuk pengguna ini.',
                'RECIPIENT_USER_MISMATCH',
                403
            );
        }

        if (strtolower($activeChallenge->challenge_uuid) !== $challengeUuid) {
            throw new BchatWelcomeException(
                'Challenge UUID tidak cocok dengan tantangan aktif saat ini.',
                'CHALLENGE_UUID_MISMATCH',
                409
            );
        }

        if ($activeChallenge->challenge_status !== 'ISSUED') {
            throw new BchatWelcomeException(
                'Status tantangan Welcome bukan ISSUED.',
                'CHALLENGE_NOT_ISSUED',
                409
            );
        }

        if (now()->greaterThan($activeChallenge->expires_at)) {
            throw new BchatWelcomeException(
                'Tantangan Welcome telah kedaluwarsa. Silakan minta tantangan baru.',
                'CHALLENGE_EXPIRED',
                409
            );
        }

        // Pengecekan perangkat aktif penerima
        $recipientDevice = BchatUserDevice::where('id', $activeChallenge->recipient_device_id)
            ->where('user_id', $recipientUser->id)
            ->where('status', 'ACTIVE')
            ->first();

        if (!$recipientDevice) {
            throw new BchatWelcomeException(
                'Perangkat penerima tidak ditemukan atau tidak berstatus aktif.',
                'RECIPIENT_DEVICE_NOT_ACTIVE',
                422
            );
        }

        if (!$recipientDevice->is_trusted) {
            throw new BchatWelcomeException(
                'Perangkat penerima belum berstatus tepercaya. Lakukan pairing terlebih dahulu.',
                'DEVICE_NOT_TRUSTED',
                403
            );
        }

        // ---------------------------------------------------------------------
        // 3. EKSEKUSI TRANSAKSI ATOMIK DENGAN HIERARCHICAL LOCKING
        //    Hierarki: users -> penugasan_pembimbing -> bchat_rooms -> bchat_welcome_challenges -> bchat_user_devices -> bchat_room_members
        // ---------------------------------------------------------------------
        $userIds = [(int) $recipientUser->id, (int) $initiatorMember->user_id];
        sort($userIds);

        $penugasanId = (int) $penugasan->id;
        $roomId = (int) $preRoom->id;
        $challengeId = (int) $activeChallenge->id;
        $deviceId = (int) $recipientDevice->id;

        $memberIds = [(int) $initiatorMember->id, (int) $recipientMember->id];
        sort($memberIds);

        try {
            return DB::transaction(function () use (
                $userIds,
                $penugasanId,
                $roomId,
                $challengeId,
                $deviceId,
                $memberIds,
                $recipientUser,
                $initiatorMember,
                $challengeUuid,
                $signatureBinary,
                $signatureBase64,
                $ipAddress,
                $userAgent
            ) {
                // A. Kunci Level 1: users (id ASC)
                User::whereIn('id', $userIds)
                    ->orderBy('id', 'asc')
                    ->lockForUpdate()
                    ->get();

                // B. Kunci Level 2: penugasan_pembimbing (id)
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

                // C. Kunci Level 3: bchat_rooms (id)
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

                if ((int) $lockedRoom->current_epoch !== 0) {
                    throw new BchatWelcomeException(
                        'Epoch room harus 0 sebelum Welcome ACK diverifikasi.',
                        'INVALID_ROOM_EPOCH',
                        409
                    );
                }

                // D. Kunci Level 4: bchat_welcome_challenges (id)
                $lockedChallenge = BchatWelcomeChallenge::where('id', $challengeId)
                    ->where('operation_status', 'ACTIVE')
                    ->lockForUpdate()
                    ->first();

                if (!$lockedChallenge) {
                    throw new BchatWelcomeException(
                        'Operasi Welcome aktif tidak ditemukan atau sudah dikonsumsi.',
                        'NO_ACTIVE_WELCOME_OPERATION',
                        409
                    );
                }

                if ($lockedChallenge->challenge_status !== 'ISSUED') {
                    throw new BchatWelcomeException(
                        'Status tantangan Welcome bukan ISSUED.',
                        'CHALLENGE_NOT_ISSUED',
                        409
                    );
                }

                if (now()->greaterThan($lockedChallenge->expires_at)) {
                    throw new BchatWelcomeException(
                        'Tantangan Welcome telah kedaluwarsa.',
                        'CHALLENGE_EXPIRED',
                        409
                    );
                }

                if (strtolower($lockedChallenge->challenge_uuid) !== $challengeUuid) {
                    throw new BchatWelcomeException(
                        'Challenge UUID tidak cocok dengan tantangan aktif saat ini.',
                        'CHALLENGE_UUID_MISMATCH',
                        409
                    );
                }

                if ((int) $lockedChallenge->recipient_user_id !== (int) $recipientUser->id) {
                    throw new BchatWelcomeException(
                        'Operasi Welcome aktif bukan ditujukan untuk pengguna ini.',
                        'RECIPIENT_USER_MISMATCH',
                        403
                    );
                }

                // E. Kunci Level 5: bchat_user_devices (id)
                $lockedDevice = BchatUserDevice::where('id', $deviceId)
                    ->lockForUpdate()
                    ->first();

                if (!$lockedDevice || $lockedDevice->status !== 'ACTIVE' || (int) $lockedDevice->user_id !== (int) $recipientUser->id) {
                    throw new BchatWelcomeException(
                        'Perangkat penerima tidak aktif atau telah dicabut.',
                        'RECIPIENT_DEVICE_NOT_ACTIVE',
                        422
                    );
                }

                if (!$lockedDevice->is_trusted) {
                    throw new BchatWelcomeException(
                        'Perangkat penerima belum berstatus tepercaya. Lakukan pairing terlebih dahulu.',
                        'DEVICE_NOT_TRUSTED',
                        403
                    );
                }

                if ((int) $lockedChallenge->recipient_device_id !== (int) $lockedDevice->id) {
                    throw new BchatWelcomeException(
                        'Perangkat penerima tidak cocok dengan tantangan aktif.',
                        'RECIPIENT_DEVICE_MISMATCH',
                        422
                    );
                }

                // F. Kunci Level 6: bchat_room_members (id ASC)
                $lockedMembers = BchatRoomMember::whereIn('id', $memberIds)
                    ->orderBy('id', 'asc')
                    ->lockForUpdate()
                    ->get();

                if ($lockedMembers->count() !== 2) {
                    throw new BchatWelcomeException(
                        'Keanggotaan room tidak lengkap saat verifikasi.',
                        'INVALID_ROOM_MEMBERSHIP',
                        422
                    );
                }

                $lockedRecipient = $lockedMembers->firstWhere('user_id', $recipientUser->id);
                $lockedInitiator = $lockedMembers->firstWhere('user_id', $initiatorMember->user_id);

                if (!$lockedRecipient || !$lockedInitiator) {
                    throw new BchatWelcomeException(
                        'Anggota room tidak cocok dengan identitas penugasan.',
                        'INVALID_ROOM_MEMBERSHIP',
                        422
                    );
                }

                if ((bool) $lockedRecipient->is_active || (bool) $lockedInitiator->is_active) {
                    throw new BchatWelcomeException(
                        'Anggota ruang obrolan sudah berstatus aktif.',
                        'ROOM_ALREADY_ACTIVE',
                        409
                    );
                }

                if ((bool) $lockedRecipient->is_initiator || !(bool) $lockedInitiator->is_initiator) {
                    throw new BchatWelcomeException(
                        'Konfigurasi peran inisiator pada keanggotaan room tidak valid.',
                        'INVALID_ROOM_MEMBERSHIP',
                        422
                    );
                }

                // -------------------------------------------------------------
                // 4. VERIFIKASI KRIPTOGRAFIS CANONICAL WELCOME ACK (ZERO TRUST)
                // -------------------------------------------------------------
                $publicKeyBinary = BchatWelcomeAckVerificationService::hexOrBinToBinary($lockedDevice->public_key, 32);
                if ($publicKeyBinary === null) {
                    throw new BchatWelcomeException(
                        'Format kunci publik perangkat penerima tidak valid.',
                        'INVALID_PUBLIC_KEY',
                        422
                    );
                }

                $verifyResult = $this->verifier->verify(
                    signatureBinary: $signatureBinary,
                    publicKeyBinary: $publicKeyBinary,
                    roomUuid: $lockedRoom->room_uuid,
                    recipientUserId: (int) $recipientUser->id,
                    welcomeOperationUuid: $lockedChallenge->welcome_operation_uuid,
                    challengeUuid: $lockedChallenge->challenge_uuid,
                    challengeGeneration: (int) $lockedChallenge->challenge_generation,
                    groupIdHashHexOrBin: $lockedChallenge->group_id_hash,
                    epoch: self::TARGET_EPOCH,
                    challengeNonceHexOrBin: $lockedChallenge->challenge_nonce
                );

                if (!$verifyResult->isValid()) {
                    $errorCode = $verifyResult->getErrorCode() ?? 'SIGNATURE_VERIFICATION_FAILED';
                    throw new BchatWelcomeException(
                        'Verifikasi tanda tangan digital Welcome ACK gagal: ' . $errorCode,
                        $errorCode,
                        422
                    );
                }

                // -------------------------------------------------------------
                // 5. MUTASI ATOMIK PARIPURNA
                // -------------------------------------------------------------
                $now = now();

                // A. Konsumsi Tantangan & Operasi Welcome
                $lockedChallenge->operation_status = 'CONSUMED';
                $lockedChallenge->challenge_status = 'CONSUMED';
                $lockedChallenge->consumed_at = $now;
                $lockedChallenge->ack_payload_hash = $verifyResult->getPayloadHash();
                $lockedChallenge->ack_signature = $signatureBase64;
                $lockedChallenge->ip_address = $ipAddress;
                $lockedChallenge->user_agent = $userAgent;
                $lockedChallenge->save();

                // B. Aktivasi Anggota Penerima
                $lockedRecipient->is_active = true;
                $lockedRecipient->welcome_ack_at = $now;
                $lockedRecipient->joined_at_epoch = self::TARGET_EPOCH;
                $lockedRecipient->save();

                // C. Aktivasi Anggota Inisiator
                $lockedInitiator->is_active = true;
                $lockedInitiator->joined_at_epoch = self::TARGET_EPOCH;
                $lockedInitiator->save();

                // D. Aktivasi Ruang Obrolan
                $lockedRoom->status = 'ACTIVE';
                $lockedRoom->current_epoch = self::TARGET_EPOCH;
                $lockedRoom->save();

                // D.1 Aktivasi Sesi Kriptografi MLS Terkait
                if ($lockedChallenge->session_id) {
                    $session = \App\Models\BchatRoomSession::where('id', $lockedChallenge->session_id)->lockForUpdate()->first();
                    if ($session && $session->status === 'PENDING') {
                        $session->status = 'ACTIVE';
                        $session->activated_at = $now;
                        $session->start_epoch = self::TARGET_EPOCH;
                        $session->save();
                    }
                } else {
                    $session = \App\Models\BchatRoomSession::where('room_id', $lockedRoom->id)->where('status', 'PENDING')->lockForUpdate()->first();
                    if ($session) {
                        $session->status = 'ACTIVE';
                        $session->activated_at = $now;
                        $session->start_epoch = self::TARGET_EPOCH;
                        $session->save();
                    }
                }

                // E. Siarkan Sinyal WebSocket Reverb (After-Commit)
                event(new BchatRoomActivated(
                    roomUuid: $lockedRoom->room_uuid,
                    currentEpoch: self::TARGET_EPOCH,
                    roomStatus: 'ACTIVE',
                    memberUserIds: [(int) $recipientUser->id, (int) $initiatorMember->user_id],
                    activatedAt: $now->toIso8601String()
                ));

                return [
                    'status'                 => 'success',
                    'room_status'            => 'ACTIVE',
                    'current_epoch'          => '1',
                    'activated_at'           => $now->toIso8601String(),
                    'room_uuid'              => $lockedRoom->room_uuid,
                    'welcome_operation_uuid' => $lockedChallenge->welcome_operation_uuid,
                    'challenge_uuid'         => $lockedChallenge->challenge_uuid,
                    'ack_payload_hash'       => $lockedChallenge->ack_payload_hash,
                ];
            }, 3);
        } catch (BchatWelcomeException $e) {
            throw $e;
        } catch (QueryException $e) {
            $errorCode = $e->errorInfo[1] ?? null;
            $sqlState = $e->errorInfo[0] ?? null;

            if ($errorCode === 1205 || $errorCode === 1213 || $sqlState === '40001') {
                throw new BchatWelcomeException(
                    'Terjadi konflik konkurensi pada transaksi verifikasi Welcome ACK. Silakan coba kembali.',
                    'CONCURRENCY_LOCK_CONFLICT',
                    409,
                    $e
                );
            }

            if ($errorCode === 1062 || $sqlState === '23000') {
                throw new BchatWelcomeException(
                    'Benturan data unik terdeteksi pada database saat verifikasi Welcome ACK.',
                    'DUPLICATE_DATA_CONFLICT',
                    409,
                    $e
                );
            }

            throw new BchatWelcomeException(
                'Terjadi kesalahan basis data saat verifikasi Welcome ACK.',
                'DATABASE_ERROR',
                500,
                $e
            );
        } catch (Throwable $e) {
            throw new BchatWelcomeException(
                'Terjadi kesalahan internal yang tidak terduga saat memproses Welcome ACK.',
                'INTERNAL_ERROR',
                500,
                $e
            );
        }
    }
}

