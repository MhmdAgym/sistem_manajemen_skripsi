<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class EmailVerificationNotificationController extends Controller
{
    /**
     * Send a new email verification notification.
     */
    public function store(Request $request): JsonResponse|RedirectResponse
    {
        if ($request->user()->hasVerifiedEmail()) {
            $redirectPath = match ($request->user()->role) {
                'admin' => '/dashboardadmin',
                'dosen' => '/dashboarddosen',
                'mahasiswa' => '/dashboardmahasiswa',
                default => '/login',
            };

            return $request->wantsJson()
                ? response()->json(['status' => 'already-verified'])
                : redirect()->intended(config('app.frontend_url').$redirectPath);
        }

        $request->user()->sendEmailVerificationNotification();

        return response()->json(['status' => 'verification-link-sent']);
    }
}
