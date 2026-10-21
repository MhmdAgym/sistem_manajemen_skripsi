<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Middleware generik untuk memvalidasi role pengguna yang sedang login.
 * Digunakan setelah middleware auth:sanctum memastikan user terautentikasi.
 *
 * Penggunaan di routes: ->middleware(['auth:sanctum', 'role:mahasiswa'])
 *                        ->middleware(['auth:sanctum', 'role:dosen'])
 */
class EnsureUserIsRole
{
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $user = $request->user();

        if (!$user || !in_array($user->role, $roles)) {
            return response()->json([
                'message' => 'Akses ditolak. Anda tidak memiliki izin untuk mengakses fitur ini.',
            ], 403);
        }

        return $next($request);
    }
}
