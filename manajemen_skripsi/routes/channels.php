<?php

use App\Models\BchatRoom;
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

    return $user->can('view', $room);
}, ['guards' => ['sanctum']]);

