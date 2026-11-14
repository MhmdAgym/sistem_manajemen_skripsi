<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BchatProvisioningOutbox extends Model
{
    use HasFactory;

    /**
     * Nama tabel eksplisit antrean outbox provisioning B-CHAT.
     */
    protected $table = 'bchat_provisioning_outbox';

    /**
     * Primary key model.
     */
    protected $primaryKey = 'id';

    /**
     * Mengaktifkan automatic timestamps Eloquent (created_at dan updated_at).
     */
    public $timestamps = true;

    /**
     * Format penyimpanan timestamp database dengan presisi mikrodetik (DATETIME(6)).
     */
    protected $dateFormat = 'Y-m-d H:i:s.u';

    /**
     * Kolom yang dapat diisi melalui mass-assignment.
     * Tidak mencakup created_at dan updated_at yang dikelola secara transaksional/otomatis.
     */
    protected $fillable = [
        'event_uuid',
        'penugasan_pembimbing_id',
        'event_type',
        'payload',
        'status',
        'attempts',
        'next_attempt_at',
        'locked_at',
        'locked_by',
        'generation_token',
        'last_error_code',
        'last_error',
        'failed_at',
        'completed_at',
        'replay_count',
        'replayed_at',
    ];

    /**
     * Tipe data casting atribut model.
     */
    protected function casts(): array
    {
        return [
            'penugasan_pembimbing_id' => 'integer',
            'payload'                 => 'array',
            'attempts'                => 'integer',
            'generation_token'        => 'integer',
            'replay_count'            => 'integer',
            'next_attempt_at'         => 'datetime',
            'locked_at'               => 'datetime',
            'failed_at'               => 'datetime',
            'completed_at'            => 'datetime',
            'replayed_at'             => 'datetime',
            'created_at'              => 'datetime',
            'updated_at'              => 'datetime',
        ];
    }

    /**
     * Menghasilkan timestamp baru dalam zona waktu UTC dengan presisi mikrodetik
     * untuk pengisian otomatis kolom created_at dan updated_at.
     */
    public function freshTimestamp(): Carbon
    {
        return Carbon::now('UTC');
    }

    /**
     * Menormalisasi nilai datetime ke format database UTC dengan presisi mikrodetik.
     *
     * Kontrak:
     * - Objek DateTimeInterface dinormalisasi ke UTC dan diformat menggunakan getDateFormat().
     * - Nilai integer numerik diperlakukan sebagai UNIX timestamp UTC.
     * - Nilai string diurai secara timezone-aware; jika string tidak memiliki penanda timezone/offset,
     *   string diinterpretasikan menggunakan timezone default aplikasi (Asia/Jakarta) dan TIDAK
     *   diasumsikan sebagai UTC, lalu dikonversi ke UTC.
     * - Service pemanggil tetap berkontrak mengirimkan objek Carbon/DateTimeInterface UTC eksplisit.
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
     * Karena database MySQL menyimpan kolom DATETIME(6) dalam UTC tanpa offset timezone,
     * string yang dimuat dari database diinterpretasikan dalam zona waktu UTC.
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
     * Relasi ke entitas PenugasanPembimbing akademik yang mendasari event outbox ini.
     */
    public function penugasanPembimbing(): BelongsTo
    {
        return $this->belongsTo(PenugasanPembimbing::class, 'penugasan_pembimbing_id', 'id');
    }

    /**
     * Relasi ke seluruh riwayat audit log administrasi (replay) outbox ini.
     */
    public function logs(): HasMany
    {
        return $this->hasMany(BchatProvisioningOutboxLog::class, 'outbox_id', 'id');
    }
}

