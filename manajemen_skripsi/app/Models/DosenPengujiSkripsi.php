<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class DosenPengujiSkripsi extends Model
{
    use HasFactory;

    protected $table = 'dosen_penguji_skripsi';
    
    protected $primaryKey = 'id_penguji_skripsi'; 

    protected $fillable = [
        'id_dosen',
        'id_ujian_skripsi',
        'peran',
    ];

    // Relasi ke model Dosen
    public function dosen()
    {
        return $this->belongsTo(Dosen::class, 'id_dosen', 'id_dosen');
    }

    // Relasi ke model Ujian Skripsi
    public function ujianSkripsi()
    {
        return $this->belongsTo(UjianSkripsi::class, 'id_ujian_skripsi', 'id_ujian_skripsi');
    }
}