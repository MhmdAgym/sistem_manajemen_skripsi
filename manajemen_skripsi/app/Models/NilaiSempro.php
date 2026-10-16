<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class NilaiSempro extends Model
{
    use HasFactory;

    protected $table = 'nilai_sempro';
    
    protected $primaryKey = 'id_nilai_sempro'; 

    protected $fillable = [
        'id_sempro',
        'tanggal_ujian',
        'status_ujian',
        'id_penguji_sempro',
    ];

    // Relasi ke model Sempro
    public function sempro()
    {
        return $this->belongsTo(Sempro::class, 'id_sempro', 'id_sempro');
    }

    // Relasi ke model PengujiSempro
    public function pengujiSempro()
    {
        return $this->belongsTo(PengujiSempro::class, 'id_penguji_sempro', 'id_penguji_sempro');
    }
}