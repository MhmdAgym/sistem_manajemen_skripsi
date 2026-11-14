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

    // Relasi ke model DosenPA (satu skripsi, satu Dosen PA aktif)
    public function dosenPA()
    {
        return $this->hasOne(DosenPA::class, 'id_skripsi', 'id_skripsi');
    }

    // Relasi ke model DosenPembimbing
    public function dosenPembimbing()
    {
        return $this->hasMany(DosenPembimbing::class, 'id_skripsi', 'id_skripsi');
    }

    // Relasi ke seluruh riwayat pengajuan judul (satu skripsi bisa memiliki beberapa riwayat pengajuan)
    public function pengajuanJudul()
    {
        return $this->hasMany(PengajuanJudul::class, 'skripsi_id', 'id_skripsi');
    }

    // Relasi ke pengajuan judul terbaru
    public function latestPengajuanJudul()
    {
        return $this->hasOne(PengajuanJudul::class, 'skripsi_id', 'id_skripsi')->latestOfMany();
    }

    // Relasi ke seluruh riwayat pengajuan dosen pembimbing
    public function pengajuanDospem()
    {
        return $this->hasMany(PengajuanDospem::class, 'skripsi_id', 'id_skripsi');
    }

    // Relasi ke pengajuan dosen pembimbing terbaru
    public function latestPengajuanDospem()
    {
        return $this->hasOne(PengajuanDospem::class, 'skripsi_id', 'id_skripsi')->latestOfMany();
    }

    // Relasi ke seluruh berkas fisik SK Pembimbing Skripsi
    public function skDokumen()
    {
        return $this->hasMany(SkSkripsiDokumen::class, 'skripsi_id', 'id_skripsi');
    }

    // Relasi ke berkas fisik SK Pembimbing Skripsi aktif terkini
    public function skDokumenTerkini()
    {
        return $this->hasOne(SkSkripsiDokumen::class, 'skripsi_id', 'id_skripsi')->where('is_terkini', true);
    }

    // Relasi ke seluruh riwayat penugasan pembimbing (proposal dan skripsi)
    public function penugasanPembimbing()
    {
        return $this->hasMany(PenugasanPembimbing::class, 'skripsi_id', 'id_skripsi');
    }

    // Relasi ke penugasan pembimbing berstatus aktif
    public function penugasanPembimbingAktif()
    {
        return $this->hasMany(PenugasanPembimbing::class, 'skripsi_id', 'id_skripsi')->where('status_penugasan', 'AKTIF');
    }
}