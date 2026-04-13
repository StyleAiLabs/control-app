<?php

use App\Contracts\DockerComposeRunner;
use App\Enums\TrialStatus;
use App\Models\Tenant;
use App\Models\Server;
use App\Services\LiteLlmTenantKeyService;
use App\Services\TrialNotificationEmailService;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
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

Artisan::command('sync360:check-trial-expiry', function () {
    /** @var LiteLlmTenantKeyService $litellm */
    $litellm = app(LiteLlmTenantKeyService::class);

    /** @var TrialNotificationEmailService $mailer */
    $mailer = app(TrialNotificationEmailService::class);

    $tenants = Tenant::query()
        ->where('trial_status', TrialStatus::Active)
        ->whereNotNull('litellm_virtual_key')
        ->orderBy('id')
        ->get();

    if ($tenants->isEmpty()) {
        $this->components->info('No active trial tenants to check.');

        return;
    }

    $expired = 0;
    $notified = 0;
    $errors = 0;

    foreach ($tenants as $tenant) {
        try {
            // 1. Refresh spend cache from LiteLLM
            $info = $litellm->getKeyInfo($tenant);
            $tenant->forceFill([
                'litellm_spend'           => $info['spend'],
                'litellm_spend_cached_at' => now(),
            ])->save();

            $spend     = (float) $info['spend'];
            $maxBudget = max(0.01, (float) ($tenant->litellm_max_budget ?? 5.0));

            // 2. Evaluate expiry conditions
            // Fall back to created_at + 14 days for tenants pre-dating the trial_ends_at column
            $budgetExpired = $spend >= $maxBudget;
            $trialEndsAt   = $tenant->trial_ends_at ?? $tenant->created_at->copy()->addDays(14);
            $timeExpired   = now()->gte($trialEndsAt);

            if ($budgetExpired || $timeExpired) {
                // 3. Expire the tenant
                $tenant->forceFill(['trial_status' => TrialStatus::Expired])->save();
                $litellm->suspendTenant($tenant);
                $expired++;

                if (! $tenant->trial_expired_notified_at) {
                    $reason = $budgetExpired ? 'budget' : 'time';
                    $mailer->sendTrialExpired($tenant, $reason);
                    $tenant->forceFill(['trial_expired_notified_at' => now()])->save();
                    $notified++;
                }

                $this->components->twoColumnDetail(
                    sprintf('%s (%s)', $tenant->business_name, $tenant->tenant_id),
                    $budgetExpired ? 'Expired: budget exhausted' : 'Expired: time limit reached',
                );

                continue;
            }

            // 4. Send threshold warnings (each only once)
            $budgetPct = $spend / $maxBudget * 100;
            $daysLeft  = $tenant->trialDaysLeft();

            if ($budgetPct >= 80 && ! $tenant->trial_80pct_notified_at) {
                $mailer->sendBudgetWarning($tenant, $spend, $maxBudget);
                $tenant->forceFill(['trial_80pct_notified_at' => now()])->save();
                $notified++;
            }

            if ($daysLeft <= 3 && ! $tenant->trial_3day_notified_at) {
                $mailer->sendExpiryWarning($tenant, $daysLeft);
                $tenant->forceFill(['trial_3day_notified_at' => now()])->save();
                $notified++;
            }

        } catch (Throwable $e) {
            $errors++;
            Log::warning('sync360:check-trial-expiry failed for tenant.', [
                'tenant_id' => $tenant->tenant_id,
                'error'     => $e->getMessage(),
            ]);
        }
    }

    $this->components->info(sprintf(
        'Trial check complete. Checked: %d. Expired: %d. Notified: %d. Errors: %d.',
        $tenants->count(),
        $expired,
        $notified,
        $errors,
    ));
})->purpose('Check trial expiry conditions and send lifecycle email notifications');

Schedule::command('sync360:check-trial-expiry')->everyThirtyMinutes();
