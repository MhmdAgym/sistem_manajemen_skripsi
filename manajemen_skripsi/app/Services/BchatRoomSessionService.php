<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\BchatRoomSessionException;
use App\Models\BchatKeyPackage;
use App\Models\BchatRoom;
use App\Models\BchatRoomMember;
use App\Models\BchatRoomSession;
use App\Models\BchatUserDevice;
use App\Models\BchatWelcomeChallenge;
use App\Models\PenugasanPembimbing;
use App\Models\User;
use App\Services\BchatWelcomeAckVerificationService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

class BchatRoomSessionService
{
    public const CHALLENGE_TTL_MINUTES = 5;
    public const MAX_GENERATION = 4294967295;

    public function __construct(
        protected readonly BchatWelcomeAckVerificationService $verifier
    ) {
    }

    /**
     * Membuat generasi sesi kriptografi berikutnya untuk suatu ruang bimbingan.
     *
     * Invarian Keamanan:
     * 1. Pengguna wajib anggota sah ruang obrolan dengan penugasan pembimbing berstatus 'AKTIF'.
     * 2. Pengguna wajib memiliki perangkat aktif dan tepercaya (is_trusted = true).
     * 3. Ruang obrolan berstatus 'TERMINATED', 'SUSPENDED', atau 'ARCHIVED_READONLY' ditolak.
     * 4. Hanya boleh ada tepat maksimal 1 sesi ACTIVE pada satu waktu.
     *    Sesi ACTIVE sebelumnya diarsipkan (ARCHIVED) secara otomatis dengan merekam end_sequence dan end_epoch.
     * 5. Jika sudah ada sesi berstatus PENDING pada ruang ini, pembuatan sesi baru berikutnya ditolak (HTTP 409).
     * 6. Sesi baru dibuat dengan status PENDING dan sequence dimulai tepat dari current_sequence + 1.
     *
     * @param User $creator Pengguna yang menginisiasi sesi baru
     * @param BchatRoom|string $roomOrUuid Model BchatRoom atau Room UUID
     * @param string|null $groupIdHash SHA-256 hash dari MLS GroupContext.group_id
     * @return BchatRoomSession
     *
     * @throws BchatRoomSessionException
     */
    public function createNextSession(
        User $creator,
        BchatRoom|string $roomOrUuid,
        ?string $groupIdHash = null
    ): BchatRoomSession {
        $room = $this->resolveRoom($roomOrUuid);

        $this->authorizeSessionAction($creator, $room);

        try {
            return DB::transaction(function () use ($room, $creator, $groupIdHash) {
                // Kunci baris room secara eksklusif untuk serialisasi
                $lockedRoom = BchatRoom::where('id', $room->id)->lockForUpdate()->firstOrFail();

                // Cek apakah sudah ada sesi berstatus PENDING
                $pendingSession = BchatRoomSession::where('room_id', $lockedRoom->id)
                    ->where('status', 'PENDING')
                    ->lockForUpdate()
                    ->first();

                if ($pendingSession) {
                    throw new BchatRoomSessionException(
                        'Sesi generasi baru masih menunggu verifikasi handshake.',
                        'SESSION_ALREADY_PENDING',
                        409
                    );
                }

                // PENTING: Sesi ACTIVE yang lama TIDAK diarsipkan di sini.
                // Sesi lama tetap ACTIVE dan dapat terus melayani percakapan
                // sampai sesi baru berhasil menyelesaikan handshake Welcome ACK.

                // Hitung nomor generasi berikutnya
                $maxGen = BchatRoomSession::where('room_id', $lockedRoom->id)
                    ->lockForUpdate()
                    ->max('generation_number');

                $nextGen = $maxGen ? ((int) $maxGen + 1) : 1;
                $startSequence = (int) $lockedRoom->current_sequence + 1;
                $currentSequence = (int) $lockedRoom->current_sequence;

                $newSession = BchatRoomSession::create([
                    'session_uuid'       => (string) Str::uuid(),
                    'room_id'            => $lockedRoom->id,
                    'generation_number'  => $nextGen,
                    'status'             => 'PENDING',
                    'group_id_hash'      => $groupIdHash ?? $lockedRoom->group_id_hash,
                    'start_epoch'        => 1,
                    'start_sequence'     => $startSequence,
                    'end_sequence'       => null,
                    'current_sequence'   => $currentSequence,
                    'created_by_user_id' => $creator->id,
                ]);

                return $newSession;
            }, 3);
        } catch (BchatRoomSessionException $e) {
            throw $e;
        } catch (Throwable $e) {
            throw new BchatRoomSessionException(
                'Terjadi kesalahan internal saat membuat generasi sesi baru: ' . $e->getMessage(),
                'INTERNAL_SESSION_ERROR',
                500,
                $e
            );
        }
    }

