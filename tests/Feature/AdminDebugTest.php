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
use Illuminate\Support\Facades\Config;
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
                'primary_server' => 'deploy@161.97.74.128',
                'branch' => 'codex/control-app-prod-deploy',
                'state' => 'idle',
                'started_at' => null,
                'finished_at' => null,
                'message' => 'Ready to deploy.',
                'latest_commit_full' => '41dd63341dd63341dd63341dd63341dd63341dd',
                'latest_commit_short' => '41dd633',
                'latest_commit_subject' => 'Fix control app deploy shell commands',
                'branch_head_commit_full' => 'cb8c399cb8c399cb8c399cb8c399cb8c399cb8c',
                'branch_head_commit_short' => 'cb8c399',
                'is_up_to_date' => false,
                'log_tail' => '',
            ]);
        $deployService->shouldReceive('status')
            ->once()
            ->andReturn([
                'enabled' => true,
                'configured' => true,
                'host' => '161.97.74.128',
                'user' => 'deploy',
                'primary_server' => 'deploy@161.97.74.128',
                'branch' => 'codex/control-app-prod-deploy',
                'state' => 'running',
                'started_at' => '2026-04-11T07:09:46Z',
                'finished_at' => null,
                'message' => 'Deployment started.',
                'latest_commit_full' => 'cb8c399cb8c399cb8c399cb8c399cb8c399cb8c',
                'latest_commit_short' => '41dd633',
                'latest_commit_subject' => 'Fix control app deploy shell commands',
                'branch_head_commit_full' => 'cb8c399cb8c399cb8c399cb8c399cb8c399cb8c',
                'branch_head_commit_short' => 'cb8c399',
                'is_up_to_date' => true,
                'log_tail' => 'Starting control app deployment...',
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
            ->assertSee('codex/control-app-prod-deploy')
            ->assertSee('deploy@161.97.74.128')
            ->assertSee('Fix control app deploy shell commands')
            ->assertSee('Fetch Latest And Deploy');

        $this->get(route('admin.deploy.control-app.status'))
            ->assertOk()
            ->assertJson([
                'primary_server' => 'deploy@161.97.74.128',
                'latest_commit_short' => '41dd633',
                'latest_commit_subject' => 'Fix control app deploy shell commands',
                'state' => 'running',
                'is_up_to_date' => true,
            ]);

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

    public function test_admin_page_stays_available_when_control_app_deploy_status_is_misconfigured(): void
    {
        $admin = User::query()->create([
            'name' => 'Debug Admin',
            'email' => 'admin@example.com',
            'password' => 'super-secret',
            'is_admin' => true,
        ]);

        Config::set('sync360.control_app_deploy.enabled', true);
        Config::set('sync360.control_app_deploy.ssh_host', '161.97.74.128');
        Config::set('sync360.control_app_deploy.ssh_user', 'deploy');
        Config::set('sync360.control_app_deploy.branch', 'codex/control-app-prod-deploy');
        Config::set('sync360.control_app_deploy.repo_path', '/root/sync360/control-app');
        Config::set('sync360.control_app_deploy.compose_file', 'docker-compose.prod.yml');
        Config::set('sync360.control_app_deploy.script_path', '/root/sync360/control-app/deploy/scripts/run-control-app-deploy.sh');
        Config::set('sync360.control_app_deploy.status_file', '/root/sync360/control-app/storage/logs/control-app-deploy.status');
        Config::set('sync360.control_app_deploy.log_file', '/root/sync360/control-app/storage/logs/control-app-deploy.log');
        Config::set('sync360.control_app_deploy.ssh_auth_mode', 'password');
        Config::set('sync360.control_app_deploy.ssh_password_env_key', 'SYNC360_MISSING_PASSWORD');

        $this->actingAs($admin);

        $this->get('/admin')
            ->assertOk()
            ->assertSee('Control App Deploy')
            ->assertSee('failed')
            ->assertSee('SYNC360_MISSING_PASSWORD');
    }

    public function test_admin_page_shows_up_to_date_when_deployed_commit_matches_branch_tip(): void
    {
        $admin = User::query()->create([
            'name' => 'Deploy Admin',
            'email' => 'deploy-admin@example.com',
            'password' => 'super-secret',
            'is_admin' => true,
        ]);

        $deployService = Mockery::mock(ControlAppDeploymentService::class);
        $deployService->shouldReceive('status')
            ->once()
            ->andReturn([
                'enabled' => true,
                'configured' => true,
                'host' => '161.97.74.128',
                'user' => 'serveradmin',
                'primary_server' => 'serveradmin@161.97.74.128',
                'branch' => 'codex/control-app-prod-deploy',
                'state' => 'succeeded',
                'started_at' => '2026-04-11T09:25:18Z',
                'finished_at' => '2026-04-11T09:25:55Z',
                'message' => 'Deployment completed successfully.',
                'latest_commit_full' => 'cb8c399954dfb41963b8c5a2625ab06c7d326021',
                'latest_commit_short' => 'cb8c399',
                'latest_commit_subject' => 'Fix deployed commit status tracking',
                'branch_head_commit_full' => 'cb8c399954dfb41963b8c5a2625ab06c7d326021',
                'branch_head_commit_short' => 'cb8c399',
                'is_up_to_date' => true,
                'log_tail' => 'Control app deployment completed successfully.',
            ]);

        $this->instance(ControlAppDeploymentService::class, $deployService);
        $this->actingAs($admin);

        $this->get('/admin')
            ->assertOk()
            ->assertSee('>Up-to-date</button>', false)
            ->assertDontSee('>Fetch Latest And Deploy</button>', false);
    }
}
