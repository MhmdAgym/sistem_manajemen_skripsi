<?php

namespace App\Services;

use App\Exceptions\BchatDeviceException;
use App\Models\BchatKeyPackage;
use App\Models\BchatRegistrationNonce;
use App\Models\BchatRoom;
use App\Models\BchatStepUpToken;
use App\Models\BchatUserDevice;
use App\Models\BchatWelcomeChallenge;
use App\Models\User;
use App\Services\BchatPopVerificationService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Throwable;

class BchatDeviceService
{
    /**
     * Cakupan aksi baku untuk otorisasi step-up registrasi perangkat.
     */
    public const ACTION_SCOPE_REGISTRATION = 'DEVICE_REGISTRATION';

    protected BchatPopVerificationService $popVerificationService;

    public function __construct(?BchatPopVerificationService $popVerificationService = null)
    {
        $this->popVerificationService = $popVerificationService ?? app(BchatPopVerificationService::class);
    }

    /**
     * Melakukan step-up password re-authentication untuk otorisasi registrasi perangkat.
     *
     * @param User $user Pengguna yang terotentikasi via Sanctum
     * @param string $password Password plaintext akun
     * @param string $deviceUuid UUID v4 perangkat target
     * @param string|null $ipAddress Alamat IP klien
     * @param string|null $userAgent User-Agent peramban
     * @return array<string, mixed> Data otorisasi step-up yang memuat plaintext token sekali pakai
     *
     * @throws BchatDeviceException Jika password salah atau format input tidak valid
     */
    public function authenticateStepUp(
        User $user,
        string $password,
        string $deviceUuid,
        ?string $ipAddress = null,
        ?string $userAgent = null
    ): array {
        // 1. Verifikasi kecocokan password akun menggunakan Hash::check
        if (!Hash::check($password, $user->password)) {
            throw new BchatDeviceException(
                'Kredensial re-autentikasi tidak valid.',
                'INVALID_CREDENTIALS',
                401
            );
        }

        $cleanDeviceUuid = strtolower(trim($deviceUuid));
        if (!Str::isUuid($cleanDeviceUuid)) {
            throw new BchatDeviceException(
                'Format Device UUID harus berupa UUID v4 yang valid.',
                'INVALID_DEVICE_UUID',
                422
            );
        }

        // 2. Buat token acak CSPRNG 256-bit (32 byte)
        $plainTokenBytes = random_bytes(32);
        $plainTokenHex = bin2hex($plainTokenBytes); // 64 karakter lowercase hex

        // 3. Simpan HANYA hash SHA-256 dari token ke basis data
        $tokenHash = hash('sha256', $plainTokenHex);
        $expiresAt = now()->addMinutes(BchatStepUpToken::TTL_MINUTES);

        BchatStepUpToken::create([
            'user_id'      => $user->id,
            'device_uuid'  => $cleanDeviceUuid,
            'token_hash'   => $tokenHash,
            'action_scope' => self::ACTION_SCOPE_REGISTRATION,
            'is_consumed'  => false,
            'expires_at'   => $expiresAt,
            'consumed_at'  => null,
            'ip_address'   => $ipAddress,
            'user_agent'   => $userAgent,
        ]);

        // 4. Kembalikan plaintext token kepada klien (tanpa pernah menyimpan plaintext di database/log)
        return [
            'user_id'       => (string) $user->id,
            'step_up_token' => $plainTokenHex,
            'device_uuid'   => $cleanDeviceUuid,
            'action_scope'  => self::ACTION_SCOPE_REGISTRATION,
            'expires_at'    => $expiresAt->toIso8601String(),
        ];
    }

