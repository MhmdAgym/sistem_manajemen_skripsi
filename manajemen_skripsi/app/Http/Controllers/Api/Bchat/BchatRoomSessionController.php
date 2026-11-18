<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Bchat;

use App\Exceptions\BchatRoomSessionException;
use App\Http\Controllers\Controller;
use App\Http\Resources\Bchat\BchatRoomSessionResource;
use App\Models\BchatRoom;
use App\Models\BchatRoomSession;
use App\Models\BchatUserDevice;
use App\Services\BchatRoomSessionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Throwable;

class BchatRoomSessionController extends Controller
{
    public function __construct(
        protected readonly BchatRoomSessionService $sessionService
    ) {
    }

    /**
     * [POST /api/bchat/rooms/{roomUuid}/sessions/next]
     *
     * Membuat generasi sesi kriptografi MLS berikutnya.
     */
    public function createNext(Request $request, string $roomUuid): JsonResponse
    {
        $user = $request->user();
        if (!$user) {
            return response()->json([
                'status'     => 'error',
                'message'    => 'Pengguna belum terautentikasi.',
                'error_code' => 'UNAUTHENTICATED',
            ], 401);
        }

        $deviceError = $this->validateActiveDevice($request, $user);
        if ($deviceError instanceof JsonResponse) {
            return $deviceError;
        }

        $validated = $request->validate([
            'group_id_hash' => ['nullable', 'string', 'size:64', 'regex:/^[0-9a-f]{64}$/i'],
        ]);

        try {
            $session = $this->sessionService->createNextSession(
                creator: $user,
                roomOrUuid: $roomUuid,
                groupIdHash: $validated['group_id_hash'] ?? null
            );

            return response()->json([
                'status'  => 'success',
                'message' => 'Generasi sesi kriptografi baru berhasil dibuat (status PENDING).',
                'data'    => new BchatRoomSessionResource($session),
            ], 201);
        } catch (BchatRoomSessionException $e) {
            return response()->json([
                'status'     => 'error',
                'message'    => $e->getMessage(),
                'error_code' => $e->getErrorCode(),
            ], $e->getHttpStatus());
        } catch (Throwable $e) {
            return response()->json([
                'status'     => 'error',
                'message'    => 'Terjadi kesalahan internal saat membuat generasi sesi baru.',
                'error_code' => 'INTERNAL_SERVER_ERROR',
            ], 500);
        }
    }

    /**
     * [GET /api/bchat/rooms/{roomUuid}/sessions]
     *
     * Mengambil seluruh riwayat generasi sesi kriptografi pada ruang bimbingan.
     */
    public function index(Request $request, string $roomUuid): JsonResponse
    {
        $user = $request->user();
        if (!$user) {
            return response()->json([
                'status'     => 'error',
                'message'    => 'Pengguna belum terautentikasi.',
                'error_code' => 'UNAUTHENTICATED',
            ], 401);
        }

        try {
            $sessions = $this->sessionService->getSessionHistory($roomUuid);

            return response()->json([
                'status'  => 'success',
                'message' => 'Riwayat generasi sesi berhasil diambil.',
                'data'    => BchatRoomSessionResource::collection($sessions),
            ], 200);
        } catch (BchatRoomSessionException $e) {
            return response()->json([
                'status'     => 'error',
                'message'    => $e->getMessage(),
                'error_code' => $e->getErrorCode(),
            ], $e->getHttpStatus());
        } catch (Throwable $e) {
            return response()->json([
                'status'     => 'error',
                'message'    => 'Terjadi kesalahan internal saat mengambil riwayat sesi.',
                'error_code' => 'INTERNAL_SERVER_ERROR',
            ], 500);
        }
    }

