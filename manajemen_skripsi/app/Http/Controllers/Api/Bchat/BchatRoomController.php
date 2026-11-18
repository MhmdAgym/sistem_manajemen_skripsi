<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Bchat;

use App\Http\Controllers\Controller;
use App\Http\Resources\Bchat\BchatRoomDetailResource;
use App\Http\Resources\Bchat\BchatRoomSummaryResource;
use App\Models\BchatRoom;
use App\Models\BchatUserDevice;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Throwable;

/**
 * Controller untuk Room Discovery & Role Metadata API (B-CHAT-22B Tahap 2C.1).
 *
 * Menyediakan endpoint read-only untuk:
 * - GET /api/bchat/rooms (Daftar ruang obrolan terotorisasi)
 * - GET /api/bchat/rooms/{roomUuid} (Detail metadata dan status handshake ruang obrolan)
 */
class BchatRoomController extends Controller
{
    /**
     * [GET /api/bchat/rooms]
     *
     * Mengambil daftar ruang obrolan yang dimiliki oleh pengguna terautentikasi.
     * Mengeliminasi status non-aktif (PENDING_OFFICIAL_SK, SUSPENDED, TERMINATED, DIBATALKAN)
     * dan menerapkan eager-loading anti-N+1.
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        if (!$user) {
            return response()->json([
                'status'     => 'error',
                'message'    => 'Pengguna belum terautentikasi.',
                'error_code' => 'UNAUTHENTICATED',
            ], 401);
        }

        if (!$user->can('viewAny', BchatRoom::class)) {
            return response()->json([
                'status'     => 'error',
                'message'    => 'Pengguna tidak memiliki izin untuk melihat daftar ruang obrolan.',
                'error_code' => 'FORBIDDEN',
            ], 403);
        }

        try {
            // Query room dasar milik user dengan filter status database awal
            $rooms = BchatRoom::with([
                'penugasanPembimbing.skripsi.mahasiswa',
                'members.user.mahasiswa',
                'welcomeChallenges' => function ($query) {
                    $query->where('operation_status', 'ACTIVE')->orderBy('id', 'asc');
                },
            ])
            ->whereHas('members', fn ($q) => $q->where('user_id', $user->id))
            ->whereNotIn('status', ['PENDING_OFFICIAL_SK', 'SUSPENDED', 'TERMINATED'])
            ->whereHas('penugasanPembimbing', fn ($q) => $q->where('status_penugasan', '!=', 'DIBATALKAN'))
            ->orderBy('updated_at', 'desc')
            ->get();

            // Saring ketat menggunakan Policy view() dan integritas keanggotaan (fail closed)
            $filteredRooms = $rooms->filter(function ($room) use ($user) {
                if (!$user->can('view', $room)) {
                    return false;
                }

                // Struktur pairwise wajib tepat 2 anggota
                if ($room->members->count() !== 2) {
                    return false;
                }

                $roles = $room->members->pluck('role')->sort()->values()->all();
                if ($roles !== ['dosen_pembimbing', 'mahasiswa']) {
                    return false;
                }

                return true;
            })->values();

            // Kumpulkan seluruh partner user_id untuk batch query status active device (cegah N+1)
            $partnerUserIds = [];
            foreach ($filteredRooms as $room) {
                $partnerMember = $room->members->first(fn ($m) => (int) $m->user_id !== (int) $user->id);
                if ($partnerMember) {
                    $partnerUserIds[] = (int) $partnerMember->user_id;
                }
            }
            $partnerUserIds = array_values(array_unique($partnerUserIds));

            $activeDeviceUserIds = [];
            if (!empty($partnerUserIds)) {
                $activeDeviceUserIds = BchatUserDevice::whereIn('user_id', $partnerUserIds)
                    ->where('status', 'ACTIVE')
                    ->pluck('user_id')
                    ->map(fn ($id) => (int) $id)
                    ->all();
            }

            $resourceCollection = $filteredRooms->map(function ($room) use ($user, $activeDeviceUserIds) {
                return (new BchatRoomSummaryResource($room, $user, $activeDeviceUserIds))->resolve();
            });

            return response()->json([
                'status'  => 'success',
                'message' => 'Daftar ruang obrolan berhasil diambil.',
                'data'    => $resourceCollection,
            ], 200);
        } catch (Throwable $e) {
            return response()->json([
                'status'     => 'error',
                'message'    => 'Terjadi kesalahan sistem internal saat memproses daftar ruang obrolan.',
                'error_code' => 'INTERNAL_SERVER_ERROR',
            ], 500);
        }
    }

    /**
     * [GET /api/bchat/rooms/{roomUuid}]
     *
     * Mengambil detail metadata dan status handshake ruang obrolan spesifik.
     * Menerapkan otorisasi ketat Policy view() dan validasi struktur membership.
     */
    public function show(Request $request, string $roomUuid): JsonResponse
    {
        $user = $request->user();

        if (!$user) {
            return response()->json([
                'status'     => 'error',
                'message'    => 'Pengguna belum terautentikasi.',
                'error_code' => 'UNAUTHENTICATED',
            ], 401);
        }

        $cleanUuid = strtolower(trim($roomUuid));
        if (!Str::isUuid($cleanUuid)) {
            return response()->json([
                'status'     => 'error',
                'message'    => 'Ruang obrolan tidak ditemukan.',
                'error_code' => 'ROOM_NOT_FOUND',
            ], 404);
        }

        try {
            $room = BchatRoom::with([
                'penugasanPembimbing.skripsi',
                'members.user',
                'activeSession',
                'pendingSession',
                'welcomeChallenges' => function ($query) {
                    $query->where('operation_status', 'ACTIVE')
                        ->with('recipientDevice:id,device_uuid')
                        ->orderBy('id', 'asc');
                },
            ])
            ->where('room_uuid', $cleanUuid)
            ->first();

            if (!$room) {
                return response()->json([
                    'status'     => 'error',
                    'message'    => 'Ruang obrolan tidak ditemukan.',
                    'error_code' => 'ROOM_NOT_FOUND',
                ], 404);
            }

            // Otorisasi via BchatRoomPolicy::view()
            if (!$user->can('view', $room)) {
                return response()->json([
                    'status'     => 'error',
                    'message'    => 'Anda tidak memiliki akses ke ruang obrolan ini.',
                    'error_code' => 'FORBIDDEN',
                ], 403);
            }

            // Validasi integritas struktur keanggotaan (fail closed)
            $members = $room->members;
            if ($members->count() !== 2) {
                return response()->json([
                    'status'     => 'error',
                    'message'    => 'Struktur keanggotaan ruang obrolan tidak valid atau tidak konsisten.',
                    'error_code' => 'INVALID_ROOM_MEMBERSHIP',
                ], 422);
            }

            $memberRoles = $members->pluck('role')->sort()->values()->all();
            if ($memberRoles !== ['dosen_pembimbing', 'mahasiswa']) {
                return response()->json([
                    'status'     => 'error',
                    'message'    => 'Komposisi peran keanggotaan ruang obrolan tidak konsisten.',
                    'error_code' => 'INVALID_ROOM_MEMBERSHIP',
                ], 422);
            }

            $partnerMember = $members->first(fn ($m) => (int) $m->user_id !== (int) $user->id);
            if (!$partnerMember) {
                return response()->json([
                    'status'     => 'error',
                    'message'    => 'Anggota rekan ruang obrolan tidak ditemukan.',
                    'error_code' => 'INVALID_ROOM_MEMBERSHIP',
                ], 422);
            }

            // Periksa ketersediaan perangkat aktif dan kesiapan KeyPackage partner secara terisolasi
            $hasActiveDevice = BchatUserDevice::where('user_id', $partnerMember->user_id)
                ->where('status', 'ACTIVE')
                ->exists();

            $hasPartnerKeyPackage = BchatUserDevice::where('user_id', $partnerMember->user_id)
                ->where('status', 'ACTIVE')
                ->where('is_trusted', true)
                ->whereHas('keyPackages', fn ($q) => $q->where('is_consumed', false))
                ->exists();

            $data = (new BchatRoomDetailResource($room, $user, $hasActiveDevice, $hasPartnerKeyPackage))->resolve();

            return response()->json([
                'status'  => 'success',
                'message' => 'Detail metadata ruang obrolan berhasil diambil.',
                'data'    => $data,
            ], 200);
        } catch (Throwable $e) {
            return response()->json([
                'status'     => 'error',
                'message'    => 'Terjadi kesalahan sistem internal saat memproses detail ruang obrolan.',
                'error_code' => 'INTERNAL_SERVER_ERROR',
            ], 500);
        }
    }
}

