<?php

namespace App\Services;

use App\Models\Tenant;
use App\Models\TenantGoogleCredential;
use App\Models\TenantInboxMonitorState;
use App\Support\GoogleWorkspaceFeature;
use Illuminate\Support\Carbon;

class TenantWorkspaceDependencyHealthService
{
    private const GOOGLE_HEALTH_STALE_HOURS = 2;
    private const INBOX_STALE_MINUTES = 15;
    private const INBOX_DEGRADED_MINUTES = 30;

    /**
     * @return array<string, mixed>
     */
    public function evaluate(Tenant $tenant): array
    {
        $tenant->loadMissing([
            'inboxMonitorState',
            'skillAssignments.catalogVersion',
            ...GoogleWorkspaceFeature::tenantRelations(),
        ]);

        $google = GoogleWorkspaceFeature::isAvailable() ? $tenant->getRelationValue('googleCredential') : null;
        $monitor = $tenant->inboxMonitorState;
        $googleConnected = $google?->isConnected() ?? false;
        $inboxEnabled = $tenant->skillAssignments->contains(
            fn ($assignment): bool => $assignment->skill_key === TenantInboxTriagePollingService::SKILL_KEY && (bool) $assignment->is_enabled
        );
        $oauthMode = $this->oauthAppMode();
        $predictedExpiryAt = $this->predictedExpiryAt($google, $oauthMode);
        $googleStatus = $this->googleHealthStatus($google, $monitor, $predictedExpiryAt);
        $inboxStatus = $this->inboxHealthStatus($tenant, $google, $monitor, $inboxEnabled);

        return [
            'oauth_app_mode' => $oauthMode,
            'google_workspace' => [
                'connected' => $googleConnected,
                'connected_email' => $google?->google_email,
                'health_status' => $googleStatus['status'],
                'health_label' => $googleStatus['label'],
                'health_note' => $googleStatus['note'],
                'last_verified_at' => $this->iso($this->lastVerifiedAt($google)),
                'last_health_checked_at' => $this->iso($google?->health_checked_at),
                'predicted_expiry_at' => $this->iso($predictedExpiryAt),
                'predicted_expiry_label' => $predictedExpiryAt?->diffForHumans(),
                'requires_reconnect' => $googleStatus['status'] === TenantGoogleCredential::HEALTH_RECONNECT_REQUIRED,
                'last_error' => $this->dependencyError($google?->last_error, $monitor?->last_error),
            ],
            'inbox_monitor' => [
                'enabled' => $inboxEnabled,
                'health_status' => $inboxStatus['status'],
                'health_label' => $inboxStatus['label'],
                'health_note' => $inboxStatus['note'],
                'last_checked_at' => $this->iso($monitor?->last_checked_at),
                'last_error' => $monitor?->last_error,
            ],
            'customer_alerts' => $this->customerAlerts($tenant, $googleStatus, $inboxStatus, $predictedExpiryAt),
            'primary_cta' => $this->primaryCta($googleStatus, $inboxStatus),
        ];
    }

    public function googleHealthStatusOnly(Tenant $tenant): string
    {
        return $this->evaluate($tenant)['google_workspace']['health_status'];
    }

    /**
     * @param  array<string, mixed>  $health
     */
    public function persist(Tenant $tenant, array $health, ?string $googleIncidentReason = null, ?string $inboxIncidentReason = null): void
    {
        $tenant->loadMissing(GoogleWorkspaceFeature::tenantRelations(['inboxMonitorState']));

        if ($tenant->googleCredential) {
            $tenant->googleCredential->forceFill([
                'health_status' => $health['google_workspace']['health_status'],
                'health_checked_at' => now(),
                'predicted_testing_expiry_at' => $health['google_workspace']['predicted_expiry_at']
                    ? Carbon::parse($health['google_workspace']['predicted_expiry_at'])
                    : null,
                'incident_alert_reason' => $googleIncidentReason,
            ])->save();
        }

        if ($tenant->inboxMonitorState) {
            $tenant->inboxMonitorState->forceFill([
                'health_status' => $health['inbox_monitor']['health_status'],
                'health_checked_at' => now(),
                'incident_alert_reason' => $inboxIncidentReason,
            ])->save();
        }
    }

