<?php

namespace App\Services;

use App\Exceptions\BchatHistoryTransferException;
use App\Models\BchatHistoryTransfer;
use App\Models\BchatRoom;
use App\Models\BchatRoomMember;
use App\Models\BchatUserDevice;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

class BchatHistoryTransferService
{
    public const TRANSFER_TTL_MINUTES = 10;

    public function __construct(
        protected readonly BchatHistoryTransferVerificationService $verificationService
    ) {
    }

    /**
     * Menginisiasi permintaan transfer riwayat oleh perangkat penerima tepercaya.
     *
     * @param User $user
     * @param string $roomUuid
     * @param array<string, mixed> $payload
     * @param string $recipientDeviceUuid
     * @return BchatHistoryTransfer
     *
     * @throws BchatHistoryTransferException
     */
    public function requestTransfer(
        User $user,
        string $roomUuid,
        array $payload,
        string $recipientDeviceUuid
    ): BchatHistoryTransfer {
        $cleanRoomUuid = strtolower(trim($roomUuid));
        $cleanRecipientUuid = strtolower(trim($recipientDeviceUuid));
        $cleanSourceUuid = strtolower(trim((string) ($payload['source_device_uuid'] ?? '')));

        if (!Str::isUuid($cleanRoomUuid) || !Str::isUuid($cleanRecipientUuid) || !Str::isUuid($cleanSourceUuid)) {
            throw new BchatHistoryTransferException('Format UUID tidak valid.', 'INVALID_UUID_FORMAT', 422);
        }

        if ($cleanRecipientUuid === $cleanSourceUuid) {
            throw new BchatHistoryTransferException('Perangkat sumber tidak boleh sama dengan perangkat penerima.', 'SOURCE_EQUALS_RECIPIENT', 422);
        }

        // 1. Verifikasi Ruang Obrolan
        $room = BchatRoom::where('room_uuid', $cleanRoomUuid)->first();
        if (!$room || $room->status !== 'ACTIVE') {
            throw new BchatHistoryTransferException('Ruang obrolan tidak ditemukan atau tidak aktif.', 'ROOM_NOT_ACTIVE', 404);
        }

        // 2. Verifikasi Keanggotaan User di Room
        $membership = BchatRoomMember::where('room_id', $room->id)
            ->where('user_id', $user->id)
            ->where('is_active', true)
            ->first();

        if (!$membership) {
            throw new BchatHistoryTransferException('Pengguna bukan anggota aktif dalam ruang obrolan ini.', 'FORBIDDEN_NOT_ROOM_MEMBER', 403);
        }

        // 3. Verifikasi Perangkat Penerima (Wajib ACTIVE dan is_trusted)
        $recipientDevice = BchatUserDevice::where('device_uuid', $cleanRecipientUuid)
            ->where('user_id', $user->id)
            ->first();

        if (!$recipientDevice || $recipientDevice->status !== 'ACTIVE') {
            throw new BchatHistoryTransferException('Perangkat penerima tidak aktif pada akun ini.', 'RECIPIENT_DEVICE_NOT_ACTIVE', 403);
        }

        if (!$recipientDevice->is_trusted) {
            throw new BchatHistoryTransferException('Perangkat penerima belum disetujui sebagai perangkat tepercaya.', 'RECIPIENT_NOT_TRUSTED', 403);
        }

        // 4. Verifikasi Perangkat Sumber (Wajib ACTIVE dan is_trusted)
        $sourceDevice = BchatUserDevice::where('device_uuid', $cleanSourceUuid)
            ->where('user_id', $user->id)
            ->first();

        if (!$sourceDevice || $sourceDevice->status !== 'ACTIVE') {
            throw new BchatHistoryTransferException('Perangkat sumber tidak ditemukan atau tidak aktif pada akun ini.', 'SOURCE_DEVICE_NOT_ACTIVE', 404);
        }

        if (!$sourceDevice->is_trusted) {
            throw new BchatHistoryTransferException('Perangkat sumber bukan merupakan perangkat tepercaya.', 'SOURCE_DEVICE_NOT_TRUSTED', 403);
        }

        // 5. Cek Apakah Sudah Ada Transfer Aktif untuk Room & Recipient Ini
        $existingActive = BchatHistoryTransfer::where('room_id', $room->id)
            ->where('recipient_device_id', $recipientDevice->id)
            ->whereIn('status', ['REQUESTED', 'READY'])
            ->where('expires_at', '>', now())
            ->first();

        if ($existingActive) {
            throw new BchatHistoryTransferException('Masih terdapat sesi transfer riwayat yang aktif untuk ruang obrolan ini.', 'TRANSFER_ALREADY_PENDING', 409);
        }

        $keyExchangeAlgo = (string) ($payload['key_exchange_algorithm'] ?? 'ECDH-P256');
        $recipientEphemeralPubKey = (string) ($payload['recipient_ephemeral_public_key'] ?? '');
        $transferNonce = strtolower(trim((string) ($payload['transfer_nonce'] ?? '')));
        $requestSignature = (string) ($payload['request_signature'] ?? '');

        if (empty($recipientEphemeralPubKey)) {
            throw new BchatHistoryTransferException('Kunci publik ephemeral penerima wajib diisi.', 'MISSING_EPHEMERAL_PUBLIC_KEY', 422);
        }

        // 6. Verifikasi Tanda Tangan Digital Permintaan oleh Perangkat Penerima
        $this->verificationService->verifyRequestSignature(
            recipientDevice: $recipientDevice,
            userId: (int) $user->id,
            roomUuid: $cleanRoomUuid,
            recipientDeviceUuid: $cleanRecipientUuid,
            sourceDeviceUuid: $cleanSourceUuid,
            nonceHex: $transferNonce,
            recipientEphemeralPublicKey: $recipientEphemeralPubKey,
            signatureHexOrBase64: $requestSignature
        );

        // 7. Simpan Sesi Transfer
        $transferUuid = (string) Str::uuid();

        return BchatHistoryTransfer::create([
            'transfer_uuid'                  => $transferUuid,
            'user_id'                        => $user->id,
            'room_id'                        => $room->id,
            'room_uuid'                      => $cleanRoomUuid,
            'recipient_device_id'            => $recipientDevice->id,
            'recipient_device_uuid'          => $cleanRecipientUuid,
            'source_device_id'               => $sourceDevice->id,
            'source_device_uuid'             => $cleanSourceUuid,
            'key_exchange_algorithm'         => $keyExchangeAlgo,
            'recipient_ephemeral_public_key' => $recipientEphemeralPubKey,
            'transfer_nonce'                 => $transferNonce,
            'request_signature'              => $requestSignature,
            'status'                         => 'REQUESTED',
            'expires_at'                     => now()->addMinutes(self::TRANSFER_TTL_MINUTES),
        ]);
    }

