<?php

namespace App\Services;

use App\Exceptions\BchatPairingException;
use App\Models\BchatDevicePairing;
use App\Models\BchatUserDevice;
use App\Models\User;
use Exception;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

class BchatPairingService
{
    /**
     * Masa berlaku default sesi pairing perangkat (5 menit / 300 detik).
     */
    public const PAIRING_TTL_SECONDS = 300;

    public function __construct(
        protected BchatPairingVerificationService $verifier
    ) {}

    /**
     * Membuat permintaan pairing baru dari perangkat baru (target device).
     *
     * @param User $user Pengguna yang terautentikasi
     * @param string $targetDeviceUuid UUID perangkat yang meminta status tepercaya
     * @return array<string, mixed> Data sesi pairing kanonis termasuk QR payload
     *
     * @throws BchatPairingException
     */
    public function createPairingRequest(User $user, string $targetDeviceUuid): array
    {
        $cleanTargetUuid = strtolower(trim($targetDeviceUuid));
        if (!Str::isUuid($cleanTargetUuid)) {
            throw new BchatPairingException('Format Target Device UUID tidak valid.', 'INVALID_DEVICE_UUID', 422);
        }

        return DB::transaction(function () use ($user, $cleanTargetUuid) {
            // 1. Kunci baris user untuk mencegah race condition pembuatan pairing bersamaan
            User::where('id', $user->id)->lockForUpdate()->firstOrFail();

            // 2. Validasi perangkat target peminta kepercayaan
            $targetDevice = BchatUserDevice::where('user_id', $user->id)
                ->where('device_uuid', $cleanTargetUuid)
                ->lockForUpdate()
                ->first();

            if (!$targetDevice) {
                throw new BchatPairingException('Perangkat target tidak ditemukan.', 'TARGET_DEVICE_NOT_FOUND', 404);
            }

            if (!$targetDevice->isActive()) {
                throw new BchatPairingException('Perangkat target tidak aktif atau telah dicabut.', 'TARGET_DEVICE_NOT_ACTIVE', 403);
            }

            if ($targetDevice->isTrusted()) {
                throw new BchatPairingException('Perangkat target sudah berstatus tepercaya.', 'DEVICE_ALREADY_TRUSTED', 400);
            }

            // 3. Pastikan user memiliki setidaknya satu perangkat aktif tepercaya yang dapat memberikan persetujuan
            $hasTrustedApprover = BchatUserDevice::where('user_id', $user->id)
                ->where('status', 'ACTIVE')
                ->where('is_trusted', true)
                ->where('device_uuid', '!=', $cleanTargetUuid)
                ->exists();

            if (!$hasTrustedApprover) {
                throw new BchatPairingException(
                    'Tidak ada perangkat tepercaya yang aktif untuk menyetujui pairing. Lakukan registrasi awal atau reset sesi.',
                    'NO_TRUSTED_DEVICE_AVAILABLE',
                    400
                );
            }

            // 4. Batalkan (CANCEL) seluruh sesi pairing PENDING lama dari perangkat target ini
            BchatDevicePairing::where('user_id', $user->id)
                ->where('target_device_id', $targetDevice->id)
                ->where('status', 'PENDING')
                ->lockForUpdate()
                ->update([
                    'status' => 'CANCELED',
                    'updated_at' => now(),
                ]);

            // 5. Bangkitkan parameter kriptografis dan sesi pairing
            $pairingUuid = (string) Str::uuid();

            // Kode pairing 8 karakter kanonis: format XXXX-XXXX (huruf kapital & angka aman)
            $codePart1 = Str::upper(Str::random(4));
            $codePart2 = Str::upper(Str::random(4));
            $pairingCode = "{$codePart1}-{$codePart2}";

            // Nonce 256-bit server (64 hex)
            $challengeNonce = bin2hex(random_bytes(32));
            $ttlSeconds = (int) config('bchat.pairing_ttl_seconds', self::PAIRING_TTL_SECONDS);
            $expiresAt = now()->addSeconds($ttlSeconds);

            $pairing = BchatDevicePairing::create([
                'user_id'                       => $user->id,
                'pairing_uuid'                  => $pairingUuid,
                'pairing_code'                  => $pairingCode,
                'target_device_id'              => $targetDevice->id,
                'target_device_uuid'            => $targetDevice->device_uuid,
                'target_public_key_fingerprint' => $targetDevice->public_key_fingerprint,
                'challenge_nonce'               => $challengeNonce,
                'status'                        => 'PENDING',
                'expires_at'                    => $expiresAt,
            ]);

            return [
                'pairing_uuid'                  => $pairing->pairing_uuid,
                'pairing_code'                  => $pairing->pairing_code,
                'target_device_uuid'            => $targetDevice->device_uuid,
                'target_device_name'            => $targetDevice->device_name,
                'target_public_key_fingerprint' => $targetDevice->public_key_fingerprint,
                'challenge_nonce'               => $pairing->challenge_nonce,
                'expires_at'                    => $pairing->expires_at->toIso8601String(),
                'expires_in_seconds'            => $ttlSeconds,
                'qr_payload'                    => [
                    'v'            => 1,
                    'type'         => 'bchat_pairing',
                    'pairing_uuid' => $pairing->pairing_uuid,
                    'pairing_code' => $pairing->pairing_code,
                    'user_id'      => (int) $user->id,
                    'target_uuid'  => $targetDevice->device_uuid,
                    'target_name'  => $targetDevice->device_name,
                    'fingerprint'  => $targetDevice->public_key_fingerprint,
                    'expires_at'   => $pairing->expires_at->toIso8601String(),
                ],
            ];
        });
    }

