<?php

declare(strict_types=1);

namespace App\Services;

use App\Events\BchatMessageSent;
use App\Exceptions\BchatMessageException;
use App\Models\BchatMessage;
use App\Models\BchatRoom;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class BchatMessageService
{
    /**
     * Mengirimkan dan menyimpan ciphertext pesan aplikasi E2EE secara atomik.
     *
     * Invarian:
     * 1. Authorization: Memvalidasi room ACTIVE, penugasan AKTIF, dan sender is_active = TRUE via BchatMessagePolicy::create().
     * 2. Idempotency: Tuple (room_id, sender_id, message_uuid) bersifat unik mutlak.
     *    - Payload identik: mengembalikan record yang sudah ada (HTTP 200 idempotent).
     *    - Payload / metadata berbeda: melempar error konflik (HTTP 409).
     * 3. Monotonic Sequence: Mengunci room via SELECT FOR UPDATE dan mengalokasikan sequence berurutan tanpa celah race condition.
     * 4. Ciphertext-Only: Hanya menerima dan menyimpan payload terenkripsi; verifikasi SHA-256 fisik at-rest.
     * 5. Event Dispatch: Menyiarkan BchatMessageSent ($afterCommit = true) hanya setelah transaksi sukses commit.
     *
     * @param User $sender Pengguna pengirim terautentikasi
     * @param string $roomUuid UUID publik ruang obrolan (36 karakter)
     * @param array<string, mixed> $payload Data tervalidasi dari BchatSendMessageRequest
     * @return array{message: BchatMessage, is_replayed: bool}
     *
     * @throws BchatMessageException Jika otorisasi gagal, room tidak ditemukan/aktif, atau benturan idempotensi
     */
    public function sendMessage(User $sender, string $roomUuid, array $payload): array
    {
        $cleanRoomUuid = strtolower(trim($roomUuid));
        if (!Str::isUuid($cleanRoomUuid)) {
            throw new BchatMessageException(
                'Format Room UUID tidak valid.',
                'INVALID_ROOM_UUID',
                422
            );
        }

        $room = BchatRoom::where('room_uuid', $cleanRoomUuid)->first();
        if (!$room) {
            throw new BchatMessageException(
                'Ruang obrolan tidak ditemukan.',
                'ROOM_NOT_FOUND',
                404
            );
        }

        // 1. Verifikasi Otorisasi via Policy Kanonis
        if (!$sender->can('create', [BchatMessage::class, $room])) {
            throw new BchatMessageException(
                'Pengguna tidak memiliki izin untuk mengirimkan pesan ke ruang obrolan ini.',
                'FORBIDDEN_CANNOT_SEND_MESSAGE',
                403
            );
        }

        $cleanMsgUuid = strtolower(trim((string) $payload['message_uuid']));
        $cleanHash = strtolower(trim((string) $payload['payload_hash_sha256']));
        $epoch = (int) $payload['epoch'];
        $generation = (int) $payload['generation'];
        $contentType = (int) $payload['content_type'];
        $clientSentAt = (string) $payload['client_sent_at'];

        // Dekode Base64 payload biner
        $rawCiphertext = base64_decode((string) $payload['ciphertext_payload'], true);
        if ($rawCiphertext === false || strlen($rawCiphertext) === 0) {
            throw new BchatMessageException(
                'Payload ciphertext biner tidak valid atau kosong.',
                'INVALID_CIPHERTEXT_PAYLOAD',
                422
            );
        }

        // Verifikasi fisik hash SHA-256
        $calculatedHash = hash('sha256', $rawCiphertext);
        if (!hash_equals($cleanHash, $calculatedHash)) {
            throw new BchatMessageException(
                'Hash SHA-256 tidak cocok dengan konten payload ciphertext (integritas gagal).',
                'PAYLOAD_HASH_MISMATCH',
                422
            );
        }

        try {
            $result = DB::transaction(function () use (
                $sender,
                $room,
                $cleanMsgUuid,
                $cleanHash,
                $epoch,
                $generation,
                $contentType,
                $rawCiphertext,
                $clientSentAt
            ) {
                // A. Kunci baris room secara eksklusif untuk serialisasi alokasi sequence
                $lockedRoom = BchatRoom::where('id', $room->id)->lockForUpdate()->first();
                if (!$lockedRoom || $lockedRoom->status !== 'ACTIVE') {
                    throw new BchatMessageException(
                        'Ruang obrolan tidak dalam status aktif saat memproses pengiriman pesan.',
                        'ROOM_NOT_ACTIVE',
                        409
                    );
                }

                // B. Pengecekan Idempotensi (uk_msg_idempotency: room_id, sender_id, message_uuid)
                $existing = BchatMessage::where('room_id', $lockedRoom->id)
                    ->where('sender_id', $sender->id)
                    ->where('message_uuid', $cleanMsgUuid)
                    ->lockForUpdate()
                    ->first();

                if ($existing) {
                    // Evaluasi kecocokan payload & metadata kriptografis
                    $isHashMatch = hash_equals(strtolower($existing->payload_hash_sha256), $cleanHash);
                    $isEpochMatch = (int) $existing->epoch === $epoch;
                    $isGenMatch = (int) $existing->generation === $generation;
                    $isTypeMatch = (int) $existing->content_type === $contentType;

                    if ($isHashMatch && $isEpochMatch && $isGenMatch && $isTypeMatch) {
                        // Idempotent hit: return rekaman yang sudah ada
                        return [
                            'message'     => $existing,
                            'is_replayed' => true,
                            'room_uuid'   => $lockedRoom->room_uuid,
                        ];
                    }

                    // Konflik idempotensi: UUID sama tetapi payload / metadata kriptografis berbeda
                    throw new BchatMessageException(
                        'Benturan idempotensi: message_uuid sudah terdaftar dengan payload atau metadata berbeda.',
                        'MESSAGE_UUID_CONFLICT',
                        409
                    );
                }

                // C. Validasi Epoch terhadap State Room
                // Di MLS RFC 9420, pesan aplikasi dikirim pada epoch grup saat ini
                if ($epoch !== (int) $lockedRoom->current_epoch) {
                    throw new BchatMessageException(
                        "Epoch pesan [{$epoch}] tidak sesuai dengan epoch room saat ini [{$lockedRoom->current_epoch}].",
                        'EPOCH_MISMATCH',
                        422
                    );
                }

                // D. Alokasi Nomor Urut Monotonik (server_sequence) per Room
                $maxSeq = BchatMessage::where('room_id', $lockedRoom->id)
                    ->lockForUpdate()
                    ->max('server_sequence');

                $nextSequence = ($maxSeq !== null) ? (int) $maxSeq + 1 : 1;

                // E. Simpan Ciphertext secara Atomik
                $message = BchatMessage::create([
                    'room_id'              => $lockedRoom->id,
                    'server_sequence'      => $nextSequence,
                    'message_uuid'         => $cleanMsgUuid,
                    'sender_id'            => $sender->id,
                    'epoch'                => $epoch,
                    'generation'           => $generation,
                    'content_type'         => $contentType,
                    'ciphertext_payload'   => $rawCiphertext,
                    'payload_hash_sha256'  => $cleanHash,
                    'client_sent_at'       => $clientSentAt,
                ]);

                // Update sequence pada room
                $lockedRoom->current_sequence = $nextSequence;
                $lockedRoom->save();

                return [
                    'message'     => $message,
                    'is_replayed' => false,
                    'room_uuid'   => $lockedRoom->room_uuid,
                ];
            }, 3);

            // F. Siarkan Event Sinyal Reverb (After-Commit) di luar blok transaksi
            // Jika Reverb daemon offline, log warning dan jangan gagalkan commit database
            if (!$result['is_replayed']) {
                try {
                    event(new BchatMessageSent(
                        roomUuid: $result['room_uuid'],
                        serverSequence: (int) $result['message']->server_sequence,
                        messageUuid: $result['message']->message_uuid,
                        senderId: (int) $sender->id,
                        createdAt: $result['message']->created_at?->toIso8601String() ?? now()->toIso8601String()
                    ));
                } catch (Throwable $broadcastErr) {
                    Log::warning('Sinyal Reverb BchatMessageSent tidak dapat disiarkan (daemon offline/unreachable): ' . $broadcastErr->getMessage());
                }
            }

            return [
                'message'     => $result['message'],
                'is_replayed' => $result['is_replayed'],
            ];
        } catch (BchatMessageException $e) {
            throw $e;
        } catch (Throwable $e) {
            throw new BchatMessageException(
                'Terjadi kesalahan internal saat menyimpan pesan E2EE: ' . $e->getMessage(),
                'INTERNAL_MESSAGE_STORAGE_ERROR',
                500,
                $e
            );
        }
    }

    /**
     * Mengambil daftar pesan ciphertext pada ruang obrolan dengan cursor pagination.
     *
     * Invarian:
     * 1. Authorization: Memvalidasi pengguna berhak membaca pesan via BchatMessagePolicy::viewAny().
     * 2. Cursor Pagination: Menggunakan server_sequence ASC (> after_sequence).
     * 3. Limit Aman: Maksimal 100 pesan per halaman.
     *
     * @param User $user Pengguna yang meminta histori
     * @param string $roomUuid UUID publik ruang obrolan
     * @param int|null $afterSequence Nomor urut terakhir yang sudah dimiliki klien
     * @param int $limit Batas jumlah rekaman (default 50, max 100)
     * @return Collection<int, BchatMessage>
     *
     * @throws BchatMessageException Jika room tidak ditemukan atau akses ditolak
     */
    public function getMessages(
        User $user,
        string $roomUuid,
        ?int $afterSequence = null,
        int $limit = 50
    ): Collection {
        $cleanRoomUuid = strtolower(trim($roomUuid));
        if (!Str::isUuid($cleanRoomUuid)) {
            throw new BchatMessageException(
                'Format Room UUID tidak valid.',
                'INVALID_ROOM_UUID',
                422
            );
        }

        $room = BchatRoom::where('room_uuid', $cleanRoomUuid)->first();
        if (!$room) {
            throw new BchatMessageException(
                'Ruang obrolan tidak ditemukan.',
                'ROOM_NOT_FOUND',
                404
            );
        }

        if (!$user->can('viewAny', [BchatMessage::class, $room])) {
            throw new BchatMessageException(
                'Pengguna tidak memiliki izin untuk membaca pesan pada ruang obrolan ini.',
                'FORBIDDEN_CANNOT_READ_MESSAGES',
                403
            );
        }

        $boundedLimit = max(1, min(100, $limit));

        $query = BchatMessage::where('room_id', $room->id)
            ->orderBy('server_sequence', 'asc')
            ->limit($boundedLimit);

        if ($afterSequence !== null && $afterSequence >= 0) {
            $query->where('server_sequence', '>', $afterSequence);
        }

        return $query->get();
    }
}
