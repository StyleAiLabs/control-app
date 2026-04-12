<?php

namespace App\Services;

use App\Contracts\DockerComposeRunner;
use App\Models\Tenant;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use RuntimeException;

class TenantDeletionService
{
    public function __construct(
        private readonly DockerComposeRunner $dockerCompose,
        private readonly TenantRuntimeService $runtime,
        private readonly LiteLlmTenantKeyService $liteLlmKeys,
        private readonly Filesystem $files,
    ) {
    }

    public function deletePermanently(Tenant $tenant): void
    {
        $tenant->loadMissing([
            'user',
            'server',
            'businessProfile',
            'businessProfileFiles',
            'conversationLogs',
            'provisioningJobs' => fn ($query) => $query->latest('id'),
        ]);

        if ($tenant->user?->is_admin) {
            throw new RuntimeException('This tenant is linked to an admin account and must be handled manually.');
        }

        $this->removeWorkspaceInfrastructure($tenant);
        $this->liteLlmKeys->deleteTenantKey($tenant);

        DB::transaction(function () use ($tenant): void {
            if ($tenant->user) {
                $tenant->user->delete();

                return;
            }

            $tenant->delete();
        });
    }

    private function removeWorkspaceInfrastructure(Tenant $tenant): void
    {
        if (app()->environment('local')) {
            $this->removeLocalWorkspaceInfrastructure($tenant);

            return;
        }

        if (! $tenant->server) {
            if ($tenant->runtime_path) {
                throw new RuntimeException('This tenant has a runtime path but no assigned client VPS, so remote cleanup cannot continue safely.');
            }

            $this->removeLocalRuntime($tenant);

            return;
        }

        $remoteRuntimePath = $tenant->runtime_path ?: $this->runtime->remoteRuntimePath($tenant);
        $composeFile = rtrim($remoteRuntimePath, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.(string) config('sync360.openclaw.compose_filename', 'compose.yaml');
        $projectName = Str::limit('sync360-'.$tenant->slug, 63, '');
        $composeBin = trim((string) ($tenant->server->docker_compose_bin ?: 'docker compose'));

        $this->dockerCompose->runCommand(
            $tenant->server,
            sprintf(
                'if [ -f %s ]; then cd %s && %s --project-name %s -f %s down --remove-orphans; fi',
                escapeshellarg($composeFile),
                escapeshellarg(dirname($composeFile)),
                $composeBin,
                escapeshellarg($projectName),
                escapeshellarg($composeFile),
            ),
        );

        if ($this->shouldManageCaddy($tenant)) {
            $this->dockerCompose->removeFile($tenant->server, $this->runtime->caddySitePath($tenant), sudo: true);
            $this->dockerCompose->runCommand($tenant->server, trim((string) $tenant->server->caddy_reload_command), sudo: true);
        }

        $this->dockerCompose->removeDirectory($tenant->server, $remoteRuntimePath);
        $this->removeLocalRuntime($tenant);
    }

    private function removeLocalWorkspaceInfrastructure(Tenant $tenant): void
    {
        $runtimePaths = collect([
            $tenant->runtime_path,
            $this->runtime->localRuntimePath($tenant),
        ])->filter(fn (?string $path): bool => filled($path))->unique()->values();

        $composeRoot = (string) $runtimePaths->first();
        $composeFile = $composeRoot !== ''
            ? rtrim($composeRoot, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.(string) config('sync360.openclaw.compose_filename', 'compose.yaml')
            : '';
        $projectName = Str::limit('sync360-'.$tenant->slug, 63, '');

        if ($composeFile !== '' && file_exists($composeFile)) {
            $command = sprintf(
                'docker compose -f %s -p %s down --remove-orphans',
                escapeshellarg($composeFile),
                escapeshellarg($projectName),
            );

            Log::info('[TenantDelete] Local dev: '.$command);

            $result = Process::run($command);

            if (! $result->successful()) {
                throw new RuntimeException('Docker compose command failed: '.$result->errorOutput());
            }
        }

        foreach ($runtimePaths as $path) {
            $this->files->deleteDirectory($path);
        }
    }

    private function removeLocalRuntime(Tenant $tenant): void
    {
        $this->files->deleteDirectory($this->runtime->localRuntimePath($tenant));
    }

    private function shouldManageCaddy(Tenant $tenant): bool
    {
        return (bool) ($tenant->server?->workspace_base_domain && $tenant->server?->caddy_sites_path && $tenant->server?->caddy_reload_command);
    }
}
