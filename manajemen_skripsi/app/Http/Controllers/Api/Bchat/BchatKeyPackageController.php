<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Bchat;

use App\Exceptions\BchatKeyPackageException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Bchat\BchatKeyPackageUploadRequest;
use App\Services\BchatKeyPackageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

class BchatKeyPackageController extends Controller
{
    public function __construct(
        protected BchatKeyPackageService $keyPackageService
    ) {}

    /**
     * [POST /api/bchat/key-packages]
     *
     * Mengunggah batch KeyPackage MLS untuk perangkat aktif pengguna.
     * Controller tipis: delegasi penuh ke BchatKeyPackageService.
     */
    public function upload(BchatKeyPackageUploadRequest $request): JsonResponse
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
            $deviceUuid = $request->string('device_uuid')->value();
            /** @var array<int, array<string, mixed>> $packages */
            $packages = (array) $request->input('packages', []);

            $result = $this->keyPackageService->uploadKeyPackages(
                user: $user,
                deviceUuid: $deviceUuid,
                packages: $packages
            );

            return response()->json([
                'status'  => 'success',
                'message' => 'KeyPackage berhasil diunggah.',
                'data'    => $result,
            ], 201);
        } catch (BchatKeyPackageException $e) {
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

    /**
     * [GET /api/bchat/rooms/{roomUuid}/partner-key-package]
     *
     * Mengambil satu KeyPackage tertua milik mitra bimbingan (partner).
     * Operasi bersifat murni read-only.
     * Controller tipis: delegasi penuh ke BchatKeyPackageService.
     */
    public function fetchPartnerKeyPackage(Request $request, string $roomUuid): JsonResponse
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
            $result = $this->keyPackageService->fetchPartnerKeyPackage(
                user: $user,
                roomUuid: $roomUuid
            );

            return response()->json([
                'status'  => 'success',
                'message' => 'KeyPackage partner berhasil diambil.',
                'data'    => $result,
            ], 200);
        } catch (BchatKeyPackageException $e) {
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

    /**
     * [GET /api/bchat/rooms/{roomUuid}/devices/{deviceUuid}/key-package]
     *
     * Mengambil satu KeyPackage aktif milik perangkat spesifik anggota room (Multi-Device Commit Relay).
     */
    public function fetchDeviceKeyPackage(Request $request, string $roomUuid, string $deviceUuid): JsonResponse
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
            $result = $this->keyPackageService->fetchDeviceKeyPackage(
                user: $user,
                roomUuid: $roomUuid,
                deviceUuid: $deviceUuid
            );

            return response()->json([
                'status'  => 'success',
                'message' => 'KeyPackage perangkat berhasil diambil.',
                'data'    => $result,
            ], 200);
        } catch (BchatKeyPackageException $e) {
            return response()->json([
                'status'     => 'error',
                'message'    => $e->getMessage(),
                'error_code' => $e->getErrorCode(),
            ], $e->getHttpStatus());
        } catch (Throwable $e) {
            return response()->json([
                'status'     => 'error',
                'message'    => 'Terjadi kesalahan sistem internal: ' . $e->getMessage(),
                'error_code' => 'INTERNAL_SERVER_ERROR',
            ], 500);
        }
    }
}

