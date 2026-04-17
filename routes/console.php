<?php

use App\Contracts\DockerComposeRunner;
use App\Enums\TenantProvisioningStatus;
use App\Enums\TrialStatus;
use App\Models\Tenant;
use App\Models\Server;
use App\Services\LiteLlmTenantKeyService;
use App\Services\TenantAgentSyncService;
use App\Services\TenantRuntimeCapabilityService;
use App\Services\TenantProfileSyncService;
use App\Services\TenantGoogleWorkspaceSmokeTestService;
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

    /** @var TenantRuntimeCapabilityService $runtimeCapabilities */
    $runtimeCapabilities = app(TenantRuntimeCapabilityService::class);
    $results = $runtimeCapabilities->ensureInstalledOnServer($server);

    foreach ($results as $capabilityId => $status) {
        $this->components->twoColumnDetail(
            sprintf('Runtime capability [%s]', $capabilityId),
            $status,
        );
    }

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

// Run every 10 minutes as a safety net for any SyncReplyFromWorkspace jobs
// that failed all retries or were never dispatched.
// The command class lives in app/Console/Commands/SyncConversationReplies.php
// and is registered via withCommands() in bootstrap/app.php.
Schedule::command('sync360:sync-replies')->everyTenMinutes();

Artisan::command('sync360:resync-live-tenants {tenantSelector? : Tenant id, tenant_id, or slug. Omit to resync every live tenant}', function (?string $tenantSelector = null) {
    $tenants = Tenant::query()
        ->with(['server', 'businessProfile', 'businessProfileFiles', 'googleCredential'])
        ->where('agent_status', 'live')
        ->where('onboarding_status', 'complete')
        ->where('provisioning_status', TenantProvisioningStatus::Ready)
        ->whereNotNull('runtime_path')
        ->whereNotNull('workspace_url')
        ->when($tenantSelector, function ($query, string $selector): void {
            $query->where(function ($nested) use ($selector): void {
                if (ctype_digit($selector)) {
                    $nested->where('id', (int) $selector);
                }

                $nested->orWhere('tenant_id', $selector)
                    ->orWhere('slug', $selector);
            });
        })
        ->orderBy('id')
        ->get();

    if ($tenants->isEmpty()) {
        throw new RuntimeException('No matching live tenants were found for resync.');
    }

    /** @var TenantProfileSyncService $profileSync */
    $profileSync = app(TenantProfileSyncService::class);

    $completed = 0;
    $failed = 0;

    foreach ($tenants as $tenant) {
        try {
            $profileSync->regenerateAndSync($tenant);
            $completed++;

            $this->components->twoColumnDetail(
                sprintf('%s (%s)', $tenant->business_name, $tenant->slug),
                'Resynced successfully.',
            );
        } catch (Throwable $exception) {
            $failed++;

            Log::warning('sync360:resync-live-tenants failed for tenant.', [
                'tenant_id' => $tenant->tenant_id,
                'slug' => $tenant->slug,
                'error' => $exception->getMessage(),
            ]);

            $this->components->twoColumnDetail(
                sprintf('%s (%s)', $tenant->business_name, $tenant->slug),
                'Failed: '.$exception->getMessage(),
            );
        }
    }

    $this->components->info(sprintf(
        'Live tenant resync finished. Completed: %d. Failed: %d.',
        $completed,
        $failed,
    ));
})->purpose('Regenerate and resync workspace instructions for live tenants after prompt or sync changes');