    /**
     * Menerbitkan registration nonce baru dengan mengonsumsi step-up token secara atomik.
     * Mengunci baris sesuai hierarki global (users -> bchat_step_up_tokens -> bchat_registration_nonces)
     * dan meng-expire seluruh nonce lama yang masih berstatus ISSUED milik user.
     *
     * @param User $user Pengguna yang terotentikasi via Sanctum
     * @param string $stepUpTokenPlain Plaintext step-up token dari klien (64 hex)
     * @param string $deviceUuid UUID v4 perangkat target
     * @param string $publicKeyHex Public key Ed25519 (64 hex)
     * @return array<string, mixed> Data challenge registration nonce kanonis
     *
     * @throws BchatDeviceException Jika token tidak valid, kadaluwarsa, tidak cocok, atau terjadi konflik
     */
    public function issueRegistrationNonce(
        User $user,
        string $stepUpTokenPlain,
        string $deviceUuid,
        string $publicKeyHex
    ): array {
        $cleanDeviceUuid = strtolower(trim($deviceUuid));
        if (!Str::isUuid($cleanDeviceUuid)) {
            throw new BchatDeviceException(
                'Format Device UUID harus berupa UUID v4 yang valid.',
                'INVALID_DEVICE_UUID',
                422
            );
        }

        $cleanPubKey = strtolower(trim($publicKeyHex));
        if (strlen($cleanPubKey) !== 64 || !ctype_xdigit($cleanPubKey)) {
            throw new BchatDeviceException(
                'Format public key harus berupa 64 karakter heksadesimal (32 byte Ed25519).',
                'INVALID_PUBLIC_KEY',
                422
            );
        }

        $cleanStepUpToken = strtolower(trim($stepUpTokenPlain));
        if (strlen($cleanStepUpToken) !== 64 || !ctype_xdigit($cleanStepUpToken)) {
            throw new BchatDeviceException(
                'Format step-up token harus berupa 64 karakter heksadesimal.',
                'INVALID_STEP_UP_TOKEN',
                422
            );
        }

        // Hitung SHA-256 fingerprint dari 32 byte biner public key
        $pubKeyBinary = hex2bin($cleanPubKey);
        if ($pubKeyBinary === false || strlen($pubKeyBinary) !== 32) {
            throw new BchatDeviceException(
                'Public key tidak dapat didekode menjadi buffer biner 32 byte.',
                'INVALID_PUBLIC_KEY',
                422
            );
        }
        $publicKeyFingerprint = hash('sha256', $pubKeyBinary);

        // Hitung hash token untuk pencarian aman di database
        $tokenHash = hash('sha256', $cleanStepUpToken);

        try {
            return DB::transaction(function () use ($user, $tokenHash, $cleanDeviceUuid, $publicKeyFingerprint) {
                // 1. Kunci Level 1: users (Mencegah balapan antar-transaksi untuk pengguna yang sama)
                User::where('id', $user->id)->lockForUpdate()->firstOrFail();

                // 2. Kunci Step-Up Token untuk verifikasi dan konsumsi atomik
                $tokenRecord = BchatStepUpToken::where('token_hash', $tokenHash)
                    ->lockForUpdate()
                    ->first();

                if (!$tokenRecord) {
                    throw new BchatDeviceException(
                        'Step-up token tidak ditemukan atau tidak valid.',
                        'INVALID_STEP_UP_TOKEN',
                        403
                    );
                }

                // Timing-attack resistant hash verification
                if (!hash_equals($tokenRecord->token_hash, $tokenHash)) {
                    throw new BchatDeviceException(
                        'Step-up token tidak cocok.',
                        'INVALID_STEP_UP_TOKEN',
                        403
                    );
                }

                // Validasi binding pengguna
                if ((int) $tokenRecord->user_id !== (int) $user->id) {
                    throw new BchatDeviceException(
                        'Step-up token tidak terikat pada pengguna yang sedang login.',
                        'STEP_UP_USER_MISMATCH',
                        403
                    );
                }

                // Validasi binding target device_uuid
                if (strtolower($tokenRecord->device_uuid) !== $cleanDeviceUuid) {
                    throw new BchatDeviceException(
                        'Device UUID tidak sesuai dengan target otorisasi step-up token.',
                        'STEP_UP_DEVICE_MISMATCH',
                        403
                    );
                }

                // Validasi action scope
                if ($tokenRecord->action_scope !== self::ACTION_SCOPE_REGISTRATION) {
                    throw new BchatDeviceException(
                        'Cakupan aksi step-up token tidak sesuai untuk registrasi perangkat.',
                        'STEP_UP_SCOPE_MISMATCH',
                        403
                    );
                }

                // Validasi status konsumsi
                if ($tokenRecord->is_consumed) {
                    throw new BchatDeviceException(
                        'Step-up token sudah pernah dikonsumsi sebelumnya.',
                        'STEP_UP_TOKEN_ALREADY_CONSUMED',
                        403
                    );
                }

                // Validasi tenggat waktu (TTL 5 menit)
                if ($tokenRecord->expires_at === null || $tokenRecord->expires_at->isPast()) {
                    throw new BchatDeviceException(
                        'Step-up token telah kedaluwarsa.',
                        'STEP_UP_TOKEN_EXPIRED',
                        403
                    );
                }

                // 3. Konsumsi token secara atomik tepat satu kali
                $tokenRecord->update([
                    'is_consumed' => true,
                    'consumed_at' => now(),
                ]);

                // 4. Invalidasi seluruh registration nonce lama berstatus ISSUED milik pengguna ini
                // Mengunci baris nonce lama secara terurut (ORDER BY id ASC) untuk mencegah deadlock intra-table
                $oldNonces = BchatRegistrationNonce::where('user_id', $user->id)
                    ->where('status', 'ISSUED')
                    ->orderBy('id', 'asc')
                    ->lockForUpdate()
                    ->get();

                foreach ($oldNonces as $oldNonce) {
                    $oldNonce->update([
                        'status' => 'EXPIRED',
                    ]);
                }

                // 5. Terbitkan registration nonce baru dengan entropi 32 byte
                $nonceBytes = random_bytes(32);
                $nonceHex = bin2hex($nonceBytes); // 64 hex characters
                $nonceUuid = (string) Str::uuid();
                $serverIssuedAt = time(); // Epoch uint64 kanonis jam server
                $nonceExpiresAt = now()->addMinutes(5);

                BchatRegistrationNonce::create([
                    'user_id'                => $user->id,
                    'device_uuid'            => $cleanDeviceUuid,
                    'nonce_uuid'             => $nonceUuid,
                    'nonce_value'            => $nonceHex,
                    'public_key_fingerprint' => $publicKeyFingerprint,
                    'status'                 => 'ISSUED',
                    'issued_at'              => $serverIssuedAt,
                    'expires_at'             => $nonceExpiresAt,
                    'consumed_at'            => null,
                ]);

                return [
                    'nonce_uuid'             => $nonceUuid,
                    'registration_nonce'     => $nonceHex,
                    'device_uuid'            => $cleanDeviceUuid,
                    'public_key_fingerprint' => $publicKeyFingerprint,
                    'issued_at'              => $serverIssuedAt,
                    'expires_at'             => $nonceExpiresAt->toIso8601String(),
                ];
            }, 3);
        } catch (BchatDeviceException $e) {
            // Rethrow exception domain B-CHAT
            throw $e;
        } catch (QueryException $e) {
            // Tangkap deadlock (1213) atau lock timeout (1205) MySQL
            $sqlState = $e->getCode();
            $errorCode = $e->errorInfo[1] ?? null;

            if ($sqlState === '40001' || $errorCode === 1213 || $errorCode === 1205) {
                throw new BchatDeviceException(
                    'Operasi mengalami konflik konkurensi database. Silakan coba kembali sesaat lagi.',
                    'CONCURRENCY_LOCK_CONFLICT',
                    409,
                    $e
                );
            }

            throw new BchatDeviceException(
                'Terjadi kesalahan saat memproses data registrasi di basis data.',
                'DATABASE_ERROR',
                500,
                $e
            );
        } catch (Throwable $e) {
            throw new BchatDeviceException(
                'Terjadi kesalahan internal yang tidak terduga.',
                'INTERNAL_ERROR',
                500,
                $e
            );
        }
    }

