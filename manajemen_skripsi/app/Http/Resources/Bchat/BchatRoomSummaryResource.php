<?php

declare(strict_types=1);

namespace App\Http\Resources\Bchat;

use App\Models\BchatRoom;
use App\Models\BchatRoomMember;
use App\Models\BchatWelcomeChallenge;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Resource untuk ringkasan ruang obrolan pada daftar (GET /api/bchat/rooms).
 * Menggunakan allowlist field eksplisit tanpa mengekspos rahasia internal atau material kriptografi.
 */
class BchatRoomSummaryResource extends JsonResource
{
    protected User $user;

    /**
     * @var array<int> Daftar user_id partner yang memiliki perangkat ACTIVE (mencegah N+1)
     */
    protected array $activeDeviceUserIds;

    public function __construct(BchatRoom $resource, User $user, array $activeDeviceUserIds = [])
    {
        parent::__construct($resource);
        $this->user = $user;
        $this->activeDeviceUserIds = $activeDeviceUserIds;
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
        $activeChallenge = $room->welcomeChallenges
            ? $room->welcomeChallenges->firstWhere('operation_status', 'ACTIVE')
            : null;

        $partnerUserId = $partnerMember ? (int) $partnerMember->user_id : null;
        $partnerRole = $partnerMember ? (string) $partnerMember->role : null;
        $hasActiveDevice = $partnerUserId !== null && in_array($partnerUserId, $this->activeDeviceUserIds, true);

        $partnerMahasiswaId = null;
        $partnerNim = null;
        if ($partnerRole === 'mahasiswa') {
            $rawMahasiswaId = $penugasan?->skripsi?->id_mahasiswa
                ?? $partnerMember?->user?->mahasiswa?->id_mahasiswa;
            $partnerMahasiswaId = $rawMahasiswaId !== null ? (int) $rawMahasiswaId : null;

            $rawNim = $partnerMember?->user?->mahasiswa?->NIM
                ?? $penugasan?->skripsi?->mahasiswa?->NIM
                ?? $partnerMember?->user?->nomor_induk;
            $partnerNim = ($rawNim !== null && trim((string) $rawNim) !== '')
                ? trim((string) $rawNim)
                : null;
        }

        $actionHint = $this->resolveActionHint($room, $currentMember, $activeChallenge);

        return [
            'room_uuid'     => (string) $room->room_uuid,
            'status'        => (string) $room->status,
            'tahap'         => (string) $room->tahap,
            'pembimbing_ke' => (int) $room->pembimbing_ke,
            'current_epoch' => (string) $room->current_epoch,
            'my_role'       => $currentMember ? (string) $currentMember->role : null,
            'is_active'     => $currentMember ? (bool) $currentMember->is_active : false,
            'is_initiator'  => $currentMember ? (bool) $currentMember->is_initiator : false,
            'partner'       => [
                'user_id'           => $partnerUserId,
                'mahasiswa_id'      => $partnerMahasiswaId,
                'nim'               => $partnerNim,
                'name'              => $partnerMember?->user?->name ?? 'Mitra Bimbingan',
                'role'              => $partnerRole,
                'has_active_device' => $hasActiveDevice,
            ],
            'assignment'    => [
                'status_penugasan' => $penugasan ? (string) $penugasan->status_penugasan : null,
                'judul_skripsi'    => (string) ($penugasan?->skripsi?->judul ?? ''),
            ],
            'action_hint'   => $actionHint,
            'created_at'    => $room->created_at?->toIso8601String(),
            'updated_at'    => $room->updated_at?->toIso8601String(),
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

