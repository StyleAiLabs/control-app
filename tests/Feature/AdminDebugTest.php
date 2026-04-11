<?php

namespace Tests\Feature;

use App\Contracts\DockerComposeRunner;
use App\Enums\ProvisioningJobStatus;
use App\Enums\TenantProvisioningStatus;
use App\Enums\TrialStatus;
use App\Jobs\ProcessTenantProvisioning;
use App\Models\ProvisioningJob;
use App\Models\Server;
use App\Models\Tenant;
use App\Models\User;
use App\Services\ControlAppDeploymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Tests\TestCase;

class AdminDebugTest extends TestCase
{
    use RefreshDatabase;

    public function test_non_admin_users_cannot_access_admin_pages(): void
    {
        $user = User::query()->create([
            'name' => 'Standard User',
            'email' => 'standard@example.com',
            'password' => 'super-secret',
            'is_admin' => false,
        ]);

        $this->actingAs($user);

        $this->get('/admin')->assertForbidden();
        $this->get('/admin/users')->assertForbidden();
        $this->get('/admin/tenants')->assertForbidden();
        $this->get('/admin/jobs')->assertForbidden();
        $this->post('/admin/deploy/control-app')->assertForbidden();
    }

    public function test_admin_pages_list_users_tenants_jobs_and_allow_retry(): void
    {
        Queue::fake();

        $admin = User::query()->create([
            'name' => 'Debug Admin',
            'email' => 'admin@example.com',
            'password' => 'super-secret',
            'is_admin' => true,
        ]);

        $user = User::query()->create([
            'name' => 'Debug User',
            'email' => 'debug@example.com',
            'password' => 'super-secret',
            'is_admin' => false,
        ]);

        $tenant = Tenant::query()->create([
            'tenant_id' => 'tenant_02',
            'slug' => 'debug-shop',
            'business_name' => 'Debug Shop',
            'industry' => 'Retail',
            'skill_pack' => 'Client Support',
            'user_id' => $user->id,
            'server_id' => Server::query()->firstOrFail()->id,
            'trial_status' => TrialStatus::Active,
            'provisioning_status' => TenantProvisioningStatus::Failed,
            'assigned_port' => 4101,
            'workspace_url' => 'https://debug-shop.workspace.test',
            'runtime_path' => '/srv/sync360/runtime/tenants/debug-shop',
        ]);

        $job = ProvisioningJob::query()->create([
            'tenant_id' => $tenant->id,
            'job_type' => 'provision_tenant',
            'status' => ProvisioningJobStatus::Failed,
            'error_message' => 'Provisioner exploded.',
        ]);

        $runner = Mockery::mock(DockerComposeRunner::class);
        $runner->shouldReceive('isRunning')
            ->once()
            ->withArgs(fn (Server $server, string $composeFile, string $projectName): bool => $server->name === 'test-vps' && $composeFile === '/srv/sync360/runtime/tenants/debug-shop/compose.yaml' && $projectName === 'sync360-debug-shop')
            ->andReturnTrue();
        $runner->shouldReceive('start')
            ->once()
            ->withArgs(fn (Server $server, string $composeFile, string $projectName): bool => $server->name === 'test-vps' && $composeFile === '/srv/sync360/runtime/tenants/debug-shop/compose.yaml' && $projectName === 'sync360-debug-shop')
            ->andReturnNull();
        $runner->shouldReceive('stop')
            ->once()
            ->withArgs(fn (Server $server, string $composeFile, string $projectName): bool => $server->name === 'test-vps' && $composeFile === '/srv/sync360/runtime/tenants/debug-shop/compose.yaml' && $projectName === 'sync360-debug-shop')
            ->andReturnNull();

        $deployService = Mockery::mock(ControlAppDeploymentService::class);
        $deployService->shouldReceive('status')
            ->once()
            ->andReturn([
                'enabled' => true,
                'configured' => true,
                'host' => '161.97.74.128',
                'user' => 'deploy',
                'branch' => 'codex/control-app-prod-deploy',
                'state' => 'idle',
                'started_at' => null,
                'finished_at' => null,
                'message' => 'Ready to deploy.',
                'log_tail' => '',
            ]);
        $deployService->shouldReceive('trigger')
            ->once()
            ->andReturn('12345');

        $this->instance(DockerComposeRunner::class, $runner);
        $this->instance(ControlAppDeploymentService::class, $deployService);

        $this->actingAs($admin);

        $this->get('/admin')
            ->assertOk()
            ->assertSee('Admin Overview')
            ->assertSee('Control App Deploy')
            ->assertSee('codex/control-app-prod-deploy');

        $this->get('/admin/users')
            ->assertOk()
            ->assertSee('Debug Admin')
            ->assertSee('debug@example.com');

        $this->get('/admin/tenants')
            ->assertOk()
            ->assertSee('Debug Shop')
            ->assertSee('Provisioner exploded.')
            ->assertSee('running');

        $this->get('/admin/jobs')
            ->assertOk()
            ->assertSee((string) $job->id)
            ->assertSee('provision_tenant');

        $this->post(route('admin.workspace.start', $tenant))
            ->assertRedirect()
            ->assertSessionHas('status', 'Workspace container start requested.');

        $this->post(route('admin.workspace.stop', $tenant))
            ->assertRedirect()
            ->assertSessionHas('status', 'Workspace container stop requested.');

        $response = $this->post(route('admin.retry', $tenant));

        $response->assertRedirect();
        $response->assertSessionHas('status', 'Provisioning retry queued.');
        $this->assertDatabaseCount('provisioning_jobs', 2);

        $retriedJob = ProvisioningJob::query()->latest('id')->first();

        $this->assertSame(ProvisioningJobStatus::Queued, $retriedJob->status);

        Queue::assertPushed(ProcessTenantProvisioning::class, function (ProcessTenantProvisioning $queuedJob) use ($tenant, $retriedJob): bool {
            return $queuedJob->tenantId === $tenant->id
                && $queuedJob->provisioningJobId === $retriedJob->id;
        });

        $this->post(route('admin.deploy.control-app'))
            ->assertRedirect()
            ->assertSessionHas('status', 'Control app deploy started. Remote process id: 12345');
    }
}
