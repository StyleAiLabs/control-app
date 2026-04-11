<?php

namespace Tests\Feature;

use App\Contracts\TenantProvisioner;
use App\Enums\ProvisioningJobStatus;
use App\Enums\TenantProvisioningStatus;
use App\Enums\TrialStatus;
use App\Jobs\ProcessTenantProvisioning;
use App\Models\ProvisioningJob;
use App\Models\Server;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class ProvisioningFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_provisioning_job_processes_and_creates_runtime_files(): void
    {
        [$user, $tenant, $job] = $this->seedTenantAndJob();

        ProcessTenantProvisioning::dispatchSync($tenant->id, $job->id);

        $tenant->refresh();
        $job->refresh();

        $this->assertSame(TenantProvisioningStatus::Ready, $tenant->provisioning_status);
        $this->assertSame(ProvisioningJobStatus::Completed, $job->status);
        $this->assertSame(4100, $tenant->assigned_port);
        $this->assertSame('https://acme-plumbing.workspace.test', $tenant->workspace_url);
        $this->assertNotNull($tenant->runtime_path);

        $this->assertFileExists($tenant->runtime_path.'/.env');
        $this->assertFileExists($tenant->runtime_path.'/metadata.json');
        $this->assertFileExists($tenant->runtime_path.'/config');
        $this->assertFileExists($tenant->runtime_path.'/data');
        $this->assertFileExists($tenant->runtime_path.'/logs');

        $this->actingAs($user);

        $this->get('/tenant/status')
            ->assertOk()
            ->assertJson([
                'provisioning_status' => 'ready',
                'workspace_url' => 'https://acme-plumbing.workspace.test',
            ]);

        $this->get('/tenant/workspace-ready')
            ->assertOk()
            ->assertSee('https://acme-plumbing.workspace.test')
            ->assertSee('your workspace is ready.', escape: false)
            ->assertDontSee('Assigned Port')
            ->assertDontSee($tenant->runtime_path);

        $this->get('/dashboard')
            ->assertOk()
            ->assertSee('Welcome back, Alice.')
            ->assertSee('Workspace is live')
            ->assertDontSee($tenant->tenant_id)
            ->assertDontSee('Assigned Port')
            ->assertDontSee($tenant->runtime_path);
    }

    public function test_provisioning_failure_marks_tenant_and_job_as_failed(): void
    {
        [, $tenant, $job] = $this->seedTenantAndJob();

        $mock = Mockery::mock(TenantProvisioner::class);
        $mock->shouldReceive('provision')
            ->once()
            ->andThrow(new RuntimeException('Runtime folder creation failed.'));

        $this->instance(TenantProvisioner::class, $mock);

        try {
            ProcessTenantProvisioning::dispatchSync($tenant->id, $job->id);
            $this->fail('Provisioning should have thrown an exception.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Runtime folder creation failed.', $exception->getMessage());
        }

        $tenant->refresh();
        $job->refresh();

        $this->assertSame(TenantProvisioningStatus::Failed, $tenant->provisioning_status);
        $this->assertSame(ProvisioningJobStatus::Failed, $job->status);
        $this->assertSame('Runtime folder creation failed.', $job->error_message);
    }

    private function seedTenantAndJob(): array
    {
        $user = User::query()->create([
            'name' => 'Alice Admin',
            'email' => 'alice@example.com',
            'password' => 'super-secret',
        ]);

        $tenant = Tenant::query()->create([
            'tenant_id' => 'tenant_01',
            'slug' => 'acme-plumbing',
            'business_name' => 'Acme Plumbing',
            'industry' => 'Trades',
            'skill_pack' => 'Operations Core',
            'user_id' => $user->id,
            'server_id' => Server::query()->firstOrFail()->id,
            'trial_status' => TrialStatus::Active,
            'provisioning_status' => TenantProvisioningStatus::Pending,
        ]);

        $job = ProvisioningJob::query()->create([
            'tenant_id' => $tenant->id,
            'job_type' => 'provision_tenant',
            'status' => ProvisioningJobStatus::Queued,
            'payload_json' => ['requested_from' => 'test'],
        ]);

        return [$user, $tenant, $job];
    }
}
