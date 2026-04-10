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
    ) {
    }

    public function provision(Tenant $tenant, ProvisioningJob $provisioningJob): void
    {
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
        $workspaceUrl = $this->runtime->workspaceUrl($assignedPort);
        $gatewayToken = Str::random(40);

        $runtimePath = $this->runtime->prepareRuntime($tenant, $provisioningJob, $assignedPort, [
            'PROVISIONING_DRIVER' => 'openclaw',
            'OPENCLAW_GATEWAY_PORT' => (string) config('sync360.openclaw.gateway_port', 18789),
            'OPENCLAW_GATEWAY_TOKEN' => $gatewayToken,
        ], [
            'provisioning_driver' => 'openclaw',
            'openclaw' => [
                'image' => config('sync360.openclaw.image'),
                'gateway_port' => (int) config('sync360.openclaw.gateway_port', 18789),
                'readiness_path' => config('sync360.openclaw.readiness_path'),
            ],
        ]);

        $composeFile = $runtimePath.DIRECTORY_SEPARATOR.(string) config('sync360.openclaw.compose_filename', 'compose.yaml');
        $projectName = $this->projectName($tenant);

        $this->writeOpenClawConfig($runtimePath, $assignedPort, $gatewayToken);
        $this->writeComposeFile($tenant, $runtimePath, $composeFile, $assignedPort, $gatewayToken);

        $tenant->forceFill([
            'assigned_port' => $assignedPort,
            'runtime_path' => $runtimePath,
            'workspace_url' => $workspaceUrl,
            'provisioning_status' => TenantProvisioningStatus::Provisioning,
        ])->save();

        try {
            $this->stopFailedRuntime($composeFile, $projectName);
            $this->dockerCompose->up($composeFile, $projectName);
            $this->waitForReadiness($assignedPort);
        } catch (Throwable $exception) {
            $this->stopFailedRuntime($composeFile, $projectName);

            throw $exception;
        }

        $tenant->forceFill([
            'assigned_port' => $assignedPort,
            'runtime_path' => $runtimePath,
            'workspace_url' => $workspaceUrl,
            'provisioning_status' => TenantProvisioningStatus::Ready,
        ])->save();

        $provisioningJob->forceFill([
            'status' => ProvisioningJobStatus::Completed,
            'completed_at' => now(),
            'error_message' => null,
        ])->save();
    }

    private function writeOpenClawConfig(string $runtimePath, int $assignedPort, string $gatewayToken): void
    {
        $configPath = $runtimePath.DIRECTORY_SEPARATOR.'config'.DIRECTORY_SEPARATOR.'openclaw.json';

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
                        'enabled' => true,
                        'allowInsecureAuth' => true,
                        'allowedOrigins' => [
                            sprintf('http://localhost:%d', $assignedPort),
                            sprintf('http://127.0.0.1:%d', $assignedPort),
                        ],
                    ],
                ],
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL,
        );
    }

    private function writeComposeFile(Tenant $tenant, string $runtimePath, string $composeFile, int $assignedPort, string $gatewayToken): void
    {
        $hostRuntimePath = $this->runtime->hostPath($runtimePath);
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
            sprintf('        source: %s', $this->yamlQuote($hostRuntimePath)),
            sprintf('        target: %s', $this->yamlQuote($containerHome)),
            '    environment:',
            sprintf('      OPENCLAW_HOME: %s', $this->yamlQuote($containerHome)),
            sprintf('      OPENCLAW_STATE_DIR: %s', $this->yamlQuote($stateDir)),
            sprintf('      OPENCLAW_CONFIG_PATH: %s', $this->yamlQuote($configPath)),
            sprintf('      OPENCLAW_GATEWAY_TOKEN: %s', $this->yamlQuote($gatewayToken)),
            '',
        ]);

        $this->files->put($composeFile, $compose);
    }

    private function waitForReadiness(int $assignedPort): void
    {
        $readinessPath = '/'.ltrim((string) config('sync360.openclaw.readiness_path', '/readyz'), '/');
        $probeHost = (string) config('sync360.openclaw.readiness_probe_host', 'host.docker.internal');
        $readinessUrl = sprintf('http://%s:%d%s', $probeHost, $assignedPort, $readinessPath);
        $timeoutSeconds = max(1, (int) config('sync360.openclaw.readiness_timeout_seconds', 45));
        $pollIntervalMs = max(100, (int) config('sync360.openclaw.readiness_poll_interval_ms', 1000));
        $deadline = microtime(true) + $timeoutSeconds;
        $lastError = null;

        do {
            try {
                $response = Http::timeout(3)->acceptJson()->get($readinessUrl);

                if ($response->successful()) {
                    return;
                }

                $lastError = sprintf('HTTP %d from %s', $response->status(), $readinessUrl);
            } catch (Throwable $exception) {
                $lastError = $exception->getMessage();
            }

            usleep($pollIntervalMs * 1000);
        } while (microtime(true) < $deadline);

        throw new RuntimeException(sprintf(
            'OpenClaw readiness check failed for [%s]. %s',
            $readinessUrl,
            $lastError ? 'Last error: '.$lastError : 'The gateway never reported ready.',
        ));
    }

    private function stopFailedRuntime(string $composeFile, string $projectName): void
    {
        try {
            $this->dockerCompose->down($composeFile, $projectName);
        } catch (Throwable) {
            // A failed cleanup should not hide the original provisioning error.
        }
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
