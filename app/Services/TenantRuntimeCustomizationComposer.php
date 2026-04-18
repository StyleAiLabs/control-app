<?php

namespace App\Services;

use App\Models\BusinessProfile;
use App\Models\BusinessProfileFiles;
use App\Models\Tenant;
use App\Models\TenantAgentCustomization;
use App\Models\TenantGoogleCredential;
use App\Models\TenantSkillAssignment;
use Illuminate\Support\Collection;
use Illuminate\Filesystem\Filesystem;
use RuntimeException;

class TenantRuntimeCustomizationComposer
{
    public function __construct(
        private readonly Filesystem $files,
        private readonly TenantRuntimeService $runtime,
        private readonly TenantRuntimeCapabilityService $runtimeCapabilities,
        private readonly TenantSkillRegistryService $skillRegistry,
        private readonly GogCommandCatalogService $gogCommands,
    ) {
    }

    public function compose(Tenant $tenant, ?TenantAgentCustomization $customization = null, ?array $baseConfig = null): ComposedTenantRuntime
    {
        $tenant->loadMissing(['businessProfile', 'businessProfileFiles', 'googleCredential', 'agentCustomization', 'skillAssignments.catalogVersion']);

        $profile = $tenant->businessProfile;
        $profileFiles = $tenant->businessProfileFiles;

        if (! $profile || ! $profileFiles) {
            throw new RuntimeException('Business profile files are required before composing tenant runtime customization output.');
        }

        $customization ??= $tenant->agentCustomization;
        $promptOverrides = is_array($customization?->prompt_overrides_json) ? $customization->prompt_overrides_json : [];
        $agentDefaults = is_array($customization?->agent_defaults_json) ? $customization->agent_defaults_json : [];
        $enabledAssignments = $this->enabledAssignments($tenant);

        $workspaceFiles = $this->baseWorkspaceFiles($tenant, $profile, $profileFiles);
        $skillFiles = $this->skillRegistry->renderedSkillFiles($enabledAssignments);
        $baseDrifted = [
            'identity' => false,
            'soul' => false,
            'user' => false,
            'bootstrap' => false,
        ];

        foreach ($this->promptFileMap() as $key => $filename) {
            $override = $promptOverrides[$key] ?? null;

            if (! is_array($override)) {
                continue;
            }

            $mode = is_string($override['mode'] ?? null) ? trim((string) $override['mode']) : '';
            $content = is_string($override['content'] ?? null) ? trim((string) $override['content']) : '';
            $baseSnapshot = is_string($override['base_snapshot'] ?? null) ? trim((string) $override['base_snapshot']) : '';
            $currentBase = trim($this->basePromptContent($profileFiles, $key));

            if ($baseSnapshot !== '' && $baseSnapshot !== $currentBase) {
                $baseDrifted[$key] = true;
            }

            if ($content === '') {
                continue;
            }

            if ($mode === 'replace') {
                $workspaceFiles[$filename] = $this->normalizeMarkdown($content);
                continue;
            }

            if ($mode === 'append') {
                $workspaceFiles[$filename] = $this->normalizeMarkdown(
                    rtrim($workspaceFiles[$filename]).PHP_EOL.PHP_EOL
                    .'<!-- sync360:admin-extras:start -->'.PHP_EOL
                    .$content.PHP_EOL
                    .'<!-- sync360:admin-extras:end -->'
                );
            }
        }

        $config = $this->composeOpenClawConfig($tenant, $customization, $baseConfig);
        $configJson = json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL;
        $manifest = array_merge(array_keys($workspaceFiles), array_keys($skillFiles));
        sort($manifest);

        return new ComposedTenantRuntime(
            workspaceFiles: $workspaceFiles,
            skillFiles: $skillFiles,
            openClawConfig: $configJson,
            baseDrifted: $baseDrifted,
            workspaceFileManifest: $manifest,
            contentHash: $this->contentHash($workspaceFiles, $skillFiles, $configJson),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function composeOpenClawConfig(Tenant $tenant, ?TenantAgentCustomization $customization = null, ?array $baseConfig = null): array
    {
        $tenant->loadMissing(['agentCustomization', 'skillAssignments.catalogVersion']);
        $customization ??= $tenant->agentCustomization;

        $config = $baseConfig ?? $this->readLocalConfig($tenant);
        $config = is_array($config) ? $config : [];
        $config['agents'] = is_array($config['agents'] ?? null) ? $config['agents'] : [];
        $config['agents']['defaults'] = is_array($config['agents']['defaults'] ?? null) ? $config['agents']['defaults'] : [];
        $config['skills'] = is_array($config['skills'] ?? null) ? $config['skills'] : [];
        $config['skills']['entries'] = is_array($config['skills']['entries'] ?? null) ? $config['skills']['entries'] : [];

        $agentDefaults = is_array($customization?->agent_defaults_json) ? $customization->agent_defaults_json : [];
        $enabledAssignments = $this->enabledAssignments($tenant);
        $currentAssignedSkillIds = array_values(array_unique(array_merge(
            $this->skillRegistry->openClawSkillIds($enabledAssignments),
            $this->skillRegistry->defaultAgentSkillIds($enabledAssignments),
            $this->normalizedSkillList((array) ($agentDefaults['default_skill_ids'] ?? [])),
        )));
        $currentDefaultSkillIds = array_values(array_unique(array_merge(
            $this->skillRegistry->defaultAgentSkillIds($enabledAssignments),
            $this->normalizedSkillList((array) ($agentDefaults['default_skill_ids'] ?? [])),
        )));
        $previousAssignedSkills = $this->previousAssignedSkillSnapshot($customization);
        $removedSkillIds = array_values(array_diff($previousAssignedSkills, $currentAssignedSkillIds));

        if (is_string($agentDefaults['model'] ?? null) && trim((string) $agentDefaults['model']) !== '') {
            $config['agents']['defaults']['model'] = trim((string) $agentDefaults['model']);
        }

        foreach ($currentAssignedSkillIds as $skillId) {
            $entry = $config['skills']['entries'][$skillId] ?? [];
            $config['skills']['entries'][$skillId] = array_merge(is_array($entry) ? $entry : [], [
                'enabled' => true,
            ]);
        }

        foreach ($removedSkillIds as $skillId) {
            $entry = $config['skills']['entries'][$skillId] ?? [];
            $config['skills']['entries'][$skillId] = array_merge(is_array($entry) ? $entry : [], [
                'enabled' => false,
            ]);
        }

        $config['agents']['defaults']['skills'] = $this->replaceSkillSet(
            $config['agents']['defaults']['skills'] ?? [],
            $currentDefaultSkillIds,
            $removedSkillIds,
        );

        if (is_array($config['agents']['list'] ?? null)) {
            $config['agents']['list'] = array_map(function (mixed $agent) use ($currentAssignedSkillIds, $removedSkillIds): mixed {
                if (! is_array($agent)) {
                    return $agent;
                }

                $agent['skills'] = $this->replaceSkillSet(
                    $agent['skills'] ?? [],
                    $currentAssignedSkillIds,
                    $removedSkillIds,
                );

                return $agent;
            }, $config['agents']['list']);
        }

        return $this->runtimeCapabilities->applyOpenClawSkills($config);
    }

    /**
     * @return array<string, mixed>
     */
    private function readLocalConfig(Tenant $tenant): array
    {
        $path = $this->runtime->localOpenClawConfigPath($tenant);

        if (! $this->files->exists($path)) {
            return [];
        }

        return json_decode($this->files->get($path), true) ?: [];
    }

    /**
     * @return array<string, string>
     */
    private function baseWorkspaceFiles(Tenant $tenant, BusinessProfile $profile, BusinessProfileFiles $profileFiles): array
    {
        $googleCredential = $tenant->googleCredential;
        $services = $this->stringList($profile->services);
        $capabilities = $this->stringList($tenant->capabilities);
        $channelLabel = match ($tenant->channel) {
            'whatsapp' => 'WhatsApp',
            'telegram' => 'Telegram',
            default => 'Customer messaging channel',
        };
        $enabledAssignments = $this->enabledAssignments($tenant);

        return [
            'IDENTITY.md' => $this->normalizeMarkdown($profileFiles->identity_markdown),
            'SOUL.md' => $this->normalizeMarkdown($profileFiles->soul_markdown),
            'USER.md' => $this->normalizeMarkdown($profileFiles->user_markdown),
            'BOOTSTRAP.md' => $this->normalizeMarkdown($profileFiles->bootstrap_markdown),
            'AGENTS.md' => $this->normalizeMarkdown($this->buildAgentsMarkdown($enabledAssignments)),
            'TOOLS.md' => $this->normalizeMarkdown($this->buildToolsMarkdown($googleCredential)),
            'PROFILE.md' => $this->normalizeMarkdown($this->buildProfileMarkdown($tenant, $profile, $services, $capabilities, $channelLabel, $googleCredential)),
            'HEARTBEAT.md' => $this->normalizeMarkdown($this->buildHeartbeatMarkdown($tenant, $profile, $capabilities, $channelLabel, $googleCredential)),
        ];
    }

    private function contentHash(array $workspaceFiles, array $skillFiles, string $openClawConfig): string
    {
        ksort($workspaceFiles);
        ksort($skillFiles);
        $payload = '';

        foreach ($workspaceFiles as $name => $contents) {
            $payload .= $name."\n".$contents."\n";
        }

        foreach ($skillFiles as $name => $contents) {
            $payload .= $name."\n".$contents."\n";
        }

        $payload .= "openclaw.json\n".$openClawConfig;

        return hash('sha256', $payload);
    }

    /**
     * @return array<string, string>
     */
    private function promptFileMap(): array
    {
        return [
            'identity' => 'IDENTITY.md',
            'soul' => 'SOUL.md',
            'user' => 'USER.md',
            'bootstrap' => 'BOOTSTRAP.md',
        ];
    }

    private function basePromptContent(BusinessProfileFiles $files, string $key): string
    {
        return match ($key) {
            'identity' => (string) $files->identity_markdown,
            'soul' => (string) $files->soul_markdown,
            'user' => (string) $files->user_markdown,
            'bootstrap' => (string) $files->bootstrap_markdown,
            default => '',
        };
    }

    private function normalizeMarkdown(?string $markdown): string
    {
        return rtrim((string) $markdown).PHP_EOL;
    }

    /**
     * @param  Collection<int, TenantSkillAssignment>  $assignments
     */
    private function buildAgentsMarkdown(Collection $assignments): string
    {
        $lines = [
            '# Agents',
            '',
            'Use this file as tenant-scoped guidance for which additional assigned skills are currently available to the assistant.',
        ];

        if ($assignments->isEmpty()) {
            return implode(PHP_EOL, $lines);
        }

        $lines = array_merge($lines, [
            '',
            '## Assigned Skill Guidance',
            '',
            'When a customer request clearly matches one of the assigned skills below, prefer using that skill and follow its `SKILL.md` instructions.',
        ]);

        foreach ($assignments as $assignment) {
            $skill = $this->skillRegistry->skillDefinitionForAssignment($assignment);
            $lines[] = '';
            $lines[] = sprintf('### %s', (string) ($skill['label'] ?? $assignment->skill_key));
            $lines[] = sprintf('- Skill key: `%s`', $assignment->skill_key);

            $skillIds = array_values(array_unique(array_map(
                static fn (string $skillId): string => trim($skillId),
                array_filter(
                    (array) ($skill['openclaw_skill_ids'] ?? []),
                    static fn (mixed $skillId): bool => is_string($skillId) && trim($skillId) !== ''
                )
            )));

            if ($skillIds !== []) {
                $lines[] = sprintf('- OpenClaw skill IDs: `%s`', implode('`, `', $skillIds));
            }

            $description = trim((string) ($skill['description'] ?? ''));

            if ($description !== '') {
                $lines[] = sprintf('- Use when: %s', $description);
            }
        }

        return implode(PHP_EOL, $lines);
    }

    /**
     * @param  mixed  $skills
     * @param  array<int, string>  $requiredSkills
     * @return array<int, string>
     */
    private function replaceSkillSet(mixed $skills, array $requiredSkills, array $removedSkillIds): array
    {
        $normalized = [];

        foreach (is_array($skills) ? $skills : [] as $skill) {
            if (is_string($skill) && trim($skill) !== '' && ! in_array(trim($skill), $normalized, true)) {
                $normalized[] = trim($skill);
            }
        }

        $normalized = array_values(array_filter(
            $normalized,
            fn (string $skill): bool => ! in_array($skill, $removedSkillIds, true),
        ));

        foreach ($requiredSkills as $skill) {
            if (is_string($skill) && trim($skill) !== '' && ! in_array(trim($skill), $normalized, true)) {
                $normalized[] = trim($skill);
            }
        }

        return $normalized;
    }

    /**
     * @return Collection<int, TenantSkillAssignment>
     */
    private function enabledAssignments(Tenant $tenant): Collection
    {
        return $tenant->skillAssignments
            ->filter(fn (TenantSkillAssignment $assignment): bool => $assignment->is_enabled)
            ->values();
    }

    /**
     * @return list<string>
     */
    private function previousAssignedSkillSnapshot(?TenantAgentCustomization $customization): array
    {
        $snapshot = is_array($customization?->last_applied_input_snapshot_json)
            ? $customization->last_applied_input_snapshot_json
            : [];
        $skillIds = [];

        foreach ((array) ($snapshot['assigned_skills'] ?? []) as $assignment) {
            foreach ((array) ($assignment['openclaw_skill_ids'] ?? []) as $skillId) {
                if (is_string($skillId) && trim($skillId) !== '' && ! in_array(trim($skillId), $skillIds, true)) {
                    $skillIds[] = trim($skillId);
                }
            }

            foreach ((array) ($assignment['default_agent_skill_ids'] ?? []) as $skillId) {
                if (is_string($skillId) && trim($skillId) !== '' && ! in_array(trim($skillId), $skillIds, true)) {
                    $skillIds[] = trim($skillId);
                }
            }
        }

        foreach ($this->normalizedSkillList((array) data_get($snapshot, 'agent_defaults.default_skill_ids', [])) as $skillId) {
            if (! in_array($skillId, $skillIds, true)) {
                $skillIds[] = $skillId;
            }
        }

        return $skillIds;
    }

    /**
     * @param  array<int, mixed>  $skills
     * @return list<string>
     */
    private function normalizedSkillList(array $skills): array
    {
        $normalized = [];

        foreach ($skills as $skill) {
            if (is_string($skill) && trim($skill) !== '' && ! in_array(trim($skill), $normalized, true)) {
                $normalized[] = trim($skill);
            }
        }

        return $normalized;
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
    ): string {
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
    ): string {
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
}