    /**
     * Memublikasikan paket Welcome MLS untuk sesi generasi yang berstatus PENDING.
     *
     * @param User $initiator Pengguna inisiator Welcome
     * @param BchatRoomSession $session Sesi target
     * @param array<string, mixed> $payloadData Data Welcome (recipient_device_uuid, key_package_hash, group_id_hash, welcome_message)
     * @return BchatWelcomeChallenge
     *
     * @throws BchatRoomSessionException
     */
    public function publishSessionWelcome(
        User $initiator,
        BchatRoomSession $session,
        array $payloadData
    ): BchatWelcomeChallenge {
        if ($session->status !== 'PENDING') {
            throw new BchatRoomSessionException(
                'Publikasi Welcome hanya diizinkan untuk sesi berstatus PENDING.',
                'INVALID_SESSION_STATUS',
                409
            );
        }

        $room = $session->room;
        if (!$room) {
            throw new BchatRoomSessionException('Ruang obrolan tidak ditemukan.', 'ROOM_NOT_FOUND', 404);
        }

        $this->authorizeSessionAction($initiator, $room);

        // Ambil data anggota penerima
        $recipientMember = $room->members()->where('user_id', '!=', $initiator->id)->first();
        if (!$recipientMember) {
            throw new BchatRoomSessionException('Mitra bimbingan penerima tidak ditemukan.', 'RECIPIENT_NOT_FOUND', 422);
        }

        $recipientUserId = (int) $recipientMember->user_id;
        $recipientDeviceUuid = strtolower(trim((string) ($payloadData['recipient_device_uuid'] ?? '')));
        $keyPackageHash = strtolower(trim((string) ($payloadData['key_package_hash'] ?? '')));
        $groupIdHash = strtolower(trim((string) ($payloadData['group_id_hash'] ?? $session->group_id_hash ?? '')));
        $rawWelcome = base64_decode((string) ($payloadData['welcome_message'] ?? ''), true);

        if ($rawWelcome === false || strlen($rawWelcome) === 0) {
            throw new BchatRoomSessionException('Payload Welcome biner tidak valid.', 'INVALID_WELCOME_PAYLOAD', 422);
        }

        try {
            return DB::transaction(function () use (
                $session,
                $room,
                $initiator,
                $recipientUserId,
                $recipientDeviceUuid,
                $keyPackageHash,
                $groupIdHash,
                $rawWelcome
            ) {
                $lockedSession = BchatRoomSession::where('id', $session->id)->lockForUpdate()->firstOrFail();
                if ($lockedSession->status !== 'PENDING') {
                    throw new BchatRoomSessionException(
                        'Status sesi berubah dan bukan PENDING.',
                        'INVALID_SESSION_STATUS',
                        409
                    );
                }

                // Cek perangkat penerima
                $recipientDevice = BchatUserDevice::where('user_id', $recipientUserId)
                    ->where('device_uuid', $recipientDeviceUuid)
                    ->where('status', 'ACTIVE')
                    ->lockForUpdate()
                    ->first();

                if (!$recipientDevice) {
                    throw new BchatRoomSessionException(
                        'Perangkat penerima tidak ditemukan atau tidak aktif.',
                        'RECIPIENT_DEVICE_NOT_ACTIVE',
                        422
                    );
                }

                if (!$recipientDevice->is_trusted) {
                    throw new BchatRoomSessionException(
                        'Perangkat penerima belum berstatus tepercaya.',
                        'DEVICE_NOT_TRUSTED',
                        403
                    );
                }

                // Cek operasi Welcome aktif untuk sesi/room ini (anti-duplikasi)
                $existingActiveChallenge = BchatWelcomeChallenge::where('room_id', $room->id)
                    ->where('operation_status', 'ACTIVE')
                    ->lockForUpdate()
                    ->first();

                if ($existingActiveChallenge) {
                    throw new BchatRoomSessionException(
                        'Operasi Welcome aktif sudah ada untuk sesi atau ruang obrolan ini.',
                        'ACTIVE_WELCOME_OPERATION_EXISTS',
                        409
                    );
                }

                // Cek KeyPackage
                $keyPackage = BchatKeyPackage::where('key_package_hash', $keyPackageHash)
                    ->lockForUpdate()
                    ->first();

                if (!$keyPackage) {
                    throw new BchatRoomSessionException('KeyPackage tidak ditemukan.', 'KEY_PACKAGE_NOT_FOUND', 404);
                }

                if ((bool) $keyPackage->is_consumed) {
                    throw new BchatRoomSessionException('KeyPackage sudah pernah dikonsumsi.', 'KEY_PACKAGE_ALREADY_CONSUMED', 409);
                }

                if ((int) $keyPackage->user_id !== $recipientUserId || (int) $keyPackage->device_id !== (int) $recipientDevice->id) {
                    throw new BchatRoomSessionException('KeyPackage tidak cocok dengan perangkat penerima.', 'KEY_PACKAGE_MISMATCH', 422);
                }

                // Konsumsi KeyPackage
                $keyPackage->is_consumed = true;
                $keyPackage->consumed_at = now();
                $keyPackage->save();

                // Buat challenge operasi Welcome terikat ke session_id
                $welcomeOpUuid = (string) Str::uuid();
                $challengeUuid = (string) Str::uuid();
                $challengeNonce = bin2hex(random_bytes(32));

                $challenge = BchatWelcomeChallenge::create([
                    'welcome_operation_uuid' => $welcomeOpUuid,
                    'room_id'                => $room->id,
                    'session_id'             => $lockedSession->id,
                    'recipient_user_id'      => $recipientUserId,
                    'recipient_device_id'    => $recipientDevice->id,
                    'welcome_message'        => $rawWelcome,
                    'group_id_hash'          => $groupIdHash,
                    'epoch'                  => 1,
                    'operation_status'       => 'ACTIVE',
                    'challenge_uuid'         => $challengeUuid,
                    'challenge_nonce'        => $challengeNonce,
                    'challenge_generation'   => 1,
                    'challenge_status'       => 'ISSUED',
                    'expires_at'             => now()->addMinutes(5),
                ]);

                if ($groupIdHash && $lockedSession->group_id_hash !== $groupIdHash) {
                    $lockedSession->group_id_hash = $groupIdHash;
                    $lockedSession->save();
                }

                return $challenge;
            }, 3);
        } catch (BchatRoomSessionException $e) {
            throw $e;
        } catch (Throwable $e) {
            throw new BchatRoomSessionException(
                'Terjadi kesalahan internal saat memublikasikan Welcome sesi: ' . $e->getMessage(),
                'INTERNAL_WELCOME_ERROR',
                500,
                $e
            );
        }
    }

