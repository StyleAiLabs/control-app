<?php

namespace App\Services;

use App\Models\Tenant;
use App\Models\TenantGoogleCredential;
use App\Models\TenantInboxMonitorState;
use App\Services\Channels\TelegramSender;
use App\Support\GoogleWorkspaceFeature;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;
use Throwable;

class TenantWorkspaceDependencyMonitorService
{
    public function __construct(
        private readonly TenantWorkspaceDependencyHealthService $health,
        private readonly TenantGoogleWorkspaceSmokeTestService $smokeTests,
        private readonly TelegramSender $telegram,
    ) {
    }

    /**
     * @return array{tenants:int,healthy:int,alerts_sent:int,failures:int}
     */
    public function monitorAll(): array
    {
        $totals = [
            'tenants' => 0,
            'healthy' => 0,
            'alerts_sent' => 0,
            'failures' => 0,
        ];

        $tenants = Tenant::query()
            ->with([
                ...GoogleWorkspaceFeature::tenantRelations([
                    'server',
                    'inboxMonitorState',
                    'skillAssignments.catalogVersion',
                ]),
            ])
            ->whereHas('googleCredential', function ($query): void {
                $query->where('status', TenantGoogleCredential::STATUS_CONNECTED);
            })
            ->orderBy('id')
            ->get();

        foreach ($tenants as $tenant) {
            $totals['tenants']++;
            $result = $this->monitorTenant($tenant);
            $totals['alerts_sent'] += $result['alerts_sent'];
            $totals['failures'] += $result['failed'] ? 1 : 0;

            if (($result['google_health_status'] ?? null) === TenantGoogleCredential::HEALTH_HEALTHY) {
                $totals['healthy']++;
            }
        }

        return $totals;
    }

    /**
     * @return array{tenant:string,google_health_status:string,alerts_sent:int,failed:bool}
     */
    public function monitorTenant(Tenant $tenant): array
    {
        $tenant->loadMissing([
            ...GoogleWorkspaceFeature::tenantRelations([
                'server',
                'inboxMonitorState',
                'skillAssignments.catalogVersion',
            ]),
        ]);

        $alertsSent = 0;
        $googleIncidentReason = null;
        $inboxIncidentReason = null;

        try {
            $this->verifyGoogleHealth($tenant);
        } catch (Throwable $exception) {
            $this->persistGoogleFailure($tenant, $exception->getMessage());
        }

        $tenant->refresh();
        $tenant->loadMissing([
            ...GoogleWorkspaceFeature::tenantRelations([
                'server',
                'inboxMonitorState',
                'skillAssignments.catalogVersion',
            ]),
        ]);

        $health = $this->health->evaluate($tenant);
        $googleStatus = $health['google_workspace']['health_status'];
        $inboxStatus = $health['inbox_monitor']['health_status'];

        if ($googleStatus === TenantGoogleCredential::HEALTH_RECONNECT_REQUIRED) {
            $googleIncidentReason = 'google_reconnect_required';
            $alertsSent += $this->sendIncidentAlertIfDue($tenant, $googleIncidentReason, $health);
        } elseif ($googleStatus === TenantGoogleCredential::HEALTH_DEGRADED) {
            $googleIncidentReason = 'google_degraded';
            $alertsSent += $this->sendIncidentAlertIfDue($tenant, $googleIncidentReason, $health);
        }

        if ($this->googleReminderIsDue($tenant, $health, 1)) {
            $alertsSent += $this->sendExpiryReminder($tenant, 1, $health);
        } elseif ($this->googleReminderIsDue($tenant, $health, 3)) {
            $alertsSent += $this->sendExpiryReminder($tenant, 3, $health);
        }

        if ($inboxStatus === TenantInboxMonitorState::HEALTH_DOWN) {
            $inboxIncidentReason = 'inbox_down';
            $alertsSent += $this->sendInboxAlertIfDue($tenant, $inboxIncidentReason, $health);
        } elseif ($inboxStatus === TenantInboxMonitorState::HEALTH_DEGRADED) {
            $inboxIncidentReason = 'inbox_degraded';
            $alertsSent += $this->sendInboxAlertIfDue($tenant, $inboxIncidentReason, $health);
        }

        $this->health->persist($tenant->fresh([
            ...GoogleWorkspaceFeature::tenantRelations(['inboxMonitorState']),
        ]), $health, $googleIncidentReason, $inboxIncidentReason);

        return [
            'tenant' => $tenant->slug,
            'google_health_status' => $googleStatus,
            'alerts_sent' => $alertsSent,
            'failed' => in_array($googleStatus, [
                TenantGoogleCredential::HEALTH_RECONNECT_REQUIRED,
                TenantGoogleCredential::HEALTH_DEGRADED,
            ], true),
        ];
    }

    private function verifyGoogleHealth(Tenant $tenant): void
    {
        if (! $tenant->googleCredential?->isConnected()) {
            return;
        }

        $this->smokeTests->run($tenant);
        $this->health->markGoogleVerified($tenant->googleCredential->fresh());
    }

    private function persistGoogleFailure(Tenant $tenant, string $message): void
    {
        if (! $tenant->googleCredential) {
            return;
        }

        $status = $this->isAuthFailure($message)
            ? TenantGoogleCredential::HEALTH_RECONNECT_REQUIRED
            : TenantGoogleCredential::HEALTH_DEGRADED;

        $tenant->googleCredential->forceFill([
            'runtime_sync_status' => TenantGoogleCredential::RUNTIME_SYNC_FAILED,
            'health_status' => $status,
            'health_checked_at' => now(),
            'last_error' => $message,
        ])->save();
    }