    /**
     * [GET /api/bchat/rooms/{roomUuid}/sessions/active]
     *
     * Mengambil sesi aktif saat ini pada ruang obrolan.
     */
    public function active(Request $request, string $roomUuid): JsonResponse
    {
        $user = $request->user();
        if (!$user) {
            return response()->json([
                'status'     => 'error',
                'message'    => 'Pengguna belum terautentikasi.',
                'error_code' => 'UNAUTHENTICATED',
            ], 401);
        }

        try {
            $session = $this->sessionService->getActiveSession($roomUuid);
            if (!$session) {
                return response()->json([
                    'status'     => 'error',
                    'message'    => 'Tidak ada sesi kriptografi yang aktif pada ruang obrolan ini.',
                    'error_code' => 'NO_ACTIVE_SESSION',
                ], 404);
            }

            return response()->json([
                'status'  => 'success',
                'message' => 'Sesi aktif berhasil diambil.',
                'data'    => new BchatRoomSessionResource($session),
            ], 200);
        } catch (BchatRoomSessionException $e) {
            return response()->json([
                'status'     => 'error',
                'message'    => $e->getMessage(),
                'error_code' => $e->getErrorCode(),
            ], $e->getHttpStatus());
        } catch (Throwable $e) {
            return response()->json([
                'status'     => 'error',
                'message'    => 'Terjadi kesalahan internal saat mengambil sesi aktif.',
                'error_code' => 'INTERNAL_SERVER_ERROR',
            ], 500);
        }
    }

    /**
     * [GET /api/bchat/rooms/{roomUuid}/sessions/pending]
     *
     * Mengambil sesi pending saat ini pada ruang obrolan.
     */
    public function pending(Request $request, string $roomUuid): JsonResponse
    {
        $user = $request->user();
        if (!$user) {
            return response()->json([
                'status'     => 'error',
                'message'    => 'Pengguna belum terautentikasi.',
                'error_code' => 'UNAUTHENTICATED',
            ], 401);
        }

        try {
            $session = $this->sessionService->getPendingSession($roomUuid);
            if (!$session) {
                return response()->json([
                    'status'     => 'error',
                    'message'    => 'Tidak ada sesi kriptografi yang pending pada ruang obrolan ini.',
                    'error_code' => 'NO_PENDING_SESSION',
                ], 404);
            }

            return response()->json([
                'status'  => 'success',
                'message' => 'Sesi pending berhasil diambil.',
                'data'    => new BchatRoomSessionResource($session),
            ], 200);
        } catch (BchatRoomSessionException $e) {
            return response()->json([
                'status'     => 'error',
                'message'    => $e->getMessage(),
                'error_code' => $e->getErrorCode(),
            ], $e->getHttpStatus());
        } catch (Throwable $e) {
            return response()->json([
                'status'     => 'error',
                'message'    => 'Terjadi kesalahan internal saat mengambil sesi pending.',
                'error_code' => 'INTERNAL_SERVER_ERROR',
            ], 500);
        }
    }

    /**
     * [GET /api/bchat/rooms/{roomUuid}/sessions/{sessionUuid}/welcome-challenge]
     *
     * Mengambil tantangan Welcome E2EE untuk sesi generasi tertentu oleh penerima.
     */
    public function welcomeChallenge(Request $request, string $roomUuid, string $sessionUuid): JsonResponse
    {
        $user = $request->user();
        if (!$user) {
            return response()->json([
                'status'     => 'error',
                'message'    => 'Pengguna belum terautentikasi.',
                'error_code' => 'UNAUTHENTICATED',
            ], 401);
        }

        $deviceError = $this->validateActiveDevice($request, $user);
        if ($deviceError instanceof JsonResponse) {
            return $deviceError;
        }

        $session = BchatRoomSession::with('room')
            ->where('session_uuid', strtolower(trim($sessionUuid)))
            ->first();
        if (!$session || !$session->room || strtolower((string) $session->room->room_uuid) !== strtolower(trim($roomUuid))) {
            return response()->json([
                'status'     => 'error',
                'message'    => 'Sesi kriptografi tidak ditemukan pada ruang obrolan ini.',
                'error_code' => 'SESSION_NOT_FOUND',
            ], 404);
        }

        try {
            $data = $this->sessionService->getSessionWelcomeChallenge(
                recipient: $user,
                session: $session
            );

            return response()->json([
                'status'  => 'success',
                'message' => 'Tantangan Welcome untuk sesi berhasil diambil.',
                'data'    => $data,
            ], 200);
        } catch (BchatRoomSessionException $e) {
            return response()->json([
                'status'     => 'error',
                'message'    => $e->getMessage(),
                'error_code' => $e->getErrorCode(),
            ], $e->getHttpStatus());
        } catch (Throwable $e) {
            return response()->json([
                'status'     => 'error',
                'message'    => 'Terjadi kesalahan internal saat mengambil tantangan Welcome sesi.',
                'error_code' => 'INTERNAL_SERVER_ERROR',
            ], 500);
        }
    }

