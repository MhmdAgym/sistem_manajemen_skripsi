<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Maksimum Perangkat Aktif per Pengguna (B-CHAT Multi-Device)
    |--------------------------------------------------------------------------
    | Batas jumlah perangkat aktif simultan untuk satu akun.
    | Nilai null atau <= 0 berarti tidak ada batas maksimum (unlimited).
    */
    'max_active_devices_per_user' => env(
        'BCHAT_MAX_ACTIVE_DEVICES_PER_USER',
        env('BCAT_MAX_ACTIVE_DEVICES_PER_USER', null)
    ),

    /*
    |--------------------------------------------------------------------------
    | Masa Berlaku Sesi Pairing Perangkat (Detik)
    |--------------------------------------------------------------------------
    | Batas waktu kedaluwarsa sesi pairing perangkat tepercaya.
    | Default: 300 detik (5 menit).
    */
    'pairing_ttl_seconds' => (int) env('BCHAT_PAIRING_TTL_SECONDS', 300),
];

