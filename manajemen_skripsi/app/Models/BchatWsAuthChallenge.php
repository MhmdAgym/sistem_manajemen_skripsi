<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BchatWsAuthChallenge extends Model
{
    use HasFactory;

    protected $table = 'bchat_ws_auth_challenges';

    protected $fillable = [
        'user_id',
        'device_id',
        'challenge_uuid',
        'device_uuid',
        'socket_id',
        'channel_name',
        'challenge_nonce',
        'status',
        'expires_at',
        'consumed_at',
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
        return now()->isAfter($this->expires_at);
    }

    public function isPending(): bool
    {
        return $this->status === 'ISSUED' && !$this->isExpired();
    }
}

