<?php

namespace App\Contracts;

use App\Models\Server;

interface DockerComposeRunner
{
    public function syncRuntime(Server $server, string $localRuntimePath, string $remoteRuntimePath): void;

    public function putFile(Server $server, string $remotePath, string $contents, bool $sudo = false): void;

    public function removeFile(Server $server, string $remotePath, bool $sudo = false): void;

    public function runCommand(Server $server, string $command, bool $sudo = false): void;

    public function up(Server $server, string $composeFile, string $projectName): void;

    public function down(Server $server, string $composeFile, string $projectName): void;

    public function start(Server $server, string $composeFile, string $projectName): void;

    public function stop(Server $server, string $composeFile, string $projectName): void;

    public function isRunning(Server $server, string $composeFile, string $projectName): bool;

    public function isHostPortInUse(Server $server, int $port): bool;

    public function waitForHttpReady(Server $server, string $url, int $timeoutSeconds, int $pollIntervalMs): void;
}
