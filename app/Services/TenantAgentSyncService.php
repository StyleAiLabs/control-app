<?php

namespace App\Services;

use App\Contracts\DockerComposeRunner;
use App\Enums\ProvisioningJobStatus;
use App\Enums\TenantProvisioningStatus;
use App\Jobs\ProcessInitialGoogleWorkspaceSync;
use App\Models\BusinessProfile;
use App\Models\BusinessProfileFiles;
use App\Models\ProvisioningJob;
use App\Models\Tenant;
use App\Models\TenantGoogleCredential;
use App\Support\GoogleWorkspaceFeature;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

class TenantAgentSyncService
{
    public function __construct(
        private readonly Filesystem $files,
        private readonly TenantRuntimeService $runtime,
        private readonly DockerComposeRunner $dockerCompose,
        private readonly GogAuthStorageService $gogAuthStorage,
        private readonly GogCommandCatalogService $gogCommands,
        private readonly TenantGoogleWorkspaceSmokeTestService $googleWorkspaceSmokeTests,
        private readonly TenantRuntimeCapabilityService $runtimeCapabilities,
        private readonly TenantRuntimeCustomizationComposer $runtimeCustomizationComposer,
        private readonly TenantSkillAnalyticsRuntimeService $skillAnalyticsRuntime,
    ) {
    }

    public function goLive(Tenant $tenant): void
    {
        $tenant->loadMissing(GoogleWorkspaceFeature::tenantRelations(['server', 'businessProfile', 'businessProfileFiles']));

        $profile = $tenant->businessProfile;
        $profileFiles = $tenant->businessProfileFiles;

        $this->ensureTenantCanGoLive($tenant, $profile, $profileFiles);

        $localRuntimePath = $this->runtime->localRuntimePath($tenant);
        /* OpenClaw reads workspace bootstrap files from .openclaw/workspace/ inside OPENCLAW_HOME. */
        $workspacePath = $this->runtime->localWorkspacePath($tenant);

        if (! $this->files->isDirectory($localRuntimePath) || ! $this->files->isDirectory($workspacePath)) {
            throw new RuntimeException('Your workspace files are still being prepared. Please wait a moment and try again.');
        }

        $artifacts = $this->artifactContents($tenant, $profile, $profileFiles);
        $remoteRuntimePath = $tenant->runtime_path ?: $this->runtime->remoteRuntimePath($tenant);
        $composeFile = $this->runtime->remoteComposePath($tenant);
        $projectName = $this->runtime->projectName($tenant);
        $syncedAt = now();

        $tenant->forceFill([
            'agent_status' => 'deploying',
        ])->save();

        try {
            $this->syncSavedChannelIfReady($tenant);

            foreach ($artifacts as $filename => $contents) {
                $this->files->put($workspacePath.DIRECTORY_SEPARATOR.$filename, $contents);
            }

            /* Skip SSH-based remote sync in local dev — files are already on disk. */
            if (app()->environment('local')) {
                Log::info('[GoLive] Local dev mode — skipping remote sync for tenant '.$tenant->slug);
            } else {
                /* Sync ONLY the .openclaw/workspace/ markdown files — never touch compose.yaml
                   or config/openclaw.json which hold provisioned credentials (LiteLLM key,
                   gateway token). Using syncRuntime() here previously overwrote those files
                   with stale local copies and broke LiteLLM authentication. */
                $localWorkspacePath  = $workspacePath;
                $remoteWorkspacePath = $this->runtime->remoteWorkspacePath($tenant);

                $this->dockerCompose->syncWorkspaceFiles($tenant->server, $localWorkspacePath, $remoteWorkspacePath);
                $this->dockerCompose->up($tenant->server, $composeFile, $projectName);
            }

            $this->skillAnalyticsRuntime->initializeTenant($tenant);

            $profileFiles->forceFill([
                'profile_markdown' => $artifacts['PROFILE.md'],
                'heartbeat_markdown' => $artifacts['HEARTBEAT.md'],
                'generated_at' => $profileFiles->generated_at ?? $syncedAt,
                'synced_at' => $syncedAt,
            ])->save();

            $profile->forceFill([
                'last_synced_to_agent' => $syncedAt,
            ])->save();

            $tenant->forceFill([
                'onboarding_status' => 'complete',
                'onboarding_step' => max((int) $tenant->onboarding_step, 7),
                'agent_status' => 'live',
                'agent_last_synced_at' => $syncedAt,
            ])->save();
        } catch (Throwable $exception) {
            $tenant->forceFill([
                'agent_status' => 'failed',
            ])->save();

            throw $exception;
        }
    }

