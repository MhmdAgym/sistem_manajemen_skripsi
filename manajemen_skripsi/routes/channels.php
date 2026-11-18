<?php

use App\Models\BchatRoom;
use App\Models\BchatUserDevice;
use App\Models\User;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Str;

/*
|--------------------------------------------------------------------------
| Broadcast Channels
|--------------------------------------------------------------------------
|
| Di sini Anda mendaftarkan channel otorisasi event broadcasting.
| Channel notifikasi akademik bersifat privat per pengguna.
| Otorisasi mendukung guard sanctum (SPA/API) dan web.
|
*/

Broadcast::channel('users.{userId}.notifications', function (User $user, int|string $userId) {
    return (int) $user->id === (int) $userId;
}, ['guards' => ['sanctum', 'web']]);

/*
|--------------------------------------------------------------------------
| B-CHAT E2EE: Private Room Channel
|--------------------------------------------------------------------------
|
| Saluran privat siaran WebSocket per ruang obrolan bimbingan E2EE.
| Otorisasi kanonis didelegasikan ke BchatRoomPolicy::view().
|
*/
Broadcast::channel('bchat.rooms.{roomUuid}', function (User $user, string $roomUuid) {
    $cleanRoomUuid = strtolower(trim($roomUuid));
    if (!Str::isUuid($cleanRoomUuid)) {
        return false;
    }

    $room = BchatRoom::with(['members', 'penugasanPembimbing'])
        ->where('room_uuid', $cleanRoomUuid)
        ->first();

    if (!$room) {
        return false;
    }

    // Validasi otorisasi perangkat kriptografis (B-CHAT Tahap 2A.2 Hardening)
    // Otorisasi WebSocket Reverb bchat.rooms.{roomUuid} wajib terikat pada tantangan bertanda tangan Ed25519:
    // Header X-Bchat-Device-Uuid, X-Bchat-Challenge-Uuid, dan X-Bchat-Signature wajib valid.
    $requestDeviceUuid = request()->header('X-Bchat-Device-Uuid') ?? request()->input('device_uuid');
    $challengeUuid = request()->header('X-Bchat-Challenge-Uuid') ?? request()->input('challenge_uuid');
    $signatureHex = request()->header('X-Bchat-Signature') ?? request()->input('signature');
    $socketId = request()->input('socket_id');
    $channelName = request()->input('channel_name') ?? "private-bchat.rooms.{$cleanRoomUuid}";

    if (!$requestDeviceUuid || trim($requestDeviceUuid) === '') {
        return false;
    }

    $cleanUuid = strtolower(trim($requestDeviceUuid));
    if (!Str::isUuid($cleanUuid)) {
        return false;
    }

    // Klaim UUID tanpa bukti tanda tangan tantangan kriptografis DITOLAK secara tegas (fail-closed)
    if (!$challengeUuid || !$signatureHex || !$socketId) {
        return false;
    }

    /** @var \App\Services\BchatWsAuthService $wsAuthService */
    $wsAuthService = app(\App\Services\BchatWsAuthService::class);
    $isVerified = $wsAuthService->verifyAndConsumeChallenge(
        user: $user,
        deviceUuid: $cleanUuid,
        challengeUuid: (string) $challengeUuid,
        signatureHex: (string) $signatureHex,
        socketId: (string) $socketId,
        channelName: (string) $channelName
    );

    if (!$isVerified) {
        return false;
    }

    return (bool) $user->can('view', $room);
}, ['guards' => ['sanctum']]);