    /**
     * @param  array<string, mixed>  $health
     */
    private function googleReminderIsDue(Tenant $tenant, array $health, int $days): bool
    {
        if (($health['oauth_app_mode'] ?? 'live') !== 'testing') {
            return false;
        }

        if (($health['google_workspace']['health_status'] ?? null) !== TenantGoogleCredential::HEALTH_EXPIRING_SOON) {
            return false;
        }

        $predictedRaw = $health['google_workspace']['predicted_expiry_at'] ?? null;
        $predicted = is_string($predictedRaw) && trim($predictedRaw) !== ''
            ? Carbon::parse($predictedRaw)
            : null;

        if (! $predicted) {
            return false;
        }

        $threshold = $days === 1 ? now()->addDay() : now()->addDays(3);

        if ($predicted->gt($threshold)) {
            return false;
        }

        return $days === 1
            ? $tenant->googleCredential?->expiry_warning_1day_sent_at === null
            : $tenant->googleCredential?->expiry_warning_3day_sent_at === null
                && $tenant->googleCredential?->expiry_warning_1day_sent_at === null;
    }

    /**
     * @param  array<string, mixed>  $health
     */
    private function sendExpiryReminder(Tenant $tenant, int $days, array $health): int
    {
        if (! $this->canSendTelegram($tenant)) {
            return 0;
        }

        $label = $days === 1 ? 'within 24 hours' : 'within 3 days';
        $message = sprintf(
            "%s: Google Workspace will need reconnecting %s to keep inbox monitoring live. Open Sync360 setup and reconnect Google Workspace.",
            $tenant->business_name,
            $label,
        );

        try {
            $this->telegram->send($tenant, $this->telegramChatId($tenant), $message);
        } catch (Throwable $exception) {
            Log::warning('sync360:monitor-workspace-dependencies failed to send Google expiry reminder.', [
                'tenant_id' => $tenant->tenant_id,
                'days' => $days,
                'error' => $exception->getMessage(),
            ]);

            return 0;
        }

        $tenant->googleCredential?->forceFill([
            $days === 1 ? 'expiry_warning_1day_sent_at' : 'expiry_warning_3day_sent_at' => now(),
        ])->save();

        return 1;
    }

    /**
     * @param  array<string, mixed>  $health
     */
    private function sendIncidentAlertIfDue(Tenant $tenant, string $reason, array $health): int
    {
        $credential = $tenant->googleCredential;

        if (! $credential || $credential->incident_alert_reason === $reason) {
            return 0;
        }

        if (! $this->canSendTelegram($tenant)) {
            return 0;
        }

        $message = sprintf(
            "%s: Google Workspace needs attention. %s",
            $tenant->business_name,
            $health['google_workspace']['health_note'] ?? 'Reconnect Google Workspace in Sync360.',
        );

        try {
            $this->telegram->send($tenant, $this->telegramChatId($tenant), $message);
        } catch (Throwable $exception) {
            Log::warning('sync360:monitor-workspace-dependencies failed to send Google incident alert.', [
                'tenant_id' => $tenant->tenant_id,
                'reason' => $reason,
                'error' => $exception->getMessage(),
            ]);

            return 0;
        }

        $credential->forceFill([
            'incident_alert_sent_at' => now(),
            'incident_alert_reason' => $reason,
        ])->save();

        return 1;
    }

    /**
     * @param  array<string, mixed>  $health
     */
    private function sendInboxAlertIfDue(Tenant $tenant, string $reason, array $health): int
    {
        $state = $tenant->inboxMonitorState;

        if (! $state || $state->incident_alert_reason === $reason || ! $this->canSendTelegram($tenant)) {
            return 0;
        }

        $message = sprintf(
            "%s: Inbox monitoring needs attention. %s",
            $tenant->business_name,
            $health['inbox_monitor']['health_note'] ?? 'Review your Google Workspace setup in Sync360.',
        );

        try {
            $this->telegram->send($tenant, $this->telegramChatId($tenant), $message);
        } catch (Throwable $exception) {
            Log::warning('sync360:monitor-workspace-dependencies failed to send inbox incident alert.', [
                'tenant_id' => $tenant->tenant_id,
                'reason' => $reason,
                'error' => $exception->getMessage(),
            ]);

            return 0;
        }

        $state->forceFill([
            'incident_alert_sent_at' => now(),
            'incident_alert_reason' => $reason,
        ])->save();

        return 1;
    }

    private function canSendTelegram(Tenant $tenant): bool
    {
        $config = is_array($tenant->channel_config) ? $tenant->channel_config : [];

        return trim((string) ($config['telegram_bot_token'] ?? '')) !== ''
            && trim((string) ($config['telegram_default_chat_id'] ?? '')) !== '';
    }

    private function telegramChatId(Tenant $tenant): string
    {
        $config = is_array($tenant->channel_config) ? $tenant->channel_config : [];

        return trim((string) ($config['telegram_default_chat_id'] ?? ''));
    }

    private function isAuthFailure(string $message): bool
    {
        $normalized = strtolower($message);

        return str_contains($normalized, 'invalid_grant')
            || str_contains($normalized, 'expired')
            || str_contains($normalized, 'revoked')
            || str_contains($normalized, 'unauthorized')
            || str_contains($normalized, 'forbidden');
    }
}
