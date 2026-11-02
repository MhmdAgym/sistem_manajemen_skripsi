<?php

use App\Models\User;
use Illuminate\Support\Facades\Broadcast;

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
