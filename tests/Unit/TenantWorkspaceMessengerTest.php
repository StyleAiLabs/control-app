<?php

namespace Tests\Unit;

use App\Contracts\DockerComposeRunner;
use App\Enums\TenantProvisioningStatus;
use App\Enums\TrialStatus;
use App\Models\Server;
use App\Models\Tenant;
use App\Models\User;
use App\Services\TenantRuntimeSkillActivationService;
use App\Services\TenantWorkspaceMessenger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\TestCase;

class TenantWorkspaceMessengerTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_sends_private_agent_hook_with_token_and_no_external_delivery(): void
    {
        config()->set('sync360.workspace_gateway.agent_hook_path', '/hooks/agent');

        $tenant = $this->seedTenant();
        File::ensureDirectoryExists(config('sync360.runtime_root').'/'.$tenant->slug.'/config');
        File::put(config('sync360.runtime_root').'/'.$tenant->slug.'/config/openclaw.json', json_encode([
            'gateway' => [
                'auth' => [
                    'token' => 'private-hook-token',
                ],
            ],
            'hooks' => [
                'enabled' => true,
                'token' => 'private-hook-token',
                'path' => '/hooks',
            ],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL);

        $runner = new class implements DockerComposeRunner
        {
            public array $request = [];

            public function syncRuntime(Server $server, string $localRuntimePath, string $remoteRuntimePath): void {}

            public function syncWorkspaceFiles(Server $server, string $localWorkspacePath, string $remoteWorkspacePath): void {}

            public function httpRequest(Server $server, string $method, string $url, ?array $json = null, int $timeoutSeconds = 15, array $headers = []): array
            {
                $this->request = compact('method', 'url', 'json', 'timeoutSeconds', 'headers');

                return ['status' => 200, 'body' => '{"ok":true,"runId":"run-123"}'];
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
        $activation = \Mockery::mock(TenantRuntimeSkillActivationService::class);
        $activation->shouldReceive('ensureRequiredSkillsReady')
            ->once()
            ->withArgs(fn (Tenant $candidate, array $requiredSkillIds): bool => $candidate->is($tenant) && $requiredSkillIds === [])
            ->andReturn([
                'expected_skill_ids' => ['gog'],
                'skill_set_hash' => 'hash',
                'verified_skill_ids' => ['gog'],
                'verified_skill_set_hash' => 'hash',
            ]);
        $this->instance(TenantRuntimeSkillActivationService::class, $activation);

        app(TenantWorkspaceMessenger::class)->send(
            $tenant,
            'gmail_inbox_monitor',
            'sync360-inbox-monitor',
            'Internal Gmail inbox event.',
        );

        $this->assertSame('POST', $runner->request['method']);
        $this->assertSame('http://127.0.0.1:4100/hooks/agent', $runner->request['url']);
        $this->assertSame('Bearer private-hook-token', $runner->request['headers']['Authorization']);
        $this->assertSame('Internal Gmail inbox event.', $runner->request['json']['message']);
        $this->assertSame('sync360-inbox-monitor', $runner->request['json']['name']);
        $this->assertFalse($runner->request['json']['deliver']);
        $this->assertSame('gmail_inbox_monitor', $runner->request['json']['source_channel']);
        $this->assertStringStartsWith('sync360-', $runner->request['json']['idempotencyKey']);
    }

    public function test_it_blocks_customer_facing_runtime_work_for_expired_trials_without_override(): void
    {
        $tenant = $this->seedTenant([
            'trial_status' => TrialStatus::Expired,
        ]);

        $activation = \Mockery::mock(TenantRuntimeSkillActivationService::class);
        $activation->shouldNotReceive('ensureRequiredSkillsReady');
        $this->instance(TenantRuntimeSkillActivationService::class, $activation);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Customer-facing runtime work is paused because this tenant trial has expired. Enable the expired-trial runtime reply override in admin to resume replies.');

        app(TenantWorkspaceMessenger::class)->send(
            $tenant,
            'gmail_inbox_monitor',
            'sync360-inbox-monitor',
            'Internal Gmail inbox event.',
        );
    }

    public function test_it_blocks_runtime_work_when_conversation_log_schema_has_drift(): void
    {
        $tenant = $this->seedTenant();

        Schema::table('conversation_logs', function ($table): void {
            $table->dropColumn('ai_summary');
        });

        $activation = \Mockery::mock(TenantRuntimeSkillActivationService::class);
        $activation->shouldNotReceive('ensureRequiredSkillsReady');
        $this->instance(TenantRuntimeSkillActivationService::class, $activation);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Conversation log schema drift detected');
        $this->expectExceptionMessage('php artisan migrate');

        app(TenantWorkspaceMessenger::class)->send(
            $tenant,
            'gmail_inbox_monitor',
            'sync360-inbox-monitor',
            'Internal Gmail inbox event.',
        );
    }

    public function test_it_allows_customer_facing_runtime_work_for_expired_trials_with_override(): void
    {
        config()->set('sync360.workspace_gateway.agent_hook_path', '/hooks/agent');

        $tenant = $this->seedTenant([
            'trial_status' => TrialStatus::Expired,
            'allow_runtime_replies_when_trial_expired' => true,
        ]);
        File::ensureDirectoryExists(config('sync360.runtime_root').'/'.$tenant->slug.'/config');
        File::put(config('sync360.runtime_root').'/'.$tenant->slug.'/config/openclaw.json', json_encode([
            'gateway' => [
                'auth' => [
                    'token' => 'private-hook-token',
                ],
            ],
            'hooks' => [
                'enabled' => true,
                'token' => 'private-hook-token',
                'path' => '/hooks',
            ],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL);

        $runner = new class implements DockerComposeRunner
        {
            public array $request = [];

            public function syncRuntime(Server $server, string $localRuntimePath, string $remoteRuntimePath): void {}

            public function syncWorkspaceFiles(Server $server, string $localWorkspacePath, string $remoteWorkspacePath): void {}

            public function httpRequest(Server $server, string $method, string $url, ?array $json = null, int $timeoutSeconds = 15, array $headers = []): array
            {
                $this->request = compact('method', 'url', 'json', 'timeoutSeconds', 'headers');

                return ['status' => 200, 'body' => '{"ok":true,"runId":"run-123"}'];
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
        $activation = \Mockery::mock(TenantRuntimeSkillActivationService::class);
        $activation->shouldReceive('ensureRequiredSkillsReady')->once();
        $this->instance(TenantRuntimeSkillActivationService::class, $activation);

        app(TenantWorkspaceMessenger::class)->send(
            $tenant,
            'gmail_inbox_monitor',
            'sync360-inbox-monitor',
            'Internal Gmail inbox event.',
        );

        $this->assertSame('POST', $runner->request['method']);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function seedTenant(array $overrides = []): Tenant
    {
        $user = User::query()->create([
            'name' => 'Owner',
            'email' => 'owner@example.com',
            'password' => 'secret',
        ]);

        return Tenant::query()->create(array_merge([
            'tenant_id' => 'tenant-messenger',
            'slug' => 'messenger-shop',
            'business_name' => 'Messenger Shop',
            'industry' => 'Services',
            'skill_pack' => 'Client Support',
            'channel' => 'telegram',
            'agent_status' => 'live',
            'trial_status' => TrialStatus::Active,
            'provisioning_status' => TenantProvisioningStatus::Ready,
            'assigned_port' => 4100,
            'workspace_url' => 'https://messenger-shop.workspace.test',
            'runtime_path' => '/srv/sync360/runtime/tenants/messenger-shop',
            'server_id' => Server::query()->firstOrFail()->id,
            'user_id' => $user->id,
        ], $overrides));
    }
}
