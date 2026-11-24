<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BchatCommitAck extends Model
{
    use HasFactory;

    protected $table = 'bchat_commit_acks';

    protected $fillable = [
        'commit_id',
        'device_id',
        'user_id',
        'ack_type',
        'acked_epoch',
        'signature',
        'canonical_payload',
        'acked_at',
    ];

    protected function casts(): array
    {
        return [
            'commit_id'   => 'integer',
            'device_id'   => 'integer',
            'user_id'     => 'integer',
            'acked_epoch' => 'integer',
            'acked_at'    => 'datetime',
        ];
    }

    public function commit(): BelongsTo
    {
        return $this->belongsTo(BchatCommit::class, 'commit_id');
    }

    public function device(): BelongsTo
    {
        return $this->belongsTo(BchatUserDevice::class, 'device_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
