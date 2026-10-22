<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Dosen extends Model
{
    use HasFactory;

    // Mendefinisikan nama tabel secara eksplisit
    protected $table = 'dosen';
    
    // Mendefinisikan custom primary key
    protected $primaryKey = 'id_dosen'; 

    protected $fillable = [
    'NIDN',
    'user_id',
    'nama_dosen',
    'no_handphone',
    'jenis_kelamin',
];

    // Relasi ke model User
    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    // Relasi ke model DosenPembimbing
    public function dosenPembimbing()
    {
        return $this->hasMany(DosenPembimbing::class, 'id_dosen', 'id_dosen');
    }

    // Relasi ke model DosenPA
    public function dosenPA()
    {
        return $this->hasMany(DosenPA::class, 'id_dosen', 'id_dosen');
    }

    // Relasi ke model DosenPengujiSkripsi
    public function dosenPengujiSkripsi()
    {
        return $this->hasMany(DosenPengujiSkripsi::class, 'id_dosen', 'id_dosen');
    }

    // Relasi ke model PenugasanKaprodi (FK: dosen_id → dosen.id_dosen)
    public function penugasanKaprodi()
    {
        return $this->hasMany(PenugasanKaprodi::class, 'dosen_id', 'id_dosen');
    }
}