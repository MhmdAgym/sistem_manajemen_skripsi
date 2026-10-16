<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PengujiSempro extends Model
{
    use HasFactory;

    protected $table = 'penguji_sempro';
    
    protected $primaryKey = 'id_penguji_sempro'; 

    protected $fillable = [
        'id_dosen',
        'id_sempro',
        'peran',
    ];

    // Relasi ke model Dosen
    public function dosen()
    {
        return $this->belongsTo(Dosen::class, 'id_dosen', 'id_dosen');
    }

    // Relasi ke model Sempro
    public function sempro()
    {
        return $this->belongsTo(Sempro::class, 'id_sempro', 'id_sempro');
    }
}