<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\URL;
use Throwable;

class PasswordResetEmailService
{
    public function send(User $user, string $token): void
    {
        if (! $this->isEnabled()) {
            $user->notify(new ResetPassword($token));

            return;
        }

        $recipientEmail = $user->getEmailForPasswordReset();

        if ($recipientEmail === '') {
            return;
        }

        $resetUrl = URL::route('password.reset', [
            'token' => $token,
            'email' => $recipientEmail,
        ]);

        $recipientName = $user->name ?: $recipientEmail;

        $htmlContent = $this->htmlContent($recipientName, $resetUrl);
        $textContent = $this->textContent($recipientName, $resetUrl);

        try {
            Http::baseUrl((string) config('services.brevo.base_url', 'https://api.brevo.com/v3'))
                ->withHeaders([
                    'api-key' => (string) config('services.brevo.key'),
                    'accept' => 'application/json',
                ])
                ->asJson()
                ->post('/smtp/email', [
                    'sender' => [
                        'name' => (string) config('services.brevo.sender_name', config('app.name')),
                        'email' => (string) config('services.brevo.sender_email'),
                    ],
                    'to' => [[
                        'email' => $recipientEmail,
                        'name' => $recipientName,
                    ]],
                    'subject' => 'Reset your Sync360 password',
                    'htmlContent' => $htmlContent,
                    'textContent' => $textContent,
                ])
                ->throw();
        } catch (Throwable $exception) {
            Log::warning('Sync360 failed to send password reset email through Brevo. Falling back to default notification pipeline.', [
                'user_id' => $user->id,
                'recipient' => $recipientEmail,
                'error' => $exception->getMessage(),
            ]);

            $user->notify(new ResetPassword($token));
        }
    }

    private function isEnabled(): bool
    {
        return (bool) config('services.brevo.enabled')
            && (string) config('services.brevo.key') !== ''
            && (string) config('services.brevo.sender_email') !== '';
    }

    private function htmlContent(string $recipientName, string $resetUrl): string
    {
        $name = e($recipientName);
        $url = e($resetUrl);

        return <<<HTML
<p>Hi {$name},</p>
<p>We received a request to reset the password for your Sync360 account.</p>
<p><a href="{$url}">Reset your password</a></p>
<p>This link will expire automatically. If you did not request a password reset, you can safely ignore this email.</p>
<p>Regards,<br>Sync360</p>
HTML;
    }

    private function textContent(string $recipientName, string $resetUrl): string
    {
        return trim(implode(PHP_EOL, [
            sprintf('Hi %s,', $recipientName),
            '',
            'We received a request to reset the password for your Sync360 account.',
            '',
            sprintf('Reset your password: %s', $resetUrl),
            '',
            'This link will expire automatically. If you did not request a password reset, you can safely ignore this email.',
            '',
            'Regards,',
            'Sync360',
        ]));
    }
}
