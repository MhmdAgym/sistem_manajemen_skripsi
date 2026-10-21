<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PengajuanJudul extends Model
{
    use HasFactory;

    protected $table = 'pengajuan_judul';

    protected $fillable = [
        'mahasiswa_id',
        'dosen_pa_id',
        'judul',
        'deskripsi',
        'nama_file',
        'ukuran_file',
        'file_path',
        'status',
        'catatan_dosen',
    ];

    // Menambahkan atribut URL file otomatis saat dikirim ke Next.js
    protected $appends = ['file_url'];

    public function getFileUrlAttribute(): string
    {
        return asset('storage/' . $this->file_path);
    }

    // Relasi ke profil Mahasiswa (FK: mahasiswa_id → mahasiswa.id_mahasiswa)
    public function mahasiswa()
    {
        return $this->belongsTo(Mahasiswa::class, 'mahasiswa_id', 'id_mahasiswa');
    }

    // Relasi ke akun User dosen PA (FK: dosen_pa_id → users.id)
    public function dosenPa()
{
    return $this->belongsTo(User::class, 'dosen_pa_id', 'id');
}
}