    /**
     * Write channel configuration into the tenant's openclaw.json and restart
     * the gateway so it picks up the new channel.
     */
    public function configureChannel(Tenant $tenant): void
    {
        $tenant->loadMissing('server');

        $localRuntimePath = $this->runtime->localRuntimePath($tenant);
        $configPath = $localRuntimePath.DIRECTORY_SEPARATOR.'config'.DIRECTORY_SEPARATOR.'openclaw.json';

        if (! $this->files->exists($configPath)) {
            throw new RuntimeException('The workspace config file does not exist yet. Please complete the earlier setup steps first.');
        }

        $config = json_decode($this->files->get($configPath), true) ?: [];
        $channelConfig = is_array($tenant->channel_config) ? $tenant->channel_config : [];

        /* Remove any stale top-level "agent" key from earlier versions. */
        unset($config['agent']);

        /* Ensure the default agent model is set via the correct OpenClaw path. */
        $defaultModel = (string) config('sync360.openclaw.default_agent_model', 'gpt-4o');

        if (! isset($config['agents']['defaults']['model'])) {
            $config['agents']['defaults']['model'] = $defaultModel;
        }

        /* Ensure the OpenAI provider routes through LiteLLM, not directly to api.openai.com. */
        if (! isset($config['models']['providers']['openai']['baseUrl'])) {
            $liteLlmBaseUrl = rtrim((string) config('services.litellm.base_url', 'https://litellm.stylesoftware.co.nz'), '/');
            $config['models'] = [
                'mode' => 'replace',
                'providers' => [
                    'openai' => [
                        'baseUrl' => $liteLlmBaseUrl.'/v1',
                        'models' => [
                            ['id' => $defaultModel, 'name' => $defaultModel],
                        ],
                    ],
                ],
            ];
        }

        /* Merge channel-specific settings into the openclaw config. */
        match ($tenant->channel) {
            'telegram' => (static function () use (&$config, $channelConfig): void {
                // OpenClaw operates in polling mode — it calls Telegram's getUpdates API
                // directly, handles all message delivery and AI reply generation autonomously.
                // Do NOT set webhookUrl here: OpenClaw in polling mode calls deleteWebhook
                // on startup and manages the full conversation lifecycle itself.
                // Conversation logs are populated via sync360:sync-replies which reads
                // OpenClaw's session memory files from the workspace VPS.
                $config['channels']['telegram'] = array_filter([
                    'enabled'   => true,
                    'botToken'  => $channelConfig['telegram_bot_token'] ?? null,
                    'dmPolicy'  => 'open',
                    'allowFrom' => ['*'],
                ]);
            })(),
            default => null, /* WhatsApp will be added in a future phase. */
        };

        $configContents = $this->renderComposedConfigContents($tenant, $config);
        $this->files->put($configPath, $configContents);

        /* Restart the gateway so it picks up the new config. */
        if (app()->environment('local')) {
            Log::info('[ConfigureChannel] Local dev mode — config written, skipping remote sync for tenant '.$tenant->slug);

            return;
        }

        $remoteRuntimePath = $tenant->runtime_path ?: $this->runtime->remoteRuntimePath($tenant);
        $remoteConfigPath = $remoteRuntimePath.'/config/openclaw.json';
        $composeFile = $this->runtime->remoteComposePath($tenant);
        $projectName = $this->runtime->projectName($tenant);

        $this->dockerCompose->putFile(
            $tenant->server,
            $remoteConfigPath,
            $configContents,
        );

        /* Restart instead of full up — faster and preserves session data. */
        $this->dockerCompose->runCommand(
            $tenant->server,
            sprintf('docker compose -f %s -p %s restart', escapeshellarg($composeFile), escapeshellarg($projectName)),
        );

        Log::info('[ConfigureChannel] Channel config written and gateway restarted for tenant '.$tenant->slug);

    }