    /**
     * Mengambil daftar permintaan transfer yang ditujukan kepada perangkat sumber.
     */
    public function getPendingTransfersForSource(User $user, BchatUserDevice $sourceDevice): Collection
    {
        // Expire-kan sesi yang telah melewati TTL
        BchatHistoryTransfer::where('source_device_id', $sourceDevice->id)
            ->whereIn('status', ['REQUESTED', 'READY'])
            ->where('expires_at', '<=', now())
            ->update(['status' => 'EXPIRED']);

        return BchatHistoryTransfer::where('user_id', $user->id)
            ->where('source_device_id', $sourceDevice->id)
            ->where('status', 'REQUESTED')
            ->where('expires_at', '>', now())
            ->with(['room', 'recipientDevice'])
            ->orderBy('created_at', 'desc')
            ->get();
    }

    /**
     * Mengambil detail sesi transfer.
     */
    public function getTransferDetail(User $user, string $transferUuid, BchatUserDevice $device): BchatHistoryTransfer
    {
        $transfer = BchatHistoryTransfer::where('transfer_uuid', strtolower(trim($transferUuid)))
            ->where('user_id', $user->id)
            ->first();

        if (!$transfer) {
            throw new BchatHistoryTransferException('Sesi transfer riwayat tidak ditemukan.', 'TRANSFER_NOT_FOUND', 404);
        }

        // Otorisasi: hanya perangkat sumber atau penerima yang boleh melihat
        if ((int) $transfer->source_device_id !== (int) $device->id && (int) $transfer->recipient_device_id !== (int) $device->id) {
            throw new BchatHistoryTransferException('Perangkat tidak memiliki akses terhadap sesi transfer ini.', 'FORBIDDEN_DEVICE_ACCESS', 403);
        }

        // Cek kedaluwarsa
        if ($transfer->isExpired() && in_array($transfer->status, ['REQUESTED', 'READY'], true)) {
            $transfer->update(['status' => 'EXPIRED']);
        }

        return $transfer;
    }