    /**
     * Mengambil status sesi pairing secara aman. Memperbarui status EXPIRED jika waktu habis.
     */
    public function getPairingStatus(User $user, string $pairingUuid): array
    {
        $cleanUuid = strtolower(trim($pairingUuid));
        if (!Str::isUuid($cleanUuid)) {
            throw new BchatPairingException('Format Pairing UUID tidak valid.', 'INVALID_PAIRING_UUID', 422);
        }

        $pairing = BchatDevicePairing::with(['targetDevice', 'approverDevice'])
            ->where('pairing_uuid', $cleanUuid)
            ->where('user_id', $user->id)
            ->first();

        if (!$pairing) {
            throw new BchatPairingException('Sesi pairing tidak ditemukan.', 'PAIRING_NOT_FOUND', 404);
        }

        // Periksa kedaluwarsa secara otomatis
        if ($pairing->status === 'PENDING' && now()->isAfter($pairing->expires_at)) {
            $pairing->update(['status' => 'EXPIRED']);
            $pairing->status = 'EXPIRED';
        }

        return [
            'pairing_uuid'     => $pairing->pairing_uuid,
            'pairing_code'     => $pairing->pairing_code,
            'status'           => $pairing->status,
            'target_device'    => [
                'device_uuid'            => $pairing->target_device_uuid,
                'device_name'            => $pairing->targetDevice?->device_name,
                'public_key_fingerprint' => $pairing->target_public_key_fingerprint,
                'is_trusted'             => (bool) $pairing->targetDevice?->is_trusted,
            ],
            'approver_device'  => $pairing->approverDevice ? [
                'device_uuid' => $pairing->approver_device_uuid,
                'device_name' => $pairing->approverDevice->device_name,
            ] : null,
            'approved_at'      => $pairing->approved_at?->toIso8601String(),
            'rejected_at'      => $pairing->rejected_at?->toIso8601String(),
            'rejection_reason' => $pairing->rejection_reason,
            'expires_at'       => $pairing->expires_at?->toIso8601String(),
            'expires_in_seconds' => max(0, (int) now()->diffInSeconds($pairing->expires_at, false)),
            'is_expired'       => $pairing->status === 'EXPIRED',
        ];
    }

