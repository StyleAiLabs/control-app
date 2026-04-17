<?php

namespace App\Services;

use App\Contracts\DockerComposeRunner;
use App\Models\ProvisioningJob;
use App\Models\Tenant;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use RuntimeException;

class TenantRuntimeService
{
    public function __construct(
        private readonly Filesystem $files,
        private readonly DockerComposeRunner $dockerCompose,
    ) {
    }

    public function allocatePort(Tenant $tenant): int
    {
        $server = $tenant->server;

        if (! $server) {
            throw new RuntimeException('Tenant does not have an assigned client VPS.');
        }

        $start = (int) config('sync360.port_range.start', 4100);
        $end = (int) config('sync360.port_range.end', 4199);

        $usedPorts = Tenant::query()
            ->whereKeyNot($tenant->getKey())
            ->where('server_id', $tenant->server_id)
            ->whereNotNull('assigned_port')
            ->pluck('assigned_port')
            ->map(fn (mixed $port): int => (int) $port)
            ->all();

        for ($port = $start; $port <= $end; $port++) {
            if (! in_array($port, $usedPorts, true) && ! $this->dockerCompose->isHostPortInUse($server, $port)) {
                return $port;
            }
        }

        throw new RuntimeException(sprintf('No available ports remain in the configured range %d-%d.', $start, $end));
    }

    public function workspaceUrl(Tenant $tenant, int $assignedPort): string
    {
        $scheme = $this->workspaceScheme($tenant);
        $workspaceHost = $this->workspaceHost($tenant);

        if ($workspaceHost !== null) {
            return sprintf('%s://%s', $scheme, $workspaceHost);
        }

        $server = $tenant->server;

        if (! $server) {
            throw new RuntimeException('Tenant does not have an assigned client VPS.');
        }

        return sprintf('%s://%s:%d', $scheme, $server->host, $assignedPort);
    }

    public function workspaceScheme(Tenant $tenant): string
    {
        $server = $tenant->server;

        if (! $server) {
            throw new RuntimeException('Tenant does not have an assigned client VPS.');
        }

        return $server->workspace_scheme ?: 'http';
    }

    public function gatewayBaseUrl(Tenant $tenant): string
    {
        if (! $tenant->assigned_port) {
            throw new RuntimeException('Tenant assigned port is missing for the private gateway URL.');
        }

        $host = app()->environment('local')
            ? (string) config('sync360.host_port_probe_host', '127.0.0.1')
            : '127.0.0.1';

        return sprintf('http://%s:%d', $host, $tenant->assigned_port);
    }

    public function workspaceHost(Tenant $tenant): ?string
    {
        $server = $tenant->server;

        if (! $server) {
            throw new RuntimeException('Tenant does not have an assigned client VPS.');
        }

        if ($server->workspace_base_domain) {
            return sprintf('%s.%s', $tenant->slug, $server->workspace_base_domain);
        }

        return null;
    }

    public function controlAppUpstream(): string
    {
        return rtrim((string) config('sync360.workspace_proxy.control_app_upstream', config('app.url')), '/');
    }

