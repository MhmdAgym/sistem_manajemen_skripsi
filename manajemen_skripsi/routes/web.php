<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return ['Laravel' => app()->version()];
});

// Endpoint unduh dokumen pengajuan dengan signed URL dan autentikasi session web
Route::middleware(['web', 'signed'])
    ->get('/dokumen/pengajuan-judul/{id}', [\App\Http\Controllers\PengajuanJudulController::class, 'unduhDokumen'])
    ->name('pengajuan-judul.dokumen');

require __DIR__.'/auth.php';