    /**
     * [POST /api/bchat/rooms/{roomUuid}/sessions/{sessionUuid}/welcome]
     *
     * Memublikasikan paket Welcome E2EE untuk sesi generasi tertentu.
     */
    public function publishWelcome(Request $request, string $roomUuid, string $sessionUuid): JsonResponse
    {
        $user = $request->user();
        if (!$user) {
            return response()->json([
                'status'     => 'error',
                'message'    => 'Pengguna belum terautentikasi.',
                'error_code' => 'UNAUTHENTICATED',
            ], 401);
        }

        $deviceError = $this->validateActiveDevice($request, $user);
        if ($deviceError instanceof JsonResponse) {
            return $deviceError;
        }

        $session = BchatRoomSession::with('room')
            ->where('session_uuid', strtolower(trim($sessionUuid)))
            ->first();
        if (!$session || !$session->room || strtolower((string) $session->room->room_uuid) !== strtolower(trim($roomUuid))) {
            return response()->json([
                'status'     => 'error',
                'message'    => 'Sesi kriptografi tidak ditemukan pada ruang obrolan ini.',
                'error_code' => 'SESSION_NOT_FOUND',
            ], 404);
        }

        $validated = $request->validate([
            'recipient_device_uuid' => ['required', 'uuid'],
            'key_package_hash'      => ['required', 'string', 'size:64', 'regex:/^[0-9a-f]{64}$/i'],
            'group_id_hash'         => ['nullable', 'string', 'size:64', 'regex:/^[0-9a-f]{64}$/i'],
            'welcome_message'       => ['required', 'string'],
        ]);

        try {
            $challenge = $this->sessionService->publishSessionWelcome(
                initiator: $user,
                session: $session,
                payloadData: $validated
            );

            return response()->json([
                'status'  => 'success',
                'message' => 'Payload Welcome untuk sesi berhasil dipublikasikan.',
                'data'    => [
                    'session_uuid'           => $session->session_uuid,
                    'welcome_operation_uuid' => $challenge->welcome_operation_uuid,
                    'challenge_uuid'         => $challenge->challenge_uuid,
                    'expires_at'             => $challenge->expires_at?->toIso8601String(),
                ],
            ], 200);
        } catch (BchatRoomSessionException $e) {
            return response()->json([
                'status'     => 'error',
                'message'    => $e->getMessage(),
                'error_code' => $e->getErrorCode(),
            ], $e->getHttpStatus());
        } catch (Throwable $e) {
            return response()->json([
                'status'     => 'error',
                'message'    => 'Terjadi kesalahan internal saat memproses publikasi Welcome.',
                'error_code' => 'INTERNAL_SERVER_ERROR',
            ], 500);
        }
    }

