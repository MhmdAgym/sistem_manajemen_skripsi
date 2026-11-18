<?php

namespace App\Http\Resources\Bchat;

use App\Models\BchatHistoryTransfer;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin BchatHistoryTransfer
 */
class BchatHistoryTransferResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'transfer_uuid'                  => $this->transfer_uuid,
            'room_uuid'                      => $this->room_uuid,
            'recipient_device_uuid'          => $this->recipient_device_uuid,
            'source_device_uuid'             => $this->source_device_uuid,
            'key_exchange_algorithm'         => $this->key_exchange_algorithm,
            'recipient_ephemeral_public_key' => $this->recipient_ephemeral_public_key,
            'source_ephemeral_public_key'    => $this->source_ephemeral_public_key,
            'transfer_nonce'                 => $this->transfer_nonce,
            'status'                         => $this->status,
            'snapshot_hash_sha256'           => $this->snapshot_hash_sha256,
            'snapshot_iv'                    => $this->snapshot_iv,
            'snapshot_auth_tag'              => $this->snapshot_auth_tag,
            'snapshot_metadata'              => $this->snapshot_metadata,
            'uploaded_at'                    => $this->uploaded_at?->toIso8601String(),
            'completed_at'                   => $this->completed_at?->toIso8601String(),
            'cancelled_at'                   => $this->cancelled_at?->toIso8601String(),
            'cancellation_reason'            => $this->cancellation_reason,
            'expires_at'                     => $this->expires_at?->toIso8601String(),
            'created_at'                     => $this->created_at?->toIso8601String(),
        ];
    }
}

