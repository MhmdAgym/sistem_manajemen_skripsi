<?php

declare(strict_types=1);

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Event siaran WebSocket Reverb saat ruang obrolan B-CHAT berhasil diaktifkan secara atomik.
 *
 * Mengimplementasikan prinsip:
 * 1. After-Commit: Hanya disiarkan jika transaksi basis data aktivasi atomik berhasil di-commit.
 * 2. Thin Signal: Hanya memuat metadata non-sensitif (room_uuid, status, current_epoch, activated_at).
 *    Tidak pernah memuat Welcome message, ciphertext, nonce, signature, atau kunci privat.
 * 3. REST Source of Truth: Menjadi sinyal pemicu bagi frontend untuk me-refresh data via API REST.
 */
class BchatRoomActivated implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * Memastikan broadcast hanya diproses setelah transaksi basis data berhasil commit.
     */
    public bool $afterCommit = true;

    /**
     * @param string $roomUuid UUID v4 ruang obrolan yang diaktifkan
     * @param int $currentEpoch Epoch grup MLS aktif saat ini (tepat 1)
     * @param string $roomStatus Status baru room ('ACTIVE')
     * @param array<int, int|string> $memberUserIds Daftar user_id anggota pairwise yang diaktifkan
     * @param string $activatedAt Waktu server saat aktivasi terjadi (ISO 8601 string)
     */
    public function __construct(
        public readonly string $roomUuid,
        public readonly int $currentEpoch,
        public readonly string $roomStatus,
        public readonly array $memberUserIds,
        public readonly string $activatedAt
    ) {
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
        return 'room.activated';
    }

    /**
     * Payload minimum yang aman untuk disiarkan via WebSocket.
     *
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'room_uuid'     => $this->roomUuid,
            'room_status'   => $this->roomStatus,
            'current_epoch' => (string) $this->currentEpoch,
            'activated_at'  => $this->activatedAt,
            'member_ids'    => $this->memberUserIds,
        ];
    }
}

