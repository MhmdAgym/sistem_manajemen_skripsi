<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Admin extends Model
{
    use HasFactory;

    // Mendefinisikan nama tabel yang spesifik
    protected $table = 'admin';
    
    // Mendefinisikan custom primary key
    protected $primaryKey = 'id_admin'; 

    protected $fillable = [
        'users_id',
        'nama_admin',
    ];

    // Relasi ke model User
    public function user()
    {
        return $this->belongsTo(User::class, 'users_id');
    }
}