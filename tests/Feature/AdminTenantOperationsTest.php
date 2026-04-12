<?php

namespace Tests\Feature;

use App\Contracts\DockerComposeRunner;
use App\Enums\TenantProvisioningStatus;
use App\Enums\TrialStatus;
use App\Models\Server;
use App\Models\Tenant;
use App\Models\User;
use App\Services\TenantHealthCheckService;
use App\Services\TenantProfileSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
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

        $runner = Mockery::mock(DockerComposeRunner::class);
        $runner->shouldReceive('isRunning')
            ->twice()
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

        $this->actingAs($admin);

        $this->get('/admin/tenants')
            ->assertOk()
            ->assertSee('Ops Shop')
            ->assertSee(route('admin.tenants.show', $tenant), false)
            ->assertDontSee('Permanent Delete');

        $this->get(route('admin.tenants.show', $tenant))
            ->assertOk()
            ->assertSee('Health Check')
            ->assertSee('Resync Agent')
            ->assertSee('Restart')
            ->assertSee('healthy')
            ->assertSee('Permanent Delete');

        $this->post(route('admin.tenants.health-check', $tenant))
            ->assertRedirect()
            ->assertSessionHas('status', 'Workspace readiness check passed.');

        $this->post(route('admin.tenants.resync-agent', $tenant))
            ->assertRedirect()
            ->assertSessionHas('status', 'Agent resync requested.');

        $this->post(route('admin.workspace.restart', $tenant))
            ->assertRedirect()
            ->assertSessionHas('status', 'Workspace container restart requested.');
    }
}