    /**
     * Remove channel configuration from the tenant's openclaw.json and restart
     * the gateway.
     */
    public function removeChannelConfig(Tenant $tenant): void
    {
        $tenant->loadMissing('server');

        $localRuntimePath = $this->runtime->localRuntimePath($tenant);
        $configPath = $localRuntimePath.DIRECTORY_SEPARATOR.'config'.DIRECTORY_SEPARATOR.'openclaw.json';

        if (! $this->files->exists($configPath)) {
            return;
        }

        $config = json_decode($this->files->get($configPath), true) ?: [];
        unset($config['channels']);
        $configContents = $this->renderComposedConfigContents($tenant, $config);

        $this->files->put($configPath, $configContents);

        if (app()->environment('local')) {
            Log::info('[RemoveChannelConfig] Local dev mode — channels removed from config for tenant '.$tenant->slug);

            return;
        }

        $remoteRuntimePath = $tenant->runtime_path ?: $this->runtime->remoteRuntimePath($tenant);
        $remoteConfigPath = $remoteRuntimePath.'/config/openclaw.json';
        $composeFile = $this->runtime->remoteComposePath($tenant);
        $projectName = $this->runtime->projectName($tenant);

        $this->dockerCompose->putFile(
            $tenant->server,
            $remoteConfigPath,
            $configContents,
        );

        $this->dockerCompose->runCommand(
            $tenant->server,
            sprintf('docker compose -f %s -p %s restart', escapeshellarg($composeFile), escapeshellarg($projectName)),
        );

        Log::info('[RemoveChannelConfig] Channels removed and gateway restarted for tenant '.$tenant->slug);
    }

    public function syncSavedChannelIfReady(Tenant $tenant): bool
    {
        $tenant->loadMissing('server');

        if ($tenant->channel !== 'telegram') {
            return false;
        }

        $channelConfig = is_array($tenant->channel_config) ? $tenant->channel_config : [];

        if (! filled($channelConfig['telegram_bot_token'] ?? null)) {
            return false;
        }

        if (! $this->files->exists($this->runtime->localOpenClawConfigPath($tenant))) {
            return false;
        }

        $this->configureChannel($tenant);

        return $this->isSavedChannelRuntimeConfigured($tenant);
    }

    public function isSavedChannelRuntimeConfigured(Tenant $tenant): bool
    {
        if ($tenant->channel !== 'telegram') {
            return false;
        }

        $channelConfig = is_array($tenant->channel_config) ? $tenant->channel_config : [];

        if (! filled($channelConfig['telegram_bot_token'] ?? null)) {
            return false;
        }

        $configPath = $this->runtime->localOpenClawConfigPath($tenant);

        if (! $this->files->exists($configPath)) {
            return false;
        }

        $config = json_decode($this->files->get($configPath), true);

        if (! is_array($config)) {
            return false;
        }

        return data_get($config, 'channels.telegram.enabled') === true
            && filled(data_get($config, 'channels.telegram.botToken'));
    }

    public function configureGoogleWorkspace(Tenant $tenant): void
    {
        if (! GoogleWorkspaceFeature::isAvailable()) {
            return;
        }

        $tenant->loadMissing(['server', 'googleCredential']);

        $credential = $tenant->googleCredential;

        if (! $credential || ! $credential->isConnected()) {
            throw new RuntimeException('A connected Google Workspace account is required before syncing GOG credentials.');
        }

        if ($tenant->provisioning_status !== TenantProvisioningStatus::Ready || ! filled($tenant->runtime_path)) {
            $credential->forceFill([
                'runtime_sync_status' => TenantGoogleCredential::RUNTIME_SYNC_PENDING,
                'last_error' => null,
            ])->save();

            return;
        }

        $localConfigRoot = $this->gogAuthStorage->localConfigRoot($tenant);
        $localArtifacts = $this->gogAuthStorage->localArtifacts($tenant, $credential);
        $this->files->deleteDirectory($localConfigRoot);

        foreach ($localArtifacts as $path => $contents) {
            $this->files->ensureDirectoryExists(dirname($path));
            $this->files->put($path, $contents);
        }

        $composeUpdate = $this->runtimeCapabilities->syncLocalCompose($tenant, ['gog']);
        $configUpdate = $this->syncLocalComposedOpenClawConfig($tenant);

        if (! app()->environment('local')) {
            $remoteConfigRoot = $this->gogAuthStorage->remoteConfigRoot($tenant);
            $remoteArtifacts = $this->gogAuthStorage->remoteArtifacts($tenant, $credential);

            $this->dockerCompose->removeDirectory($tenant->server, $remoteConfigRoot);

            foreach ($remoteArtifacts as $path => $contents) {
                $this->dockerCompose->putFile($tenant->server, $path, $contents);
            }

            if ($composeUpdate['changed']) {
                $this->dockerCompose->putFile($tenant->server, $composeUpdate['remote_compose_file'], $composeUpdate['contents']);
            }

            if ($configUpdate['changed']) {
                $this->dockerCompose->putFile($tenant->server, $configUpdate['remote_config_file'], $configUpdate['contents']);
            }

            if ($composeUpdate['changed'] || $configUpdate['changed']) {
                $this->runtimeCapabilities->reloadRuntime($tenant, $composeUpdate['changed']);
            }
        } else {
            if ($composeUpdate['changed'] || $configUpdate['changed']) {
                $this->runtimeCapabilities->reloadRuntime($tenant, $composeUpdate['changed']);
            }
        }

        $credential->forceFill([
            'runtime_sync_status' => TenantGoogleCredential::RUNTIME_SYNC_SYNCED,
            'last_synced_at' => now(),
            'last_error' => null,
        ])->save();
    }

