<?php

declare(strict_types=1);

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Event siaran WebSocket Reverb saat Commit MLS baru berhasil diterbitkan untuk relay.
 *
 * Mengimplementasikan prinsip:
 * 1. After-Commit: Hanya disiarkan jika transaksi basis data berhasil di-commit.
 * 2. Thin Signal: Hanya memuat metadata non-sensitif (room_uuid, commit_uuid, from_epoch, to_epoch, commit_hash).
 *    Isi wireformat biner diambil melalui endpoint REST terotentikasi.
 */
class BchatCommitPublished implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public bool $afterCommit = true;

    public function __construct(
        public readonly string $roomUuid,
        public readonly string $commitUuid,
        public readonly int $fromEpoch,
        public readonly int $toEpoch,
        public readonly string $commitHash,
        public readonly ?string $addedDeviceUuid,
        public readonly string $createdAt
    ) {
    }

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel("bchat.rooms.{$this->roomUuid}"),
        ];
    }

    public function broadcastAs(): string
    {
        return 'bchat.commit.published';
    }

    public function broadcastWith(): array
    {
        return [
            'room_uuid'         => $this->roomUuid,
            'commit_uuid'       => $this->commitUuid,
            'from_epoch'        => (string) $this->fromEpoch,
            'to_epoch'          => (string) $this->toEpoch,
            'commit_hash'       => $this->commitHash,
            'added_device_uuid' => $this->addedDeviceUuid,
            'created_at'        => $this->createdAt,
        ];
    }
}