    /**
     * Mencari sesi pairing aktif berdasarkan pairing_code (dipanggil oleh approver device).
     */
    public function lookupPairingByCode(User $user, string $pairingCode): array
    {
        $cleanCode = strtoupper(trim($pairingCode));

        $pairing = BchatDevicePairing::with(['targetDevice'])
            ->where('user_id', $user->id)
            ->where('pairing_code', $cleanCode)
            ->first();

        if (!$pairing) {
            throw new BchatPairingException('Kode pairing tidak ditemukan atau bukan milik akun Anda.', 'PAIRING_NOT_FOUND', 404);
        }

        if ($pairing->status === 'PENDING' && now()->isAfter($pairing->expires_at)) {
            $pairing->update(['status' => 'EXPIRED']);
            throw new BchatPairingException('Kode pairing sudah kedaluwarsa.', 'PAIRING_EXPIRED', 410);
        }

        if ($pairing->status !== 'PENDING') {
            throw new BchatPairingException(
                "Sesi pairing sudah tidak aktif (status: {$pairing->status}).",
                'PAIRING_NOT_PENDING',
                400
            );
        }

        return [
            'pairing_uuid'                  => $pairing->pairing_uuid,
            'pairing_code'                  => $pairing->pairing_code,
            'target_device_uuid'            => $pairing->target_device_uuid,
            'target_device_name'            => $pairing->targetDevice?->device_name,
            'target_public_key_fingerprint' => $pairing->target_public_key_fingerprint,
            'challenge_nonce'               => $pairing->challenge_nonce,
            'expires_at'                    => $pairing->expires_at?->toIso8601String(),
        ];
    }

