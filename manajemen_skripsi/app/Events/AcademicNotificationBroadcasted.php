<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Queue\SerializesModels;

class AcademicNotificationBroadcasted implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * Pastikan broadcast hanya diproses setelah transaksi basis data berhasil commit.
     */
    public bool $afterCommit = true;

    public int|string $userId;
    public string $notificationId;
    public string $eventIdentity;
    public string $eventType;
    public string $title;
    public string $message;
    public string $url;
    public array $metadata;
    public string $createdAt;

    /**
     * Buat instance event broadcast baru dari record notifikasi database.
     *
     * @param  int|string  $userId
     * @param  DatabaseNotification  $notification
     */
    public function __construct(int|string $userId, DatabaseNotification $notification)
    {
        $this->userId         = $userId;
        $this->notificationId = (string) $notification->id;

        $data = is_array($notification->data)
            ? $notification->data
            : (json_decode($notification->data, true) ?: []);

        $this->eventIdentity = (string) ($data['event_identity'] ?? '');
        $this->eventType     = (string) ($data['event_type'] ?? '');
        $this->title         = (string) ($data['title'] ?? '');
        $this->message       = (string) ($data['message'] ?? '');
        $this->url           = (string) ($data['url'] ?? '/');
        $this->metadata      = is_array($data['metadata'] ?? null) ? $data['metadata'] : [];
        $this->createdAt     = $notification->created_at ? $notification->created_at->toIso8601String() : now()->toIso8601String();
    }

    /**
     * Dapatkan private channel per pengguna tempat event disiarkan.
     *
     * @return array<int, \Illuminate\Broadcasting\Channel>
     */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel("users.{$this->userId}.notifications"),
        ];
    }

    /**
     * Nama event siaran yang dikonsumsi oleh Laravel Echo di frontend.
     */
    public function broadcastAs(): string
    {
        return 'academic.notification';
    }

    /**
     * Data payload minimum yang aman untuk disiarkan ke klien via WebSocket.
     *
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'id'             => $this->notificationId,
            'event_identity' => $this->eventIdentity,
            'event_type'     => $this->eventType,
            'title'          => $this->title,
            'message'        => $this->message,
            'url'            => $this->url,
            'metadata'       => $this->metadata,
            'created_at'     => $this->createdAt,
        ];
    }
}
