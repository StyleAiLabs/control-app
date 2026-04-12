<?php

namespace App\Services;

use App\Contracts\DockerComposeRunner;
use App\Models\Server;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Process;

class LocalDockerComposeRunner implements DockerComposeRunner
{
    public function __construct(
        private readonly Filesystem $files,
    ) {
    }

    public function syncRuntime(Server $server, string $localRuntimePath, string $remoteRuntimePath): void
    {
        // Local mode provisions directly from the app filesystem, so no sync step is needed.
    }

    /**
     * @param  array<string, mixed>|null  $json
     * @return array{status:int, body:string}
     */
    public function httpRequest(Server $server, string $method, string $url, ?array $json = null, int $timeoutSeconds = 15): array
    {
        $request = Http::timeout($timeoutSeconds)->acceptJson();

        if ($json !== null) {
            $request = $request->asJson();
        }

        $response = $request->send($method, $url, $json !== null ? ['json' => $json] : []);

        return [
            'status' => $response->status(),
            'body' => $response->body(),
        ];
    }

    public function putFile(Server $server, string $remotePath, string $contents, bool $sudo = false): void
    {
        $directory = dirname($remotePath);
        $this->files->ensureDirectoryExists($directory);
        $this->files->put($remotePath, $contents);
    }

    public function removeFile(Server $server, string $remotePath, bool $sudo = false): void
    {
        $this->files->delete($remotePath);
    }

    public function removeDirectory(Server $server, string $remotePath, bool $sudo = false): void
    {
        $this->files->deleteDirectory($remotePath);
    }

    public function runCommand(Server $server, string $command, bool $sudo = false): void
    {
        $this->run(['sh', '-lc', $command]);
    }

    public function up(Server $server, string $composeFile, string $projectName): void
    {
        $this->run([
            ...$this->composeBaseCommand($server, $projectName, $composeFile),
            'up',
            '-d',
        ]);
    }

    public function down(Server $server, string $composeFile, string $projectName): void
    {
        $this->run([
            ...$this->composeBaseCommand($server, $projectName, $composeFile),
            'down',
            '--remove-orphans',
        ]);
    }

    public function start(Server $server, string $composeFile, string $projectName): void
    {
        $this->run([
            ...$this->composeBaseCommand($server, $projectName, $composeFile),
            'start',
        ]);
    }

    public function stop(Server $server, string $composeFile, string $projectName): void
    {
        $this->run([
            ...$this->composeBaseCommand($server, $projectName, $composeFile),
            'stop',
        ]);
    }

    public function isRunning(Server $server, string $composeFile, string $projectName): bool
    {
        $process = $this->run([
            ...$this->composeBaseCommand($server, $projectName, $composeFile),
            'ps',
            '--status',
            'running',
            '-q',
        ], throwOnFailure: false);

        if (! $process->isSuccessful()) {
            return false;
        }

        return trim($process->getOutput()) !== '';
    }

    public function isHostPortInUse(Server $server, int $port): bool
    {
        $probeHost = (string) config('sync360.host_port_probe_host', 'host.docker.internal');
        $timeout = max(1, (int) config('sync360.host_port_probe_timeout_seconds', 1));
        $socket = @fsockopen($probeHost, $port, $errorCode, $errorMessage, $timeout);

        if ($socket !== false) {
            fclose($socket);

            return true;
        }

        return false;
    }

    public function waitForHttpReady(Server $server, string $url, int $timeoutSeconds, int $pollIntervalMs): void
    {
        $deadline = microtime(true) + max(1, $timeoutSeconds);
        $lastError = null;

        do {
            try {
                $response = Http::timeout(3)->acceptJson()->get($url);

                if ($response->successful()) {
                    return;
                }

                $lastError = sprintf('HTTP %d from %s', $response->status(), $url);
            } catch (\Throwable $exception) {
                $lastError = $exception->getMessage();
            }

            usleep(max(100, $pollIntervalMs) * 1000);
        } while (microtime(true) < $deadline);

        throw new RuntimeException(sprintf(
            'OpenClaw readiness check failed for [%s]. %s',
            $url,
            $lastError ? 'Last error: '.$lastError : 'The gateway never reported ready.',
        ));
    }

    /**
     * @param  list<string>  $command
     */
    private function run(array $command, bool $throwOnFailure = true): Process
    {
        $process = new Process($command, timeout: (int) config('sync360.openclaw.compose_timeout_seconds', 120));
        $process->run();

        if ($throwOnFailure && ! $process->isSuccessful()) {
            throw new ProcessFailedException($process);
        }

        return $process;
    }

    /**
     * @return list<string>
     */
    private function composeBaseCommand(Server $server, string $projectName, string $composeFile): array
    {
        $composeBin = preg_split('/\s+/', trim((string) ($server->docker_compose_bin ?: config('sync360.infrastructure.local_docker_compose_bin', 'docker compose')))) ?: ['docker', 'compose'];

        return [
            ...$composeBin,
            '--project-name',
            $projectName,
            '-f',
            $composeFile,
        ];
    }
}
