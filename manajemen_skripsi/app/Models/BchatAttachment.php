<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BchatAttachment extends Model
{
    use HasFactory;

    protected $table = 'bchat_attachments';

    protected $primaryKey = 'id';

    protected $fillable = [
        'attachment_uuid',
        'upload_session_id',
        'room_id',
        'session_id',
        'message_id',
        'plaintext_bytes',
        'ciphertext_bytes',
        'total_chunks',
        'file_hash_sha256',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'upload_session_id' => 'integer',
            'room_id'           => 'integer',
            'session_id'        => 'integer',
            'message_id'        => 'integer',
            'plaintext_bytes'   => 'integer',
            'ciphertext_bytes'  => 'integer',
            'total_chunks'      => 'integer',
            'created_at'        => 'datetime',
            'updated_at'        => 'datetime',
        ];
    }

    /**
     * Relasi ke sesi unggah (BchatUploadSession).
     */
    public function uploadSession(): BelongsTo
    {
        return $this->belongsTo(BchatUploadSession::class, 'upload_session_id', 'id');
    }

    /**
     * Relasi ke BchatRoom pemilik lampiran.
     */
    public function room(): BelongsTo
    {
        return $this->belongsTo(BchatRoom::class, 'room_id', 'id');
    }

    /**
     * Relasi ke sesi kriptografi MLS (BchatRoomSession) yang menaungi lampiran ini.
     */
    public function session(): BelongsTo
    {
        return $this->belongsTo(BchatRoomSession::class, 'session_id', 'id');
    }

    /**
     * Relasi ke pesan manifest terenkripsi (BchatMessage) yang menaungi lampiran ini.
     */
    public function message(): BelongsTo
    {
        return $this->belongsTo(BchatMessage::class, 'message_id', 'id');
    }

    /**
     * Relasi ke potongan-potongan chunk biner (BchatAttachmentChunk).
     */
    public function chunks(): HasMany
    {
        return $this->hasMany(BchatAttachmentChunk::class, 'attachment_id', 'id')->orderBy('chunk_index', 'asc');
    }
}

