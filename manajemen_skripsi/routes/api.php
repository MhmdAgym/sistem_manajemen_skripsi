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
use App\Http\Controllers\Mahasiswa\PengajuanDospemMahasiswaController;
use App\Http\Controllers\Admin\PengajuanDospemAdminController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\Mahasiswa\SkSkripsiDokumenMahasiswaController;
use App\Http\Controllers\Admin\SkSkripsiDokumenAdminController;
use App\Http\Controllers\Admin\PenugasanPembimbingAdminController;
use App\Http\Controllers\Api\Bchat\BchatDeviceController;
use App\Http\Controllers\Api\Bchat\BchatKeyPackageController;
use App\Http\Controllers\Api\Bchat\BchatRoomController;
use App\Http\Controllers\Api\Bchat\BchatWelcomeAckController;
use App\Http\Controllers\Api\Bchat\BchatWelcomeController;
use App\Http\Controllers\Api\Bchat\BchatMessageController;
use App\Http\Controllers\Api\Bchat\BchatAttachmentController;

// ---------------------------------------------------------------------------
// SANCTUM USER DETECTION & NOTIFIKASI AKADEMIK TERPUSAT
// ---------------------------------------------------------------------------
Route::middleware(['auth:sanctum'])->group(function () {
    Route::get('/user', function (Request $request) {
        return $request->user();
    });

    Route::get('/notifications', [NotificationController::class, 'index']);
    Route::get('/notifications/unread-count', [NotificationController::class, 'unreadCount']);
    Route::patch('/notifications/{id}/read', [NotificationController::class, 'markAsRead']);
    Route::patch('/notifications/mark-all-read', [NotificationController::class, 'markAllRead']);
});

// ---------------------------------------------------------------------------
// B-CHAT E2EE: DEVICE CONTROL PLANE (Phase 5B.3A)
// ---------------------------------------------------------------------------
Route::middleware(['auth:sanctum'])->prefix('bchat/devices')->group(function () {
    Route::post('/step-up', [BchatDeviceController::class, 'stepUp'])->middleware('throttle:10,1');
    Route::post('/registration-nonce', [BchatDeviceController::class, 'issueRegistrationNonce'])->middleware('throttle:10,1');
    Route::post('/register', [BchatDeviceController::class, 'register'])->middleware('throttle:10,1');
    Route::get('/status', [BchatDeviceController::class, 'status'])->middleware('throttle:30,1');
});

// ---------------------------------------------------------------------------
// B-CHAT E2EE: KEYPACKAGE DEPOSIT PLANE (Tahap 2C.3)
// ---------------------------------------------------------------------------
Route::middleware(['auth:sanctum'])->prefix('bchat/key-packages')->group(function () {
    Route::post('/', [BchatKeyPackageController::class, 'upload'])->middleware('throttle:30,1');
});