    public function remoteRuntimePath(Tenant $tenant): string
    {
        $server = $tenant->server;

        if (! $server || ! $server->runtime_root) {
            throw new RuntimeException('Tenant server runtime root is not configured.');
        }

        return rtrim($server->runtime_root, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.'tenants'.DIRECTORY_SEPARATOR.$tenant->slug;
    }

    public function localRuntimePath(Tenant $tenant): string
    {
        $runtimeRoot = $this->normalizePath((string) config('sync360.runtime_root'));

        return $runtimeRoot.DIRECTORY_SEPARATOR.$tenant->slug;
    }

    public function localEnvPath(Tenant $tenant): string
    {
        return $this->localRuntimePath($tenant).DIRECTORY_SEPARATOR.'.env';
    }

    public function localComposePath(Tenant $tenant): string
    {
        return $this->localRuntimePath($tenant)
            .DIRECTORY_SEPARATOR
            .(string) config('sync360.openclaw.compose_filename', 'compose.yaml');
    }

    public function remoteComposePath(Tenant $tenant): string
    {
        return rtrim($tenant->runtime_path ?: $this->remoteRuntimePath($tenant), DIRECTORY_SEPARATOR)
            .DIRECTORY_SEPARATOR
            .(string) config('sync360.openclaw.compose_filename', 'compose.yaml');
    }

    public function localOpenClawConfigPath(Tenant $tenant): string
    {
        return $this->localRuntimePath($tenant)
            .DIRECTORY_SEPARATOR.'config'.DIRECTORY_SEPARATOR.'openclaw.json';
    }

    public function localWorkspacePath(Tenant $tenant): string
    {
        return $this->localRuntimePath($tenant)
            .DIRECTORY_SEPARATOR.'.openclaw'.DIRECTORY_SEPARATOR.'workspace';
    }

    public function localWorkspaceMemoryPath(Tenant $tenant): string
    {
        return $this->localWorkspacePath($tenant).DIRECTORY_SEPARATOR.'memory';
    }

    public function remoteOpenClawConfigPath(Tenant $tenant): string
    {
        return rtrim($tenant->runtime_path ?: $this->remoteRuntimePath($tenant), DIRECTORY_SEPARATOR)
            .DIRECTORY_SEPARATOR.'config'.DIRECTORY_SEPARATOR.'openclaw.json';
    }

    public function remoteWorkspacePath(Tenant $tenant): string
    {
        return rtrim($tenant->runtime_path ?: $this->remoteRuntimePath($tenant), DIRECTORY_SEPARATOR)
            .DIRECTORY_SEPARATOR.'.openclaw'.DIRECTORY_SEPARATOR.'workspace';
    }

    public function remoteWorkspaceMemoryPath(Tenant $tenant): string
    {
        return $this->remoteWorkspacePath($tenant).DIRECTORY_SEPARATOR.'memory';
    }

    public function caddySitePath(Tenant $tenant): string
    {
        $server = $tenant->server;

        if (! $server || ! $server->caddy_sites_path) {
            throw new RuntimeException('Tenant server Caddy sites path is not configured.');
        }

        return rtrim($server->caddy_sites_path, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.$tenant->slug.'.caddy';
    }

    public function localGogConfigPath(Tenant $tenant): string
    {
        return $this->localRuntimePath($tenant).DIRECTORY_SEPARATOR.'.openclaw'.DIRECTORY_SEPARATOR.'gogcli';
    }

    public function remoteGogConfigPath(Tenant $tenant): string
    {
        $runtimePath = $tenant->runtime_path ?: $this->remoteRuntimePath($tenant);

        return rtrim($runtimePath, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.'.openclaw'.DIRECTORY_SEPARATOR.'gogcli';
    }

    public function containerGogConfigHome(): string
    {
        return rtrim((string) config('sync360.openclaw.container_home', '/home/node/.openclaw'), '/')
            .DIRECTORY_SEPARATOR.'.openclaw';
    }

    public function containerGogConfigPath(): string
    {
        return $this->containerGogConfigHome().DIRECTORY_SEPARATOR.'gogcli';
    }

    public function googleKeyringPassword(Tenant $tenant): string
    {
        return hash_hmac('sha256', (string) $tenant->tenant_id, (string) config('app.key'));
    }

    /**
     * @return list<string>
     */
    public function localDockerComposeCommandParts(): array
    {
        return preg_split(
            '/\s+/',
            trim((string) config('sync360.infrastructure.local_docker_compose_bin', 'docker compose'))
        ) ?: ['docker', 'compose'];
    }

    public function localDockerComposeShellPrefix(): string
    {
        return implode(' ', array_map(
            static fn (string $part): string => escapeshellarg($part),
            $this->localDockerComposeCommandParts(),
        ));
    }

    public function projectName(Tenant $tenant): string
    {
        return Str::limit('sync360-'.$tenant->slug, 63, '');
    }

    public function containerName(Tenant $tenant): string
    {
        return 'sync360-'.$tenant->slug;
    }

    /**
     * @param  array<string, scalar|null>  $extraEnv
     * @param  array<string, mixed>  $extraMetadata
     */
    public function prepareRuntime(
        Tenant $tenant,
        ProvisioningJob $provisioningJob,
        int $assignedPort,
        array $extraEnv = [],
        array $extraMetadata = [],
    ): string {
        $templatePath = $this->normalizePath((string) config('sync360.template_root'));
        $runtimeRoot = $this->normalizePath((string) config('sync360.runtime_root'));
        $runtimePath = $this->localRuntimePath($tenant);

        if (! $this->files->exists($templatePath)) {
            throw new RuntimeException(sprintf('Template directory [%s] does not exist.', $templatePath));
        }

        if ($this->files->exists($runtimePath)) {
            $this->files->deleteDirectory($runtimePath);
        }

        $this->files->ensureDirectoryExists($runtimeRoot);

        if (! $this->files->copyDirectory($templatePath, $runtimePath)) {
            throw new RuntimeException(sprintf('Unable to copy tenant template into [%s].', $runtimePath));
        }

        foreach (['config', 'data', 'logs', 'workspace', '.openclaw/workspace', '.openclaw/gogcli/keyring'] as $directory) {
            $this->files->ensureDirectoryExists($runtimePath.DIRECTORY_SEPARATOR.$directory);
        }

        $workspaceUrl = $this->workspaceUrl($tenant, $assignedPort);

        $envValues = array_merge([
            'TENANT_ID' => $tenant->tenant_id,
            'TENANT_SLUG' => $tenant->slug,
            'BUSINESS_NAME' => $tenant->business_name,
            'INDUSTRY' => $tenant->industry,
            'SKILL_PACK' => $tenant->skill_pack,
            'ASSIGNED_PORT' => (string) $assignedPort,
            'WORKSPACE_URL' => $workspaceUrl,
            'XDG_CONFIG_HOME' => $this->containerGogConfigHome(),
            'GOG_KEYRING_BACKEND' => 'file',
            'GOG_KEYRING_PASSWORD' => $this->googleKeyringPassword($tenant),
        ], $extraEnv);

        $this->files->put(
            $runtimePath.DIRECTORY_SEPARATOR.'.env',
            $this->renderEnvFile($envValues),
        );

        $this->files->put(
            $runtimePath.DIRECTORY_SEPARATOR.'metadata.json',
            json_encode(array_merge([
                'tenant_id' => $tenant->tenant_id,
                'slug' => $tenant->slug,
                'business_name' => $tenant->business_name,
                'industry' => $tenant->industry,
                'skill_pack' => $tenant->skill_pack,
                'assigned_port' => $assignedPort,
                'workspace_url' => $workspaceUrl,
                'generated_at' => Carbon::now()->toIso8601String(),
                'requested_by' => [
                    'user_id' => $tenant->user_id,
                    'contact_name' => $tenant->user?->name,
                    'email' => $tenant->user?->email,
                ],
                'server' => $tenant->server ? [
                    'id' => $tenant->server->id,
                    'name' => $tenant->server->name,
                    'host' => $tenant->server->host,
                    'ssh_host' => $tenant->server->ssh_host,
                    'runtime_root' => $tenant->server->runtime_root,
                ] : null,
                'job_payload' => $provisioningJob->payload_json ?? [],
            ], $extraMetadata), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL,
        );

        return $runtimePath;
    }

    /**
     * @param  array<string, scalar|null>  $values
     */
    private function renderEnvFile(array $values): string
    {
        $lines = [];

        foreach ($values as $key => $value) {
            if ($value === null) {
                continue;
            }

            $escapedValue = str_replace(['\\', '"', "\n"], ['\\\\', '\"', '\n'], (string) $value);
            $shouldQuote = preg_match('/\s|=/', $escapedValue) === 1;

            $lines[] = sprintf('%s=%s', $key, $shouldQuote ? '"'.$escapedValue.'"' : $escapedValue);
        }

        return implode(PHP_EOL, $lines).PHP_EOL;
    }

    private function normalizePath(string $path): string
    {
        if ($path === '') {
            throw new RuntimeException('Provisioning path configuration cannot be empty.');
        }

        if (str_starts_with($path, DIRECTORY_SEPARATOR)) {
            return $path;
        }

        return base_path($path);
    }
}
