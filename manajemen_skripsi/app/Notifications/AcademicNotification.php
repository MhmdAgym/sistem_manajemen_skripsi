<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;
use Ramsey\Uuid\Uuid;

class AcademicNotification extends Notification
{
    use Queueable;

    public string $eventIdentity;
    public string $eventType;
    public string $title;
    public string $message;
    public string $url;
    public array $metadata;

    /**
     * Buat instance notifikasi akademik baru dengan ID deterministik RFC 4122 v5.
     *
     * @param  int|string  $recipientUserId
     * @param  string  $eventType
     * @param  string  $eventIdentity
     * @param  array  $payload  [title, message, url, metadata]
     * @throws \InvalidArgumentException
     */
    public function __construct(
        int|string $recipientUserId,
        string $eventType,
        string $eventIdentity,
        array $payload
    ) {
        $eventIdentity = trim($eventIdentity);
        if (empty($eventIdentity)) {
            throw new \InvalidArgumentException('event_identity wajib diisi dan tidak boleh kosong.');
        }

        $eventType = trim($eventType);
        if (empty($eventType)) {
            throw new \InvalidArgumentException('event_type wajib diisi dan tidak boleh kosong.');
        }

        $title = trim($payload['title'] ?? '');
        if (empty($title)) {
            throw new \InvalidArgumentException('title notifikasi wajib diisi.');
        }

        $message = trim($payload['message'] ?? '');
        if (empty($message)) {
            throw new \InvalidArgumentException('message notifikasi wajib diisi.');
        }

        $url = trim($payload['url'] ?? '/');
        // Validasi keamanan: url wajib path internal (diawali '/') dan bukan protocol-relative '//', '/\', atau absolut 'http(s)://'
        if (!str_starts_with($url, '/') || str_starts_with($url, '//') || str_starts_with($url, '/\\') || str_contains($url, '://')) {
            throw new \InvalidArgumentException("URL notifikasi harus berupa path relatif internal yang diawali dengan '/'");
        }

        $this->eventIdentity = $eventIdentity;
        $this->eventType     = $eventType;
        $this->title         = $title;
        $this->message       = $message;
        $this->url           = $url;
        $this->metadata      = is_array($payload['metadata'] ?? null) ? $payload['metadata'] : [];

        // Seed kanonis deterministik per penerima dan per peristiwa bisnis
        $canonicalSeed = "unuha:notification:v1:user:{$recipientUserId}:{$eventIdentity}";

        // ID deterministik RFC 4122 v5 berbasis NAMESPACE_OID
        $this->id = Uuid::uuid5(Uuid::NAMESPACE_OID, $canonicalSeed)->toString();
    }

    /**
     * Tentukan kanal transmisi notifikasi (Hanya database untuk N4).
     *
     * @param  mixed  $notifiable
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /**
     * Bentuk muatan data terstruktur untuk tabel database notifications.
     *
     * @param  mixed  $notifiable
     * @return array<string, mixed>
     */
    public function toDatabase(object $notifiable): array
    {
        return [
            'event_identity' => $this->eventIdentity,
            'event_type'     => $this->eventType,
            'title'          => $this->title,
            'message'        => $this->message,
            'url'            => $this->url,
            'metadata'       => $this->metadata,
        ];
    }

    /**
     * Representasi array umum notifikasi.
     *
     * @param  mixed  $notifiable
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return $this->toDatabase($notifiable);
    }
}
