<?php

namespace App\Contracts;

use App\Models\Server;

interface DockerComposeRunner
{
    public function syncRuntime(Server $server, string $localRuntimePath, string $remoteRuntimePath): void;

    /**
     * Sync ONLY tenant workspace artifacts (generated markdown, machine-readable
     * JSON, materialized skill files, and tenant-scoped assets) to the remote
     * .openclaw/workspace/ directory WITHOUT touching compose.yaml,
     * config/openclaw.json, or any other credential/config files in the runtime root.
     */
    public function syncWorkspaceFiles(Server $server, string $localWorkspacePath, string $remoteWorkspacePath): void;

    /**
     * @param  array<string, mixed>|null  $json
     * @return array{status:int, body:string}
     */
    /**
     * @param  array<string, string>  $headers
     */
    public function httpRequest(Server $server, string $method, string $url, ?array $json = null, int $timeoutSeconds = 15, array $headers = []): array;

    public function putFile(Server $server, string $remotePath, string $contents, bool $sudo = false): void;

    public function removeFile(Server $server, string $remotePath, bool $sudo = false): void;

    public function removeDirectory(Server $server, string $remotePath, bool $sudo = false): void;

    public function runCommand(Server $server, string $command, bool $sudo = false): void;

    public function up(Server $server, string $composeFile, string $projectName): void;

    public function down(Server $server, string $composeFile, string $projectName): void;

    public function start(Server $server, string $composeFile, string $projectName): void;

    public function stop(Server $server, string $composeFile, string $projectName): void;

    public function isRunning(Server $server, string $composeFile, string $projectName): bool;

    public function isHostPortInUse(Server $server, int $port): bool;

    public function waitForHttpReady(Server $server, string $url, int $timeoutSeconds, int $pollIntervalMs): void;
}