    /**
     * Mengunggah snapshot ciphertext oleh perangkat sumber.
     *
     * @param User $user
     * @param string $transferUuid
     * @param BchatUserDevice $sourceDevice
     * @param array<string, mixed> $payload
     * @return BchatHistoryTransfer
     *
     * @throws BchatHistoryTransferException
     */
    public function uploadEncryptedSnapshot(
        User $user,
        string $transferUuid,
        BchatUserDevice $sourceDevice,
        array $payload
    ): BchatHistoryTransfer {
        return DB::transaction(function () use ($user, $transferUuid, $sourceDevice, $payload) {
            $transfer = BchatHistoryTransfer::where('transfer_uuid', strtolower(trim($transferUuid)))
                ->where('user_id', $user->id)
                ->lockForUpdate()
                ->first();

            if (!$transfer) {
                throw new BchatHistoryTransferException('Sesi transfer riwayat tidak ditemukan.', 'TRANSFER_NOT_FOUND', 404);
            }

            if ((int) $transfer->source_device_id !== (int) $sourceDevice->id) {
                throw new BchatHistoryTransferException('Hanya perangkat sumber yang ditunjuk yang berhak mengunggah snapshot.', 'FORBIDDEN_NOT_SOURCE_DEVICE', 403);
            }

            if ($transfer->isExpired()) {
                $transfer->update(['status' => 'EXPIRED']);
                throw new BchatHistoryTransferException('Sesi transfer riwayat telah kedaluwarsa.', 'TRANSFER_EXPIRED', 410);
            }

            if ($transfer->status !== 'REQUESTED') {
                throw new BchatHistoryTransferException("Sesi transfer tidak dalam status menunggu unggahan (status saat ini: {$transfer->status}).", 'INVALID_TRANSFER_STATUS', 409);
            }

            $sourceEphemeralPubKey = (string) ($payload['source_ephemeral_public_key'] ?? '');
            $snapshotCiphertext = (string) ($payload['snapshot_ciphertext'] ?? '');
            $snapshotHashSha256 = strtolower(trim((string) ($payload['snapshot_hash_sha256'] ?? '')));
            $snapshotIv = (string) ($payload['snapshot_iv'] ?? '');
            $snapshotAuthTag = (string) ($payload['snapshot_auth_tag'] ?? '');
            $snapshotMetadata = $payload['snapshot_metadata'] ?? null;
            $sourceSignature = (string) ($payload['source_signature'] ?? '');

            if (empty($sourceEphemeralPubKey) || empty($snapshotCiphertext) || empty($snapshotHashSha256) || empty($sourceSignature)) {
                throw new BchatHistoryTransferException('Data snapshot ciphertext tidak lengkap.', 'INCOMPLETE_SNAPSHOT_PAYLOAD', 422);
            }

            // 1. Verifikasi Integritas SHA-256 Snapshot Ciphertext
            $rawCiphertext = base64_decode($snapshotCiphertext, true);
            if ($rawCiphertext === false || strlen($rawCiphertext) === 0) {
                throw new BchatHistoryTransferException('Ciphertext snapshot bukan Base64 valid.', 'INVALID_CIPHERTEXT_BASE64', 422);
            }

            $calculatedHash = hash('sha256', $rawCiphertext);
            if (!hash_equals($snapshotHashSha256, $calculatedHash)) {
                throw new BchatHistoryTransferException('Hash SHA-256 snapshot tidak cocok dengan isi ciphertext (integritas gagal).', 'SNAPSHOT_HASH_MISMATCH', 422);
            }

            // 2. Verifikasi Tanda Tangan Digital Perangkat Sumber
            $this->verificationService->verifySnapshotSignature(
                sourceDevice: $sourceDevice,
                userId: (int) $user->id,
                transferUuid: $transfer->transfer_uuid,
                roomUuid: $transfer->room_uuid,
                recipientDeviceUuid: $transfer->recipient_device_uuid,
                sourceDeviceUuid: $transfer->source_device_uuid,
                snapshotHashSha256Hex: $snapshotHashSha256,
                sourceEphemeralPublicKey: $sourceEphemeralPubKey,
                signatureHexOrBase64: $sourceSignature
            );

            // 3. Simpan Ciphertext (Server HANYA menyimpan ciphertext dan metadata operasional)
            $transfer->update([
                'status'                      => 'READY',
                'source_ephemeral_public_key' => $sourceEphemeralPubKey,
                'snapshot_ciphertext'         => $snapshotCiphertext,
                'snapshot_hash_sha256'        => $snapshotHashSha256,
                'snapshot_iv'                 => $snapshotIv,
                'snapshot_auth_tag'           => $snapshotAuthTag,
                'snapshot_metadata'           => $snapshotMetadata,
                'source_signature'            => $sourceSignature,
                'uploaded_at'                 => now(),
            ]);

            return $transfer;
        });
    }

