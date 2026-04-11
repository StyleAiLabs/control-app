<?php

namespace App\Services;

use App\Models\Server;
use RuntimeException;

class ServerPlacementService
{
    public function selectServer(): Server
    {
        $query = Server::query()
            ->where('status', 'active')
            ->whereColumn('current_clients', '<', 'max_clients')
            ->whereNotNull('runtime_root');

        if (config('sync360.infrastructure.driver') === 'ssh') {
            $query
                ->whereNotNull('ssh_host')
                ->whereNotNull('ssh_user')
                ->whereNotNull('workspace_scheme');

            if (config('sync360.provisioning.driver') === 'openclaw') {
                $query
                    ->whereNotNull('workspace_base_domain')
                    ->whereNotNull('caddy_sites_path')
                    ->whereNotNull('caddy_reload_command');
            }
        }

        $server = $query
            ->orderBy('current_clients')
            ->orderBy('id')
            ->first();

        if (! $server) {
            throw new RuntimeException('No active client VPS is currently available for tenant provisioning.');
        }

        return $server;
    }
}
