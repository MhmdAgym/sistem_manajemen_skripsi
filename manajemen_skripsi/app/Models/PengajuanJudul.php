<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PengajuanJudul extends Model
{
    use HasFactory;

    protected $table = 'pengajuan_judul';

    protected $fillable = [
        'skripsi_id',
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

    public function getFileUrlAttribute(): ?string
    {
        if (empty($this->file_path) || empty($this->id)) {
            return null;
        }

        try {
            return \Illuminate\Support\Facades\URL::temporarySignedRoute(
                'pengajuan-judul.dokumen',
                now()->addMinutes(60),
                ['id' => $this->id]
            );
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning(
                'Gagal membuat URL sementara dokumen pengajuan judul',
                ['pengajuan_id' => $this->id, 'error_class' => get_class($e)]
            );
            return null;
        }
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

    // Relasi ke data Skripsi (FK: skripsi_id → skripsi.id_skripsi)
    public function skripsi()
    {
        return $this->belongsTo(Skripsi::class, 'skripsi_id', 'id_skripsi');
    }

    // Relasi ke riwayat log audit (FK: pengajuan_judul_id → pengajuan_judul_logs.pengajuan_judul_id)
    public function logs()
    {
        return $this->hasMany(PengajuanJudulLog::class, 'pengajuan_judul_id', 'id');
    }
}