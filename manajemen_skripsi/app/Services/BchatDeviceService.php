<?php

namespace App\Services;

use App\Exceptions\BchatDeviceException;
use App\Models\BchatDevicePairing;
use App\Models\BchatKeyPackage;
use App\Models\BchatRegistrationNonce;
use App\Models\BchatRoom;
use App\Models\BchatStepUpToken;
use App\Models\BchatUserDevice;
use App\Models\BchatWelcomeChallenge;
use App\Models\User;
use App\Services\BchatPairingVerificationService;
use App\Services\BchatPopVerificationService;
use App\Services\BchatWelcomeAckVerificationService;
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
     * @param string      $registrationMode         Mode registrasi: 'MANAGED_REPLACEMENT' (default) atau 'ADD_DEVICE'
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
        ?string $expectedActiveDeviceUuid = null,
        string $registrationMode = 'MANAGED_REPLACEMENT'
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
                $expectedActiveDeviceUuid,
                $registrationMode
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
                // Cek status perangkat dengan UUID ini pada akun pengguna
                $existingActiveForThisUuid = BchatUserDevice::where('user_id', $user->id)
                    ->where('device_uuid', $targetDeviceUuid)
                    ->where('status', 'ACTIVE')
                    ->first();

                $existingRevokedForThisUuid = BchatUserDevice::where('user_id', $user->id)
                    ->where('device_uuid', $targetDeviceUuid)
                    ->where('status', 'REVOKED')
                    ->exists();

                // Perangkat yang berstatus REVOKED tidak boleh dihidupkan kembali melalui pendaftaran ulang biasa
                if (!$existingActiveForThisUuid && $existingRevokedForThisUuid) {
                    throw new BchatDeviceException(
                        'Perangkat yang telah dicabut (REVOKED) tidak dapat didaftarkan kembali. Silakan daftarkan perangkat baru dengan identitas baru.',
                        'REVOKED_DEVICE_CANNOT_REREGISTER',
                        403
                    );
                }

                $isAddDeviceMode = ($registrationMode === 'ADD_DEVICE');

                if ($isAddDeviceMode) {
                    // Validasi batas konfigurasi maksimum perangkat aktif (jika dikonfigurasi)
                    $maxActiveConfig = config('bchat.max_active_devices_per_user');
                    if ($maxActiveConfig !== null && (int) $maxActiveConfig > 0) {
                        // Perangkat yang sama yang sudah terdaftar aktif (rotasi kunci) tidak dianggap sebagai penambahan kuota
                        $activeCountQuery = BchatUserDevice::where('user_id', $user->id)
                            ->where('status', 'ACTIVE');

                        if ($existingActiveForThisUuid) {
                            $activeCountQuery->where('device_uuid', '!=', $targetDeviceUuid);
                        }

                        $currentActiveCount = $activeCountQuery->count();
                        if ($currentActiveCount >= (int) $maxActiveConfig) {
                            throw new BchatDeviceException(
                                "Batas maksimum perangkat aktif ({$maxActiveConfig}) untuk akun ini telah tercapai.",
                                'MAX_ACTIVE_DEVICES_REACHED',
                                422
                            );
                        }
                    }

                    // Pada ADD_DEVICE, perangkat aktif lain TIDAK dicabut.
                    // Hanya jika UUID fisik perangkat ini sendiri sudah terdaftar ACTIVE sebelumnya,
                    // versi kunci lama milik UUID ini yang akan digantikan (key rotation / re-enrollment).
                    $activeDevice = $existingActiveForThisUuid;
                } else {
                    $hasAnyActive = BchatUserDevice::where('user_id', $user->id)
                        ->where('status', 'ACTIVE')
                        ->exists();

                    // -------------------------------------------------------------
                    // 4B. REPLACEMENT PROTECTION (v2 - MANAGED_REPLACEMENT mode)
                    //     Jika pengguna sudah memiliki perangkat ACTIVE, konfirmasi
                    //     eksplisit wajib diberikan beserta UUID perangkat yang akan
                    //     diganti. Validasi ini dilakukan di dalam transaksi dengan
                    //     data yang sudah ter-lock agar tidak rentan race condition.
                    // -------------------------------------------------------------
                    if ($hasAnyActive) {
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

                        $activeDevice = BchatUserDevice::where('user_id', $user->id)
                            ->where('device_uuid', $cleanExpectedUuid)
                            ->where('status', 'ACTIVE')
                            ->first();

                        // Tolak jika expected UUID tidak cocok dengan perangkat aktif aktual
                        if (!$activeDevice) {
                            throw new BchatDeviceException(
                                'UUID perangkat aktif yang diharapkan tidak sesuai dengan perangkat aktif yang terdaftar pada akun ini. Kemungkinan perangkat sudah berubah.',
                                'EXPECTED_DEVICE_UUID_MISMATCH',
                                409
                            );
                        }
                    } else {
                        $activeDevice = null;
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

                    // D. Batalkan seluruh sesi pairing PENDING yang melibatkan perangkat lama
                    BchatDevicePairing::where('user_id', $user->id)
                        ->where('status', 'PENDING')
                        ->where(function ($query) use ($lockedActiveDevice) {
                            $query->where('target_device_id', $lockedActiveDevice->id)
                                ->orWhere('approver_device_id', $lockedActiveDevice->id)
                                ->orWhere('target_device_uuid', $lockedActiveDevice->device_uuid)
                                ->orWhere('approver_device_uuid', $lockedActiveDevice->device_uuid);
                        })
                        ->lockForUpdate()
                        ->get()
                        ->each(function (BchatDevicePairing $pairing) {
                            $pairing->update([
                                'status'           => 'CANCELED',
                                'rejection_reason' => 'Perangkat terkait telah dicabut melalui Managed Replacement',
                                'updated_at'       => now(),
                            ]);
                        });
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
                // Hierarki penguncian transaksi: users (level 1) telah di-lock di baris 403.
                // Lakukan evaluasi status tepercaya dengan penguncian bchat_user_devices.
                $hasActiveTrustedDevice = BchatUserDevice::where('user_id', $user->id)
                    ->where('status', 'ACTIVE')
                    ->where('is_trusted', true)
                    ->lockForUpdate()
                    ->exists();

                $hasAnyActiveDevice = BchatUserDevice::where('user_id', $user->id)
                    ->where('status', 'ACTIVE')
                    ->lockForUpdate()
                    ->exists();

                // Bootstrap trust root HANYA terjadi jika akun belum pernah memiliki perangkat tepercaya,
                // tidak ada perangkat aktif saat ini, dan registrasi ini bukan merupakan Managed Replacement.
                $isReplacementRegistration = ($revokedDeviceId !== null);

                $hasEverHadTrustedDevice = BchatUserDevice::where('user_id', $user->id)
                    ->where('is_trusted', true)
                    ->lockForUpdate()
                    ->exists();

                if (!$hasAnyActiveDevice && !$hasActiveTrustedDevice && !$hasEverHadTrustedDevice && !$isReplacementRegistration) {
                    // Bootstrap trust root pertama kali untuk akun baru (belum pernah memiliki perangkat tepercaya)
                    $isTrusted = true;
                } elseif ($isAddDeviceMode) {
                    // Mode ADD_DEVICE:
                    // Jika merupakan rotasi kunci untuk UUID fisik yang sama dan sebelumnya sudah berstatus tepercaya
                    if ($existingActiveForThisUuid && (bool) $existingActiveForThisUuid->is_trusted) {
                        $isTrusted = true;
                    } else {
                        // Perangkat tambahan baru: TIDAK BOLEH otomatis tepercaya (wajib alur pairing)
                        $isTrusted = false;
                    }
                } else {
                    // Mode MANAGED_REPLACEMENT:
                    // Perangkat pengganti TIDAK mewarisi status tepercaya perangkat lama.
                    // Perangkat pengganti tetap is_trusted = false dan wajib melalui Trusted Device Pairing yang valid.
                    $isTrusted = false;
                }

                $trustedAt = $isTrusted ? now() : null;

                $newDevice = BchatUserDevice::create([
                    'user_id'                => $user->id,
                    'device_uuid'            => $targetDeviceUuid,
                    'device_name'            => $cleanDeviceName,
                    'key_version'            => $newKeyVersion,
                    'public_key'             => $targetPublicKeyHex,
                    'public_key_fingerprint' => $targetFingerprint,
                    'status'                 => 'ACTIVE',
                    'is_trusted'             => $isTrusted,
                    'trusted_at'             => $trustedAt,
                    'trusted_by_device_id'   => null,
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
                    'is_trusted'             => (bool) $newDevice->is_trusted,
                    'trusted_at'             => $newDevice->trusted_at?->toIso8601String(),
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
     * Mendukung multi-device: mengembalikan daftar seluruh perangkat aktif dalam active_devices,
     * serta mempertahankan field legacy active_device dan has_active_device demi backward compatibility.
     * Mendukung rekonsiliasi staged key: jika device_uuid diberikan, endpoint memeriksa
     * apakah UUID tersebut sudah ACTIVE di backend (untuk recovery drop-response scenario).
     *
     * @param User        $user           Pengguna terautentikasi via Sanctum
     * @param string|null $queryDeviceUuid UUID opsional untuk rekonsiliasi staged key
     * @return array<string, mixed>       Metadata status perangkat (non-sensitive)
     */
    public function getActiveDeviceStatus(User $user, ?string $queryDeviceUuid = null): array
    {
        // Ambil seluruh perangkat ACTIVE milik user (diurutkan paling baru)
        $activeDevices = BchatUserDevice::where('user_id', $user->id)
            ->where('status', 'ACTIVE')
            ->orderByDesc('created_at')
            ->get();

        $activeDeviceList = $activeDevices->map(function (BchatUserDevice $dev) {
            return [
                'device_uuid'   => (string) $dev->device_uuid,
                'device_name'   => (string) $dev->device_name,
                'status'        => (string) $dev->status,
                'is_trusted'    => (bool) $dev->is_trusted,
                'trusted_at'    => $dev->trusted_at?->toIso8601String(),
                'key_version'   => (int) $dev->key_version,
                'registered_at' => $dev->created_at?->toIso8601String(),
                'public_key'    => (string) $dev->public_key,
            ];
        })->values()->all();

        $firstActive = $activeDevices->first();
        $hasTrusted = $activeDevices->contains(fn (BchatUserDevice $d) => (bool) $d->is_trusted);

        // Ambil perangkat REVOKED terbaru untuk riwayat perangkat (maksimal 10)
        $revokedDevices = BchatUserDevice::where('user_id', $user->id)
            ->where('status', 'REVOKED')
            ->orderByDesc('revoked_at')
            ->limit(10)
            ->get();

        $revokedDeviceList = $revokedDevices->map(function (BchatUserDevice $dev) {
            return [
                'device_uuid'       => (string) $dev->device_uuid,
                'device_name'       => (string) $dev->device_name,
                'status'            => (string) $dev->status,
                'is_trusted'        => (bool) $dev->is_trusted,
                'trusted_at'        => $dev->trusted_at?->toIso8601String(),
                'key_version'       => (int) $dev->key_version,
                'registered_at'     => $dev->created_at?->toIso8601String(),
                'revoked_at'        => $dev->revoked_at?->toIso8601String(),
                'revocation_reason' => $dev->revocation_reason,
            ];
        })->values()->all();

        $result = [
            'has_active_device'  => $firstActive !== null,
            'has_trusted_device' => $hasTrusted,
            'active_device'      => $firstActive !== null ? [
                'device_uuid'   => (string) $firstActive->device_uuid,
                'device_name'   => (string) $firstActive->device_name,
                'status'        => (string) $firstActive->status,
                'is_trusted'    => (bool) $firstActive->is_trusted,
                'trusted_at'    => $firstActive->trusted_at?->toIso8601String(),
                'key_version'   => (int) $firstActive->key_version,
                'registered_at' => $firstActive->created_at?->toIso8601String(),
            ] : null,
            'active_devices'     => $activeDeviceList,
            'revoked_devices'    => $revokedDeviceList,
            'queried_device'     => null,
        ];

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
     * Mencabut satu perangkat spesifik milik pengguna tanpa mencabut perangkat aktif lainnya.
     * Operasi atomik dalam transaksi database dengan penguncian baris (lockForUpdate).
     * Mensyaratkan re-autentikasi step-up (password akun atau step-up token valid).
     *
     * @param User        $user        Pengguna terautentikasi
     * @param string      $deviceUuid  UUID perangkat yang akan dicabut
     * @param string      $reason      Alasan pencabutan (default: 'USER_REVOCATION')
     * @param string|null $password    Password akun untuk re-autentikasi step-up
     * @param string|null $stepUpToken Token step-up jika telah diotentikasi sebelumnya
     * @return array<string, mixed> Metadata konfirmasi pencabutan perangkat
     *
     * @throws BchatDeviceException Jika step-up gagal, UUID tidak valid, atau perangkat aktif tidak ditemukan
     */
    public function revokeDevice(
        User $user,
        string $deviceUuid,
        string $reason = 'USER_REVOCATION',
        ?string $password = null,
        ?string $stepUpToken = null
    ): array {
        $cleanDeviceUuid = strtolower(trim($deviceUuid));
        if (!Str::isUuid($cleanDeviceUuid)) {
            throw new BchatDeviceException('Format Device UUID tidak valid.', 'INVALID_DEVICE_UUID', 422);
        }

        // 1. Verifikasi Step-Up Re-Authentication (Password Akun atau Step-Up Token)
        if ($password !== null && trim($password) !== '') {
            if (!Hash::check($password, $user->password)) {
                throw new BchatDeviceException(
                    'Password re-autentikasi tidak valid.',
                    'INVALID_CREDENTIALS',
                    401
                );
            }
        } elseif ($stepUpToken !== null && trim($stepUpToken) !== '') {
            $cleanToken = strtolower(trim($stepUpToken));
            $tokenHash = hash('sha256', $cleanToken);
            $tokenRecord = BchatStepUpToken::where('token_hash', $tokenHash)
                ->where('user_id', $user->id)
                ->where('is_consumed', false)
                ->where('expires_at', '>', now())
                ->first();

            if (!$tokenRecord) {
                throw new BchatDeviceException(
                    'Step-up token tidak valid atau telah kedaluwarsa.',
                    'INVALID_STEP_UP_TOKEN',
                    403
                );
            }
            $tokenRecord->update([
                'is_consumed' => true,
                'consumed_at' => now(),
            ]);
        } else {
            throw new BchatDeviceException(
                'Konfirmasi password atau token step-up wajib disertakan untuk mencabut perangkat.',
                'STEP_UP_REQUIRED',
                403
            );
        }

        return DB::transaction(function () use ($user, $cleanDeviceUuid, $reason) {
            // 1. PENGUNCIAN LEVEL 1: users (Mutual Exclusion Gate)
            // Mengikuti hierarki locking yang konsisten dengan registerDevice()
            User::where('id', $user->id)->lockForUpdate()->firstOrFail();

            // 2. Kunci dan validasi perangkat aktif target
            $device = BchatUserDevice::where('user_id', $user->id)
                ->where('device_uuid', $cleanDeviceUuid)
                ->where('status', 'ACTIVE')
                ->lockForUpdate()
                ->first();

            if (!$device) {
                throw new BchatDeviceException(
                    'Perangkat aktif tidak ditemukan atau sudah dicabut sebelumnya.',
                    'ACTIVE_DEVICE_NOT_FOUND',
                    404
                );
            }

            // Batalkan Welcome Challenge aktif yang menargetkan perangkat ini saja
            $challenges = BchatWelcomeChallenge::where('recipient_device_id', $device->id)
                ->where('operation_status', 'ACTIVE')
                ->lockForUpdate()
                ->get();
            foreach ($challenges as $ch) {
                $ch->update([
                    'operation_status' => 'REVOKED_SUPERSEDED',
                    'challenge_status' => 'EXPIRED',
                ]);
            }

            // Invalidasi KeyPackage yang belum dikonsumsi milik perangkat ini saja
            $keyPackages = BchatKeyPackage::where('device_id', $device->id)
                ->where('is_consumed', false)
                ->lockForUpdate()
                ->get();
            foreach ($keyPackages as $kp) {
                $kp->update([
                    'is_consumed' => true,
                    'consumed_at' => now(),
                ]);
            }

            // Revoke perangkat target
            $device->update([
                'status'            => 'REVOKED',
                'revoked_at'        => now(),
                'revocation_reason' => $reason,
            ]);

            // Hitung sisa perangkat tepercaya aktif selain perangkat ini
            $remainingTrustedCount = BchatUserDevice::where('user_id', $user->id)
                ->where('status', 'ACTIVE')
                ->where('is_trusted', true)
                ->where('id', '!=', $device->id)
                ->count();

            // Batalkan seluruh sesi pairing PENDING yang melibatkan perangkat ini (target atau approver)
            // ATAU jika setelah pencabutan ini akun tidak lagi memiliki perangkat tepercaya aktif untuk menyetujui
            BchatDevicePairing::where('user_id', $user->id)
                ->where('status', 'PENDING')
                ->where(function ($query) use ($device, $remainingTrustedCount) {
                    $query->where('target_device_id', $device->id)
                        ->orWhere('approver_device_id', $device->id)
                        ->orWhere('target_device_uuid', $device->device_uuid)
                        ->orWhere('approver_device_uuid', $device->device_uuid);

                    if ($remainingTrustedCount === 0) {
                        $query->orWhereNotNull('id');
                    }
                })
                ->lockForUpdate()
                ->get()
                ->each(function (BchatDevicePairing $pairing) {
                    $pairing->update([
                        'status'           => 'CANCELED',
                        'rejection_reason' => 'Perangkat terkait telah dicabut (REVOKED)',
                        'updated_at'       => now(),
                    ]);
                });

            return [
                'device_uuid'       => (string) $device->device_uuid,
                'device_name'       => (string) $device->device_name,
                'status'            => 'REVOKED',
                'revocation_reason' => $reason,
                'revoked_at'        => $device->revoked_at?->toIso8601String(),
            ];
        });
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

    /**
     * Memeriksa apakah perangkat memiliki bukti historis kriptografis yang sah (Fail-Closed).
     *
     * Bukti sah secara eksklusif mencakup:
     * 1. Bukti Welcome ACK: Memiliki catatan Welcome Challenge CONSUMED yang terikat pada perangkat
     *    dan pengguna ini, dengan signature Ed25519 yang terverifikasi secara matematis terhadap kunci publik perangkat.
     * 2. Bukti Pairing Approval: Memiliki catatan persetujuan pairing APPROVED di mana perangkat ini adalah target,
     *    terikat pada user_id, device_uuid, dan fingerprint, serta approval signature Ed25519 dari approver device terverifikasi.
     *
     * KeyPackage terlepas dari status is_consumed, riwayat MANAGED_REPLACEMENT, atau kesamaan user_id
     * TIDAK DAPAT dijadikan bukti trust karena tidak membuktikan penguasaan kunci privat secara matematis.
     */
    public static function hasHistoricalTrustProof(BchatUserDevice|int $device): bool
    {
        $deviceModel = $device instanceof BchatUserDevice
            ? $device
            : BchatUserDevice::find($device);

        if (!$deviceModel) {
            return false;
        }

        // Perangkat yang berstatus REVOKED tidak dapat dianggap tepercaya
        if ($deviceModel->status !== 'ACTIVE') {
            return false;
        }

        // 1. Verifikasi Bukti Welcome ACK Kriptografis
        if (self::verifyHistoricalWelcomeAckProof($deviceModel)) {
            return true;
        }

        // 2. Verifikasi Bukti Persetujuan Pairing Kriptografis
        if (self::verifyHistoricalPairingProof($deviceModel)) {
            return true;
        }

        return false;
    }

    /**
     * Verifikasi bukti Welcome ACK: memastikan tanda tangan Ed25519 valid secara matematis
     * terhadap public key perangkat penerima dan terikat pada room serta user penerima.
     */
    protected static function verifyHistoricalWelcomeAckProof(BchatUserDevice $device): bool
    {
        $challenges = DB::table('bchat_welcome_challenges')
            ->join('bchat_rooms', 'bchat_welcome_challenges.room_id', '=', 'bchat_rooms.id')
            ->where('bchat_welcome_challenges.recipient_device_id', $device->id)
            ->where('bchat_welcome_challenges.recipient_user_id', $device->user_id)
            ->where('bchat_welcome_challenges.operation_status', 'CONSUMED')
            ->whereNotNull('bchat_welcome_challenges.ack_signature')
            ->select('bchat_welcome_challenges.*', 'bchat_rooms.room_uuid')
            ->get();

        if ($challenges->isEmpty()) {
            return false;
        }

        $publicKeyBinary = BchatWelcomeAckVerificationService::hexOrBinToBinary($device->public_key, 32);
        if ($publicKeyBinary === null) {
            return false;
        }

        $verifier = app(BchatWelcomeAckVerificationService::class);

        foreach ($challenges as $ch) {
            $rawSig = (string) $ch->ack_signature;
            $sigBinary = null;
            if (strlen($rawSig) === 128 && ctype_xdigit($rawSig)) {
                $sigBinary = hex2bin($rawSig);
            } else {
                $sigBinary = base64_decode($rawSig, true);
            }

            if (!$sigBinary || strlen($sigBinary) !== 64) {
                continue;
            }

            try {
                $result = $verifier->verify(
                    signatureBinary: $sigBinary,
                    publicKeyBinary: $publicKeyBinary,
                    roomUuid: (string) $ch->room_uuid,
                    recipientUserId: (int) $ch->recipient_user_id,
                    welcomeOperationUuid: (string) $ch->welcome_operation_uuid,
                    challengeUuid: (string) $ch->challenge_uuid,
                    challengeGeneration: (int) $ch->challenge_generation,
                    groupIdHashHexOrBin: (string) $ch->group_id_hash,
                    epoch: (int) $ch->epoch,
                    challengeNonceHexOrBin: (string) $ch->challenge_nonce
                );

                if ($result->isValid()) {
                    if ($ch->ack_payload_hash !== null && !hash_equals((string) $ch->ack_payload_hash, (string) $result->getPayloadHash())) {
                        continue;
                    }
                    return true;
                }
            } catch (Throwable) {
                continue;
            }
        }

        return false;
    }

    /**
     * Verifikasi bukti Pairing: memastikan pairing berstatus APPROVED, approver device sah dan aktif,
     * serta tanda tangan persetujuan Ed25519 terverifikasi secara kriptografis dan terikat pada target device.
     */
    protected static function verifyHistoricalPairingProof(BchatUserDevice $device): bool
    {
        $pairings = DB::table('bchat_device_pairings')
            ->where('target_device_id', $device->id)
            ->where('target_device_uuid', $device->device_uuid)
            ->where('target_public_key_fingerprint', $device->public_key_fingerprint)
            ->where('user_id', $device->user_id)
            ->where('status', 'APPROVED')
            ->whereNotNull('approved_at')
            ->whereNotNull('approval_signature')
            ->whereNotNull('approval_payload')
            ->get();

        if ($pairings->isEmpty()) {
            return false;
        }

        foreach ($pairings as $p) {
            $approver = DB::table('bchat_user_devices')
                ->where('id', $p->approver_device_id)
                ->where('user_id', $device->user_id)
                ->first(['id', 'device_uuid', 'public_key']);

            if (!$approver || !$approver->public_key) {
                continue;
            }

            $approverPubKeyBin = BchatWelcomeAckVerificationService::hexOrBinToBinary($approver->public_key, 32);
            if ($approverPubKeyBin === null) {
                continue;
            }

            $payloadHex = (string) $p->approval_payload;
            $sigHex = (string) $p->approval_signature;

            if (strlen($sigHex) !== 128 || !ctype_xdigit($sigHex) || strlen($payloadHex) !== 318 || !ctype_xdigit($payloadHex)) {
                continue;
            }

            $payloadBin = hex2bin($payloadHex);
            $sigBin = hex2bin($sigHex);

            // Verifikasi detached signature Ed25519
            if (!sodium_crypto_sign_verify_detached($sigBin, $payloadBin, $approverPubKeyBin)) {
                continue;
            }

            // Verifikasi integritas kanonikal payload
            $magic = substr($payloadBin, 0, 31);
            if ($magic !== BchatPairingVerificationService::MAGIC_HEADER) {
                continue;
            }

            $unpackedUser = unpack('JuserId', substr($payloadBin, 31, 8));
            if ($unpackedUser === false || (int) $unpackedUser['userId'] !== (int) $device->user_id) {
                continue;
            }

            $cleanTargetUuid = strtolower(str_replace('-', '', $device->device_uuid));
            if (!hash_equals(hex2bin($cleanTargetUuid), substr($payloadBin, 71, 16))) {
                continue;
            }

            $cleanFingerprint = strtolower(trim($device->public_key_fingerprint));
            if (!hash_equals(hex2bin($cleanFingerprint), substr($payloadBin, 119, 32))) {
                continue;
            }

            return true;
        }

        return false;
    }
}
