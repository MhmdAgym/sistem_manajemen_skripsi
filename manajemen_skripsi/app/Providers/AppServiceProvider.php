<?php

namespace App\Providers;

use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        ResetPassword::toMailUsing(function (object $notifiable, string $token) {
            $url = config('app.frontend_url')."/password-reset/$token?email={$notifiable->getEmailForPasswordReset()}";

            return (new MailMessage)
                ->subject('Reset Password - Sistem Manajemen Skripsi')
                ->view('emails.reset-password', [
                    'user' => $notifiable,
                    'url' => $url,
                ]);
        });

        VerifyEmail::toMailUsing(function (object $notifiable, string $url) {
            return (new MailMessage)
                ->subject('Verifikasi Alamat Email - Sistem Manajemen Skripsi')
                ->view('emails.verify-email', [
                    'user' => $notifiable,
                    'url' => $url,
                ]);
        });
    }
}
