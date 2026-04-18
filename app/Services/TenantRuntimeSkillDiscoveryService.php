<?php

namespace App\Services;

use App\Contracts\DockerComposeRunner;
use App\Models\Server;
use App\Models\Tenant;
use Illuminate\Filesystem\Filesystem;
use RuntimeException;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Process;

class TenantRuntimeSkillDiscoveryService
{
    public function __construct(
        private readonly Filesystem $files,
        private readonly DockerComposeRunner $dockerCompose,
        private readonly TenantRuntimeService $runtime,
    ) {
    }

    /**
     * @return array{
     *     workspace_state:string,
     *     refreshed_at:string,
     *     skills:array<int, string>,
     *     raw_output:string
     * }
     */
    public function inspect(Tenant $tenant): array
    {
        $tenant->loadMissing('server');

        if (! $tenant->server) {
            throw new RuntimeException('Tenant server is missing.');
        }

        $composeFile = $this->composeFileFor($tenant);
        $projectName = $this->runtime->projectName($tenant);

        if (! $this->dockerCompose->isRunning($tenant->server, $composeFile, $projectName)) {
            throw new RuntimeException('Workspace container is not currently running. Start or restart the workspace, then refresh runtime skills.');
        }

        $serviceName = (string) config('sync360.openclaw.service_name', 'openclaw-gateway');
        $command = 'openclaw skills list --eligible';

        try {
            $output = $this->shouldUseLocalComposeExecution($tenant)
                ? $this->runLocalComposeCommand($tenant, $composeFile, $projectName, $serviceName, $command)
                : $this->runRemoteComposeCommand($tenant->server, $composeFile, $projectName, $serviceName, $command);
        } catch (ProcessFailedException $exception) {
            $error = trim($exception->getProcess()->getErrorOutput()) ?: trim($exception->getProcess()->getOutput());

            throw new RuntimeException($error !== ''
                ? $error
                : 'OpenClaw skill discovery command failed inside the tenant runtime.');
        }

        $rawOutput = $this->normalizeOutput($output);

        return [
            'workspace_state' => 'running',
            'refreshed_at' => now()->toDateTimeString(),
            'skills' => $this->parseSkillOutput($rawOutput),
            'raw_output' => $rawOutput,
        ];
    }

    private function shouldUseLocalComposeExecution(Tenant $tenant): bool
    {
        return app()->environment('local') && $this->files->exists($this->runtime->localComposePath($tenant));
    }

    private function composeFileFor(Tenant $tenant): string
    {
        if ($this->shouldUseLocalComposeExecution($tenant)) {
            return $this->runtime->localComposePath($tenant);
        }

        if (! filled($tenant->runtime_path)) {
            throw new RuntimeException('Tenant runtime path is missing.');
        }

        return $this->runtime->remoteComposePath($tenant);
    }

    private function runLocalComposeCommand(
        Tenant $tenant,
        string $composeFile,
        string $projectName,
        string $serviceName,
        string $command
    ): string {
        $process = new Process([
            ...$this->runtime->localDockerComposeCommandParts(),
            '-f',
            $composeFile,
            '-p',
            $projectName,
            'exec',
            '-T',
            $serviceName,
            'sh',
            '-lc',
            $command,
        ], timeout: (int) config('sync360.openclaw.compose_timeout_seconds', 120));
        $process->run();

        if (! $process->isSuccessful()) {
            throw new ProcessFailedException($process);
        }

        return $process->getOutput();
    }

    private function runRemoteComposeCommand(
        Server $server,
        string $composeFile,
        string $projectName,
        string $serviceName,
        string $command
    ): string {
        $remoteCommand = sprintf(
            'cd %s && %s --project-name %s -f %s exec -T %s sh -lc %s',
            $this->shellQuote(dirname($composeFile)),
            trim((string) ($server->docker_compose_bin ?: 'docker compose')),
            $this->shellQuote($projectName),
            $this->shellQuote($composeFile),
            $this->shellQuote($serviceName),
            $this->shellQuote($command),
        );

        $process = new Process([
            ...$this->authPrefix($server),
            (string) config('sync360.infrastructure.ssh_bin', 'ssh'),
            ...$this->sshOptions(),
            '-p',
            (string) ($server->ssh_port ?: 22),
            $this->target($server),
            sprintf('sh -lc %s', $this->shellQuote($remoteCommand)),
        ], timeout: (int) config('sync360.openclaw.compose_timeout_seconds', 120));
        $process->run();

        if (! $process->isSuccessful()) {
            throw new ProcessFailedException($process);
        }

        return $process->getOutput();
    }

    /**
     * @return list<string>
     */
    private function parseSkillOutput(string $output): array
    {
        $skills = [];
        $lines = preg_split('/\R+/', $output) ?: [];

        foreach ($lines as $line) {
            $normalized = trim($line);

            if ($normalized === '') {
                continue;
            }

            if (preg_match('/^(eligible|available)\s+skills:?$/i', $normalized)) {
                continue;
            }

            $normalized = preg_replace('/^[-*]\s+/', '', $normalized) ?? $normalized;
            $normalized = preg_replace('/^\d+[.)]\s+/', '', $normalized) ?? $normalized;

            if ($normalized === '') {
                continue;
            }

            $skills[] = $normalized;
        }

        return array_values(array_unique($skills));
    }

    private function normalizeOutput(string $output): string
    {
        $withoutAnsi = preg_replace('/\e\[[\d;]*[A-Za-z]/', '', $output) ?? $output;

        return trim($withoutAnsi);
    }

    /**
     * @return list<string>
     */
    private function authPrefix(Server $server): array
    {
        if (! $server->usesPasswordAuth()) {
            return [];
        }

        $envKey = $server->ssh_password_env_key;
        $password = $envKey ? env($envKey) : null;

        if (! is_string($password) || $password === '') {
            throw new RuntimeException('Remote server SSH password is not configured in the worker environment.');
        }

        return [
            (string) config('sync360.infrastructure.sshpass_bin', 'sshpass'),
            '-p',
            $password,
        ];
    }

    /**
     * @return list<string>
     */
    private function sshOptions(): array
    {
        $options = [];

        foreach ((array) config('sync360.infrastructure.ssh_options', []) as $option) {
            $options[] = '-o';
            $options[] = (string) $option;
        }

        return $options;
    }

    private function target(Server $server): string
    {
        $user = $server->ssh_user ?: 'root';
        $host = $server->ssh_host ?: $server->host;

        if (! $host) {
            throw new RuntimeException('Remote server host is not configured.');
        }

        return sprintf('%s@%s', $user, $host);
    }

    private function shellQuote(string $value): string
    {
        return "'".str_replace("'", "'\"'\"'", $value)."'";
    }
}