    public function disconnectGoogleWorkspace(Tenant $tenant): void
    {
        if (! GoogleWorkspaceFeature::isAvailable()) {
            return;
        }

        $tenant->loadMissing(['server', 'googleCredential']);

        $credential = $tenant->googleCredential ?: $tenant->googleCredential()->create();
        $localConfigRoot = $this->gogAuthStorage->localConfigRoot($tenant);

        $credential->forceFill([
            'status' => TenantGoogleCredential::STATUS_DISCONNECTED,
            'runtime_sync_status' => filled($tenant->runtime_path)
                ? TenantGoogleCredential::RUNTIME_SYNC_SYNCED
                : TenantGoogleCredential::RUNTIME_SYNC_PENDING,
            'access_token' => null,
            'refresh_token' => null,
            'expires_at' => null,
            'scopes' => null,
            'oauth_state' => null,
            'oauth_code_verifier' => null,
            'oauth_state_expires_at' => null,
            'disconnected_at' => now(),
            'last_synced_at' => now(),
            'last_error' => null,
        ])->save();

        $this->files->deleteDirectory($localConfigRoot);

        $composeUpdate = null;
        $configUpdate = null;

        if (filled($tenant->runtime_path)) {
            $composeUpdate = $this->runtimeCapabilities->syncLocalCompose($tenant, ['gog']);
            $configUpdate = $this->syncLocalComposedOpenClawConfig($tenant);
        }

        if ($tenant->server && filled($tenant->runtime_path)) {
            if (! app()->environment('local')) {
                $this->dockerCompose->removeDirectory($tenant->server, $this->gogAuthStorage->remoteConfigRoot($tenant));

                if (($composeUpdate['changed'] ?? false) === true) {
                    $this->dockerCompose->putFile($tenant->server, $composeUpdate['remote_compose_file'], $composeUpdate['contents']);
                }

                if (($configUpdate['changed'] ?? false) === true) {
                    $this->dockerCompose->putFile($tenant->server, $configUpdate['remote_config_file'], $configUpdate['contents']);
                }
            }

            $this->runtimeCapabilities->reloadRuntime($tenant, (bool) ($composeUpdate['changed'] ?? false));
        }
    }

    public function syncConnectedGoogleWorkspace(Tenant $tenant): void
    {
        if (! GoogleWorkspaceFeature::isAvailable()) {
            return;
        }

        $tenant->loadMissing(['server', 'googleCredential']);

        if (! $tenant->googleCredential?->isConnected()) {
            return;
        }

        try {
            if ($this->hasActiveInitialGoogleWorkspaceSyncJob($tenant)) {
                return;
            }

            $this->syncConnectedGoogleWorkspaceOrFail($tenant);
        } catch (Throwable) {
            // The strict path already persists the failure details for the UI.
        }
    }

