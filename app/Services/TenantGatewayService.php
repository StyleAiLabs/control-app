<?php

namespace App\Services;

use App\Contracts\DockerComposeRunner;
use App\Models\Tenant;
use RuntimeException;

class TenantGatewayService
{
    public function __construct(
        private readonly DockerComposeRunner $dockerCompose,
        private readonly TenantRuntimeService $runtime,
    ) {
    }

    /**
     * @param  array<string, mixed>|null  $json
     * @return array{status:int, body:string}
     */
    /**
     * @param  array<string, string>  $headers
     * @return array{status:int, body:string}
     */
    public function request(Tenant $tenant, string $method, string $path, ?array $json = null, int $timeoutSeconds = 15, array $headers = []): array
    {
        $tenant->loadMissing('server');

        if (! $tenant->server) {
            throw new RuntimeException('Tenant server is missing for the private gateway request.');
        }

        $url = rtrim($this->runtime->gatewayBaseUrl($tenant), '/').'/'.ltrim($path, '/');

        return $this->dockerCompose->httpRequest($tenant->server, $method, $url, $json, $timeoutSeconds, $headers);
    }
}
