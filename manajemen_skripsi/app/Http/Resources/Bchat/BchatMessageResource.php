<?php

declare(strict_types=1);

namespace App\Http\Resources\Bchat;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Resource representasi JSON untuk rekaman pesan aplikasi B-CHAT E2EE.
 *
 * @mixin \App\Models\BchatMessage
 */
class BchatMessageResource extends JsonResource
{
    /**
     * Transformasi model rekaman pesan ke array representasi API.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        // Pastikan ciphertext biner (longblob) dikonversi ke Base64 kanonikal
        $rawCiphertext = $this->ciphertext_payload;
        $ciphertextBase64 = is_string($rawCiphertext) ? base64_encode($rawCiphertext) : '';

        return [
            'server_sequence'     => (int) $this->server_sequence,
            'message_uuid'        => (string) $this->message_uuid,
            'sender_id'           => (int) $this->sender_id,
            'epoch'               => (int) $this->epoch,
            'generation'          => (int) $this->generation,
            'content_type'        => (int) $this->content_type,
            'ciphertext_payload'  => $ciphertextBase64,
            'payload_hash_sha256' => (string) $this->payload_hash_sha256,
            'session_uuid'        => $this->session?->session_uuid,
            'session_generation'  => $this->session?->generation_number,
            'client_sent_at'      => $this->client_sent_at?->toIso8601String(),
            'created_at'          => $this->created_at?->toIso8601String(),
        ];
    }
}

