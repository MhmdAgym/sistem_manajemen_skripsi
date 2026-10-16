<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class DosenPembimbing extends Model
{
    use HasFactory;

    protected $table = 'dosen_pembimbing';
    
    protected $primaryKey = 'id_pembimbing'; 

    // Matikan timestamps karena di migration tidak ada $table->timestamps()
    public $timestamps = false;

    protected $fillable = [
        'id_skripsi',
        'id_dosen',
    ];

    // Relasi ke model Skripsi
    public function skripsi()
    {
        return $this->belongsTo(Skripsi::class, 'id_skripsi', 'id_skripsi');
    }

    // Relasi ke model Dosen
    public function dosen()
    {
        return $this->belongsTo(Dosen::class, 'id_dosen', 'id_dosen');
    }
}