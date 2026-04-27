<?php

namespace App\Services;

use App\Models\BusinessProfile;
use App\Models\BusinessProfileFiles;
use App\Models\Tenant;
use App\Models\TenantAgentCustomization;
use App\Models\TenantGoogleCredential;
use App\Models\TenantSkillAssignment;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;
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
        $config = $this->applyPrivateHookIngress($tenant, $config);

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
            $model = trim((string) $agentDefaults['model']);
            $config['agents']['defaults']['model'] = $model;
            $config['models'] = is_array($config['models'] ?? null) ? $config['models'] : [];
            $config['models']['providers'] = is_array($config['models']['providers'] ?? null) ? $config['models']['providers'] : [];
            $config['models']['providers']['openai'] = is_array($config['models']['providers']['openai'] ?? null)
                ? $config['models']['providers']['openai']
                : [];
            $config['models']['providers']['openai']['models'] = [
                [
                    'id' => $model,
                    'name' => $model,
                ],
            ];
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
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>
     */
    private function applyPrivateHookIngress(Tenant $tenant, array $config): array
    {
        $gatewayToken = data_get($config, 'gateway.auth.token');
        $gatewayToken = is_string($gatewayToken) ? trim($gatewayToken) : '';

        if ($gatewayToken === '') {
            return $config;
        }

        $hooks = is_array($config['hooks'] ?? null) ? $config['hooks'] : [];
        $hooks['enabled'] = true;
        $configuredHookToken = is_string($hooks['token'] ?? null) ? trim((string) $hooks['token']) : '';
        $hooks['token'] = $configuredHookToken !== '' && $configuredHookToken !== $gatewayToken
            ? trim((string) $hooks['token'])
            : hash('sha256', implode('|', [
                'sync360-hooks',
                (string) $tenant->tenant_id,
                $gatewayToken,
            ]));
        $hooks['path'] = is_string($hooks['path'] ?? null) && trim((string) $hooks['path']) !== ''
            ? trim((string) $hooks['path'])
            : '/hooks';

        $config['hooks'] = $hooks;

        return $config;
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
        $channelLabel = match ($tenant->channel) {
            'whatsapp' => 'WhatsApp',
            'telegram' => 'Telegram',
            default => 'Customer messaging channel',
        };
        $enabledAssignments = $this->enabledAssignments($tenant);
        $moduleLines = $this->moduleLines($enabledAssignments);
        $businessProfileArtifact = $this->buildBusinessProfileArtifact($tenant, $profile, $profileFiles, $enabledAssignments);

        return array_merge([
            'IDENTITY.md' => $this->normalizeMarkdown($profileFiles->identity_markdown),
            'SOUL.md' => $this->normalizeMarkdown($profileFiles->soul_markdown),
            'USER.md' => $this->normalizeMarkdown($profileFiles->user_markdown),
            'BOOTSTRAP.md' => $this->normalizeMarkdown($profileFiles->bootstrap_markdown),
            'AGENTS.md' => $this->normalizeMarkdown($this->buildAgentsMarkdown($enabledAssignments)),
            'TOOLS.md' => $this->normalizeMarkdown($this->buildToolsMarkdown($googleCredential)),
            'BUSINESS_PROFILE.json' => $this->buildBusinessProfileJson($businessProfileArtifact['payload']),
            'PROFILE.md' => $this->normalizeMarkdown($this->buildProfileMarkdown($tenant, $profile, $services, $moduleLines, $channelLabel, $googleCredential, $businessProfileArtifact['payload']['logo'])),
            'HEARTBEAT.md' => $this->normalizeMarkdown($this->buildHeartbeatMarkdown($tenant, $profile, $moduleLines, $channelLabel, $googleCredential)),
        ], $businessProfileArtifact['workspace_files'], $this->analyticsHelperFiles($enabledAssignments));
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
     * @param  Collection<int, TenantSkillAssignment>|array<int, TenantSkillAssignment>  $enabledAssignments
     * @return array<string, string>
     */
    private function analyticsHelperFiles(Collection|array $enabledAssignments): array
    {
        $registry = json_encode([
            'generated_at' => now()->toIso8601String(),
            'skills' => $this->skillRegistry->analyticsRegistryEntries($enabledAssignments),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        return [
            '.sync360/bin/log-skill-conversion' => $this->buildAnalyticsHelperShell(),
            '.sync360/bin/log-skill-conversion.mjs' => $this->buildAnalyticsHelperNode(),
            '.sync360/skill-analytics-registry.json' => ($registry ?: '{}').PHP_EOL,
        ];
    }

    private function buildAnalyticsHelperShell(): string
    {
        return $this->runtimeHelperTemplate('log-skill-conversion');
    }

    private function buildAnalyticsHelperNode(): string
    {
        return $this->runtimeHelperTemplate('log-skill-conversion.mjs');
    }

    private function runtimeHelperTemplate(string $filename): string
    {
        $path = base_path('resources/runtime-helpers/sync360/'.$filename);

        if (! $this->files->exists($path)) {
            throw new RuntimeException("Runtime helper template [{$filename}] is missing.");
        }

        return rtrim($this->files->get($path)).PHP_EOL;
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
            $lines[] = sprintf('- Runtime type: `%s`', (string) ($skill['runtime_type'] ?? TenantSkillRegistryService::RUNTIME_TYPE_SYNC360_WORKSPACE));

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

            $agentInstructions = $this->skillRegistry->agentInstructionsForAssignment($assignment);

            if ($agentInstructions !== null) {
                $lines[] = '- Agent instructions source: `skills/'.$assignment->skill_key.'/agent-instructions.md`';
                $lines[] = '';
                $lines[] = '#### Agent Instructions';
                $lines[] = '';
                $lines[] = $agentInstructions;
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
     * @param  array<int, string>  $moduleLines
     * @param  array{present:bool,path:?string,mime_type:?string,original_filename:?string,size_bytes:?int,uploaded_at:?string}  $logo
     */
    private function buildProfileMarkdown(
        Tenant $tenant,
        BusinessProfile $profile,
        array $services,
        array $moduleLines,
        string $channelLabel,
        ?TenantGoogleCredential $googleCredential,
        array $logo,
    ): string {
        $serviceLines = $services === []
            ? ['- No services have been confirmed yet.']
            : array_map(static fn (string $service): string => '- '.$service, $services);

        $lines = [
            '# Business Profile',
            '',
            '## Summary',
            '- Business Name: '.($profile->business_name ?: $tenant->business_name),
            '- Trading Name: '.($profile->trading_name ?: 'Not provided'),
            '- Industry: '.($profile->industry ?: $tenant->industry),
            '- Website: '.($profile->website_url ?: 'Not provided'),
            '- Channel: '.$channelLabel,
            '- Communication Style: '.($tenant->tone ?: $profile->tone_hint ?: 'Not provided'),
            '- Logo Asset: '.($logo['present'] ? ($logo['path'] ?: 'Present') : 'Not provided'),
            '',
            '## Description',
            $profile->description ?: 'A full business description has not been provided yet.',
            '',
            '## Enabled Modules',
            ...($moduleLines !== [] ? array_map(static fn (string $module): string => '- '.$module, $moduleLines) : ['- No enabled modules have been confirmed yet.']),
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
     * @param  array<int, string>  $moduleLines
     */
    private function buildHeartbeatMarkdown(
        Tenant $tenant,
        BusinessProfile $profile,
        array $moduleLines,
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
            $rules[] = '- When an assigned custom skill gives an exact tool contract, follow that contract exactly before falling back to general tool behavior.';
            $rules[] = '- For normal Telegram notifications, do not mix poll fields into a send action. Use only the fields the skill asks for, and report the exact tool error if sending fails.';
            $rules[] = '- When the owner replies to a high-value lead notification that includes `Lead ref: <gmail_message_id>`, read the replied message, extract that lead reference, and use `gog gmail get <gmail_message_id>` to reopen the exact email before drafting quotes, replies, bookings, or follow-up actions.';
            $rules[] = '- Do not guess with Gmail searches from company labels or notification summaries when an exact `Lead ref` is present in the replied message.';
            $rules[] = '- If the owner asks to continue from an email but did not reply to the original lead notification or no `Lead ref` is visible, ask them to reply to the original lead notification again or paste the lead reference.';
            $rules[] = '- For internal workflow triggers, do not browse the public web or research companies unless the owner explicitly asks for external research.';
            $rules[] = '- Inbox Triage must execute exactly one Gmail send action for a low-risk basic support or business-information enquiry when that branch applies: send the grounded answer now, or send one focused clarifying question when the answer is incomplete.';
            $rules[] = '- Do not claim that Inbox Triage will email a clarifying question or follow-up later unless `gog gmail send` or `gog gmail drafts create` already succeeded in the current run.';
            $rules[] = '- Inbox Triage auto-replies must follow the tenant tone chosen during onboarding, stay concise, and use plain ASCII body text without literal escape sequences such as `\\n`, `\\r`, or `\\t`.';
            $rules[] = '- Questions about services, opening hours, location coverage, or simple documented support do not qualify for `High-Value Lead Detected` unless the same message also shows clear commercial buying intent.';
            $rules[] = '- Never auto-reply from Inbox Triage with invented pricing, timelines, policy promises, legal/payment positions, or bespoke commitments.';
        } else {
            $rules[] = '- If the owner asks for Google Workspace help before Google Workspace is connected, explain that the workspace connection still needs to be completed in Sync360.';
        }

        if (filled($profile->after_hours_policy)) {
            $rules[] = '- Follow this after-hours policy when the business is unavailable: '.$profile->after_hours_policy;
        }

        if ($moduleLines !== []) {
            $rules[] = '- Stay within the purpose of the enabled modules: '.implode('; ', $moduleLines).'.';
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
     * @param  Collection<int, TenantSkillAssignment>|array<int, TenantSkillAssignment>  $assignments
     * @return array<int, string>
     */
    private function moduleLines(Collection|array $assignments): array
    {
        $lines = [];

        foreach ($assignments as $assignment) {
            if (! $assignment instanceof TenantSkillAssignment || ! $assignment->is_enabled) {
                continue;
            }

            $item = $assignment->catalogVersion?->item;
            $label = is_string($item?->label) && trim($item->label) !== '' ? trim($item->label) : $assignment->skill_key;
            $description = is_string($item?->description) && trim($item->description) !== '' ? trim($item->description) : null;
            $lines[] = $description ? sprintf('%s: %s', $label, $description) : $label;
        }

        return array_values(array_unique($lines));
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

    /**
     * @param  Collection<int, TenantSkillAssignment>  $enabledAssignments
     * @return array{payload: array<string, mixed>, workspace_files: array<string, string>}
     */
    private function buildBusinessProfileArtifact(
        Tenant $tenant,
        BusinessProfile $profile,
        BusinessProfileFiles $profileFiles,
        Collection $enabledAssignments,
    ): array {
        $logo = $this->logoPayload($profileFiles);
        $workspaceFiles = [];

        if (($logo['present'] ?? false) === true && is_string($logo['storage_path'] ?? null)) {
            $storagePath = trim((string) $logo['storage_path']);

            if ($storagePath !== '' && Storage::disk('local')->exists($storagePath) && is_string($logo['path'] ?? null)) {
                $workspaceFiles[$logo['path']] = Storage::disk('local')->get($storagePath);
            }
        }

        $payload = [
            'business_name' => $profile->business_name ?: $tenant->business_name,
            'trading_name' => $profile->trading_name,
            'industry' => $profile->industry ?: $tenant->industry,
            'description' => $profile->description,
            'tagline' => $profile->tagline,
            'website_url' => $profile->website_url,
            'contact_email' => $profile->contact_email,
            'contact_phone' => $profile->contact_phone,
            'contact_mobile' => $profile->contact_mobile,
            'physical_address' => $profile->physical_address,
            'postal_address' => $profile->postal_address,
            'city' => $profile->city,
            'country' => $profile->country,
            'tax_number' => $profile->tax_number,
            'company_reg_number' => $profile->company_reg_number,
            'owner_name' => $profile->owner_name,
            'owner_email' => $profile->owner_email,
            'owner_phone' => $profile->owner_phone,
            'business_hours' => is_array($profile->business_hours) ? array_values($profile->business_hours) : [],
            'after_hours_policy' => $profile->after_hours_policy,
            'primary_language' => $profile->primary_language,
            'services' => $this->stringList($profile->services),
            'faqs' => $this->stringList($profile->faqs),
            'target_customers' => $profile->target_customers,
            'pricing_notes' => $profile->pricing_notes,
            'tone' => $tenant->tone ?: $profile->tone_hint,
            'enabled_modules' => $this->enabledModulePayloads($enabledAssignments),
            'logo' => Arr::except($logo, ['storage_path']),
            'profile_completeness' => $profile->profile_completeness,
            'last_synced_to_agent' => $profile->last_synced_to_agent?->toIso8601String(),
            'generated_at' => $profileFiles->generated_at?->toIso8601String(),
            'updated_at' => $profile->updated_at?->toIso8601String(),
        ];

        return [
            'payload' => $payload,
            'workspace_files' => $workspaceFiles,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function buildBusinessProfileJson(array $payload): string
    {
        $encoded = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        if ($encoded === false) {
            throw new RuntimeException('Unable to encode BUSINESS_PROFILE.json.');
        }

        return $encoded.PHP_EOL;
    }

    /**
     * @return array{present:bool,path:?string,mime_type:?string,original_filename:?string,size_bytes:?int,uploaded_at:?string,storage_path:?string}
     */
    private function logoPayload(BusinessProfileFiles $profileFiles): array
    {
        $storagePath = is_string($profileFiles->logo_storage_path) ? trim($profileFiles->logo_storage_path) : '';
        $present = $storagePath !== '' && Storage::disk('local')->exists($storagePath);
        $extension = strtolower(pathinfo($storagePath, PATHINFO_EXTENSION));

        return [
            'present' => $present,
            'path' => $present ? 'business-assets/logo.'.($extension !== '' ? $extension : 'png') : null,
            'mime_type' => $present ? $profileFiles->logo_mime_type : null,
            'original_filename' => $present ? $profileFiles->logo_original_filename : null,
            'size_bytes' => $present ? $profileFiles->logo_size_bytes : null,
            'uploaded_at' => $present ? $profileFiles->logo_uploaded_at?->toIso8601String() : null,
            'storage_path' => $present ? $storagePath : null,
        ];
    }

    /**
     * @param  Collection<int, TenantSkillAssignment>  $assignments
     * @return array<int, array{skill_key:string,label:string,description:?string,onboarding_role:?string,runtime_type:string}>
     */
    private function enabledModulePayloads(Collection $assignments): array
    {
        $payload = [];

        foreach ($assignments as $assignment) {
            $item = $assignment->catalogVersion?->item;
            $skill = $this->skillRegistry->skillDefinitionForAssignment($assignment);

            $payload[] = [
                'skill_key' => $assignment->skill_key,
                'label' => is_string($item?->label) && trim($item->label) !== ''
                    ? trim($item->label)
                    : (string) ($skill['label'] ?? $assignment->skill_key),
                'description' => is_string($item?->description) && trim($item->description) !== ''
                    ? trim($item->description)
                    : (is_string($skill['description'] ?? null) && trim((string) $skill['description']) !== '' ? trim((string) $skill['description']) : null),
                'onboarding_role' => is_string($item?->onboarding_role) && trim($item->onboarding_role) !== ''
                    ? trim($item->onboarding_role)
                    : null,
                'runtime_type' => (string) ($skill['runtime_type'] ?? TenantSkillRegistryService::RUNTIME_TYPE_SYNC360_WORKSPACE),
            ];
        }

        return $payload;
    }
}
