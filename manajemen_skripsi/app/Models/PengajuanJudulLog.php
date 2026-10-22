<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PengajuanJudulLog extends Model
{
    use HasFactory;

    protected $table = 'pengajuan_judul_logs';

    /**
     * Matikan timestamps bawaan karena tabel hanya memiliki kolom created_at (append-only).
     */
    public $timestamps = false;

    protected $fillable = [
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
    ];

    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
        ];
    }

    /**
     * Mekanisme append-only pada level model Eloquent:
     * Menolak pembaruan (update) dan penghapusan (delete) untuk menjaga integritas riwayat audit.
     */
    protected static function booted(): void
    {
        static::updating(function () {
            throw new \RuntimeException('PengajuanJudulLog bersifat append-only: Pembaruan data log tidak diizinkan.');
        });

        static::deleting(function () {
            throw new \RuntimeException('PengajuanJudulLog bersifat append-only: Penghapusan data log tidak diizinkan.');
        });
    }

    /**
     * Relasi ke PengajuanJudul induk (FK: pengajuan_judul_id -> pengajuan_judul.id)
     */
    public function pengajuanJudul(): BelongsTo
    {
        return $this->belongsTo(PengajuanJudul::class, 'pengajuan_judul_id', 'id');
    }

    /**
     * Relasi ke User aktor (FK: user_id -> users.id, nullable)
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id', 'id');
    }
}
