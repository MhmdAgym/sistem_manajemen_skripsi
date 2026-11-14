<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BchatStepUpToken extends Model
{
    use HasFactory;

    /**
     * Masa berlaku otorisasi step-up kanonis (5 menit).
     */
    public const TTL_MINUTES = 5;

    /**
     * Nama tabel eksplisit sesuai migration B-CHAT.
     */
    protected $table = 'bchat_step_up_tokens';

    /**
     * Primary key model.
     */
    protected $primaryKey = 'id';

    /**
     * Atribut yang dapat diisi secara mass-assignment.
     */
    protected $fillable = [
        'user_id',
        'device_uuid',
        'token_hash',
        'action_scope',
        'is_consumed',
        'expires_at',
        'consumed_at',
        'ip_address',
        'user_agent',
    ];

    /**
     * Type casting atribut model.
     */
    protected function casts(): array
    {
        return [
            'user_id'     => 'integer',
            'is_consumed' => 'boolean',
            'expires_at'  => 'datetime',
            'consumed_at' => 'datetime',
            'created_at'  => 'datetime',
            'updated_at'  => 'datetime',
        ];
    }

    /**
     * Relasi ke User pemilik otorisasi step-up.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id', 'id');
    }

    /**
     * Scope untuk menyaring token yang masih valid (belum dikonsumsi dan belum expired).
     */
    public function scopeValid(Builder $query): Builder
    {
        return $query->where('is_consumed', false)
            ->where('expires_at', '>', now());
    }

    /**
     * Cek apakah token masih valid digunakan.
     */
    public function isValid(): bool
    {
        return !$this->is_consumed && $this->expires_at !== null && !$this->expires_at->isPast();
    }
}

