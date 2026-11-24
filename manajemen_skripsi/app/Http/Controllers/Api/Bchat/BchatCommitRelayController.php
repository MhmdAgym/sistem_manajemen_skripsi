<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Bchat;

use App\Exceptions\BchatCommitRelayException;
use App\Http\Controllers\Controller;
use App\Models\BchatUserDevice;
use App\Services\BchatCommitRelayService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

class BchatCommitRelayController extends Controller
{
    public function __construct(
        protected BchatCommitRelayService $commitRelayService
    ) {
    }

    /**
     * POST /api/bchat/rooms/{roomUuid}/commits
     * Menerbitkan Commit baru untuk di-relay (Two-Phase Commit).
     */
    public function publish(Request $request, string $roomUuid): JsonResponse
    {
        $validated = $request->validate([
            'device_uuid'            => ['required', 'string', 'size:36'],
            'from_epoch'             => ['required', 'integer', 'min:0'],
            'to_epoch'               => ['required', 'integer', 'min:1'],
            'commit_wire_base64'     => ['required', 'string'],
            'commit_uuid'            => ['nullable', 'string', 'size:36'],
            'added_device_uuid'      => ['nullable', 'string', 'size:36'],
            'key_package_hash'       => ['nullable', 'string', 'size:64'],
            'welcome_wire_base64'    => ['nullable', 'string'],
        ]);

        $validated['room_uuid'] = $roomUuid;

        try {
            $result = $this->commitRelayService->publishCommit($request->user(), $validated);
            $commit = $result['commit'];

            return response()->json([
                'success'     => true,
                'is_replayed' => $result['is_replayed'],
                'data'        => [
                    'commit_uuid'            => $commit->commit_uuid,
                    'room_uuid'              => $roomUuid,
                    'from_epoch'             => (string) $commit->from_epoch,
                    'to_epoch'               => (string) $commit->to_epoch,
                    'commit_hash'            => $commit->commit_hash,
                    'status'                 => $commit->status,
                    'added_device_uuid'      => $commit->addedDevice?->device_uuid,
                    'welcome_operation_uuid' => $commit->welcome_operation_uuid,
                    'created_at'             => $commit->created_at?->toIso8601String(),
                ],
            ], $result['is_replayed'] ? 200 : 201);
        } catch (BchatCommitRelayException $e) {
            return $e->render();
        } catch (Throwable $e) {
            return response()->json([
                'success'    => false,
                'message'    => 'Terjadi kesalahan sistem saat mempublikasikan Commit: ' . $e->getMessage(),
                'error_code' => 'INTERNAL_COMMIT_ERROR',
            ], 500);
        }
    }

    /**
     * GET /api/bchat/rooms/{roomUuid}/commits
     * Mengambil daftar Commit yang perlu diproses perangkat ini.
     */
    public function index(Request $request, string $roomUuid): JsonResponse
    {
        $deviceUuid = (string) $request->header('X-Device-UUID', '');
        if (empty($deviceUuid)) {
            $deviceUuid = (string) $request->query('device_uuid', '');
        }

        if (empty($deviceUuid)) {
            return response()->json([
                'success'    => false,
                'message'    => 'Header X-Device-UUID atau query device_uuid wajib disertakan.',
                'error_code' => 'DEVICE_UUID_REQUIRED',
            ], 422);
        }

        $device = BchatUserDevice::where('device_uuid', $deviceUuid)
            ->where('user_id', $request->user()->id)
            ->first();

        if (!$device || $device->status !== 'ACTIVE' || !$device->is_trusted) {
            return response()->json([
                'success'    => false,
                'message'    => 'Perangkat tidak valid atau belum terverifikasi tepercaya.',
                'error_code' => 'DEVICE_NOT_TRUSTED',
            ], 403);
        }

        $sinceEpoch = $request->query('since_epoch') !== null ? (int) $request->query('since_epoch') : null;
        $includeActiveWelcome = filter_var($request->query('include_active_welcome', false), FILTER_VALIDATE_BOOLEAN);

        try {
            $commits = $this->commitRelayService->getPendingCommits(
                $request->user(),
                $device,
                $roomUuid,
                $sinceEpoch,
                $includeActiveWelcome
            );

            return response()->json([
                'success' => true,
                'data'    => $commits,
            ]);
        } catch (BchatCommitRelayException $e) {
            return $e->render();
        } catch (Throwable $e) {
            return response()->json([
                'success'    => false,
                'message'    => 'Gagal mengambil daftar Commit: ' . $e->getMessage(),
                'error_code' => 'FETCH_COMMITS_ERROR',
            ], 500);
        }
    }

