<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BchatRoomMember extends Model
{
    use HasFactory;

    /**
     * Nama tabel eksplisit sesuai migration B-CHAT.
     */
    protected $table = 'bchat_room_members';

    /**
     * Primary key model.
     */
    protected $primaryKey = 'id';

    /**
     * Atribut yang dapat diisi secara mass-assignment.
     */
    protected $fillable = [
        'room_id',
        'user_id',
        'role',
        'is_initiator',
        'is_active',
        'joined_at_epoch',
        'welcome_ack_at',
    ];

    /**
     * Type casting atribut model.
     */
    protected function casts(): array
    {
        return [
            'room_id'         => 'integer',
            'user_id'         => 'integer',
            'is_initiator'    => 'boolean',
            'is_active'       => 'boolean',
            'joined_at_epoch' => 'integer',
            'welcome_ack_at'  => 'datetime',
            'created_at'      => 'datetime',
            'updated_at'      => 'datetime',
        ];
    }

    /**
     * Relasi ke BchatRoom induk.
     */
    public function room(): BelongsTo
    {
        return $this->belongsTo(BchatRoom::class, 'room_id', 'id');
    }

    /**
     * Relasi ke akun User pemilik keanggotaan.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id', 'id');
    }
}

