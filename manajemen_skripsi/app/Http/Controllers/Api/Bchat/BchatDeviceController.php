<?php

namespace App\Http\Controllers\Api\Bchat;

use App\Exceptions\BchatDeviceException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Bchat\BchatDeviceRegisterRequest;
use App\Http\Requests\Bchat\BchatDeviceStatusRequest;
use App\Http\Requests\Bchat\BchatRegistrationNonceRequest;
use App\Http\Requests\Bchat\BchatStepUpRequest;
use App\Services\BchatDeviceService;
use Illuminate\Http\JsonResponse;

class BchatDeviceController extends Controller
{
    public function __construct(
        protected BchatDeviceService $deviceService
    ) {}

    /**
     * [POST /api/bchat/devices/step-up]
     * Melakukan step-up password re-authentication untuk otorisasi registrasi perangkat.
     */
    public function stepUp(BchatStepUpRequest $request): JsonResponse
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
            $result = $this->deviceService->authenticateStepUp(
                $user,
                $request->string('password')->value(),
                $request->string('device_uuid')->value(),
                $request->ip(),
                $request->userAgent()
            );

            return response()->json([
                'status'  => 'success',
                'message' => 'Step-up re-authentication berhasil.',
                'data'    => $result,
            ], 200);
        } catch (BchatDeviceException $e) {
            return response()->json([
                'status'     => 'error',
                'message'    => $e->getMessage(),
                'error_code' => $e->getErrorCode(),
            ], $e->getHttpStatus());
        } catch (\Throwable $e) {
            return response()->json([
                'status'     => 'error',
                'message'    => 'Terjadi kesalahan sistem internal.',
                'error_code' => 'INTERNAL_SERVER_ERROR',
            ], 500);
        }
    }

    /**
     * [POST /api/bchat/devices/registration-nonce]
     * Menerbitkan registration challenge nonce baru dengan mengonsumsi step-up token.
     */
    public function issueRegistrationNonce(BchatRegistrationNonceRequest $request): JsonResponse
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
            $result = $this->deviceService->issueRegistrationNonce(
                $user,
                $request->string('step_up_token')->value(),
                $request->string('device_uuid')->value(),
                $request->string('public_key')->value()
            );

            return response()->json([
                'status'  => 'success',
                'message' => 'Registration nonce berhasil diterbitkan.',
                'data'    => $result,
            ], 200);
        } catch (BchatDeviceException $e) {
            return response()->json([
                'status'     => 'error',
                'message'    => $e->getMessage(),
                'error_code' => $e->getErrorCode(),
            ], $e->getHttpStatus());
        } catch (\Throwable $e) {
            return response()->json([
                'status'     => 'error',
                'message'    => 'Terjadi kesalahan sistem internal.',
                'error_code' => 'INTERNAL_SERVER_ERROR',
            ], 500);
        }
    }

    /**
     * [GET /api/bchat/devices/status]
     *
     * Mengembalikan status perangkat aktif milik pengguna yang sedang login.
     * Mendukung rekonsiliasi staged key melalui query parameter device_uuid opsional.
     *
     * Endpoint ini TIDAK PERNAH mengembalikan:
     * - Private key
     * - Registration nonce atau step-up token
     * - Material kriptografi sensitif lainnya
     * - Informasi perangkat milik pengguna lain
     */
    public function status(BchatDeviceStatusRequest $request): JsonResponse
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
            $queryDeviceUuid = $request->filled('device_uuid')
                ? $request->string('device_uuid')->value()
                : null;

            $result = $this->deviceService->getActiveDeviceStatus($user, $queryDeviceUuid);

            return response()->json([
                'status'  => 'success',
                'message' => 'Status perangkat berhasil diambil.',
                'data'    => $result,
            ], 200);
        } catch (\Throwable $e) {
            return response()->json([
                'status'     => 'error',
                'message'    => 'Terjadi kesalahan sistem internal.',
                'error_code' => 'INTERNAL_SERVER_ERROR',
            ], 500);
        }
    }

    /**
     * [POST /api/bchat/devices/register]
     * Mendaftarkan perangkat baru atau memproses Managed Replacement perangkat aktif.
     *
     * Replacement Protection (v2):
     * - Jika pengguna sudah memiliki perangkat ACTIVE, field confirm_replacement = true
     *   dan expected_active_device_uuid wajib disertakan.
     * - Tanpa konfirmasi eksplisit, registrasi ditolak HTTP 409 dan perangkat lama tetap ACTIVE.
     */
    public function register(BchatDeviceRegisterRequest $request): JsonResponse
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
            $deviceName = $request->filled('device_name')
                ? $request->string('device_name')->value()
                : 'Browser Device';

            $confirmReplacement       = (bool) $request->boolean('confirm_replacement', false);
            $expectedActiveDeviceUuid = $request->filled('expected_active_device_uuid')
                ? $request->string('expected_active_device_uuid')->value()
                : null;

            $result = $this->deviceService->registerDevice(
                user: $user,
                nonceUuid: $request->string('nonce_uuid')->value(),
                payloadInput: $request->string('pop_payload')->value(),
                signatureInput: $request->string('pop_signature')->value(),
                deviceName: $deviceName,
                confirmReplacement: $confirmReplacement,
                expectedActiveDeviceUuid: $expectedActiveDeviceUuid
            );

            return response()->json([
                'status'  => 'success',
                'message' => 'Perangkat berhasil didaftarkan.',
                'data'    => [
                    'device_uuid'    => (string) $result['device_uuid'],
                    'device_name'    => (string) $result['device_name'],
                    'key_version'    => (int) $result['key_version'],
                    'status'         => (string) $result['status'],
                    'public_key'     => (string) $result['public_key'],
                    'registered_at'  => (string) $result['registered_at'],
                    'is_replacement' => (bool) $result['is_replacement'],
                ],
            ], 201);
        } catch (BchatDeviceException $e) {
            return response()->json([
                'status'     => 'error',
                'message'    => $e->getMessage(),
                'error_code' => $e->getErrorCode(),
            ], $e->getHttpStatus());
        } catch (\Throwable $e) {
            return response()->json([
                'status'     => 'error',
                'message'    => 'Terjadi kesalahan sistem internal.',
                'error_code' => 'INTERNAL_SERVER_ERROR',
            ], 500);
        }
    }
}
