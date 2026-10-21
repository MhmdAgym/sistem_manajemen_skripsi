<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->api(prepend: [
            \Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful::class,
        ]);

        // Mengecualikan seluruh rute API dari pemeriksaan CSRF (Mengatasi error CSRF token mismatch)
        $middleware->validateCsrfTokens(except: [
            'api/*',
        ]);

        $middleware->alias([
            'verified' => \App\Http\Middleware\EnsureEmailIsVerified::class,
            'admin'    => \App\Http\Middleware\EnsureUserIsAdmin::class,
            'role'     => \App\Http\Middleware\EnsureUserIsRole::class,
        ]);

        $middleware->redirectGuestsTo(fn () => config('app.frontend_url').'/login');
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();