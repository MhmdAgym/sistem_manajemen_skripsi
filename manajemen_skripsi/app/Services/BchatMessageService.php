<?php

declare(strict_types=1);

namespace App\Services;

use App\Events\BchatMessageSent;
use App\Exceptions\BchatMessageException;
use App\Models\BchatAttachment;
use App\Models\BchatCommit;
use App\Models\BchatMessage;
use App\Models\BchatRoom;
use App\Models\BchatRoomSession;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
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

        // Ekstraksi referensi lampiran (jika ada atau tipe manifest)
        $attachmentUuids = [];
        if (!empty($payload['attachment_uuids']) && is_array($payload['attachment_uuids'])) {
            $attachmentUuids = array_values(array_filter($payload['attachment_uuids'], fn ($u) => is_string($u) && trim($u) !== ''));
        } elseif (!empty($payload['attachment_uuid']) && is_string($payload['attachment_uuid']) && trim($payload['attachment_uuid']) !== '') {
            $attachmentUuids = [trim($payload['attachment_uuid'])];
        }
        $attachmentUuids = array_map(fn ($u) => strtolower(trim((string) $u)), $attachmentUuids);

        // Jika tipe ATTACHMENT_MANIFEST (2), wajib ada referensi lampiran
        if ($contentType === 2 && empty($attachmentUuids)) {
            throw new BchatMessageException(
                'Pesan manifest lampiran wajib menyertakan referensi minimal satu lampiran.',
                'MISSING_ATTACHMENT_REFERENCE',
                422
            );
        }

        // Validasi tidak ada referensi lampiran duplikat
        if (!empty($attachmentUuids) && count($attachmentUuids) !== count(array_unique($attachmentUuids))) {
            throw new BchatMessageException(
                'Daftar lampiran mengandung UUID duplikat.',
                'DUPLICATE_ATTACHMENT_REFERENCE',
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
                $clientSentAt,
                $attachmentUuids
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

                // A.1 Validasi Sesi Kriptografi MLS yang Aktif
                $activeSession = BchatRoomSession::where('room_id', $lockedRoom->id)
                    ->where('status', 'ACTIVE')
                    ->lockForUpdate()
                    ->first();

                if (!$activeSession) {
                    $hasAnySession = BchatRoomSession::where('room_id', $lockedRoom->id)->exists();
                    if ($hasAnySession) {
                        throw new BchatMessageException(
                            'Ruang obrolan tidak memiliki sesi kriptografi yang aktif.',
                            'NO_ACTIVE_CRYPTO_SESSION',
                            409
                        );
                    }

                    // Auto-create Generasi 1 aktif jika room legacy/fixture belum memiliki rekaman session
                    $activeSession = BchatRoomSession::create([
                        'session_uuid'       => (string) Str::uuid(),
                        'room_id'            => $lockedRoom->id,
                        'generation_number'  => 1,
                        'status'             => 'ACTIVE',
                        'group_id_hash'      => $lockedRoom->group_id_hash,
                        'start_epoch'        => 1,
                        'start_sequence'     => 1,
                        'current_sequence'   => (int) $lockedRoom->current_sequence,
                        'activated_at'       => now(),
                    ]);
                }

                // A.2 Validasi Gate Transisi Dua Fase (Two-Phase Commit Relay)
                // Jika ruang obrolan sedang dalam proses transisi keanggotaan/epoch (PENDING_ACKS),
                // pengiriman pesan DIBLOKIR secara fail-closed hingga seluruh perangkat memberikan ACK valid.
                if (Schema::hasTable('bchat_commits')) {
                    $pendingCommit = BchatCommit::where('room_id', $lockedRoom->id)
                        ->where('status', 'PENDING_ACKS')
                        ->first();

                    if ($pendingCommit) {
                        throw new BchatMessageException(
                            "Ruang obrolan sedang dalam proses transisi epoch [{$pendingCommit->from_epoch} -> {$pendingCommit->to_epoch}]. Pengiriman pesan ditangguhkan hingga seluruh anggota menyelesaikan sinkronisasi.",
                            'ROOM_TRANSITIONING_EPOCH',
                            409
                        );
                    }
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

                // D.1 Validasi Ketat Lampiran yang Direferensikan (jika ada)
                $attachmentsToBind = [];
                if (!empty($attachmentUuids)) {
                    foreach ($attachmentUuids as $attUuid) {
                        $attachment = BchatAttachment::where('attachment_uuid', $attUuid)
                            ->lockForUpdate()
                            ->first();

                        if (!$attachment) {
                            throw new BchatMessageException(
                                "Lampiran [{$attUuid}] tidak ditemukan pada sistem.",
                                'ATTACHMENT_NOT_FOUND',
                                404
                            );
                        }

                        // Validasi lampiran milik room yang sama
                        if ((int) $attachment->room_id !== (int) $lockedRoom->id) {
                            throw new BchatMessageException(
                                "Lampiran [{$attUuid}] bukan milik ruang obrolan ini.",
                                'ATTACHMENT_ROOM_MISMATCH',
                                403
                            );
                        }

                        // Validasi attachment.session_id sama persis dengan sesi aktif pesan saat ini
                        if ((int) $attachment->session_id !== (int) $activeSession->id) {
                            throw new BchatMessageException(
                                "Lampiran [{$attUuid}] tidak berasal dari sesi kriptografi yang sama dengan pesan (mismatch generasi).",
                                'ATTACHMENT_SESSION_MISMATCH',
                                409
                            );
                        }

                        // Validasi lampiran belum terikat ke pesan lain
                        if ($attachment->message_id !== null) {
                            throw new BchatMessageException(
                                "Lampiran [{$attUuid}] sudah terikat ke pesan lain.",
                                'ATTACHMENT_ALREADY_ATTACHED',
                                409
                            );
                        }

                        // Validasi status lampiran sah untuk dilampirkan
                        if (!in_array($attachment->status, ['PENDING_MESSAGE', 'ATTACHED'], true)) {
                            throw new BchatMessageException(
                                "Status lampiran [{$attUuid}] tidak valid untuk dilampirkan (status: {$attachment->status}).",
                                'INVALID_ATTACHMENT_STATUS',
                                422
                            );
                        }

                        $attachmentsToBind[] = $attachment;
                    }
                }

                // E. Simpan Ciphertext secara Atomik
                $message = BchatMessage::create([
                    'room_id'              => $lockedRoom->id,
                    'session_id'           => $activeSession->id,
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

                // E.1 Ikat message_id ke seluruh lampiran tervalidasi secara konsisten & atomik
                foreach ($attachmentsToBind as $att) {
                    $att->message_id = $message->id;
                    $att->status = 'ATTACHED';
                    $att->save();
                }

                // Update sequence pada room dan active session
                $lockedRoom->current_sequence = $nextSequence;
                $lockedRoom->save();

                $activeSession->current_sequence = $nextSequence;
                $activeSession->save();

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
        int $limit = 50,
        ?string $sessionUuid = null,
        ?int $generationNumber = null
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

        if ($sessionUuid !== null && trim($sessionUuid) !== '') {
            $cleanSessionUuid = strtolower(trim($sessionUuid));
            $query->whereHas('session', function ($q) use ($cleanSessionUuid) {
                $q->where('session_uuid', $cleanSessionUuid);
            });
        } elseif ($generationNumber !== null && $generationNumber > 0) {
            $query->whereHas('session', function ($q) use ($generationNumber) {
                $q->where('generation_number', $generationNumber);
            });
        }

        return $query->get();
    }
}
