<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Bimbingan extends Model
{
    use HasFactory;

    protected $table = 'bimbingan';
    
    protected $primaryKey = 'id_bimbingan'; 

    protected $fillable = [
        'id_skripsi',
        'tanggal',
        'catatan',
    ];

    // Relasi ke model Skripsi
    public function skripsi()
    {
        return $this->belongsTo(Skripsi::class, 'id_skripsi', 'id_skripsi');
    }
}