<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Bchat;

use App\Exceptions\BchatWelcomeException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Bchat\BchatWelcomePublishRequest;
use App\Services\BchatWelcomeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

class BchatWelcomeController extends Controller
{
    public function __construct(
        protected BchatWelcomeService $welcomeService
    ) {
    }

    /**
     * [POST /api/bchat/rooms/{roomUuid}/welcome]
     *
     * Mempublikasikan paket Welcome E2EE (MLS) oleh inisiator ruang bimbingan.
     * Controller tipis: delegasi penuh ke BchatWelcomeService.
     */
    public function publish(BchatWelcomePublishRequest $request, string $roomUuid): JsonResponse
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
            $result = $this->welcomeService->publishWelcome(
                initiatorUser: $user,
                roomUuid: $roomUuid,
                payloadData: $request->validated()
            );

            return response()->json([
                'status'  => 'success',
                'message' => 'Payload Welcome E2EE berhasil dipublikasikan.',
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
                'message'    => 'Terjadi kesalahan internal saat memproses Welcome message.',
                'error_code' => 'INTERNAL_SERVER_ERROR',
            ], 500);
        }
    }

    /**
     * [POST /api/bchat/rooms/{roomUuid}/welcome-challenge]
     *
     * Mengambil tantangan Welcome aktif atau merotasi tantangan in-place jika kedaluwarsa.
     * Controller tipis: delegasi penuh ke BchatWelcomeService.
     */
    public function challenge(Request $request, string $roomUuid): JsonResponse
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
            $result = $this->welcomeService->issueOrRotateChallenge(
                requestingUser: $user,
                roomUuid: $roomUuid
            );

            return response()->json([
                'status'  => 'success',
                'message' => 'Welcome challenge berhasil diambil/diperbarui.',
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
                'message'    => 'Terjadi kesalahan internal saat memproses Welcome challenge.',
                'error_code' => 'INTERNAL_SERVER_ERROR',
            ], 500);
        }
    }
}

