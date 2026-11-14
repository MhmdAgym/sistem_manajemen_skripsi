<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BchatRegistrationNonce extends Model
{
    use HasFactory;

    /**
     * Nama tabel eksplisit sesuai migration B-CHAT.
     */
    protected $table = 'bchat_registration_nonces';

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
        'nonce_uuid',
        'nonce_value',
        'public_key_fingerprint',
        'status',
        'issued_at',
        'expires_at',
        'consumed_at',
    ];

    /**
     * Type casting atribut model.
     */
    protected function casts(): array
    {
        return [
            'user_id'     => 'integer',
            'issued_at'   => 'integer',
            'expires_at'  => 'datetime',
            'consumed_at' => 'datetime',
            'created_at'  => 'datetime',
            'updated_at'  => 'datetime',
        ];
    }

    /**
     * Relasi ke User pemohon registrasi.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id', 'id');
    }

    /**
     * Scope untuk menyaring nonce berstatus ISSUED.
     */
    public function scopeIssued(Builder $query): Builder
    {
        return $query->where('status', 'ISSUED');
    }

    /**
     * Cek apakah nonce telah kedaluwarsa.
     */
    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }
}

