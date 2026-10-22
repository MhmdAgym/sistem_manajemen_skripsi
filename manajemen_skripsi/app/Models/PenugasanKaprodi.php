<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PenugasanKaprodi extends Model
{
    use HasFactory;

    protected $table = 'penugasan_kaprodi';

    protected $fillable = [
        'dosen_id',
        'prodi',
        'tanggal_mulai',
        'tanggal_selesai',
        'is_aktif',
    ];

    protected function casts(): array
    {
        return [
            'tanggal_mulai'   => 'date',
            'tanggal_selesai' => 'date',
            'is_aktif'        => 'boolean',
        ];
    }

    /**
     * Relasi ke model Dosen (FK: dosen_id -> dosen.id_dosen)
     */
    public function dosen(): BelongsTo
    {
        return $this->belongsTo(Dosen::class, 'dosen_id', 'id_dosen');
    }
}