    /**
     * Memproses Welcome ACK dan mengaktifkan sesi generasi baru secara atomik.
     *
     * @param User $recipient Pengguna penerima Welcome
     * @param BchatRoomSession $session Sesi target
     * @param array<string, mixed> $payloadData Data ACK (challenge_uuid, signature)
     * @param string|null $ipAddress Alamat IP klien
     * @param string|null $userAgent User-Agent klien
     * @return BchatRoomSession
     *
     * @throws BchatRoomSessionException
     */
    public function processSessionWelcomeAck(
        User $recipient,
        BchatRoomSession $session,
        array $payloadData,
        ?string $ipAddress = null,
        ?string $userAgent = null
    ): BchatRoomSession {
        if ($session->status !== 'PENDING') {
            throw new BchatRoomSessionException(
                'Aktivasi ACK hanya diizinkan untuk sesi berstatus PENDING.',
                'INVALID_SESSION_STATUS',
                409
            );
        }

        $room = $session->room;
        if (!$room) {
            throw new BchatRoomSessionException('Ruang obrolan tidak ditemukan.', 'ROOM_NOT_FOUND', 404);
        }

        $challengeUuid = strtolower(trim((string) ($payloadData['challenge_uuid'] ?? '')));
        $signatureBase64 = (string) ($payloadData['signature'] ?? '');

        $signatureBinary = base64_decode($signatureBase64, true);
        if ($signatureBinary === false || strlen($signatureBinary) !== BchatWelcomeAckVerificationService::SIGNATURE_LENGTH) {
            throw new BchatRoomSessionException(
                'Signature digital Welcome ACK tidak valid (wajib 64 byte Ed25519).',
                'INVALID_SIGNATURE_FORMAT',
                422
            );
        }

        try {
            return DB::transaction(function () use (
                $session,
                $room,
                $recipient,
                $challengeUuid,
                $signatureBinary,
                $signatureBase64,
                $ipAddress,
                $userAgent
            ) {
                $lockedSession = BchatRoomSession::where('id', $session->id)->lockForUpdate()->firstOrFail();
                if ($lockedSession->status !== 'PENDING') {
                    throw new BchatRoomSessionException(
                        'Status sesi berubah dan bukan PENDING.',
                        'INVALID_SESSION_STATUS',
                        409
                    );
                }

                $lockedRoom = BchatRoom::where('id', $room->id)->lockForUpdate()->firstOrFail();

                // Cari welcome challenge aktif yang terikat ke sesi ini
                $lockedChallenge = BchatWelcomeChallenge::where('session_id', $lockedSession->id)
                    ->where('operation_status', 'ACTIVE')
                    ->where('challenge_status', 'ISSUED')
                    ->lockForUpdate()
                    ->first();

                if (!$lockedChallenge) {
                    throw new BchatRoomSessionException(
                        'Tantangan Welcome aktif tidak ditemukan untuk sesi ini.',
                        'NO_ACTIVE_WELCOME_OPERATION',
                        409
                    );
                }

                if (strtolower($lockedChallenge->challenge_uuid) !== $challengeUuid) {
                    throw new BchatRoomSessionException(
                        'Challenge UUID tidak cocok dengan tantangan aktif sesi ini.',
                        'CHALLENGE_UUID_MISMATCH',
                        409
                    );
                }

                if (now()->greaterThan($lockedChallenge->expires_at)) {
                    throw new BchatRoomSessionException('Tantangan Welcome telah kedaluwarsa.', 'CHALLENGE_EXPIRED', 409);
                }

                if ((int) $lockedChallenge->recipient_user_id !== (int) $recipient->id) {
                    throw new BchatRoomSessionException('Pengguna bukan penerima sah dari tantangan Welcome ini.', 'FORBIDDEN', 403);
                }

                // Kunci perangkat penerima
                $lockedDevice = BchatUserDevice::where('id', $lockedChallenge->recipient_device_id)
                    ->lockForUpdate()
                    ->first();

                if (!$lockedDevice || $lockedDevice->status !== 'ACTIVE') {
                    throw new BchatRoomSessionException('Perangkat penerima tidak aktif.', 'DEVICE_NOT_ACTIVE', 422);
                }

                if (!$lockedDevice->is_trusted) {
                    throw new BchatRoomSessionException(
                        'Perangkat penerima belum berstatus tepercaya. Lakukan pairing terlebih dahulu.',
                        'DEVICE_NOT_TRUSTED',
                        403
                    );
                }

                // Verifikasi kriptografis Ed25519 Zero-Trust
                $publicKeyBinary = BchatWelcomeAckVerificationService::hexOrBinToBinary($lockedDevice->public_key, 32);
                if ($publicKeyBinary === null) {
                    throw new BchatRoomSessionException('Format public key perangkat penerima tidak valid.', 'INVALID_PUBLIC_KEY', 422);
                }

                $verifyResult = $this->verifier->verify(
                    signatureBinary: $signatureBinary,
                    publicKeyBinary: $publicKeyBinary,
                    roomUuid: $lockedRoom->room_uuid,
                    recipientUserId: (int) $recipient->id,
                    welcomeOperationUuid: $lockedChallenge->welcome_operation_uuid,
                    challengeUuid: $lockedChallenge->challenge_uuid,
                    challengeGeneration: (int) $lockedChallenge->challenge_generation,
                    groupIdHashHexOrBin: $lockedChallenge->group_id_hash,
                    epoch: 1,
                    challengeNonceHexOrBin: $lockedChallenge->challenge_nonce
                );

                if (!$verifyResult->isValid()) {
                    $errorCode = $verifyResult->getErrorCode() ?? 'SIGNATURE_VERIFICATION_FAILED';
                    throw new BchatRoomSessionException(
                        'Verifikasi tanda tangan digital Welcome ACK gagal: ' . $errorCode,
                        $errorCode,
                        422
                    );
                }

                $now = now();

                // Mutasi Challenge
                $lockedChallenge->operation_status = 'CONSUMED';
                $lockedChallenge->challenge_status = 'CONSUMED';
                $lockedChallenge->consumed_at = $now;
                $lockedChallenge->ack_payload_hash = $verifyResult->getPayloadHash();
                $lockedChallenge->ack_signature = $signatureBase64;
                $lockedChallenge->ip_address = $ipAddress;
                $lockedChallenge->user_agent = $userAgent;
                $lockedChallenge->save();

                // Mutasi Sesi: Arsipkan seluruh sesi ACTIVE lama secara atomik
                $oldActiveSessions = BchatRoomSession::where('room_id', $lockedRoom->id)
                    ->where('status', 'ACTIVE')
                    ->where('id', '!=', $lockedSession->id)
                    ->lockForUpdate()
                    ->get();

                foreach ($oldActiveSessions as $oldSession) {
                    $oldSession->status = 'ARCHIVED';
                    $oldSession->archived_at = $now;
                    $oldSession->end_sequence = (int) $lockedRoom->current_sequence;
                    $oldSession->end_epoch = (int) $lockedRoom->current_epoch;
                    $oldSession->save();
                }

                // Mutasi Sesi: PENDING -> ACTIVE
                // Sinkronkan start_sequence dengan sekuens terkini room + 1
                $lockedSession->status = 'ACTIVE';
                $lockedSession->activated_at = $now;
                $lockedSession->start_epoch = 1;
                $lockedSession->start_sequence = (int) $lockedRoom->current_sequence + 1;
                $lockedSession->current_sequence = (int) $lockedRoom->current_sequence;
                $lockedSession->save();

                // Pastikan Room status ACTIVE dan group_id_hash sinkron
                $lockedRoom->status = 'ACTIVE';
                $lockedRoom->current_epoch = 1;
                if (!empty($lockedChallenge->group_id_hash)) {
                    $lockedRoom->group_id_hash = $lockedChallenge->group_id_hash;
                }
                $lockedRoom->save();

                return $lockedSession;
            }, 3);
        } catch (BchatRoomSessionException $e) {
            throw $e;
        } catch (Throwable $e) {
            throw new BchatRoomSessionException(
                'Terjadi kesalahan internal saat memproses Welcome ACK: ' . $e->getMessage(),
                'INTERNAL_ACK_ERROR',
                500,
                $e
            );
        }
    }

