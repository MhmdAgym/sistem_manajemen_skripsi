<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BchatMessage extends Model
{
    use HasFactory;

    /**
     * Nama tabel eksplisit sesuai migration B-CHAT.
     */
    protected $table = 'bchat_messages';

    /**
     * Primary key model.
     */
    protected $primaryKey = 'id';

    /**
     * Atribut yang dapat diisi secara mass-assignment.
     */
    protected $fillable = [
        'room_id',
        'server_sequence',
        'message_uuid',
        'sender_id',
        'epoch',
        'generation',
        'content_type',
        'ciphertext_payload',
        'payload_hash_sha256',
        'client_sent_at',
    ];

    /**
     * Type casting atribut model.
     */
    protected function casts(): array
    {
        return [
            'room_id'         => 'integer',
            'server_sequence' => 'integer',
            'sender_id'       => 'integer',
            'epoch'           => 'integer',
            'generation'      => 'integer',
            'content_type'    => 'integer',
            'client_sent_at'  => 'datetime',
            'created_at'      => 'datetime',
            'updated_at'      => 'datetime',
        ];
    }

    /**
     * Relasi ke BchatRoom tempat pesan ini dikirimkan.
     */
    public function room(): BelongsTo
    {
        return $this->belongsTo(BchatRoom::class, 'room_id', 'id');
    }

    /**
     * Relasi ke User pengirim pesan ciphertext.
     */
    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sender_id', 'id');
    }

    /**
     * Relasi ke tanda terima pesan (delivery & read receipts).
     */
    public function receipts(): HasMany
    {
        return $this->hasMany(BchatMessageReceipt::class, 'message_id', 'id');
    }

    /**
     * Relasi ke berkas lampiran (BchatAttachment) yang dinaungi oleh pesan ini.
     */
    public function attachments(): HasMany
    {
        return $this->hasMany(BchatAttachment::class, 'message_id', 'id');
    }
}

