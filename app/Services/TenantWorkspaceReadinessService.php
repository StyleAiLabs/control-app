<?php

namespace App\Services;

use App\Enums\ProvisioningJobStatus;
use App\Enums\TenantProvisioningStatus;
use App\Models\BusinessProfile;
use App\Models\BusinessProfileFiles;
use App\Models\ProvisioningJob;
use App\Models\Tenant;
use App\Models\TenantGoogleCredential;

class TenantWorkspaceReadinessService
{
    /**
     * @return array{
     *   ready: bool,
     *   runtime_ready: bool,
     *   customer_ready: bool,
     *   go_live_ready: bool,
     *   grandfathered: bool,
     *   blocking_code: ?string,
     *   blocking_message: ?string,
     *   next_action: ?array{type:string,label:string,route:?string},
     *   google_connection_status: string,
     *   google_runtime_sync_status: string,
     *   google_runtime_sync_label: string,
     *   google_sync_job_status: ?string
     * }
     */
    public function evaluate(Tenant $tenant, bool $googleAvailable, ?ProvisioningJob $initialGoogleSyncJob = null): array
    {
        $credential = $googleAvailable && $tenant->relationLoaded('googleCredential')
            ? $tenant->googleCredential
            : null;
        $runtimeReady = $tenant->provisioning_status === TenantProvisioningStatus::Ready
            && filled($tenant->runtime_path);
        $grandfathered = $tenant->agent_status === 'live'
            || $tenant->onboarding_status === 'complete';

        $googleConnectionStatus = $credential?->status ?? TenantGoogleCredential::STATUS_PENDING;
        $googleRuntimeSyncStatus = $credential?->runtime_sync_status ?? TenantGoogleCredential::RUNTIME_SYNC_PENDING;
        $googleSyncJobStatus = $initialGoogleSyncJob?->status?->value;
        $googleVerified = $googleConnectionStatus === TenantGoogleCredential::STATUS_CONNECTED
            && $googleRuntimeSyncStatus === TenantGoogleCredential::RUNTIME_SYNC_VERIFIED;

        $customerReady = $runtimeReady && (
            ! $googleAvailable
            || $grandfathered
            || $googleVerified
        );

        $goLiveReady = $customerReady
            && $tenant->agent_status !== 'live'
            && $this->hasGoLivePrerequisites($tenant);

        [$blockingCode, $blockingMessage, $nextAction] = $this->blockingState(
            $tenant,
            $runtimeReady,
            $grandfathered,
            $googleAvailable,
            $credential,
            $googleRuntimeSyncStatus,
            $googleSyncJobStatus,
            $goLiveReady,
        );

        return [
            'ready' => $runtimeReady,
            'runtime_ready' => $runtimeReady,
            'customer_ready' => $customerReady,
            'go_live_ready' => $goLiveReady,
            'grandfathered' => $grandfathered,
            'blocking_code' => $blockingCode,
            'blocking_message' => $blockingMessage,
            'next_action' => $nextAction,
            'google_connection_status' => $googleConnectionStatus,
            'google_runtime_sync_status' => $googleRuntimeSyncStatus,
            'google_runtime_sync_label' => $this->googleRuntimeSyncLabel(
                $googleConnectionStatus,
                $googleRuntimeSyncStatus,
                $runtimeReady,
                $googleSyncJobStatus,
            ),
            'google_sync_job_status' => $googleSyncJobStatus,
        ];
    }

    private function hasGoLivePrerequisites(Tenant $tenant): bool
    {
        if (! $tenant->relationLoaded('server') || ! $tenant->server) {
            return false;
        }

        if (! $tenant->relationLoaded('businessProfile')) {
            return false;
        }

        $profile = $tenant->businessProfile;

        if (! $profile instanceof BusinessProfile) {
            return false;
        }

        if (! $tenant->relationLoaded('businessProfileFiles')) {
            return false;
        }

        $profileFiles = $tenant->businessProfileFiles;

        if (! $profileFiles instanceof BusinessProfileFiles) {
            return false;
        }

        return $profileFiles->generated_at !== null
            && filled($profileFiles->identity_markdown)
            && filled($profileFiles->soul_markdown)
            && filled($profileFiles->user_markdown)
            && filled($profileFiles->bootstrap_markdown)
            && filled($tenant->runtime_path);
    }