    /**
     * Mengambil sesi kriptografi yang sedang ACTIVE pada ruang obrolan.
     */
    public function getActiveSession(BchatRoom|int|string $roomOrUuid): ?BchatRoomSession
    {
        $room = $this->resolveRoom($roomOrUuid);

        return BchatRoomSession::where('room_id', $room->id)
            ->where('status', 'ACTIVE')
            ->first();
    }

    /**
     * Mengambil sesi kriptografi yang sedang PENDING pada ruang obrolan.
     */
    public function getPendingSession(BchatRoom|int|string $roomOrUuid): ?BchatRoomSession
    {
        $room = $this->resolveRoom($roomOrUuid);

        return BchatRoomSession::where('room_id', $room->id)
            ->where('status', 'PENDING')
            ->first();
    }

    /**
     * Mengambil tantangan Welcome aktif untuk sesi generasi yang berstatus PENDING.
     *
     * @param User $recipient Pengguna penerima Welcome
     * @param BchatRoomSession $session Sesi target
     * @return array<string, mixed>
     *
     * @throws BchatRoomSessionException
     */
    public function getSessionWelcomeChallenge(
        User $recipient,
        BchatRoomSession $session
    ): array {
        if ($session->status !== 'PENDING') {
            throw new BchatRoomSessionException(
                'Tantangan Welcome hanya tersedia untuk sesi berstatus PENDING.',
                'INVALID_SESSION_STATUS',
                409
            );
        }

        $room = $session->room;
        if (!$room) {
            throw new BchatRoomSessionException('Ruang obrolan tidak ditemukan.', 'ROOM_NOT_FOUND', 404);
        }

        $this->authorizeSessionAction($recipient, $room);

        $requestDeviceUuid = request()->header('X-Bchat-Device-Uuid');
        $cleanRequestDeviceUuid = ($requestDeviceUuid !== null && trim($requestDeviceUuid) !== '')
            ? strtolower(trim($requestDeviceUuid))
            : null;

        return DB::transaction(function () use ($session, $room, $recipient, $cleanRequestDeviceUuid) {
            $lockedSession = BchatRoomSession::where('id', $session->id)->lockForUpdate()->firstOrFail();
            if ($lockedSession->status !== 'PENDING') {
                throw new BchatRoomSessionException(
                    'Tantangan Welcome hanya tersedia untuk sesi berstatus PENDING.',
                    'INVALID_SESSION_STATUS',
                    409
                );
            }

            $lockedChallenge = BchatWelcomeChallenge::where('session_id', $lockedSession->id)
                ->where('operation_status', 'ACTIVE')
                ->where('challenge_status', 'ISSUED')
                ->lockForUpdate()
                ->first();

            if (!$lockedChallenge) {
                throw new BchatRoomSessionException(
                    'Tantangan Welcome belum dipublikasikan atau sudah tidak aktif untuk sesi ini.',
                    'NO_ACTIVE_WELCOME_OPERATION',
                    404
                );
            }

            if ((int) $lockedChallenge->recipient_user_id !== (int) $recipient->id) {
                throw new BchatRoomSessionException(
                    'Pengguna bukan penerima sah dari tantangan Welcome ini.',
                    'FORBIDDEN',
                    403
                );
            }

            $lockedDevice = BchatUserDevice::where('id', $lockedChallenge->recipient_device_id)
                ->lockForUpdate()
                ->first();

            if (!$lockedDevice || $lockedDevice->status !== 'ACTIVE' || (int) $lockedDevice->user_id !== (int) $recipient->id) {
                throw new BchatRoomSessionException(
                    'Perangkat penerima tantangan tidak aktif atau tidak valid.',
                    'RECIPIENT_DEVICE_NOT_ACTIVE',
                    422
                );
            }

            if (!$lockedDevice->is_trusted) {
                throw new BchatRoomSessionException(
                    'Perangkat penerima belum berstatus tepercaya. Lakukan pairing terlebih dahulu.',
                    'DEVICE_NOT_TRUSTED',
                    403
                );
            }

            if ($cleanRequestDeviceUuid !== null && strtolower((string) $lockedDevice->device_uuid) !== $cleanRequestDeviceUuid) {
                throw new BchatRoomSessionException(
                    'Operasi Welcome aktif ditujukan untuk perangkat yang berbeda.',
                    'RECIPIENT_DEVICE_MISMATCH',
                    403
                );
            }

            // Rotasi challenge in-place jika telah kedaluwarsa (konsisten dengan BchatWelcomeService)
            $now = now();
            if ($lockedChallenge->expires_at && $now->greaterThan($lockedChallenge->expires_at)) {
                if ((int) $lockedChallenge->challenge_generation >= self::MAX_GENERATION) {
                    throw new BchatRoomSessionException(
                        'Batas maksimum generasi tantangan tercapai (overflow guard).',
                        'CHALLENGE_GENERATION_OVERFLOW',
                        409
                    );
                }

                $lockedChallenge->challenge_generation = (int) $lockedChallenge->challenge_generation + 1;
                $lockedChallenge->challenge_uuid = (string) Str::uuid();
                $lockedChallenge->challenge_nonce = bin2hex(random_bytes(32));
                $lockedChallenge->challenge_status = 'ISSUED';
                $lockedChallenge->expires_at = $now->copy()->addMinutes(self::CHALLENGE_TTL_MINUTES);
                $lockedChallenge->save();
            }

            $remainingTtl = max(0, (int) now()->diffInSeconds($lockedChallenge->expires_at, false));

            return [
                'welcome_operation_uuid' => $lockedChallenge->welcome_operation_uuid,
                'session_uuid'           => $lockedSession->session_uuid,
                'room_uuid'              => $room->room_uuid,
                'recipient_device_uuid'  => (string) $lockedDevice->device_uuid,
                'welcome_message'        => base64_encode($lockedChallenge->welcome_message),
                'group_id_hash'          => $lockedChallenge->group_id_hash,
                'epoch'                  => (string) $lockedChallenge->epoch,
                'challenge_uuid'         => $lockedChallenge->challenge_uuid,
                'challenge_nonce'        => $lockedChallenge->challenge_nonce,
                'challenge_generation'   => (int) $lockedChallenge->challenge_generation,
                'expires_at'             => $lockedChallenge->expires_at?->toIso8601String(),
                'ttl_remaining_seconds'  => $remainingTtl,
            ];
        }, 3);
    }