    public function syncConnectedGoogleWorkspaceOrFail(Tenant $tenant): void
    {
        if (! GoogleWorkspaceFeature::isAvailable()) {
            return;
        }

        $tenant->loadMissing(['server', 'googleCredential']);

        if (! $tenant->googleCredential?->isConnected()) {
            return;
        }

        try {
            $this->configureGoogleWorkspace($tenant);
            $this->verifyConnectedGoogleWorkspace($tenant->fresh(['server', 'googleCredential']));
        } catch (Throwable $exception) {
            $tenant->googleCredential?->forceFill([
                'runtime_sync_status' => TenantGoogleCredential::RUNTIME_SYNC_FAILED,
                'last_error' => $exception->getMessage(),
            ])->save();

            Log::warning('[GoogleWorkspaceSync] Failed to sync Google Workspace auth for tenant '.$tenant->slug, [
                'tenant_id' => $tenant->tenant_id,
                'error' => $exception->getMessage(),
            ]);

            throw $exception;
        }
    }

    public function dispatchInitialGoogleWorkspaceSync(Tenant $tenant, string $trigger = 'system'): ?ProvisioningJob
    {
        if (! GoogleWorkspaceFeature::isAvailable()) {
            return null;
        }

        $tenant->loadMissing(['server', 'googleCredential']);

        $credential = $tenant->googleCredential;

        if (! $credential?->isConnected()) {
            return null;
        }

        if ($tenant->provisioning_status !== TenantProvisioningStatus::Ready || ! filled($tenant->runtime_path)) {
            $credential->forceFill([
                'runtime_sync_status' => TenantGoogleCredential::RUNTIME_SYNC_PENDING,
                'last_error' => null,
            ])->save();

            return null;
        }

        $activeJob = $this->latestInitialGoogleWorkspaceSyncJob($tenant, [ProvisioningJobStatus::Queued, ProvisioningJobStatus::Running]);

        if ($activeJob) {
            return $activeJob;
        }

        $job = ProvisioningJob::query()->create([
            'tenant_id' => $tenant->id,
            'job_type' => ProcessInitialGoogleWorkspaceSync::JOB_TYPE,
            'status' => ProvisioningJobStatus::Queued,
            'payload_json' => [
                'trigger' => $trigger,
                'google_email' => $credential->google_email,
            ],
        ]);

        $credential->forceFill([
            'runtime_sync_status' => TenantGoogleCredential::RUNTIME_SYNC_PENDING,
            'last_error' => null,
        ])->save();

        ProcessInitialGoogleWorkspaceSync::dispatch($tenant->id, $job->id)->afterCommit();

        return $job;
    }

    public function latestInitialGoogleWorkspaceSyncJob(Tenant $tenant, ?array $statuses = null): ?ProvisioningJob
    {
        $query = ProvisioningJob::query()
            ->where('tenant_id', $tenant->id)
            ->where('job_type', ProcessInitialGoogleWorkspaceSync::JOB_TYPE)
            ->latest('id');

        if (is_array($statuses) && $statuses !== []) {
            $query->whereIn('status', array_map(
                static fn (ProvisioningJobStatus|string $status): string => $status instanceof ProvisioningJobStatus ? $status->value : $status,
                $statuses,
            ));
        }

        return $query->first();
    }

    public function hasActiveInitialGoogleWorkspaceSyncJob(Tenant $tenant): bool
    {
        return $this->latestInitialGoogleWorkspaceSyncJob($tenant, [
            ProvisioningJobStatus::Queued,
            ProvisioningJobStatus::Running,
        ]) !== null;
    }

    public function verifyConnectedGoogleWorkspace(Tenant $tenant): void
    {
        if (! GoogleWorkspaceFeature::isAvailable()) {
            return;
        }

        $tenant->loadMissing(['server', 'googleCredential']);

        $credential = $tenant->googleCredential;

        if (! $credential?->isConnected()) {
            return;
        }

        if ($tenant->provisioning_status !== TenantProvisioningStatus::Ready || ! filled($tenant->runtime_path)) {
            $credential->forceFill([
                'runtime_sync_status' => TenantGoogleCredential::RUNTIME_SYNC_PENDING,
                'last_error' => null,
            ])->save();

            return;
        }

        $this->googleWorkspaceSmokeTests->run($tenant);
        $this->clearKnownGoogleWorkspaceFailureMemory($tenant);

        $credential->forceFill([
            'runtime_sync_status' => TenantGoogleCredential::RUNTIME_SYNC_VERIFIED,
            'last_synced_at' => now(),
            'last_error' => null,
        ])->save();
    }

