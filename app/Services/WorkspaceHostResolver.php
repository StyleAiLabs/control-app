<?php

namespace App\Services;

use App\Models\Server;
use App\Models\Tenant;

class WorkspaceHostResolver
{
    public function resolveTenantForHost(?string $host): ?Tenant
    {
        $normalizedHost = $this->normalizeHost($host);

        if ($normalizedHost === null) {
            return null;
        }

        foreach ($this->workspaceDomains() as $serverId => $baseDomain) {
            if (! str_ends_with($normalizedHost, '.'.$baseDomain)) {
                continue;
            }

            $slug = substr($normalizedHost, 0, -strlen('.'.$baseDomain));

            if ($slug === false || $slug === '' || str_contains($slug, '.')) {
                continue;
            }

            return Tenant::query()
                ->with('server')
                ->where('server_id', $serverId)
                ->where('slug', $slug)
                ->first();
        }

        return null;
    }

    public function tenantMatchesHost(Tenant $tenant, ?string $host): bool
    {
        $normalizedHost = $this->normalizeHost($host);
        $workspaceHost = strtolower(trim((string) $tenant->workspaceHost(), '.'));

        return $normalizedHost !== null
            && $workspaceHost !== ''
            && $normalizedHost === $workspaceHost;
    }

    /**
     * @return array<int, string>
     */
    private function workspaceDomains(): array
    {
        return Server::query()
            ->whereNotNull('workspace_base_domain')
            ->pluck('workspace_base_domain', 'id')
            ->mapWithKeys(fn (mixed $domain, mixed $id): array => [(int) $id => strtolower(trim((string) $domain, '.'))])
            ->filter(fn (string $domain): bool => $domain !== '')
            ->all();
    }

    private function normalizeHost(?string $host): ?string
    {
        $normalizedHost = strtolower(trim((string) $host, '.'));

        return $normalizedHost !== '' ? $normalizedHost : null;
    }
}
