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
}