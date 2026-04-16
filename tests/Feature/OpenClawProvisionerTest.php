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
        config()->set('services.litellm.base_url', 'https://litellm.stylesoftware.co.nz');
        config()->set('services.litellm.master_key', 'litellm-master');
        Http::fake([
            'https://litellm.stylesoftware.co.nz/key/generate' => Http::response(['key' => 'sk-tenant-acme'], 200),
            'https://acme-plumbing.workspace.test/login' => Http::response('login', 200),
        ]);

        $runner = Mockery::mock(DockerComposeRunner::class);
        $runner->shouldReceive('isHostPortInUse')
            ->once()
            ->withArgs(fn (Server $server, int $port): bool => $server->name === 'test-vps' && $port === 4100)
            ->andReturnFalse();
        $runner->shouldReceive('down')
            ->once()
            ->withArgs(fn (Server $server, string $composeFile, string $projectName): bool => $server->name === 'test-vps' && $composeFile === '/srv/sync360/runtime/tenants/acme-plumbing/compose.yaml' && $projectName === 'sync360-acme-plumbing')
            ->andReturnNull();
        $runner->shouldReceive('runCommand')
            ->once()
            ->withArgs(function (Server $server, string $command, bool $sudo = false): bool {
                return $server->name === 'test-vps'
                    && $command === "docker rm -f 'sync360-acme-plumbing' >/dev/null 2>&1 || true"
                    && $sudo === false;
            })
            ->andReturnNull();
        $runner->shouldReceive('syncRuntime')
            ->once()
            ->withArgs(fn (Server $server, string $localRuntimePath, string $remoteRuntimePath): bool => $server->name === 'test-vps' && str_contains($localRuntimePath, '/runtime/acme-plumbing') && $remoteRuntimePath === '/srv/sync360/runtime/tenants/acme-plumbing')
            ->andReturnNull();
        $runner->shouldReceive('putFile')
            ->once()
            ->withArgs(function (Server $server, string $remotePath, string $contents, bool $sudo): bool {
                return $server->name === 'test-vps'
                    && $remotePath === '/etc/caddy/sites/acme-plumbing.caddy'
                    && str_contains($contents, 'acme-plumbing.workspace.test')
                    && str_contains($contents, 'reverse_proxy https://app.sync360.test')
                    && $sudo === true;
            })
            ->andReturnNull();
        $runner->shouldReceive('runCommand')
            ->once()
            ->withArgs(fn (Server $server, string $command, bool $sudo): bool => $server->name === 'test-vps' && $command === 'systemctl reload caddy' && $sudo === true)
            ->andReturnNull();
        $runner->shouldReceive('up')
            ->once()
            ->withArgs(fn (Server $server, string $composeFile, string $projectName): bool => $server->name === 'test-vps' && $composeFile === '/srv/sync360/runtime/tenants/acme-plumbing/compose.yaml' && $projectName === 'sync360-acme-plumbing')
            ->andReturnNull();
        $runner->shouldReceive('waitForHttpReady')
            ->once()
            ->withArgs(fn (Server $server, string $url, int $timeoutSeconds, int $pollIntervalMs): bool => $server->name === 'test-vps' && $url === 'http://127.0.0.1:4100/readyz' && $timeoutSeconds === 1 && $pollIntervalMs === 100)
            ->andReturnNull();
        $runner->shouldReceive('removeFile')
            ->once()
            ->withArgs(fn (Server $server, string $remotePath, bool $sudo): bool => $server->name === 'test-vps' && $remotePath === '/etc/caddy/sites/acme-plumbing.caddy' && $sudo === true)
            ->andReturnNull();
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
        $this->assertSame('https://acme-plumbing.workspace.test', $tenant->workspace_url);
        $this->assertSame(4100, $tenant->assigned_port);
        $this->assertSame('/srv/sync360/runtime/tenants/acme-plumbing', $tenant->runtime_path);
        $this->assertSame('sk-tenant-acme', $tenant->litellm_virtual_key);
        $this->assertSame('openclaw-tenant_01', $tenant->litellm_key_alias);
        $this->assertSame('trial', $tenant->litellm_plan_name);
        $localRuntimePath = $this->testProvisioningBase.'/runtime/acme-plumbing';
        $this->assertFileExists($localRuntimePath.'/.env');
        $this->assertFileExists($localRuntimePath.'/config/openclaw.json');
        $this->assertFileExists($localRuntimePath.'/config/workspace.caddy');
        $this->assertFileExists($localRuntimePath.'/compose.yaml');

        $this->assertStringContainsString('PROVISIONING_DRIVER=openclaw', (string) file_get_contents($localRuntimePath.'/.env'));
        $this->assertStringContainsString('OPENAI_API_KEY=sk-tenant-acme', (string) file_get_contents($localRuntimePath.'/.env'));
        $this->assertStringContainsString('OPENAI_BASE_URL=https://litellm.stylesoftware.co.nz', (string) file_get_contents($localRuntimePath.'/.env'));
        $this->assertStringContainsString('"mode": "local"', (string) file_get_contents($localRuntimePath.'/config/openclaw.json'));
        $this->assertStringContainsString('acme-plumbing.workspace.test', (string) file_get_contents($localRuntimePath.'/config/workspace.caddy'));
        $this->assertStringContainsString('ghcr.io/openclaw/openclaw:latest', (string) file_get_contents($localRuntimePath.'/compose.yaml'));
        $this->assertStringContainsString('/srv/sync360/runtime/tenants/acme-plumbing', (string) file_get_contents($localRuntimePath.'/compose.yaml'));
        $this->assertStringContainsString('OPENAI_API_KEY: "sk-tenant-acme"', (string) file_get_contents($localRuntimePath.'/compose.yaml'));
        $this->assertStringContainsString('OPENAI_BASE_URL: "https://litellm.stylesoftware.co.nz"', (string) file_get_contents($localRuntimePath.'/compose.yaml'));

        Http::assertSent(fn ($request) => $request->url() === 'https://litellm.stylesoftware.co.nz/key/generate'
            && $request->hasHeader('Authorization', 'Bearer litellm-master')
            && $request['key_alias'] === 'openclaw-tenant_01'
            && $request['metadata']['tenant_id'] === 'tenant_01'
            && $request['metadata']['plan'] === 'trial');
    }

    public function test_openclaw_readiness_failure_marks_tenant_and_job_as_failed(): void
    {
        config()->set('sync360.provisioning.driver', 'openclaw');
        config()->set('services.litellm.base_url', 'https://litellm.stylesoftware.co.nz');
        config()->set('services.litellm.master_key', 'litellm-master');
        Http::fake([
            'https://litellm.stylesoftware.co.nz/key/generate' => Http::response(['key' => 'sk-tenant-acme'], 200),
        ]);

        $runner = Mockery::mock(DockerComposeRunner::class);
        $runner->shouldReceive('isHostPortInUse')->once()->andReturnFalse();
        $runner->shouldReceive('down')->twice()->andReturnNull();
        $runner->shouldReceive('runCommand')
            ->twice()
            ->withArgs(function (Server $server, string $command, bool $sudo = false): bool {
                return $server->name === 'test-vps'
                    && $command === "docker rm -f 'sync360-acme-plumbing' >/dev/null 2>&1 || true"
                    && $sudo === false;
            })
            ->andReturnNull();
        $runner->shouldReceive('syncRuntime')->once()->andReturnNull();
        $runner->shouldReceive('putFile')->once()->andReturnNull();
        $runner->shouldReceive('runCommand')->twice()->withArgs(fn (Server $server, string $command, bool $sudo = false): bool => $server->name === 'test-vps' && $command === 'systemctl reload caddy' && $sudo === true)->andReturnNull();
        $runner->shouldReceive('up')->once()->andReturnNull();
        $runner->shouldReceive('waitForHttpReady')
            ->once()
            ->andThrow(new RuntimeException('OpenClaw readiness check failed for [http://127.0.0.1:4100/readyz]. Last error: curl failed'));
        $runner->shouldReceive('removeFile')->twice()->andReturnNull();
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

    public function test_public_workspace_failure_marks_tenant_and_job_as_failed(): void
    {
        config()->set('sync360.provisioning.driver', 'openclaw');
        config()->set('services.litellm.base_url', 'https://litellm.stylesoftware.co.nz');
        config()->set('services.litellm.master_key', 'litellm-master');
        Http::fake([
            'https://litellm.stylesoftware.co.nz/key/generate' => Http::response(['key' => 'sk-tenant-acme'], 200),
            'https://acme-plumbing.workspace.test/login' => Http::response('bad gateway', 502),
        ]);

        $runner = Mockery::mock(DockerComposeRunner::class);
        $runner->shouldReceive('isHostPortInUse')->once()->andReturnFalse();
        $runner->shouldReceive('down')->twice()->andReturnNull();
        $runner->shouldReceive('runCommand')
            ->twice()
            ->withArgs(function (Server $server, string $command, bool $sudo = false): bool {
                return $server->name === 'test-vps'
                    && $command === "docker rm -f 'sync360-acme-plumbing' >/dev/null 2>&1 || true"
                    && $sudo === false;
            })
            ->andReturnNull();
        $runner->shouldReceive('syncRuntime')->once()->andReturnNull();
        $runner->shouldReceive('putFile')->once()->andReturnNull();
        $runner->shouldReceive('runCommand')->twice()->withArgs(fn (Server $server, string $command, bool $sudo = false): bool => $server->name === 'test-vps' && $command === 'systemctl reload caddy' && $sudo === true)->andReturnNull();
        $runner->shouldReceive('up')->once()->andReturnNull();
        $runner->shouldReceive('waitForHttpReady')->once()->andReturnNull();
        $runner->shouldReceive('removeFile')->twice()->andReturnNull();
        $runner->shouldReceive('isRunning')->never();
        $runner->shouldReceive('start')->never();
        $runner->shouldReceive('stop')->never();

        $this->instance(DockerComposeRunner::class, $runner);

        [, $tenant, $job] = $this->seedTenantAndJob();

        try {
            ProcessTenantProvisioning::dispatchSync($tenant->id, $job->id);
            $this->fail('Provisioning should have thrown when the public hostname never became healthy.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('Public workspace readiness check failed', $exception->getMessage());
        }

        $tenant->refresh();
        $job->refresh();

        $this->assertSame(TenantProvisioningStatus::Failed, $tenant->provisioning_status);
        $this->assertSame(ProvisioningJobStatus::Failed, $job->status);
        $this->assertNotNull($job->error_message);
        $this->assertStringContainsString('Public workspace readiness check failed', $job->error_message);
    }

    public function test_litellm_key_generation_failure_aborts_openclaw_provisioning(): void
    {
        config()->set('sync360.provisioning.driver', 'openclaw');
        config()->set('services.litellm.base_url', 'https://litellm.stylesoftware.co.nz');
        config()->set('services.litellm.master_key', 'litellm-master');
        Http::fake([
            'https://litellm.stylesoftware.co.nz/key/generate' => Http::response(['error' => 'budget service unavailable'], 500),
        ]);

        $runner = Mockery::mock(DockerComposeRunner::class);
        $runner->shouldReceive('isHostPortInUse')
            ->once()
            ->withArgs(fn (Server $server, int $port): bool => $server->name === 'test-vps' && $port === 4100)
            ->andReturnFalse();
        $runner->shouldReceive('down')->never();
        $runner->shouldReceive('syncRuntime')->never();
        $runner->shouldReceive('putFile')->never();
        $runner->shouldReceive('runCommand')->never();
        $runner->shouldReceive('up')->never();
        $runner->shouldReceive('waitForHttpReady')->never();
        $runner->shouldReceive('removeFile')->never();
        $runner->shouldReceive('isRunning')->never();
        $runner->shouldReceive('start')->never();
        $runner->shouldReceive('stop')->never();

        $this->instance(DockerComposeRunner::class, $runner);

        [, $tenant, $job] = $this->seedTenantAndJob();

        try {
            ProcessTenantProvisioning::dispatchSync($tenant->id, $job->id);
            $this->fail('Provisioning should have thrown when LiteLLM key generation failed.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('LiteLLM request to [/key/generate] failed', $exception->getMessage());
        }

        $tenant->refresh();
        $job->refresh();

        $this->assertSame(TenantProvisioningStatus::Failed, $tenant->provisioning_status);
        $this->assertSame(ProvisioningJobStatus::Failed, $job->status);
        $this->assertNull($tenant->litellm_virtual_key);
        $this->assertStringContainsString('LiteLLM request to [/key/generate] failed', (string) $job->error_message);
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