    public function clearKnownGoogleWorkspaceFailureMemory(Tenant $tenant): void
    {
        $tenant->loadMissing('server');

        foreach ($this->knownGoogleFailureMemoryFiles($tenant) as $localPath => $remotePath) {
            if ($this->files->exists($localPath)) {
                $this->files->delete($localPath);
            }

            if (! app()->environment('local') && $tenant->server) {
                $this->dockerCompose->removeFile($tenant->server, $remotePath);
            }
        }
    }

    private function ensureTenantCanGoLive(Tenant $tenant, ?BusinessProfile $profile, ?BusinessProfileFiles $profileFiles): void
    {
        if (! $tenant->server) {
            throw new RuntimeException('We could not find the assigned workspace server for this customer.');
        }

        if ($tenant->provisioning_status !== TenantProvisioningStatus::Ready) {
            throw new RuntimeException('Your workspace is still being prepared in the background. Please wait until it is ready before going live.');
        }

        if (! $profile) {
            throw new RuntimeException('We still need your business details before we can bring the assistant live.');
        }

        if (! $profileFiles || ! $profileFiles->generated_at || ! filled($profileFiles->identity_markdown) || ! filled($profileFiles->soul_markdown) || ! filled($profileFiles->user_markdown) || ! filled($profileFiles->bootstrap_markdown)) {
            throw new RuntimeException('We still need to prepare the internal business files before going live.');
        }

        /* Channel is optional — tenant can connect one later. */

        if (! filled($tenant->runtime_path)) {
            throw new RuntimeException('The workspace runtime path is missing, so we cannot sync the final setup files yet.');
        }
    }

    /**
     * @return array<string, string>
     */
    private function artifactContents(Tenant $tenant, BusinessProfile $profile, BusinessProfileFiles $profileFiles): array
    {
        return $this->runtimeCustomizationComposer->compose(
            $tenant->fresh(['businessProfile', 'businessProfileFiles', 'googleCredential', 'agentCustomization'])
        )->workspaceFiles;
    }

    /**
     * @param  array<string, mixed>  $baseConfig
     */
    private function renderComposedConfigContents(Tenant $tenant, array $baseConfig): string
    {
        return json_encode(
            $this->runtimeCustomizationComposer->composeOpenClawConfig(
                $tenant->fresh(['agentCustomization']),
                $tenant->fresh()->agentCustomization,
                $baseConfig,
            ),
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
        ).PHP_EOL;
    }

    /**
     * @return array{changed:bool, contents:string, remote_config_file:string}
     */
    private function syncLocalComposedOpenClawConfig(Tenant $tenant): array
    {
        $localConfigPath = $this->runtime->localOpenClawConfigPath($tenant);

        if (! $this->files->exists($localConfigPath)) {
            throw new RuntimeException('The tenant OpenClaw config file does not exist yet, so runtime capabilities cannot be applied.');
        }

        $existingContents = $this->files->get($localConfigPath);
        $existingConfig = json_decode($existingContents, true) ?: [];
        $updatedContents = $this->renderComposedConfigContents($tenant, $existingConfig);
        $changed = $updatedContents !== $existingContents;

        if ($changed) {
            $this->files->put($localConfigPath, $updatedContents);
        }

        return [
            'changed' => $changed,
            'contents' => $updatedContents,
            'remote_config_file' => $this->runtime->remoteOpenClawConfigPath($tenant),
        ];
    }