    /**
     * Mendaftarkan perangkat baru klien (Dedicated ACK Identity Ed25519) melalui verifikasi PoP,
     * serta mengeksekusi Managed Replacement secara atomik jika pengguna telah memiliki perangkat aktif.
     *
     * Replacement Protection (v2):
     * - Jika perangkat aktif sudah ada, parameter $confirmReplacement wajib true.
     * - $expectedActiveDeviceUuid harus cocok dengan perangkat ACTIVE aktual di backend.
     * - Jika konfirmasi tidak diberikan atau tidak cocok, registrasi ditolak tanpa mencabut perangkat lama.
     *
     * @param User        $user                     Pengguna terautentikasi via Sanctum
     * @param string      $nonceUuid                UUID v4 unik sesi challenge registrasi
     * @param string      $payloadInput             Buffer payload PoP 121-byte (biner murni atau 242-char hex)
     * @param string      $signatureInput           Buffer detached signature 64-byte Ed25519 (biner murni atau 128-char hex)
     * @param string      $deviceName               Nama/label peramban atau perangkat klien
     * @param bool        $confirmReplacement       Konfirmasi eksplisit pengguna untuk Managed Replacement
     * @param string|null $expectedActiveDeviceUuid UUID perangkat aktif yang diharapkan untuk diganti
     * @return array<string, mixed> Metadata konfirmasi registrasi perangkat
     *
     * @throws BchatDeviceException Jika validasi nonce, PoP, konfirmasi replacement, atau terjadi konflik
     */
    public function registerDevice(
        User $user,
        string $nonceUuid,
        string $payloadInput,
        string $signatureInput,
        string $deviceName = 'Browser Device',
        bool $confirmReplacement = false,
        ?string $expectedActiveDeviceUuid = null
    ): array {
        // 1. Sanitasi dan validasi format dasar parameter input
        $cleanNonceUuid = strtolower(trim($nonceUuid));
        if (!Str::isUuid($cleanNonceUuid)) {
            throw new BchatDeviceException(
                'Format Nonce UUID harus berupa UUID v4 yang valid.',
                'INVALID_NONCE_UUID',
                422
            );
        }

        // Konversi dan validasi payload PoP (121 byte)
        $payloadBinary = (strlen($payloadInput) === 242 && ctype_xdigit($payloadInput))
            ? hex2bin($payloadInput)
            : $payloadInput;

        if ($payloadBinary === false || strlen($payloadBinary) !== BchatPopVerificationService::PAYLOAD_LENGTH) {
            throw new BchatDeviceException(
                'Panjang payload PoP tidak valid (harus tepat 121 byte).',
                'INVALID_POP_PAYLOAD_LENGTH',
                422
            );
        }

        // Konversi dan validasi detached signature Ed25519 (64 byte)
        $signatureBinary = (strlen($signatureInput) === 128 && ctype_xdigit($signatureInput))
            ? hex2bin($signatureInput)
            : $signatureInput;

        if ($signatureBinary === false || strlen($signatureBinary) !== BchatPopVerificationService::SIGNATURE_LENGTH) {
            throw new BchatDeviceException(
                'Panjang signature PoP tidak valid (harus tepat 64 byte).',
                'INVALID_POP_SIGNATURE_LENGTH',
                422
            );
        }

        $cleanDeviceName = trim($deviceName);
        if ($cleanDeviceName === '') {
            $cleanDeviceName = 'Browser Device';
        }
        if (mb_strlen($cleanDeviceName) > 100) {
            $cleanDeviceName = mb_substr($cleanDeviceName, 0, 100);
        }

        try {
            return DB::transaction(function () use (
                $user,
                $cleanNonceUuid,
                $payloadBinary,
                $signatureBinary,
                $cleanDeviceName,
                $confirmReplacement,
                $expectedActiveDeviceUuid
            ) {
                // -------------------------------------------------------------
                // 1. PENGUNCIAN LEVEL 1: users (Mutual Exclusion Gate)
                // -------------------------------------------------------------
                User::where('id', $user->id)->lockForUpdate()->firstOrFail();

                // -------------------------------------------------------------
                // 2. KUNCI & VALIDASI REGISTRATION NONCE
                // -------------------------------------------------------------
                $nonceRecord = BchatRegistrationNonce::where('nonce_uuid', $cleanNonceUuid)
                    ->lockForUpdate()
                    ->first();

                if (!$nonceRecord) {
                    throw new BchatDeviceException(
                        'Registration nonce tidak ditemukan.',
                        'REGISTRATION_NONCE_NOT_FOUND',
                        404
                    );
                }

                // Validasi binding kepemilikan user
                if ((int) $nonceRecord->user_id !== (int) $user->id) {
                    throw new BchatDeviceException(
                        'Registration nonce tidak terikat pada pengguna yang sedang login.',
                        'NONCE_USER_MISMATCH',
                        403
                    );
                }

                // Validasi status single-use nonce
                if ($nonceRecord->status === 'CONSUMED') {
                    throw new BchatDeviceException(
                        'Registration nonce sudah pernah dikonsumsi.',
                        'NONCE_ALREADY_CONSUMED',
                        403
                    );
                }

                if ($nonceRecord->status === 'EXPIRED' || $nonceRecord->isExpired()) {
                    throw new BchatDeviceException(
                        'Registration nonce telah kedaluwarsa.',
                        'NONCE_EXPIRED',
                        403
                    );
                }

                if ($nonceRecord->status !== 'ISSUED') {
                    throw new BchatDeviceException(
                        'Status registration nonce tidak valid untuk registrasi.',
                        'NONCE_STATUS_INVALID',
                        403
                    );
                }

                // -------------------------------------------------------------
                // 3. VERIFIKASI KRIPTOGRAFIS POP ED25519 (121-BYTE)
                // -------------------------------------------------------------
                $popResult = $this->popVerificationService->verify(
                    payloadBinary: $payloadBinary,
                    signatureBinary: $signatureBinary,
                    expectedUserId: $user->id,
                    expectedDeviceUuid: $nonceRecord->device_uuid,
                    expectedNonceHex: $nonceRecord->nonce_value,
                    expectedIssuedAt: $nonceRecord->issued_at,
                    expectedFingerprint: $nonceRecord->public_key_fingerprint
                );

                if (!$popResult->isValid()) {
                    $popErrorCode = $popResult->getErrorCode() ?? 'POP_VERIFICATION_FAILED';
                    throw new BchatDeviceException(
                        'Verifikasi Registration Proof-of-Possession gagal: ' . $popErrorCode,
                        $this->mapPopErrorCode($popErrorCode),
                        $this->mapPopErrorStatus($popErrorCode)
                    );
                }

                $parsedPoP = $popResult->getParsedData();
                $targetDeviceUuid = $parsedPoP['device_uuid'];
                $targetPublicKeyHex = $parsedPoP['public_key_hex'];
                $targetFingerprint = $parsedPoP['public_key_fingerprint'];

                // Cek apakah public key sudah terdaftar pada perangkat lain
                $existingKeyDevice = BchatUserDevice::where('public_key', $targetPublicKeyHex)->first();
                if ($existingKeyDevice) {
                    throw new BchatDeviceException(
                        'Public key Ed25519 sudah terdaftar pada perangkat lain.',
                        'PUBLIC_KEY_ALREADY_IN_USE',
                        409
                    );
                }

                // -------------------------------------------------------------
                // 4. DISCOVERY PERANGKAT AKTIF LAMA & ENTITAS TERDAMPAK
                //    (Unlocked read sebelum penguncian hierarki tabel)
                // -------------------------------------------------------------
                $activeDevice = BchatUserDevice::where('user_id', $user->id)
                    ->where('status', 'ACTIVE')
                    ->first();

                // -------------------------------------------------------------
                // 4B. REPLACEMENT PROTECTION (v2)
                //     Jika pengguna sudah memiliki perangkat ACTIVE, konfirmasi
                //     eksplisit wajib diberikan beserta UUID perangkat yang akan
                //     diganti. Validasi ini dilakukan di dalam transaksi dengan
                //     data yang sudah ter-lock agar tidak rentan race condition.
                // -------------------------------------------------------------
                if ($activeDevice) {
                    // Tolak jika konfirmasi replacement tidak diberikan atau false
                    if (!$confirmReplacement) {
                        throw new BchatDeviceException(
                            'Akun Anda sudah memiliki perangkat aktif. Konfirmasi penggantian perangkat (confirm_replacement) wajib diberikan secara eksplisit.',
                            'REPLACEMENT_CONFIRMATION_REQUIRED',
                            409
                        );
                    }

                    // Tolak jika expected_active_device_uuid tidak disertakan
                    if ($expectedActiveDeviceUuid === null || trim($expectedActiveDeviceUuid) === '') {
                        throw new BchatDeviceException(
                            'UUID perangkat aktif yang diharapkan untuk diganti (expected_active_device_uuid) wajib disertakan bersama konfirmasi replacement.',
                            'EXPECTED_DEVICE_UUID_REQUIRED',
                            409
                        );
                    }

                    $cleanExpectedUuid = strtolower(trim($expectedActiveDeviceUuid));

                    // Tolak jika expected UUID tidak cocok dengan perangkat aktif aktual
                    if (strtolower($activeDevice->device_uuid) !== $cleanExpectedUuid) {
                        throw new BchatDeviceException(
                            'UUID perangkat aktif yang diharapkan tidak sesuai dengan perangkat aktif yang terdaftar pada akun ini. Kemungkinan perangkat sudah berubah.',
                            'EXPECTED_DEVICE_UUID_MISMATCH',
                            409
                        );
                    }
                }

                $affectedRoomIds = [];
                $affectedChallengeIds = [];
                $oldKeyPackageIds = [];

                if ($activeDevice) {
                    // Cari Welcome Challenge aktif yang menargetkan perangkat lama
                    $challenges = BchatWelcomeChallenge::where('recipient_device_id', $activeDevice->id)
                        ->where('operation_status', 'ACTIVE')
                        ->get(['id', 'room_id']);

                    $affectedRoomIds = $challenges->pluck('room_id')->unique()->sort()->values()->all();
                    $affectedChallengeIds = $challenges->pluck('id')->sort()->values()->all();

                    // Cari KeyPackage lama yang belum dikonsumsi milik perangkat aktif lama
                    $oldKeyPackageIds = BchatKeyPackage::where('device_id', $activeDevice->id)
                        ->where('is_consumed', false)
                        ->orderBy('id', 'asc')
                        ->pluck('id')
                        ->all();
                }

                // -------------------------------------------------------------
                // 5. HIERARCHICAL LOCKING (rooms -> welcome_challenges -> user_devices -> key_packages)
                // -------------------------------------------------------------
                // A. Kunci rooms terdampak (id ASC)
                if (!empty($affectedRoomIds)) {
                    BchatRoom::whereIn('id', $affectedRoomIds)
                        ->orderBy('id', 'asc')
                        ->lockForUpdate()
                        ->get();
                }

                // B. Kunci welcome_challenges terdampak (id ASC)
                $lockedChallenges = collect();
                if (!empty($affectedChallengeIds)) {
                    $lockedChallenges = BchatWelcomeChallenge::whereIn('id', $affectedChallengeIds)
                        ->orderBy('id', 'asc')
                        ->lockForUpdate()
                        ->get();
                }

                // C. Kunci perangkat aktif lama (user_devices)
                $lockedActiveDevice = null;
                if ($activeDevice) {
                    $lockedActiveDevice = BchatUserDevice::where('id', $activeDevice->id)
                        ->lockForUpdate()
                        ->first();
                }

                // D. Kunci key_packages lama yang belum dikonsumsi (id ASC)
                $lockedKeyPackages = collect();
                if (!empty($oldKeyPackageIds)) {
                    $lockedKeyPackages = BchatKeyPackage::whereIn('id', $oldKeyPackageIds)
                        ->orderBy('id', 'asc')
                        ->lockForUpdate()
                        ->get();
                }

                // -------------------------------------------------------------
                // 6. VALIDASI ULANG POST-LOCK (Double-Checked Re-validation)
                // -------------------------------------------------------------
                if ($lockedActiveDevice) {
                    if ($lockedActiveDevice->status !== 'ACTIVE' || (int) $lockedActiveDevice->user_id !== (int) $user->id) {
                        throw new BchatDeviceException(
                            'Kondisi perangkat lama telah berubah selama proses replacement.',
                            'ACTIVE_DEVICE_STATE_CONCURRENCY',
                            409
                        );
                    }

                    // Re-validasi kecocokan expected_active_device_uuid pasca-lock
                    // untuk mencegah race condition antara pre-lock check dan lock acquisition.
                    if ($expectedActiveDeviceUuid !== null) {
                        $cleanExpectedUuidPostLock = strtolower(trim($expectedActiveDeviceUuid));
                        if (strtolower($lockedActiveDevice->device_uuid) !== $cleanExpectedUuidPostLock) {
                            throw new BchatDeviceException(
                                'UUID perangkat aktif tidak lagi sesuai saat dikonfirmasi ulang setelah locking. Kemungkinan replacement telah dilakukan bersamaan.',
                                'EXPECTED_DEVICE_UUID_MISMATCH',
                                409
                            );
                        }
                    }
                }

                // -------------------------------------------------------------
                // 7. MUTASI MANAGED REPLACEMENT (Pencabutan & Invalidasi)
                // -------------------------------------------------------------
                $revokedDeviceId = null;
                if ($lockedActiveDevice) {
                    $revokedDeviceId = $lockedActiveDevice->id;

                    // A. Batalkan Welcome Challenge aktif terkait perangkat lama
                    foreach ($lockedChallenges as $challenge) {
                        if ((int) $challenge->recipient_device_id === (int) $lockedActiveDevice->id && $challenge->operation_status === 'ACTIVE') {
                            $challenge->update([
                                'operation_status' => 'REVOKED_SUPERSEDED',
                                'challenge_status' => 'EXPIRED',
                            ]);
                        }
                    }

                    // B. Invalidasi KeyPackage lama yang belum dikonsumsi
                    foreach ($lockedKeyPackages as $kp) {
                        if ((int) $kp->device_id === (int) $lockedActiveDevice->id && !$kp->is_consumed) {
                            $kp->update([
                                'is_consumed' => true,
                                'consumed_at' => now(),
                            ]);
                        }
                    }

                    // C. Revoke status perangkat aktif lama
                    $lockedActiveDevice->update([
                        'status'            => 'REVOKED',
                        'revoked_at'        => now(),
                        'revocation_reason' => 'MANAGED_REPLACEMENT',
                    ]);
                }

                // -------------------------------------------------------------
                // 8. KONSUMSI ATOMIK REGISTRATION NONCE
                // -------------------------------------------------------------
                $nonceRecord->update([
                    'status'      => 'CONSUMED',
                    'consumed_at' => now(),
                ]);

                // -------------------------------------------------------------
                // 9. HITUNG KEY VERSION MONOTONIK PER (user_id, device_uuid)
                // -------------------------------------------------------------
                $maxKeyVersion = BchatUserDevice::where('user_id', $user->id)
                    ->where('device_uuid', $targetDeviceUuid)
                    ->max('key_version');

                $newKeyVersion = $maxKeyVersion !== null ? ((int) $maxKeyVersion + 1) : 1;

                // -------------------------------------------------------------
                // 10. PEMBUATAN PERANGKAT BARU (STATUS = ACTIVE)
                // -------------------------------------------------------------
                $newDevice = BchatUserDevice::create([
                    'user_id'                => $user->id,
                    'device_uuid'            => $targetDeviceUuid,
                    'device_name'            => $cleanDeviceName,
                    'key_version'            => $newKeyVersion,
                    'public_key'             => $targetPublicKeyHex,
                    'public_key_fingerprint' => $targetFingerprint,
                    'status'                 => 'ACTIVE',
                    'revoked_at'             => null,
                    'revocation_reason'      => null,
                    'last_active_at'         => now(),
                ]);

                return [
                    'device_id'              => $newDevice->id,
                    'device_uuid'            => $newDevice->device_uuid,
                    'device_name'            => $newDevice->device_name,
                    'key_version'            => $newDevice->key_version,
                    'public_key'             => $newDevice->public_key,
                    'public_key_fingerprint' => $newDevice->public_key_fingerprint,
                    'status'                 => $newDevice->status,
                    'is_replacement'         => $revokedDeviceId !== null,
                    'revoked_device_id'      => $revokedDeviceId,
                    'registered_at'          => $newDevice->created_at?->toIso8601String() ?? now()->toIso8601String(),
                ];
            }, 3);
        } catch (BchatDeviceException $e) {
            throw $e;
        } catch (QueryException $e) {
            $sqlState = $e->getCode();
            $errorCode = $e->errorInfo[1] ?? null;
            $errorMessage = $e->getMessage();

            if ($errorCode === 1062) {
                if (str_contains($errorMessage, 'uk_device_public_key')) {
                    throw new BchatDeviceException(
                        'Public key Ed25519 sudah terdaftar pada perangkat lain.',
                        'PUBLIC_KEY_ALREADY_IN_USE',
                        409,
                        $e
                    );
                }

                if (str_contains($errorMessage, 'uk_device_active_user_slot')) {
                    throw new BchatDeviceException(
                        'Pengguna sudah memiliki perangkat aktif lain yang terdaftar.',
                        'ACTIVE_DEVICE_CONFLICT',
                        409,
                        $e
                    );
                }

                if (str_contains($errorMessage, 'uk_device_user_uuid_version')) {
                    throw new BchatDeviceException(
                        'Konflik versi kunci perangkat.',
                        'KEY_VERSION_CONFLICT',
                        409,
                        $e
                    );
                }

                throw new BchatDeviceException(
                    'Terjadi pelanggaran constraint unik pada basis data.',
                    'UNIQUE_CONSTRAINT_VIOLATION',
                    409,
                    $e
                );
            }

            if ($sqlState === '40001' || $errorCode === 1213 || $errorCode === 1205) {
                throw new BchatDeviceException(
                    'Operasi registrasi mengalami konflik konkurensi database. Silakan coba kembali sesaat lagi.',
                    'CONCURRENCY_LOCK_CONFLICT',
                    409,
                    $e
                );
            }

            throw new BchatDeviceException(
                'Terjadi kesalahan saat memproses registrasi di basis data.',
                'DATABASE_ERROR',
                500,
                $e
            );
        } catch (Throwable $e) {
            throw new BchatDeviceException(
                'Terjadi kesalahan internal yang tidak terduga saat registrasi perangkat.',
                'INTERNAL_ERROR',
                500,
                $e
            );
        }
    }

