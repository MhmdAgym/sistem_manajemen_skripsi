<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Bchat;

use App\Exceptions\BchatWelcomeException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Bchat\BchatWelcomeAckRequest;
use App\Services\BchatWelcomeAckService;
use Illuminate\Http\JsonResponse;
use Throwable;

class BchatWelcomeAckController extends Controller
{
    public function __construct(
        protected BchatWelcomeAckService $ackService
    ) {
    }

    /**
     * [POST /api/bchat/rooms/{roomUuid}/welcome-ack]
     *
     * Memproses verifikasi Canonical Welcome ACK dan aktivasi atomik ruang obrolan.
     * Controller tipis: delegasi penuh ke BchatWelcomeAckService.
     */
    public function welcomeAck(BchatWelcomeAckRequest $request, string $roomUuid): JsonResponse
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
            $result = $this->ackService->processWelcomeAck(
                recipientUser: $user,
                roomUuid: $roomUuid,
                payloadData: $request->validated(),
                ipAddress: $request->ip(),
                userAgent: $request->userAgent()
            );

            return response()->json([
                'status'  => 'success',
                'message' => 'Welcome ACK berhasil diverifikasi dan ruang obrolan telah aktif.',
                'data'    => $result,
            ], 200);
        } catch (BchatWelcomeException $e) {
            return response()->json([
                'status'     => 'error',
                'message'    => $e->getMessage(),
                'error_code' => $e->getErrorCode(),
            ], $e->getHttpStatus());
        } catch (Throwable $e) {
            return response()->json([
                'status'     => 'error',
                'message'    => 'Terjadi kesalahan sistem internal.',
                'error_code' => 'INTERNAL_SERVER_ERROR',
            ], 500);
        }
    }
}

