<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BchatHistoryTransfer extends Model
{
    use HasFactory;

    protected $table = 'bchat_history_transfers';

    protected $primaryKey = 'id';

    protected $fillable = [
        'user_id',
        'room_id',
        'transfer_uuid',
        'room_uuid',
        'recipient_device_id',
        'recipient_device_uuid',
        'source_device_id',
        'source_device_uuid',
        'key_exchange_algorithm',
        'recipient_ephemeral_public_key',
        'source_ephemeral_public_key',
        'transfer_nonce',
        'request_payload',
        'request_signature',
        'status',
        'snapshot_ciphertext',
        'snapshot_hash_sha256',
        'snapshot_iv',
        'snapshot_auth_tag',
        'snapshot_metadata',
        'source_signature',
        'completion_signature',
        'uploaded_at',
        'completed_at',
        'cancelled_at',
        'cancellation_reason',
        'expires_at',
    ];

    protected function casts(): array
    {
        return [
            'user_id'             => 'integer',
            'room_id'             => 'integer',
            'recipient_device_id' => 'integer',
            'source_device_id'    => 'integer',
            'snapshot_metadata'   => 'array',
            'uploaded_at'         => 'datetime',
            'completed_at'        => 'datetime',
            'cancelled_at'        => 'datetime',
            'expires_at'          => 'datetime',
            'created_at'          => 'datetime',
            'updated_at'          => 'datetime',
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
     * Relasi ke Room ruang obrolan.
     */
    public function room(): BelongsTo
    {
        return $this->belongsTo(BchatRoom::class, 'room_id', 'id');
    }

    /**
     * Relasi ke perangkat penerima transfer riwayat.
     */
    public function recipientDevice(): BelongsTo
    {
        return $this->belongsTo(BchatUserDevice::class, 'recipient_device_id', 'id');
    }

    /**
     * Relasi ke perangkat sumber transfer riwayat.
     */
    public function sourceDevice(): BelongsTo
    {
        return $this->belongsTo(BchatUserDevice::class, 'source_device_id', 'id');
    }

    /**
     * Scope sesi REQUESTED.
     */
    public function scopeRequested(Builder $query): Builder
    {
        return $query->where('status', 'REQUESTED');
    }

    /**
     * Scope sesi READY (ciphertext telah diunggah oleh sumber).
     */
    public function scopeReady(Builder $query): Builder
    {
        return $query->where('status', 'READY');
    }

    /**
     * Scope sesi aktif (belum selesai/batal/expired).
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->whereIn('status', ['REQUESTED', 'READY'])
            ->where('expires_at', '>', now());
    }

    /**
     * Cek apakah transfer telah kedaluwarsa.
     */
    public function isExpired(): bool
    {
        return $this->status === 'EXPIRED' || ($this->expires_at !== null && $this->expires_at->isPast());
    }

    /**
     * Cek apakah transfer dapat dibatalkan.
     */
    public function canBeCancelled(): bool
    {
        return in_array($this->status, ['REQUESTED', 'READY'], true) && !$this->isExpired();
    }
}

