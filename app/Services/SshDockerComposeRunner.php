<?php

namespace App\Services;

use App\Contracts\DockerComposeRunner;
use App\Models\Server;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Process;

class SshDockerComposeRunner implements DockerComposeRunner
{
    public function __construct(
        private readonly Filesystem $files,
    ) {
    }

    public function syncRuntime(Server $server, string $localRuntimePath, string $remoteRuntimePath): void
    {
        $this->runSsh($server, sprintf(
            'rm -rf %s && mkdir -p %s',
            $this->shellQuote($remoteRuntimePath),
            $this->shellQuote($remoteRuntimePath),
        ));

        $this->runScp($server, $localRuntimePath, $remoteRuntimePath);
    }

    public function syncWorkspaceFiles(Server $server, string $localWorkspacePath, string $remoteWorkspacePath): void
    {
        /* Ensure the remote workspace directory exists (don't delete anything). */
        $this->runSsh($server, sprintf(
            'mkdir -p %s',
            $this->shellQuote($remoteWorkspacePath),
        ));

        /* Copy ONLY the workspace markdown files — never touches compose.yaml or
           config/openclaw.json so provisioning credentials are always preserved. */
        $this->runScp($server, $localWorkspacePath, $remoteWorkspacePath);
    }

    /**
     * @param  array<string, mixed>|null  $json
     * @return array{status:int, body:string}
     */
    public function httpRequest(Server $server, string $method, string $url, ?array $json = null, int $timeoutSeconds = 15, array $headers = []): array
    {
        $parts = [
            'tmp_file=$(mktemp)',
        ];

        $curlParts = [
            'curl',
            '-sS',
            '-X',
            $this->shellQuote(strtoupper($method)),
            '-H',
            $this->shellQuote('Accept: application/json'),
        ];

        foreach ($headers as $name => $value) {
            $normalizedName = trim((string) $name);
            $normalizedValue = trim((string) $value);

            if ($normalizedName === '' || $normalizedValue === '') {
                continue;
            }

            $curlParts[] = '-H';
            $curlParts[] = $this->shellQuote($normalizedName.': '.$normalizedValue);
        }

        if ($json !== null) {
            $payload = json_encode($json, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

            if ($payload === false) {
                throw new RuntimeException('Unable to encode the private gateway request payload.');
            }

            $curlParts[] = '-H';
            $curlParts[] = $this->shellQuote('Content-Type: application/json');
            $curlParts[] = '--data-binary';
            $curlParts[] = '@-';

            $parts[] = sprintf(
                'status=$(printf %%s %s | %s -o "$tmp_file" -w "%%{http_code}" %s)',
                $this->shellQuote($payload),
                implode(' ', $curlParts),
                $this->shellQuote($url),
            );
        } else {
            $parts[] = sprintf(
                'status=$(%s -o "$tmp_file" -w "%%{http_code}" %s)',
                implode(' ', $curlParts),
                $this->shellQuote($url),
            );
        }

        $parts[] = 'printf \'__SYNC360_STATUS__%s\n\' "$status"';
        $parts[] = 'cat "$tmp_file"';
        $parts[] = 'rm -f "$tmp_file"';

        $process = $this->runSsh(
            $server,
            sprintf('sh -lc %s', $this->shellQuote(implode(' && ', $parts))),
            timeoutSeconds: $timeoutSeconds,
        );

        $output = $process->getOutput();

        if (! preg_match('/^__SYNC360_STATUS__(\d{3})\n/s', $output, $matches)) {
            throw new RuntimeException('Unable to parse the private gateway HTTP response.');
        }

        return [
            'status' => (int) $matches[1],
            'body' => substr($output, strlen($matches[0])),
        ];
    }

    public function putFile(Server $server, string $remotePath, string $contents, bool $sudo = false): void
    {
        $tempFile = tempnam(sys_get_temp_dir(), 'sync360-');

        if ($tempFile === false) {
            throw new RuntimeException('Unable to create a temporary file for remote upload.');
        }

        $this->files->put($tempFile, $contents);

        try {
            $remoteTempPath = '/tmp/'.Str::uuid()->toString().'-'.basename($remotePath);

            $this->runScpFile($server, $tempFile, $remoteTempPath);
            $this->runCommand($server, sprintf(
                'mkdir -p %s && install -m 0644 %s %s && rm -f %s',
                $this->shellQuote(dirname($remotePath)),
                $this->shellQuote($remoteTempPath),
                $this->shellQuote($remotePath),
                $this->shellQuote($remoteTempPath),
            ), $sudo);
        } finally {
            $this->files->delete($tempFile);
        }
    }

    public function removeFile(Server $server, string $remotePath, bool $sudo = false): void
    {
        $this->runCommand($server, sprintf('rm -f %s', $this->shellQuote($remotePath)), $sudo);
    }

    public function removeDirectory(Server $server, string $remotePath, bool $sudo = false): void
    {
        $this->runCommand($server, sprintf('rm -rf %s', $this->shellQuote($remotePath)), $sudo);
    }

    public function runCommand(Server $server, string $command, bool $sudo = false): void
    {
        $this->runSsh($server, $this->wrapRemoteCommand($server, $command, $sudo));
    }

    public function up(Server $server, string $composeFile, string $projectName): void
    {
        $this->runSsh($server, $this->composeCommand($server, $composeFile, $projectName, 'up -d'), timeoutSeconds: $this->composeTimeout());
    }

    public function down(Server $server, string $composeFile, string $projectName): void
    {
        $this->runSsh($server, $this->composeCommand($server, $composeFile, $projectName, 'down --remove-orphans'), throwOnFailure: false, timeoutSeconds: $this->composeTimeout());
    }

    public function start(Server $server, string $composeFile, string $projectName): void
    {
        $this->runSsh($server, $this->composeCommand($server, $composeFile, $projectName, 'start'), timeoutSeconds: $this->composeTimeout());
    }

    public function stop(Server $server, string $composeFile, string $projectName): void
    {
        $this->runSsh($server, $this->composeCommand($server, $composeFile, $projectName, 'stop'), timeoutSeconds: $this->composeTimeout());
    }

    public function isRunning(Server $server, string $composeFile, string $projectName): bool
    {
        $process = $this->runSsh(
            $server,
            $this->composeCommand($server, $composeFile, $projectName, 'ps --status running -q'),
            throwOnFailure: false,
            timeoutSeconds: $this->composeTimeout(),
        );

        if (! $process->isSuccessful()) {
            return false;
        }

        return trim($process->getOutput()) !== '';
    }

    public function isHostPortInUse(Server $server, int $port): bool
    {
        $process = $this->runSsh(
            $server,
            sprintf('sh -lc %s', $this->shellQuote(sprintf('ss -ltn "( sport = :%d )" | tail -n +2 | grep -q .', $port))),
            throwOnFailure: false,
        );

        return $process->isSuccessful();
    }

    public function waitForHttpReady(Server $server, string $url, int $timeoutSeconds, int $pollIntervalMs): void
    {
        $deadline = microtime(true) + max(1, $timeoutSeconds);
        $lastError = null;

        do {
            $process = $this->runSsh(
                $server,
                sprintf('sh -lc %s', $this->shellQuote(sprintf('curl -fsS %s >/dev/null', $this->shellQuote($url)))),
                throwOnFailure: false,
            );

            if ($process->isSuccessful()) {
                return;
            }

            $lastError = trim($process->getErrorOutput()) ?: trim($process->getOutput()) ?: 'Remote readiness probe did not succeed.';

            usleep(max(100, $pollIntervalMs) * 1000);
        } while (microtime(true) < $deadline);

        throw new RuntimeException(sprintf(
            'OpenClaw readiness check failed for [%s]. Last error: %s',
            $url,
            $lastError ?: 'The gateway never reported ready.',
        ));
    }

    private function runScp(Server $server, string $localRuntimePath, string $remoteRuntimePath): void
    {
        $command = [
            ...$this->authPrefix($server),
            $this->scpBinary(),
            '-r',
            ...$this->sshOptions(),
            '-P',
            (string) ($server->ssh_port ?: 22),
            rtrim($localRuntimePath, DIRECTORY_SEPARATOR).'/.',
            $this->target($server).':'.$remoteRuntimePath.'/',
        ];

        $this->runLocalProcess($command, (int) config('sync360.infrastructure.scp_timeout_seconds', 120));
    }

    private function runScpFile(Server $server, string $localFilePath, string $remotePath): void
    {
        $command = [
            ...$this->authPrefix($server),
            $this->scpBinary(),
            ...$this->sshOptions(),
            '-P',
            (string) ($server->ssh_port ?: 22),
            $localFilePath,
            $this->target($server).':'.$remotePath,
        ];

        $this->runLocalProcess($command, (int) config('sync360.infrastructure.scp_timeout_seconds', 120));
    }

    private function runSsh(Server $server, string $remoteCommand, bool $throwOnFailure = true, ?int $timeoutSeconds = null): Process
    {
        $command = [
            ...$this->authPrefix($server),
            $this->sshBinary(),
            ...$this->sshOptions(),
            '-p',
            (string) ($server->ssh_port ?: 22),
            $this->target($server),
            $remoteCommand,
        ];

        return $this->runLocalProcess(
            $command,
            $timeoutSeconds ?? (int) config('sync360.infrastructure.ssh_timeout_seconds', 30),
            $throwOnFailure,
        );
    }

    private function composeTimeout(): int
    {
        return (int) config('sync360.openclaw.compose_timeout_seconds', 600);
    }

    private function composeCommand(Server $server, string $composeFile, string $projectName, string $action): string
    {
        $composeBin = trim((string) ($server->docker_compose_bin ?: 'docker compose'));

        return sprintf(
            'cd %s && %s --project-name %s -f %s %s',
            $this->shellQuote(dirname($composeFile)),
            $composeBin,
            $this->shellQuote($projectName),
            $this->shellQuote($composeFile),
            $action,
        );
    }

    private function wrapRemoteCommand(Server $server, string $command, bool $sudo): string
    {
        $shellCommand = sprintf('sh -lc %s', $this->shellQuote($command));

        if (! $sudo) {
            return $shellCommand;
        }

        $password = $this->resolveSecret($server->sudo_password_env_key ?: $server->ssh_password_env_key);

        return sprintf(
            'printf %%s %s | sudo -S -p \'\' %s',
            $this->shellQuote($password),
            $shellCommand,
        );
    }

    /**
     * @return list<string>
     */
    private function authPrefix(Server $server): array
    {
        if ($server->usesPasswordAuth()) {
            return [
                $this->sshpassBinary(),
                '-p',
                $this->resolveSecret($server->ssh_password_env_key),
            ];
        }

        return [];
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

    private function sshBinary(): string
    {
        return (string) config('sync360.infrastructure.ssh_bin', 'ssh');
    }

    private function scpBinary(): string
    {
        return (string) config('sync360.infrastructure.scp_bin', 'scp');
    }

    private function sshpassBinary(): string
    {
        return (string) config('sync360.infrastructure.sshpass_bin', 'sshpass');
    }

    private function resolveSecret(?string $envKey): string
    {
        if (! $envKey) {
            throw new RuntimeException('Remote server secret environment key is not configured.');
        }

        $value = env($envKey);

        if ($value === null || $value === '') {
            throw new RuntimeException(sprintf('The required secret [%s] is not set in the worker environment.', $envKey));
        }

        return (string) $value;
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

    /**
     * @param  list<string>  $command
     */
    private function runLocalProcess(array $command, int $timeoutSeconds, bool $throwOnFailure = true): Process
    {
        $process = new Process($command, timeout: $timeoutSeconds);
        $process->run();

        if ($throwOnFailure && ! $process->isSuccessful()) {
            throw new ProcessFailedException($process);
        }

        return $process;
    }
}
