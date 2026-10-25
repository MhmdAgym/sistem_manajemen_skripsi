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
use App\Http\Controllers\KaprodiPengajuanJudulController;
use App\Http\Controllers\Admin\PenugasanKaprodiController;

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

// [MAHASISWA] Pengajuan judul — lihat riwayat, kirim baru & revisi
Route::middleware(['auth:sanctum', 'role:mahasiswa'])->group(function () {
    Route::get('/mahasiswa/pengajuan-judul', [PengajuanJudulController::class, 'index']);
    Route::post('/mahasiswa/pengajuan-judul', [PengajuanJudulController::class, 'store']);
    Route::post('/mahasiswa/pengajuan-judul/{id}/revisi', [PengajuanJudulController::class, 'revisi']);
});

// [DOSEN PA] Lihat daftar & verifikasi pengajuan
Route::middleware(['auth:sanctum', 'role:dosen'])->group(function () {
    Route::get('/dosen/pengajuan-judul', [PengajuanJudulController::class, 'daftarVerifikasiDosen']);
    Route::patch('/dosen/pengajuan-judul/{id}/verifikasi', [PengajuanJudulController::class, 'verifikasiDosen']);
});

// [KAPRODI] Antrean & persetujuan judul program studi
Route::middleware(['auth:sanctum', 'role:dosen'])->group(function () {
    Route::get('/kaprodi/pengajuan-judul', [KaprodiPengajuanJudulController::class, 'index']);
    Route::get('/kaprodi/pengajuan-judul/{id}', [KaprodiPengajuanJudulController::class, 'show']);
    Route::patch('/kaprodi/pengajuan-judul/{id}/persetujuan', [KaprodiPengajuanJudulController::class, 'persetujuan']);
});

// [ADMIN] Akses semua pengajuan
Route::middleware(['auth:sanctum', 'admin'])
    ->get('/admin/pengajuan-judul', [PengajuanJudulController::class, 'semuaPengajuan']);

// [ADMIN] Manajemen Akun
Route::middleware(['auth:sanctum', 'admin'])->group(function () {
    Route::apiResource('users', UserController::class)->only(['index', 'show', 'update']);
});

// [ADMIN] Manajemen Penugasan Kaprodi, Admin, & Dosen PA
Route::middleware(['auth:sanctum', 'admin'])->group(function () {
    Route::get('/admin/penugasan-kaprodi/dosen-options', [PenugasanKaprodiController::class, 'dosenOptions']);
    Route::get('/admin/penugasan-kaprodi', [PenugasanKaprodiController::class, 'index']);
    Route::post('/admin/penugasan-kaprodi', [PenugasanKaprodiController::class, 'store']);
    Route::get('/admin/penugasan-kaprodi/{id}', [PenugasanKaprodiController::class, 'show']);
    Route::patch('/admin/penugasan-kaprodi/{id}', [PenugasanKaprodiController::class, 'update']);
    Route::patch('/admin/penugasan-kaprodi/{id}/nonaktifkan', [PenugasanKaprodiController::class, 'nonaktifkan']);

    // Admin & Penetapan Dosen PA
    Route::apiResource('admin', AdminController::class);
    Route::apiResource('dosen-pa', DosenPAController::class);
});

// [DOSEN PEMBIMBING, BIMBINGAN, UJIAN & PENILAIAN] — Rute Terlindungi dengan Autentikasi Sanctum & Otorisasi Peran
Route::middleware(['auth:sanctum'])->group(function () {
    Route::apiResource('dosen-pembimbing', DosenPembimbingController::class);
    Route::apiResource('bimbingan', BimbinganController::class);
    Route::apiResource('sempro', SemproController::class);
    Route::apiResource('penguji-sempro', PengujiSemproController::class);
    Route::apiResource('nilai-sempro', NilaiSemproController::class);
    Route::apiResource('ujian-skripsi', UjianSkripsiController::class);
    Route::apiResource('dosen-penguji-skripsi', DosenPengujiSkripsiController::class);
    Route::apiResource('nilai-skripsi', NilaiSkripsiController::class);

    // Master Data Mahasiswa, Dosen, dan Skripsi (Otorisasi granular diatur pada level Controller)
    Route::apiResource('mahasiswa', MahasiswaController::class);
    Route::apiResource('dosen', DosenController::class);
    Route::apiResource('skripsi', SkripsiController::class);
});