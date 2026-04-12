<?php

namespace App\Services;

use App\Contracts\DockerComposeRunner;
use App\Enums\TenantProvisioningStatus;
use App\Models\BusinessProfile;
use App\Models\BusinessProfileFiles;
use App\Models\Tenant;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class TenantAgentSyncService
{
    public function __construct(
        private readonly Filesystem $files,
        private readonly TenantRuntimeService $runtime,
        private readonly DockerComposeRunner $dockerCompose,
    ) {
    }

    public function goLive(Tenant $tenant): void
    {
        $tenant->loadMissing(['server', 'businessProfile', 'businessProfileFiles']);

        $profile = $tenant->businessProfile;
        $profileFiles = $tenant->businessProfileFiles;

        $this->ensureTenantCanGoLive($tenant, $profile, $profileFiles);

        $localRuntimePath = $this->runtime->localRuntimePath($tenant);
        $workspacePath = $localRuntimePath.DIRECTORY_SEPARATOR.'workspace';

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
                $this->dockerCompose->syncRuntime($tenant->server, $localRuntimePath, $remoteRuntimePath);
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
                'onboarding_step' => max((int) $tenant->onboarding_step, 6),
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

        /* Ensure the default agent model is set if missing. */
        if (! isset($config['agent']['model'])) {
            $config['agent']['model'] = (string) config('sync360.openclaw.default_agent_model', 'gpt-4o');
        }

        /* Merge channel-specific settings into the openclaw config. */
        match ($tenant->channel) {
            'telegram' => $config['channels']['telegram'] = array_filter([
                'enabled' => true,
                'botToken' => $channelConfig['telegram_bot_token'] ?? null,
                'dmPolicy' => 'open',
                'allowFrom' => ['*'],
            ]),
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
            'PROFILE.md' => $this->normalizeMarkdown($this->buildProfileMarkdown($tenant, $profile, $services, $capabilities, $channelLabel)),
            'HEARTBEAT.md' => $this->normalizeMarkdown($this->buildHeartbeatMarkdown($tenant, $profile, $capabilities, $channelLabel)),
        ];
    }

    /**
     * @param  array<int, string>  $services
     * @param  array<int, string>  $capabilities
     */
    private function buildProfileMarkdown(Tenant $tenant, BusinessProfile $profile, array $services, array $capabilities, string $channelLabel): string
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
    private function buildHeartbeatMarkdown(Tenant $tenant, BusinessProfile $profile, array $capabilities, string $channelLabel): string
    {
        $rules = [
            '- Represent '.($profile->business_name ?: $tenant->business_name).' clearly and accurately.',
            '- Use a '.($tenant->tone ?: $profile->tone_hint ?: 'professional').' tone unless the customer context suggests a gentler variation is needed.',
            '- Do not invent pricing, turnaround times, guarantees, or services that are not in the business profile.',
            '- If details are missing, collect the customer message and hand it over rather than guessing.',
            '- Keep replies concise, helpful, and suited to '.$channelLabel.'.',
        ];

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
}
