<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class BchatProvisioningOutboxLog extends Model
{
    use HasFactory;

    /**
     * Nama tabel eksplisit log audit outbox provisioning B-CHAT.
     */
    protected $table = 'bchat_provisioning_outbox_logs';

    /**
     * Primary key model.
     */
    protected $primaryKey = 'id';

    /**
     * Mengaktifkan manajemen timestamp untuk created_at.
     */
    public $timestamps = true;

    /**
     * Menonaktifkan kolom updated_at karena tabel log audit bersifat append-only.
     */
    public const UPDATED_AT = null;

    /**
     * Format penyimpanan timestamp database dengan presisi mikrodetik (DATETIME(6)).
     */
    protected $dateFormat = 'Y-m-d H:i:s.u';

    /**
     * Kolom yang dapat diisi melalui mass-assignment.
     * Tidak mencakup created_at yang dikelola secara otomatis oleh Eloquent.
     */
    protected $fillable = [
        'outbox_id',
        'user_id',
        'aktor_nama',
        'aktor_role',
        'alasan_replay',
        'snapshot_attempts_sebelum',
        'snapshot_error_terakhir',
    ];

    /**
     * Tipe data casting atribut model.
     */
    protected function casts(): array
    {
        return [
            'outbox_id'                 => 'integer',
            'user_id'                   => 'integer',
            'snapshot_attempts_sebelum' => 'integer',
            'created_at'                => 'datetime',
        ];
    }

    /**
     * Proteksi integritas append-only pada tingkat model Eloquent.
     * Mencegah mutasi UPDATE dan DELETE terhadap rekaman audit log.
     *
     * Catatan: Proteksi ini berlaku untuk operasi Eloquent model instances.
     * Operasi mass-update/delete via Query Builder atau SQL langsung tidak memicu model events.
     */
    protected static function booted(): void
    {
        static::updating(function () {
            throw new LogicException('Tabel log outbox bersifat append-only: rekaman log tidak boleh diubah.');
        });

        static::deleting(function () {
            throw new LogicException('Tabel log outbox bersifat append-only: rekaman log tidak boleh dihapus.');
        });
    }

    /**
     * Menghasilkan timestamp baru dalam zona waktu UTC dengan presisi mikrodetik
     * untuk pengisian otomatis kolom created_at.
     */
    public function freshTimestamp(): Carbon
    {
        return Carbon::now('UTC');
    }

    /**
     * Menormalisasi nilai datetime ke format database UTC dengan presisi mikrodetik.
     *
     * @param  mixed  $value
     * @return string|null
     */
    public function fromDateTime($value): ?string
    {
        if (empty($value)) {
            return null;
        }

        if ($value instanceof \DateTimeInterface) {
            return Carbon::instance($value)->setTimezone('UTC')->format($this->getDateFormat());
        }

        if (is_numeric($value)) {
            return Carbon::createFromTimestampUTC($value)->format($this->getDateFormat());
        }

        if (is_string($value)) {
            return Carbon::parse($value)->setTimezone('UTC')->format($this->getDateFormat());
        }

        return parent::fromDateTime($value);
    }

    /**
     * Mengonversi representasi datetime dari database ke objek Carbon UTC.
     *
     * @param  mixed  $value
     * @return \Illuminate\Support\Carbon|null
     */
    protected function asDateTime($value)
    {
        if ($value instanceof \DateTimeInterface) {
            return Carbon::instance($value)->setTimezone('UTC');
        }

        if (is_numeric($value)) {
            return Carbon::createFromTimestampUTC($value);
        }

        if (is_string($value)) {
            return Carbon::parse($value, 'UTC')->setTimezone('UTC');
        }

        return parent::asDateTime($value);
    }

    /**
     * Relasi ke entitas induk antrean outbox (BchatProvisioningOutbox).
     */
    public function outbox(): BelongsTo
    {
        return $this->belongsTo(BchatProvisioningOutbox::class, 'outbox_id', 'id');
    }

    /**
     * Relasi ke entitas User aktor administrator yang memicu operasi replay.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id', 'id');
    }
}

