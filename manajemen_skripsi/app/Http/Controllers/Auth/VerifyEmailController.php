<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Auth\Events\Verified;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class VerifyEmailController extends Controller
{
    /**
     * Mark the user's email address as verified.
     */
    public function __invoke(Request $request): RedirectResponse
    {
        $user = User::find($request->route('id'));

        if (! $user) {
            abort(404, 'User not found.');
        }

        if (! hash_equals(sha1($user->getEmailForVerification()), (string) $request->route('hash'))) {
            abort(403, 'Invalid verification link.');
        }

        if (! $user->hasVerifiedEmail()) {
            if ($user->markEmailAsVerified()) {
                event(new Verified($user));
            }
        }

        $redirectPath = match ($user->role) {
            'admin' => '/dashboardadmin',
            'dosen' => '/dashboarddosen',
            'mahasiswa' => '/dashboardmahasiswa',
            default => '/login',
        };

        return redirect()->intended(
            config('app.frontend_url').$redirectPath.'?verified=1'
        );
    }
}
