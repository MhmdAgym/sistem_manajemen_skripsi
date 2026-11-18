<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Bchat;

use App\Exceptions\BchatMessageException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Bchat\BchatSendMessageRequest;
use App\Http\Resources\Bchat\BchatMessageResource;
use App\Models\BchatUserDevice;
use App\Services\BchatMessageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

/**
 * Controller untuk Transport Pesan Aplikasi E2EE (B-CHAT-09 / I-04B.4R).
 *
 * Menyediakan endpoint REST API:
 * - POST /api/bchat/rooms/{roomUuid}/messages (Pengiriman ciphertext terenkripsi)
 * - GET /api/bchat/rooms/{roomUuid}/messages (Pengambilan ciphertext dengan cursor pagination)
 */
class BchatMessageController extends Controller
{
    public function __construct(
        protected readonly BchatMessageService $messageService
    ) {
    }

    /**
     * [POST /api/bchat/rooms/{roomUuid}/messages]
     *
     * Menyimpan ciphertext pesan aplikasi E2EE secara atomik dan idempoten.
     */
    public function store(BchatSendMessageRequest $request, string $roomUuid): JsonResponse
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

        try {
            $result = $this->messageService->sendMessage(
                sender: $user,
                roomUuid: $roomUuid,
                payload: $request->validated()
            );

            $httpStatus = $result['is_replayed'] ? 200 : 201;
            $messageText = $result['is_replayed']
                ? 'Pesan sudah pernah diterima sebelumnya (idempoten).'
                : 'Pesan E2EE berhasil dikirim dan disimpan.';

            return response()->json([
                'status'  => 'success',
                'message' => $messageText,
                'data'    => new BchatMessageResource($result['message']),
            ], $httpStatus);
        } catch (BchatMessageException $e) {
            return response()->json([
                'status'     => 'error',
                'message'    => $e->getMessage(),
                'error_code' => $e->getErrorCode(),
            ], $e->getHttpStatus());
        } catch (Throwable $e) {
            return response()->json([
                'status'     => 'error',
                'message'    => 'Terjadi kesalahan internal saat memproses pengiriman pesan.',
                'error_code' => 'INTERNAL_SERVER_ERROR',
            ], 500);
        }
    }

    /**
     * [GET /api/bchat/rooms/{roomUuid}/messages]
     *
     * Mengambil daftar pesan ciphertext dengan cursor pagination (after_sequence).
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

        $deviceError = $this->validateActiveDevice($request, $user);
        if ($deviceError instanceof JsonResponse) {
            return $deviceError;
        }

        $afterSequence = $request->has('after_sequence') ? (int) $request->query('after_sequence') : null;
        $limit = $request->has('limit') ? (int) $request->query('limit') : 50;
        $sessionUuid = $request->has('session_uuid') ? (string) $request->query('session_uuid') : null;
        $generationNumber = $request->has('generation_number') ? (int) $request->query('generation_number') : null;

        try {
            $messages = $this->messageService->getMessages(
                user: $user,
                roomUuid: $roomUuid,
                afterSequence: $afterSequence,
                limit: $limit,
                sessionUuid: $sessionUuid,
                generationNumber: $generationNumber
            );

            return response()->json([
                'status'  => 'success',
                'message' => 'Daftar pesan berhasil diambil.',
                'data'    => BchatMessageResource::collection($messages),
            ], 200);
        } catch (BchatMessageException $e) {
            return response()->json([
                'status'     => 'error',
                'message'    => $e->getMessage(),
                'error_code' => $e->getErrorCode(),
            ], $e->getHttpStatus());
        } catch (Throwable $e) {
            return response()->json([
                'status'     => 'error',
                'message'    => 'Terjadi kesalahan internal saat mengambil histori pesan.',
                'error_code' => 'INTERNAL_SERVER_ERROR',
            ], 500);
        }
    }

    /**
     * Memvalidasi bahwa pengguna memiliki perangkat aktif dan tepercaya,
     * serta perangkat pemanggil tidak berstatus REVOKED atau UNTRUSTED.
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
                    'message'    => 'Perangkat klien tidak valid atau telah dicabut (REVOKED). Operasi pesan ditolak.',
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

        // Jika tidak ada header spesifik namun pengguna memiliki riwayat perangkat terdaftar
        if (BchatUserDevice::where('user_id', $user->id)->exists()) {
            $hasActive = BchatUserDevice::where('user_id', $user->id)
                ->where('status', 'ACTIVE')
                ->exists();

            if (!$hasActive) {
                return response()->json([
                    'status'     => 'error',
                    'message'    => 'Pengguna tidak memiliki perangkat berstatus aktif. Operasi pesan ditolak.',
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
        }

        return null;
    }
}

