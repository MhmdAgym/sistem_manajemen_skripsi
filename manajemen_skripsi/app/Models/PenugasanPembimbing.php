<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class PenugasanPembimbing extends Model
{
    use HasFactory;

    /**
     * Nama tabel eksplisit sesuai migration B-CHAT.
     */
    protected $table = 'penugasan_pembimbing';

    /**
     * Primary key model.
     */
    protected $primaryKey = 'id';

    /**
     * Atribut yang dapat diisi secara mass assignment.
     * Kolom generated virtual `active_slot` secara sengaja diabaikan/dilindungi.
     */
    protected $fillable = [
        'skripsi_id',
        'tahap',
        'pembimbing_ke',
        'dosen_id',
        'sk_dokumen_id',
        'pengajuan_dospem_id',
        'status_penugasan',
        'tanggal_mulai',
        'tanggal_selesai',
        'digantikan_oleh_id',
    ];

    /**
     * Tipe data casting atribut model.
     */
    protected function casts(): array
    {
        return [
            'pembimbing_ke'   => 'integer',
            'tanggal_mulai'   => 'date',
            'tanggal_selesai' => 'date',
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
     * Relasi ke Dosen yang ditugaskan (FK: dosen_id -> dosen.id_dosen).
     * PENTING: Menggunakan primary key entitas dosen (id_dosen), bukan users.id.
     */
    public function dosen(): BelongsTo
    {
        return $this->belongsTo(Dosen::class, 'dosen_id', 'id_dosen');
    }

    /**
     * Relasi ke Dokumen SK Pembimbing Skripsi (FK: sk_dokumen_id -> sk_skripsi_dokumen.id).
     * Bernilai NULL untuk tahap proposal, dan WAJIB terisi untuk tahap skripsi.
     */
    public function skDokumen(): BelongsTo
    {
        return $this->belongsTo(SkSkripsiDokumen::class, 'sk_dokumen_id', 'id');
    }

    /**
     * Relasi ke Pengajuan Dospem Proposal (FK: pengajuan_dospem_id -> pengajuan_dospem.id).
     * Khusus untuk penugasan pembimbing tahap proposal.
     */
    public function pengajuanDospem(): BelongsTo
    {
        return $this->belongsTo(PengajuanDospem::class, 'pengajuan_dospem_id', 'id');
    }

    /**
     * Relasi self-reference ke penugasan baru yang menggantikan posisi ini (FK: digantikan_oleh_id -> penugasan_pembimbing.id).
     */
    public function digantikanOleh(): BelongsTo
    {
        return $this->belongsTo(self::class, 'digantikan_oleh_id', 'id');
    }

    /**
     * Relasi self-reference ke penugasan sebelumnya yang digantikan oleh penugasan ini.
     */
    public function menggantikan(): HasOne
    {
        return $this->hasOne(self::class, 'digantikan_oleh_id', 'id');
    }

    /**
     * Relasi ke seluruh riwayat log audit mutasi penugasan pembimbing.
     */
    public function logs(): HasMany
    {
        return $this->hasMany(PenugasanPembimbingLog::class, 'penugasan_pembimbing_id', 'id');
    }

    /**
     * Relasi ke log audit mutasi penugasan pembimbing terbaru.
     */
    public function latestLog(): HasOne
    {
        return $this->hasOne(PenugasanPembimbingLog::class, 'penugasan_pembimbing_id', 'id')->latestOfMany();
    }

    /**
     * Relasi ke ruang obrolan BchatRoom E2EE yang terhubung secara kanonis dengan penugasan ini.
     * Kardinalitas 1 : 0..1 (penugasan_pembimbing_id unik di bchat_rooms).
     */
    public function bchatRoom(): HasOne
    {
        return $this->hasOne(BchatRoom::class, 'penugasan_pembimbing_id', 'id');
    }

    /**
     * Relasi ke seluruh riwayat antrean event transactional outbox provisioning untuk penugasan ini.
     */
    public function bchatProvisioningOutboxes(): HasMany
    {
        return $this->hasMany(BchatProvisioningOutbox::class, 'penugasan_pembimbing_id', 'id');
    }

    /**
     * Relasi ke event transactional outbox provisioning khusus tahap proposal (PROPOSAL_ROOM_PROVISION).
     * Kardinalitas 1 : 0..1 (dijamin unique constraint uk_outbox_penugasan_event).
     */
    public function bchatRoomProvisioningOutbox(): HasOne
    {
        return $this->hasOne(BchatProvisioningOutbox::class, 'penugasan_pembimbing_id', 'id')
            ->where('event_type', 'PROPOSAL_ROOM_PROVISION');
    }
}

