<?php

namespace App\Http\Controllers\Api\Bchat;

use App\Exceptions\BchatDeviceException;
use App\Http\Controllers\Controller;
use App\Services\BchatWsAuthService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

class BchatWsAuthController extends Controller
{
    public function __construct(
        protected BchatWsAuthService $wsAuthService
    ) {}

    /**
     * POST /api/bchat/broadcasting/challenge
     * Menerbitkan tantangan kriptografis untuk otorisasi kanal Reverb WebSocket.
     */
    public function issueChallenge(Request $request): JsonResponse
    {
        $deviceUuid = $request->input('device_uuid') ?: $request->header('X-Bchat-Device-Uuid');
        $socketId = $request->input('socket_id');
        $channelName = $request->input('channel_name');

        if (!$deviceUuid) {
            return response()->json([
                'status'     => 'error',
                'error_code' => 'DEVICE_UUID_REQUIRED',
                'message'    => 'Device UUID wajib disertakan via body atau header X-Bchat-Device-Uuid.',
            ], 422);
        }

        if (!$socketId) {
            return response()->json([
                'status'     => 'error',
                'error_code' => 'SOCKET_ID_REQUIRED',
                'message'    => 'Socket ID wajib diisi.',
            ], 422);
        }

        if (!$channelName) {
            return response()->json([
                'status'     => 'error',
                'error_code' => 'CHANNEL_NAME_REQUIRED',
                'message'    => 'Channel Name wajib diisi.',
            ], 422);
        }

        try {
            $data = $this->wsAuthService->issueChallenge(
                user: $request->user(),
                deviceUuid: (string) $deviceUuid,
                socketId: (string) $socketId,
                channelName: (string) $channelName
            );

            return response()->json([
                'status'  => 'success',
                'message' => 'WebSocket auth challenge berhasil diterbitkan.',
                'data'    => $data,
            ], 201);
        } catch (BchatDeviceException $e) {
            return response()->json([
                'status'     => 'error',
                'error_code' => $e->getErrorCode(),
                'message'    => $e->getMessage(),
            ], $e->getHttpStatus());
        } catch (Throwable $e) {
            return response()->json([
                'status'     => 'error',
                'error_code' => 'WS_AUTH_CHALLENGE_FAILED',
                'message'    => 'Terjadi kesalahan sistem saat menerbitkan WebSocket auth challenge: ' . $e->getMessage(),
            ], 500);
        }
    }
}