// ---------------------------------------------------------------------------
// B-CHAT E2EE: ROOM DISCOVERY & WELCOME ACK HANDSHAKE (Tahap 2C.1 / 2C.3)
// ---------------------------------------------------------------------------
Route::middleware(['auth:sanctum'])->prefix('bchat/rooms')->group(function () {
    Route::get('/', [BchatRoomController::class, 'index'])
        ->middleware('throttle:60,1');
    Route::get('/{roomUuid}', [BchatRoomController::class, 'show'])
        ->middleware('throttle:60,1');
    Route::get('/{roomUuid}/partner-key-package', [BchatKeyPackageController::class, 'fetchPartnerKeyPackage'])
        ->middleware('throttle:60,1');
    Route::post('/{roomUuid}/welcome', [BchatWelcomeController::class, 'publish'])
        ->middleware('throttle:10,1');
    Route::post('/{roomUuid}/welcome-challenge', [BchatWelcomeController::class, 'challenge'])
        ->middleware('throttle:10,1');
    Route::post('/{roomUuid}/welcome-ack', [BchatWelcomeAckController::class, 'welcomeAck'])
        ->middleware('throttle:10,1');
    Route::post('/{roomUuid}/messages', [BchatMessageController::class, 'store'])
        ->middleware('throttle:60,1');
    Route::get('/{roomUuid}/messages', [BchatMessageController::class, 'index'])
        ->middleware('throttle:60,1');

    // B-CHAT E2EE Attachment Transport Plane (Tahap 2)
    Route::post('/{roomUuid}/attachments/upload-session', [BchatAttachmentController::class, 'createUploadSession'])
        ->middleware('throttle:30,1');
    Route::put('/{roomUuid}/attachments/upload-session/{sessionUuid}/chunks/{chunkIndex}', [BchatAttachmentController::class, 'uploadChunk'])
        ->middleware('throttle:120,1');
    Route::post('/{roomUuid}/attachments/upload-session/{sessionUuid}/complete', [BchatAttachmentController::class, 'completeUploadSession'])
        ->middleware('throttle:30,1');
    Route::get('/{roomUuid}/attachments/{attachmentUuid}/chunks/{chunkIndex}', [BchatAttachmentController::class, 'downloadChunk'])
        ->middleware('throttle:120,1');
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

// [MAHASISWA] Pengajuan Dosen Pembimbing Proposal
Route::middleware(['auth:sanctum', 'role:mahasiswa'])->group(function () {
    Route::get('/mahasiswa/pengajuan-dospem/status-kelayakan', [PengajuanDospemMahasiswaController::class, 'statusKelayakan']);
    Route::get('/mahasiswa/pengajuan-dospem', [PengajuanDospemMahasiswaController::class, 'index']);
    Route::post('/mahasiswa/pengajuan-dospem', [PengajuanDospemMahasiswaController::class, 'store']);
});

// [MAHASISWA] Dokumen SK Pembimbing Skripsi
Route::middleware(['auth:sanctum', 'role:mahasiswa'])->group(function () {
    Route::get('/mahasiswa/sk-dokumen', [SkSkripsiDokumenMahasiswaController::class, 'index']);
    Route::post('/mahasiswa/sk-dokumen', [SkSkripsiDokumenMahasiswaController::class, 'store']);
    Route::get('/mahasiswa/sk-dokumen/{id}', [SkSkripsiDokumenMahasiswaController::class, 'show']);
    Route::post('/mahasiswa/sk-dokumen/{id}/revisi', [SkSkripsiDokumenMahasiswaController::class, 'update']);
    Route::get('/mahasiswa/sk-dokumen/{id}/download', [SkSkripsiDokumenMahasiswaController::class, 'download']);
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

    // [ADMIN] Pengajuan & Penetapan Dosen Pembimbing Proposal
    Route::get('/admin/pengajuan-dospem/dosen-options', [PengajuanDospemAdminController::class, 'dosenOptions']);
    Route::get('/admin/pengajuan-dospem', [PengajuanDospemAdminController::class, 'index']);
    Route::get('/admin/pengajuan-dospem/{id}', [PengajuanDospemAdminController::class, 'show']);
    Route::post('/admin/pengajuan-dospem/{id}/tetapkan', [PengajuanDospemAdminController::class, 'tetapkan']);
    Route::patch('/admin/pengajuan-dospem/{id}/status', [PengajuanDospemAdminController::class, 'status']);

    // [ADMIN] Verifikasi Dokumen SK Pembimbing Skripsi
    Route::get('/admin/sk-dokumen', [SkSkripsiDokumenAdminController::class, 'index']);
    Route::get('/admin/sk-dokumen/{id}', [SkSkripsiDokumenAdminController::class, 'show']);
    Route::patch('/admin/sk-dokumen/{id}/verifikasi', [SkSkripsiDokumenAdminController::class, 'verify']);
    Route::get('/admin/sk-dokumen/{id}/download', [SkSkripsiDokumenAdminController::class, 'download']);

    // [ADMIN] Penugasan Pembimbing Skripsi
    Route::get('/admin/penugasan-pembimbing', [PenugasanPembimbingAdminController::class, 'index']);
    Route::post('/admin/penugasan-pembimbing', [PenugasanPembimbingAdminController::class, 'store']);
    Route::get('/admin/penugasan-pembimbing/{id}', [PenugasanPembimbingAdminController::class, 'show']);
    Route::patch('/admin/penugasan-pembimbing/{id}/status', [PenugasanPembimbingAdminController::class, 'update']);

    // Admin & Penetapan Dosen PA
    Route::apiResource('admin', AdminController::class);
    Route::apiResource('dosen-pa', DosenPAController::class);
});

// [DOSEN PEMBIMBING, BIMBINGAN, UJIAN & PENILAIAN] — Rute Terlindungi dengan Autentikasi Sanctum & Otorisasi Peran
Route::middleware(['auth:sanctum'])->group(function () {
    Route::apiResource('dosen-pembimbing', DosenPembimbingController::class)->only(['index', 'show']);
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