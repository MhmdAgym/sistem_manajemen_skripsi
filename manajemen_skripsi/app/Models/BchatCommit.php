<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BchatCommit extends Model
{
    use HasFactory;

    protected $table = 'bchat_commits';

    protected $fillable = [
        'commit_uuid',
        'room_id',
        'session_id',
        'sender_user_id',
        'sender_device_id',
        'from_epoch',
        'to_epoch',
        'commit_message',
        'commit_hash',
        'added_device_id',
        'key_package_id',
        'welcome_operation_uuid',
        'status',
        'transition_completed_at',
    ];

    protected function casts(): array
    {
        return [
            'room_id'                 => 'integer',
            'session_id'              => 'integer',
            'sender_user_id'          => 'integer',
            'sender_device_id'        => 'integer',
            'from_epoch'              => 'integer',
            'to_epoch'                => 'integer',
            'added_device_id'         => 'integer',
            'key_package_id'          => 'integer',
            'transition_completed_at' => 'datetime',
        ];
    }

    public function room(): BelongsTo
    {
        return $this->belongsTo(BchatRoom::class, 'room_id');
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(BchatRoomSession::class, 'session_id');
    }

    public function senderUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sender_user_id');
    }

    public function senderDevice(): BelongsTo
    {
        return $this->belongsTo(BchatUserDevice::class, 'sender_device_id');
    }

    public function addedDevice(): BelongsTo
    {
        return $this->belongsTo(BchatUserDevice::class, 'added_device_id');
    }

    public function keyPackage(): BelongsTo
    {
        return $this->belongsTo(BchatKeyPackage::class, 'key_package_id');
    }

    public function acks(): HasMany
    {
        return $this->hasMany(BchatCommitAck::class, 'commit_id');
    }
}
