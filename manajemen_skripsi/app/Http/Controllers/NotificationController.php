<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class NotificationController extends Controller
{
    /**
     * [AUTHENTICATED] Ambil daftar notifikasi milik pengguna yang sedang login.
     *
     * Dilengkapi paginasi dan pengurutan stabil (created_at DESC, id DESC).
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        $perPage = min(max((int) $request->query('per_page', 15), 1), 50);

        $notifications = $user->notifications()
            ->orderBy('created_at', 'desc')
            ->orderBy('id', 'desc')
            ->paginate($perPage);

        return response()->json([
            'status' => 'success',
            'data'   => $notifications->items(),
            'meta'   => [
                'current_page' => $notifications->currentPage(),
                'last_page'    => $notifications->lastPage(),
                'per_page'     => $notifications->perPage(),
                'total'        => $notifications->total(),
            ],
        ]);
    }

    /**
     * [AUTHENTICATED] Ambil jumlah notifikasi belum dibaca milik pengguna yang sedang login.
     */
    public function unreadCount(Request $request): JsonResponse
    {
        $user = $request->user();

        $count = $user->unreadNotifications()->count();

        return response()->json([
            'status' => 'success',
            'data'   => [
                'unread_count' => $count,
            ],
        ]);
    }

    /**
     * [AUTHENTICATED] Tandai satu notifikasi sebagai sudah dibaca.
     *
     * Proteksi Anti-IDOR: Hanya notifikasi yang berelasi dengan user aktif yang dapat diakses.
     */
    public function markAsRead(Request $request, string $id): JsonResponse
    {
        $user = $request->user();

        $notification = $user->notifications()->where('id', $id)->first();

        if (!$notification) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Notifikasi tidak ditemukan atau Anda tidak memiliki akses.',
            ], 404);
        }

        if (is_null($notification->read_at)) {
            $notification->markAsRead();
        }

        return response()->json([
            'status'  => 'success',
            'message' => 'Notifikasi berhasil ditandai sebagai sudah dibaca.',
            'data'    => $notification->fresh(),
        ]);
    }

    /**
     * [AUTHENTICATED] Tandai semua notifikasi belum dibaca milik pengguna aktif sebagai sudah dibaca.
     *
     * Eksekusi langsung melalui query builder update untuk efisiensi basis data.
     */
    public function markAllRead(Request $request): JsonResponse
    {
        $user = $request->user();

        $updatedCount = $user->unreadNotifications()->update([
            'read_at' => now(),
        ]);

        return response()->json([
            'status'  => 'success',
            'message' => 'Semua notifikasi berhasil ditandai sebagai sudah dibaca.',
            'data'    => [
                'updated_count' => $updatedCount,
            ],
        ]);
    }
}