    public function markGoogleVerified(TenantGoogleCredential $credential): void
    {
        $credential->forceFill([
            'health_status' => TenantGoogleCredential::HEALTH_HEALTHY,
            'health_checked_at' => now(),
            'last_verified_at' => now(),
            'last_error' => null,
            'incident_alert_reason' => null,
        ])->save();
    }

    public function resetGoogleAlerts(TenantGoogleCredential $credential): void
    {
        $credential->forceFill([
            'health_status' => null,
            'health_checked_at' => null,
            'last_verified_at' => null,
            'predicted_testing_expiry_at' => null,
            'expiry_warning_3day_sent_at' => null,
            'expiry_warning_1day_sent_at' => null,
            'incident_alert_reason' => null,
        ])->save();
    }

    public function resetInboxAlerts(TenantInboxMonitorState $state): void
    {
        $state->forceFill([
            'health_status' => null,
            'health_checked_at' => null,
            'incident_alert_reason' => null,
        ])->save();
    }

    private function oauthAppMode(): string
    {
        return strtolower((string) config('services.google.oauth_app_mode', 'live')) === 'testing'
            ? 'testing'
            : 'live';
    }

    private function predictedExpiryAt(?TenantGoogleCredential $credential, string $oauthMode): ?Carbon
    {
        if ($oauthMode !== 'testing' || ! $credential?->isConnected() || ! $credential->connected_at) {
            return null;
        }

        return $credential->connected_at->copy()->addDays(7);
    }

    /**
     * @return array{status:string,label:string,note:string}
     */
    private function googleHealthStatus(
        ?TenantGoogleCredential $credential,
        ?TenantInboxMonitorState $monitor,
        ?Carbon $predictedExpiryAt,
    ): array {
        if (! GoogleWorkspaceFeature::isAvailable()) {
            return [
                'status' => TenantGoogleCredential::HEALTH_NOT_CONNECTED,
                'label' => 'Unavailable',
                'note' => 'Google Workspace connect is temporarily unavailable in this environment until the latest database migration has been run.',
            ];
        }

        if (! $credential?->isConnected()) {
            return [
                'status' => TenantGoogleCredential::HEALTH_NOT_CONNECTED,
                'label' => 'Not connected',
                'note' => 'Connect Google Workspace to restore inbox monitoring and live tools.',
            ];
        }

        $dependencyError = $this->dependencyError($credential->last_error, $monitor?->last_error);

        if ($this->isAuthFailure($dependencyError)) {
            return [
                'status' => TenantGoogleCredential::HEALTH_RECONNECT_REQUIRED,
                'label' => 'Reconnect required',
                'note' => 'Reconnect Google Workspace to restore inbox monitoring and live tools.',
            ];
        }

        if (in_array($credential->runtime_sync_status, [
            TenantGoogleCredential::RUNTIME_SYNC_PENDING,
            TenantGoogleCredential::RUNTIME_SYNC_SYNCED,
        ], true)) {
            return [
                'status' => TenantGoogleCredential::HEALTH_SYNCING,
                'label' => 'Syncing',
                'note' => 'Google Workspace is still being linked and verified for live tools.',
            ];
        }

        $lastVerifiedAt = $this->lastVerifiedAt($credential);
        $verificationFresh = $lastVerifiedAt && $lastVerifiedAt->gte(now()->subHours(self::GOOGLE_HEALTH_STALE_HOURS));

        if ($predictedExpiryAt && $predictedExpiryAt->lte(now()->addDay())) {
            return [
                'status' => TenantGoogleCredential::HEALTH_EXPIRING_SOON,
                'label' => 'Expires soon',
                'note' => 'Google Workspace will need reconnecting soon to keep your inbox monitoring live.',
            ];
        }

        if ($predictedExpiryAt && $predictedExpiryAt->lte(now()->addDays(3))) {
            return [
                'status' => TenantGoogleCredential::HEALTH_EXPIRING_SOON,
                'label' => 'Expires soon',
                'note' => 'Google Workspace will need reconnecting soon to keep your inbox monitoring live.',
            ];
        }

        if ($credential->runtime_sync_status === TenantGoogleCredential::RUNTIME_SYNC_FAILED || ! $verificationFresh) {
            return [
                'status' => TenantGoogleCredential::HEALTH_DEGRADED,
                'label' => 'Needs attention',
                'note' => 'Google Workspace needs attention. Some live tools may not be working normally.',
            ];
        }

        return [
            'status' => TenantGoogleCredential::HEALTH_HEALTHY,
            'label' => 'Working',
            'note' => 'Google Workspace is connected and working.',
        ];
    }

