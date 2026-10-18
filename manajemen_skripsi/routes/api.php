<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

// Import Controller CRUD yang sudah dibuat
use App\Http\Controllers\AdminController;
use App\Http\Controllers\MahasiswaController;
use App\Http\Controllers\DosenController;
use App\Http\Controllers\SkripsiController;
use App\Http\Controllers\DosenPAController;
use App\Http\Controllers\DosenPembimbingController;
use App\Http\Controllers\BimbinganController;
use App\Http\Controllers\SemproController;
use App\Http\Controllers\PengujiSemproController;
use App\Http\Controllers\NilaiSemproController;
use App\Http\Controllers\UjianSkripsiController;
use App\Http\Controllers\DosenPengujiSkripsiController;
use App\Http\Controllers\NilaiSkripsiController;
use App\Http\Controllers\UserController;

// Route bawaan untuk mendeteksi user yang sedang login (membutuhkan Sanctum token)
Route::middleware(['auth:sanctum'])->get('/user', function (Request $request) {
    return $request->user();
});

// --- RUTE KHUSUS MANAJEMEN AKUN (ADMIN ONLY) ---
Route::middleware(['auth:sanctum', 'admin'])->group(function () {
    Route::apiResource('users', UserController::class)->only(['index', 'show', 'update']);
});

// --- RUTE CRUD API SISTEM MANAJEMEN SKRIPSI ---
Route::apiResource('admin', AdminController::class);
Route::apiResource('mahasiswa', MahasiswaController::class);
Route::apiResource('dosen', DosenController::class);
Route::apiResource('skripsi', SkripsiController::class);
Route::apiResource('dosen-pa', DosenPAController::class);
Route::apiResource('dosen-pembimbing', DosenPembimbingController::class);
Route::apiResource('bimbingan', BimbinganController::class);
Route::apiResource('sempro', SemproController::class);
Route::apiResource('penguji-sempro', PengujiSemproController::class);
Route::apiResource('nilai-sempro', NilaiSemproController::class);
Route::apiResource('ujian-skripsi', UjianSkripsiController::class);
Route::apiResource('dosen-penguji-skripsi', DosenPengujiSkripsiController::class);
Route::apiResource('nilai-skripsi', NilaiSkripsiController::class);