Artisan::command('sync360:sync-runtime-capabilities {tenantSelector? : Tenant id, tenant_id, or slug. Omit to sync every ready tenant runtime} {capability? : Optional capability id, such as gog}', function (?string $tenantSelector = null, ?string $capability = null) {
    /** @var TenantRuntimeCapabilityService $runtimeCapabilities */
    $runtimeCapabilities = app(TenantRuntimeCapabilityService::class);
    $runtimeCapabilities->requiresSshInfrastructure();

    $selectedCapabilityIds = $capability ? [$capability] : $runtimeCapabilities->capabilityIds();
    $runtimeCapabilities->selectedDefinitions($selectedCapabilityIds);
    /** @var DockerComposeRunner $runner */
    $runner = app(DockerComposeRunner::class);

    $tenants = Tenant::query()
        ->with(['server', 'googleCredential'])
        ->where('provisioning_status', TenantProvisioningStatus::Ready)
        ->whereNotNull('runtime_path')
        ->whereNotNull('assigned_port')
        ->whereNotNull('server_id')
        ->when($tenantSelector, function ($query, string $selector): void {
            $query->where(function ($nested) use ($selector): void {
                if (ctype_digit($selector)) {
                    $nested->where('id', (int) $selector);
                }

                $nested->orWhere('tenant_id', $selector)
                    ->orWhere('slug', $selector);
            });
        })
        ->orderBy('id')
        ->get();

    if ($tenants->isEmpty()) {
        throw new RuntimeException('No matching ready tenants were found for runtime capability sync.');
    }

    $hostCapabilityResults = [];
    $completed = 0;
    $failed = 0;

    foreach ($tenants as $tenant) {
        try {
            if (! $tenant->server) {
                throw new RuntimeException('Tenant server is missing.');
            }

            $hostKey = sprintf('%d:%s', $tenant->server->id, implode(',', $selectedCapabilityIds));

            if (! isset($hostCapabilityResults[$hostKey])) {
                $hostCapabilityResults[$hostKey] = $runtimeCapabilities->ensureInstalledOnServer($tenant->server, $selectedCapabilityIds);
            }

            $composeUpdate = $runtimeCapabilities->syncLocalCompose($tenant, $selectedCapabilityIds);
            $configUpdate = $runtimeCapabilities->syncLocalOpenClawConfig($tenant, $selectedCapabilityIds);

            if ($composeUpdate['changed']) {
                $runner->putFile($tenant->server, $composeUpdate['remote_compose_file'], $composeUpdate['contents']);
            }

            if ($configUpdate['changed']) {
                $runner->putFile($tenant->server, $configUpdate['remote_config_file'], $configUpdate['contents']);
            }

            if ($composeUpdate['changed'] || $configUpdate['changed']) {
                $runtimeCapabilities->reloadRuntime($tenant, $composeUpdate['changed']);
            }

            $runtimeCapabilities->verifyHostCapabilities($tenant->server, $selectedCapabilityIds);
            $runtimeCapabilities->verifyContainerCapabilities($tenant, $selectedCapabilityIds);

            if (in_array('gog', $selectedCapabilityIds, true) && $tenant->googleCredential?->isConnected()) {
                app(TenantAgentSyncService::class)->verifyConnectedGoogleWorkspace($tenant->fresh(['server', 'googleCredential']));
            }

            $completed++;

            $this->components->twoColumnDetail(
                sprintf('%s (%s)', $tenant->business_name, $tenant->slug),
                sprintf(
                    'Capabilities synced. Host: %s. Compose changed: %s. Config changed: %s.',
                    implode(', ', array_map(
                        static fn (string $capabilityId, string $status): string => $capabilityId.'='.$status,
                        array_keys($hostCapabilityResults[$hostKey]),
                        array_values($hostCapabilityResults[$hostKey]),
                    )),
                    $composeUpdate['changed'] ? 'yes' : 'no',
                    $configUpdate['changed'] ? 'yes' : 'no',
                ),
            );
        } catch (Throwable $exception) {
            $failed++;

            if ($tenant->googleCredential?->isConnected() && in_array('gog', $selectedCapabilityIds, true)) {
                $tenant->googleCredential->forceFill([
                    'runtime_sync_status' => \App\Models\TenantGoogleCredential::RUNTIME_SYNC_FAILED,
                    'last_error' => $exception->getMessage(),
                ])->save();
            }

            Log::warning('sync360:sync-runtime-capabilities failed for tenant.', [
                'tenant_id' => $tenant->tenant_id,
                'slug' => $tenant->slug,
                'capabilities' => $selectedCapabilityIds,
                'error' => $exception->getMessage(),
            ]);

            $this->components->twoColumnDetail(
                sprintf('%s (%s)', $tenant->business_name, $tenant->slug),
                'Failed: '.$exception->getMessage(),
            );
        }
    }

    $this->components->info(sprintf(
        'Runtime capability sync finished. Completed: %d. Failed: %d.',
        $completed,
        $failed,
    ));
})->purpose('Install host-managed runtime capabilities and resync compose/config for ready tenant runtimes');

Artisan::command('sync360:test-google-workspace {tenantSelector : Tenant id, tenant_id, or slug}', function (string $tenantSelector) {
    $tenant = Tenant::query()
        ->with(['server', 'googleCredential'])
        ->where(function ($query) use ($tenantSelector): void {
            if (ctype_digit($tenantSelector)) {
                $query->where('id', (int) $tenantSelector);
            }

            $query->orWhere('tenant_id', $tenantSelector)
                ->orWhere('slug', $tenantSelector);
        })
        ->first();

    if (! $tenant) {
        throw new RuntimeException('No matching tenant was found for the Google Workspace smoke test.');
    }

    /** @var TenantGoogleWorkspaceSmokeTestService $smokeTests */
    $smokeTests = app(TenantGoogleWorkspaceSmokeTestService::class);
    $result = $smokeTests->run($tenant);
    app(TenantAgentSyncService::class)->clearKnownGoogleWorkspaceFailureMemory($tenant);

    $this->components->info(sprintf('Google Workspace smoke test passed for [%s].', $tenant->slug));
    $this->components->twoColumnDetail('Connected email', (string) ($result['google_email'] ?? 'unknown'));
    $this->components->twoColumnDetail('Compose file', (string) ($result['compose_file'] ?? 'unknown'));
    $this->components->twoColumnDetail('Expected XDG config home', (string) ($result['xdg_config_home_expected'] ?? 'unknown'));
    $this->components->twoColumnDetail('Host capability', ($result['host_capability_verified'] ?? false) ? 'verified' : 'not checked');
    $this->components->twoColumnDetail('Container binary', ($result['container_binary_verified'] ?? false) ? 'verified' : 'not checked');
    $this->components->twoColumnDetail('Runtime artifacts', ($result['runtime_artifacts_verified'] ?? false) ? 'verified' : 'missing');
    $this->components->twoColumnDetail('Container smoke', ($result['container_smoke_passed'] ?? false) ? 'passed' : 'failed');

    if (is_array($result['container_result'] ?? null)) {
        $containerResult = $result['container_result'];

        $this->components->twoColumnDetail('Container account', (string) ($containerResult['account'] ?? 'unknown'));
        $this->components->twoColumnDetail('Gmail address', (string) ($containerResult['gmail_email_address'] ?? 'unknown'));
        $this->components->twoColumnDetail('Gmail messages total', isset($containerResult['gmail_messages_total']) ? (string) $containerResult['gmail_messages_total'] : 'unknown');
        $this->components->twoColumnDetail('Calendars returned', isset($containerResult['calendar_items_returned']) ? (string) $containerResult['calendar_items_returned'] : 'unknown');
    }
})->purpose('Run a tenant-side Google Workspace smoke test against the mounted OpenClaw runtime auth');
