<?php

namespace Tests\Feature;

use App\Contracts\DockerComposeRunner;
use App\Enums\TenantProvisioningStatus;
use App\Enums\TrialStatus;
use App\Jobs\ProcessInitialGoogleWorkspaceSync;
use App\Models\ProvisioningJob;
use App\Models\Server;
use App\Models\Tenant;
use App\Models\TenantGoogleCredential;
use App\Models\User;
use App\Services\TenantHealthCheckService;
use App\Services\TenantProfileSyncService;
use Illuminate\Support\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Tests\TestCase;

class AdminTenantOperationsTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_view_and_trigger_tenant_support_actions(): void
    {
        $admin = User::query()->create([
            'name' => 'Debug Admin',
            'email' => 'admin@example.com',
            'password' => 'super-secret',
            'is_admin' => true,
        ]);

        $user = User::query()->create([
            'name' => 'Customer User',
            'email' => 'customer@example.com',
            'password' => 'super-secret',
            'is_admin' => false,
        ]);

        $tenant = Tenant::query()->create([
            'tenant_id' => 'tenant_ops_01',
            'slug' => 'ops-shop',
            'business_name' => 'Ops Shop',
            'industry' => 'Retail',
            'skill_pack' => 'Client Support',
            'user_id' => $user->id,
            'server_id' => Server::query()->firstOrFail()->id,
            'trial_status' => TrialStatus::Active,
            'provisioning_status' => TenantProvisioningStatus::Ready,
            'agent_status' => 'live',
            'workspace_url' => 'https://ops-shop.workspace.test',
            'runtime_path' => '/srv/sync360/runtime/tenants/ops-shop',
            'last_health_check_status' => 'healthy',
            'health_check_message' => 'Workspace readiness check passed.',
        ]);

        $tenant->googleCredential()->create([
            'status' => TenantGoogleCredential::STATUS_CONNECTED,
            'runtime_sync_status' => TenantGoogleCredential::RUNTIME_SYNC_VERIFIED,
            'google_email' => 'owner@example.com',
            'access_token' => 'google-access-token',
            'refresh_token' => 'google-refresh-token',
            'scopes' => ['openid', 'email'],
            'connected_at' => now(),
        ]);

        $runner = Mockery::mock(DockerComposeRunner::class);
        $runner->shouldReceive('isRunning')
            ->times(4)
            ->withArgs(fn (Server $server, string $composeFile, string $projectName): bool => $composeFile === '/srv/sync360/runtime/tenants/ops-shop/compose.yaml' && $projectName === 'sync360-ops-shop')
            ->andReturnTrue();
        $runner->shouldReceive('stop')
            ->once()
            ->withArgs(fn (Server $server, string $composeFile, string $projectName): bool => $composeFile === '/srv/sync360/runtime/tenants/ops-shop/compose.yaml' && $projectName === 'sync360-ops-shop')
            ->andReturnNull();
        $runner->shouldReceive('start')
            ->once()
            ->withArgs(fn (Server $server, string $composeFile, string $projectName): bool => $composeFile === '/srv/sync360/runtime/tenants/ops-shop/compose.yaml' && $projectName === 'sync360-ops-shop')
            ->andReturnNull();

        $healthChecks = Mockery::mock(TenantHealthCheckService::class);
        $healthChecks->shouldReceive('check')
            ->once()
            ->withArgs(fn (Tenant $checkedTenant): bool => $checkedTenant->is($tenant))
            ->andReturn([
                'healthy' => true,
                'status' => 'healthy',
                'message' => 'Workspace readiness check passed.',
                'workspace_state' => 'running',
            ]);

        $profileSync = Mockery::mock(TenantProfileSyncService::class);
        $profileSync->shouldReceive('regenerateAndSync')
            ->once()
            ->withArgs(fn (Tenant $resyncedTenant): bool => $resyncedTenant->is($tenant))
            ->andReturnNull();

        $this->instance(DockerComposeRunner::class, $runner);
        $this->instance(TenantHealthCheckService::class, $healthChecks);
        $this->instance(TenantProfileSyncService::class, $profileSync);

        Artisan::shouldReceive('call')
            ->once()
            ->with('sync360:bootstrap-client-vps', [
                'serverSelector' => (string) $tenant->server_id,
            ])
            ->andReturn(0);
        Artisan::shouldReceive('output')
            ->once()
            ->andReturn('Runtime capability [gog] installed');

        Artisan::shouldReceive('call')
            ->once()
            ->with('sync360:sync-runtime-capabilities', [
                'tenantSelector' => $tenant->slug,
            ])
            ->andReturn(0);
        Artisan::shouldReceive('output')
            ->once()
            ->andReturn('Runtime capability sync finished. Completed: 1. Failed: 0.');

        Artisan::shouldReceive('call')
            ->once()
            ->with('sync360:test-google-workspace', [
                'tenantSelector' => $tenant->slug,
            ])
            ->andReturn(0);
        Artisan::shouldReceive('output')
            ->once()
            ->andReturn('Google Workspace smoke test passed for [ops-shop]. Host capability verified. Container binary verified.');

        $this->actingAs($admin);

        $this->get('/admin/tenants')
            ->assertOk()
            ->assertSee('Ops Shop')
            ->assertSee(route('admin.tenants.show', $tenant), false)
            ->assertDontSee('Permanent Delete');

        $this->get(route('admin.tenants.show', ['tenant' => $tenant, 'tab' => 'support']))
            ->assertOk()
            ->assertSee('Health Check')
            ->assertSee('Resync Agent')
            ->assertSee('Bootstrap VPS')
            ->assertSee('Restart')
            ->assertSee('healthy')
            ->assertSee('Permanent Delete');

        $this->get(route('admin.tenants.show', ['tenant' => $tenant, 'tab' => 'overview']))
            ->assertOk()
            ->assertSee('Add 7 Days')
            ->assertSee('class="badge badge--technical', false)
            ->assertSee('class="type-value type-value--technical"', false)
            ->assertSee('class="type-label"', false);

        $this->get(route('admin.tenants.show', ['tenant' => $tenant, 'tab' => 'google']))
            ->assertOk()
            ->assertSee('Sync Runtime Capabilities')
            ->assertSee('Test Google Workspace');

        $this->post(route('admin.tenants.health-check', $tenant))
            ->assertRedirect(route('admin.tenants.show', ['tenant' => $tenant, 'tab' => 'support']))
            ->assertSessionHas('status', 'Workspace readiness check passed.');

        $this->post(route('admin.tenants.resync-agent', $tenant))
            ->assertRedirect(route('admin.tenants.show', ['tenant' => $tenant, 'tab' => 'support']))
            ->assertSessionHas('status', 'Agent resync requested.');

        $this->post(route('admin.tenants.runtime.bootstrap', $tenant))
            ->assertRedirect(route('admin.tenants.show', ['tenant' => $tenant, 'tab' => 'support']))
            ->assertSessionHas('status', 'Client VPS bootstrap finished for test-vps. Runtime capability [gog] installed');

        $this->post(route('admin.tenants.runtime-capabilities.sync', $tenant))
            ->assertRedirect(route('admin.tenants.show', ['tenant' => $tenant, 'tab' => 'google']))
            ->assertSessionHas('status', 'Runtime capability sync finished for ops-shop. Runtime capability sync finished. Completed: 1. Failed: 0.');

        $this->post(route('admin.tenants.google.test', $tenant))
            ->assertRedirect(route('admin.tenants.show', ['tenant' => $tenant, 'tab' => 'google']))
            ->assertSessionHas('status', 'Google Workspace smoke test passed for ops-shop. Google Workspace smoke test passed for [ops-shop]. Host capability verified. Container binary verified.');

        $this->post(route('admin.workspace.restart', $tenant))
            ->assertRedirect(route('admin.tenants.show', ['tenant' => $tenant, 'tab' => 'support']))
            ->assertSessionHas('status', 'Workspace container restart requested.');
    }

    public function test_admin_tenant_pages_show_google_sync_details_and_allow_requeue(): void
    {
        Queue::fake([ProcessInitialGoogleWorkspaceSync::class]);

        $admin = User::query()->create([
            'name' => 'Debug Admin',
            'email' => 'admin@example.com',
            'password' => 'super-secret',
            'is_admin' => true,
        ]);

        $user = User::query()->create([
            'name' => 'Customer User',
            'email' => 'customer@example.com',
            'password' => 'super-secret',
            'is_admin' => false,
        ]);

        $tenant = Tenant::query()->create([
            'tenant_id' => 'tenant_ops_02',
            'slug' => 'google-ops-shop',
            'business_name' => 'Google Ops Shop',
            'industry' => 'Retail',
            'skill_pack' => 'Client Support',
            'user_id' => $user->id,
            'server_id' => Server::query()->firstOrFail()->id,
            'trial_status' => TrialStatus::Active,
            'provisioning_status' => TenantProvisioningStatus::Ready,
            'agent_status' => 'live',
            'workspace_url' => 'https://google-ops-shop.workspace.test',
            'runtime_path' => '/srv/sync360/runtime/tenants/google-ops-shop',
        ]);

        $connectedAt = Carbon::parse('2026-04-18 09:15:00');
        $lastSyncedAt = Carbon::parse('2026-04-18 10:45:00');
        $jobStartedAt = Carbon::parse('2026-04-18 10:30:00');
        $jobCompletedAt = Carbon::parse('2026-04-18 10:34:00');

        $tenant->googleCredential()->create([
            'status' => TenantGoogleCredential::STATUS_CONNECTED,
            'runtime_sync_status' => TenantGoogleCredential::RUNTIME_SYNC_FAILED,
            'google_email' => 'owner@example.com',
            'access_token' => 'google-access-token',
            'refresh_token' => 'google-refresh-token',
            'scopes' => ['openid', 'email'],
            'connected_at' => $connectedAt,
            'last_synced_at' => $lastSyncedAt,
            'last_error' => 'Refresh token exchange failed inside the tenant runtime.',
        ]);

        ProvisioningJob::query()->create([
            'tenant_id' => $tenant->id,
            'job_type' => ProcessInitialGoogleWorkspaceSync::JOB_TYPE,
            'status' => 'failed',
            'error_message' => 'gmail-cli failed: missing required query argument.',
            'started_at' => $jobStartedAt,
            'completed_at' => $jobCompletedAt,
        ]);

        $runner = Mockery::mock(DockerComposeRunner::class);
        $runner->shouldReceive('isRunning')
            ->twice()
            ->withArgs(fn (Server $server, string $composeFile, string $projectName): bool => $composeFile === '/srv/sync360/runtime/tenants/google-ops-shop/compose.yaml' && $projectName === 'sync360-google-ops-shop')
            ->andReturnTrue();

        $this->instance(DockerComposeRunner::class, $runner);
        $this->actingAs($admin);

        $this->get('/admin/tenants')
            ->assertOk()
            ->assertSee('Google')
            ->assertSee('owner@example.com')
            ->assertSee('Needs attention')
            ->assertSee('Refresh token exchange failed inside the tenant runtime.');

        $this->get(route('admin.tenants.show', ['tenant' => $tenant, 'tab' => 'google']))
            ->assertOk()
            ->assertSee('Google Workspace Connection')
            ->assertSee('owner@example.com')
            ->assertSee('Needs attention')
            ->assertSee('2026-04-18 09:15:00')
            ->assertSee('2026-04-18 10:45:00')
            ->assertSee('gmail-cli failed: missing required query argument.')
            ->assertSee('Queue Google Sync');

        $this->post(route('admin.tenants.google.sync', $tenant))
            ->assertRedirect(route('admin.tenants.show', ['tenant' => $tenant, 'tab' => 'google']))
            ->assertSessionHas('status', 'Google Workspace sync queued for google-ops-shop.');

        Queue::assertPushed(ProcessInitialGoogleWorkspaceSync::class, function (ProcessInitialGoogleWorkspaceSync $job) use ($tenant): bool {
            return $job->tenantId === $tenant->id;
        });

        $queuedJob = ProvisioningJob::query()
            ->where('tenant_id', $tenant->id)
            ->where('job_type', ProcessInitialGoogleWorkspaceSync::JOB_TYPE)
            ->latest('id')
            ->first();

        $this->assertNotNull($queuedJob);
        $this->assertSame('queued', $queuedJob?->status?->value);
    }

    public function test_admin_can_extend_active_tenant_trial_by_seven_days(): void
    {
        Carbon::setTestNow('2026-04-25 10:00:00');

        $admin = User::query()->create([
            'name' => 'Debug Admin',
            'email' => 'admin@example.com',
            'password' => 'super-secret',
            'is_admin' => true,
        ]);

        $user = User::query()->create([
            'name' => 'Customer User',
            'email' => 'customer@example.com',
            'password' => 'super-secret',
            'is_admin' => false,
        ]);

        $tenant = Tenant::query()->create([
            'tenant_id' => 'tenant_trial_extend_01',
            'slug' => 'trial-extend-shop',
            'business_name' => 'Trial Extend Shop',
            'industry' => 'Retail',
            'skill_pack' => 'Client Support',
            'user_id' => $user->id,
            'server_id' => Server::query()->firstOrFail()->id,
            'trial_status' => TrialStatus::Active,
            'trial_ends_at' => Carbon::parse('2026-04-30 10:00:00'),
            'provisioning_status' => TenantProvisioningStatus::Ready,
        ]);

        $this->actingAs($admin);

        $this->post(route('admin.tenants.trial.extend', $tenant), [
            'return_tab' => 'overview',
        ])->assertRedirect(route('admin.tenants.show', ['tenant' => $tenant, 'tab' => 'overview']))
            ->assertSessionHas('status', 'Extended trial by 7 days. New end date: 2026-05-07 10:00:00.');

        $tenant->refresh();

        $this->assertSame(TrialStatus::Active, $tenant->trial_status);
        $this->assertSame('2026-05-07 10:00:00', $tenant->trial_ends_at?->toDateTimeString());

        Carbon::setTestNow();
    }

    public function test_admin_can_reactivate_expired_trial_for_seven_days_and_reset_expiry_warnings(): void
    {
        Carbon::setTestNow('2026-04-25 10:00:00');

        $admin = User::query()->create([
            'name' => 'Debug Admin',
            'email' => 'admin@example.com',
            'password' => 'super-secret',
            'is_admin' => true,
        ]);

        $user = User::query()->create([
            'name' => 'Customer User',
            'email' => 'customer@example.com',
            'password' => 'super-secret',
            'is_admin' => false,
        ]);

        $tenant = Tenant::query()->create([
            'tenant_id' => 'tenant_trial_extend_02',
            'slug' => 'expired-trial-shop',
            'business_name' => 'Expired Trial Shop',
            'industry' => 'Retail',
            'skill_pack' => 'Client Support',
            'user_id' => $user->id,
            'server_id' => Server::query()->firstOrFail()->id,
            'trial_status' => TrialStatus::Expired,
            'trial_ends_at' => Carbon::parse('2026-04-20 10:00:00'),
            'trial_3day_notified_at' => Carbon::parse('2026-04-17 10:00:00'),
            'trial_expired_notified_at' => Carbon::parse('2026-04-20 10:30:00'),
            'provisioning_status' => TenantProvisioningStatus::Ready,
        ]);

        $this->actingAs($admin);

        $this->post(route('admin.tenants.trial.extend', $tenant), [
            'return_tab' => 'overview',
        ])->assertRedirect(route('admin.tenants.show', ['tenant' => $tenant, 'tab' => 'overview']))
            ->assertSessionHas('status', 'Extended trial by 7 days. New end date: 2026-05-02 10:00:00.');

        $tenant->refresh();

        $this->assertSame(TrialStatus::Active, $tenant->trial_status);
        $this->assertSame('2026-05-02 10:00:00', $tenant->trial_ends_at?->toDateTimeString());
        $this->assertNull($tenant->trial_3day_notified_at);
        $this->assertNull($tenant->trial_expired_notified_at);

        Carbon::setTestNow();
    }
}
