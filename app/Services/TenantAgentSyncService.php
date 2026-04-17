<?php

namespace App\Services;

use App\Contracts\DockerComposeRunner;
use App\Enums\TenantProvisioningStatus;
use App\Models\BusinessProfile;
use App\Models\BusinessProfileFiles;
use App\Models\Tenant;
use App\Models\TenantGoogleCredential;
use App\Support\GoogleWorkspaceFeature;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Process;
use Throwable;

class TenantAgentSyncService
{
    public function __construct(
        private readonly Filesystem $files,
        private readonly TenantRuntimeService $runtime,
        private readonly DockerComposeRunner $dockerCompose,
        private readonly GogAuthStorageService $gogAuthStorage,
        private readonly TenantGoogleWorkspaceSmokeTestService $googleWorkspaceSmokeTests,
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
        $workspacePath = $localRuntimePath.DIRECTORY_SEPARATOR.'.openclaw'.DIRECTORY_SEPARATOR.'workspace';

        if (! $this->files->isDirectory($localRuntimePath) || ! $this->files->isDirectory($workspacePath)) {
            throw new RuntimeException('Your workspace files are still being prepared. Please wait a moment and try again.');
        }

        $artifacts = $this->artifactContents($tenant, $profile, $profileFiles);
        $remoteRuntimePath = $tenant->runtime_path ?: $this->runtime->remoteRuntimePath($tenant);
        $composeFile = rtrim($tenant->runtime_path ?: $remoteRuntimePath, DIRECTORY_SEPARATOR)
            .DIRECTORY_SEPARATOR
            .(string) config('sync360.openclaw.compose_filename', 'compose.yaml');
        $projectName = Str::limit('sync360-'.$tenant->slug, 63, '');
        $syncedAt = now();

        $tenant->forceFill([
            'agent_status' => 'deploying',
        ])->save();

        try {
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
                $remoteWorkspacePath = rtrim($remoteRuntimePath, DIRECTORY_SEPARATOR)
                    .DIRECTORY_SEPARATOR.'.openclaw'.DIRECTORY_SEPARATOR.'workspace';

                $this->dockerCompose->syncWorkspaceFiles($tenant->server, $localWorkspacePath, $remoteWorkspacePath);
                $this->dockerCompose->up($tenant->server, $composeFile, $projectName);
            }

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

        $this->files->put(
            $configPath,
            json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL,
        );

        /* Restart the gateway so it picks up the new config. */
        if (app()->environment('local')) {
            Log::info('[ConfigureChannel] Local dev mode — config written, skipping remote sync for tenant '.$tenant->slug);

            return;
        }

        $remoteRuntimePath = $tenant->runtime_path ?: $this->runtime->remoteRuntimePath($tenant);
        $remoteConfigPath = $remoteRuntimePath.'/config/openclaw.json';
        $composeFile = $remoteRuntimePath.'/'.(string) config('sync360.openclaw.compose_filename', 'compose.yaml');
        $projectName = Str::limit('sync360-'.$tenant->slug, 63, '');

        $this->dockerCompose->putFile(
            $tenant->server,
            $remoteConfigPath,
            json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL,
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

        $this->files->put(
            $configPath,
            json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL,
        );

        if (app()->environment('local')) {
            Log::info('[RemoveChannelConfig] Local dev mode — channels removed from config for tenant '.$tenant->slug);

            return;
        }

        $remoteRuntimePath = $tenant->runtime_path ?: $this->runtime->remoteRuntimePath($tenant);
        $remoteConfigPath = $remoteRuntimePath.'/config/openclaw.json';
        $composeFile = $remoteRuntimePath.'/'.(string) config('sync360.openclaw.compose_filename', 'compose.yaml');
        $projectName = Str::limit('sync360-'.$tenant->slug, 63, '');

        $this->dockerCompose->putFile(
            $tenant->server,
            $remoteConfigPath,
            json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL,
        );

        $this->dockerCompose->runCommand(
            $tenant->server,
            sprintf('docker compose -f %s -p %s restart', escapeshellarg($composeFile), escapeshellarg($projectName)),
        );

        Log::info('[RemoveChannelConfig] Channels removed and gateway restarted for tenant '.$tenant->slug);
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
        $composeUpdate = $this->ensureGoogleRuntimeEnvironment($tenant);

        $this->files->deleteDirectory($localConfigRoot);

        foreach ($localArtifacts as $path => $contents) {
            $this->files->ensureDirectoryExists(dirname($path));
            $this->files->put($path, $contents);
        }

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

            $this->reloadRuntime($tenant, $composeUpdate['changed']);
        } else {
            $this->reloadRuntime($tenant, $composeUpdate['changed']);
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

        $this->files->deleteDirectory($localConfigRoot);

        if ($tenant->server && filled($tenant->runtime_path)) {
            if (! app()->environment('local')) {
                $this->dockerCompose->removeDirectory($tenant->server, $this->gogAuthStorage->remoteConfigRoot($tenant));
            }

            $this->reloadRuntime($tenant);
        }

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
        }
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

        $credential->forceFill([
            'runtime_sync_status' => TenantGoogleCredential::RUNTIME_SYNC_VERIFIED,
            'last_synced_at' => now(),
            'last_error' => null,
        ])->save();
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

    private function reloadRuntime(Tenant $tenant, bool $recreate = false): void
    {
        $runtimePath = app()->environment('local')
            ? $this->runtime->localRuntimePath($tenant)
            : rtrim((string) $tenant->runtime_path, DIRECTORY_SEPARATOR);
        $composeFile = rtrim($runtimePath, DIRECTORY_SEPARATOR)
            .DIRECTORY_SEPARATOR
            .(string) config('sync360.openclaw.compose_filename', 'compose.yaml');
        $projectName = Str::limit('sync360-'.$tenant->slug, 63, '');

        if (app()->environment('local')) {
            $this->runLocalComposeCommand($tenant, $composeFile, $projectName, $recreate ? ['up', '-d', '--force-recreate'] : ['restart']);

            return;
        }

        if ($recreate) {
            $this->dockerCompose->runCommand(
                $tenant->server,
                sprintf('docker compose -f %s -p %s up -d --force-recreate', escapeshellarg($composeFile), escapeshellarg($projectName)),
            );

            return;
        }

        $this->dockerCompose->runCommand(
            $tenant->server,
            sprintf('docker compose -f %s -p %s restart', escapeshellarg($composeFile), escapeshellarg($projectName)),
        );
    }

    /**
     * @return array{changed:bool, contents:string, remote_compose_file:string}
     */
    private function ensureGoogleRuntimeEnvironment(Tenant $tenant): array
    {
        $localComposeFile = $this->runtime->localRuntimePath($tenant)
            .DIRECTORY_SEPARATOR
            .(string) config('sync360.openclaw.compose_filename', 'compose.yaml');

        if (! $this->files->exists($localComposeFile)) {
            throw new RuntimeException('The tenant compose file does not exist yet, so Google Workspace env settings cannot be applied.');
        }

        $existingContents = $this->files->get($localComposeFile);
        $updatedContents = $this->syncComposeEnvironment($existingContents, [
            'XDG_CONFIG_HOME' => $this->yamlQuote($this->runtime->containerGogConfigHome()),
            'GOG_KEYRING_BACKEND' => $this->yamlQuote('file'),
            'GOG_KEYRING_PASSWORD' => $this->yamlQuote($this->runtime->googleKeyringPassword($tenant)),
        ]);

        $changed = $updatedContents !== $existingContents;

        if ($changed) {
            $this->files->put($localComposeFile, $updatedContents);
        }

        return [
            'changed' => $changed,
            'contents' => $updatedContents,
            'remote_compose_file' => rtrim((string) $tenant->runtime_path, DIRECTORY_SEPARATOR)
                .DIRECTORY_SEPARATOR
                .(string) config('sync360.openclaw.compose_filename', 'compose.yaml'),
        ];
    }

    /**
     * @param  array<string, string>  $desiredEntries
     */
    private function syncComposeEnvironment(string $contents, array $desiredEntries): string
    {
        $lines = preg_split("/\r?\n/", $contents) ?: [];
        $environmentLine = null;

        foreach ($lines as $index => $line) {
            if (trim($line) === 'environment:') {
                $environmentLine = $index;
                break;
            }
        }

        if ($environmentLine === null) {
            throw new RuntimeException('The tenant compose file is missing an environment block.');
        }

        $blockStart = $environmentLine + 1;
        $blockEnd = count($lines);

        for ($index = $blockStart; $index < count($lines); $index++) {
            $line = $lines[$index];

            if ($line !== '' && ! str_starts_with($line, '      ')) {
                $blockEnd = $index;
                break;
            }
        }

        $existingEntries = [];
        $existingOrder = [];

        for ($index = $blockStart; $index < $blockEnd; $index++) {
            if (preg_match('/^\s{6}([A-Z0-9_]+):\s*(.+)$/', $lines[$index], $matches) === 1) {
                $existingEntries[$matches[1]] = $matches[2];
                $existingOrder[] = $matches[1];
            }
        }

        foreach ($desiredEntries as $key => $value) {
            if (! in_array($key, $existingOrder, true)) {
                $existingOrder[] = $key;
            }

            $existingEntries[$key] = $value;
        }

        $replacementLines = array_map(
            static fn (string $key): string => sprintf('      %s: %s', $key, $existingEntries[$key]),
            $existingOrder,
        );

        array_splice($lines, $blockStart, $blockEnd - $blockStart, $replacementLines);

        return implode(PHP_EOL, $lines);
    }

    private function yamlQuote(string $value): string
    {
        return '"'.str_replace(['\\', '"'], ['\\\\', '\\"'], $value).'"';
    }

    /**
     * @param  list<string>  $subCommand
     */
    private function runLocalComposeCommand(Tenant $tenant, string $composeFile, string $projectName, array $subCommand): void
    {
        $process = new Process([
            ...$this->runtime->localDockerComposeCommandParts(),
            '-f',
            $composeFile,
            '-p',
            $projectName,
            ...$subCommand,
        ], timeout: (int) config('sync360.openclaw.compose_timeout_seconds', 120));
        $process->run();

        if (! $process->isSuccessful()) {
            throw new ProcessFailedException($process);
        }
    }

    /**
     * @return array<string, string>
     */
    private function artifactContents(Tenant $tenant, BusinessProfile $profile, BusinessProfileFiles $profileFiles): array
    {
        $googleCredential = GoogleWorkspaceFeature::isAvailable() ? $tenant->googleCredential : null;
        $services = $this->stringList($profile->services);
        $capabilities = $this->stringList($tenant->capabilities);
        $channelLabel = match ($tenant->channel) {
            'whatsapp' => 'WhatsApp',
            'telegram' => 'Telegram',
            default => 'Customer messaging channel',
        };

        return [
            'IDENTITY.md' => $this->normalizeMarkdown($profileFiles->identity_markdown),
            'SOUL.md' => $this->normalizeMarkdown($profileFiles->soul_markdown),
            'USER.md' => $this->normalizeMarkdown($profileFiles->user_markdown),
            'BOOTSTRAP.md' => $this->normalizeMarkdown($profileFiles->bootstrap_markdown),
            'TOOLS.md' => $this->normalizeMarkdown($this->buildToolsMarkdown($googleCredential)),
            'PROFILE.md' => $this->normalizeMarkdown($this->buildProfileMarkdown($tenant, $profile, $services, $capabilities, $channelLabel, $googleCredential)),
            'HEARTBEAT.md' => $this->normalizeMarkdown($this->buildHeartbeatMarkdown($tenant, $profile, $capabilities, $channelLabel, $googleCredential)),
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
            '- Treat messages from the workspace owner as internal operating requests when they ask about inboxes, calendars, files, contacts, sheets, or docs.',
        ];

        if ($googleCredential?->isConnected()) {
            $rules[] = '- Google Workspace is connected for the owner account '.($googleCredential->google_email ?: 'on file').'. Use the available Gmail, Calendar, Drive, Contacts, Sheets, and Docs tools for owner requests when relevant.';
            $rules[] = '- If a Google Workspace tool call fails, explain that access is temporarily unavailable and ask the owner to retry or reconnect. Do not say you are fundamentally unable to check emails or calendars.';
        } else {
            $rules[] = '- If the owner asks for Gmail, Calendar, Drive, Contacts, Sheets, or Docs help before Google Workspace is connected, explain that the workspace connection still needs to be completed in Sync360.';
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

        $runtimeState = match ($googleCredential->runtime_sync_status) {
            TenantGoogleCredential::RUNTIME_SYNC_VERIFIED => 'verified',
            TenantGoogleCredential::RUNTIME_SYNC_SYNCED => 'synced but not yet fully verified',
            TenantGoogleCredential::RUNTIME_SYNC_FAILED => 'connected but currently needs attention',
            default => 'still being prepared',
        };

        return implode(PHP_EOL, [
            ...$lines,
            '- Google Workspace is connected for owner account '.($googleCredential->google_email ?: 'on file').'.',
            '- Runtime status is '.$runtimeState.'.',
            '- The `gog` CLI is preconfigured in this workspace. You do not need to run a fresh login when the connection is healthy.',
            '- When you need Gmail, Calendar, Drive, Contacts, Sheets, or Docs access, use exec to run `gog` commands instead of replying with a generic refusal.',
            '- If you are unsure which gog subcommand to use, inspect help first with `gog --help`, then `gog gmail --help`, `gog calendar --help`, `gog drive --help`, `gog contacts --help`, `gog sheets --help`, or `gog docs --help`.',
            '- For owner requests like "check my recent emails", first use exec to inspect the available gog Gmail commands, then run the relevant read/list command and summarize the findings clearly.',
            '- Prefer read/list actions first. Only send, update, or delete Google Workspace content when the owner explicitly asks for that action.',
            '- If a gog command fails, explain that Google Workspace access is temporarily unavailable and suggest retrying, resyncing, or reconnecting. Do not claim you fundamentally lack email or calendar access when the connection is present.',
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
                '- Owner requests for inbox, calendar, drive, contacts, sheets, or docs should be handled only after the owner completes the Google Workspace step in Sync360.',
            ];
        }

        $statusLabel = match ($googleCredential->runtime_sync_status) {
            TenantGoogleCredential::RUNTIME_SYNC_VERIFIED => 'Verified inside the live tenant runtime.',
            TenantGoogleCredential::RUNTIME_SYNC_SYNCED => 'Credentials synced into the tenant runtime and awaiting live verification.',
            TenantGoogleCredential::RUNTIME_SYNC_FAILED => 'Connection needs attention before owner workspace actions are reliable.',
            default => 'Connection is still being prepared in the tenant runtime.',
        };

        return [
            '- Google Workspace Account: '.($googleCredential->google_email ?: 'Connected'),
            '- Runtime Status: '.$statusLabel,
            '- When the workspace owner asks for recent emails, calendar events, files, contacts, sheets, or docs, use the available Google Workspace tools instead of giving a generic refusal.',
            '- If those tools fail during a request, explain that the workspace connection needs attention and suggest reconnecting or retrying after resync.',
        ];
    }
}
