<?php

namespace App\Contracts;

interface DockerComposeRunner
{
    public function up(string $composeFile, string $projectName): void;

    public function down(string $composeFile, string $projectName): void;

    public function start(string $composeFile, string $projectName): void;

    public function stop(string $composeFile, string $projectName): void;

    public function isRunning(string $composeFile, string $projectName): bool;

    public function isHostPortInUse(int $port): bool;
}