    /**
     * Mengambil seluruh riwayat generasi sesi kriptografi pada ruang obrolan.
     *
     * @return Collection<int, BchatRoomSession>
     */
    public function getSessionHistory(BchatRoom|int|string $roomOrUuid): Collection
    {
        $room = $this->resolveRoom($roomOrUuid);

        return BchatRoomSession::where('room_id', $room->id)
            ->orderBy('generation_number', 'asc')
            ->get();
    }

    /**
     * Mengarsipkan sesi secara eksplisit.
     */
    public function archiveSession(BchatRoomSession $session, ?string $reason = null): BchatRoomSession
    {
        return DB::transaction(function () use ($session, $reason) {
            $locked = BchatRoomSession::where('id', $session->id)->lockForUpdate()->firstOrFail();
            $locked->status = 'ARCHIVED';
            $locked->archived_at = now();
            if ($reason !== null) {
                $locked->failure_reason = $reason;
            }
            $locked->save();

            return $locked;
        });
    }

    /**
     * Menandai sesi sebagai gagal (FAILED).
     */
    public function failSession(BchatRoomSession $session, string $reason): BchatRoomSession
    {
        return DB::transaction(function () use ($session, $reason) {
            $locked = BchatRoomSession::where('id', $session->id)->lockForUpdate()->firstOrFail();
            $locked->status = 'FAILED';
            $locked->failed_at = now();
            $locked->failure_reason = $reason;
            $locked->save();

            return $locked;
        });
    }

