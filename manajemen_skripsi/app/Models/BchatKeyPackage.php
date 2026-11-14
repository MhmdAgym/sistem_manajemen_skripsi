<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BchatKeyPackage extends Model
{
    use HasFactory;

    /**
     * Nama tabel eksplisit sesuai migration B-CHAT.
     */
    protected $table = 'bchat_key_packages';

    /**
     * Primary key model.
     */
    protected $primaryKey = 'id';

    /**
     * Atribut yang dapat diisi secara mass-assignment.
     */
    protected $fillable = [
        'user_id',
        'device_id',
        'key_package_hash',
        'key_package_data',
        'is_consumed',
        'consumed_at',
    ];

    /**
     * Type casting atribut model.
     */
    protected function casts(): array
    {
        return [
            'user_id'     => 'integer',
            'device_id'   => 'integer',
            'is_consumed' => 'boolean',
            'consumed_at' => 'datetime',
            'created_at'  => 'datetime',
            'updated_at'  => 'datetime',
        ];
    }

    /**
     * Relasi ke User pemilik paket kunci MLS publik.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id', 'id');
    }

    /**
     * Relasi ke BchatUserDevice penerbit KeyPackage ini.
     */
    public function device(): BelongsTo
    {
        return $this->belongsTo(BchatUserDevice::class, 'device_id', 'id');
    }
}
