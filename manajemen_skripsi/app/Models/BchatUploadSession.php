<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class BchatUploadSession extends Model
{
    use HasFactory;

    protected $table = 'bchat_upload_sessions';

    protected $primaryKey = 'id';

    protected $fillable = [
        'session_uuid',
        'room_id',
        'user_id',
        'total_bytes',
        'total_chunks',
        'status',
        'expires_at',
    ];

    protected function casts(): array
    {
        return [
            'room_id'      => 'integer',
            'user_id'      => 'integer',
            'total_bytes'  => 'integer',
            'total_chunks' => 'integer',
            'expires_at'   => 'datetime',
            'created_at'   => 'datetime',
            'updated_at'   => 'datetime',
        ];
    }

    /**
     * Relasi ke BchatRoom tempat sesi unggah ini berlangsung.
     */
    public function room(): BelongsTo
    {
        return $this->belongsTo(BchatRoom::class, 'room_id', 'id');
    }

    /**
     * Relasi ke User pengunggah berkas.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id', 'id');
    }

    /**
     * Relasi ke berkas lampiran utama yang terikat pada sesi unggah ini (kardinalitas 1:1).
     */
    public function attachment(): HasOne
    {
        return $this->hasOne(BchatAttachment::class, 'upload_session_id', 'id');
    }

    /**
     * Relasi ke berkas lampiran yang terikat pada sesi unggah ini.
     */
    public function attachments(): HasMany
    {
        return $this->hasMany(BchatAttachment::class, 'upload_session_id', 'id');
    }

    /**
     * Memeriksa apakah sesi unggah telah kedaluwarsa.
     */
    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }
}

