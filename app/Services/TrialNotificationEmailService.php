<?php

namespace App\Services;

use App\Models\Tenant;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class TrialNotificationEmailService
{
    public function sendBudgetWarning(Tenant $tenant, float $spend, float $maxBudget): void
    {
        if (! $this->isEnabled()) {
            return;
        }

        $recipientEmail = $tenant->user?->email;

        if (! $recipientEmail) {
            return;
        }

        $spentFormatted = number_format($spend, 2);
        $maxFormatted = number_format($maxBudget, 2);
        $remaining = number_format(max(0, $maxBudget - $spend), 2);
        $name = e($tenant->user?->name ?: $tenant->business_name);
        $businessName = e($tenant->business_name);
        $dashboardUrl = e(route('dashboard'));

        $html = <<<HTML
<p>Hi {$name},</p>
<p>Your <strong>{$businessName}</strong> AI credit balance is running low.</p>
<p>You have used <strong>\${$spentFormatted}</strong> of your \${$maxFormatted} trial credit — only <strong>\${$remaining}</strong> remaining.</p>
<p>Once your credit runs out your digital employee will be automatically paused.</p>
<p><a href="{$dashboardUrl}">View your usage on the dashboard →</a></p>
<p>If you would like to continue using Sync360 beyond the trial, <a href="mailto:hello@sync360.co.nz">contact us</a> and we will get you set up.</p>
<p>Regards,<br>Sync360</p>
HTML;

        $text = implode(PHP_EOL, [
            "Hi {$name},",
            '',
            "Your {$businessName} AI credit balance is running low.",
            '',
            "You have used \${$spentFormatted} of your \${$maxFormatted} trial credit — only \${$remaining} remaining.",
            '',
            'Once your credit runs out your digital employee will be automatically paused.',
            '',
            "View your usage on the dashboard: {$dashboardUrl}",
            '',
            'If you would like to continue using Sync360 beyond the trial, contact us at hello@sync360.co.nz.',
            '',
            'Regards,',
            'Sync360',
        ]);

        $this->send(
            $recipientEmail,
            $tenant->user?->name ?: $tenant->business_name,
            "Your {$tenant->business_name} AI credit is almost used up",
            $html,
            $text,
            $tenant,
            'budget_warning',
        );
    }

    public function sendExpiryWarning(Tenant $tenant, int $daysLeft): void
    {
        if (! $this->isEnabled()) {
            return;
        }

        $recipientEmail = $tenant->user?->email;

        if (! $recipientEmail) {
            return;
        }

        $dayLabel = $daysLeft === 1 ? '1 day' : "{$daysLeft} days";
        $name = e($tenant->user?->name ?: $tenant->business_name);
        $businessName = e($tenant->business_name);
        $dashboardUrl = e(route('dashboard'));

        $html = <<<HTML
<p>Hi {$name},</p>
<p>Your <strong>{$businessName}</strong> Sync360 trial ends in <strong>{$dayLabel}</strong>.</p>
<p>After the trial ends your digital employee will be automatically paused.</p>
<p>Make sure you have tested everything you need before the trial ends.</p>
<p><a href="{$dashboardUrl}">View your trial status on the dashboard →</a></p>
<p>To continue using Sync360 after the trial, <a href="mailto:hello@sync360.co.nz">contact us</a> and we will get you set up.</p>
<p>Regards,<br>Sync360</p>
HTML;

        $text = implode(PHP_EOL, [
            "Hi {$name},",
            '',
            "Your {$businessName} Sync360 trial ends in {$dayLabel}.",
            '',
            'After the trial ends your digital employee will be automatically paused.',
            '',
            'Make sure you have tested everything you need before the trial ends.',
            '',
            "View your trial status on the dashboard: {$dashboardUrl}",
            '',
            'To continue using Sync360 after the trial, contact us at hello@sync360.co.nz.',
            '',
            'Regards,',
            'Sync360',
        ]);

        $this->send(
            $recipientEmail,
            $tenant->user?->name ?: $tenant->business_name,
            "Your Sync360 trial ends in {$dayLabel}",
            $html,
            $text,
            $tenant,
            'expiry_warning',
        );
    }

    public function sendTrialExpired(Tenant $tenant, string $reason): void
    {
        if (! $this->isEnabled()) {
            return;
        }

        $recipientEmail = $tenant->user?->email;

        if (! $recipientEmail) {
            return;
        }

        $name = e($tenant->user?->name ?: $tenant->business_name);
        $businessName = e($tenant->business_name);
        $reasonText = $reason === 'budget'
            ? 'your trial AI credit has been fully used'
            : 'your 14-day trial period has ended';

        $html = <<<HTML
<p>Hi {$name},</p>
<p>Your <strong>{$businessName}</strong> Sync360 trial has ended — {$reasonText}.</p>
<p>Your digital employee has been paused. Your workspace data is safe and will remain intact.</p>
<p>To continue using Sync360, please <a href="mailto:hello@sync360.co.nz">contact us</a> and our team will get you set up with a plan that suits your needs.</p>
<p>Regards,<br>Sync360</p>
HTML;

        $text = implode(PHP_EOL, [
            "Hi {$name},",
            '',
            "Your {$businessName} Sync360 trial has ended — {$reasonText}.",
            '',
            'Your digital employee has been paused. Your workspace data is safe and will remain intact.',
            '',
            'To continue using Sync360, contact us at hello@sync360.co.nz and our team will get you set up.',
            '',
            'Regards,',
            'Sync360',
        ]);

        $this->send(
            $recipientEmail,
            $tenant->user?->name ?: $tenant->business_name,
            "Your {$tenant->business_name} Sync360 trial has ended",
            $html,
            $text,
            $tenant,
            'trial_expired',
        );
    }

    private function isEnabled(): bool
    {
        return (bool) config('services.brevo.enabled')
            && (string) config('services.brevo.key') !== ''
            && (string) config('services.brevo.sender_email') !== '';
    }

    private function send(
        string $recipientEmail,
        string $recipientName,
        string $subject,
        string $htmlContent,
        string $textContent,
        Tenant $tenant,
        string $emailType,
    ): void {
        try {
            Http::baseUrl((string) config('services.brevo.base_url', 'https://api.brevo.com/v3'))
                ->withHeaders([
                    'api-key' => (string) config('services.brevo.key'),
                    'accept'  => 'application/json',
                ])
                ->asJson()
                ->post('/smtp/email', [
                    'sender' => [
                        'name'  => (string) config('services.brevo.sender_name', config('app.name')),
                        'email' => (string) config('services.brevo.sender_email'),
                    ],
                    'to' => [[
                        'email' => $recipientEmail,
                        'name'  => $recipientName,
                    ]],
                    'subject'     => $subject,
                    'htmlContent' => $htmlContent,
                    'textContent' => $textContent,
                ])
                ->throw();
        } catch (Throwable $exception) {
            Log::warning('Sync360 failed to send trial notification email through Brevo.', [
                'tenant_id'  => $tenant->id,
                'email_type' => $emailType,
                'recipient'  => $recipientEmail,
                'error'      => $exception->getMessage(),
            ]);
        }
    }
}
