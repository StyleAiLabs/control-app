<?php

namespace App\Services;

use App\Models\Server;
use App\Models\Tenant;
use App\Models\TenantSkillAssignment;
use Illuminate\Filesystem\Filesystem;
use RuntimeException;
use Symfony\Component\Process\Process;

class TenantSkillRuntimeInspectorService
{
    public function __construct(
        private readonly Filesystem $files,
        private readonly TenantRuntimeService $runtime,
        private readonly TenantSkillRegistryService $skillRegistry,
        private readonly TenantSkillAnalyticsRuntimeStorageService $runtimeStorage,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function inspect(Tenant $tenant): array
    {
        $tenant->loadMissing(['server', 'skillAssignments.catalogVersion', 'skillAnalyticsSyncState']);

        $assignments = $tenant->skillAssignments
            ->filter(fn (TenantSkillAssignment $assignment): bool => $assignment->is_enabled)
            ->sortBy('skill_key')
            ->values();
        $config = $this->readJson($tenant, $this->openClawConfigPath($tenant));
        $registry = $this->readJson($tenant, $this->analyticsRegistryPath($tenant));
        $analyticsRegistrySkills = is_array($registry['skills'] ?? null) ? $registry['skills'] : [];
        $configuredSkillIds = $this->configuredOpenClawSkillIds($config);
        $runtimeVisibility = $this->runtimeSkillVisibility($tenant);
        $warnings = [];
        $assigned = [];
        $materialized = [];

        foreach ($assignments as $assignment) {
            $skill = $this->skillRegistry->skillDefinitionForAssignment($assignment);
            $runtimeType = (string) ($skill['runtime_type'] ?? TenantSkillRegistryService::RUNTIME_TYPE_SYNC360_WORKSPACE);
            $openClawSkillIds = $this->stringList($skill['openclaw_skill_ids'] ?? []);
            $defaultAgentSkillIds = $this->stringList($skill['default_agent_skill_ids'] ?? []);
            $analyticsEnabled = (bool) data_get($skill, 'analytics.enabled', false);

            $assigned[] = [
                'skill_key' => $assignment->skill_key,
                'label' => (string) ($skill['label'] ?? $assignment->skill_key),
                'version' => (string) ($skill['version'] ?? $assignment->catalogVersion?->version ?? '0.0.0'),
                'runtime_type' => $runtimeType,
                'openclaw_skill_ids' => $openClawSkillIds,
                'default_agent_skill_ids' => $defaultAgentSkillIds,
                'analytics_enabled' => $analyticsEnabled,
            ];

            $fileStates = [];
            foreach (['SKILL.md', 'agent-instructions.md', 'RELEASE_NOTES.md'] as $filename) {
                $path = $this->workspaceSkillPath($tenant, $assignment->skill_key, $filename);
                $exists = $this->pathExists($tenant, $path, 'file');
                $fileStates[$filename] = $exists;

                if (! $exists) {
                    $warnings[] = sprintf('%s is missing materialized workspace file %s.', $assignment->skill_key, $filename);
                }
            }

            $materialized[] = [
                'skill_key' => $assignment->skill_key,
                'files' => $fileStates,
            ];

            if ($analyticsEnabled && ! array_key_exists($assignment->skill_key, $analyticsRegistrySkills)) {
                $warnings[] = sprintf('%s has analytics enabled but is missing from .sync360/skill-analytics-registry.json.', $assignment->skill_key);
            }

            if ($runtimeType === TenantSkillRegistryService::RUNTIME_TYPE_SYNC360_WORKSPACE) {
                $expectedSkillIds = $openClawSkillIds !== [] ? $openClawSkillIds : [$assignment->skill_key];

                foreach ($expectedSkillIds as $skillId) {
                    if (! $this->isAllowlisted($config, $skillId)) {
                        $warnings[] = sprintf('%s is missing from OpenClaw agent skill allowlists.', $assignment->skill_key);
                    }

                    if (data_get($config, 'skills.entries.'.$skillId.'.enabled') === false) {
                        $warnings[] = sprintf('%s is disabled in openclaw.json skills.entries.', $assignment->skill_key);
                    }

                    if (($runtimeVisibility['checked'] ?? false) === true && ! in_array($skillId, (array) ($runtimeVisibility['skills'] ?? []), true)) {
                        $warnings[] = sprintf('%s is not visible in runtime openclaw skills list.', $assignment->skill_key);
                    }
                }
            }

            if ($runtimeType === TenantSkillRegistryService::RUNTIME_TYPE_OPENCLAW_NATIVE && $openClawSkillIds === []) {
                $warnings[] = sprintf('%s is openclaw_native but has no openclaw_skill_ids.', $assignment->skill_key);
            }
        }

        $analyticsDbPath = $this->analyticsDbPath($tenant);
        $analyticsDbExists = $this->pathExists($tenant, $analyticsDbPath, 'file');
        $analyticsDbRowCount = null;

        if ($analyticsDbExists) {
            try {
                $analyticsDbRowCount = $this->runtimeStorage->rowCountForTenant($tenant);
            } catch (RuntimeException $exception) {
                $warnings[] = sprintf('Unable to read runtime SQLite row count: %s', $exception->getMessage());
            }
        }

        if ($this->hasAnalyticsSkill($assigned) && ! $analyticsDbExists) {
            $warnings[] = sprintf('Analytics-enabled tenant has no runtime SQLite DB at %s.', $analyticsDbPath);
        }

        $syncState = $tenant->skillAnalyticsSyncState;

        return [
            'tenant' => [
                'id' => $tenant->id,
                'tenant_id' => $tenant->tenant_id,
                'slug' => $tenant->slug,
                'driver' => $this->usesLocalRuntimeDriver() ? 'local' : 'ssh',
            ],
            'assigned_skills' => $assigned,
            'materialized_workspace_skill_files' => $materialized,
            'analytics_registry' => [
                'path' => $this->analyticsRegistryPath($tenant),
                'exists' => $this->pathExists($tenant, $this->analyticsRegistryPath($tenant), 'file'),
                'skills' => array_keys($analyticsRegistrySkills),
            ],
            'sqlite_db' => [
                'path' => $analyticsDbPath,
                'exists' => $analyticsDbExists,
                'row_count' => $analyticsDbRowCount,
            ],
            'sync_state' => [
                'last_runtime_row_id' => $syncState?->last_runtime_row_id,
                'last_synced_at' => $syncState?->last_synced_at?->toIso8601String(),
                'last_failed_at' => $syncState?->last_failed_at?->toIso8601String(),
                'last_error_message' => $syncState?->last_error_message,
            ],
            'openclaw_config_skill_ids' => $configuredSkillIds,
            'runtime_skill_visibility' => $runtimeVisibility,
            'warnings' => array_values(array_unique($warnings)),
        ];
    }

    private function openClawConfigPath(Tenant $tenant): string
    {
        return $this->usesLocalRuntimeDriver()
            ? $this->runtime->localOpenClawConfigPath($tenant)
            : $this->runtime->remoteOpenClawConfigPath($tenant);
    }

    private function analyticsRegistryPath(Tenant $tenant): string
    {
        $workspacePath = $this->usesLocalRuntimeDriver()
            ? $this->runtime->localWorkspacePath($tenant)
            : $this->runtime->remoteWorkspacePath($tenant);

        return rtrim($workspacePath, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.'.sync360'.DIRECTORY_SEPARATOR.'skill-analytics-registry.json';
    }

    private function analyticsDbPath(Tenant $tenant): string
    {
        return $this->usesLocalRuntimeDriver()
            ? $this->runtime->localSkillAnalyticsDbPath($tenant)
            : $this->runtime->remoteSkillAnalyticsDbPath($tenant);
    }

    private function workspaceSkillPath(Tenant $tenant, string $skillKey, string $filename): string
    {
        $workspacePath = $this->usesLocalRuntimeDriver()
            ? $this->runtime->localWorkspacePath($tenant)
            : $this->runtime->remoteWorkspacePath($tenant);

        return rtrim($workspacePath, DIRECTORY_SEPARATOR)
            .DIRECTORY_SEPARATOR.'skills'
            .DIRECTORY_SEPARATOR.$skillKey
            .DIRECTORY_SEPARATOR.$filename;
    }

    /**
     * @return array<string, mixed>
     */
    private function readJson(Tenant $tenant, string $path): array
    {
        $contents = $this->readFile($tenant, $path);

        if ($contents === null || trim($contents) === '') {
            return [];
        }

        $decoded = json_decode($contents, true);

        return is_array($decoded) ? $decoded : [];
    }

    private function readFile(Tenant $tenant, string $path): ?string
    {
        if ($this->usesLocalRuntimeDriver()) {
            return $this->files->exists($path) ? $this->files->get($path) : null;
        }

        if (! $tenant->server) {
            return null;
        }

        $command = sprintf('if [ -f %1$s ]; then cat %1$s; fi', $this->shellQuote($path));
        $output = $this->runRemoteShell($tenant->server, $command);

        return trim($output) === '' ? null : $output;
    }

    private function pathExists(Tenant $tenant, string $path, string $type): bool
    {
        if ($this->usesLocalRuntimeDriver()) {
            return $type === 'dir' ? $this->files->isDirectory($path) : $this->files->isFile($path);
        }

        if (! $tenant->server) {
            return false;
        }

        $testFlag = $type === 'dir' ? '-d' : '-f';
        $command = sprintf('if [ %s %s ]; then printf 1; else printf 0; fi', $testFlag, $this->shellQuote($path));

        return trim($this->runRemoteShell($tenant->server, $command)) === '1';
    }

    /**
     * @param  array<string, mixed>  $config
     * @return list<string>
     */
    private function configuredOpenClawSkillIds(array $config): array
    {
        $skillIds = array_merge($this->agentDefaultSkillIds($config), $this->agentListSkillIds($config));

        foreach ((array) data_get($config, 'skills.entries', []) as $skillId => $entry) {
            if (is_string($skillId) && is_array($entry) && ($entry['enabled'] ?? false) === true) {
                $skillIds[] = $skillId;
            }
        }

        $skillIds = array_values(array_unique(array_filter(
            $skillIds,
            static fn (string $skillId): bool => trim($skillId) !== '',
        )));
        sort($skillIds);

        return $skillIds;
    }

    /**
     * @param  array<string, mixed>  $config
     * @return list<string>
     */
    private function agentDefaultSkillIds(array $config): array
    {
        return $this->stringList(data_get($config, 'agents.defaults.skills', []));
    }

    /**
     * @param  array<string, mixed>  $config
     * @return list<string>
     */
    private function agentListSkillIds(array $config): array
    {
        $skillIds = [];

        foreach ((array) data_get($config, 'agents.list', []) as $agent) {
            if (! is_array($agent)) {
                continue;
            }

            $skillIds = array_merge($skillIds, $this->stringList($agent['skills'] ?? []));
        }

        return array_values(array_unique($skillIds));
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function isAllowlisted(array $config, string $skillId): bool
    {
        return in_array($skillId, array_merge($this->agentDefaultSkillIds($config), $this->agentListSkillIds($config)), true);
    }

    /**
     * @return array{checked:bool, available:bool, skills:list<string>, error:?string}
     */
    private function runtimeSkillVisibility(Tenant $tenant): array
    {
        try {
            if (! $this->containerIsRunning($tenant)) {
                return [
                    'checked' => false,
                    'available' => false,
                    'skills' => [],
                    'error' => 'Tenant container is not running or could not be inspected.',
                ];
            }

            $command = sprintf(
                'docker exec %s sh -lc %s',
                $this->shellQuote($this->runtime->containerName($tenant)),
                $this->shellQuote('openclaw skills list --json 2>/dev/null || openclaw skills list')
            );
            $output = $this->usesLocalRuntimeDriver()
                ? $this->runLocalShell($command)
                : $this->runRemoteShell($this->serverFor($tenant), $command);

            return [
                'checked' => true,
                'available' => true,
                'skills' => $this->parseSkillList($output),
                'error' => null,
            ];
        } catch (RuntimeException $exception) {
            return [
                'checked' => false,
                'available' => false,
                'skills' => [],
                'error' => $exception->getMessage(),
            ];
        }
    }

    private function containerIsRunning(Tenant $tenant): bool
    {
        $command = sprintf(
            'docker inspect -f %s %s 2>/dev/null',
            $this->shellQuote('{{.State.Running}}'),
            $this->shellQuote($this->runtime->containerName($tenant)),
        );

        try {
            $output = $this->usesLocalRuntimeDriver()
                ? $this->runLocalShell($command)
                : $this->runRemoteShell($this->serverFor($tenant), $command);
        } catch (RuntimeException) {
            return false;
        }

        return trim($output) === 'true';
    }

    /**
     * @return list<string>
     */
    private function parseSkillList(string $output): array
    {
        $decoded = json_decode(trim($output), true);
        $skillIds = [];

        if (is_array($decoded)) {
            $rows = array_is_list($decoded) ? $decoded : ($decoded['skills'] ?? []);

            foreach (is_array($rows) ? $rows : [] as $row) {
                if (is_string($row)) {
                    $skillIds[] = $row;
                    continue;
                }

                if (! is_array($row)) {
                    continue;
                }

                foreach (['name', 'id', 'skill', 'key'] as $key) {
                    if (is_string($row[$key] ?? null) && trim((string) $row[$key]) !== '') {
                        $skillIds[] = trim((string) $row[$key]);
                        break;
                    }
                }
            }
        }

        if ($skillIds === []) {
            foreach (preg_split('/\R/', $output) ?: [] as $line) {
                $line = trim($line);

                if ($line === '' || str_contains(strtolower($line), 'skill')) {
                    continue;
                }

                if (preg_match('/^[-*]?\s*([A-Za-z0-9][A-Za-z0-9_.-]*)\b/', $line, $matches) === 1) {
                    $skillIds[] = $matches[1];
                }
            }
        }

        $skillIds = array_values(array_unique(array_filter(
            $skillIds,
            static fn (string $skillId): bool => trim($skillId) !== '',
        )));
        sort($skillIds);

        return $skillIds;
    }

    /**
     * @param  mixed  $value
     * @return list<string>
     */
    private function stringList(mixed $value): array
    {
        $items = [];

        foreach (is_array($value) ? $value : [] as $item) {
            if (is_string($item) && trim($item) !== '' && ! in_array(trim($item), $items, true)) {
                $items[] = trim($item);
            }
        }

        return $items;
    }

    /**
     * @param  list<array<string, mixed>>  $assigned
     */
    private function hasAnalyticsSkill(array $assigned): bool
    {
        foreach ($assigned as $skill) {
            if (($skill['analytics_enabled'] ?? false) === true) {
                return true;
            }
        }

        return false;
    }

    private function usesLocalRuntimeDriver(): bool
    {
        return config('sync360.infrastructure.driver', 'local') === 'local';
    }

    private function runRemoteShell(Server $server, string $command): string
    {
        $process = new Process($this->sshCommandParts($server, $command));
        $process->run();

        if (! $process->isSuccessful()) {
            $output = trim($process->getErrorOutput()) ?: trim($process->getOutput());

            throw new RuntimeException($output !== '' ? $output : 'Remote tenant skill inspection command failed.');
        }

        return $process->getOutput();
    }

    private function runLocalShell(string $command): string
    {
        $process = new Process(['sh', '-lc', $command]);
        $process->run();

        if (! $process->isSuccessful()) {
            $output = trim($process->getErrorOutput()) ?: trim($process->getOutput());

            throw new RuntimeException($output !== '' ? $output : 'Local tenant skill inspection command failed.');
        }

        return $process->getOutput();
    }

    private function serverFor(Tenant $tenant): Server
    {
        if (! $tenant->server) {
            throw new RuntimeException('Tenant server is missing.');
        }

        return $tenant->server;
    }

    /**
     * @return list<string>
     */
    private function sshCommandParts(Server $server, string $command): array
    {
        $parts = [];
        $authMode = (string) ($server->ssh_auth_mode ?? 'key');

        if ($authMode === 'password') {
            $password = (string) env((string) $server->ssh_password_env_key, '');

            if ($password !== '') {
                $parts[] = (string) config('sync360.infrastructure.sshpass_bin', 'sshpass');
                $parts[] = '-p';
                $parts[] = $password;
            }
        }

        $parts[] = (string) config('sync360.infrastructure.ssh_bin', 'ssh');
        $parts[] = '-p';
        $parts[] = (string) ($server->ssh_port ?: 22);

        if ($authMode === 'key' && filled($server->ssh_private_key_path)) {
            $parts[] = '-i';
            $parts[] = (string) $server->ssh_private_key_path;
        }

        foreach ((array) config('sync360.infrastructure.ssh_options', []) as $option) {
            if (is_string($option) && trim($option) !== '') {
                $parts[] = '-o';
                $parts[] = trim($option);
            }
        }

        $parts[] = sprintf('%s@%s', $server->ssh_user, $server->ssh_host ?: $server->host);
        $parts[] = sprintf('sh -lc %s', $this->shellQuote($command));

        return $parts;
    }

    private function shellQuote(string $value): string
    {
        return "'".str_replace("'", "'\"'\"'", $value)."'";
    }
}
