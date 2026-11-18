<?php

namespace App\Http\Controllers\Api\Bchat;

use App\Exceptions\BchatHistoryTransferException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Bchat\BchatHistoryTransferCompleteRequest;
use App\Http\Requests\Bchat\BchatHistoryTransferRequestRequest;
use App\Http\Requests\Bchat\BchatHistoryTransferUploadRequest;
use App\Http\Resources\Bchat\BchatHistoryTransferResource;
use App\Models\BchatUserDevice;
use App\Services\BchatHistoryTransferService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

class BchatHistoryTransferController extends Controller
{
    public function __construct(
        protected readonly BchatHistoryTransferService $transferService
    ) {
    }

    /**
     * [POST /api/bchat/rooms/{roomUuid}/history-transfers]
     * Inisiasi permintaan transfer riwayat oleh perangkat penerima.
     */
    public function store(BchatHistoryTransferRequestRequest $request, string $roomUuid): JsonResponse
    {
        $user = $request->user();
        if (!$user) {
            return $this->unauthenticatedResponse();
        }

        $device = $this->getCallingDevice($request, $user);
        if ($device instanceof JsonResponse) {
            return $device;
        }

        try {
            $transfer = $this->transferService->requestTransfer(
                user: $user,
                roomUuid: $roomUuid,
                payload: $request->validated(),
                recipientDeviceUuid: $device->device_uuid
            );

            return response()->json([
                'status'  => 'success',
                'message' => 'Permintaan transfer riwayat berhasil diinisiasi.',
                'data'    => new BchatHistoryTransferResource($transfer),
            ], 201);
        } catch (BchatHistoryTransferException $e) {
            return $this->errorResponse($e->getMessage(), $e->getErrorCode(), $e->getHttpStatus());
        } catch (Throwable $e) {
            return $this->errorResponse('Terjadi kesalahan internal saat menginisiasi transfer: ' . $e->getMessage(), 'INTERNAL_SERVER_ERROR', 500);
        }
    }

    /**
     * [GET /api/bchat/history-transfers/pending]
     * Mengambil daftar permintaan transfer yang ditujukan kepada perangkat sumber.
     */
    public function pendingForSource(Request $request): JsonResponse
    {
        $user = $request->user();
        if (!$user) {
            return $this->unauthenticatedResponse();
        }

        $device = $this->getCallingDevice($request, $user);
        if ($device instanceof JsonResponse) {
            return $device;
        }

        $transfers = $this->transferService->getPendingTransfersForSource($user, $device);

        return response()->json([
            'status'  => 'success',
            'message' => 'Daftar transfer riwayat tertunda berhasil diambil.',
            'data'    => BchatHistoryTransferResource::collection($transfers),
        ], 200);
    }

    /**
     * [GET /api/bchat/history-transfers/{transferUuid}]
     * Mengambil status/detail sesi transfer riwayat.
     */
    public function show(Request $request, string $transferUuid): JsonResponse
    {
        $user = $request->user();
        if (!$user) {
            return $this->unauthenticatedResponse();
        }

        $device = $this->getCallingDevice($request, $user);
        if ($device instanceof JsonResponse) {
            return $device;
        }

        try {
            $transfer = $this->transferService->getTransferDetail($user, $transferUuid, $device);

            return response()->json([
                'status'  => 'success',
                'message' => 'Detail transfer riwayat berhasil diambil.',
                'data'    => new BchatHistoryTransferResource($transfer),
            ], 200);
        } catch (BchatHistoryTransferException $e) {
            return $this->errorResponse($e->getMessage(), $e->getErrorCode(), $e->getHttpStatus());
        } catch (Throwable $e) {
            return $this->errorResponse('Terjadi kesalahan internal: ' . $e->getMessage(), 'INTERNAL_SERVER_ERROR', 500);
        }
    }

    /**
     * [POST /api/bchat/history-transfers/{transferUuid}/upload]
     * Mengunggah snapshot ciphertext oleh perangkat sumber.
     */
    public function uploadSnapshot(BchatHistoryTransferUploadRequest $request, string $transferUuid): JsonResponse
    {
        $user = $request->user();
        if (!$user) {
            return $this->unauthenticatedResponse();
        }

        $device = $this->getCallingDevice($request, $user);
        if ($device instanceof JsonResponse) {
            return $device;
        }

        try {
            $transfer = $this->transferService->uploadEncryptedSnapshot(
                user: $user,
                transferUuid: $transferUuid,
                sourceDevice: $device,
                payload: $request->validated()
            );

            return response()->json([
                'status'  => 'success',
                'message' => 'Snapshot riwayat terenkripsi berhasil diunggah.',
                'data'    => new BchatHistoryTransferResource($transfer),
            ], 200);
        } catch (BchatHistoryTransferException $e) {
            return $this->errorResponse($e->getMessage(), $e->getErrorCode(), $e->getHttpStatus());
        } catch (Throwable $e) {
            return $this->errorResponse('Terjadi kesalahan internal saat mengunggah snapshot: ' . $e->getMessage(), 'INTERNAL_SERVER_ERROR', 500);
        }
    }