    /**
     * Mengambil status perangkat aktif milik pengguna yang sedang login.
     *
     * Mendukung rekonsiliasi staged key: jika device_uuid diberikan, endpoint memeriksa
     * apakah UUID tersebut sudah ACTIVE di backend (untuk recovery drop-response scenario).
     *
     * @param User        $user           Pengguna terautentikasi via Sanctum
     * @param string|null $queryDeviceUuid UUID opsional untuk rekonsiliasi staged key
     * @return array<string, mixed>       Metadata status perangkat (non-sensitive)
     */
    public function getActiveDeviceStatus(User $user, ?string $queryDeviceUuid = null): array
    {
        // Ambil perangkat ACTIVE milik user (paling baru)
        $activeDevice = BchatUserDevice::where('user_id', $user->id)
            ->where('status', 'ACTIVE')
            ->orderByDesc('created_at')
            ->first();

        $result = [
            'has_active_device' => $activeDevice !== null,
            'active_device'     => null,
            'queried_device'    => null,
        ];

        if ($activeDevice !== null) {
            $result['active_device'] = [
                'device_uuid'   => (string) $activeDevice->device_uuid,
                'device_name'   => (string) $activeDevice->device_name,
                'status'        => (string) $activeDevice->status,
                'key_version'   => (int) $activeDevice->key_version,
                'registered_at' => $activeDevice->created_at?->toIso8601String(),
            ];
        }

        // Rekonsiliasi staged key: periksa device_uuid spesifik yang diberikan klien
        if ($queryDeviceUuid !== null) {
            $cleanQueryUuid = strtolower(trim($queryDeviceUuid));

            // Hanya kembalikan informasi device yang benar-benar milik user ini
            $queriedDevice = BchatUserDevice::where('user_id', $user->id)
                ->where('device_uuid', $cleanQueryUuid)
                ->orderByDesc('key_version')
                ->first();

            if ($queriedDevice !== null) {
                $result['queried_device'] = [
                    'device_uuid'   => (string) $queriedDevice->device_uuid,
                    'status'        => (string) $queriedDevice->status,
                    'key_version'   => (int) $queriedDevice->key_version,
                    'registered_at' => $queriedDevice->created_at?->toIso8601String(),
                    'is_active'     => $queriedDevice->status === 'ACTIVE',
                ];
            } else {
                // UUID tidak terdaftar atas nama user ini — kembalikan null tanpa membocorkan
                // apakah UUID tersebut milik user lain
                $result['queried_device'] = null;
            }
        }

        return $result;
    }

