<?php

namespace Tests\Feature;

use App\Contracts\DockerComposeRunner;
use App\Enums\TenantProvisioningStatus;
use App\Enums\TrialStatus;
use App\Models\Server;
use App\Models\Tenant;
use App\Models\User;
use App\Services\TenantHealthCheckService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class TenantHealthCheckFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_health_check_marks_tenant_live_when_workspace_is_ready(): void
    {
        $tenant = $this->seedTenant();

        $runner = new class implements DockerComposeRunner
        {
            public function syncRuntime(Server $server, string $localRuntimePath, string $remoteRuntimePath): void {}
            public function syncWorkspaceFiles(Server $server, string $localWorkspacePath, string $remoteWorkspacePath): void {}
            public function httpRequest(Server $server, string $method, string $url, ?array $json = null, int $timeoutSeconds = 15, array $headers = []): array
            {
                $response = Http::timeout($timeoutSeconds)->acceptJson()->send($method, $url, $json !== null ? ['json' => $json] : []);

                return ['status' => $response->status(), 'body' => $response->body()];
            }
            public function putFile(Server $server, string $remotePath, string $contents, bool $sudo = false): void {}
            public function removeFile(Server $server, string $remotePath, bool $sudo = false): void {}
            public function removeDirectory(Server $server, string $remotePath, bool $sudo = false): void {}
            public function runCommand(Server $server, string $command, bool $sudo = false): void {}
            public function up(Server $server, string $composeFile, string $projectName): void {}
            public function down(Server $server, string $composeFile, string $projectName): void {}
            public function start(Server $server, string $composeFile, string $projectName): void {}
            public function stop(Server $server, string $composeFile, string $projectName): void {}
            public function isRunning(Server $server, string $composeFile, string $projectName): bool { return true; }
            public function isHostPortInUse(Server $server, int $port): bool { return false; }
            public function waitForHttpReady(Server $server, string $url, int $timeoutSeconds, int $pollIntervalMs): void {}
        };

        $this->instance(DockerComposeRunner::class, $runner);

        Http::fake([
            'http://127.0.0.1:4100/readyz' => Http::response(['ok' => true], 200),
        ]);

        $result = app(TenantHealthCheckService::class)->check($tenant);

        $tenant->refresh();

        $this->assertTrue($result['healthy']);
        $this->assertSame('healthy', $tenant->last_health_check_status);
        $this->assertSame('live', $tenant->agent_status);
        $this->assertSame('Workspace readiness check passed.', $tenant->health_check_message);
        $this->assertNotNull($tenant->last_health_check_at);
    }

    public function test_health_check_command_updates_live_tenants(): void
    {
        $tenant = $this->seedTenant();

        $runner = new class implements DockerComposeRunner
        {
            public function syncRuntime(Server $server, string $localRuntimePath, string $remoteRuntimePath): void {}
            public function syncWorkspaceFiles(Server $server, string $localWorkspacePath, string $remoteWorkspacePath): void {}
            public function httpRequest(Server $server, string $method, string $url, ?array $json = null, int $timeoutSeconds = 15, array $headers = []): array
            {
                $response = Http::timeout($timeoutSeconds)->acceptJson()->send($method, $url, $json !== null ? ['json' => $json] : []);

                return ['status' => $response->status(), 'body' => $response->body()];
            }
            public function putFile(Server $server, string $remotePath, string $contents, bool $sudo = false): void {}
            public function removeFile(Server $server, string $remotePath, bool $sudo = false): void {}
            public function removeDirectory(Server $server, string $remotePath, bool $sudo = false): void {}
            public function runCommand(Server $server, string $command, bool $sudo = false): void {}
            public function up(Server $server, string $composeFile, string $projectName): void {}
            public function down(Server $server, string $composeFile, string $projectName): void {}
            public function start(Server $server, string $composeFile, string $projectName): void {}
            public function stop(Server $server, string $composeFile, string $projectName): void {}
            public function isRunning(Server $server, string $composeFile, string $projectName): bool { return true; }
            public function isHostPortInUse(Server $server, int $port): bool { return false; }
            public function waitForHttpReady(Server $server, string $url, int $timeoutSeconds, int $pollIntervalMs): void {}
        };

        $this->instance(DockerComposeRunner::class, $runner);

        Http::fake([
            'http://127.0.0.1:4100/readyz' => Http::response(['ok' => true], 200),
        ]);

        $this->artisan('tenants:health-check')
            ->assertExitCode(0);

        $tenant->refresh();

        $this->assertSame('healthy', $tenant->last_health_check_status);
        $this->assertSame('live', $tenant->agent_status);
    }

    public function test_health_check_ignores_conversation_log_schema_drift_when_runtime_is_ready(): void
    {
        $tenant = $this->seedTenant();

        $runner = new class implements DockerComposeRunner
        {
            public function syncRuntime(Server $server, string $localRuntimePath, string $remoteRuntimePath): void {}
            public function syncWorkspaceFiles(Server $server, string $localWorkspacePath, string $remoteWorkspacePath): void {}
            public function httpRequest(Server $server, string $method, string $url, ?array $json = null, int $timeoutSeconds = 15, array $headers = []): array
            {
                return ['status' => 200, 'body' => '{"ok":true}'];
            }
            public function putFile(Server $server, string $remotePath, string $contents, bool $sudo = false): void {}
            public function removeFile(Server $server, string $remotePath, bool $sudo = false): void {}
            public function removeDirectory(Server $server, string $remotePath, bool $sudo = false): void {}
            public function runCommand(Server $server, string $command, bool $sudo = false): void {}
            public function up(Server $server, string $composeFile, string $projectName): void {}
            public function down(Server $server, string $composeFile, string $projectName): void {}
            public function start(Server $server, string $composeFile, string $projectName): void {}
            public function stop(Server $server, string $composeFile, string $projectName): void {}
            public function isRunning(Server $server, string $composeFile, string $projectName): bool { return true; }
            public function isHostPortInUse(Server $server, int $port): bool { return false; }
            public function waitForHttpReady(Server $server, string $url, int $timeoutSeconds, int $pollIntervalMs): void {}
        };

        $this->instance(DockerComposeRunner::class, $runner);

        Schema::table('conversation_logs', function ($table): void {
            $table->dropColumn('ai_summary');
        });

        $result = app(TenantHealthCheckService::class)->check($tenant);

        $tenant->refresh();

        $this->assertTrue($result['healthy']);
        $this->assertSame('healthy', $result['status']);
        $this->assertSame('running', $result['workspace_state']);
        $this->assertSame('Workspace readiness check passed.', $result['message']);
        $this->assertSame('healthy', $tenant->last_health_check_status);
        $this->assertSame('live', $tenant->agent_status);
    }

    private function seedTenant(): Tenant
    {
        $user = User::query()->create([
            'name' => 'Alice Admin',
            'email' => 'alice@example.com',
            'password' => 'super-secret',
        ]);

        return Tenant::query()->create([
            'tenant_id' => 'tenant_health_01',
            'slug' => 'acme-plumbing',
            'business_name' => 'Acme Plumbing',
            'industry' => 'Trades',
            'skill_pack' => 'Operations Core',
            'user_id' => $user->id,
            'server_id' => Server::query()->firstOrFail()->id,
            'trial_status' => TrialStatus::Active,
            'provisioning_status' => TenantProvisioningStatus::Ready,
            'agent_status' => 'live',
            'assigned_port' => 4100,
            'workspace_url' => 'https://acme-plumbing.workspace.test',
            'runtime_path' => '/srv/sync360/runtime/tenants/acme-plumbing',
        ]);
    }
}
