<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Bchat;

use App\Exceptions\BchatTrustBootstrapException;
use App\Http\Controllers\Controller;
use App\Services\BchatTrustBootstrapService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

class BchatTrustBootstrapController extends Controller
{
    public function __construct(
        protected BchatTrustBootstrapService $bootstrapService
    ) {
    }

    /**
     * Memeriksa apakah perangkat dan akun memenuhi syarat untuk Trust Bootstrap.
     */
    public function checkEligibility(Request $request, string $deviceUuid): JsonResponse
    {
        $user = $request->user();
        if (!$user) {
            return response()->json([
                'status'     => 'error',
                'message'    => 'Pengguna belum terautentikasi.',
                'error_code' => 'UNAUTHENTICATED',
            ], 401);
        }

        $result = $this->bootstrapService->checkEligibility($user, $deviceUuid);

        return response()->json([
            'status' => 'success',
            'data'   => [
                'device_uuid' => $deviceUuid,
                'is_eligible' => $result['is_eligible'],
                'reason'      => $result['reason'],
                'error_code'  => $result['error_code'],
            ],
        ]);
    }

    /**
     * Menerbitkan challenge bootstrap sekali pakai setelah step-up authentication.
     */
    public function issueChallenge(Request $request, string $deviceUuid): JsonResponse
    {
        $user = $request->user();
        if (!$user) {
            return response()->json([
                'status'     => 'error',
                'message'    => 'Pengguna belum terautentikasi.',
                'error_code' => 'UNAUTHENTICATED',
            ], 401);
        }

        $password = (string) $request->input('password', '');
        if (trim($password) === '') {
            return response()->json([
                'status'     => 'error',
                'message'    => 'Password re-autentikasi wajib diisi.',
                'error_code' => 'PASSWORD_REQUIRED',
            ], 422);
        }

        try {
            $data = $this->bootstrapService->issueChallenge(
                user: $user,
                deviceUuid: $deviceUuid,
                password: $password,
                ipAddress: $request->ip(),
                userAgent: $request->userAgent()
            );

            return response()->json([
                'status'  => 'success',
                'message' => 'Challenge trust bootstrap berhasil diterbitkan.',
                'data'    => $data,
            ]);
        } catch (BchatTrustBootstrapException $e) {
            return response()->json([
                'status'     => 'error',
                'message'    => $e->getMessage(),
                'error_code' => $e->getErrorCode(),
            ], $e->getStatusCode());
        } catch (Throwable $e) {
            return response()->json([
                'status'     => 'error',
                'message'    => 'Terjadi kesalahan sistem saat menerbitkan challenge bootstrap: ' . $e->getMessage(),
                'error_code' => 'INTERNAL_SERVER_ERROR',
            ], 500);
        }
    }

    /**
     * Memverifikasi tanda tangan Ed25519 dan menetapkan status tepercaya perdana.
     */
    public function verify(Request $request, string $deviceUuid): JsonResponse
    {
        $user = $request->user();
        if (!$user) {
            return response()->json([
                'status'     => 'error',
                'message'    => 'Pengguna belum terautentikasi.',
                'error_code' => 'UNAUTHENTICATED',
            ], 401);
        }

        $challengeUuid = (string) $request->input('challenge_uuid', '');
        $signature = (string) $request->input('signature', '');

        if (trim($challengeUuid) === '') {
            return response()->json([
                'status'     => 'error',
                'message'    => 'Challenge UUID wajib disertakan.',
                'error_code' => 'CHALLENGE_UUID_REQUIRED',
            ], 422);
        }

        if (trim($signature) === '') {
            return response()->json([
                'status'     => 'error',
                'message'    => 'Signature Ed25519 wajib disertakan.',
                'error_code' => 'SIGNATURE_REQUIRED',
            ], 422);
        }

        try {
            $data = $this->bootstrapService->verifyAndBootstrap(
                user: $user,
                deviceUuid: $deviceUuid,
                challengeUuid: $challengeUuid,
                signature: $signature,
                ipAddress: $request->ip(),
                userAgent: $request->userAgent()
            );

            return response()->json([
                'status'  => 'success',
                'message' => 'Perangkat berhasil ditetapkan sebagai trust root pertama akun.',
                'data'    => $data,
            ]);
        } catch (BchatTrustBootstrapException $e) {
            return response()->json([
                'status'     => 'error',
                'message'    => $e->getMessage(),
                'error_code' => $e->getErrorCode(),
            ], $e->getStatusCode());
        } catch (Throwable $e) {
            return response()->json([
                'status'     => 'error',
                'message'    => 'Terjadi kesalahan sistem saat memverifikasi bootstrap: ' . $e->getMessage(),
                'error_code' => 'INTERNAL_SERVER_ERROR',
            ], 500);
        }
    }
}

