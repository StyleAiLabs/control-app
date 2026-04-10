<?php

namespace App\Services;

use App\Contracts\DockerComposeRunner;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Process;

class LocalDockerComposeRunner implements DockerComposeRunner
{
    public function up(string $composeFile, string $projectName): void
    {
        $this->run([
            'docker-compose',
            '--project-name',
            $projectName,
            '-f',
            $composeFile,
            'up',
            '-d',
        ]);
    }

    public function down(string $composeFile, string $projectName): void
    {
        $this->run([
            'docker-compose',
            '--project-name',
            $projectName,
            '-f',
            $composeFile,
            'down',
            '--remove-orphans',
        ]);
    }

    public function start(string $composeFile, string $projectName): void
    {
        $this->run([
            'docker-compose',
            '--project-name',
            $projectName,
            '-f',
            $composeFile,
            'start',
        ]);
    }

    public function stop(string $composeFile, string $projectName): void
    {
        $this->run([
            'docker-compose',
            '--project-name',
            $projectName,
            '-f',
            $composeFile,
            'stop',
        ]);
    }

    public function isRunning(string $composeFile, string $projectName): bool
    {
        $process = $this->run([
            'docker-compose',
            '--project-name',
            $projectName,
            '-f',
            $composeFile,
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

    public function isHostPortInUse(int $port): bool
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
}
