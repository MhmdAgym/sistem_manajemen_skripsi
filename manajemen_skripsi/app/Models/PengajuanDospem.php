<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class PengajuanDospem extends Model
{
    use HasFactory;

    protected $table = 'pengajuan_dospem';

    protected $fillable = [
        'skripsi_id',
        'mahasiswa_id',
        'tahap',
        'status',
        'dosen_id',
        'catatan_mahasiswa',
        'catatan_admin',
    ];

    /**
     * Relasi ke data Skripsi (FK: skripsi_id -> skripsi.id_skripsi)
     */
    public function skripsi(): BelongsTo
    {
        return $this->belongsTo(Skripsi::class, 'skripsi_id', 'id_skripsi');
    }

    /**
     * Relasi ke data Mahasiswa (FK: mahasiswa_id -> mahasiswa.id_mahasiswa)
     */
    public function mahasiswa(): BelongsTo
    {
        return $this->belongsTo(Mahasiswa::class, 'mahasiswa_id', 'id_mahasiswa');
    }

    /**
     * Relasi ke data Dosen yang ditetapkan sebagai pembimbing (FK: dosen_id -> dosen.id_dosen)
     */
    public function dosen(): BelongsTo
    {
        return $this->belongsTo(Dosen::class, 'dosen_id', 'id_dosen');
    }

    /**
     * Relasi ke seluruh riwayat log audit pengajuan dospem
     */
    public function logs(): HasMany
    {
        return $this->hasMany(PengajuanDospemLog::class, 'pengajuan_dospem_id', 'id');
    }

    /**
     * Relasi ke log audit terbaru
     */
    public function latestLog(): HasOne
    {
        return $this->hasOne(PengajuanDospemLog::class, 'pengajuan_dospem_id', 'id')->latestOfMany();
    }
}
