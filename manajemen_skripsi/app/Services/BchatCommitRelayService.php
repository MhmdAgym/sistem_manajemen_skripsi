<?php

declare(strict_types=1);

namespace App\Services;

use App\Events\BchatCommitPublished;
use App\Exceptions\BchatCommitRelayException;
use App\Models\BchatCommit;
use App\Models\BchatCommitAck;
use App\Models\BchatKeyPackage;
use App\Models\BchatRoom;
use App\Models\BchatRoomMember;
use App\Models\BchatRoomSession;
use App\Models\BchatUserDevice;
use App\Models\BchatWelcomeChallenge;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class BchatCommitRelayService
{
    /**
     * Menerbitkan pesan Commit MLS baru sebagai transisi PENDING_ACKS (Fase 1 Two-Phase Commit).
     *
     * INVARIAN DUA FASE:
     * 1. room.current_epoch TIDAK dimajukan saat commit diterbitkan.
     * 2. Pengiriman pesan diblokir pada BchatMessageService selama status PENDING_ACKS.
     * 3. KeyPackage dikunci dan dikonsumsi secara atomik di dalam transaksi database.
     * 4. Pemilik perangkat yang ditambahkan wajib anggota resmi room.
     */
    public function publishCommit(User $senderUser, array $data): array
    {
        $roomUuid = $data['room_uuid'] ?? '';
        $deviceUuid = $data['device_uuid'] ?? '';
        $fromEpoch = (int) ($data['from_epoch'] ?? 0);
        $toEpoch = (int) ($data['to_epoch'] ?? 0);
        $commitWireBase64 = $data['commit_wire_base64'] ?? '';
        $addedDeviceUuid = $data['added_device_uuid'] ?? null;
        $keyPackageHash = $data['key_package_hash'] ?? null;
        $welcomeWireBase64 = $data['welcome_wire_base64'] ?? null;

        if (empty($commitWireBase64)) {
            throw new BchatCommitRelayException('commit_wire_base64 tidak boleh kosong.', 'EMPTY_COMMIT_PAYLOAD', 422);
        }

        $rawCommitBytes = base64_decode($commitWireBase64, true);
        if ($rawCommitBytes === false || strlen($rawCommitBytes) === 0) {
            throw new BchatCommitRelayException('commit_wire_base64 bukan format Base64 RFC 4648 yang valid.', 'INVALID_COMMIT_BASE64', 422);
        }

        $commitHash = hash('sha256', $rawCommitBytes);

        // Eksekusi dalam transaksi atomik dengan lock hierarchy
        $result = DB::transaction(function () use (
            $senderUser,
            $roomUuid,
            $deviceUuid,
            $fromEpoch,
            $toEpoch,
            $rawCommitBytes,
            $commitHash,
            $addedDeviceUuid,
            $keyPackageHash,
            $welcomeWireBase64,
            $data
        ) {
            // 1. Kunci Room via SELECT ... FOR UPDATE
            $room = BchatRoom::where('room_uuid', $roomUuid)->lockForUpdate()->first();
            if (!$room) {
                throw new BchatCommitRelayException('Ruang obrolan tidak ditemukan.', 'ROOM_NOT_FOUND', 404);
            }

            if ($room->status !== 'ACTIVE') {
                throw new BchatCommitRelayException('Ruang obrolan tidak dalam status aktif.', 'ROOM_NOT_ACTIVE', 422);
            }

            // 2. Verifikasi keanggotaan pengirim
            $isMember = BchatRoomMember::where('room_id', $room->id)
                ->where('user_id', $senderUser->id)
                ->where('is_active', true)
                ->exists();
            if (!$isMember) {
                throw new BchatCommitRelayException('Pengguna bukan anggota aktif pada ruang obrolan ini.', 'NOT_ROOM_MEMBER', 403);
            }

            // 3. Verifikasi perangkat pengirim
            $senderDevice = BchatUserDevice::where('device_uuid', $deviceUuid)
                ->where('user_id', $senderUser->id)
                ->first();
            if (!$senderDevice || $senderDevice->status !== 'ACTIVE' || !$senderDevice->is_trusted) {
                throw new BchatCommitRelayException('Perangkat pengirim tidak aktif atau belum terverifikasi tepercaya.', 'DEVICE_NOT_TRUSTED', 403);
            }

            // 4. Verifikasi sesi aktif
            $activeSession = BchatRoomSession::where('room_id', $room->id)
                ->where('status', 'ACTIVE')
                ->lockForUpdate()
                ->first();
            if (!$activeSession) {
                throw new BchatCommitRelayException('Tidak ada sesi kriptografi aktif pada ruang obrolan ini.', 'NO_ACTIVE_SESSION', 422);
            }

            // 5. Pencegahan konkurensi: Tidak boleh ada 2 transisi berjalan bersamaan
            $existingPending = BchatCommit::where('room_id', $room->id)
                ->where('status', 'PENDING_ACKS')
                ->lockForUpdate()
                ->first();
            if ($existingPending) {
                // Jika commit_hash sama persis, ini adalah retry idempoten
                if ($existingPending->commit_hash === $commitHash) {
                    return [
                        'commit'      => $existingPending,
                        'is_replayed' => true,
                    ];
                }
                throw new BchatCommitRelayException(
                    "Terdapat transisi epoch yang sedang berlangsung (commit: {$existingPending->commit_uuid}). Selesaikan transisi terlebih dahulu.",
                    'TRANSITION_ALREADY_PENDING',
                    409
                );
            }

            // 6. Idempotensi Mutlak berdasarkan commit_hash
            $existingCommit = BchatCommit::where('commit_hash', $commitHash)->first();
            if ($existingCommit) {
                if ($existingCommit->room_id === $room->id &&
                    (int) $existingCommit->from_epoch === $fromEpoch &&
                    (int) $existingCommit->to_epoch === $toEpoch) {
                    return [
                        'commit'      => $existingCommit,
                        'is_replayed' => true,
                    ];
                }
                throw new BchatCommitRelayException('Benturan idempotensi commit: commit_hash sudah ada dengan konteks berbeda.', 'COMMIT_CONFLICT', 409);
            }

            // 7. Validasi Monotonisitas Epoch
            if ($fromEpoch !== (int) $room->current_epoch) {
                throw new BchatCommitRelayException(
                    "from_epoch [{$fromEpoch}] tidak sesuai dengan epoch aktif room saat ini [{$room->current_epoch}].",
                    'EPOCH_MISMATCH',
                    422
                );
            }

            if ($toEpoch !== $fromEpoch + 1) {
                throw new BchatCommitRelayException(
                    "to_epoch [{$toEpoch}] wajib tepat 1 tingkat lebih tinggi dari from_epoch [{$fromEpoch}].",
                    'INVALID_TARGET_EPOCH',
                    422
                );
            }

            // Cek apakah transisi dari from_epoch ini sudah pernah selesai diterbitkan sebelumnya
            $existingTransition = BchatCommit::where('room_id', $room->id)
                ->where('from_epoch', $fromEpoch)
                ->where('status', 'ACTIVE')
                ->first();
            if ($existingTransition) {
                throw new BchatCommitRelayException(
                    "Transisi epoch dari [{$fromEpoch}] sudah pernah diselesaikan sebelumnya oleh commit [{$existingTransition->commit_uuid}].",
                    'EPOCH_TRANSITION_ALREADY_EXISTS',
                    409
                );
            }

            // 8. Validasi Ketat Perangkat Tambahan & Konsumsi KeyPackage (Pola BchatRoomSessionService)
            $addedDevice = null;
            $welcomeOpUuid = null;
            $keyPackage = null;

            if ($addedDeviceUuid) {
                $addedDevice = BchatUserDevice::where('device_uuid', $addedDeviceUuid)->first();
                if (!$addedDevice || $addedDevice->status !== 'ACTIVE' || !$addedDevice->is_trusted) {
                    throw new BchatCommitRelayException('Perangkat yang ditambahkan tidak valid atau belum berstatus tepercaya.', 'ADDED_DEVICE_INVALID', 422);
                }

                // INVARIAN OTORISASI: Pemilik perangkat yang ditambahkan WAJIB anggota resmi ruang obrolan
                $isAddedUserMember = BchatRoomMember::where('room_id', $room->id)
                    ->where('user_id', $addedDevice->user_id)
                    ->where('is_active', true)
                    ->exists();
                if (!$isAddedUserMember) {
                    throw new BchatCommitRelayException('Pemilik perangkat yang ditambahkan bukan anggota aktif ruang obrolan.', 'ADDED_DEVICE_NOT_ROOM_MEMBER', 403);
                }

                // INVARIAN KEYPACKAGE: Kunci & konsumsi KeyPackage atomik di dalam transaksi yang sama
                if (empty($keyPackageHash)) {
                    throw new BchatCommitRelayException('key_package_hash wajib disertakan saat menambahkan perangkat.', 'KEY_PACKAGE_HASH_REQUIRED', 422);
                }

                $keyPackage = BchatKeyPackage::where('key_package_hash', $keyPackageHash)
                    ->lockForUpdate()
                    ->first();

                if (!$keyPackage) {
                    throw new BchatCommitRelayException('KeyPackage tidak ditemukan.', 'KEY_PACKAGE_NOT_FOUND', 404);
                }

                if ((bool) $keyPackage->is_consumed) {
                    throw new BchatCommitRelayException('KeyPackage sudah pernah dikonsumsi.', 'KEY_PACKAGE_ALREADY_CONSUMED', 409);
                }

                if ((int) $keyPackage->user_id !== (int) $addedDevice->user_id || (int) $keyPackage->device_id !== (int) $addedDevice->id) {
                    throw new BchatCommitRelayException('KeyPackage tidak cocok dengan perangkat penerima.', 'KEY_PACKAGE_MISMATCH', 422);
                }

                // Tandai KeyPackage terkonsumsi di dalam transaksi ini
                $keyPackage->is_consumed = true;
                $keyPackage->consumed_at = now();
                $keyPackage->save();

                // Welcome Challenge untuk perangkat baru
                if ($welcomeWireBase64) {
                    $rawWelcomeBytes = base64_decode($welcomeWireBase64, true);
                    if ($rawWelcomeBytes === false || strlen($rawWelcomeBytes) === 0) {
                        throw new BchatCommitRelayException('welcome_wire_base64 tidak valid.', 'INVALID_WELCOME_BASE64', 422);
                    }

                    $welcomeOpUuid = (string) Str::uuid();
                    $challengeUuid = (string) Str::uuid();
                    $challengeNonce = bin2hex(random_bytes(32));

                    BchatWelcomeChallenge::create([
                        'welcome_operation_uuid' => $welcomeOpUuid,
                        'room_id'                => $room->id,
                        'session_id'             => $activeSession->id,
                        'recipient_user_id'      => $addedDevice->user_id,
                        'recipient_device_id'    => $addedDevice->id,
                        'welcome_message'        => $rawWelcomeBytes,
                        'group_id_hash'          => $activeSession->group_id_hash ?? $room->group_id_hash ?? '',
                        'epoch'                  => $toEpoch,
                        'operation_status'       => 'ACTIVE',
                        'challenge_uuid'         => $challengeUuid,
                        'challenge_nonce'        => $challengeNonce,
                        'challenge_generation'   => 1,
                        'challenge_status'       => 'ISSUED',
                        'expires_at'             => now()->addMinutes(30),
                    ]);
                }
            }

            // 9. Simpan Rekaman BchatCommit dengan status PENDING_ACKS (Two-Phase Gate)
            $commitUuid = $data['commit_uuid'] ?? (string) Str::uuid();
            $commit = BchatCommit::create([
                'commit_uuid'            => $commitUuid,
                'room_id'                => $room->id,
                'session_id'             => $activeSession->id,
                'sender_user_id'         => $senderUser->id,
                'sender_device_id'       => $senderDevice->id,
                'from_epoch'             => $fromEpoch,
                'to_epoch'               => $toEpoch,
                'commit_message'         => $rawCommitBytes,
                'commit_hash'            => $commitHash,
                'added_device_id'        => $addedDevice?->id,
                'key_package_id'         => $keyPackage?->id,
                'welcome_operation_uuid' => $welcomeOpUuid,
                'status'                 => 'PENDING_ACKS',
            ]);

            // 10. Otomatis catat COMMIT_ACK untuk pengirim/inisiator
            $canonicalSenderPayload = "COMMIT_ACK:{$room->room_uuid}:{$activeSession->session_uuid}:{$commit->commit_uuid}:{$senderDevice->device_uuid}:{$toEpoch}";
            BchatCommitAck::create([
                'commit_id'         => $commit->id,
                'device_id'         => $senderDevice->id,
                'user_id'           => $senderUser->id,
                'ack_type'          => 'COMMIT_ACK',
                'acked_epoch'       => $toEpoch,
                'signature'         => 'INITIATOR_SELF_ACK',
                'canonical_payload' => $canonicalSenderPayload,
                'acked_at'          => now(),
            ]);

            // INVARIAN KRUSIAL TWO-PHASE:
            // room.current_epoch TIDAK DIMAJUKAN DI SINI!
            // Epoch hanya maju setelah seluruh ACK yang diwajibkan lengkap via evaluateAndFinalizeTransition.

            self::evaluateAndFinalizeTransition($commit, $room, $activeSession);

            return [
                'commit'      => $commit->fresh(),
                'is_replayed' => false,
            ];
        }, 3);

        // 11. Siarkan sinyal realtime Reverb (After-Commit)
        if (!$result['is_replayed']) {
            try {
                $commit = $result['commit'];
                event(new BchatCommitPublished(
                    roomUuid: $roomUuid,
                    commitUuid: $commit->commit_uuid,
                    fromEpoch: (int) $commit->from_epoch,
                    toEpoch: (int) $commit->to_epoch,
                    commitHash: $commit->commit_hash,
                    addedDeviceUuid: $addedDeviceUuid,
                    createdAt: $commit->created_at?->toIso8601String() ?? now()->toIso8601String()
                ));
            } catch (Throwable $e) {
                Log::warning('Sinyal Reverb BchatCommitPublished gagal disiarkan: ' . $e->getMessage());
            }
        }

        return $result;
    }

    /**
     * Mengambil daftar commit yang belum di-ACK atau commit sejak epoch tertentu.
     */
    /**
     * Mengambil daftar commit yang belum di-ACK atau commit sejak epoch tertentu.
     * Mendukung pemulihan Welcome aktif (?include_active_welcome=true) secara read-only untuk target perangkat yang sah.
     */
    public function getPendingCommits(
        User $user,
        BchatUserDevice $device,
        string $roomUuid,
        ?int $sinceEpoch = null,
        bool $includeActiveWelcome = false
    ): array {
        $room = BchatRoom::where('room_uuid', $roomUuid)->first();
        if (!$room) {
            throw new BchatCommitRelayException('Ruang obrolan tidak ditemukan.', 'ROOM_NOT_FOUND', 404);
        }

        $isMember = BchatRoomMember::where('room_id', $room->id)
            ->where('user_id', $user->id)
            ->where('is_active', true)
            ->exists();
        if (!$isMember) {
            throw new BchatCommitRelayException('Bukan anggota aktif ruang obrolan.', 'NOT_ROOM_MEMBER', 403);
        }

        $query = BchatCommit::where('room_id', $room->id)
            ->whereIn('status', ['PENDING_ACKS', 'ACTIVE'])
            ->orderBy('to_epoch', 'asc');

        if ($sinceEpoch !== null) {
            $query->where('to_epoch', '>', $sinceEpoch);
        } else {
            $ackedCommitIds = BchatCommitAck::where('device_id', $device->id)->pluck('commit_id');
            if ($includeActiveWelcome) {
                // Sertakan commit yang belum di-ACK ATAU commit aktif pada epoch saat ini di mana perangkat ini adalah target penambahan yang sah
                $query->where(function ($q) use ($ackedCommitIds, $device, $room) {
                    $q->whereNotIn('id', $ackedCommitIds)
                      ->orWhere(function ($sub) use ($device, $room) {
                          $sub->where('added_device_id', $device->id)
                              ->where('status', 'ACTIVE')
                              ->where('to_epoch', $room->current_epoch);
                      });
                });
            } else {
                $query->whereNotIn('id', $ackedCommitIds);
            }
        }

        $commits = $query->get();

        return $commits->map(function ($c) use ($device) {
            $welcomeWire = null;
            if ($c->added_device_id === $device->id && $c->welcome_operation_uuid) {
                $challenge = BchatWelcomeChallenge::where('welcome_operation_uuid', $c->welcome_operation_uuid)
                    ->where('recipient_device_id', $device->id)
                    ->first();
                if ($challenge && $challenge->welcome_message) {
                    $welcomeWire = base64_encode($challenge->welcome_message);
                }
            }

            return [
                'commit_uuid'            => $c->commit_uuid,
                'from_epoch'             => (string) $c->from_epoch,
                'to_epoch'               => (string) $c->to_epoch,
                'commit_wire_base64'     => base64_encode($c->commit_message),
                'welcome_wire_base64'    => $welcomeWire,
                'commit_hash'            => $c->commit_hash,
                'status'                 => $c->status,
                'sender_device_uuid'     => $c->senderDevice?->device_uuid,
                'added_device_uuid'      => $c->addedDevice?->device_uuid,
                'key_package_hash'       => $c->keyPackage?->key_package_hash,
                'welcome_operation_uuid' => $c->welcome_operation_uuid,
                'created_at'             => $c->created_at?->toIso8601String(),
            ];
        })->values()->all();
    }

    /**
     * Mencatat bukti COMMIT_ACK kriptografis Ed25519 untuk ANGGOTA LAMA yang memproses pesan Commit.
     */
    public function ackCommit(User $user, BchatUserDevice $device, string $commitUuid, string $signature, int $ackedEpoch): array
    {
        return DB::transaction(function () use ($user, $device, $commitUuid, $signature, $ackedEpoch) {
            $commit = BchatCommit::where('commit_uuid', $commitUuid)->lockForUpdate()->first();
            if (!$commit) {
                throw new BchatCommitRelayException('Commit tidak ditemukan.', 'COMMIT_NOT_FOUND', 404);
            }

            $room = BchatRoom::where('id', $commit->room_id)->lockForUpdate()->first();
            $session = BchatRoomSession::where('id', $commit->session_id)->lockForUpdate()->first();

            // 1. Verifikasi Keanggotaan User pada Room
            $isMember = BchatRoomMember::where('room_id', $room->id)
                ->where('user_id', $user->id)
                ->where('is_active', true)
                ->exists();
            if (!$isMember) {
                throw new BchatCommitRelayException('Pengguna bukan anggota aktif ruang obrolan ini.', 'NOT_ROOM_MEMBER', 403);
            }

            // 2. Verifikasi Perangkat Pengirim
            if ((int) $device->user_id !== (int) $user->id || $device->status !== 'ACTIVE' || !$device->is_trusted) {
                throw new BchatCommitRelayException('Perangkat tidak valid atau belum berstatus tepercaya.', 'DEVICE_NOT_TRUSTED', 403);
            }

            // 3. Verifikasi Tipe ACK: Perangkat baru yang ditambahkan TIDAK BOLEH mengirim COMMIT_ACK
            if (!empty($commit->added_device_id) && (int) $device->id === (int) $commit->added_device_id) {
                throw new BchatCommitRelayException(
                    'Perangkat yang baru ditambahkan wajib mengirimkan WELCOME_ACK setelah memproses Welcome, bukan COMMIT_ACK.',
                    'INVALID_ACK_TYPE_FOR_DEVICE',
                    422
                );
            }

            // 4. Verifikasi Epoch
            if ((int) $commit->to_epoch !== $ackedEpoch) {
                throw new BchatCommitRelayException(
                    "acked_epoch [{$ackedEpoch}] tidak sesuai dengan to_epoch commit [{$commit->to_epoch}].",
                    'EPOCH_MISMATCH',
                    422
                );
            }

            // 5. Idempotensi ACK
            $existingAck = BchatCommitAck::where('commit_id', $commit->id)
                ->where('device_id', $device->id)
                ->first();
            if ($existingAck) {
                return [
                    'ack'         => $existingAck,
                    'is_replayed' => true,
                    'status'      => $commit->status,
                    'is_complete' => $commit->status === 'ACTIVE',
                ];
            }

            // 6. Verifikasi Signature Ed25519 Terikat Konteks Lengkap
            $canonicalPayload = "COMMIT_ACK:{$room->room_uuid}:{$session->session_uuid}:{$commit->commit_uuid}:{$device->device_uuid}:{$ackedEpoch}";
            self::verifyEd25519Signature($device, $canonicalPayload, $signature);

            // 7. Simpan BchatCommitAck
            $ack = BchatCommitAck::create([
                'commit_id'         => $commit->id,
                'device_id'         => $device->id,
                'user_id'           => $user->id,
                'ack_type'          => 'COMMIT_ACK',
                'acked_epoch'       => $ackedEpoch,
                'signature'         => $signature,
                'canonical_payload' => $canonicalPayload,
                'acked_at'          => now(),
            ]);

            // 8. Evaluasi Kuorum Transisi Dua Fase
            $finalized = self::evaluateAndFinalizeTransition($commit, $room, $session);

            return [
                'ack'         => $ack,
                'is_replayed' => false,
                'status'      => $commit->fresh()->status,
                'is_complete' => $finalized,
            ];
        }, 3);
    }

    /**
     * Mencatat bukti WELCOME_ACK kriptografis Ed25519 untuk ANGGOTA BARU yang memproses pesan Welcome.
     */
    public function ackWelcome(User $user, BchatUserDevice $device, string $commitUuid, string $signature, int $ackedEpoch): array
    {
        return DB::transaction(function () use ($user, $device, $commitUuid, $signature, $ackedEpoch) {
            $commit = BchatCommit::where('commit_uuid', $commitUuid)->lockForUpdate()->first();
            if (!$commit) {
                throw new BchatCommitRelayException('Commit tidak ditemukan.', 'COMMIT_NOT_FOUND', 404);
            }

            $room = BchatRoom::where('id', $commit->room_id)->lockForUpdate()->first();
            $session = BchatRoomSession::where('id', $commit->session_id)->lockForUpdate()->first();

            // 1. Verifikasi Keanggotaan User pada Room
            $isMember = BchatRoomMember::where('room_id', $room->id)
                ->where('user_id', $user->id)
                ->where('is_active', true)
                ->exists();
            if (!$isMember) {
                throw new BchatCommitRelayException('Pengguna bukan anggota aktif ruang obrolan ini.', 'NOT_ROOM_MEMBER', 403);
            }

            // 2. Verifikasi Perangkat Pengirim
            if ((int) $device->user_id !== (int) $user->id || $device->status !== 'ACTIVE' || !$device->is_trusted) {
                throw new BchatCommitRelayException('Perangkat tidak valid atau belum berstatus tepercaya.', 'DEVICE_NOT_TRUSTED', 403);
            }

            // 3. Verifikasi Sasaran: Hanya perangkat yang ditambahkan yang boleh mengirim WELCOME_ACK
            if (empty($commit->added_device_id) || (int) $device->id !== (int) $commit->added_device_id) {
                throw new BchatCommitRelayException(
                    'Perangkat ini bukan perangkat yang ditambahkan pada transaksi Commit ini.',
                    'DEVICE_NOT_ADDED_RECIPIENT',
                    422
                );
            }

            // 4. Verifikasi Epoch
            if ((int) $commit->to_epoch !== $ackedEpoch) {
                throw new BchatCommitRelayException(
                    "acked_epoch [{$ackedEpoch}] tidak sesuai dengan to_epoch commit [{$commit->to_epoch}].",
                    'EPOCH_MISMATCH',
                    422
                );
            }

            // 5. Idempotensi ACK
            $existingAck = BchatCommitAck::where('commit_id', $commit->id)
                ->where('device_id', $device->id)
                ->first();
            if ($existingAck) {
                return [
                    'ack'         => $existingAck,
                    'is_replayed' => true,
                    'status'      => $commit->status,
                    'is_complete' => $commit->status === 'ACTIVE',
                ];
            }

            // 6. Verifikasi Signature Ed25519 Terikat Konteks Lengkap
            $canonicalPayload = "WELCOME_ACK:{$room->room_uuid}:{$session->session_uuid}:{$commit->commit_uuid}:{$device->device_uuid}:{$ackedEpoch}";
            self::verifyEd25519Signature($device, $canonicalPayload, $signature);

            // 7. Simpan BchatCommitAck
            $ack = BchatCommitAck::create([
                'commit_id'         => $commit->id,
                'device_id'         => $device->id,
                'user_id'           => $user->id,
                'ack_type'          => 'WELCOME_ACK',
                'acked_epoch'       => $ackedEpoch,
                'signature'         => $signature,
                'canonical_payload' => $canonicalPayload,
                'acked_at'          => now(),
            ]);

            // 8. Konsumsi Welcome Challenge jika ada
            if ($commit->welcome_operation_uuid) {
                BchatWelcomeChallenge::where('welcome_operation_uuid', $commit->welcome_operation_uuid)
                    ->update([
                        'operation_status' => 'CONSUMED',
                        'challenge_status' => 'CONSUMED',
                        'consumed_at'      => now(),
                    ]);
            }

            // 9. Evaluasi Kuorum Transisi Dua Fase
            $finalized = self::evaluateAndFinalizeTransition($commit, $room, $session);

            return [
                'ack'         => $ack,
                'is_replayed' => false,
                'status'      => $commit->fresh()->status,
                'is_complete' => $finalized,
            ];
        }, 3);
    }

    /**
     * Evaluasi kuorum transisi: Majukan epoch room HANYA jika seluruh ACK lengkap.
     */
    private static function evaluateAndFinalizeTransition(BchatCommit $commit, BchatRoom $room, BchatRoomSession $session): bool
    {
        // 1. Ambil seluruh anggota resmi room
        $memberUserIds = BchatRoomMember::where('room_id', $room->id)
            ->where('is_active', true)
            ->pluck('user_id');

        // 2. Daftar perangkat eksisting yang wajib memberikan COMMIT_ACK
        // (Semua perangkat aktif & tepercaya milik anggota, kecuali perangkat baru yang ditambahkan)
        $existingDeviceQuery = BchatUserDevice::whereIn('user_id', $memberUserIds)
            ->where('status', 'ACTIVE')
            ->where('is_trusted', 1);

        if ($commit->added_device_id) {
            $existingDeviceQuery->where('id', '!=', $commit->added_device_id);
        }

        $requiredCommitDeviceIds = $existingDeviceQuery->pluck('id')->all();

        $ackedCommitDeviceIds = BchatCommitAck::where('commit_id', $commit->id)
            ->where('ack_type', 'COMMIT_ACK')
            ->pluck('device_id')
            ->all();

        $missingCommitAcks = array_diff($requiredCommitDeviceIds, $ackedCommitDeviceIds);
        $hasAllCommitAcks = empty($missingCommitAcks);

        // 3. Verifikasi WELCOME_ACK untuk perangkat baru jika ada added_device_id
        $hasWelcomeAck = true;
        if ($commit->added_device_id) {
            $hasWelcomeAck = BchatCommitAck::where('commit_id', $commit->id)
                ->where('device_id', $commit->added_device_id)
                ->where('ack_type', 'WELCOME_ACK')
                ->exists();
        }

        // 4. Jika seluruh syarat terpenuhi, FINALISASI TRANSISI (Fase 2)
        if ($hasAllCommitAcks && $hasWelcomeAck) {
            $commit->status = 'ACTIVE';
            $commit->transition_completed_at = now();
            $commit->save();

            // Secara resmi majukan epoch room
            $room->current_epoch = $commit->to_epoch;
            $room->save();

            return true;
        }

        return false;
    }

    /**
     * Memverifikasi tanda tangan Ed25519 dengan aman via libsodium.
     */
    private static function verifyEd25519Signature(BchatUserDevice $device, string $canonicalPayload, string $signature): void
    {
        $rawPubKey = hex2bin($device->public_key);
        if ($rawPubKey === false || strlen($rawPubKey) !== 32) {
            throw new BchatCommitRelayException('Kunci publik perangkat tidak valid.', 'INVALID_DEVICE_PUBKEY', 422);
        }

        $rawSig = ctype_xdigit($signature) ? hex2bin($signature) : base64_decode($signature, true);
        if ($rawSig === false || strlen($rawSig) !== 64) {
            throw new BchatCommitRelayException('Format tanda tangan Ed25519 tidak valid (wajib 64 byte).', 'INVALID_SIGNATURE_FORMAT', 422);
        }

        if (!function_exists('sodium_crypto_sign_verify_detached')) {
            throw new BchatCommitRelayException('Ekstensi Sodium tidak tersedia di server.', 'SODIUM_UNAVAILABLE', 500);
        }

        $isValid = @sodium_crypto_sign_verify_detached($rawSig, $canonicalPayload, $rawPubKey);
        if (!$isValid) {
            throw new BchatCommitRelayException('Tanda tangan Ed25519 tidak valid untuk ACK ini.', 'INVALID_COMMIT_ACK_SIGNATURE', 422);
        }
    }
}
