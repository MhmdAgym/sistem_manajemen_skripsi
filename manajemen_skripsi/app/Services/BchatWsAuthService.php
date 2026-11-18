<?php

namespace App\Services;

use App\Exceptions\BchatDeviceException;
use App\Models\BchatRoom;
use App\Models\BchatUserDevice;
use App\Models\BchatWsAuthChallenge;
use App\Models\User;
use Exception;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class BchatWsAuthService
{
    public const DEFAULT_CHALLENGE_TTL_SECONDS = 60;

    /**
     * Menerbitkan tantangan kriptografis baru untuk autentikasi kanal Reverb WebSocket.
     *
     * @param User $user Pengguna yang terautentikasi
     * @param string $deviceUuid UUID perangkat yang meminta otorisasi
     * @param string $socketId Socket ID Reverb
     * @param string $channelName Nama kanal privat (contoh: private-bchat.rooms.{uuid})
     * @return array<string, mixed>
     */
    public function issueChallenge(
        User $user,
        string $deviceUuid,
        string $socketId,
        string $channelName
    ): array {
        $cleanDeviceUuid = strtolower(trim($deviceUuid));
        if (!Str::isUuid($cleanDeviceUuid)) {
            throw new BchatDeviceException('Format Device UUID tidak valid.', 'INVALID_DEVICE_UUID', 422);
        }

        $cleanSocketId = trim($socketId);
        if ($cleanSocketId === '') {
            throw new BchatDeviceException('Socket ID wajib diisi.', 'INVALID_SOCKET_ID', 422);
        }

        $cleanChannelName = trim($channelName);
        if ($cleanChannelName === '') {
            throw new BchatDeviceException('Channel Name wajib diisi.', 'INVALID_CHANNEL_NAME', 422);
        }

        // Ekstrak room UUID dari nama kanal jika berupa kanal ruang bimbingan
        if (str_starts_with($cleanChannelName, 'private-bchat.rooms.')) {
            $roomUuid = str_replace('private-bchat.rooms.', '', $cleanChannelName);
        } elseif (str_starts_with($cleanChannelName, 'bchat.rooms.')) {
            $roomUuid = str_replace('bchat.rooms.', '', $cleanChannelName);
        } else {
            throw new BchatDeviceException('Format kanal WebSocket tidak didukung untuk verifikasi E2EE.', 'UNSUPPORTED_CHANNEL', 422);
        }

        $cleanRoomUuid = strtolower(trim($roomUuid));
        if (!Str::isUuid($cleanRoomUuid)) {
            throw new BchatDeviceException('Format Room UUID pada kanal tidak valid.', 'INVALID_ROOM_UUID', 422);
        }

        $room = BchatRoom::with(['members', 'penugasanPembimbing'])
            ->where('room_uuid', $cleanRoomUuid)
            ->first();

        if (!$room) {
            throw new BchatDeviceException('Ruang obrolan bimbingan tidak ditemukan.', 'ROOM_NOT_FOUND', 404);
        }

        // Verifikasi hak akses pengguna terhadap ruang obrolan (BchatRoomPolicy::view)
        if (!$user->can('view', $room)) {
            throw new BchatDeviceException('Anda tidak memiliki hak akses terhadap ruang obrolan ini.', 'ROOM_ACCESS_DENIED', 403);
        }

        // Verifikasi perangkat: wajib milik akun, status ACTIVE, dan is_trusted = true
        $device = BchatUserDevice::where('user_id', $user->id)
            ->where('device_uuid', $cleanDeviceUuid)
            ->first();

        if (!$device) {
            throw new BchatDeviceException('Perangkat tidak ditemukan pada akun ini.', 'DEVICE_NOT_FOUND', 404);
        }

        if (!$device->isActive()) {
            throw new BchatDeviceException('Perangkat tidak aktif atau telah dicabut (REVOKED).', 'DEVICE_NOT_ACTIVE', 403);
        }

        if (!$device->isTrusted()) {
            throw new BchatDeviceException('Perangkat belum berstatus tepercaya (wajib pairing terlebih dahulu).', 'DEVICE_NOT_TRUSTED', 403);
        }

        return DB::transaction(function () use ($user, $device, $cleanDeviceUuid, $cleanSocketId, $cleanChannelName) {
            // Expire challenge aktif sebelumnya untuk tuple (user, device, socket, channel) yang sama
            BchatWsAuthChallenge::where('user_id', $user->id)
                ->where('device_uuid', $cleanDeviceUuid)
                ->where('socket_id', $cleanSocketId)
                ->where('channel_name', $cleanChannelName)
                ->where('status', 'ISSUED')
                ->update(['status' => 'EXPIRED']);

            $challengeUuid = (string) Str::uuid();
            $nonceHex = bin2hex(random_bytes(32));
            $ttlSeconds = (int) config('bchat.ws_challenge_ttl_seconds', self::DEFAULT_CHALLENGE_TTL_SECONDS);
            $expiresAt = now()->addSeconds($ttlSeconds);

            $payloadBinary = BchatWsAuthVerificationService::buildCanonicalPayload(
                userId: $user->id,
                deviceUuid: $cleanDeviceUuid,
                socketId: $cleanSocketId,
                channelName: $cleanChannelName,
                nonceHex: $nonceHex,
                expiresAtTimestamp: $expiresAt->timestamp
            );

            $challenge = BchatWsAuthChallenge::create([
                'user_id'         => $user->id,
                'device_id'       => $device->id,
                'challenge_uuid'  => $challengeUuid,
                'device_uuid'     => $cleanDeviceUuid,
                'socket_id'       => $cleanSocketId,
                'channel_name'    => $cleanChannelName,
                'challenge_nonce' => $nonceHex,
                'status'          => 'ISSUED',
                'expires_at'      => $expiresAt,
            ]);

            return [
                'challenge_uuid'     => $challenge->challenge_uuid,
                'challenge_nonce'    => $challenge->challenge_nonce,
                'device_uuid'        => $device->device_uuid,
                'socket_id'          => $cleanSocketId,
                'channel_name'       => $cleanChannelName,
                'expires_at'         => $challenge->expires_at->toIso8601String(),
                'expires_in_seconds' => $ttlSeconds,
                'payload_hex'        => bin2hex($payloadBinary),
            ];
        });
    }

    /**
     * Memverifikasi tanda tangan Ed25519 dan mengonsumsi tantangan autentikasi kanal WebSocket.
     */
    public function verifyAndConsumeChallenge(
        User $user,
        string $deviceUuid,
        string $challengeUuid,
        string $signatureHex,
        string $socketId,
        string $channelName
    ): bool {
        $cleanDeviceUuid = strtolower(trim($deviceUuid));
        $cleanChallengeUuid = strtolower(trim($challengeUuid));
        $cleanSignatureHex = strtolower(trim($signatureHex));
        $cleanSocketId = trim($socketId);
        $cleanChannelName = trim($channelName);

        if (!Str::isUuid($cleanDeviceUuid) || !Str::isUuid($cleanChallengeUuid)) {
            return false;
        }

        if (strlen($cleanSignatureHex) !== 128 || !ctype_xdigit($cleanSignatureHex)) {
            return false;
        }

        return DB::transaction(function () use (
            $user,
            $cleanDeviceUuid,
            $cleanChallengeUuid,
            $cleanSignatureHex,
            $cleanSocketId,
            $cleanChannelName
        ) {
            // Kunci baris user untuk mencegah race condition
            User::where('id', $user->id)->lockForUpdate()->firstOrFail();

            // 1. Verifikasi perangkat: harus aktif dan is_trusted
            $device = BchatUserDevice::where('user_id', $user->id)
                ->where('device_uuid', $cleanDeviceUuid)
                ->lockForUpdate()
                ->first();

            if (!$device || !$device->isActive() || !$device->isTrusted()) {
                return false;
            }

            // 2. Kunci dan validasi sesi challenge
            $challenge = BchatWsAuthChallenge::where('challenge_uuid', $cleanChallengeUuid)
                ->where('user_id', $user->id)
                ->where('device_uuid', $cleanDeviceUuid)
                ->lockForUpdate()
                ->first();

            if (!$challenge) {
                return false;
            }

            // Anti-replay: challenge harus berstatus ISSUED
            if ($challenge->status !== 'ISSUED') {
                return false;
            }

            // Kedaluwarsa: tolak jika melewati batas waktu
            if (now()->isAfter($challenge->expires_at)) {
                $challenge->update(['status' => 'EXPIRED']);
                return false;
            }

            // Validasi binding kontekstual ketat ke socket_id dan channel_name
            // Normalisasi nama kanal (hilangkan prefix private- jika ada pembanding)
            $normChallengeChannel = ltrim($challenge->channel_name, 'private-');
            $normReqChannel = ltrim($cleanChannelName, 'private-');

            if ($challenge->socket_id !== $cleanSocketId || $normChallengeChannel !== $normReqChannel) {
                return false;
            }

            // 3. Bangun payload kanonis 151-byte persis saat diterbitkan
            try {
                $canonicalPayload = BchatWsAuthVerificationService::buildCanonicalPayload(
                    userId: $challenge->user_id,
                    deviceUuid: $challenge->device_uuid,
                    socketId: $challenge->socket_id,
                    channelName: $challenge->channel_name,
                    nonceHex: $challenge->challenge_nonce,
                    expiresAtTimestamp: $challenge->expires_at->timestamp
                );
            } catch (Exception) {
                return false;
            }

            // 4. Verifikasi tanda tangan detached Ed25519 menggunakan public key perangkat terdaftar
            $signatureBinary = hex2bin($cleanSignatureHex);
            $isSignatureValid = BchatWsAuthVerificationService::verifySignature(
                payloadBinary: $canonicalPayload,
                signatureBinary: $signatureBinary,
                publicKeyHex: $device->public_key
            );

            if (!$isSignatureValid) {
                return false;
            }

            // 5. Konsumsi atomik challenge untuk mencegah replay
            $challenge->update([
                'status'      => 'CONSUMED',
                'consumed_at' => now(),
            ]);

            return true;
        });
    }
}

