<?php

namespace App\Services;

use App\Contracts\DockerComposeRunner;
use App\Contracts\TenantProvisioner;
use App\Enums\ProvisioningJobStatus;
use App\Enums\TenantProvisioningStatus;
use App\Models\ProvisioningJob;
use App\Models\Tenant;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class OpenClawProvisioner implements TenantProvisioner
{
    public function __construct(
        private readonly TenantRuntimeService $runtime,
        private readonly DockerComposeRunner $dockerCompose,
        private readonly Filesystem $files,
        private readonly LiteLlmTenantKeyService $liteLlmKeys,
    ) {
    }

    public function provision(Tenant $tenant, ProvisioningJob $provisioningJob): void
    {
        $tenant->loadMissing('server');

        if (! $tenant->server) {
            throw new RuntimeException('Tenant does not have an assigned client VPS.');
        }

        $tenant->forceFill([
            'provisioning_status' => TenantProvisioningStatus::Provisioning,
        ])->save();

        $provisioningJob->forceFill([
            'status' => ProvisioningJobStatus::Running,
            'started_at' => now(),
            'completed_at' => null,
            'error_message' => null,
        ])->save();

        $assignedPort = $tenant->assigned_port ?? $this->runtime->allocatePort($tenant);
        $workspaceUrl = $this->runtime->workspaceUrl($tenant, $assignedPort);
        $gatewayToken = Str::random(40);
        $liteLlmKey = $this->liteLlmKeys->ensureTenantKey($tenant);

        $localRuntimePath = $this->runtime->prepareRuntime($tenant, $provisioningJob, $assignedPort, [
            'PROVISIONING_DRIVER' => 'openclaw',
            'OPENCLAW_GATEWAY_PORT' => (string) config('sync360.openclaw.gateway_port', 18789),
            'OPENCLAW_GATEWAY_TOKEN' => $gatewayToken,
            'OPENAI_API_KEY' => $liteLlmKey['key'],
            'OPENAI_BASE_URL' => $liteLlmKey['base_url'],
        ], [
            'provisioning_driver' => 'openclaw',
            'openclaw' => [
                'image' => config('sync360.openclaw.image'),
                'gateway_port' => (int) config('sync360.openclaw.gateway_port', 18789),
                'readiness_path' => config('sync360.openclaw.readiness_path'),
            ],
            'litellm' => [
                'key_alias' => $liteLlmKey['alias'],
                'plan_name' => $liteLlmKey['plan_name'],
                'max_budget' => $liteLlmKey['max_budget'],
                'budget_duration' => $liteLlmKey['budget_duration'],
                'base_url' => $liteLlmKey['base_url'],
            ],
        ]);
        $remoteRuntimePath = $this->runtime->remoteRuntimePath($tenant);

        $composeFile = $remoteRuntimePath.DIRECTORY_SEPARATOR.(string) config('sync360.openclaw.compose_filename', 'compose.yaml');
        $projectName = $this->projectName($tenant);

        $this->writeOpenClawConfig($localRuntimePath, $tenant, $assignedPort, $gatewayToken);
        $this->writeComposeFile($tenant, $localRuntimePath, $remoteRuntimePath, $assignedPort, $gatewayToken, $liteLlmKey['key'], $liteLlmKey['base_url']);
        $caddyConfig = $this->shouldManageCaddy($tenant)
            ? $this->writeCaddyConfig($tenant, $localRuntimePath, $assignedPort)
            : null;

        $tenant->forceFill([
            'assigned_port' => $assignedPort,
            'runtime_path' => $remoteRuntimePath,
            'workspace_url' => $workspaceUrl,
            'provisioning_status' => TenantProvisioningStatus::Provisioning,
        ])->save();

        try {
            $this->cleanupFailedRuntime($tenant, $composeFile, $projectName, reloadProxy: false);
            $this->dockerCompose->syncRuntime($tenant->server, $localRuntimePath, $remoteRuntimePath);
            $this->installCaddyConfig($tenant, $caddyConfig);
            $this->dockerCompose->up($tenant->server, $composeFile, $projectName);
            $this->waitForReadiness($tenant, $assignedPort);
            $this->waitForPublicWorkspace($tenant);
        } catch (Throwable $exception) {
            $this->cleanupFailedRuntime($tenant, $composeFile, $projectName, reloadProxy: true);

            throw $exception;
        }

        $tenant->forceFill([
            'assigned_port' => $assignedPort,
            'runtime_path' => $remoteRuntimePath,
            'workspace_url' => $workspaceUrl,
            'provisioning_status' => TenantProvisioningStatus::Ready,
        ])->save();

        $provisioningJob->forceFill([
            'status' => ProvisioningJobStatus::Completed,
            'completed_at' => now(),
            'error_message' => null,
        ])->save();
    }

    private function writeOpenClawConfig(string $runtimePath, Tenant $tenant, int $assignedPort, string $gatewayToken): void
    {
        $configPath = $runtimePath.DIRECTORY_SEPARATOR.'config'.DIRECTORY_SEPARATOR.'openclaw.json';
        $this->files->ensureDirectoryExists(dirname($configPath));

        $this->files->put(
            $configPath,
            json_encode([
                'gateway' => [
                    'mode' => 'local',
                    'bind' => 'lan',
                    'auth' => [
                        'mode' => 'token',
                        'token' => $gatewayToken,
                    ],
                    'controlUi' => [
                        'enabled' => false,
                    ],
                ],
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL,
        );
    }

    private function writeCaddyConfig(Tenant $tenant, string $runtimePath, int $assignedPort): string
    {
        $host = $this->runtime->workspaceHost($tenant);

        if (! $host) {
            throw new RuntimeException('A tenant hostname is required before generating a Caddy site.');
        }

        $caddyConfig = implode(PHP_EOL, [
            sprintf('%s {', $host),
            sprintf('    reverse_proxy %s', $this->runtime->controlAppUpstream()),
            '}',
            '',
        ]);

        $configPath = $runtimePath.DIRECTORY_SEPARATOR.'config'.DIRECTORY_SEPARATOR.'workspace.caddy';
        $this->files->ensureDirectoryExists(dirname($configPath));
        $this->files->put($configPath, $caddyConfig);

        return $caddyConfig;
    }

    private function writeComposeFile(
        Tenant $tenant,
        string $localRuntimePath,
        string $remoteRuntimePath,
        int $assignedPort,
        string $gatewayToken,
        string $liteLlmKey,
        string $liteLlmBaseUrl,
    ): void
    {
        $composeFile = $localRuntimePath.DIRECTORY_SEPARATOR.(string) config('sync360.openclaw.compose_filename', 'compose.yaml');
        $this->files->ensureDirectoryExists(dirname($composeFile));
        $containerHome = rtrim((string) config('sync360.openclaw.container_home', '/home/node/.openclaw'), '/');
        $gatewayPort = (int) config('sync360.openclaw.gateway_port', 18789);
        $serviceName = (string) config('sync360.openclaw.service_name', 'openclaw-gateway');
        $image = (string) config('sync360.openclaw.image');
        $configPath = $containerHome.'/config/openclaw.json';
        $stateDir = $containerHome.'/data';
        $command = sprintf(
            'openclaw gateway --allow-unconfigured --port=%d',
            $gatewayPort,
        );

        $compose = implode(PHP_EOL, [
            'services:',
            sprintf('  %s:', $serviceName),
            sprintf('    image: %s', $image),
            '    restart: unless-stopped',
            sprintf("    container_name: sync360-%s", $tenant->slug),
            '    command:',
            sprintf("      - /bin/sh"),
            sprintf("      - -lc"),
            sprintf("      - %s", $this->yamlQuote($command)),
            '    ports:',
            sprintf('      - "127.0.0.1:%d:%d"', $assignedPort, $gatewayPort),
            '    volumes:',
            '      - type: bind',
            sprintf('        source: %s', $this->yamlQuote($remoteRuntimePath)),
            sprintf('        target: %s', $this->yamlQuote($containerHome)),
            '    environment:',
            sprintf('      OPENCLAW_HOME: %s', $this->yamlQuote($containerHome)),
            sprintf('      OPENCLAW_STATE_DIR: %s', $this->yamlQuote($stateDir)),
            sprintf('      OPENCLAW_CONFIG_PATH: %s', $this->yamlQuote($configPath)),
            sprintf('      OPENCLAW_GATEWAY_TOKEN: %s', $this->yamlQuote($gatewayToken)),
            sprintf('      OPENAI_API_KEY: %s', $this->yamlQuote($liteLlmKey)),
            sprintf('      OPENAI_BASE_URL: %s', $this->yamlQuote($liteLlmBaseUrl)),
            sprintf('      OPENCLAW_MODEL: %s', $this->yamlQuote((string) config('sync360.openclaw.default_agent_model', 'gpt-4o'))),
            '',
        ]);

        $this->files->put($composeFile, $compose);
    }

    private function waitForReadiness(Tenant $tenant, int $assignedPort): void
    {
        $readinessPath = '/'.ltrim((string) config('sync360.openclaw.readiness_path', '/readyz'), '/');
        $readinessUrl = sprintf('http://127.0.0.1:%d%s', $assignedPort, $readinessPath);
        $timeoutSeconds = max(1, (int) config('sync360.openclaw.readiness_timeout_seconds', 45));
        $pollIntervalMs = max(100, (int) config('sync360.openclaw.readiness_poll_interval_ms', 1000));
        $this->dockerCompose->waitForHttpReady($tenant->server, $readinessUrl, $timeoutSeconds, $pollIntervalMs);
    }

    private function waitForPublicWorkspace(Tenant $tenant): void
    {
        $publicReadinessUrl = rtrim((string) $tenant->workspace_url, '/').'/login';
        $deadline = microtime(true) + max(1, (int) config('sync360.workspace_proxy.public_readiness_timeout_seconds', 120));
        $pollIntervalMs = max(100, (int) config('sync360.workspace_proxy.public_readiness_poll_interval_ms', 1500));
        $lastError = null;

        do {
            try {
                $response = Http::timeout(5)->get($publicReadinessUrl);

                if ($response->successful()) {
                    return;
                }

                $lastError = sprintf('HTTP %d from %s', $response->status(), $publicReadinessUrl);
            } catch (Throwable $exception) {
                $lastError = $exception->getMessage();
            }

            usleep($pollIntervalMs * 1000);
        } while (microtime(true) < $deadline);

        throw new RuntimeException(sprintf(
            'Public workspace readiness check failed for [%s]. %s',
            $publicReadinessUrl,
            $lastError ? 'Last error: '.$lastError : 'The workspace never became reachable over HTTPS.',
        ));
    }

    private function installCaddyConfig(Tenant $tenant, ?string $caddyConfig): void
    {
        if (! $caddyConfig || ! $this->shouldManageCaddy($tenant)) {
            return;
        }

        $this->dockerCompose->putFile(
            $tenant->server,
            $this->runtime->caddySitePath($tenant),
            $caddyConfig,
            sudo: true,
        );

        $this->reloadCaddy($tenant);
    }

    private function reloadCaddy(Tenant $tenant): void
    {
        $reloadCommand = trim((string) ($tenant->server?->caddy_reload_command ?: ''));

        if ($reloadCommand === '') {
            throw new RuntimeException('Tenant server Caddy reload command is not configured.');
        }

        $this->dockerCompose->runCommand($tenant->server, $reloadCommand, sudo: true);
    }

    private function cleanupFailedRuntime(Tenant $tenant, string $composeFile, string $projectName, bool $reloadProxy): void
    {
        try {
            $this->dockerCompose->down($tenant->server, $composeFile, $projectName);
        } catch (Throwable) {
            // A failed cleanup should not hide the original provisioning error.
        }

        if (! $this->shouldManageCaddy($tenant)) {
            return;
        }

        try {
            $this->dockerCompose->removeFile($tenant->server, $this->runtime->caddySitePath($tenant), sudo: true);

            if ($reloadProxy) {
                $this->reloadCaddy($tenant);
            }
        } catch (Throwable) {
            // Best-effort reverse proxy cleanup.
        }
    }

    private function shouldManageCaddy(Tenant $tenant): bool
    {
        return (bool) ($tenant->server?->workspace_base_domain && $tenant->server?->caddy_sites_path && $tenant->server?->caddy_reload_command);
    }

    private function projectName(Tenant $tenant): string
    {
        return Str::limit('sync360-'.$tenant->slug, 63, '');
    }

    private function yamlQuote(string $value): string
    {
        return '"'.str_replace(['\\', '"'], ['\\\\', '\"'], $value).'"';
    }
}
