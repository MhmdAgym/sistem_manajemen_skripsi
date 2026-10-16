<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class DosenPA extends Model
{
    use HasFactory;

    protected $table = 'dosen_pa';
    
    protected $primaryKey = 'id_dosen_pa'; 

    // Matikan timestamps karena di migration tidak ada $table->timestamps()
    public $timestamps = false;

    protected $fillable = [
        'id_dosen',
        'id_skripsi',
    ];

    // Relasi ke model Dosen
    public function dosen()
    {
        return $this->belongsTo(Dosen::class, 'id_dosen', 'id_dosen');
    }

    // Relasi ke model Skripsi
    public function skripsi()
    {
        return $this->belongsTo(Skripsi::class, 'id_skripsi', 'id_skripsi');
    }
}