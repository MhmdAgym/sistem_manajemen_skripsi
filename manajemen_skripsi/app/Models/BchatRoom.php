<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class BchatRoom extends Model
{
    use HasFactory;

    /**
     * Nama tabel eksplisit sesuai migration B-CHAT.
     */
    protected $table = 'bchat_rooms';

    /**
     * Primary key model.
     */
    protected $primaryKey = 'id';

    /**
     * Atribut yang dapat diisi secara mass-assignment.
     */
    protected $fillable = [
        'room_uuid',
        'penugasan_pembimbing_id',
        'tahap',
        'pembimbing_ke',
        'current_sequence',
        'status',
        'current_epoch',
        'group_id_hash',
    ];

    /**
     * Type casting atribut model.
     */
    protected function casts(): array
    {
        return [
            'penugasan_pembimbing_id' => 'integer',
            'pembimbing_ke'           => 'integer',
            'current_sequence'        => 'integer',
            'current_epoch'           => 'integer',
            'created_at'              => 'datetime',
            'updated_at'              => 'datetime',
        ];
    }

    /**
     * Relasi ke Penugasan Pembimbing akademik yang menjadi dasar legalitas room.
     * Kardinalitas 1 : 0..1 (penugasan_pembimbing_id unik).
     */
    public function penugasanPembimbing(): BelongsTo
    {
        return $this->belongsTo(PenugasanPembimbing::class, 'penugasan_pembimbing_id', 'id');
    }

    /**
     * Relasi ke anggota room (BchatRoomMember).
     */
    public function members(): HasMany
    {
        return $this->hasMany(BchatRoomMember::class, 'room_id', 'id');
    }

    /**
     * Relasi many-to-many ke User melalui pivot bchat_room_members.
     */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'bchat_room_members', 'room_id', 'user_id')
            ->withPivot(['role', 'is_initiator', 'is_active', 'joined_at_epoch', 'welcome_ack_at'])
            ->withTimestamps();
    }

    /**
     * Relasi ke pesan-pesan E2EE dalam room ini.
     */
    public function messages(): HasMany
    {
        return $this->hasMany(BchatMessage::class, 'room_id', 'id');
    }

    /**
     * Relasi ke operasi dan tantangan Welcome (Welcome Challenge handshake).
     */
    public function welcomeChallenges(): HasMany
    {
        return $this->hasMany(BchatWelcomeChallenge::class, 'room_id', 'id');
    }

    /**
     * Relasi ke sesi unggah berkas (BchatUploadSession) dalam room ini.
     */
    public function uploadSessions(): HasMany
    {
        return $this->hasMany(BchatUploadSession::class, 'room_id', 'id');
    }

    /**
     * Relasi ke berkas lampiran (BchatAttachment) dalam room ini.
     */
    public function attachments(): HasMany
    {
        return $this->hasMany(BchatAttachment::class, 'room_id', 'id');
    }

    /**
     * Relasi ke seluruh sesi kriptografi MLS (BchatRoomSession) dalam room ini.
     */
    public function sessions(): HasMany
    {
        return $this->hasMany(BchatRoomSession::class, 'room_id', 'id')->orderBy('generation_number', 'asc');
    }

    /**
     * Relasi ke sesi aktif saat ini (tepat maksimal 1 sesi ACTIVE).
     */
    public function activeSession(): HasOne
    {
        return $this->hasOne(BchatRoomSession::class, 'room_id', 'id')->where('status', 'ACTIVE');
    }

    /**
     * Relasi ke sesi generasi baru yang sedang menunggu handshake (PENDING).
     */
    public function pendingSession(): HasOne
    {
        return $this->hasOne(BchatRoomSession::class, 'room_id', 'id')
            ->where('status', 'PENDING')
            ->latestOfMany('generation_number');
    }

    /**
     * Relasi ke sesi terbaru berdasarkan generation_number.
     */
    public function latestSession(): HasOne
    {
        return $this->hasOne(BchatRoomSession::class, 'room_id', 'id')->latestOfMany('generation_number');
    }

    /**
     * Helper status room.
     */
    public function isActive(): bool
    {
        return $this->status === 'ACTIVE';
    }

    public function isArchived(): bool
    {
        return $this->status === 'ARCHIVED_READONLY';
    }

    public function isPendingWelcomeAck(): bool
    {
        return $this->status === 'PENDING_WELCOME_ACK';
    }

    public function hasActiveCryptoSession(): bool
    {
        return $this->activeSession()->exists();
    }
}

