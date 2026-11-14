<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BchatAttachmentChunk extends Model
{
    use HasFactory;

    protected $table = 'bchat_attachment_chunks';

    protected $primaryKey = 'id';

    protected $fillable = [
        'attachment_id',
        'chunk_index',
        'payload_length',
        'storage_path',
        'storage_hash_sha256',
    ];

    protected function casts(): array
    {
        return [
            'attachment_id'  => 'integer',
            'chunk_index'    => 'integer',
            'payload_length' => 'integer',
            'created_at'     => 'datetime',
            'updated_at'     => 'datetime',
        ];
    }

    /**
     * Relasi ke BchatAttachment induk.
     */
    public function attachment(): BelongsTo
    {
        return $this->belongsTo(BchatAttachment::class, 'attachment_id', 'id');
    }
}

