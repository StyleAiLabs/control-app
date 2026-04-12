<?php

use App\Contracts\DockerComposeRunner;
use App\Models\Tenant;
use App\Models\Server;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('sync360:bootstrap-client-vps {serverSelector? : Server id or name to prepare}', function (?string $serverSelector = null) {
    if (config('sync360.infrastructure.driver') !== 'ssh') {
        throw new \RuntimeException('Set SYNC360_INFRASTRUCTURE_DRIVER=ssh before bootstrapping a remote client VPS.');
    }

    $server = Server::query()
        ->when($serverSelector, function ($query, string $selector) {
            $query->where(function ($nested) use ($selector): void {
                if (ctype_digit($selector)) {
                    $nested->where('id', (int) $selector);
                }

                $nested->orWhere('name', $selector);
            });
        }, fn ($query) => $query->where('status', 'active')->orderBy('id'))
        ->first();

    if (! $server) {
        throw new \RuntimeException('No matching client VPS server record was found.');
    }

    /** @var DockerComposeRunner $runner */
    $runner = app(DockerComposeRunner::class);

    $runtimeRoot = rtrim((string) $server->runtime_root, '/');
    $caddySitesPath = rtrim((string) $server->caddy_sites_path, '/');
    $reloadCommand = (string) $server->caddy_reload_command;

    if ($runtimeRoot === '' || $caddySitesPath === '' || trim($reloadCommand) === '') {
        throw new \RuntimeException('The selected server is missing runtime_root, caddy_sites_path, or caddy_reload_command.');
    }

    $this->components->info(sprintf('Preparing client VPS [%s] at [%s]...', $server->name, $server->ssh_host ?: $server->host));

    $runner->runCommand($server, implode(' && ', [
        'command -v caddy >/dev/null 2>&1 || ('
            .'apt-get update'
            .' && apt-get install -y debian-keyring debian-archive-keyring apt-transport-https curl gnupg'
            .' && curl -1sLf https://dl.cloudsmith.io/public/caddy/stable/gpg.key | gpg --dearmor -o /usr/share/keyrings/caddy-stable-archive-keyring.gpg'
            .' && curl -1sLf https://dl.cloudsmith.io/public/caddy/stable/debian.deb.txt > /etc/apt/sources.list.d/caddy-stable.list'
            .' && chmod o+r /usr/share/keyrings/caddy-stable-archive-keyring.gpg'
            .' && chmod o+r /etc/apt/sources.list.d/caddy-stable.list'
            .' && apt-get update'
            .' && apt-get install -y caddy'
        .')',
    ]), sudo: true);
    $runner->runCommand($server, sprintf(
        'mkdir -p %s %s/tenants && chown -R %s:%s %s',
        escapeshellarg($runtimeRoot),
        escapeshellarg($runtimeRoot),
        escapeshellarg((string) $server->ssh_user),
        escapeshellarg((string) $server->ssh_user),
        escapeshellarg($runtimeRoot),
    ), sudo: true);
    $runner->runCommand($server, sprintf(
        'mkdir -p %s && chown -R %s:%s %s',
        escapeshellarg($caddySitesPath),
        escapeshellarg((string) $server->ssh_user),
        escapeshellarg((string) $server->ssh_user),
        escapeshellarg($caddySitesPath),
    ), sudo: true);
    $runner->runCommand($server, sprintf(
        'id -nG %1$s | grep -qw docker || usermod -aG docker %1$s',
        escapeshellarg((string) $server->ssh_user),
    ), sudo: true);
    $runner->runCommand($server, 'grep -Fq \'import /etc/caddy/sites/*.caddy\' /etc/caddy/Caddyfile || printf \'\\nimport /etc/caddy/sites/*.caddy\\n\' >> /etc/caddy/Caddyfile', sudo: true);
    $runner->runCommand($server, 'systemctl enable --now caddy', sudo: true);
    $runner->runCommand($server, $reloadCommand, sudo: true);
    $runner->runCommand($server, 'docker compose version');

    $this->components->info('Client VPS bootstrap completed successfully.');
})->purpose('Install and prepare Caddy/runtime directories on the remote client VPS');

Artisan::command('tenants:health-check', function () {
    /** @var \App\Services\TenantHealthCheckService $healthChecks */
    $healthChecks = app(\App\Services\TenantHealthCheckService::class);

    $tenants = Tenant::query()
        ->whereIn('agent_status', ['live', 'failed'])
        ->whereNotNull('workspace_url')
        ->whereNotNull('runtime_path')
        ->whereNotNull('server_id')
        ->orderBy('id')
        ->get();

    if ($tenants->isEmpty()) {
        $this->components->info('No live or failed tenants required a health check.');

        return;
    }

    $healthy = 0;
    $failed = 0;

    foreach ($tenants as $tenant) {
        $result = $healthChecks->check($tenant);

        if ($result['healthy']) {
            $healthy++;
        } else {
            $failed++;
        }

        $this->components->twoColumnDetail(
            sprintf('%s (%s)', $tenant->business_name, $tenant->tenant_id),
            $result['message'],
        );
    }

    $this->components->info(sprintf(
        'Health checks finished. Healthy: %d. Failed: %d.',
        $healthy,
        $failed,
    ));
})->purpose('Check live tenant workspaces and refresh their health status');

Schedule::command('tenants:health-check')->everyFiveMinutes();
