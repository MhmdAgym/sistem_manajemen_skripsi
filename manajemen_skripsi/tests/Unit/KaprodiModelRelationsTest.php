<?php

namespace Tests\Unit;

use App\Models\Dosen;
use App\Models\PengajuanJudul;
use App\Models\PengajuanJudulLog;
use App\Models\PenugasanKaprodi;
use App\Models\Skripsi;
use App\Models\User;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Tests\TestCase;

class KaprodiModelRelationsTest extends TestCase
{
    /**
     * 1. Model PenugasanKaprodi memiliki atribut, cast, dan relasi dosen() yang valid.
     */
    public function test_penugasan_kaprodi_model_configuration_and_relations(): void
    {
        $penugasan = new PenugasanKaprodi();

        $this->assertEquals('penugasan_kaprodi', $penugasan->getTable());
        $this->assertEquals([
            'dosen_id',
            'prodi',
            'tanggal_mulai',
            'tanggal_selesai',
            'is_aktif',
        ], $penugasan->getFillable());

        $casts = $penugasan->getCasts();
        $this->assertEquals('date', $casts['tanggal_mulai']);
        $this->assertEquals('date', $casts['tanggal_selesai']);
        $this->assertEquals('boolean', $casts['is_aktif']);

        $relation = $penugasan->dosen();
        $this->assertInstanceOf(BelongsTo::class, $relation);
        $this->assertEquals('dosen_id', $relation->getForeignKeyName());
        $this->assertEquals('id_dosen', $relation->getOwnerKeyName());
    }

    /**
     * 2. Model PengajuanJudulLog memiliki atribut, cast, dan relasi yang valid.
     */
    public function test_pengajuan_judul_log_model_configuration_and_relations(): void
    {
        $log = new PengajuanJudulLog();

        $this->assertEquals('pengajuan_judul_logs', $log->getTable());
        $this->assertFalse($log->timestamps);

        $this->assertEquals([
            'pengajuan_judul_id',
            'user_id',
            'aktor_nama',
            'aktor_role',
            'aktor_nomor_induk',
            'tahap',
            'tindakan',
            'status_sebelum',
            'status_sesudah',
            'catatan',
            'snapshot_judul',
            'snapshot_file_path',
            'created_at',
        ], $log->getFillable());

        $casts = $log->getCasts();
        $this->assertEquals('datetime', $casts['created_at']);

        // Relasi ke PengajuanJudul
        $relPengajuan = $log->pengajuanJudul();
        $this->assertInstanceOf(BelongsTo::class, $relPengajuan);
        $this->assertEquals('pengajuan_judul_id', $relPengajuan->getForeignKeyName());
        $this->assertEquals('id', $relPengajuan->getOwnerKeyName());

        // Relasi ke User
        $relUser = $log->user();
        $this->assertInstanceOf(BelongsTo::class, $relUser);
        $this->assertEquals('user_id', $relUser->getForeignKeyName());
        $this->assertEquals('id', $relUser->getOwnerKeyName());
    }

    /**
     * 3. Model PengajuanJudulLog menolak operasi update dan delete (append-only enforcement).
     */
    public function test_pengajuan_judul_log_is_append_only(): void
    {
        $log = new PengajuanJudulLog();
        $log->exists = true; // Simulasi model sudah tersimpan
        $log->catatan = 'Catatan diubah'; // Membuat model dirty agar memicu event updating

        // Uji update diblokir
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('PengajuanJudulLog bersifat append-only: Pembaruan data log tidak diizinkan.');
        $log->save();
    }

    /**
     * 4. Model PengajuanJudulLog menolak operasi delete (append-only enforcement).
     */
    public function test_pengajuan_judul_log_delete_is_blocked(): void
    {
        $log = new PengajuanJudulLog();
        $log->exists = true; // Simulasi model sudah tersimpan

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('PengajuanJudulLog bersifat append-only: Penghapusan data log tidak diizinkan.');
        $log->delete();
    }

    /**
     * 5. Model PengajuanJudul menyertakan skripsi_id pada fillable dan memiliki relasi skripsi() serta logs().
     */
    public function test_pengajuan_judul_model_relations(): void
    {
        $pengajuan = new PengajuanJudul();

        $this->assertContains('skripsi_id', $pengajuan->getFillable());

        $relSkripsi = $pengajuan->skripsi();
        $this->assertInstanceOf(BelongsTo::class, $relSkripsi);
        $this->assertEquals('skripsi_id', $relSkripsi->getForeignKeyName());
        $this->assertEquals('id_skripsi', $relSkripsi->getOwnerKeyName());

        $relLogs = $pengajuan->logs();
        $this->assertInstanceOf(HasMany::class, $relLogs);
        $this->assertEquals('pengajuan_judul_id', $relLogs->getForeignKeyName());
        $this->assertEquals('id', $relLogs->getLocalKeyName());
    }

    /**
     * 6. Model Skripsi memiliki relasi pengajuanJudul() dan latestPengajuanJudul().
     */
    public function test_skripsi_model_relations(): void
    {
        $skripsi = new Skripsi();

        $relPengajuan = $skripsi->pengajuanJudul();
        $this->assertInstanceOf(HasMany::class, $relPengajuan);
        $this->assertEquals('skripsi_id', $relPengajuan->getForeignKeyName());
        $this->assertEquals('id_skripsi', $relPengajuan->getLocalKeyName());

        $relLatest = $skripsi->latestPengajuanJudul();
        $this->assertInstanceOf(HasOne::class, $relLatest);
        $this->assertEquals('skripsi_id', $relLatest->getForeignKeyName());
        $this->assertEquals('id_skripsi', $relLatest->getLocalKeyName());
    }

    /**
     * 7. Model Dosen memiliki relasi penugasanKaprodi().
     */
    public function test_dosen_model_relations(): void
    {
        $dosen = new Dosen();

        $relPenugasan = $dosen->penugasanKaprodi();
        $this->assertInstanceOf(HasMany::class, $relPenugasan);
        $this->assertEquals('dosen_id', $relPenugasan->getForeignKeyName());
        $this->assertEquals('id_dosen', $relPenugasan->getLocalKeyName());
    }
}