    /**
     * Menyetujui pairing dengan verifikasi kriptografis Ed25519 dari approver device.
     *
     * @param User $user Pengguna yang terautentikasi
     * @param string $pairingUuid UUID sesi pairing
     * @param string $approverDeviceUuid UUID perangkat tepercaya pemberi persetujuan
     * @param string $payloadHex Hex dari payload kanonis 159-byte yang ditandatangani
     * @param string $signatureHex Hex dari signature detached 64-byte Ed25519
     * @return array<string, mixed> Hasil persetujuan
     */
    public function approvePairing(
        User $user,
        string $pairingUuid,
        string $approverDeviceUuid,
        string $payloadHex,
        string $signatureHex
    ): array {
        $cleanPairingUuid = strtolower(trim($pairingUuid));
        if (!Str::isUuid($cleanPairingUuid)) {
            throw new BchatPairingException('Format Pairing UUID tidak valid.', 'INVALID_PAIRING_UUID', 422);
        }

        $cleanApproverUuid = strtolower(trim($approverDeviceUuid));
        if (!Str::isUuid($cleanApproverUuid)) {
            throw new BchatPairingException('Format Approver Device UUID tidak valid.', 'INVALID_APPROVER_DEVICE_UUID', 422);
        }

        $cleanPayload = strtolower(trim($payloadHex));
        if (strlen($cleanPayload) !== 318 || !ctype_xdigit($cleanPayload)) { // 159 bytes * 2 = 318 hex
            throw new BchatPairingException('Panjang atau format payload persetujuan tidak valid (diharapkan 159 byte hex).', 'INVALID_APPROVAL_PAYLOAD', 422);
        }

        $cleanSignature = strtolower(trim($signatureHex));
        if (strlen($cleanSignature) !== 128 || !ctype_xdigit($cleanSignature)) { // 64 bytes * 2 = 128 hex
            throw new BchatPairingException('Panjang atau format signature persetujuan tidak valid (diharapkan 64 byte hex).', 'INVALID_APPROVAL_SIGNATURE', 422);
        }

        $payloadBinary = hex2bin($cleanPayload);
        $signatureBinary = hex2bin($cleanSignature);

        // Periksa kedaluwarsa di luar transaksi mutasi persetujuan agar status EXPIRED terpersist di basis data
        $preCheck = BchatDevicePairing::where('pairing_uuid', $cleanPairingUuid)
            ->where('user_id', $user->id)
            ->first();

        if ($preCheck && ($preCheck->status === 'EXPIRED' || ($preCheck->status === 'PENDING' && now()->isAfter($preCheck->expires_at)))) {
            if ($preCheck->status === 'PENDING') {
                $preCheck->update(['status' => 'EXPIRED']);
            }
            throw new BchatPairingException('Sesi pairing telah kedaluwarsa.', 'PAIRING_EXPIRED', 410);
        }

        return DB::transaction(function () use (
            $user,
            $cleanPairingUuid,
            $cleanApproverUuid,
            $payloadBinary,
            $signatureBinary,
            $cleanPayload,
            $cleanSignature
        ) {
            // 1. Kunci baris user untuk menjamin mutual exclusion
            User::where('id', $user->id)->lockForUpdate()->firstOrFail();

            // 2. Kunci dan validasi sesi pairing
            $pairing = BchatDevicePairing::where('pairing_uuid', $cleanPairingUuid)
                ->where('user_id', $user->id)
                ->lockForUpdate()
                ->first();

            if (!$pairing) {
                throw new BchatPairingException('Sesi pairing tidak ditemukan.', 'PAIRING_NOT_FOUND', 404);
            }

            // 3. Kunci dan validasi Approver Device (otorisasi perangkat pemberi persetujuan)
            $approver = BchatUserDevice::where('user_id', $user->id)
                ->where('device_uuid', $cleanApproverUuid)
                ->lockForUpdate()
                ->first();

            if (!$approver) {
                throw new BchatPairingException('Perangkat approver tidak ditemukan pada akun ini.', 'APPROVER_DEVICE_NOT_FOUND', 404);
            }

            if (!$approver->isActive()) {
                throw new BchatPairingException('Perangkat approver tidak aktif atau telah dicabut (REVOKED).', 'APPROVER_DEVICE_NOT_ACTIVE', 403);
            }

            if (!$approver->isTrusted()) {
                throw new BchatPairingException('Perangkat approver belum berstatus tepercaya.', 'APPROVER_DEVICE_NOT_TRUSTED', 403);
            }

            if ((int) $approver->id === (int) $pairing->target_device_id) {
                throw new BchatPairingException('Perangkat tidak dapat menyetujui dirinya sendiri.', 'CANNOT_SELF_APPROVE', 400);
            }

            // 4. Validasi status sesi pairing
            if ($pairing->status === 'EXPIRED' || ($pairing->status === 'PENDING' && now()->isAfter($pairing->expires_at))) {
                if ($pairing->status === 'PENDING') {
                    $pairing->update(['status' => 'EXPIRED']);
                }
                throw new BchatPairingException('Sesi pairing telah kedaluwarsa.', 'PAIRING_EXPIRED', 410);
            }

            if ($pairing->status === 'APPROVED') {
                throw new BchatPairingException('Sesi pairing ini sudah pernah disetujui sebelumnya.', 'PAIRING_ALREADY_APPROVED', 409);
            }

            if ($pairing->status !== 'PENDING') {
                throw new BchatPairingException("Sesi pairing tidak dapat disetujui karena berstatus {$pairing->status}.", 'PAIRING_NOT_PENDING', 400);
            }

            // 4. Kunci dan validasi Target Device
            $target = BchatUserDevice::where('id', $pairing->target_device_id)
                ->lockForUpdate()
                ->first();

            if (!$target) {
                throw new BchatPairingException('Perangkat target tidak ditemukan.', 'TARGET_DEVICE_NOT_FOUND', 404);
            }

            if (!$target->isActive()) {
                throw new BchatPairingException('Perangkat target tidak aktif atau telah dicabut (REVOKED).', 'TARGET_DEVICE_NOT_ACTIVE', 403);
            }

            // 5. Verifikasi Tanda Tangan Kriptografis Persetujuan via BchatPairingVerificationService
            try {
                $this->verifier->verifyApproval(
                    $payloadBinary,
                    $signatureBinary,
                    $user,
                    $pairing,
                    $approver
                );
            } catch (Exception $e) {
                throw new BchatPairingException(
                    "Verifikasi kriptografis persetujuan pairing gagal: {$e->getMessage()}",
                    $e->getMessage(),
                    401,
                    $e
                );
            }

            // 6. Mutasi Status Atomik: Tandai Pairing APPROVED
            $now = now();
            $pairing->update([
                'status'               => 'APPROVED',
                'approver_device_id'   => $approver->id,
                'approver_device_uuid' => $approver->device_uuid,
                'approval_signature'   => $cleanSignature,
                'approval_payload'     => $cleanPayload,
                'approved_at'          => $now,
            ]);

            // 7. Mutasi Status Atomik: Tandai Perangkat Target Menjadi Tepercaya (TRUSTED)
            $target->update([
                'is_trusted'           => true,
                'trusted_at'           => $now,
                'trusted_by_device_id' => $approver->id,
            ]);

            return [
                'pairing_uuid'         => $pairing->pairing_uuid,
                'status'               => 'APPROVED',
                'target_device_uuid'   => $target->device_uuid,
                'approver_device_uuid' => $approver->device_uuid,
                'approved_at'          => $now->toIso8601String(),
            ];
        }, 3);
    }

