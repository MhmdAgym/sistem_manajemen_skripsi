<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SkSkripsiDokumen extends Model
{
    use HasFactory;

    /**
     * Nama tabel eksplisit sesuai migration B-CHAT.
     */
    protected $table = 'sk_skripsi_dokumen';

    /**
     * Primary key model.
     */
    protected $primaryKey = 'id';

    /**
     * Atribut yang dapat diisi secara mass assignment.
     * Kolom generated virtual `terkini_slot` secara sengaja diabaikan/dilindungi.
     */
    protected $fillable = [
        'skripsi_id',
        'mahasiswa_id',
        'versi_ke',
        'is_terkini',
        'induk_dokumen_id',
        'nomor_sk',
        'tanggal_sk',
        'file_path',
        'file_hash_sha256',
        'file_size_bytes',
        'status_dokumen',
        'admin_id',
        'diperiksa_at',
        'catatan_admin',
        'dosen_1_usulan_id',
        'dosen_2_usulan_id',
    ];

    /**
     * Tipe data casting atribut model.
     */
    protected function casts(): array
    {
        return [
            'versi_ke'        => 'integer',
            'is_terkini'      => 'boolean',
            'tanggal_sk'      => 'date',
            'file_size_bytes' => 'integer',
            'diperiksa_at'    => 'datetime',
        ];
    }

    /**
     * Relasi ke Skripsi induk (FK: skripsi_id -> skripsi.id_skripsi).
     */
    public function skripsi(): BelongsTo
    {
        return $this->belongsTo(Skripsi::class, 'skripsi_id', 'id_skripsi');
    }

    /**
     * Relasi ke Mahasiswa pengunggah berkas (FK: mahasiswa_id -> mahasiswa.id_mahasiswa).
     */
    public function mahasiswa(): BelongsTo
    {
        return $this->belongsTo(Mahasiswa::class, 'mahasiswa_id', 'id_mahasiswa');
    }

    /**
     * Relasi ke User Admin pemeriksa berkas (FK: admin_id -> users.id, nullable).
     */
    public function admin(): BelongsTo
    {
        return $this->belongsTo(User::class, 'admin_id', 'id');
    }

    /**
     * Relasi self-reference ke Dokumen versi sebelumnya yang diperbaiki (FK: induk_dokumen_id -> sk_skripsi_dokumen.id).
     */
    public function indukDokumen(): BelongsTo
    {
        return $this->belongsTo(self::class, 'induk_dokumen_id', 'id');
    }

    /**
     * Relasi self-reference ke Dokumen revisi turunan.
     */
    public function dokumenRevisi(): HasMany
    {
        return $this->hasMany(self::class, 'induk_dokumen_id', 'id');
    }

    /**
     * Relasi ke usulan Dosen Pembimbing 1 saat mahasiswa mengunggah SK (FK: dosen_1_usulan_id -> dosen.id_dosen).
     */
    public function dosen1Usulan(): BelongsTo
    {
        return $this->belongsTo(Dosen::class, 'dosen_1_usulan_id', 'id_dosen');
    }

    /**
     * Relasi ke usulan Dosen Pembimbing 2 saat mahasiswa mengunggah SK (FK: dosen_2_usulan_id -> dosen.id_dosen).
     */
    public function dosen2Usulan(): BelongsTo
    {
        return $this->belongsTo(Dosen::class, 'dosen_2_usulan_id', 'id_dosen');
    }

    /**
     * Relasi ke seluruh penugasan pembimbing yang diterbitkan berdasarkan dokumen SK ini.
     */
    public function penugasanPembimbing(): HasMany
    {
        return $this->hasMany(PenugasanPembimbing::class, 'sk_dokumen_id', 'id');
    }
}