    /**
     * @return array{status:string,label:string,note:string}
     */
    private function inboxHealthStatus(
        Tenant $tenant,
        ?TenantGoogleCredential $credential,
        ?TenantInboxMonitorState $monitor,
        bool $inboxEnabled,
    ): array {
        if (! $inboxEnabled) {
            return [
                'status' => TenantInboxMonitorState::HEALTH_NOT_ENABLED,
                'label' => 'Not enabled',
                'note' => 'Inbox monitoring is not enabled for this workspace.',
            ];
        }

        if (! $credential?->isConnected()) {
            return [
                'status' => TenantInboxMonitorState::HEALTH_DOWN,
                'label' => 'Reconnect required',
                'note' => 'We’re not currently checking your inbox. Reconnect Google Workspace or review setup.',
            ];
        }

        $googleStatus = $tenant->googleCredential?->health_status;
        if ($googleStatus === TenantGoogleCredential::HEALTH_RECONNECT_REQUIRED) {
            return [
                'status' => TenantInboxMonitorState::HEALTH_DOWN,
                'label' => 'Inbox down',
                'note' => 'We’re not currently checking your inbox. Reconnect Google Workspace or review setup.',
            ];
        }

        if (! ($monitor?->enabled ?? true)) {
            return [
                'status' => TenantInboxMonitorState::HEALTH_NOT_ENABLED,
                'label' => 'Disabled',
                'note' => 'Inbox monitoring is turned off right now.',
            ];
        }

        $lastCheckedAt = $monitor?->last_checked_at;
        $staleAt = now()->subMinutes(self::INBOX_STALE_MINUTES);
        $downAt = now()->subMinutes(self::INBOX_DEGRADED_MINUTES);

        if ($monitor?->status === TenantInboxMonitorState::STATUS_FAILED || ($monitor?->backoff_until && $monitor->backoff_until->isFuture())) {
            return [
                'status' => TenantInboxMonitorState::HEALTH_DOWN,
                'label' => 'Inbox down',
                'note' => 'We’re not currently checking your inbox. Reconnect Google Workspace or review setup.',
            ];
        }

        if (! $lastCheckedAt || $lastCheckedAt->lt($downAt)) {
            return [
                'status' => TenantInboxMonitorState::HEALTH_DOWN,
                'label' => 'Inbox down',
                'note' => 'We’re not currently checking your inbox. Reconnect Google Workspace or review setup.',
            ];
        }

        if ($lastCheckedAt->lt($staleAt)) {
            return [
                'status' => TenantInboxMonitorState::HEALTH_DEGRADED,
                'label' => 'Needs attention',
                'note' => 'We’re having trouble checking your inbox right now.',
            ];
        }

        return [
            'status' => TenantInboxMonitorState::HEALTH_HEALTHY,
            'label' => 'Watching your inbox',
            'note' => 'Last checked '.$lastCheckedAt->diffForHumans(),
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function customerAlerts(
        Tenant $tenant,
        array $googleStatus,
        array $inboxStatus,
        ?Carbon $predictedExpiryAt,
    ): array {
        $alerts = [];
        $cta = ['text' => 'Open setup', 'href' => route('onboarding.show', ['step' => 6])];

        if ($googleStatus['status'] === TenantGoogleCredential::HEALTH_RECONNECT_REQUIRED) {
            $alerts[] = [
                'type' => 'warning',
                'icon' => '⚠️',
                'title' => 'Reconnect Google Workspace',
                'message' => $googleStatus['note'],
                'cta' => $cta,
            ];

            return $alerts;
        }

        if ($googleStatus['status'] === TenantGoogleCredential::HEALTH_EXPIRING_SOON) {
            $alerts[] = [
                'type' => 'warning',
                'icon' => '⚠️',
                'title' => 'Google Workspace needs reconnecting soon',
                'message' => $predictedExpiryAt
                    ? sprintf('%s Reconnect before it interrupts inbox monitoring.', $predictedExpiryAt->diffForHumans())
                    : $googleStatus['note'],
                'cta' => $cta,
            ];
        } elseif ($googleStatus['status'] === TenantGoogleCredential::HEALTH_DEGRADED) {
            $alerts[] = [
                'type' => 'warning',
                'icon' => '⚠️',
                'title' => 'Google Workspace needs attention',
                'message' => $googleStatus['note'],
                'cta' => $cta,
            ];
        }

        if (in_array($inboxStatus['status'], [
            TenantInboxMonitorState::HEALTH_DEGRADED,
            TenantInboxMonitorState::HEALTH_DOWN,
        ], true)) {
            $alerts[] = [
                'type' => 'warning',
                'icon' => '⚠️',
                'title' => 'Inbox monitoring needs attention',
                'message' => $inboxStatus['note'],
                'cta' => $cta,
            ];
        }

        return $alerts;
    }

    /**
     * @param  array{status:string,label:string,note:string}  $googleStatus
     * @param  array{status:string,label:string,note:string}  $inboxStatus
     * @return array{text:string,href:string}|null
     */
    private function primaryCta(array $googleStatus, array $inboxStatus): ?array
    {
        if (
            in_array($googleStatus['status'], [
                TenantGoogleCredential::HEALTH_EXPIRING_SOON,
                TenantGoogleCredential::HEALTH_DEGRADED,
                TenantGoogleCredential::HEALTH_RECONNECT_REQUIRED,
            ], true)
            || in_array($inboxStatus['status'], [
                TenantInboxMonitorState::HEALTH_DEGRADED,
                TenantInboxMonitorState::HEALTH_DOWN,
            ], true)
        ) {
            return [
                'text' => 'Reconnect Google Workspace',
                'href' => route('onboarding.show', ['step' => 6]),
            ];
        }

        return null;
    }

    private function lastVerifiedAt(?TenantGoogleCredential $credential): ?Carbon
    {
        if (! $credential) {
            return null;
        }

        return $credential->last_verified_at
            ?? ($credential->runtime_sync_status === TenantGoogleCredential::RUNTIME_SYNC_VERIFIED
                ? ($credential->last_synced_at ?? $credential->updated_at)
                : null);
    }

    private function dependencyError(?string ...$errors): ?string
    {
        foreach ($errors as $error) {
            if (filled($error)) {
                return trim((string) $error);
            }
        }

        return null;
    }

    private function isAuthFailure(?string $error): bool
    {
        if (! filled($error)) {
            return false;
        }

        $normalized = strtolower((string) $error);

        foreach (['invalid_grant', 'expired', 'revoked', 'unauthorized', 'forbidden', 'token has been expired or revoked'] as $needle) {
            if (str_contains($normalized, $needle)) {
                return true;
            }
        }

        return false;
    }

    private function iso(?Carbon $value): ?string
    {
        return $value?->toIso8601String();
    }
}
