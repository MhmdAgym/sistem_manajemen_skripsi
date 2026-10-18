<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Skripsi extends Model
{
    use HasFactory;

    protected $table = 'skripsi';
    
    protected $primaryKey = 'id_skripsi'; 

    // Matikan timestamps karena di migration tidak ada $table->timestamps()
    public $timestamps = false;

    protected $fillable = [
        'id_mahasiswa',
        'judul',
        'status_judul',
    ];

    // Relasi ke model Mahasiswa
    public function mahasiswa()
    {
        return $this->belongsTo(Mahasiswa::class, 'id_mahasiswa', 'id_mahasiswa');
    }

    // Relasi ke model DosenPA
    public function dosenPA()
    {
        return $this->hasMany(DosenPA::class, 'id_skripsi', 'id_skripsi');
    }

    // Relasi ke model DosenPembimbing
    public function dosenPembimbing()
    {
        return $this->hasMany(DosenPembimbing::class, 'id_skripsi', 'id_skripsi');
    }
}