    /**
     * [POST /api/bchat/rooms/{roomUuid}/sessions/{sessionUuid}/ack]
     *
     * Memproses Welcome ACK untuk mengaktifkan sesi generasi baru.
     */
    public function ack(Request $request, string $roomUuid, string $sessionUuid): JsonResponse
    {
        $user = $request->user();
        if (!$user) {
            return response()->json([
                'status'     => 'error',
                'message'    => 'Pengguna belum terautentikasi.',
                'error_code' => 'UNAUTHENTICATED',
            ], 401);
        }

        $deviceError = $this->validateActiveDevice($request, $user);
        if ($deviceError instanceof JsonResponse) {
            return $deviceError;
        }

        $session = BchatRoomSession::with('room')
            ->where('session_uuid', strtolower(trim($sessionUuid)))
            ->first();
        if (!$session || !$session->room || strtolower((string) $session->room->room_uuid) !== strtolower(trim($roomUuid))) {
            return response()->json([
                'status'     => 'error',
                'message'    => 'Sesi kriptografi tidak ditemukan pada ruang obrolan ini.',
                'error_code' => 'SESSION_NOT_FOUND',
            ], 404);
        }

        $validated = $request->validate([
            'challenge_uuid' => ['required', 'uuid'],
            'signature'      => ['required', 'string'],
        ]);

        try {
            $activatedSession = $this->sessionService->processSessionWelcomeAck(
                recipient: $user,
                session: $session,
                payloadData: $validated,
                ipAddress: $request->ip(),
                userAgent: $request->userAgent()
            );

            return response()->json([
                'status'  => 'success',
                'message' => 'Welcome ACK berhasil diverifikasi dan sesi generasi baru kini AKTIF.',
                'data'    => new BchatRoomSessionResource($activatedSession),
            ], 200);
        } catch (BchatRoomSessionException $e) {
            return response()->json([
                'status'     => 'error',
                'message'    => $e->getMessage(),
                'error_code' => $e->getErrorCode(),
            ], $e->getHttpStatus());
        } catch (Throwable $e) {
            return response()->json([
                'status'     => 'error',
                'message'    => 'Terjadi kesalahan internal saat memproses Welcome ACK.',
                'error_code' => 'INTERNAL_SERVER_ERROR',
            ], 500);
        }
    }

    /**
     * Validasi status aktif dan tepercaya perangkat pemanggil.
     */
    protected function validateActiveDevice(Request $request, $user): ?JsonResponse
    {
        $requestDeviceUuid = $request->header('X-Bchat-Device-Uuid');
        if ($requestDeviceUuid !== null && trim($requestDeviceUuid) !== '') {
            $cleanUuid = strtolower(trim($requestDeviceUuid));
            $device = BchatUserDevice::where('user_id', $user->id)
                ->where('device_uuid', $cleanUuid)
                ->first();

            if (!$device || $device->status !== 'ACTIVE') {
                return response()->json([
                    'status'     => 'error',
                    'message'    => 'Perangkat klien tidak valid atau telah dicabut (REVOKED). Operasi sesi ditolak.',
                    'error_code' => 'DEVICE_NOT_ACTIVE',
                ], 403);
            }

            if (!$device->is_trusted) {
                return response()->json([
                    'status'     => 'error',
                    'message'    => 'Perangkat belum berstatus tepercaya. Lakukan pairing terlebih dahulu.',
                    'error_code' => 'DEVICE_NOT_TRUSTED',
                ], 403);
            }

            return null;
        }

        // Cek perangkat fallback
        $hasActive = BchatUserDevice::where('user_id', $user->id)
            ->where('status', 'ACTIVE')
            ->exists();

        if (!$hasActive) {
            return response()->json([
                'status'     => 'error',
                'message'    => 'Pengguna tidak memiliki perangkat berstatus aktif. Operasi sesi ditolak.',
                'error_code' => 'DEVICE_NOT_ACTIVE',
            ], 403);
        }

        $hasTrusted = BchatUserDevice::where('user_id', $user->id)
            ->where('status', 'ACTIVE')
            ->where('is_trusted', true)
            ->exists();

        if (!$hasTrusted) {
            return response()->json([
                'status'     => 'error',
                'message'    => 'Pengguna tidak memiliki perangkat tepercaya yang aktif. Lakukan pairing terlebih dahulu.',
                'error_code' => 'DEVICE_NOT_TRUSTED',
            ], 403);
        }

        return null;
    }
}