    /**
     * [GET /api/bchat/history-transfers/{transferUuid}/download]
     * Mengunduh snapshot ciphertext oleh perangkat penerima.
     */
    public function downloadSnapshot(Request $request, string $transferUuid): JsonResponse
    {
        $user = $request->user();
        if (!$user) {
            return $this->unauthenticatedResponse();
        }

        $device = $this->getCallingDevice($request, $user);
        if ($device instanceof JsonResponse) {
            return $device;
        }

        try {
            $data = $this->transferService->downloadEncryptedSnapshot($user, $transferUuid, $device);

            return response()->json([
                'status'  => 'success',
                'message' => 'Snapshot riwayat terenkripsi berhasil diambil.',
                'data'    => $data,
            ], 200);
        } catch (BchatHistoryTransferException $e) {
            return $this->errorResponse($e->getMessage(), $e->getErrorCode(), $e->getHttpStatus());
        } catch (Throwable $e) {
            return $this->errorResponse('Terjadi kesalahan internal saat mengunduh snapshot: ' . $e->getMessage(), 'INTERNAL_SERVER_ERROR', 500);
        }
    }

    /**
     * [POST /api/bchat/history-transfers/{transferUuid}/complete]
     * Menyelesaikan transfer setelah berhasil diimpor oleh penerima.
     */
    public function complete(BchatHistoryTransferCompleteRequest $request, string $transferUuid): JsonResponse
    {
        $user = $request->user();
        if (!$user) {
            return $this->unauthenticatedResponse();
        }

        $device = $this->getCallingDevice($request, $user);
        if ($device instanceof JsonResponse) {
            return $device;
        }

        try {
            $transfer = $this->transferService->completeTransfer(
                user: $user,
                transferUuid: $transferUuid,
                recipientDevice: $device,
                payload: $request->validated()
            );

            return response()->json([
                'status'  => 'success',
                'message' => 'Transfer riwayat berhasil diselesaikan.',
                'data'    => new BchatHistoryTransferResource($transfer),
            ], 200);
        } catch (BchatHistoryTransferException $e) {
            return $this->errorResponse($e->getMessage(), $e->getErrorCode(), $e->getHttpStatus());
        } catch (Throwable $e) {
            return $this->errorResponse('Terjadi kesalahan internal saat menyelesaikan transfer: ' . $e->getMessage(), 'INTERNAL_SERVER_ERROR', 500);
        }
    }

    /**
     * [POST /api/bchat/history-transfers/{transferUuid}/cancel]
     * Membatalkan sesi transfer.
     */
    public function cancel(Request $request, string $transferUuid): JsonResponse
    {
        $user = $request->user();
        if (!$user) {
            return $this->unauthenticatedResponse();
        }

        $device = $this->getCallingDevice($request, $user);
        if ($device instanceof JsonResponse) {
            return $device;
        }

        try {
            $reason = (string) $request->input('reason', 'Dibatalkan oleh pengguna/perangkat.');
            $transfer = $this->transferService->cancelTransfer($user, $transferUuid, $device, $reason);

            return response()->json([
                'status'  => 'success',
                'message' => 'Sesi transfer riwayat berhasil dibatalkan.',
                'data'    => new BchatHistoryTransferResource($transfer),
            ], 200);
        } catch (BchatHistoryTransferException $e) {
            return $this->errorResponse($e->getMessage(), $e->getErrorCode(), $e->getHttpStatus());
        } catch (Throwable $e) {
            return $this->errorResponse('Terjadi kesalahan internal saat membatalkan transfer: ' . $e->getMessage(), 'INTERNAL_SERVER_ERROR', 500);
        }
    }

    protected function getCallingDevice(Request $request, $user): BchatUserDevice|JsonResponse
    {
        $deviceUuid = $request->header('X-Bchat-Device-Uuid');
        if (!$deviceUuid) {
            return response()->json([
                'status'     => 'error',
                'message'    => 'Header X-Bchat-Device-Uuid wajib disertakan.',
                'error_code' => 'MISSING_DEVICE_UUID_HEADER',
            ], 422);
        }

        $device = BchatUserDevice::where('device_uuid', strtolower(trim($deviceUuid)))
            ->where('user_id', $user->id)
            ->first();

        if (!$device || $device->status !== 'ACTIVE') {
            return response()->json([
                'status'     => 'error',
                'message'    => 'Perangkat tidak ditemukan atau tidak aktif.',
                'error_code' => 'DEVICE_NOT_ACTIVE',
            ], 403);
        }

        return $device;
    }

    protected function unauthenticatedResponse(): JsonResponse
    {
        return response()->json([
            'status'     => 'error',
            'message'    => 'Pengguna belum terautentikasi.',
            'error_code' => 'UNAUTHENTICATED',
        ], 401);
    }

    protected function errorResponse(string $message, string $errorCode, int $httpStatus): JsonResponse
    {
        return response()->json([
            'status'     => 'error',
            'message'    => $message,
            'error_code' => $errorCode,
        ], $httpStatus);
    }
}

