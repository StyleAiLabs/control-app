<?php

namespace App\Services;

use App\Contracts\DockerComposeRunner;
use App\Models\Tenant;
use Illuminate\Http\Client\Factory as HttpFactory;

class TenantHealthCheckService
{
    public function __construct(
        private readonly DockerComposeRunner $dockerCompose,
        private readonly HttpFactory $http,
    ) {
    }

    /**
     * @return array{healthy:bool,status:string,message:string,workspace_state:string}
     */
    public function check(Tenant $tenant): array
    {
        $tenant->loadMissing('server');

        if (! $tenant->server) {
            return $this->markFailed($tenant, 'Tenant is missing an assigned server.', 'missing_server');
        }

        if (! filled($tenant->workspace_url)) {
            return $this->markFailed($tenant, 'Tenant workspace URL is missing.', 'missing_workspace_url');
        }

        if (! filled($tenant->runtime_path)) {
            return $this->markFailed($tenant, 'Tenant runtime path is missing.', 'missing_runtime_path');
        }

        $composeFile = rtrim((string) $tenant->runtime_path, DIRECTORY_SEPARATOR)
            .DIRECTORY_SEPARATOR
            .(string) config('sync360.openclaw.compose_filename', 'compose.yaml');
        $projectName = substr('sync360-'.$tenant->slug, 0, 63);
        $workspaceState = $this->dockerCompose->isRunning($tenant->server, $composeFile, $projectName)
            ? 'running'
            : 'stopped';

        if ($workspaceState !== 'running') {
            return $this->markFailed($tenant, 'Workspace container is not currently running.', $workspaceState);
        }

        $readinessPath = '/'.ltrim((string) config('sync360.openclaw.readiness_path', '/readyz'), '/');
        $healthUrl = rtrim((string) $tenant->workspace_url, '/').$readinessPath;

        try {
            $response = $this->http
                ->timeout((int) config('sync360.workspace_gateway.timeout_seconds', 15))
                ->acceptJson()
                ->get($healthUrl);

            if ($response->successful()) {
                $tenant->forceFill([
                    'last_health_check_at' => now(),
                    'last_health_check_status' => 'healthy',
                    'health_check_message' => 'Workspace readiness check passed.',
                    'agent_status' => 'live',
                ])->save();

                return [
                    'healthy' => true,
                    'status' => 'healthy',
                    'message' => 'Workspace readiness check passed.',
                    'workspace_state' => $workspaceState,
                ];
            }

            return $this->markFailed(
                $tenant,
                sprintf('Workspace readiness returned HTTP %d.', $response->status()),
                $workspaceState,
            );
        } catch (\Throwable $exception) {
            return $this->markFailed(
                $tenant,
                'Workspace readiness check failed: '.$exception->getMessage(),
                $workspaceState,
            );
        }
    }

    /**
     * @return array{healthy:bool,status:string,message:string,workspace_state:string}
     */
    private function markFailed(Tenant $tenant, string $message, string $workspaceState): array
    {
        $tenant->forceFill([
            'last_health_check_at' => now(),
            'last_health_check_status' => 'failed',
            'health_check_message' => $message,
            'agent_status' => 'failed',
        ])->save();

        return [
            'healthy' => false,
            'status' => 'failed',
            'message' => $message,
            'workspace_state' => $workspaceState,
        ];
    }
}
