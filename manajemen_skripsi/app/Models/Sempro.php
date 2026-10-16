<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Sempro extends Model
{
    use HasFactory;

    protected $table = 'sempro';
    
    protected $primaryKey = 'id_sempro'; 

    protected $fillable = [
        'id_skripsi',
        'tanggal_sempro',
        'status_sempro',
    ];

    // Relasi ke model Skripsi
    public function skripsi()
    {
        return $this->belongsTo(Skripsi::class, 'id_skripsi', 'id_skripsi');
    }
}