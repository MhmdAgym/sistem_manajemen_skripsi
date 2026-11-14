<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BchatMessageReceipt extends Model
{
    use HasFactory;

    /**
     * Nama tabel eksplisit sesuai migration B-CHAT.
     */
    protected $table = 'bchat_message_receipts';

    /**
     * Primary key model.
     */
    protected $primaryKey = 'id';

    /**
     * Tabel migrasi tidak menggunakan kolom created_at dan updated_at standar,
     * melainkan kolom khusus `status_at` (TIMESTAMP default CURRENT_TIMESTAMP).
     */
    public $timestamps = false;

    /**
     * Atribut yang dapat diisi secara mass-assignment.
     */
    protected $fillable = [
        'message_id',
        'user_id',
        'status',
        'status_at',
    ];

    /**
     * Type casting atribut model.
     */
    protected function casts(): array
    {
        return [
            'message_id' => 'integer',
            'user_id'    => 'integer',
            'status_at'  => 'datetime',
        ];
    }

    /**
     * Relasi ke BchatMessage yang dikonfirmasi.
     */
    public function message(): BelongsTo
    {
        return $this->belongsTo(BchatMessage::class, 'message_id', 'id');
    }

    /**
     * Relasi ke User yang mengirimkan tanda terima (penerima pesan).
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id', 'id');
    }
}

