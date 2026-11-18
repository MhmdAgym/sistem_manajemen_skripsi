<?php

declare(strict_types=1);

namespace App\Http\Resources\Bchat;

use App\Models\BchatRoom;
use App\Models\BchatRoomMember;
use App\Models\BchatRoomSession;
use App\Models\BchatWelcomeChallenge;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Resource untuk detail metadata ruang obrolan (GET /api/bchat/rooms/{roomUuid}).
 * Menyediakan konteks status handshake, kapabilitas otorisasi Policy aktual, dan metadata aman.
 */
class BchatRoomDetailResource extends JsonResource
{
    protected User $user;
    protected bool $hasActiveDevice;
    protected bool $hasPartnerKeyPackage;

    public function __construct(
        BchatRoom $resource,
        User $user,
        bool $hasActiveDevice = false,
        bool $hasPartnerKeyPackage = false
    ) {
        parent::__construct($resource);
        $this->user = $user;
        $this->hasActiveDevice = $hasActiveDevice;
        $this->hasPartnerKeyPackage = $hasPartnerKeyPackage;
    }

    public function toArray(Request $request): array
    {
        /** @var BchatRoom $room */
        $room = $this->resource;

        $members = $room->members;
        $currentMember = $members->first(fn ($m) => (int) $m->user_id === (int) $this->user->id);
        $partnerMember = $members->first(fn ($m) => (int) $m->user_id !== (int) $this->user->id);

        $penugasan = $room->penugasanPembimbing;

        // Ambil welcome challenge aktif jika ada dari relasi yang sudah di-load
        $activeChallenges = $room->welcomeChallenges
            ? $room->welcomeChallenges->where('operation_status', 'ACTIVE')
            : collect();

        $activeChallenge = $activeChallenges->first();

        $pendingSession = $room->relationLoaded('pendingSession')
            ? $room->pendingSession
            : $room->pendingSession()->first();

        $pendingSessionChallenge = null;
        if ($pendingSession !== null) {
            $pendingSessionChallenge = $activeChallenges->first(
                fn ($c) => (int) $c->session_id === (int) $pendingSession->id
            );
        }

        $actionHint = $this->resolveActionHint($room, $currentMember, $activeChallenge);
        $pendingSessionActionHint = $this->resolvePendingSessionActionHint(
            $room,
            $currentMember,
            $pendingSession,
            $pendingSessionChallenge
        );

        // Group ID Hash hanya boleh tampil setelah Welcome dipublikasikan atau room aktif
        $groupIdHash = null;
        if ($room->status === 'ACTIVE') {
            $groupIdHash = $room->group_id_hash;
        } elseif ($activeChallenge !== null) {
            $groupIdHash = $activeChallenge->group_id_hash ?? $room->group_id_hash;
        }

        return [
            'room_uuid'     => (string) $room->room_uuid,
            'status'        => (string) $room->status,
            'tahap'         => (string) $room->tahap,
            'pembimbing_ke' => (int) $room->pembimbing_ke,
            'current_epoch' => (string) $room->current_epoch,
            'group_id_hash' => $groupIdHash,
            'my_membership' => [
                'role'            => $currentMember ? (string) $currentMember->role : null,
                'is_initiator'    => $currentMember ? (bool) $currentMember->is_initiator : false,
                'is_active'       => $currentMember ? (bool) $currentMember->is_active : false,
                'joined_at_epoch' => $currentMember ? (int) $currentMember->joined_at_epoch : 0,
            ],
            'partner'       => [
                'name'              => $partnerMember?->user?->name ?? 'Mitra Bimbingan',
                'role'              => $partnerMember ? (string) $partnerMember->role : null,
                'has_active_device' => $this->hasActiveDevice,
                'has_key_package'   => $this->hasPartnerKeyPackage,
                'is_active'         => $partnerMember ? (bool) $partnerMember->is_active : false,
            ],
            'assignment'    => [
                'status_penugasan' => $penugasan ? (string) $penugasan->status_penugasan : null,
                'judul_skripsi'    => (string) ($penugasan?->skripsi?->judul ?? ''),
                'tanggal_mulai'    => $penugasan?->tanggal_mulai?->toDateString(),
            ],
            'action_hint'   => $actionHint,
            'handshake_context' => [
                'has_active_challenge'   => $activeChallenge !== null,
                'welcome_operation_uuid' => $activeChallenge?->welcome_operation_uuid,
                'epoch'                  => $activeChallenge ? (string) $activeChallenge->epoch : null,
                'expires_at'             => $activeChallenge?->expires_at?->toIso8601String(),
                'action_required'        => $actionHint['action_required'],
                'action_type'            => $actionHint['action_type'],
            ],
            'capabilities'  => [
                'can_send_messages' => (bool) $this->user->can('sendMessage', $room),
                'can_read_messages' => (bool) $this->user->can('readMessages', $room),
                'can_handshake'     => (bool) $this->user->can('handshake', $room),
            ],
            'active_session' => $room->activeSession ? [
                'session_uuid'      => (string) $room->activeSession->session_uuid,
                'generation_number' => (int) $room->activeSession->generation_number,
                'status'            => (string) $room->activeSession->status,
                'start_sequence'    => (int) $room->activeSession->start_sequence,
                'current_sequence'  => (int) $room->activeSession->current_sequence,
            ] : null,
            'pending_session' => $pendingSession ? [
                'session_uuid'           => (string) $pendingSession->session_uuid,
                'generation_number'      => (int) $pendingSession->generation_number,
                'status'                 => (string) $pendingSession->status,
                'start_sequence'         => (int) $pendingSession->start_sequence,
                'current_sequence'       => (int) $pendingSession->current_sequence,
                'created_by_user_id'     => $pendingSession->created_by_user_id !== null ? (int) $pendingSession->created_by_user_id : null,
                'has_active_challenge'   => $pendingSessionChallenge !== null,
                'welcome_operation_uuid' => $pendingSessionChallenge?->welcome_operation_uuid,
                'recipient_device_uuid'  => $pendingSessionChallenge?->recipientDevice?->device_uuid,
                'expires_at'             => $pendingSessionChallenge?->expires_at?->toIso8601String(),
                'action_required'        => $pendingSessionActionHint['action_required'],
                'action_type'            => $pendingSessionActionHint['action_type'],
            ] : null,
            'created_at'    => $room->created_at?->toIso8601String(),
            'updated_at'    => $room->updated_at?->toIso8601String(),
        ];
    }

