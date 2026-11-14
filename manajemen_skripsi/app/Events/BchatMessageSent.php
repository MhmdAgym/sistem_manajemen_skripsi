<?php

declare(strict_types=1);

namespace App\Events;

use App\Models\BchatMessage;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Event siaran WebSocket Reverb saat pesan aplikasi E2EE berhasil disimpan di server.
 *
 * Invarian B-CHAT-09:
 * 1. After-Commit: Hanya disiarkan setelah transaksi basis data berhasil commit secara absolut.
 * 2. Thin Signal: HANYA membawa metadata sinyal non-sensitif (room_uuid, server_sequence, message_uuid, sender_id, created_at).
 *    DILARANG memuat ciphertext, plaintext, payload_hash, filename, atau material kriptografi.
 * 3. REST Source of Truth: Menjadi sinyal pemicu bagi peramban penerima untuk mengambil ciphertext via REST API.
 */
class BchatMessageSent implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * Memastikan broadcast dieksekusi secara terkontrol pasca-transaksi (handled via BchatMessageService).
     */
    public bool $afterCommit = false;

    /**
     * @param string $roomUuid UUID v4 ruang obrolan tempat pesan dikirim
     * @param int $serverSequence Nomor urut kedatangan server monotonik per-room
     * @param string $messageUuid UUID v4 pesan yang dibangkitkan klien
     * @param int $senderId ID pengguna pengirim pesan
     * @param string $createdAt Waktu server saat pesan disimpan (ISO 8601)
     */
    public function __construct(
        public readonly string $roomUuid,
        public readonly int $serverSequence,
        public readonly string $messageUuid,
        public readonly int $senderId,
        public readonly string $createdAt
    ) {
    }

    /**
     * Factory method dari instans BchatMessage dan roomUuid.
     */
    public static function fromMessage(BchatMessage $message, string $roomUuid): self
    {
        return new self(
            roomUuid: $roomUuid,
            serverSequence: (int) $message->server_sequence,
            messageUuid: $message->message_uuid,
            senderId: (int) $message->sender_id,
            createdAt: $message->created_at?->toIso8601String() ?? now()->toIso8601String()
        );
    }

    /**
     * Saluran privat siaran Reverb untuk ruang obrolan ini.
     *
     * @return array<int, \Illuminate\Broadcasting\Channel>
     */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel("bchat.rooms.{$this->roomUuid}"),
        ];
    }

    /**
     * Nama event siaran yang dikonsumsi oleh Laravel Echo di frontend.
     */
    public function broadcastAs(): string
    {
        return 'message.sent';
    }

    /**
     * Payload minimum yang aman untuk disiarkan via WebSocket (Thin Signal).
     *
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'room_uuid'       => $this->roomUuid,
            'server_sequence' => $this->serverSequence,
            'message_uuid'    => $this->messageUuid,
            'sender_id'       => $this->senderId,
            'created_at'      => $this->createdAt,
        ];
    }
}
