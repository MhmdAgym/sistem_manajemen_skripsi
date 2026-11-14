<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PenugasanPembimbingLog extends Model
{
    use HasFactory;

    /**
     * Nama tabel eksplisit sesuai migration B-CHAT.
     */
    protected $table = 'penugasan_pembimbing_logs';

    /**
     * Primary key model.
     */
    protected $primaryKey = 'id';

    /**
     * Nonaktifkan timestamps standar Laravel karena tabel ini hanya memiliki created_at (append-only).
     */
    public $timestamps = false;

    /**
     * Atribut yang dapat diisi secara mass assignment.
     */
    protected $fillable = [
        'penugasan_pembimbing_id',
        'user_id',
        'aktor_nama',
        'aktor_role',
        'tindakan',
        'status_sebelum',
        'status_sesudah',
        'snapshot_dosen_lama_id',
        'snapshot_dosen_baru_id',
        'catatan',
        'created_at',
    ];

    /**
     * Tipe data casting atribut model.
     */
    protected function casts(): array
    {
        return [
            'snapshot_dosen_lama_id' => 'integer',
            'snapshot_dosen_baru_id' => 'integer',
            'created_at'             => 'datetime',
        ];
    }

    /**
     * Penegakan aturan desain append-only pada level model Eloquent:
     * Mencegah mutasi update dan delete terhadap riwayat audit log.
     */
    protected static function booted(): void
    {
        static::updating(function () {
            throw new \RuntimeException('PenugasanPembimbingLog bersifat append-only: Pembaruan data log tidak diizinkan.');
        });

        static::deleting(function () {
            throw new \RuntimeException('PenugasanPembimbingLog bersifat append-only: Penghapusan data log tidak diizinkan.');
        });
    }

    /**
     * Relasi ke data PenugasanPembimbing induk (FK: penugasan_pembimbing_id -> penugasan_pembimbing.id).
     */
    public function penugasanPembimbing(): BelongsTo
    {
        return $this->belongsTo(PenugasanPembimbing::class, 'penugasan_pembimbing_id', 'id');
    }

    /**
     * Relasi ke akun User aktor yang mencatat mutasi (FK: user_id -> users.id, nullable).
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id', 'id');
    }

    /**
     * Relasi opsional ke profil Dosen lama sebelum mutasi pergantian (FK: snapshot_dosen_lama_id -> dosen.id_dosen, nullable).
     */
    public function snapshotDosenLama(): BelongsTo
    {
        return $this->belongsTo(Dosen::class, 'snapshot_dosen_lama_id', 'id_dosen');
    }

    /**
     * Relasi opsional ke profil Dosen baru setelah mutasi pergantian/penetapan (FK: snapshot_dosen_baru_id -> dosen.id_dosen, nullable).
     */
    public function snapshotDosenBaru(): BelongsTo
    {
        return $this->belongsTo(Dosen::class, 'snapshot_dosen_baru_id', 'id_dosen');
    }
}

