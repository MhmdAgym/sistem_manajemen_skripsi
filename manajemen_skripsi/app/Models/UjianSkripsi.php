<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class UjianSkripsi extends Model
{
    use HasFactory;

    protected $table = 'ujian_skripsi';
    
    protected $primaryKey = 'id_ujian_skripsi'; 

    protected $fillable = [
        'id_sempro',
        'tanggal_ujian',
        'status_ujian',
    ];

    // Relasi ke model Sempro
    public function sempro()
    {
        return $this->belongsTo(Sempro::class, 'id_sempro', 'id_sempro');
    }

    // Relasi ke model DosenPengujiSkripsi
    public function dosenPengujiSkripsi()
    {
        return $this->hasMany(DosenPengujiSkripsi::class, 'id_ujian_skripsi', 'id_ujian_skripsi');
    }

    // Relasi ke model NilaiSkripsi
    public function nilaiSkripsi()
    {
        return $this->hasMany(NilaiSkripsi::class, 'id_ujian_skripsi', 'id_ujian_skripsi');
    }
}