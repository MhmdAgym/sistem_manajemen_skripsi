<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Mahasiswa extends Model
{
    use HasFactory;

    // Mendefinisikan nama tabel secara eksplisit
    protected $table = 'mahasiswa';
    
    // Mendefinisikan custom primary key
    protected $primaryKey = 'id_mahasiswa'; 

    protected $fillable = [
    'NIM',
    'user_id',
    'nama_mahasiswa',
    'prodi',
    'no_handphone',
    'jenis_kelamin',
    'fakultas',
];

    // Relasi ke model User
    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    // Relasi ke model Skripsi
    public function skripsi()
    {
        return $this->hasMany(Skripsi::class, 'id_mahasiswa', 'id_mahasiswa');
    }

    // Relasi ke skripsi terbaru
    public function latestSkripsi()
    {
        return $this->hasOne(Skripsi::class, 'id_mahasiswa', 'id_mahasiswa')->latestOfMany('id_skripsi');
    }
}