    /**
     * Menolak permintaan pairing oleh approver device.
     */
    public function rejectPairing(
        User $user,
        string $pairingUuid,
        string $approverDeviceUuid,
        ?string $reason = null
    ): array {
        $cleanPairingUuid = strtolower(trim($pairingUuid));
        $cleanApproverUuid = strtolower(trim($approverDeviceUuid));

        // Periksa kedaluwarsa di luar transaksi penolakan agar status EXPIRED terpersist di basis data
        $preCheck = BchatDevicePairing::where('pairing_uuid', $cleanPairingUuid)
            ->where('user_id', $user->id)
            ->first();

        if ($preCheck && ($preCheck->status === 'EXPIRED' || ($preCheck->status === 'PENDING' && now()->isAfter($preCheck->expires_at)))) {
            if ($preCheck->status === 'PENDING') {
                $preCheck->update(['status' => 'EXPIRED']);
            }
            throw new BchatPairingException('Sesi pairing telah kedaluwarsa.', 'PAIRING_EXPIRED', 410);
        }

        return DB::transaction(function () use ($user, $cleanPairingUuid, $cleanApproverUuid, $reason) {
            User::where('id', $user->id)->lockForUpdate()->firstOrFail();

            $pairing = BchatDevicePairing::where('pairing_uuid', $cleanPairingUuid)
                ->where('user_id', $user->id)
                ->lockForUpdate()
                ->first();

            if (!$pairing) {
                throw new BchatPairingException('Sesi pairing tidak ditemukan.', 'PAIRING_NOT_FOUND', 404);
            }

            if ($pairing->status === 'EXPIRED' || ($pairing->status === 'PENDING' && now()->isAfter($pairing->expires_at))) {
                if ($pairing->status === 'PENDING') {
                    $pairing->update(['status' => 'EXPIRED']);
                }
                throw new BchatPairingException('Sesi pairing telah kedaluwarsa.', 'PAIRING_EXPIRED', 410);
            }

            if ($pairing->status !== 'PENDING') {
                throw new BchatPairingException("Sesi pairing sudah tidak pending ({$pairing->status}).", 'PAIRING_NOT_PENDING', 400);
            }

            $approver = BchatUserDevice::where('user_id', $user->id)
                ->where('device_uuid', $cleanApproverUuid)
                ->lockForUpdate()
                ->first();

            if (!$approver || !$approver->isActive()) {
                throw new BchatPairingException('Perangkat approver tidak aktif.', 'APPROVER_DEVICE_NOT_ACTIVE', 403);
            }

            if (!$approver->isTrusted()) {
                throw new BchatPairingException('Perangkat approver belum berstatus tepercaya.', 'APPROVER_DEVICE_NOT_TRUSTED', 403);
            }

            if ((int) $approver->id === (int) $pairing->target_device_id) {
                throw new BchatPairingException('Perangkat tidak dapat menolak dirinya sendiri.', 'CANNOT_SELF_APPROVE', 400);
            }

            $now = now();
            $pairing->update([
                'status'               => 'REJECTED',
                'approver_device_id'   => $approver->id,
                'approver_device_uuid' => $approver->device_uuid,
                'rejected_at'          => $now,
                'rejection_reason'     => $reason ?: 'Ditolak oleh pengguna',
            ]);

            return [
                'pairing_uuid' => $pairing->pairing_uuid,
                'status'       => 'REJECTED',
                'rejected_at'  => $now->toIso8601String(),
            ];
        });
    }

