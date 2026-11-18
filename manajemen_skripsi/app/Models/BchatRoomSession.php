<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BchatRoomSession extends Model
{
    use HasFactory;

    /**
     * Nama tabel eksplisit sesuai migration B-CHAT.
     */
    protected $table = 'bchat_room_sessions';

    /**
     * Primary key model.
     */
    protected $primaryKey = 'id';

    /**
     * Atribut yang dapat diisi secara mass-assignment.
     * Kolom virtual generated `active_room_slot` dilindungi dari mass-assignment.
     */
    protected $fillable = [
        'session_uuid',
        'room_id',
        'generation_number',
        'status',
        'group_id_hash',
        'start_epoch',
        'end_epoch',
        'start_sequence',
        'end_sequence',
        'current_sequence',
        'created_by_user_id',
        'activated_at',
        'archived_at',
        'failed_at',
        'failure_reason',
    ];

    /**
     * Type casting atribut model.
     */
    protected function casts(): array
    {
        return [
            'room_id'            => 'integer',
            'generation_number'  => 'integer',
            'start_epoch'        => 'integer',
            'end_epoch'          => 'integer',
            'start_sequence'     => 'integer',
            'end_sequence'       => 'integer',
            'current_sequence'   => 'integer',
            'created_by_user_id' => 'integer',
            'activated_at'       => 'datetime',
            'archived_at'        => 'datetime',
            'failed_at'          => 'datetime',
            'created_at'         => 'datetime',
            'updated_at'         => 'datetime',
        ];
    }

    /**
     * Relasi ke BchatRoom induk.
     */
    public function room(): BelongsTo
    {
        return $this->belongsTo(BchatRoom::class, 'room_id', 'id');
    }

    /**
     * Relasi ke User yang menginisiasi sesi ini (jika ada).
     */
    public function createdByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id', 'id');
    }

    /**
     * Relasi ke pesan-pesan E2EE dalam sesi ini.
     */
    public function messages(): HasMany
    {
        return $this->hasMany(BchatMessage::class, 'session_id', 'id');
    }

    /**
     * Relasi ke berkas lampiran yang dinaungi oleh sesi ini.
     */
    public function attachments(): HasMany
    {
        return $this->hasMany(BchatAttachment::class, 'session_id', 'id');
    }

    /**
     * Relasi ke operasi dan tantangan Welcome pada sesi ini.
     */
    public function welcomeChallenges(): HasMany
    {
        return $this->hasMany(BchatWelcomeChallenge::class, 'session_id', 'id');
    }

    /**
     * Helper status sesi.
     */
    public function isActive(): bool
    {
        return $this->status === 'ACTIVE';
    }

    public function isPending(): bool
    {
        return $this->status === 'PENDING';
    }

    public function isArchived(): bool
    {
        return $this->status === 'ARCHIVED';
    }

    public function isFailed(): bool
    {
        return $this->status === 'FAILED';
    }
}