    /**
     * Mengunduh snapshot ciphertext oleh perangkat penerima.
     *
     * @param User $user
     * @param string $transferUuid
     * @param BchatUserDevice $recipientDevice
     * @return array<string, mixed>
     *
     * @throws BchatHistoryTransferException
     */
    public function downloadEncryptedSnapshot(
        User $user,
        string $transferUuid,
        BchatUserDevice $recipientDevice
    ): array {
        $transfer = BchatHistoryTransfer::where('transfer_uuid', strtolower(trim($transferUuid)))
            ->where('user_id', $user->id)
            ->first();

        if (!$transfer) {
            throw new BchatHistoryTransferException('Sesi transfer riwayat tidak ditemukan.', 'TRANSFER_NOT_FOUND', 404);
        }

        // Penegakan Keras: HANYA perangkat penerima yang ditunjuk yang berhak mengunduh
        if ((int) $transfer->recipient_device_id !== (int) $recipientDevice->id) {
            throw new BchatHistoryTransferException('Perangkat Anda bukan merupakan perangkat penerima target dari transfer ini.', 'FORBIDDEN_NOT_RECIPIENT_DEVICE', 403);
        }

        if ($transfer->isExpired()) {
            $transfer->update(['status' => 'EXPIRED']);
            throw new BchatHistoryTransferException('Sesi transfer riwayat telah kedaluwarsa.', 'TRANSFER_EXPIRED', 410);
        }

        if ($transfer->status !== 'READY') {
            throw new BchatHistoryTransferException("Snapshot riwayat belum siap untuk diunduh (status saat ini: {$transfer->status}).", 'TRANSFER_NOT_READY', 409);
        }

        return [
            'transfer_uuid'                  => $transfer->transfer_uuid,
            'room_uuid'                      => $transfer->room_uuid,
            'source_device_uuid'             => $transfer->source_device_uuid,
            'source_device_public_key'       => $transfer->sourceDevice?->public_key,
            'recipient_device_uuid'          => $transfer->recipient_device_uuid,
            'key_exchange_algorithm'         => $transfer->key_exchange_algorithm,
            'source_ephemeral_public_key'    => $transfer->source_ephemeral_public_key,
            'recipient_ephemeral_public_key' => $transfer->recipient_ephemeral_public_key,
            'snapshot_ciphertext'            => $transfer->snapshot_ciphertext,
            'snapshot_hash_sha256'           => $transfer->snapshot_hash_sha256,
            'snapshot_iv'                    => $transfer->snapshot_iv,
            'snapshot_auth_tag'              => $transfer->snapshot_auth_tag,
            'snapshot_metadata'              => $transfer->snapshot_metadata,
            'source_signature'               => $transfer->source_signature,
            'expires_at'                     => $transfer->expires_at->toIso8601String(),
        ];
    }

