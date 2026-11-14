<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BchatWelcomeChallenge extends Model
{
    use HasFactory;

    /**
     * Nama tabel eksplisit sesuai migration B-CHAT.
     */
    protected $table = 'bchat_welcome_challenges';

    /**
     * Primary key model.
     */
    protected $primaryKey = 'id';

    /**
     * Atribut yang dapat diisi secara mass-assignment.
     * Kolom virtual generated `active_operation_slot` dilindungi dari mass-assignment.
     */
    protected $fillable = [
        'welcome_operation_uuid',
        'room_id',
        'recipient_user_id',
        'recipient_device_id',
        'welcome_message',
        'group_id_hash',
        'epoch',
        'operation_status',
        'challenge_uuid',
        'challenge_nonce',
        'challenge_generation',
        'challenge_status',
        'expires_at',
        'consumed_at',
        'ack_payload_hash',
        'ack_signature',
        'ip_address',
        'user_agent',
    ];

    /**
     * Type casting atribut model.
     */
    protected function casts(): array
    {
        return [
            'room_id'              => 'integer',
            'recipient_user_id'    => 'integer',
            'recipient_device_id'  => 'integer',
            'epoch'                => 'integer',
            'challenge_generation' => 'integer',
            'expires_at'           => 'datetime',
            'consumed_at'          => 'datetime',
            'created_at'           => 'datetime',
            'updated_at'           => 'datetime',
        ];
    }

    /**
     * Relasi ke BchatRoom induk dari operasi Welcome ini.
     */
    public function room(): BelongsTo
    {
        return $this->belongsTo(BchatRoom::class, 'room_id', 'id');
    }

    /**
     * Relasi ke User penerima paket Welcome (pihak yang ditantang ACK).
     */
    public function recipientUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recipient_user_id', 'id');
    }

    /**
     * Relasi ke BchatUserDevice penerima paket Welcome (perangkat penandatangan ACK).
     */
    public function recipientDevice(): BelongsTo
    {
        return $this->belongsTo(BchatUserDevice::class, 'recipient_device_id', 'id');
    }
}
