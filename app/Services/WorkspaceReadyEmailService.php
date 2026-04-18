<?php

namespace App\Services;

use App\Models\ProvisioningJob;
use App\Models\Tenant;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class WorkspaceReadyEmailService
{
    public const PAYLOAD_LOGIN_EMAIL = 'workspace_login_email';

    public const PAYLOAD_PASSWORD_ENCRYPTED = 'workspace_password_encrypted';

    public const PAYLOAD_EMAIL_SENT_AT = 'workspace_ready_email_sent_at';

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function withProvisioningCredentials(array $payload, string $loginEmail, string $plainPassword): array
    {
        $payload[self::PAYLOAD_LOGIN_EMAIL] = $loginEmail;
        $payload[self::PAYLOAD_PASSWORD_ENCRYPTED] = Crypt::encryptString($plainPassword);

        return $payload;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function carryForwardCredentials(array $payload, ?ProvisioningJob $sourceJob, ?string $fallbackEmail = null): array
    {
        $sourcePayload = $sourceJob?->payload_json ?? [];

        if (! isset($payload[self::PAYLOAD_LOGIN_EMAIL]) && isset($sourcePayload[self::PAYLOAD_LOGIN_EMAIL])) {
            $payload[self::PAYLOAD_LOGIN_EMAIL] = $sourcePayload[self::PAYLOAD_LOGIN_EMAIL];
        }

        if (! isset($payload[self::PAYLOAD_PASSWORD_ENCRYPTED]) && isset($sourcePayload[self::PAYLOAD_PASSWORD_ENCRYPTED])) {
            $payload[self::PAYLOAD_PASSWORD_ENCRYPTED] = $sourcePayload[self::PAYLOAD_PASSWORD_ENCRYPTED];
        }

        if (! isset($payload[self::PAYLOAD_LOGIN_EMAIL]) && $fallbackEmail) {
            $payload[self::PAYLOAD_LOGIN_EMAIL] = $fallbackEmail;
        }

        return $payload;
    }

    public function sendWorkspaceReadyEmail(Tenant $tenant, ProvisioningJob $provisioningJob): void
    {
        if (! $this->isEnabled()) {
            return;
        }

        $payload = $provisioningJob->payload_json ?? [];

        if (isset($payload[self::PAYLOAD_EMAIL_SENT_AT])) {
            return;
        }

        $recipientEmail = Arr::get($payload, self::PAYLOAD_LOGIN_EMAIL, $tenant->user?->email);
        $encryptedPassword = Arr::get($payload, self::PAYLOAD_PASSWORD_ENCRYPTED);

        if (! is_string($recipientEmail) || $recipientEmail === '' || ! is_string($encryptedPassword) || $encryptedPassword === '') {
            return;
        }

        try {
            $plainPassword = Crypt::decryptString($encryptedPassword);
        } catch (DecryptException $exception) {
            Log::warning('Sync360 could not decrypt the stored workspace password for the ready email.', [
                'tenant_id' => $tenant->id,
                'provisioning_job_id' => $provisioningJob->id,
                'error' => $exception->getMessage(),
            ]);

            return;
        }

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
                        'name' => $tenant->user?->name ?: $recipientEmail,
                    ]],
                    'subject' => sprintf('Your %s workspace has been created', $tenant->business_name),
                    'htmlContent' => $this->htmlContent($tenant, $recipientEmail, $plainPassword),
                    'textContent' => $this->textContent($tenant, $recipientEmail, $plainPassword),
                ])
                ->throw();
        } catch (Throwable $exception) {
            Log::warning('Sync360 failed to send the workspace ready email through Brevo.', [
                'tenant_id' => $tenant->id,
                'provisioning_job_id' => $provisioningJob->id,
                'recipient' => $recipientEmail,
                'error' => $exception->getMessage(),
            ]);

            return;
        }

        unset($payload[self::PAYLOAD_PASSWORD_ENCRYPTED]);
        $payload[self::PAYLOAD_EMAIL_SENT_AT] = now()->toIso8601String();

        $provisioningJob->forceFill([
            'payload_json' => $payload,
        ])->save();
    }

    private function isEnabled(): bool
    {
        return (bool) config('services.brevo.enabled')
            && (string) config('services.brevo.key') !== ''
            && (string) config('services.brevo.sender_email') !== '';
    }

    private function htmlContent(Tenant $tenant, string $loginEmail, string $plainPassword): string
    {
        $workspaceUrl = e((string) $tenant->workspace_url);
        $recipientName = e($tenant->user?->name ?: $tenant->business_name);
        $businessName = e($tenant->business_name);
        $email = e($loginEmail);
        $password = e($plainPassword);

        return <<<HTML
<p>Hi {$recipientName},</p>
<p>Your <strong>{$businessName}</strong> workspace has been created.</p>
<p>You can log in using the details below and continue your setup inside Sync360:</p>
<p>
  <strong>Sync360 workspace URL:</strong> <a href="{$workspaceUrl}">{$workspaceUrl}</a><br>
  <strong>Username:</strong> {$email}<br>
  <strong>Password:</strong> {$password}
</p>
<p>We recommend signing in to Sync360, updating your password after your first login, and finishing the remaining setup steps.</p>
<p>If you need any help getting started, just reply to this email and our team will be happy to help.</p>
<p>Regards,<br>Sync360</p>
HTML;
    }

    private function textContent(Tenant $tenant, string $loginEmail, string $plainPassword): string
    {
        return trim(implode(PHP_EOL, [
            sprintf('Hi %s,', $tenant->user?->name ?: $tenant->business_name),
            '',
            sprintf('Your %s workspace has been created.', $tenant->business_name),
            '',
            'You can log in using the details below and continue your setup inside Sync360:',
            '',
            sprintf('Sync360 workspace URL: %s', $tenant->workspace_url),
            sprintf('Username: %s', $loginEmail),
            sprintf('Password: %s', $plainPassword),
            '',
            'We recommend signing in to Sync360, updating your password after your first login, and finishing the remaining setup steps.',
            '',
            'If you need any help getting started, just reply to this email and our team will be happy to help.',
            '',
            'Regards,',
            'Sync360',
        ]));
    }
}