    /**
     * Menyelesaikan transfer setelah penerima berhasil mendekripsi dan mengimpor riwayat.
     *
     * @param User $user
     * @param string $transferUuid
     * @param BchatUserDevice $recipientDevice
     * @param array<string, mixed> $payload
     * @return BchatHistoryTransfer
     *
     * @throws BchatHistoryTransferException
     */
    public function completeTransfer(
        User $user,
        string $transferUuid,
        BchatUserDevice $recipientDevice,
        array $payload
    ): BchatHistoryTransfer {
        return DB::transaction(function () use ($user, $transferUuid, $recipientDevice, $payload) {
            $transfer = BchatHistoryTransfer::where('transfer_uuid', strtolower(trim($transferUuid)))
                ->where('user_id', $user->id)
                ->lockForUpdate()
                ->first();

            if (!$transfer) {
                throw new BchatHistoryTransferException('Sesi transfer riwayat tidak ditemukan.', 'TRANSFER_NOT_FOUND', 404);
            }

            if ((int) $transfer->recipient_device_id !== (int) $recipientDevice->id) {
                throw new BchatHistoryTransferException('Hanya perangkat penerima yang berhak mengonfirmasi penyelesaian transfer.', 'FORBIDDEN_NOT_RECIPIENT_DEVICE', 403);
            }

            if ($transfer->status === 'COMPLETED') {
                return $transfer; // Idempotent
            }

            if ($transfer->isExpired()) {
                $transfer->update(['status' => 'EXPIRED']);
                throw new BchatHistoryTransferException('Sesi transfer riwayat telah kedaluwarsa.', 'TRANSFER_EXPIRED', 410);
            }

            if ($transfer->status !== 'READY') {
                throw new BchatHistoryTransferException("Sesi transfer belum siap diselesaikan (status saat ini: {$transfer->status}).", 'INVALID_TRANSFER_STATUS', 409);
            }

            $completionSignature = (string) ($payload['completion_signature'] ?? '');
            if (empty($completionSignature)) {
                throw new BchatHistoryTransferException('Tanda tangan digital penyelesaian wajib disertakan.', 'MISSING_COMPLETION_SIGNATURE', 422);
            }

            // Verifikasi tanda tangan ACK penerima
            $this->verificationService->verifyCompletionSignature(
                recipientDevice: $recipientDevice,
                userId: (int) $user->id,
                transferUuid: $transfer->transfer_uuid,
                recipientDeviceUuid: $transfer->recipient_device_uuid,
                snapshotHashSha256Hex: $transfer->snapshot_hash_sha256,
                signatureHexOrBase64: $completionSignature
            );

            // Update status ke COMPLETED dan bersihkan payload ciphertext dari server
            $transfer->update([
                'status'               => 'COMPLETED',
                'completion_signature' => $completionSignature,
                'completed_at'         => now(),
                'snapshot_ciphertext'  => null, // Menghapus ciphertext dari server setelah berhasil diimpor
            ]);

            return $transfer;
        });
    }

    /**
     * Membatalkan transfer riwayat oleh pengguna/perangkat.
     */
    public function cancelTransfer(
        User $user,
        string $transferUuid,
        BchatUserDevice $device,
        ?string $reason = null
    ): BchatHistoryTransfer {
        return DB::transaction(function () use ($user, $transferUuid, $device, $reason) {
            $transfer = BchatHistoryTransfer::where('transfer_uuid', strtolower(trim($transferUuid)))
                ->where('user_id', $user->id)
                ->lockForUpdate()
                ->first();

            if (!$transfer) {
                throw new BchatHistoryTransferException('Sesi transfer riwayat tidak ditemukan.', 'TRANSFER_NOT_FOUND', 404);
            }

            if ((int) $transfer->source_device_id !== (int) $device->id && (int) $transfer->recipient_device_id !== (int) $device->id) {
                throw new BchatHistoryTransferException('Perangkat tidak berhak membatalkan transfer ini.', 'FORBIDDEN_DEVICE_ACCESS', 403);
            }

            if ($transfer->status === 'COMPLETED') {
                throw new BchatHistoryTransferException('Transfer yang sudah selesai tidak dapat dibatalkan.', 'TRANSFER_ALREADY_COMPLETED', 409);
            }

            $transfer->update([
                'status'              => 'CANCELLED',
                'cancelled_at'        => now(),
                'cancellation_reason' => $reason ?? 'Dibatalkan oleh pengguna/perangkat.',
                'snapshot_ciphertext' => null,
            ]);

            return $transfer;
        });
    }
}

