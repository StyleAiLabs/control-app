<?php

namespace App\Services;

use App\Contracts\DockerComposeRunner;
use App\Models\ProvisioningJob;
use App\Models\Tenant;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Carbon;
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
        $start = (int) config('sync360.port_range.start', 4100);
        $end = (int) config('sync360.port_range.end', 4199);

        $usedPorts = Tenant::query()
            ->whereKeyNot($tenant->getKey())
            ->whereNotNull('assigned_port')
            ->pluck('assigned_port')
            ->map(fn (mixed $port): int => (int) $port)
            ->all();

        for ($port = $start; $port <= $end; $port++) {
            if (! in_array($port, $usedPorts, true) && ! $this->dockerCompose->isHostPortInUse($port)) {
                return $port;
            }
        }

        throw new RuntimeException(sprintf('No available ports remain in the configured range %d-%d.', $start, $end));
    }

    public function workspaceUrl(int $assignedPort): string
    {
        return sprintf('http://localhost:%d', $assignedPort);
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
        $runtimePath = $runtimeRoot.DIRECTORY_SEPARATOR.$tenant->slug;

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

        foreach (['config', 'data', 'logs', 'workspace'] as $directory) {
            $this->files->ensureDirectoryExists($runtimePath.DIRECTORY_SEPARATOR.$directory);
        }

        $workspaceUrl = $this->workspaceUrl($assignedPort);

        $envValues = array_merge([
            'TENANT_ID' => $tenant->tenant_id,
            'TENANT_SLUG' => $tenant->slug,
            'BUSINESS_NAME' => $tenant->business_name,
            'INDUSTRY' => $tenant->industry,
            'SKILL_PACK' => $tenant->skill_pack,
            'ASSIGNED_PORT' => (string) $assignedPort,
            'WORKSPACE_URL' => $workspaceUrl,
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
                'job_payload' => $provisioningJob->payload_json ?? [],
            ], $extraMetadata), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL,
        );

        return $runtimePath;
    }

    public function hostPath(string $path): string
    {
        $normalizedPath = $this->normalizePath($path);
        $appRoot = rtrim(base_path(), DIRECTORY_SEPARATOR);
        $hostRoot = rtrim($this->normalizeHostProjectRoot((string) config('sync360.host_project_root')), DIRECTORY_SEPARATOR);

        if ($hostRoot === $appRoot) {
            return $normalizedPath;
        }

        if (str_starts_with($normalizedPath, $appRoot)) {
            return $hostRoot.substr($normalizedPath, strlen($appRoot));
        }

        return $normalizedPath;
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

    private function normalizeHostProjectRoot(string $path): string
    {
        if ($path === '') {
            return base_path();
        }

        if (str_starts_with($path, DIRECTORY_SEPARATOR)) {
            return $path;
        }

        return base_path($path);
    }
}