    /**
     * Membatalkan permintaan pairing oleh perangkat peminta (target device).
     */
    public function cancelPairing(User $user, string $pairingUuid, string $targetDeviceUuid): array
    {
        $cleanPairingUuid = strtolower(trim($pairingUuid));
        $cleanTargetUuid = strtolower(trim($targetDeviceUuid));

        // Periksa kedaluwarsa di luar transaksi pembatalan agar status EXPIRED terpersist di basis data
        $preCheck = BchatDevicePairing::where('pairing_uuid', $cleanPairingUuid)
            ->where('user_id', $user->id)
            ->first();

        if ($preCheck && ($preCheck->status === 'EXPIRED' || ($preCheck->status === 'PENDING' && now()->isAfter($preCheck->expires_at)))) {
            if ($preCheck->status === 'PENDING') {
                $preCheck->update(['status' => 'EXPIRED']);
            }
            throw new BchatPairingException('Sesi pairing telah kedaluwarsa.', 'PAIRING_EXPIRED', 410);
        }

        return DB::transaction(function () use ($user, $cleanPairingUuid, $cleanTargetUuid) {
            User::where('id', $user->id)->lockForUpdate()->firstOrFail();

            $pairing = BchatDevicePairing::where('pairing_uuid', $cleanPairingUuid)
                ->where('user_id', $user->id)
                ->lockForUpdate()
                ->first();

            if (!$pairing) {
                throw new BchatPairingException('Sesi pairing tidak ditemukan.', 'PAIRING_NOT_FOUND', 404);
            }

            if ($pairing->status === 'EXPIRED' || ($pairing->status === 'PENDING' && now()->isAfter($pairing->expires_at))) {
                if ($pairing->status === 'PENDING') {
                    $pairing->update(['status' => 'EXPIRED']);
                }
                throw new BchatPairingException('Sesi pairing telah kedaluwarsa.', 'PAIRING_EXPIRED', 410);
            }

            if ($pairing->status !== 'PENDING') {
                throw new BchatPairingException("Sesi pairing sudah tidak pending ({$pairing->status}).", 'PAIRING_NOT_PENDING', 400);
            }

            if ($pairing->target_device_uuid !== $cleanTargetUuid) {
                throw new BchatPairingException('Hanya perangkat peminta yang dapat membatalkan sesi ini.', 'UNAUTHORIZED_CANCELLATION', 403);
            }

            $pairing->update([
                'status'     => 'CANCELED',
                'updated_at' => now(),
            ]);

            return [
                'pairing_uuid' => $pairing->pairing_uuid,
                'status'       => 'CANCELED',
            ];
        });
    }
}