    /**
     * POST /api/bchat/rooms/{roomUuid}/commits/{commitUuid}/ack
     * Mengirim bukti penerimaan dan pemrosesan Commit untuk ANGGOTA LAMA.
     */
    public function ack(Request $request, string $roomUuid, string $commitUuid): JsonResponse
    {
        $validated = $request->validate([
            'device_uuid' => ['required', 'string', 'size:36'],
            'signature'   => ['required', 'string'],
            'acked_epoch' => ['required', 'integer', 'min:1'],
        ]);

        $device = BchatUserDevice::where('device_uuid', $validated['device_uuid'])
            ->where('user_id', $request->user()->id)
            ->first();

        if (!$device || $device->status !== 'ACTIVE' || !$device->is_trusted) {
            return response()->json([
                'success'    => false,
                'message'    => 'Perangkat tidak valid atau belum terverifikasi tepercaya.',
                'error_code' => 'DEVICE_NOT_TRUSTED',
            ], 403);
        }

        try {
            $result = $this->commitRelayService->ackCommit(
                $request->user(),
                $device,
                $commitUuid,
                $validated['signature'],
                (int) $validated['acked_epoch']
            );

            return response()->json([
                'success'     => true,
                'is_replayed' => $result['is_replayed'],
                'status'      => $result['status'],
                'is_complete' => $result['is_complete'] ?? false,
                'message'     => 'Commit berhasil di-ACK.',
            ], 200);
        } catch (BchatCommitRelayException $e) {
            return $e->render();
        } catch (Throwable $e) {
            return response()->json([
                'success'    => false,
                'message'    => 'Gagal memproses ACK Commit: ' . $e->getMessage(),
                'error_code' => 'COMMIT_ACK_ERROR',
            ], 500);
        }
    }

    /**
     * POST /api/bchat/rooms/{roomUuid}/commits/{commitUuid}/welcome-ack
     * Mengirim bukti penerimaan dan pemrosesan Welcome untuk ANGGOTA BARU.
     */
    public function welcomeAck(Request $request, string $roomUuid, string $commitUuid): JsonResponse
    {
        $validated = $request->validate([
            'device_uuid' => ['required', 'string', 'size:36'],
            'signature'   => ['required', 'string'],
            'acked_epoch' => ['required', 'integer', 'min:1'],
        ]);

        $device = BchatUserDevice::where('device_uuid', $validated['device_uuid'])
            ->where('user_id', $request->user()->id)
            ->first();

        if (!$device || $device->status !== 'ACTIVE' || !$device->is_trusted) {
            return response()->json([
                'success'    => false,
                'message'    => 'Perangkat tidak valid atau belum terverifikasi tepercaya.',
                'error_code' => 'DEVICE_NOT_TRUSTED',
            ], 403);
        }

        try {
            $result = $this->commitRelayService->ackWelcome(
                $request->user(),
                $device,
                $commitUuid,
                $validated['signature'],
                (int) $validated['acked_epoch']
            );

            return response()->json([
                'success'     => true,
                'is_replayed' => $result['is_replayed'],
                'status'      => $result['status'],
                'is_complete' => $result['is_complete'] ?? false,
                'message'     => 'Welcome berhasil di-ACK oleh perangkat baru.',
            ], 200);
        } catch (BchatCommitRelayException $e) {
            return $e->render();
        } catch (Throwable $e) {
            return response()->json([
                'success'    => false,
                'message'    => 'Gagal memproses Welcome ACK: ' . $e->getMessage(),
                'error_code' => 'WELCOME_ACK_ERROR',
            ], 500);
        }
    }
}