    /**
     * Memetakan kode kesalahan internal BchatPopVerificationService ke kode error domain.
     */
    protected function mapPopErrorCode(string $code): string
    {
        return match ($code) {
            'INVALID_PAYLOAD_LENGTH'          => 'INVALID_POP_PAYLOAD_LENGTH',
            'INVALID_SIGNATURE_LENGTH'        => 'INVALID_POP_SIGNATURE_LENGTH',
            'INVALID_MAGIC_HEADER'            => 'INVALID_MAGIC_HEADER',
            'USER_ID_OUT_OF_RANGE',
            'EXPECTED_USER_ID_INVALID',
            'USER_ID_MISMATCH'                => 'USER_ID_MISMATCH',
            'EXPECTED_DEVICE_UUID_INVALID',
            'DEVICE_UUID_MISMATCH'            => 'DEVICE_UUID_MISMATCH',
            'EXPECTED_NONCE_INVALID',
            'NONCE_MISMATCH'                  => 'NONCE_MISMATCH',
            'TIMESTAMP_OUT_OF_RANGE',
            'EXPECTED_TIMESTAMP_INVALID',
            'TIMESTAMP_MISMATCH'              => 'TIMESTAMP_MISMATCH',
            'FINGERPRINT_MISMATCH'            => 'FINGERPRINT_MISMATCH',
            'SIGNATURE_VERIFICATION_FAILED'   => 'SIGNATURE_VERIFICATION_FAILED',
            'SODIUM_EXTENSION_NOT_AVAILABLE'  => 'SODIUM_EXTENSION_NOT_AVAILABLE',
            default                           => 'POP_VERIFICATION_FAILED',
        };
    }

    /**
     * Memetakan kode kesalahan internal BchatPopVerificationService ke status HTTP yang tepat.
     */
    protected function mapPopErrorStatus(string $code): int
    {
        return match ($code) {
            'INVALID_PAYLOAD_LENGTH',
            'INVALID_SIGNATURE_LENGTH',
            'INVALID_MAGIC_HEADER'            => 422,
            'USER_ID_MISMATCH',
            'DEVICE_UUID_MISMATCH',
            'NONCE_MISMATCH',
            'TIMESTAMP_MISMATCH',
            'FINGERPRINT_MISMATCH',
            'SIGNATURE_VERIFICATION_FAILED'   => 401,
            'SODIUM_EXTENSION_NOT_AVAILABLE'  => 500,
            default                           => 401,
        };
    }
}
