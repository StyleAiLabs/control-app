<?php

namespace Tests\Feature;

use App\Contracts\DockerComposeRunner;
use App\Enums\ProvisioningJobStatus;
use App\Enums\TenantProvisioningStatus;
use App\Enums\TrialStatus;
use App\Jobs\ProcessTenantProvisioning;
use App\Models\ProvisioningJob;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class OpenClawProvisionerTest extends TestCase
{
    use RefreshDatabase;

    public function test_openclaw_provisioning_writes_runtime_and_marks_tenant_ready(): void
    {
        config()->set('sync360.provisioning.driver', 'openclaw');

        Http::fake([
            'http://localhost:4100/readyz' => Http::response(['status' => 'ok'], 200),
        ]);

        $runner = Mockery::mock(DockerComposeRunner::class);
        $runner->shouldReceive('isHostPortInUse')->once()->with(4100)->andReturnFalse();
        $runner->shouldReceive('up')
            ->once()
            ->withArgs(fn (string $composeFile, string $projectName): bool => str_ends_with($composeFile, '/compose.yaml') && $projectName === 'sync360-acme-plumbing')
            ->andReturnNull();
        $runner->shouldReceive('down')->once()->andReturnNull();
        $runner->shouldReceive('isRunning')->never();
        $runner->shouldReceive('start')->never();
        $runner->shouldReceive('stop')->never();

        $this->instance(DockerComposeRunner::class, $runner);

        [, $tenant, $job] = $this->seedTenantAndJob();

        ProcessTenantProvisioning::dispatchSync($tenant->id, $job->id);

        $tenant->refresh();
        $job->refresh();

        $this->assertSame(TenantProvisioningStatus::Ready, $tenant->provisioning_status);
        $this->assertSame(ProvisioningJobStatus::Completed, $job->status);
        $this->assertSame('http://localhost:4100', $tenant->workspace_url);
        $this->assertSame(4100, $tenant->assigned_port);
        $this->assertNotNull($tenant->runtime_path);
        $this->assertFileExists($tenant->runtime_path.'/.env');
        $this->assertFileExists($tenant->runtime_path.'/config/openclaw.json');
        $this->assertFileExists($tenant->runtime_path.'/compose.yaml');

        $this->assertStringContainsString('PROVISIONING_DRIVER=openclaw', (string) file_get_contents($tenant->runtime_path.'/.env'));
        $this->assertStringContainsString('"mode": "local"', (string) file_get_contents($tenant->runtime_path.'/config/openclaw.json'));
        $this->assertStringContainsString('ghcr.io/openclaw/openclaw:latest', (string) file_get_contents($tenant->runtime_path.'/compose.yaml'));
    }

    public function test_openclaw_readiness_failure_marks_tenant_and_job_as_failed(): void
    {
        config()->set('sync360.provisioning.driver', 'openclaw');

        Http::fake([
            'http://localhost:4100/readyz' => Http::response(['status' => 'booting'], 503),
        ]);

        $runner = Mockery::mock(DockerComposeRunner::class);
        $runner->shouldReceive('isHostPortInUse')->once()->with(4100)->andReturnFalse();
        $runner->shouldReceive('up')->once()->andReturnNull();
        $runner->shouldReceive('down')->twice()->andReturnNull();
        $runner->shouldReceive('isRunning')->never();
        $runner->shouldReceive('start')->never();
        $runner->shouldReceive('stop')->never();

        $this->instance(DockerComposeRunner::class, $runner);

        [, $tenant, $job] = $this->seedTenantAndJob();

        try {
            ProcessTenantProvisioning::dispatchSync($tenant->id, $job->id);
            $this->fail('Provisioning should have thrown when the readiness endpoint never became healthy.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('OpenClaw readiness check failed', $exception->getMessage());
        }

        $tenant->refresh();
        $job->refresh();

        $this->assertSame(TenantProvisioningStatus::Failed, $tenant->provisioning_status);
        $this->assertSame(ProvisioningJobStatus::Failed, $job->status);
        $this->assertNotNull($job->error_message);
        $this->assertStringContainsString('OpenClaw readiness check failed', $job->error_message);
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