    /**
     * @param  array<int, string>  $services
     * @param  array<int, string>  $capabilities
     */
    private function buildProfileMarkdown(
        Tenant $tenant,
        BusinessProfile $profile,
        array $services,
        array $capabilities,
        string $channelLabel,
        ?TenantGoogleCredential $googleCredential,
    ): string
    {
        $serviceLines = $services === []
            ? ['- No services have been confirmed yet.']
            : array_map(static fn (string $service): string => '- '.$service, $services);

        $capabilityLines = $capabilities === []
            ? ['- No customer-handling capabilities have been selected yet.']
            : array_map(static fn (string $capability): string => '- '.$capability, $capabilities);

        $lines = [
            '# Business Profile',
            '',
            '## Summary',
            '- Business Name: '.($profile->business_name ?: $tenant->business_name),
            '- Trading Name: '.($profile->trading_name ?: 'Not provided'),
            '- Industry: '.($profile->industry ?: $tenant->industry),
            '- Skill Pack: '.($tenant->skill_pack ?: 'Not provided'),
            '- Website: '.($profile->website_url ?: 'Not provided'),
            '- Channel: '.$channelLabel,
            '- Communication Style: '.($tenant->tone ?: $profile->tone_hint ?: 'Not provided'),
            '',
            '## Description',
            $profile->description ?: 'A full business description has not been provided yet.',
            '',
            '## Services',
            ...$serviceLines,
            '',
            '## Contact Details',
            '- Contact Email: '.($profile->contact_email ?: 'Not provided'),
            '- Contact Phone: '.($profile->contact_phone ?: 'Not provided'),
            '- Mobile: '.($profile->contact_mobile ?: 'Not provided'),
            '- Address: '.($profile->physical_address ?: 'Not provided'),
            '- Postal Address: '.($profile->postal_address ?: 'Not provided'),
            '- City: '.($profile->city ?: 'Not provided'),
            '- Country: '.($profile->country ?: 'Not provided'),
            '- Tax / GST Number: '.($profile->tax_number ?: 'Not provided'),
            '- Company Registration: '.($profile->company_reg_number ?: 'Not provided'),
            '',
            '## Business Hours',
            ...(is_array($profile->business_hours) && $profile->business_hours !== []
                ? array_map(static fn (mixed $hours): string => '- '.(is_string($hours) ? $hours : json_encode($hours)), $profile->business_hours)
                : ['- Business hours have not been confirmed yet.']),
            '',
            '## Customer Handling',
            ...$capabilityLines,
            '',
            '## Owner Workspace Access',
            ...$this->ownerWorkspaceAccessLines($googleCredential),
            '',
            '## Notes',
            '- Pricing Notes: '.($profile->pricing_notes ?: 'Share tailored pricing guidance only when enough information is available.'),
            '- Target Customers: '.($profile->target_customers ?: 'Not provided'),
            '- After Hours Policy: '.($profile->after_hours_policy ?: 'Capture the message and arrange follow-up when needed.'),
        ];

        return implode(PHP_EOL, $lines).PHP_EOL;
    }

    /**
     * @param  array<int, string>  $capabilities
     */
    private function buildHeartbeatMarkdown(
        Tenant $tenant,
        BusinessProfile $profile,
        array $capabilities,
        string $channelLabel,
        ?TenantGoogleCredential $googleCredential,
    ): string
    {
        $rules = [
            '- Represent '.($profile->business_name ?: $tenant->business_name).' clearly and accurately.',
            '- Use a '.($tenant->tone ?: $profile->tone_hint ?: 'professional').' tone unless the customer context suggests a gentler variation is needed.',
            '- Do not invent pricing, turnaround times, guarantees, or services that are not in the business profile.',
            '- If details are missing, collect the customer message and hand it over rather than guessing.',
            '- Keep replies concise, helpful, and suited to '.$channelLabel.'.',
            '- **CRITICAL IDENTITY RULE**: You are an employee of '.($profile->business_name ?: $tenant->business_name).'. NEVER mention that you are an AI, a bot, or integrated with "OpenClaw", "OpenAI", "Claude", or any other platforms. Keep your true nature strictly confidential.',
            '- Treat messages from the workspace owner as internal operating requests when they ask about Google Workspace work such as inboxes, calendars, files, contacts, sheets, docs, slides, tasks, people, chat, classroom, forms, apps script, or groups.',
        ];

        if ($googleCredential?->isConnected()) {
            $rules[] = '- Google Workspace is connected for the owner account '.($googleCredential->google_email ?: 'on file').'. Use the available `gog` tools for '.$this->gogCommands->ownerServiceSummary().' when relevant.';
            $rules[] = '- Treat that connected Google account as the default account unless a tool explicitly reports multiple configured accounts or no default account.';
            $rules[] = '- Do not ask the owner which Google account to use unless a tool explicitly reports multiple configured accounts or a missing default account.';
            $rules[] = '- Sync360 owns OAuth and account configuration. Do not run `gog auth ...`, do not ask the owner to replace `credentials.json`, and do not ask them to redo Google API Console setup during a normal request.';
            $rules[] = '- If a Google Workspace tool call fails, explain the specific tool error you observed. Suggest reconnecting only when the error explicitly indicates invalid, expired, or unauthorized credentials. Treat insufficient-permission or missing-scope errors as permission issues, not missing credential-file issues. Do not say you are fundamentally unable to check emails or calendars.';
        } else {
            $rules[] = '- If the owner asks for Google Workspace help before Google Workspace is connected, explain that the workspace connection still needs to be completed in Sync360.';
        }

        if (filled($profile->after_hours_policy)) {
            $rules[] = '- Follow this after-hours policy when the business is unavailable: '.$profile->after_hours_policy;
        }

        if (in_array('after_hours', $capabilities, true)) {
            $rules[] = '- If the business is closed, explain that the message has been received and set expectations for follow-up.';
        }

        if (in_array('complaints', $capabilities, true)) {
            $rules[] = '- For complaints, acknowledge the concern calmly and gather the details needed for follow-up.';
        }

        if (in_array('appointments', $capabilities, true)) {
            $rules[] = '- For booking requests, guide the customer toward the next confirmed step instead of promising an appointment slot.';
        }

        return implode(PHP_EOL, [
            '# Heartbeat Rules',
            '',
            '## Always',
            ...$rules,
            '',
            '## Escalation',
            '- Escalate when the customer asks for something outside the confirmed services or when legal, billing, or safety-sensitive information is unclear.',
            '- Capture the customer name, best contact details, and what they need help with whenever human follow-up is required.',
        ]).PHP_EOL;
    }

