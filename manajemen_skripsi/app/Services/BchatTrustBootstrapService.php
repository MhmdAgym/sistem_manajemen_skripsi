<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\BchatTrustBootstrapException;
use App\Models\BchatTrustBootstrapChallenge;
use App\Models\BchatUserDevice;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class BchatTrustBootstrapService
{
    public const CHALLENGE_TTL_MINUTES = 5;
    public const PURPOSE_TRUST_BOOTSTRAP = 'TRUST_BOOTSTRAP';

    /**
     * Memeriksa apakah akun dan perangkat memenuhi syarat untuk bootstrap trust root pertama.
     *
     * Syarat wajib:
     * 1. Perangkat milik pengguna dan berstatus ACTIVE.
     * 2. Perangkat belum berstatus tepercaya (is_trusted = false).
     * 3. Pengguna TIDAK memiliki perangkat aktif lain yang sudah tepercaya.
     * 4. Pengguna BELUM PERNAH memiliki perangkat tepercaya dalam sejarahnya (Fail-Closed).
     */
    public function checkEligibility(User $user, string $deviceUuid): array
    {
        $cleanUuid = strtolower(trim($deviceUuid));
        if (!Str::isUuid($cleanUuid)) {
            return [
                'is_eligible' => false,
                'reason'      => 'Format Device UUID tidak valid.',
                'error_code'  => 'INVALID_DEVICE_UUID',
                'device'      => null,
            ];
        }

        $device = BchatUserDevice::where('user_id', $user->id)
            ->where('device_uuid', $cleanUuid)
            ->first();

        if (!$device) {
            return [
                'is_eligible' => false,
                'reason'      => 'Perangkat tidak ditemukan pada akun ini.',
                'error_code'  => 'DEVICE_NOT_FOUND',
                'device'      => null,
            ];
        }

        if ($device->status !== 'ACTIVE') {
            return [
                'is_eligible' => false,
                'reason'      => "Perangkat berstatus {$device->status} dan tidak dapat melakukan bootstrap.",
                'error_code'  => 'DEVICE_NOT_ACTIVE',
                'device'      => $device,
            ];
        }

        if ((bool) $device->is_trusted) {
            return [
                'is_eligible' => false,
                'reason'      => 'Perangkat sudah berstatus tepercaya.',
                'error_code'  => 'DEVICE_ALREADY_TRUSTED',
                'device'      => $device,
            ];
        }

        // Cek apakah ada perangkat aktif lain yang sudah tepercaya
        $hasActiveTrusted = BchatUserDevice::where('user_id', $user->id)
            ->where('status', 'ACTIVE')
            ->where('is_trusted', true)
            ->exists();

        if ($hasActiveTrusted) {
            return [
                'is_eligible' => false,
                'reason'      => 'Akun sudah memiliki perangkat aktif tepercaya. Gunakan alur Trusted Device Pairing.',
                'error_code'  => 'ACCOUNT_ALREADY_HAS_TRUSTED_DEVICE',
                'device'      => $device,
            ];
        }

        // Cek apakah akun pernah memiliki trust root sah sebelumnya (mencegah bootstrap ulang jika perangkat pernah revoke)
        $hasEverHadTrusted = BchatUserDevice::where('user_id', $user->id)
            ->where('is_trusted', true)
            ->exists();

        if ($hasEverHadTrusted) {
            return [
                'is_eligible' => false,
                'reason'      => 'Akun sudah pernah memiliki perangkat tepercaya sebelumnya. Bootstrap trust root pertama tidak diizinkan.',
                'error_code'  => 'ACCOUNT_PREVIOUSLY_HAD_TRUST_ROOT',
                'device'      => $device,
            ];
        }

        return [
            'is_eligible' => true,
            'reason'      => null,
            'error_code'  => null,
            'device'      => $device,
        ];
    }

    /**
     * Menerbitkan challenge bootstrap sekali pakai setelah step-up authentication password berhasil.
     */
    public function issueChallenge(
        User $user,
        string $deviceUuid,
        string $password,
        ?string $ipAddress = null,
        ?string $userAgent = null
    ): array {
        // 1. Step-Up Authentication: verifikasi password akun
        if (!Hash::check($password, $user->password)) {
            throw new BchatTrustBootstrapException(
                'Password re-autentikasi tidak valid.',
                'INVALID_CREDENTIALS',
                401
            );
        }

        // 2. Periksa kelayakan bootstrap
        $eligibility = $this->checkEligibility($user, $deviceUuid);
        if (!$eligibility['is_eligible']) {
            throw new BchatTrustBootstrapException(
                $eligibility['reason'] ?? 'Perangkat tidak memenuhi syarat untuk bootstrap trust root.',
                $eligibility['error_code'] ?? 'BOOTSTRAP_NOT_ELIGIBLE',
                400
            );
        }

        /** @var BchatUserDevice $device */
        $device = $eligibility['device'];

        return DB::transaction(function () use ($user, $device, $ipAddress, $userAgent) {
            // Batalkan challenge ISSUED sebelumnya untuk perangkat ini
            BchatTrustBootstrapChallenge::where('user_id', $user->id)
                ->where('device_id', $device->id)
                ->where('status', 'ISSUED')
                ->update(['status' => 'EXPIRED', 'updated_at' => now()]);

            $challengeUuid = (string) Str::uuid();
            $challengeNonce = bin2hex(random_bytes(32)); // 64 hex chars (256-bit CSPRNG)
            $expiresAt = now()->addMinutes(self::CHALLENGE_TTL_MINUTES);

            // Kanonikal payload yang wajib ditandatangani Ed25519 oleh private key lokal
            $payloadToSign = self::buildPayloadToSign($challengeNonce, $device->device_uuid, $device->public_key_fingerprint);

            $challenge = BchatTrustBootstrapChallenge::create([
                'user_id'                => $user->id,
                'device_id'              => $device->id,
                'challenge_uuid'         => $challengeUuid,
                'device_uuid'            => $device->device_uuid,
                'public_key_fingerprint' => $device->public_key_fingerprint,
                'challenge_nonce'        => $challengeNonce,
                'purpose'                => self::PURPOSE_TRUST_BOOTSTRAP,
                'status'                 => 'ISSUED',
                'expires_at'             => $expiresAt,
                'consumed_at'            => null,
                'bootstrap_signature'    => null,
                'bootstrap_payload'      => null,
                'ip_address'             => $ipAddress,
                'user_agent'             => $userAgent,
            ]);

            return [
                'challenge_uuid'  => $challenge->challenge_uuid,
                'device_uuid'     => $device->device_uuid,
                'purpose'         => self::PURPOSE_TRUST_BOOTSTRAP,
                'challenge_nonce' => $challenge->challenge_nonce,
                'payload_to_sign' => $payloadToSign,
                'expires_at'      => $challenge->expires_at->toIso8601String(),
            ];
        }, 3);
    }

    /**
     * Memverifikasi tanda tangan Ed25519 dan menetapkan perangkat sebagai trust root pertama akun.
     */
    public function verifyAndBootstrap(
        User $user,
        string $deviceUuid,
        string $challengeUuid,
        string $signature,
        ?string $ipAddress = null,
        ?string $userAgent = null
    ): array {
        $cleanDeviceUuid = strtolower(trim($deviceUuid));
        $cleanChallengeUuid = strtolower(trim($challengeUuid));
        $cleanSignature = strtolower(trim($signature));

        if (!Str::isUuid($cleanDeviceUuid)) {
            throw new BchatTrustBootstrapException('Format Device UUID tidak valid.', 'INVALID_DEVICE_UUID', 422);
        }
        if (!Str::isUuid($cleanChallengeUuid)) {
            throw new BchatTrustBootstrapException('Format Challenge UUID tidak valid.', 'INVALID_CHALLENGE_UUID', 422);
        }
        if (strlen($cleanSignature) !== 128 || !ctype_xdigit($cleanSignature)) {
            throw new BchatTrustBootstrapException(
                'Format signature Ed25519 tidak valid (harus 128 karakter heksadesimal).',
                'INVALID_SIGNATURE_FORMAT',
                422
            );
        }

        try {
            return DB::transaction(function () use ($user, $cleanDeviceUuid, $cleanChallengeUuid, $cleanSignature, $ipAddress, $userAgent) {
                // 1. Lock level 1: users (Mutual Exclusion Gate)
                User::where('id', $user->id)->lockForUpdate()->firstOrFail();

                // 2. Lock level 2: devices
                $device = BchatUserDevice::where('user_id', $user->id)
                    ->where('device_uuid', $cleanDeviceUuid)
                    ->lockForUpdate()
                    ->first();

                if (!$device) {
                    throw new BchatTrustBootstrapException('Perangkat tidak ditemukan pada akun ini.', 'DEVICE_NOT_FOUND', 404);
                }

                if ($device->status !== 'ACTIVE') {
                    throw new BchatTrustBootstrapException(
                        "Perangkat berstatus {$device->status} dan tidak dapat melakukan bootstrap.",
                        'DEVICE_NOT_ACTIVE',
                        400
                    );
                }

                if ((bool) $device->is_trusted) {
                    throw new BchatTrustBootstrapException('Perangkat sudah berstatus tepercaya.', 'DEVICE_ALREADY_TRUSTED', 400);
                }

                // Re-validasi eksklusivitas akun di bawah lock (mencegah race condition 2 request bersamaan)
                $hasActiveTrusted = BchatUserDevice::where('user_id', $user->id)
                    ->where('status', 'ACTIVE')
                    ->where('is_trusted', true)
                    ->exists();

                if ($hasActiveTrusted) {
                    throw new BchatTrustBootstrapException(
                        'Akun sudah memiliki perangkat aktif tepercaya. Bootstrap tidak diizinkan.',
                        'ACCOUNT_ALREADY_HAS_TRUSTED_DEVICE',
                        409
                    );
                }

                $hasEverHadTrusted = BchatUserDevice::where('user_id', $user->id)
                    ->where('is_trusted', true)
                    ->exists();

                if ($hasEverHadTrusted) {
                    throw new BchatTrustBootstrapException(
                        'Akun sudah pernah memiliki perangkat tepercaya. Bootstrap trust root pertama tidak diizinkan.',
                        'ACCOUNT_PREVIOUSLY_HAD_TRUST_ROOT',
                        409
                    );
                }

                // 3. Lock challenge
                $challenge = BchatTrustBootstrapChallenge::where('challenge_uuid', $cleanChallengeUuid)
                    ->where('device_id', $device->id)
                    ->where('user_id', $user->id)
                    ->lockForUpdate()
                    ->first();

                if (!$challenge) {
                    throw new BchatTrustBootstrapException('Challenge bootstrap tidak ditemukan.', 'CHALLENGE_NOT_FOUND', 404);
                }

                if ($challenge->status === 'CONSUMED') {
                    throw new BchatTrustBootstrapException('Challenge telah digunakan sebelumnya.', 'CHALLENGE_ALREADY_CONSUMED', 409);
                }

                if ($challenge->status !== 'ISSUED') {
                    throw new BchatTrustBootstrapException("Challenge berstatus {$challenge->status} dan tidak valid.", 'CHALLENGE_NOT_ACTIVE', 400);
                }

                if ($challenge->purpose !== self::PURPOSE_TRUST_BOOTSTRAP) {
                    throw new BchatTrustBootstrapException('Tujuan challenge tidak sesuai untuk Trust Bootstrap.', 'INVALID_CHALLENGE_PURPOSE', 400);
                }

                if ($challenge->isExpired()) {
                    $challenge->update(['status' => 'EXPIRED', 'updated_at' => now()]);
                    throw new BchatTrustBootstrapException('Challenge telah kedaluwarsa.', 'CHALLENGE_EXPIRED', 410);
                }

                // 4. Verifikasi Tanda Tangan Ed25519
                $expectedPayload = self::buildPayloadToSign(
                    $challenge->challenge_nonce,
                    $device->device_uuid,
                    $device->public_key_fingerprint
                );

                $sigBinary = hex2bin($cleanSignature);
                $pubKeyBinary = hex2bin($device->public_key);

                if ($sigBinary === false || strlen($sigBinary) !== 64 || $pubKeyBinary === false || strlen($pubKeyBinary) !== 32) {
                    throw new BchatTrustBootstrapException('Material kunci atau tanda tangan tidak valid.', 'INVALID_CRYPTO_MATERIAL', 422);
                }

                if (!function_exists('sodium_crypto_sign_verify_detached')) {
                    throw new BchatTrustBootstrapException('Ekstensi kriptografi sodium tidak tersedia di server.', 'SODIUM_NOT_AVAILABLE', 500);
                }

                $isSignatureValid = sodium_crypto_sign_verify_detached($sigBinary, $expectedPayload, $pubKeyBinary);

                if (!$isSignatureValid) {
                    // Tandai challenge expired agar tidak dapat di-bruteforce
                    $challenge->update(['status' => 'EXPIRED', 'updated_at' => now()]);

                    Log::warning('BChat Trust Bootstrap Verification FAILED: Invalid Ed25519 signature', [
                        'user_id'     => $user->id,
                        'device_id'   => $device->id,
                        'device_uuid' => $device->device_uuid,
                        'fingerprint' => $device->public_key_fingerprint,
                        'ip'          => $ipAddress,
                    ]);

                    throw new BchatTrustBootstrapException(
                        'Tanda tangan kriptografis Ed25519 tidak cocok dengan kunci publik terdaftar perangkat.',
                        'INVALID_SIGNATURE',
                        422
                    );
                }

                // 5. Penetapan Status Tepercaya (Atomik)
                $now = now();
                $challenge->update([
                    'status'              => 'CONSUMED',
                    'consumed_at'         => $now,
                    'bootstrap_signature' => $cleanSignature,
                    'bootstrap_payload'   => $expectedPayload,
                    'ip_address'          => $ipAddress,
                    'user_agent'          => $userAgent,
                    'updated_at'          => $now,
                ]);

                $device->update([
                    'is_trusted'           => true,
                    'trusted_at'           => $now,
                    'trusted_by_device_id' => null, // Trust root perdana
                    'updated_at'           => $now,
                ]);

                // 6. Audit Logging Aman (Tanpa kunci privat, password, atau token rahasia)
                Log::info('BChat Trust Bootstrap SUCCESS: Perangkat berhasil menjadi Trust Root perdana', [
                    'user_id'          => $user->id,
                    'device_id'        => $device->id,
                    'device_uuid'      => $device->device_uuid,
                    'fingerprint'      => $device->public_key_fingerprint,
                    'trusted_at'       => $now->toIso8601String(),
                    'is_root_of_trust' => true,
                    'ip'               => $ipAddress,
                ]);

                return [
                    'device_id'        => $device->id,
                    'device_uuid'      => $device->device_uuid,
                    'is_trusted'       => true,
                    'trusted_at'       => $now->toIso8601String(),
                    'is_root_of_trust' => true,
                ];
            }, 3);
        } catch (QueryException $e) {
            Log::error('BChat Trust Bootstrap DB Error: ' . $e->getMessage());
            throw new BchatTrustBootstrapException('Terjadi kesalahan basis data saat memproses bootstrap trust.', 'DB_ERROR', 500, $e);
        }
    }

    /**
     * Format kanonikal payload yang wajib ditandatangani klien.
     */
    public static function buildPayloadToSign(string $challengeNonce, string $deviceUuid, string $fingerprint): string
    {
        return sprintf(
            '%s:%s:%s:%s',
            self::PURPOSE_TRUST_BOOTSTRAP,
            strtolower(trim($challengeNonce)),
            strtolower(trim($deviceUuid)),
            strtolower(trim($fingerprint))
        );
    }
}

