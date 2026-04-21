<?php

namespace App\Services;

use App\Contracts\DockerComposeRunner;
use App\Models\Tenant;
use RuntimeException;
use Symfony\Component\Process\Process;

class TenantSkillAnalyticsRuntimeService
{
    public function __construct(
        private readonly DockerComposeRunner $dockerCompose,
        private readonly TenantRuntimeService $runtime,
        private readonly TenantSkillRegistryService $skillRegistry,
    ) {
    }

    /**
     * @return array{initialized:int, skipped:int, tenants:int}
     */
    public function initialize(?Tenant $selectedTenant = null): array
    {
        $tenants = $selectedTenant
            ? collect([$selectedTenant->fresh(['server', 'skillAssignments.catalogVersion'])])
            : Tenant::query()
                ->with(['server', 'skillAssignments.catalogVersion'])
                ->whereNotNull('runtime_path')
                ->orderBy('id')
                ->get();

        $totals = [
            'initialized' => 0,
            'skipped' => 0,
            'tenants' => $tenants->count(),
        ];

        foreach ($tenants as $tenant) {
            $result = $this->initializeTenant($tenant);

            if ($result['initialized']) {
                $totals['initialized']++;
            } else {
                $totals['skipped']++;
            }
        }

        return $totals;
    }

    /**
     * @return array{initialized:bool, skill_count:int}
     */
    public function initializeTenant(Tenant $tenant): array
    {
        $tenant->loadMissing(['server', 'skillAssignments.catalogVersion']);

        $skillCount = count($this->analyticsRegistryEntries($tenant));

        if ($skillCount === 0) {
            return [
                'initialized' => false,
                'skill_count' => 0,
            ];
        }

        if ($this->usesLocalRuntimeDriver()) {
            $this->initializeLocalTenant($tenant);
        } else {
            $this->initializeRemoteTenant($tenant);
        }

        return [
            'initialized' => true,
            'skill_count' => $skillCount,
        ];
    }

    public function tenantHasAnalyticsSkills(Tenant $tenant): bool
    {
        $tenant->loadMissing('skillAssignments.catalogVersion');

        return $this->analyticsRegistryEntries($tenant) !== [];
    }

    public function runtimeDatabaseExists(Tenant $tenant): bool
    {
        return $this->usesLocalRuntimeDriver()
            ? is_file($this->runtime->localSkillAnalyticsDbPath($tenant))
            : $this->remoteRuntimeDatabaseExists($tenant);
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function analyticsRegistryEntries(Tenant $tenant): array
    {
        return $this->skillRegistry->analyticsRegistryEntries($tenant->skillAssignments);
    }

    private function initializeLocalTenant(Tenant $tenant): void
    {
        $workspacePath = $this->runtime->localWorkspacePath($tenant);

        $process = new Process([
            'sh',
            '.sync360/bin/log-skill-conversion',
            '--init-only',
        ], $workspacePath);
        $process->run();

        if (! $process->isSuccessful()) {
            throw new RuntimeException($this->processFailureMessage($process, 'Local skill analytics initialization failed.'));
        }
    }

    private function initializeRemoteTenant(Tenant $tenant): void
    {
        if (! $tenant->server) {
            throw new RuntimeException('Tenant server is missing.');
        }

        $containerWorkspacePath = rtrim((string) config('sync360.openclaw.container_home', '/home/node/.openclaw'), '/')
            .'/'.'.openclaw/workspace';
        $innerCommand = sprintf(
            'cd %s && sh .sync360/bin/log-skill-conversion --init-only',
            escapeshellarg($containerWorkspacePath),
        );

        $this->dockerCompose->runCommand(
            $tenant->server,
            sprintf(
                'docker exec %s sh -lc %s',
                escapeshellarg($this->runtime->containerName($tenant)),
                escapeshellarg($innerCommand),
            ),
        );
    }

    private function remoteRuntimeDatabaseExists(Tenant $tenant): bool
    {
        if (! $tenant->server) {
            return false;
        }

        $dbPath = $this->runtime->remoteSkillAnalyticsDbPath($tenant);
        $command = sprintf('if [ -f %s ]; then printf 1; else printf 0; fi', escapeshellarg($dbPath));

        $process = new Process($this->sshCommandParts($tenant, $command));
        $process->run();

        if (! $process->isSuccessful()) {
            throw new RuntimeException($this->processFailureMessage($process, 'Remote skill analytics database check failed.'));
        }

        return trim($process->getOutput()) === '1';
    }

    private function usesLocalRuntimeDriver(): bool
    {
        return config('sync360.infrastructure.driver', 'local') === 'local';
    }

    /**
     * @return list<string>
     */
    private function sshCommandParts(Tenant $tenant, string $command): array
    {
        $server = $tenant->server;

        if (! $server) {
            throw new RuntimeException('Tenant server is missing.');
        }

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

        if ($authMode === 'key' && $server->ssh_private_key_path) {
            $parts[] = '-i';
            $parts[] = $server->ssh_private_key_path;
        }

        $parts[] = '-o';
        $parts[] = 'StrictHostKeyChecking=no';
        $parts[] = '-o';
        $parts[] = 'UserKnownHostsFile=/dev/null';
        $parts[] = $server->ssh_user.'@'.($server->ssh_host ?: $server->host);
        $parts[] = sprintf('sh -lc %s', escapeshellarg($command));

        return $parts;
    }

    private function processFailureMessage(Process $process, string $fallback): string
    {
        $output = trim($process->getErrorOutput()) ?: trim($process->getOutput());

        return $output !== '' ? $output : $fallback;
    }
}
