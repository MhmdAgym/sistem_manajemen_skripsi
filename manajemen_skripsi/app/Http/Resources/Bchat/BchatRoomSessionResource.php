<?php

declare(strict_types=1);

namespace App\Http\Resources\Bchat;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Resource representasi JSON untuk sesi generasi ruang obrolan B-CHAT E2EE.
 *
 * @mixin \App\Models\BchatRoomSession
 */
class BchatRoomSessionResource extends JsonResource
{
    /**
     * Transformasi model rekaman sesi ke array representasi API.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'session_uuid'       => (string) $this->session_uuid,
            'room_uuid'          => (string) ($this->room?->room_uuid ?? ''),
            'generation_number'  => (int) $this->generation_number,
            'status'             => (string) $this->status,
            'group_id_hash'      => $this->group_id_hash ? (string) $this->group_id_hash : null,
            'start_epoch'        => (int) $this->start_epoch,
            'end_epoch'          => $this->end_epoch !== null ? (int) $this->end_epoch : null,
            'start_sequence'     => (int) $this->start_sequence,
            'end_sequence'       => $this->end_sequence !== null ? (int) $this->end_sequence : null,
            'current_sequence'   => (int) $this->current_sequence,
            'created_by_user_id' => $this->created_by_user_id !== null ? (int) $this->created_by_user_id : null,
            'activated_at'       => $this->activated_at?->toIso8601String(),
            'archived_at'        => $this->archived_at?->toIso8601String(),
            'failed_at'          => $this->failed_at?->toIso8601String(),
            'failure_reason'     => $this->failure_reason ? (string) $this->failure_reason : null,
            'created_at'         => $this->created_at?->toIso8601String(),
            'updated_at'         => $this->updated_at?->toIso8601String(),
        ];
    }
}

