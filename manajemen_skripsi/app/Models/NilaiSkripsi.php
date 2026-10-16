<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class NilaiSkripsi extends Model
{
    use HasFactory;

    protected $table = 'nilai_skripsi';
    
    protected $primaryKey = 'id_nilai'; 

    protected $fillable = [
        'id_ujian_skripsi',
        'id_dosen',
        'nilai',
        'catatan',
    ];

    // Relasi ke model UjianSkripsi
    public function ujianSkripsi()
    {
        return $this->belongsTo(UjianSkripsi::class, 'id_ujian_skripsi', 'id_ujian_skripsi');
    }

    // Relasi ke model Dosen
    public function dosen()
    {
        return $this->belongsTo(Dosen::class, 'id_dosen', 'id_dosen');
    }
}