    /**
     * Helper resolusi entitas BchatRoom.
     */
    protected function resolveRoom(BchatRoom|int|string $roomOrUuid): BchatRoom
    {
        if ($roomOrUuid instanceof BchatRoom) {
            return $roomOrUuid;
        }

        if (is_int($roomOrUuid)) {
            $room = BchatRoom::find($roomOrUuid);
        } else {
            $cleanUuid = strtolower(trim((string) $roomOrUuid));
            $room = BchatRoom::where('room_uuid', $cleanUuid)->first();
        }

        if (!$room) {
            throw new BchatRoomSessionException('Ruang obrolan tidak ditemukan.', 'ROOM_NOT_FOUND', 404);
        }

        return $room;
    }

    /**
     * Helper otorisasi inisiasi/manajemen sesi.
     */
    protected function authorizeSessionAction(User $user, BchatRoom $room): void
    {
        $member = $room->members()->where('user_id', $user->id)->first();
        if (!$member) {
            throw new BchatRoomSessionException(
                'Pengguna bukan anggota dari ruang obrolan ini.',
                'FORBIDDEN_NOT_MEMBER',
                403
            );
        }

        $penugasan = $room->penugasanPembimbing;
        if (!$penugasan || $penugasan->status_penugasan !== 'AKTIF') {
            throw new BchatRoomSessionException(
                'Penugasan pembimbing akademik untuk ruang obrolan ini tidak aktif.',
                'ASSIGNMENT_INACTIVE',
                403
            );
        }

        if (in_array($room->status, ['TERMINATED', 'SUSPENDED', 'ARCHIVED_READONLY'], true)) {
            throw new BchatRoomSessionException(
                'Tidak dapat mengelola sesi pada ruang obrolan yang telah ditutup atau dinonaktifkan.',
                'INVALID_ROOM_STATUS',
                409
            );
        }

        // Validasi perangkat aktif dan tepercaya
        $deviceQuery = BchatUserDevice::where('user_id', $user->id);
        $requestDeviceUuid = request()->header('X-Bchat-Device-Uuid');
        if ($requestDeviceUuid !== null && trim($requestDeviceUuid) !== '') {
            $deviceQuery->where('device_uuid', strtolower(trim($requestDeviceUuid)));
        }
        $device = $deviceQuery->where('status', 'ACTIVE')->first();

        if (!$device) {
            throw new BchatRoomSessionException(
                'Perangkat pengguna tidak ditemukan atau tidak berstatus aktif.',
                'DEVICE_NOT_ACTIVE',
                403
            );
        }

        if (!$device->is_trusted) {
            throw new BchatRoomSessionException(
                'Perangkat belum berstatus tepercaya. Lakukan pairing terlebih dahulu.',
                'DEVICE_NOT_TRUSTED',
                403
            );
        }
    }
}