    /**
     * @return array{0:?string,1:?string,2:?array{type:string,label:string,route:?string}}
     */
    private function blockingState(
        Tenant $tenant,
        bool $runtimeReady,
        bool $grandfathered,
        bool $googleAvailable,
        ?TenantGoogleCredential $credential,
        string $googleRuntimeSyncStatus,
        ?string $googleSyncJobStatus,
        bool $goLiveReady,
    ): array {
        if ($tenant->agent_status === 'live' || $goLiveReady) {
            return [null, null, null];
        }

        if (! $runtimeReady) {
            return [
                'workspace_provisioning',
                'Your workspace is still being prepared in the background. Please wait until it is ready before continuing.',
                [
                    'type' => 'wait',
                    'label' => 'Keep This Page Open',
                    'route' => null,
                ],
            ];
        }

        if (! $googleAvailable || $grandfathered) {
            return [null, null, null];
        }

        if (! $credential?->isConnected()) {
            return [
                'google_connect_required',
                'Connect Google Workspace to finish preparing your customer-ready workspace.',
                [
                    'type' => $credential?->status === TenantGoogleCredential::STATUS_DISCONNECTED ? 'reconnect_google' : 'connect_google',
                    'label' => $credential?->status === TenantGoogleCredential::STATUS_DISCONNECTED ? 'Reconnect Google Workspace' : 'Connect Google Workspace',
                    'route' => route('onboarding.show', ['step' => 6]),
                ],
            ];
        }

        if ($credential->health_status === TenantGoogleCredential::HEALTH_RECONNECT_REQUIRED) {
            return [
                'google_reconnect_required',
                'Reconnect Google Workspace to restore inbox monitoring and live tools.',
                [
                    'type' => 'reconnect_google',
                    'label' => 'Reconnect Google Workspace',
                    'route' => route('onboarding.show', ['step' => 6]),
                ],
            ];
        }

        if ($googleRuntimeSyncStatus === TenantGoogleCredential::RUNTIME_SYNC_FAILED) {
            return [
                'google_sync_failed',
                'Google Workspace is connected, but the first live sync needs attention before you can continue.',
                [
                    'type' => 'reconnect_google',
                    'label' => 'Review Google Workspace Setup',
                    'route' => route('onboarding.show', ['step' => 6]),
                ],
            ];
        }

        if ($googleSyncJobStatus === ProvisioningJobStatus::Queued->value) {
            return [
                'google_sync_queued',
                'Google Workspace is connected. The first live sync is queued and will start shortly.',
                [
                    'type' => 'wait_for_google_sync',
                    'label' => 'Check Google Workspace Status',
                    'route' => route('onboarding.show', ['step' => 6]),
                ],
            ];
        }

        if ($googleSyncJobStatus === ProvisioningJobStatus::Running->value
            || $googleRuntimeSyncStatus === TenantGoogleCredential::RUNTIME_SYNC_PENDING) {
            return [
                'google_sync_running',
                'Google Workspace is connected and syncing into your workspace now.',
                [
                    'type' => 'wait_for_google_sync',
                    'label' => 'Check Google Workspace Status',
                    'route' => route('onboarding.show', ['step' => 6]),
                ],
            ];
        }

        if ($googleRuntimeSyncStatus === TenantGoogleCredential::RUNTIME_SYNC_SYNCED) {
            return [
                'google_verifying',
                'Google Workspace is connected. We are running a final live check before you can continue.',
                [
                    'type' => 'wait_for_google_verification',
                    'label' => 'Check Google Workspace Status',
                    'route' => route('onboarding.show', ['step' => 6]),
                ],
            ];
        }

        return [null, null, null];
    }

    private function googleRuntimeSyncLabel(
        string $connectionStatus,
        string $runtimeSyncStatus,
        bool $workspaceReady,
        ?string $syncJobStatus,
    ): string {
        if ($connectionStatus !== TenantGoogleCredential::STATUS_CONNECTED) {
            return ucfirst($runtimeSyncStatus);
        }

        return match (true) {
            $connectionStatus === TenantGoogleCredential::STATUS_CONNECTED && $runtimeSyncStatus === TenantGoogleCredential::RUNTIME_SYNC_VERIFIED && $workspaceReady => 'Ready',
            $runtimeSyncStatus === TenantGoogleCredential::RUNTIME_SYNC_FAILED => 'Needs attention',
            $runtimeSyncStatus === TenantGoogleCredential::RUNTIME_SYNC_SYNCED => 'Checking',
            $syncJobStatus === ProvisioningJobStatus::Running->value => 'Syncing',
            $syncJobStatus === ProvisioningJobStatus::Queued->value => 'Queued',
            $runtimeSyncStatus === TenantGoogleCredential::RUNTIME_SYNC_PENDING && ! $workspaceReady => 'Waiting for workspace',
            default => ucfirst($runtimeSyncStatus),
        };
    }
}
