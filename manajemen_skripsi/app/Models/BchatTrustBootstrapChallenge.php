<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BchatTrustBootstrapChallenge extends Model
{
    use HasFactory;

    protected $table = 'bchat_trust_bootstrap_challenges';

    public const PURPOSE_TRUST_BOOTSTRAP = 'TRUST_BOOTSTRAP';

    protected $fillable = [
        'user_id',
        'device_id',
        'challenge_uuid',
        'device_uuid',
        'public_key_fingerprint',
        'challenge_nonce',
        'purpose',
        'status',
        'expires_at',
        'consumed_at',
        'bootstrap_signature',
        'bootstrap_payload',
        'ip_address',
        'user_agent',
    ];

    protected function casts(): array
    {
        return [
            'user_id'     => 'integer',
            'device_id'   => 'integer',
            'expires_at'  => 'datetime',
            'consumed_at' => 'datetime',
            'created_at'  => 'datetime',
            'updated_at'  => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function device(): BelongsTo
    {
        return $this->belongsTo(BchatUserDevice::class, 'device_id');
    }

    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }

    public function isConsumed(): bool
    {
        return $this->status === 'CONSUMED';
    }
}