    /**
     * Menentukan action hint untuk sesi generasi baru berstatus PENDING pada room ACTIVE.
     */
    private function resolvePendingSessionActionHint(
        BchatRoom $room,
        ?BchatRoomMember $currentMember,
        ?BchatRoomSession $pendingSession,
        ?BchatWelcomeChallenge $pendingChallenge
    ): array {
        if (!$currentMember || !$pendingSession || $room->status !== 'ACTIVE') {
            return [
                'action_required' => false,
                'action_type'     => 'NONE',
            ];
        }

        if ($pendingChallenge === null) {
            if ($currentMember->role === 'mahasiswa') {
                return [
                    'action_required' => true,
                    'action_type'     => 'PUBLISH_SESSION_WELCOME',
                ];
            }

            return [
                'action_required' => false,
                'action_type'     => 'AWAIT_PARTNER_SESSION_WELCOME',
            ];
        }

        $isRecipient = (int) $pendingChallenge->recipient_user_id === (int) $currentMember->user_id;
        if ($isRecipient) {
            return [
                'action_required' => true,
                'action_type'     => 'FETCH_SESSION_CHALLENGE_AND_ACK',
            ];
        }

        return [
            'action_required' => false,
            'action_type'     => 'AWAIT_PARTNER_SESSION_ACK',
        ];
    }

    /**
     * Menentukan action hint secara deterministik berdasarkan status room,
     * keanggotaan pengguna, dan status operasi Welcome aktif.
     */
    private function resolveActionHint(BchatRoom $room, ?BchatRoomMember $currentMember, ?BchatWelcomeChallenge $activeChallenge): array
    {
        if (!$currentMember) {
            return [
                'action_required' => false,
                'action_type'     => 'NONE',
            ];
        }

        if ($room->status === 'ACTIVE' || $room->status === 'ARCHIVED_READONLY') {
            return [
                'action_required' => false,
                'action_type'     => 'NONE',
            ];
        }

        if ($room->status === 'PENDING_WELCOME_ACK') {
            if ($activeChallenge === null) {
                // Sebelum Welcome aktif: Mahasiswa adalah inisiator default, Dosen penerima default
                if ($currentMember->role === 'mahasiswa') {
                    return [
                        'action_required' => true,
                        'action_type'     => 'PUBLISH_WELCOME',
                    ];
                }

                return [
                    'action_required' => false,
                    'action_type'     => 'AWAIT_PARTNER_WELCOME',
                ];
            }

            // Setelah Welcome aktif: tentukan peran berdasarkan data aktual active challenge
            $isRecipient = (int) $activeChallenge->recipient_user_id === (int) $currentMember->user_id;

            if ($isRecipient) {
                return [
                    'action_required' => true,
                    'action_type'     => 'FETCH_CHALLENGE_AND_ACK',
                ];
            }

            return [
                'action_required' => false,
                'action_type'     => 'AWAIT_PARTNER_ACK',
            ];
        }

        return [
            'action_required' => false,
            'action_type'     => 'NONE',
        ];
    }
}

