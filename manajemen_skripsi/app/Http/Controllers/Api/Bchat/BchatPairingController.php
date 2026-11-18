<?php

namespace App\Http\Controllers\Api\Bchat;

use App\Exceptions\BchatPairingException;
use App\Http\Controllers\Controller;
use App\Services\BchatPairingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

class BchatPairingController extends Controller
{
    public function __construct(
        protected BchatPairingService $pairingService
    ) {}

    /**
     * POST /api/bchat/pairing/request
     * Membuat permintaan pairing untuk perangkat baru.
     */
    public function request(Request $request): JsonResponse
    {
        $targetDeviceUuid = $request->input('target_device_uuid') ?: $request->header('X-Bchat-Device-Uuid');

        if (!$targetDeviceUuid) {
            return response()->json([
                'status'     => 'error',
                'error_code' => 'TARGET_DEVICE_UUID_REQUIRED',
                'message'    => 'Target Device UUID wajib disertakan via body atau header X-Bchat-Device-Uuid.',
            ], 422);
        }

        try {
            $data = $this->pairingService->createPairingRequest($request->user(), $targetDeviceUuid);

            return response()->json([
                'status'  => 'success',
                'message' => 'Permintaan pairing berhasil dibuat.',
                'data'    => $data,
            ], 201);
        } catch (BchatPairingException $e) {
            return response()->json([
                'status'     => 'error',
                'error_code' => $e->getErrorCode(),
                'message'    => $e->getMessage(),
            ], $e->getStatusCode());
        } catch (Throwable $e) {
            return response()->json([
                'status'     => 'error',
                'error_code' => 'PAIRING_REQUEST_FAILED',
                'message'    => 'Terjadi kesalahan sistem saat membuat permintaan pairing.',
            ], 500);
        }
    }

    /**
     * GET /api/bchat/pairing/{pairingUuid}
     * Memeriksa status sesi pairing (polling/status endpoint).
     */
    public function status(Request $request, string $pairingUuid): JsonResponse
    {
        try {
            $data = $this->pairingService->getPairingStatus($request->user(), $pairingUuid);

            return response()->json([
                'status' => 'success',
                'data'   => $data,
            ], 200);
        } catch (BchatPairingException $e) {
            return response()->json([
                'status'     => 'error',
                'error_code' => $e->getErrorCode(),
                'message'    => $e->getMessage(),
            ], $e->getStatusCode());
        } catch (Throwable $e) {
            return response()->json([
                'status'     => 'error',
                'error_code' => 'PAIRING_STATUS_FAILED',
                'message'    => 'Terjadi kesalahan sistem saat memeriksa status pairing.',
            ], 500);
        }
    }

    /**
     * POST /api/bchat/pairing/lookup
     * Mencari detail pairing berdasarkan kode pairing (untuk approver device).
     */
    public function lookup(Request $request): JsonResponse
    {
        $request->validate([
            'pairing_code' => 'required|string|max:16',
        ]);

        try {
            $data = $this->pairingService->lookupPairingByCode($request->user(), $request->input('pairing_code'));

            return response()->json([
                'status' => 'success',
                'data'   => $data,
            ], 200);
        } catch (BchatPairingException $e) {
            return response()->json([
                'status'     => 'error',
                'error_code' => $e->getErrorCode(),
                'message'    => $e->getMessage(),
            ], $e->getStatusCode());
        } catch (Throwable $e) {
            return response()->json([
                'status'     => 'error',
                'error_code' => 'PAIRING_LOOKUP_FAILED',
                'message'    => 'Terjadi kesalahan sistem saat mencari kode pairing.',
            ], 500);
        }
    }

    /**
     * POST /api/bchat/pairing/{pairingUuid}/approve
     * Menyetujui pairing dengan verifikasi kriptografis Ed25519 dari approver device.
     */
    public function approve(Request $request, string $pairingUuid): JsonResponse
    {
        $request->validate([
            'approver_device_uuid' => 'required|string|uuid',
            'approval_payload'     => 'required|string',
            'approval_signature'   => 'required|string',
        ]);

        try {
            $data = $this->pairingService->approvePairing(
                $request->user(),
                $pairingUuid,
                $request->input('approver_device_uuid'),
                $request->input('approval_payload'),
                $request->input('approval_signature')
            );

            return response()->json([
                'status'  => 'success',
                'message' => 'Perangkat berhasil disetujui sebagai perangkat tepercaya.',
                'data'    => $data,
            ], 200);
        } catch (BchatPairingException $e) {
            return response()->json([
                'status'     => 'error',
                'error_code' => $e->getErrorCode(),
                'message'    => $e->getMessage(),
            ], $e->getStatusCode());
        } catch (Throwable $e) {
            return response()->json([
                'status'     => 'error',
                'error_code' => 'PAIRING_APPROVAL_FAILED',
                'message'    => 'Terjadi kesalahan sistem saat menyetujui pairing.',
            ], 500);
        }
    }

    /**
     * POST /api/bchat/pairing/{pairingUuid}/reject
     * Menolak permintaan pairing dari approver device.
     */
    public function reject(Request $request, string $pairingUuid): JsonResponse
    {
        $request->validate([
            'approver_device_uuid' => 'required|string|uuid',
            'reason'               => 'nullable|string|max:255',
        ]);

        try {
            $data = $this->pairingService->rejectPairing(
                $request->user(),
                $pairingUuid,
                $request->input('approver_device_uuid'),
                $request->input('reason')
            );

            return response()->json([
                'status'  => 'success',
                'message' => 'Permintaan pairing berhasil ditolak.',
                'data'    => $data,
            ], 200);
        } catch (BchatPairingException $e) {
            return response()->json([
                'status'     => 'error',
                'error_code' => $e->getErrorCode(),
                'message'    => $e->getMessage(),
            ], $e->getStatusCode());
        } catch (Throwable $e) {
            return response()->json([
                'status'     => 'error',
                'error_code' => 'PAIRING_REJECTION_FAILED',
                'message'    => 'Terjadi kesalahan sistem saat menolak pairing.',
            ], 500);
        }
    }

    /**
     * POST /api/bchat/pairing/{pairingUuid}/cancel
     * Membatalkan permintaan pairing oleh perangkat target.
     */
    public function cancel(Request $request, string $pairingUuid): JsonResponse
    {
        $targetDeviceUuid = $request->input('target_device_uuid') ?: $request->header('X-Bchat-Device-Uuid');

        if (!$targetDeviceUuid) {
            return response()->json([
                'status'     => 'error',
                'error_code' => 'TARGET_DEVICE_UUID_REQUIRED',
                'message'    => 'Target Device UUID wajib disertakan via body atau header X-Bchat-Device-Uuid.',
            ], 422);
        }

        try {
            $data = $this->pairingService->cancelPairing(
                $request->user(),
                $pairingUuid,
                $targetDeviceUuid
            );

            return response()->json([
                'status'  => 'success',
                'message' => 'Permintaan pairing berhasil dibatalkan.',
                'data'    => $data,
            ], 200);
        } catch (BchatPairingException $e) {
            return response()->json([
                'status'     => 'error',
                'error_code' => $e->getErrorCode(),
                'message'    => $e->getMessage(),
            ], $e->getStatusCode());
        } catch (Throwable $e) {
            return response()->json([
                'status'     => 'error',
                'error_code' => 'PAIRING_CANCELLATION_FAILED',
                'message'    => 'Terjadi kesalahan sistem saat membatalkan pairing.',
            ], 500);
        }
    }
}

