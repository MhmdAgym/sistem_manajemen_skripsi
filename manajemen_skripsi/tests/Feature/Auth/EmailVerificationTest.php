<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Auth\Events\Verified;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class EmailVerificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_email_can_be_verified(): void
    {
        $user = User::factory()->unverified()->create();

        Event::fake();

        $verificationUrl = URL::temporarySignedRoute(
            'verification.verify',
            now()->addMinutes(60),
            ['id' => $user->id, 'hash' => sha1($user->email)]
        );

        $response = $this->actingAs($user)->get($verificationUrl);

        Event::assertDispatched(Verified::class);
        $this->assertTrue($user->fresh()->hasVerifiedEmail());
        $response->assertRedirect(config('app.frontend_url').'/dashboardmahasiswa?verified=1');
    }

    public function test_email_can_be_verified_without_active_session(): void
    {
        $user = User::factory()->unverified()->create();

        Event::fake();

        $verificationUrl = URL::temporarySignedRoute(
            'verification.verify',
            now()->addMinutes(60),
            ['id' => $user->id, 'hash' => sha1($user->email)]
        );

        $response = $this->get($verificationUrl);

        Event::assertDispatched(Verified::class);
        $this->assertTrue($user->fresh()->hasVerifiedEmail());
        $response->assertRedirect(config('app.frontend_url').'/dashboardmahasiswa?verified=1');
    }

    public function test_email_verification_redirects_to_dosen_dashboard(): void
    {
        $user = User::factory()->unverified()->create(['role' => 'dosen']);

        Event::fake();

        $verificationUrl = URL::temporarySignedRoute(
            'verification.verify',
            now()->addMinutes(60),
            ['id' => $user->id, 'hash' => sha1($user->email)]
        );

        $response = $this->get($verificationUrl);

        Event::assertDispatched(Verified::class);
        $this->assertTrue($user->fresh()->hasVerifiedEmail());
        $response->assertRedirect(config('app.frontend_url').'/dashboarddosen?verified=1');
    }

    public function test_email_verification_redirects_to_admin_dashboard(): void
    {
        $user = User::factory()->unverified()->create(['role' => 'admin']);

        Event::fake();

        $verificationUrl = URL::temporarySignedRoute(
            'verification.verify',
            now()->addMinutes(60),
            ['id' => $user->id, 'hash' => sha1($user->email)]
        );

        $response = $this->get($verificationUrl);

        Event::assertDispatched(Verified::class);
        $this->assertTrue($user->fresh()->hasVerifiedEmail());
        $response->assertRedirect(config('app.frontend_url').'/dashboardadmin?verified=1');
    }

    public function test_email_is_not_verified_with_invalid_hash(): void
    {
        $user = User::factory()->unverified()->create();

        $verificationUrl = URL::temporarySignedRoute(
            'verification.verify',
            now()->addMinutes(60),
            ['id' => $user->id, 'hash' => sha1('wrong-email')]
        );

        $response = $this->get($verificationUrl);

        $response->assertStatus(403);
        $this->assertFalse($user->fresh()->hasVerifiedEmail());
    }

    public function test_custom_verification_email_template_renders_properly(): void
    {
        $user = User::factory()->unverified()->create([
            'name' => 'Ahmad Agym',
            'email' => 'agym@student.unuha.ac.id',
        ]);

        $notification = new \Illuminate\Auth\Notifications\VerifyEmail();
        $mail = $notification->toMail($user);

        $this->assertEquals('Verifikasi Alamat Email - Sistem Manajemen Skripsi', $mail->subject);
        $this->assertEquals('emails.verify-email', $mail->view);

        $rendered = (string) $mail->render();
        $this->assertStringContainsString('Sistem Manajemen Skripsi', $rendered);
        $this->assertStringContainsString('Universitas Nurul Huda', $rendered);
        $this->assertStringContainsString('Ahmad Agym', $rendered);
        $this->assertStringContainsString('Verifikasi Email', $rendered);
        $this->assertStringContainsString('verify-email/' . $user->id, $rendered);
    }
}
