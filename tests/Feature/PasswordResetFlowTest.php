<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Tests\TestCase;

class PasswordResetFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_can_request_a_password_reset_link(): void
    {
        Notification::fake();

        $user = User::query()->create([
            'name' => 'Alice Admin',
            'email' => 'alice@example.com',
            'password' => 'super-secret',
        ]);

        $response = $this->from(route('password.request'))->post(route('password.email'), [
            'email' => $user->email,
        ]);

        $response
            ->assertRedirect(route('password.request'))
            ->assertSessionHas('status');

        $this->assertDatabaseHas('password_reset_tokens', [
            'email' => $user->email,
        ]);

        Notification::assertSentTo($user, ResetPassword::class);
    }

    public function test_guest_password_reset_request_uses_brevo_when_enabled(): void
    {
        config()->set('services.brevo.enabled', true);
        config()->set('services.brevo.key', 'test-brevo-key');
        config()->set('services.brevo.sender_email', 'hello@sync360.test');
        config()->set('services.brevo.sender_name', 'Sync360');

        Http::fake([
            'https://api.brevo.com/v3/smtp/email' => Http::response(['messageId' => 'brevo-reset-123'], 201),
        ]);

        $user = User::query()->create([
            'name' => 'Alice Admin',
            'email' => 'alice@example.com',
            'password' => 'super-secret',
        ]);

        $response = $this->from(route('password.request'))->post(route('password.email'), [
            'email' => $user->email,
        ]);

        $response
            ->assertRedirect(route('password.request'))
            ->assertSessionHas('status');

        Http::assertSent(function ($request) use ($user) {
            $payload = $request->data();
            $htmlContent = (string) ($payload['htmlContent'] ?? '');

            return $request->url() === 'https://api.brevo.com/v3/smtp/email'
                && $request->hasHeader('api-key', 'test-brevo-key')
                && ($payload['to'][0]['email'] ?? null) === $user->email
                && ($payload['subject'] ?? null) === 'Reset your Sync360 password'
                && str_contains($htmlContent, '/reset-password/')
                && str_contains($htmlContent, 'email='.urlencode($user->email));
        });
    }

    public function test_user_can_reset_password_from_valid_token(): void
    {
        $user = User::query()->create([
            'name' => 'Alice Admin',
            'email' => 'alice@example.com',
            'password' => 'super-secret',
        ]);

        $token = Password::broker()->createToken($user);

        $response = $this->post(route('password.update'), [
            'token' => $token,
            'email' => $user->email,
            'password' => 'new-secret-pass',
            'password_confirmation' => 'new-secret-pass',
        ]);

        $response
            ->assertRedirect(route('login'))
            ->assertSessionHas('status', 'Your password has been reset. You can log in with your new password now.');

        $user->refresh();

        $this->assertTrue(Hash::check('new-secret-pass', $user->password));
        $this->assertFalse(Hash::check('super-secret', $user->password));
    }

    public function test_invalid_reset_token_shows_validation_error_and_keeps_existing_password(): void
    {
        $user = User::query()->create([
            'name' => 'Alice Admin',
            'email' => 'alice@example.com',
            'password' => 'super-secret',
        ]);

        $response = $this->from(route('password.reset', ['token' => 'invalid-token', 'email' => $user->email]))
            ->post(route('password.update'), [
                'token' => 'invalid-token',
                'email' => $user->email,
                'password' => 'new-secret-pass',
                'password_confirmation' => 'new-secret-pass',
            ]);

        $response
            ->assertRedirect(route('password.reset', ['token' => 'invalid-token', 'email' => $user->email]))
            ->assertSessionHasErrors('email');

        $user->refresh();

        $this->assertTrue(Hash::check('super-secret', $user->password));
    }
}
