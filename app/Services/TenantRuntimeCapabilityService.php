<?php

namespace App\Services;

use App\Contracts\DockerComposeRunner;
use App\Models\Server;
use App\Models\Tenant;
use App\Models\TenantAgentCustomization;
use Illuminate\Filesystem\Filesystem;
use RuntimeException;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Process;
use Throwable;

class TenantRuntimeCapabilityService
{
    public function __construct(
        private readonly Filesystem $files,
        private readonly TenantRuntimeService $runtime,
        private readonly DockerComposeRunner $dockerCompose,
        private readonly GogCommandCatalogService $gogCommands,
    ) {
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function definitions(): array
    {
        $definitions = config('sync360.runtime_capabilities', []);

        return is_array($definitions) ? $definitions : [];
    }

    /**
     * @return list<string>
     */
    public function capabilityIds(): array
    {
        return array_keys($this->definitions());
    }

    public function requiresSshInfrastructure(): void
    {
        if ((string) config('sync360.infrastructure.driver', 'local') === 'local') {
            throw new RuntimeException('Host-managed runtime capabilities are only supported when SYNC360_INFRASTRUCTURE_DRIVER=ssh.');
        }
    }

    /**
     * @param  array<int, string>|null  $capabilityIds
     * @return array<string, array<string, mixed>>
     */
    public function selectedDefinitions(?array $capabilityIds = null): array
    {
        $definitions = $this->definitions();

        if ($capabilityIds === null || $capabilityIds === []) {
            return $definitions;
        }

        $selected = [];

        foreach ($capabilityIds as $capabilityId) {
            if (! isset($definitions[$capabilityId])) {
                throw new RuntimeException(sprintf('Unknown runtime capability [%s].', $capabilityId));
            }

            $selected[$capabilityId] = $definitions[$capabilityId];
        }

        return $selected;
    }

    /**
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>
     */
    public function applyOpenClawSkills(array $config, ?array $capabilityIds = null): array
    {
        $config['skills'] = is_array($config['skills'] ?? null) ? $config['skills'] : [];
        $config['skills']['entries'] = is_array($config['skills']['entries'] ?? null) ? $config['skills']['entries'] : [];
        $config['agents'] = is_array($config['agents'] ?? null) ? $config['agents'] : [];
        $config['agents']['defaults'] = is_array($config['agents']['defaults'] ?? null) ? $config['agents']['defaults'] : [];
        $config['agents']['defaults']['skills'] = $this->appendSkills(
            $config['agents']['defaults']['skills'] ?? [],
            $this->openClawSkills($capabilityIds),
        );

        foreach ($this->openClawSkills($capabilityIds) as $skill) {
            $entry = $config['skills']['entries'][$skill] ?? [];

            if (! is_array($entry)) {
                $entry = [];
            }

            $config['skills']['entries'][$skill] = array_merge($entry, [
                'enabled' => true,
            ]);
        }

        if (is_array($config['agents']['list'] ?? null)) {
            $skills = $this->openClawSkills($capabilityIds);

            $config['agents']['list'] = array_map(function (mixed $agent) use ($skills): mixed {
                if (! is_array($agent)) {
                    return $agent;
                }

                $agent['skills'] = $this->appendSkills($agent['skills'] ?? [], $skills);

                return $agent;
            }, $config['agents']['list']);
        }

        return $config;
    }

    /**
     * @param  array<string, mixed>  $config
     */
    public function renderOpenClawConfig(array $config, ?array $capabilityIds = null): string
    {
        return json_encode(
            $this->applyOpenClawSkills($config, $capabilityIds),
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
        ).PHP_EOL;
    }

    /**
     * @return array{changed:bool, contents:string, remote_config_file:string}
     */
    public function syncLocalOpenClawConfig(Tenant $tenant, ?array $capabilityIds = null): array
    {
        $localConfigPath = $this->runtime->localOpenClawConfigPath($tenant);

        if (! $this->files->exists($localConfigPath)) {
            throw new RuntimeException('The tenant OpenClaw config file does not exist yet, so runtime capabilities cannot be applied.');
        }

        $existingContents = $this->files->get($localConfigPath);
        $existingConfig = json_decode($existingContents, true) ?: [];
        $updatedContents = $this->renderOpenClawConfig($existingConfig, $capabilityIds);
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
     * @return array{changed:bool, contents:string, remote_compose_file:string}
     */
    public function syncLocalCompose(Tenant $tenant, ?array $capabilityIds = null): array
    {
        $localComposePath = $this->runtime->localComposePath($tenant);

        if (! $this->files->exists($localComposePath)) {
            throw new RuntimeException('The tenant compose file does not exist yet, so runtime capabilities cannot be applied.');
        }

        $runtimeEnvironment = $this->parseRuntimeEnvFile($tenant);
        $openClawConfig = $this->parseLocalOpenClawConfig($tenant);
        $gatewayToken = (string) ($runtimeEnvironment['OPENCLAW_GATEWAY_TOKEN'] ?? data_get($openClawConfig, 'gateway.auth.token', ''));
        $credentials = $this->resolveRuntimeCredentials($tenant, runtimeEnvironment: $runtimeEnvironment);

        $updatedContents = $this->renderCompose(
            $tenant,
            $tenant->runtime_path ?: $this->runtime->remoteRuntimePath($tenant),
            (int) $tenant->assigned_port,
            $gatewayToken,
            $credentials['api_key'],
            $credentials['base_url'],
            $capabilityIds,
        );
        $existingContents = $this->files->get($localComposePath);
        $changed = $updatedContents !== $existingContents;

        if ($changed) {
            $this->files->put($localComposePath, $updatedContents);
        }

        return [
            'changed' => $changed,
            'contents' => $updatedContents,
            'remote_compose_file' => $this->runtime->remoteComposePath($tenant),
        ];
    }

    /**
     * @return array{changed:bool, contents:string, remote_env_file:string}
     */
    public function syncLocalRuntimeEnvCredentials(Tenant $tenant, ?TenantAgentCustomization $customization = null): array
    {
        $envPath = $this->runtime->localEnvPath($tenant);

        if (! $this->files->exists($envPath)) {
            throw new RuntimeException('The tenant runtime env file does not exist yet, so runtime credentials cannot be applied.');
        }

        $runtimeEnvironment = $this->parseRuntimeEnvFile($tenant);
        $credentials = $this->resolveRuntimeCredentials($tenant, $customization, $runtimeEnvironment);
        $runtimeEnvironment['OPENAI_API_KEY'] = $credentials['api_key'];
        $runtimeEnvironment['OPENAI_BASE_URL'] = $credentials['base_url'];

        $updatedContents = $this->renderEnvFile($runtimeEnvironment);
        $existingContents = $this->files->get($envPath);
        $changed = $updatedContents !== $existingContents;

        if ($changed) {
            $this->files->put($envPath, $updatedContents);
        }

        return [
            'changed' => $changed,
            'contents' => $updatedContents,
            'remote_env_file' => $this->runtime->remoteEnvPath($tenant),
        ];
    }

    public function renderCompose(
        Tenant $tenant,
        string $remoteRuntimePath,
        int $assignedPort,
        string $gatewayToken,
        string $liteLlmKey,
        string $liteLlmBaseUrl,
        ?array $capabilityIds = null,
    ): string {
        if ($assignedPort <= 0) {
            throw new RuntimeException('The tenant assigned port is required before rendering the runtime compose file.');
        }

        if ($gatewayToken === '' || $liteLlmKey === '' || $liteLlmBaseUrl === '') {
            throw new RuntimeException('Runtime compose generation requires the provisioned gateway token and LiteLLM credentials.');
        }

        $containerHome = rtrim((string) config('sync360.openclaw.container_home', '/home/node/.openclaw'), '/');
        $gatewayPort = (int) config('sync360.openclaw.gateway_port', 18789);
        $serviceName = (string) config('sync360.openclaw.service_name', 'openclaw-gateway');
        $image = (string) config('sync360.openclaw.image');
        $configPath = $containerHome.'/config/openclaw.json';
        $stateDir = $containerHome.'/data';
        $command = sprintf('openclaw gateway --allow-unconfigured --port=%d', $gatewayPort);

        $volumeLines = [
            '      - type: bind',
            sprintf('        source: %s', $this->yamlQuote($remoteRuntimePath)),
            sprintf('        target: %s', $this->yamlQuote($containerHome)),
        ];

        foreach ($this->selectedDefinitions($capabilityIds) as $definition) {
            foreach (($definition['container_mounts'] ?? []) as $mount) {
                if (! is_array($mount)) {
                    continue;
                }

                $source = (string) ($mount['source'] ?? '');
                $target = (string) ($mount['target'] ?? '');

                if ($source === '' || $target === '') {
                    continue;
                }

                $volumeLines[] = '      - type: bind';
                $volumeLines[] = sprintf('        source: %s', $this->yamlQuote($source));
                $volumeLines[] = sprintf('        target: %s', $this->yamlQuote($target));

                if (($mount['read_only'] ?? false) === true) {
                    $volumeLines[] = '        read_only: true';
                }
            }
        }

        $environmentEntries = array_merge([
            'OPENCLAW_HOME' => $this->yamlQuote($containerHome),
            'OPENCLAW_STATE_DIR' => $this->yamlQuote($stateDir),
            'OPENCLAW_CONFIG_PATH' => $this->yamlQuote($configPath),
            'OPENCLAW_GATEWAY_TOKEN' => $this->yamlQuote($gatewayToken),
            'OPENAI_API_KEY' => $this->yamlQuote($liteLlmKey),
            'OPENAI_BASE_URL' => $this->yamlQuote($liteLlmBaseUrl),
            'XDG_CONFIG_HOME' => $this->yamlQuote($this->runtime->containerGogConfigHome()),
            'GOG_KEYRING_BACKEND' => $this->yamlQuote('file'),
            'GOG_KEYRING_PASSWORD' => $this->yamlQuote($this->runtime->googleKeyringPassword($tenant)),
        ], $this->capabilityEnvironmentEntries($tenant, $capabilityIds));

        $environmentLines = array_map(
            static fn (string $key, string $value): string => sprintf('      %s: %s', $key, $value),
            array_keys($environmentEntries),
            array_values($environmentEntries),
        );

        return implode(PHP_EOL, [
            'services:',
            sprintf('  %s:', $serviceName),
            sprintf('    image: %s', $image),
            '    restart: unless-stopped',
            sprintf('    container_name: %s', $this->runtime->containerName($tenant)),
            '    command:',
            '      - /bin/sh',
            '      - -lc',
            sprintf('      - %s', $this->yamlQuote($command)),
            '    ports:',
            sprintf('      - "127.0.0.1:%d:%d"', $assignedPort, $gatewayPort),
            '    volumes:',
            ...$volumeLines,
            '    environment:',
            ...$environmentLines,
            '',
        ]);
    }

    /**
     * @param  array<int, string>|null  $capabilityIds
     * @return array<string, string>
     */
    public function ensureInstalledOnServer(Server $server, ?array $capabilityIds = null): array
    {
        $this->requiresSshInfrastructure();

        $results = [];

        foreach ($this->selectedDefinitions($capabilityIds) as $definition) {
            $capabilityId = (string) $definition['id'];

            if ($this->hostHasPinnedVersion($server, $definition)) {
                $results[$capabilityId] = 'already-installed';
                continue;
            }

            $this->ensureHostInstallPrerequisites($server, $definition);
            $this->dockerCompose->runCommand($server, $this->installCommand($definition), sudo: true);
            $this->verifyHostDefinition($server, $definition);
            $results[$capabilityId] = 'installed';
        }

        return $results;
    }

    /**
     * @param  array<int, string>|null  $capabilityIds
     */
    public function verifyHostCapabilities(Server $server, ?array $capabilityIds = null): void
    {
        $this->requiresSshInfrastructure();

        foreach ($this->selectedDefinitions($capabilityIds) as $definition) {
            $this->verifyHostDefinition($server, $definition);
        }
    }

    /**
     * @param  array<int, string>|null  $capabilityIds
     */
    public function verifyContainerCapabilities(Tenant $tenant, ?array $capabilityIds = null): void
    {
        $this->requiresSshInfrastructure();
        $tenant->loadMissing('server');

        if (! $tenant->server) {
            throw new RuntimeException('Tenant server is missing.');
        }

        foreach ($this->selectedDefinitions($capabilityIds) as $definition) {
            $command = $this->containerVerificationCommand($tenant, $definition);

            $this->dockerCompose->runCommand($tenant->server, $command);
        }
    }

    public function reloadRuntime(Tenant $tenant, bool $recreate = false): void
    {
        $composeFile = app()->environment('local')
            ? $this->runtime->localComposePath($tenant)
            : $this->runtime->remoteComposePath($tenant);
        $projectName = $this->runtime->projectName($tenant);

        if (app()->environment('local')) {
            $this->runLocalComposeCommand(
                $composeFile,
                $projectName,
                $recreate ? ['up', '-d', '--force-recreate'] : ['restart'],
            );

            return;
        }

        if (! $tenant->server) {
            throw new RuntimeException('Tenant server is missing.');
        }

        $this->dockerCompose->runCommand(
            $tenant->server,
            $recreate
                ? sprintf('docker compose -f %s -p %s up -d --force-recreate', escapeshellarg($composeFile), escapeshellarg($projectName))
                : sprintf('docker compose -f %s -p %s restart', escapeshellarg($composeFile), escapeshellarg($projectName)),
        );
    }

    /**
     * @return array{api_key:string, base_url:string}
     */
    public function resolveRuntimeCredentials(Tenant $tenant, ?TenantAgentCustomization $customization = null, ?array $runtimeEnvironment = null, ?string $fallbackApiKey = null): array
    {
        $tenant->loadMissing('agentCustomization');
        $customization ??= $tenant->agentCustomization;

        $override = is_string($customization?->runtime_api_key_override) ? trim((string) $customization->runtime_api_key_override) : '';
        $apiKey = $override !== '' ? $override : trim((string) ($fallbackApiKey ?: $tenant->litellm_virtual_key));
        $baseUrl = rtrim((string) config('services.litellm.base_url', ''), '/')
            ?: trim((string) (($runtimeEnvironment ?? [])['OPENAI_BASE_URL'] ?? ''));

        if ($apiKey === '') {
            throw new RuntimeException('The tenant runtime API key is missing, so runtime credentials cannot be regenerated safely.');
        }

        if ($baseUrl === '') {
            throw new RuntimeException('The runtime base URL is missing, so runtime credentials cannot be regenerated safely.');
        }

        return [
            'api_key' => $apiKey,
            'base_url' => $baseUrl,
        ];
    }

    /**
     * @param  array<int, string>|null  $capabilityIds
     * @return list<string>
     */
    public function openClawSkills(?array $capabilityIds = null): array
    {
        $skills = [];

        foreach ($this->selectedDefinitions($capabilityIds) as $definition) {
            foreach (($definition['openclaw_skills'] ?? []) as $skill) {
                if (is_string($skill) && trim($skill) !== '' && ! in_array($skill, $skills, true)) {
                    $skills[] = trim($skill);
                }
            }
        }

        return $skills;
    }

    /**
     * @param  array<int, string>|null  $capabilityIds
     * @return array<string, string>
     */
    private function capabilityEnvironmentEntries(Tenant $tenant, ?array $capabilityIds = null): array
    {
        $entries = [];
        $selectedDefinitions = $this->selectedDefinitions($capabilityIds);

        foreach ($selectedDefinitions as $definition) {
            foreach (($definition['env'] ?? []) as $key => $value) {
                if (! is_string($key) || trim($key) === '' || ! is_string($value)) {
                    continue;
                }

                $entries[$key] = $this->yamlQuote($value);
            }
        }

        if (isset($selectedDefinitions['gog'])) {
            foreach ($this->gogCommands->runtimeEnvironmentFor($tenant) as $key => $value) {
                $entries[$key] = $this->yamlQuote($value);
            }
        }

        return $entries;
    }

    /**
     * @param  mixed  $skills
     * @param  list<string>  $requiredSkills
     * @return array<int, string>
     */
    private function appendSkills(mixed $skills, array $requiredSkills): array
    {
        $skillList = array_values(array_filter(
            array_map(
                static fn (mixed $value): ?string => is_string($value) && trim($value) !== '' ? trim($value) : null,
                is_array($skills) ? $skills : [],
            )
        ));

        foreach ($requiredSkills as $skill) {
            if (! in_array($skill, $skillList, true)) {
                $skillList[] = $skill;
            }
        }

        return $skillList;
    }

    /**
     * @return array<string, string>
     */
    private function parseRuntimeEnvFile(Tenant $tenant): array
    {
        $envPath = $this->runtime->localEnvPath($tenant);

        if (! $this->files->exists($envPath)) {
            return [];
        }

        $values = [];

        foreach (preg_split("/\r?\n/", $this->files->get($envPath)) ?: [] as $line) {
            $trimmed = trim($line);

            if ($trimmed === '' || str_starts_with($trimmed, '#') || ! str_contains($line, '=')) {
                continue;
            }

            [$key, $value] = explode('=', $line, 2);
            $key = trim($key);
            $value = trim($value);

            if ($key === '') {
                continue;
            }

            if (str_starts_with($value, '"') && str_ends_with($value, '"')) {
                $value = substr($value, 1, -1);
                $value = str_replace(['\n', '\"', '\\\\'], ["\n", '"', '\\'], $value);
            }

            $values[$key] = $value;
        }

        return $values;
    }

    /**
     * @param  array<string, string>  $values
     */
    private function renderEnvFile(array $values): string
    {
        $lines = [];

        foreach ($values as $key => $value) {
            $escapedValue = str_replace(['\\', '"', "\n"], ['\\\\', '\"', '\n'], (string) $value);
            $shouldQuote = preg_match('/\s|=/', $escapedValue) === 1;

            $lines[] = sprintf('%s=%s', $key, $shouldQuote ? '"'.$escapedValue.'"' : $escapedValue);
        }

        return implode(PHP_EOL, $lines).PHP_EOL;
    }

    /**
     * @return array<string, mixed>
     */
    private function parseLocalOpenClawConfig(Tenant $tenant): array
    {
        $configPath = $this->runtime->localOpenClawConfigPath($tenant);

        if (! $this->files->exists($configPath)) {
            return [];
        }

        $decoded = json_decode($this->files->get($configPath), true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @param  array<string, mixed>  $definition
     */
    private function ensureHostInstallPrerequisites(Server $server, array $definition): void
    {
        if (($definition['install_strategy'] ?? null) !== 'binary_download') {
            throw new RuntimeException(sprintf('Unsupported install strategy [%s].', (string) ($definition['install_strategy'] ?? 'unknown')));
        }

        $this->dockerCompose->runCommand(
            $server,
            'command -v curl >/dev/null 2>&1 && command -v tar >/dev/null 2>&1 || (apt-get update && apt-get install -y curl tar)',
            sudo: true,
        );
    }

    /**
     * @param  array<string, mixed>  $definition
     */
    private function installCommand(array $definition): string
    {
        $downloadUrl = (string) ($definition['download_url'] ?? '');
        $sha256 = (string) ($definition['sha256'] ?? '');
        $archiveBinaryPath = (string) ($definition['archive_binary_path'] ?? '');
        $installPath = (string) ($definition['host_install_path'] ?? '');

        if ($downloadUrl === '' || $sha256 === '' || $archiveBinaryPath === '' || $installPath === '') {
            throw new RuntimeException('Runtime capability install metadata is incomplete.');
        }

        return sprintf(
            'set -eu; tmpdir=$(mktemp -d); trap %s EXIT; archive="$tmpdir/capability.tar.gz"; curl -fsSL %s -o "$archive"; printf "%%s  %%s\n" %s "$archive" | sha256sum -c - >/dev/null; tar -xzf "$archive" -C "$tmpdir"; install -m 0755 "$tmpdir"/%s %s',
            escapeshellarg('rm -rf "$tmpdir"'),
            escapeshellarg($downloadUrl),
            escapeshellarg($sha256),
            escapeshellarg($archiveBinaryPath),
            escapeshellarg($installPath),
        );
    }

    /**
     * @param  array<string, mixed>  $definition
     */
    private function hostHasPinnedVersion(Server $server, array $definition): bool
    {
        try {
            $this->dockerCompose->runCommand($server, $this->versionCheckCommand($definition), sudo: true);

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * @param  array<string, mixed>  $definition
     */
    private function verifyHostDefinition(Server $server, array $definition): void
    {
        foreach (($definition['host_verify_commands'] ?? []) as $command) {
            if (! is_string($command) || trim($command) === '') {
                continue;
            }

            $this->dockerCompose->runCommand(
                $server,
                $this->interpolateCommand($command, $definition),
                sudo: true,
            );
        }
    }

    /**
     * @param  array<string, mixed>  $definition
     */
    private function containerVerificationCommand(Tenant $tenant, array $definition): string
    {
        $containerCommand = trim((string) ($definition['container_verify_command'] ?? ''));

        if ($containerCommand === '') {
            throw new RuntimeException(sprintf(
                'Runtime capability [%s] does not define a container verification command.',
                (string) ($definition['id'] ?? 'unknown'),
            ));
        }

        return sprintf(
            'docker exec %s sh -c %s',
            escapeshellarg($this->runtime->containerName($tenant)),
            escapeshellarg($containerCommand),
        );
    }

    /**
     * @param  array<string, mixed>  $definition
     */
    private function versionCheckCommand(array $definition): string
    {
        return sprintf(
            '%s >/dev/null 2>&1 && %s',
            $this->interpolateCommand('test -x {{path}}', $definition),
            $this->interpolateCommand('{{path}} --version 2>&1 | grep -qF {{version}}', $definition),
        );
    }

    /**
     * @param  array<string, mixed>  $definition
     */
    private function interpolateCommand(string $command, array $definition): string
    {
        return str_replace(
            ['{{path}}', '{{version}}'],
            [
                escapeshellarg((string) ($definition['host_install_path'] ?? '')),
                escapeshellarg((string) ($definition['version'] ?? '')),
            ],
            $command,
        );
    }

    /**
     * @param  list<string>  $subCommand
     */
    private function runLocalComposeCommand(string $composeFile, string $projectName, array $subCommand): void
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

    private function yamlQuote(string $value): string
    {
        return '"'.str_replace(['\\', '"'], ['\\\\', '\"'], $value).'"';
    }
}
