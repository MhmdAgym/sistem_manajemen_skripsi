<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BchatUserDevice extends Model
{
    use HasFactory;

    /**
     * Nama tabel eksplisit sesuai migration B-CHAT.
     */
    protected $table = 'bchat_user_devices';

    /**
     * Primary key model.
     */
    protected $primaryKey = 'id';

    /**
     * Atribut yang dapat diisi secara mass-assignment.
     * Kolom virtual generated `active_user_slot` dilindungi dari mass-assignment.
     */
    protected $fillable = [
        'user_id',
        'device_uuid',
        'device_name',
        'key_version',
        'public_key',
        'public_key_fingerprint',
        'status',
        'is_trusted',
        'trusted_at',
        'trusted_by_device_id',
        'revoked_at',
        'revocation_reason',
        'last_active_at',
    ];

    /**
     * Type casting atribut model.
     */
    protected function casts(): array
    {
        return [
            'user_id'              => 'integer',
            'key_version'          => 'integer',
            'is_trusted'           => 'boolean',
            'trusted_by_device_id' => 'integer',
            'trusted_at'           => 'datetime',
            'revoked_at'           => 'datetime',
            'last_active_at'       => 'datetime',
            'created_at'           => 'datetime',
            'updated_at'           => 'datetime',
        ];
    }

    /**
     * Relasi ke User pemilik perangkat.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id', 'id');
    }

    /**
     * Relasi ke seluruh KeyPackage yang diterbitkan oleh perangkat ini.
     */
    public function keyPackages(): HasMany
    {
        return $this->hasMany(BchatKeyPackage::class, 'device_id', 'id');
    }

    /**
     * Relasi ke seluruh WelcomeChallenge di mana perangkat ini adalah penerima target.
     */
    public function welcomeChallenges(): HasMany
    {
        return $this->hasMany(BchatWelcomeChallenge::class, 'recipient_device_id', 'id');
    }

    /**
     * Relasi ke perangkat tepercaya yang menyetujui perangkat ini (jika ada).
     */
    public function trustedByDevice(): BelongsTo
    {
        return $this->belongsTo(self::class, 'trusted_by_device_id', 'id');
    }

    /**
     * Relasi ke sesi pairing di mana perangkat ini bertindak sebagai peminta (target).
     */
    public function targetPairings(): HasMany
    {
        return $this->hasMany(BchatDevicePairing::class, 'target_device_id', 'id');
    }

    /**
     * Relasi ke sesi pairing yang disetujui oleh perangkat ini (approver).
     */
    public function approvedPairings(): HasMany
    {
        return $this->hasMany(BchatDevicePairing::class, 'approver_device_id', 'id');
    }

    /**
     * Scope untuk menyaring perangkat berstatus ACTIVE.
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', 'ACTIVE');
    }

    /**
     * Scope untuk menyaring perangkat yang tepercaya (is_trusted = true).
     */
    public function scopeTrusted(Builder $query): Builder
    {
        return $query->where('is_trusted', true);
    }

    /**
     * Scope untuk menyaring perangkat berstatus REVOKED.
     */
    public function scopeRevoked(Builder $query): Builder
    {
        return $query->where('status', 'REVOKED');
    }

    /**
     * Scope untuk menyaring perangkat berstatus EXPIRED.
     */
    public function scopeExpired(Builder $query): Builder
    {
        return $query->where('status', 'EXPIRED');
    }

    /**
     * Cek apakah perangkat sedang aktif.
     */
    public function isActive(): bool
    {
        return $this->status === 'ACTIVE';
    }

    /**
     * Cek apakah perangkat telah berstatus tepercaya (trusted).
     */
    public function isTrusted(): bool
    {
        return (bool) $this->is_trusted;
    }
}

