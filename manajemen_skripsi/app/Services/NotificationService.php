<?php

namespace App\Services;

use App\Events\AcademicNotificationBroadcasted;
use App\Notifications\AcademicNotification;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\Log;

class NotificationService
{
    /**
     * Kirim notifikasi akademik terpusat ke satu penerima secara idempoten.
     *
     * @param  Model  $notifiable  Model pengguna (User) penerima notifikasi
     * @param  string  $eventType  Kategori/jenis event (e.g. 'pengajuan_judul')
     * @param  string  $eventIdentity  Pengenal deterministik peristiwa (e.g. 'pengajuan_judul:1:submit:log_1')
     * @param  array  $payload  [title, message, url, metadata]
     * @return DatabaseNotification
     * @throws \InvalidArgumentException
     * @throws \RuntimeException
     * @throws UniqueConstraintViolationException
     */
    public function send(
        Model $notifiable,
        string $eventType,
        string $eventIdentity,
        array $payload
    ): DatabaseNotification {
        if (!method_exists($notifiable, 'notify')) {
            throw new \InvalidArgumentException('Penerima notifikasi harus menggunakan trait Notifiable.');
        }

        $notification = new AcademicNotification(
            $notifiable->getKey(),
            $eventType,
            $eventIdentity,
            $payload
        );

        return $this->sendNotification($notifiable, $notification);
    }

    /**
     * Kirim instance AcademicNotification langsung ke model penerima.
     *
     * @param  Model  $notifiable
     * @param  AcademicNotification  $notification
     * @return DatabaseNotification
     * @throws \RuntimeException
     * @throws UniqueConstraintViolationException
     */
    public function sendNotification(
        Model $notifiable,
        AcademicNotification $notification
    ): DatabaseNotification {
        if (!method_exists($notifiable, 'notify')) {
            throw new \InvalidArgumentException('Penerima notifikasi harus menggunakan trait Notifiable.');
        }

        try {
            $notifiable->notify($notification);

            $stored = DatabaseNotification::find($notification->id);
            if (!$stored) {
                throw new \RuntimeException("Gagal memuat notifikasi [{$notification->id}] setelah penyimpanan.");
            }

            // Siarkan event broadcasting hanya setelah record tersimpan di database
            $this->broadcastNotification($notifiable, $stored);

            return $stored;
        } catch (UniqueConstraintViolationException $e) {
            return $this->handleUniqueConstraintViolation($e, $notifiable, $notification);
        }
    }

    /**
     * Kirim notifikasi akademik terpusat ke banyak penerima secara idempoten.
     *
     * @param  iterable  $notifiables  Koleksi atau array model penerima
     * @param  string  $eventType
     * @param  string  $eventIdentity
     * @param  array  $payload
     * @return array<DatabaseNotification>
     */
    public function sendToMany(
        iterable $notifiables,
        string $eventType,
        string $eventIdentity,
        array $payload
    ): array {
        $results = [];
        $uniqueNotifiables = collect($notifiables)->unique(function ($n) {
            return $n instanceof Model ? $n->getKey() : (string) $n;
        });

        foreach ($uniqueNotifiables as $notifiable) {
            if ($notifiable instanceof Model) {
                $results[] = $this->send($notifiable, $eventType, $eventIdentity, $payload);
            }
        }

        return $results;
    }

    /**
     * Tangani benturan constraint unik MySQL secara presisi.
     *
     * @param  UniqueConstraintViolationException  $e
     * @param  Model  $notifiable
     * @param  AcademicNotification  $notification
     * @return DatabaseNotification
     * @throws UniqueConstraintViolationException
     * @throws \RuntimeException
     */
    protected function handleUniqueConstraintViolation(
        UniqueConstraintViolationException $e,
        Model $notifiable,
        AcademicNotification $notification
    ): DatabaseNotification {
        $errorMessage = $e->getMessage();
        $prev = $e->getPrevious();
        $isDriver1062 = false;

        if ($prev instanceof \PDOException && isset($prev->errorInfo[1]) && (int) $prev->errorInfo[1] === 1062) {
            $isDriver1062 = true;
        } elseif ($e->getCode() === 23000 || $e->getCode() === 1062) {
            $isDriver1062 = true;
        }

        $isPrimaryCollision = str_contains($errorMessage, $notification->id)
            && (str_contains($errorMessage, 'PRIMARY') || str_contains($errorMessage, 'notifications'));

        // Jika bukan duplicate key pada Primary Key notifications, lemparkan error asli
        if (!$isDriver1062 || !$isPrimaryCollision) {
            throw $e;
        }

        // Ambil record yang sudah tersimpan melalui Primary Key O(1)
        $existing = DatabaseNotification::find($notification->id);

        if (!$existing) {
            // Jika record tidak ditemukan (misal rollback paralel), lemparkan error asli
            throw $e;
        }

        // Validasi kesesuaian penerima menggunakan getMorphClass()
        $expectedMorphType = $notifiable->getMorphClass();
        $expectedMorphId   = (int) $notifiable->getKey();

        $isOwnerMatch = (
            $existing->notifiable_type === $expectedMorphType &&
            (int) $existing->notifiable_id === $expectedMorphId
        );

        $existingData = is_array($existing->data) ? $existing->data : json_decode($existing->data, true);

        // Validasi identitas peristiwa bisnis
        $isEventMatch = (
            isset($existingData['event_identity']) &&
            $existingData['event_identity'] === $notification->eventIdentity &&
            isset($existingData['event_type']) &&
            $existingData['event_type'] === $notification->eventType
        );

        if ($isOwnerMatch && $isEventMatch) {
            Log::info("Idempotent notification bypass for ID [{$notification->id}] on [{$expectedMorphType}:{$expectedMorphId}].");
            return $existing;
        }

        // Jika UUID sama tetapi isi atau pemilik berbeda: Anomali integritas/benturan hash
        Log::emergency("CRITICAL NOTIFICATION INTEGRITY ANOMALY for ID [{$notification->id}]", [
            'expected_type'  => $expectedMorphType,
            'existing_type'  => $existing->notifiable_type,
            'expected_id'    => $expectedMorphId,
            'existing_id'    => $existing->notifiable_id,
            'expected_event' => $notification->eventIdentity,
            'existing_event' => $existingData['event_identity'] ?? null,
        ]);

        throw new \RuntimeException(
            "Benturan integritas notifikasi terdeteksi pada ID [{$notification->id}]. Record yang sudah ada tidak cocok dengan penerima atau identitas peristiwa bisnis yang diharapkan."
        );
    }

    /**
     * Siarkan notifikasi yang baru dibuat ke kanal WebSocket secara aman.
     *
     * @param  Model  $notifiable
     * @param  DatabaseNotification  $stored
     * @return void
     */
    protected function broadcastNotification(Model $notifiable, DatabaseNotification $stored): void
    {
        try {
            // Siarkan event untuk record baru yang berhasil disimpan
            event(new AcademicNotificationBroadcasted(
                $notifiable->getKey(),
                $stored
            ));
        } catch (\Throwable $e) {
            Log::error("Gagal menyiarkan broadcast notifikasi [{$stored->id}] untuk penerima [{$notifiable->getKey()}]: " . $e->getMessage(), [
                'exception'       => $e,
                'notification_id' => $stored->id,
                'notifiable_id'   => $notifiable->getKey(),
            ]);
        }
    }
}
