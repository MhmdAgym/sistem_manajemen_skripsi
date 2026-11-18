<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BchatDevicePairing extends Model
{
    use HasFactory;

    protected $table = 'bchat_device_pairings';

    protected $primaryKey = 'id';

    protected $fillable = [
        'user_id',
        'pairing_uuid',
        'pairing_code',
        'target_device_id',
        'target_device_uuid',
        'target_public_key_fingerprint',
        'challenge_nonce',
        'status',
        'approver_device_id',
        'approver_device_uuid',
        'approval_signature',
        'approval_payload',
        'approved_at',
        'rejected_at',
        'rejection_reason',
        'expires_at',
    ];

    protected function casts(): array
    {
        return [
            'user_id'            => 'integer',
            'target_device_id'   => 'integer',
            'approver_device_id' => 'integer',
            'approved_at'        => 'datetime',
            'rejected_at'        => 'datetime',
            'expires_at'         => 'datetime',
            'created_at'         => 'datetime',
            'updated_at'         => 'datetime',
        ];
    }

    /**
     * Relasi ke User pemilik akun.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id', 'id');
    }

    /**
     * Relasi ke perangkat target (peminta pairing).
     */
    public function targetDevice(): BelongsTo
    {
        return $this->belongsTo(BchatUserDevice::class, 'target_device_id', 'id');
    }

    /**
     * Relasi ke perangkat tepercaya yang menyetujui (approver).
     */
    public function approverDevice(): BelongsTo
    {
        return $this->belongsTo(BchatUserDevice::class, 'approver_device_id', 'id');
    }

    /**
     * Scope sesi PENDING.
     */
    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', 'PENDING');
    }

    /**
     * Scope sesi APPROVED.
     */
    public function scopeApproved(Builder $query): Builder
    {
        return $query->where('status', 'APPROVED');
    }

    /**
     * Cek apakah sesi masih dalam status PENDING.
     */
    public function isPending(): bool
    {
        return $this->status === 'PENDING';
    }

    /**
     * Cek apakah sesi telah APPROVED.
     */
    public function isApproved(): bool
    {
        return $this->status === 'APPROVED';
    }

    /**
     * Cek apakah sesi telah kedaluwarsa waktu.
     */
    public function isExpired(): bool
    {
        if ($this->status === 'EXPIRED') {
            return true;
        }

        return $this->expires_at && now()->isAfter($this->expires_at);
    }
}

