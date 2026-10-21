<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

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
use App\Http\Controllers\PengajuanJudulController;

// ---------------------------------------------------------------------------
// SANCTUM USER DETECTION
// ---------------------------------------------------------------------------
Route::middleware(['auth:sanctum'])->get('/user', function (Request $request) {
    return $request->user();
});

// ---------------------------------------------------------------------------
// PENTING: Route spesifik HARUS didaftarkan sebelum apiResource,
// karena apiResource membuat wildcard `/{mahasiswa}` yang menangkap semua sub-path
// jika didaftarkan lebih dulu (Laravel matching = urutan registrasi, bukan spesifisitas).
// ---------------------------------------------------------------------------

// [MAHASISWA] Profil + info Dosen PA dari relasi DB
Route::middleware(['auth:sanctum', 'role:mahasiswa'])
    ->get('/mahasiswa/profile', [MahasiswaController::class, 'myProfile']);

// [MAHASISWA] Pengajuan judul — lihat riwayat & kirim baru
Route::middleware(['auth:sanctum', 'role:mahasiswa'])->group(function () {
    Route::get('/mahasiswa/pengajuan-judul', [PengajuanJudulController::class, 'index']);
    Route::post('/mahasiswa/pengajuan-judul', [PengajuanJudulController::class, 'store']);
});

// [DOSEN PA] Lihat daftar & verifikasi pengajuan
Route::middleware(['auth:sanctum', 'role:dosen'])->group(function () {
    Route::get('/dosen/pengajuan-judul', [PengajuanJudulController::class, 'daftarVerifikasiDosen']);
    Route::patch('/dosen/pengajuan-judul/{id}/verifikasi', [PengajuanJudulController::class, 'verifikasiDosen']);
});

// [ADMIN] Akses semua pengajuan
Route::middleware(['auth:sanctum', 'admin'])
    ->get('/admin/pengajuan-judul', [PengajuanJudulController::class, 'semuaPengajuan']);

// [ADMIN] Manajemen Akun
Route::middleware(['auth:sanctum', 'admin'])->group(function () {
    Route::apiResource('users', UserController::class)->only(['index', 'show', 'update']);
});

// ---------------------------------------------------------------------------
// RUTE CRUD API SISTEM — apiResource wildcard didaftarkan SETELAH route spesifik
// ---------------------------------------------------------------------------
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