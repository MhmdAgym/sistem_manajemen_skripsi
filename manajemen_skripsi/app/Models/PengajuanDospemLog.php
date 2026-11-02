<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PengajuanDospemLog extends Model
{
    use HasFactory;

    protected $table = 'pengajuan_dospem_logs';

    /**
     * Matikan timestamps bawaan karena tabel hanya memiliki kolom created_at (append-only).
     */
    public $timestamps = false;

    protected $fillable = [
        'pengajuan_dospem_id',
        'user_id',
        'aktor_nama',
        'aktor_role',
        'aktor_nomor_induk',
        'tahap',
        'tindakan',
        'status_sebelum',
        'status_sesudah',
        'catatan',
        'snapshot_dosen_id',
        'snapshot_dosen_nama',
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
            throw new \RuntimeException('PengajuanDospemLog bersifat append-only: Pembaruan data log tidak diizinkan.');
        });

        static::deleting(function () {
            throw new \RuntimeException('PengajuanDospemLog bersifat append-only: Penghapusan data log tidak diizinkan.');
        });
    }

    /**
     * Relasi ke PengajuanDospem induk (FK: pengajuan_dospem_id -> pengajuan_dospem.id)
     */
    public function pengajuanDospem(): BelongsTo
    {
        return $this->belongsTo(PengajuanDospem::class, 'pengajuan_dospem_id', 'id');
    }

    /**
     * Relasi ke User aktor (FK: user_id -> users.id, nullable)
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id', 'id');
    }
}