    private function buildToolsMarkdown(?TenantGoogleCredential $googleCredential): string
    {
        $lines = [
            '# Tools',
            '',
            '## Workspace Exec',
            '- Use the workspace exec tool whenever you need to inspect or operate against runtime-local tooling.',
            '- Prefer direct command execution over speculative conversational answers when a tool can verify the result.',
            '',
            '## Google Workspace via gog',
        ];

        if (! $googleCredential?->isConnected()) {
            $lines = [
                ...$lines,
                '- Google Workspace is not connected for this tenant yet.',
                '- If the owner asks for Gmail, Calendar, Drive, Contacts, Sheets, or Docs help, explain that the Google Workspace step in Sync360 still needs to be completed.',
            ];

            return implode(PHP_EOL, $lines).PHP_EOL;
        }

        return implode(PHP_EOL, [
            ...$lines,
            ...$this->gogCommands->toolGuidanceLines($googleCredential),
        ]).PHP_EOL;
    }

    /**
     * @param  mixed  $values
     * @return array<int, string>
     */
    private function stringList(mixed $values): array
    {
        if (! is_array($values)) {
            return [];
        }

        return array_values(array_filter(
            array_map(
                static fn (mixed $value): ?string => is_string($value) && trim($value) !== '' ? trim($value) : null,
                $values,
            )
        ));
    }

    private function normalizeMarkdown(?string $markdown): string
    {
        return rtrim((string) $markdown).PHP_EOL;
    }

    /**
     * @return array<int, string>
     */
    private function ownerWorkspaceAccessLines(?TenantGoogleCredential $googleCredential): array
    {
        if (! $googleCredential?->isConnected()) {
            return [
                '- Google Workspace is not connected yet.',
                '- Owner requests for Google Workspace work should be handled only after the owner completes the Google Workspace step in Sync360.',
            ];
        }

        return $this->gogCommands->ownerAccessLines($googleCredential);
    }

    /**
     * @return array<string, string>
     */
    private function knownGoogleFailureMemoryFiles(Tenant $tenant): array
    {
        $localMemoryPath = $this->runtime->localWorkspaceMemoryPath($tenant);
        $remoteMemoryPath = $this->runtime->remoteWorkspaceMemoryPath($tenant);
        $dates = [now()->format('Y-m-d'), now()->subDay()->format('Y-m-d')];
        $suffixes = [
            'email-access-issue.md',
            'check-emails-issue.md',
            'email-check-issue.md',
        ];
        $paths = [];

        foreach ($dates as $date) {
            foreach ($suffixes as $suffix) {
                $filename = $date.'-'.$suffix;
                $paths[$localMemoryPath.DIRECTORY_SEPARATOR.$filename] = $remoteMemoryPath.DIRECTORY_SEPARATOR.$filename;
            }
        }

        return $paths;
    }
}
