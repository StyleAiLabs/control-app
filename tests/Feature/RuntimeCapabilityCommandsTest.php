<?php

namespace Tests\Feature;

use App\Contracts\DockerComposeRunner;
use App\Enums\TenantProvisioningStatus;
use App\Enums\TrialStatus;
use App\Models\Server;
use App\Models\Tenant;
use App\Models\TenantGoogleCredential;
use App\Models\User;
use App\Services\TenantGoogleWorkspaceSmokeTestService;
use App\Services\TenantProfileSyncService;
use App\Services\TenantRuntimeCapabilityService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class RuntimeCapabilityCommandsTest extends TestCase
{
    use RefreshDatabase;

    public function test_bootstrap_client_vps_installs_runtime_capabilities(): void
    {
        config()->set('sync360.infrastructure.driver', 'ssh');

        $runnerSpy = new class implements DockerComposeRunner
        {
            /** @var list<string> */
            public array $commands = [];

            public function syncRuntime(Server $server, string $localRuntimePath, string $remoteRuntimePath): void {}
            public function syncWorkspaceFiles(Server $server, string $localWorkspacePath, string $remoteWorkspacePath): void {}
            public function httpRequest(Server $server, string $method, string $url, ?array $json = null, int $timeoutSeconds = 15): array { return ['status' => 200, 'body' => '']; }
            public function putFile(Server $server, string $remotePath, string $contents, bool $sudo = false): void {}
            public function removeFile(Server $server, string $remotePath, bool $sudo = false): void {}
            public function removeDirectory(Server $server, string $remotePath, bool $sudo = false): void {}
            public function runCommand(Server $server, string $command, bool $sudo = false): void { $this->commands[] = $command; }
            public function up(Server $server, string $composeFile, string $projectName): void {}
            public function down(Server $server, string $composeFile, string $projectName): void {}
            public function start(Server $server, string $composeFile, string $projectName): void {}
            public function stop(Server $server, string $composeFile, string $projectName): void {}
            public function isRunning(Server $server, string $composeFile, string $projectName): bool { return false; }
            public function isHostPortInUse(Server $server, int $port): bool { return false; }
            public function waitForHttpReady(Server $server, string $url, int $timeoutSeconds, int $pollIntervalMs): void {}
        };

        $this->instance(DockerComposeRunner::class, $runnerSpy);

        $runtimeCapabilities = Mockery::mock(TenantRuntimeCapabilityService::class);
        $runtimeCapabilities->shouldReceive('ensureInstalledOnServer')
            ->once()
            ->andReturn(['gog' => 'installed']);
        $this->instance(TenantRuntimeCapabilityService::class, $runtimeCapabilities);

        $this->artisan('sync360:bootstrap-client-vps')
            ->expectsOutputToContain('Runtime capability [gog]')
            ->expectsOutputToContain('Client VPS bootstrap completed successfully.')
            ->assertExitCode(0);
    }

    public function test_sync_runtime_capabilities_command_rejects_local_driver(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Host-managed runtime capabilities are only supported when SYNC360_INFRASTRUCTURE_DRIVER=ssh.');

        $this->artisan('sync360:sync-runtime-capabilities');
    }

    public function test_sync_runtime_capabilities_command_repairs_targeted_tenant_and_marks_google_verified(): void
    {
        config()->set('sync360.infrastructure.driver', 'ssh');

        $tenant = $this->seedReadyTenant();

        $localRuntimePath = config('sync360.runtime_root').'/'.$tenant->slug;
        File::ensureDirectoryExists($localRuntimePath.'/config');
        File::put($localRuntimePath.'/.env', implode(PHP_EOL, [
            'OPENCLAW_GATEWAY_TOKEN=test-token',
            'OPENAI_API_KEY=sk-tenant-acme',
            'OPENAI_BASE_URL=https://litellm.stylesoftware.co.nz',
            '',
        ]));
        File::put($localRuntimePath.'/config/openclaw.json', json_encode([
            'agents' => [
                'defaults' => [
                    'model' => 'gpt-4o',
                ],
            ],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL);
        File::put($localRuntimePath.'/compose.yaml', implode(PHP_EOL, [
            'services:',
            '  openclaw-gateway:',
            '    image: ghcr.io/openclaw/openclaw:latest',
            '    restart: unless-stopped',
            '    container_name: sync360-acme-plumbing',
            '    command:',
            '      - /bin/sh',
            '      - -lc',
            '      - "openclaw gateway --allow-unconfigured --port=18789"',
            '    ports:',
            '      - "127.0.0.1:4100:18789"',
            '    volumes:',
            '      - type: bind',
            '        source: "/srv/sync360/runtime/tenants/acme-plumbing"',
            '        target: "/home/node/.openclaw"',
            '    environment:',
            '      OPENCLAW_HOME: "/home/node/.openclaw"',
            '      OPENCLAW_STATE_DIR: "/home/node/.openclaw/data"',
            '      OPENCLAW_CONFIG_PATH: "/home/node/.openclaw/config/openclaw.json"',
            '      OPENCLAW_GATEWAY_TOKEN: "test-token"',
            '      OPENAI_API_KEY: "sk-tenant-acme"',
            '      OPENAI_BASE_URL: "https://litellm.stylesoftware.co.nz"',
            '      XDG_CONFIG_HOME: "/home/node/.openclaw/.openclaw"',
            '      GOG_KEYRING_BACKEND: "file"',
            '      GOG_KEYRING_PASSWORD: "test-password"',
            '',
        ]));
        File::ensureDirectoryExists($localRuntimePath.'/.openclaw/workspace/memory');

        $staleMemoryPath = $localRuntimePath.'/.openclaw/workspace/memory/'.now()->format('Y-m-d').'-email-check-issue.md';
        $otherMemoryPath = $localRuntimePath.'/.openclaw/workspace/memory/'.now()->format('Y-m-d').'-healthy-session.md';

        File::put($staleMemoryPath, '# Email check issue'.PHP_EOL);
        File::put($otherMemoryPath, '# Healthy session'.PHP_EOL);

        $runnerSpy = new class implements DockerComposeRunner
        {
            /** @var list<string> */
            public array $commands = [];

            /** @var array<int, array<string, string>> */
            public array $putFiles = [];

            /** @var list<string> */
            public array $removedFiles = [];

            public function syncRuntime(Server $server, string $localRuntimePath, string $remoteRuntimePath): void {}
            public function syncWorkspaceFiles(Server $server, string $localWorkspacePath, string $remoteWorkspacePath): void {}
            public function httpRequest(Server $server, string $method, string $url, ?array $json = null, int $timeoutSeconds = 15): array { return ['status' => 200, 'body' => '']; }
            public function putFile(Server $server, string $remotePath, string $contents, bool $sudo = false): void
            {
                $this->putFiles[] = ['path' => $remotePath, 'contents' => $contents];
            }
            public function removeFile(Server $server, string $remotePath, bool $sudo = false): void
            {
                $this->removedFiles[] = $remotePath;
            }
            public function removeDirectory(Server $server, string $remotePath, bool $sudo = false): void {}
            public function runCommand(Server $server, string $command, bool $sudo = false): void
            {
                $this->commands[] = $command;
            }
            public function up(Server $server, string $composeFile, string $projectName): void {}
            public function down(Server $server, string $composeFile, string $projectName): void {}
            public function start(Server $server, string $composeFile, string $projectName): void {}
            public function stop(Server $server, string $composeFile, string $projectName): void {}
            public function isRunning(Server $server, string $composeFile, string $projectName): bool { return false; }
            public function isHostPortInUse(Server $server, int $port): bool { return false; }
            public function waitForHttpReady(Server $server, string $url, int $timeoutSeconds, int $pollIntervalMs): void {}
        };

        $this->instance(DockerComposeRunner::class, $runnerSpy);
        $this->mock(TenantGoogleWorkspaceSmokeTestService::class, function ($mock): void {
            $mock->shouldReceive('run')
                ->once()
                ->andReturn([
                    'tenant_slug' => 'acme-plumbing',
                    'google_email' => 'owner@example.com',
                    'host_capability_verified' => true,
                    'container_binary_verified' => true,
                    'gog_env_verified' => true,
                    'gmail_cli_verified' => true,
                    'calendar_cli_verified' => true,
                    'drive_cli_verified' => true,
                    'contacts_cli_verified' => true,
                    'help_probes_verified' => true,
                    'runtime_artifacts_verified' => true,
                    'container_smoke_passed' => true,
                ]);
        });

        $this->artisan('sync360:sync-runtime-capabilities '.$tenant->slug.' gog')
            ->expectsOutputToContain('Runtime capability sync finished. Completed: 1. Failed: 0.')
            ->assertExitCode(0);

        $tenant->refresh();
        $credential = $tenant->googleCredential()->firstOrFail();

        $this->assertSame(TenantGoogleCredential::RUNTIME_SYNC_VERIFIED, $credential->runtime_sync_status);
        $this->assertStringContainsString('source: "/usr/local/bin/gog"', File::get($localRuntimePath.'/compose.yaml'));
        $this->assertStringContainsString('GOG_ENABLE_COMMANDS: "gmail,calendar,drive,contacts,tasks,sheets,docs,slides,people,chat,classroom,forms,appscript,groups"', File::get($localRuntimePath.'/compose.yaml'));
        $this->assertStringContainsString('GOG_ACCOUNT: "owner@example.com"', File::get($localRuntimePath.'/compose.yaml'));
        $this->assertStringContainsString('"gog"', File::get($localRuntimePath.'/config/openclaw.json'));
        $this->assertNotEmpty($runnerSpy->putFiles);
        $this->assertTrue(collect($runnerSpy->putFiles)->contains(fn (array $file): bool => $file['path'] === '/srv/sync360/runtime/tenants/acme-plumbing/compose.yaml'));
        $this->assertTrue(collect($runnerSpy->putFiles)->contains(fn (array $file): bool => $file['path'] === '/srv/sync360/runtime/tenants/acme-plumbing/config/openclaw.json'));
        $this->assertTrue(collect($runnerSpy->commands)->contains(fn (string $command): bool => str_contains($command, 'docker exec') && str_contains($command, 'sync360-acme-plumbing')));
        $this->assertFileDoesNotExist($staleMemoryPath);
        $this->assertFileExists($otherMemoryPath);
        $this->assertContains('/srv/sync360/runtime/tenants/acme-plumbing/.openclaw/workspace/memory/'.now()->format('Y-m-d').'-email-check-issue.md', $runnerSpy->removedFiles);
    }

    public function test_sync_runtime_capabilities_refreshes_workspace_guidance_for_live_tenants(): void
    {
        config()->set('sync360.infrastructure.driver', 'ssh');

        $tenant = $this->seedReadyTenant();
        $tenant->forceFill([
            'agent_status' => 'live',
            'onboarding_status' => 'complete',
        ])->save();

        $localRuntimePath = config('sync360.runtime_root').'/'.$tenant->slug;
        File::ensureDirectoryExists($localRuntimePath.'/config');
        File::put($localRuntimePath.'/.env', implode(PHP_EOL, [
            'OPENCLAW_GATEWAY_TOKEN=test-token',
            'OPENAI_API_KEY=sk-tenant-acme',
            'OPENAI_BASE_URL=https://litellm.stylesoftware.co.nz',
            '',
        ]));
        File::put($localRuntimePath.'/config/openclaw.json', json_encode([
            'agents' => [
                'defaults' => [
                    'model' => 'gpt-4o',
                ],
            ],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL);
        File::put($localRuntimePath.'/compose.yaml', 'services: {}'.PHP_EOL);

        $runner = Mockery::mock(DockerComposeRunner::class);
        $runner->shouldReceive('putFile')->andReturnNull();
        $runner->shouldReceive('runCommand')->andReturnNull();
        $this->instance(DockerComposeRunner::class, $runner);

        $profileSync = Mockery::mock(TenantProfileSyncService::class);
        $profileSync->shouldReceive('regenerateAndSyncWorkspaceOnly')
            ->once()
            ->withArgs(fn (Tenant $refreshedTenant): bool => $refreshedTenant->slug === 'acme-plumbing')
            ->andReturnNull();
        $this->instance(TenantProfileSyncService::class, $profileSync);

        $this->mock(TenantGoogleWorkspaceSmokeTestService::class, function ($mock): void {
            $mock->shouldReceive('run')
                ->once()
                ->andReturn([
                    'tenant_slug' => 'acme-plumbing',
                    'google_email' => 'owner@example.com',
                    'host_capability_verified' => true,
                    'container_binary_verified' => true,
                    'gog_env_verified' => true,
                    'gmail_cli_verified' => true,
                    'calendar_cli_verified' => true,
                    'drive_cli_verified' => true,
                    'contacts_cli_verified' => true,
                    'help_probes_verified' => true,
                    'runtime_artifacts_verified' => true,
                    'container_smoke_passed' => true,
                ]);
        });

        $this->artisan('sync360:sync-runtime-capabilities '.$tenant->slug.' gog')
            ->assertExitCode(0);
    }

    public function test_sync_runtime_capabilities_marks_google_failed_with_precise_error_when_cli_verification_breaks(): void
    {
        config()->set('sync360.infrastructure.driver', 'ssh');

        $tenant = $this->seedReadyTenant();

        $localRuntimePath = config('sync360.runtime_root').'/'.$tenant->slug;
        File::ensureDirectoryExists($localRuntimePath.'/config');
        File::put($localRuntimePath.'/.env', implode(PHP_EOL, [
            'OPENCLAW_GATEWAY_TOKEN=test-token',
            'OPENAI_API_KEY=sk-tenant-acme',
            'OPENAI_BASE_URL=https://litellm.stylesoftware.co.nz',
            '',
        ]));
        File::put($localRuntimePath.'/config/openclaw.json', json_encode([
            'agents' => [
                'defaults' => [
                    'model' => 'gpt-4o',
                ],
            ],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL);
        File::put($localRuntimePath.'/compose.yaml', 'services: {}'.PHP_EOL);

        $runner = Mockery::mock(DockerComposeRunner::class);
        $runner->shouldReceive('putFile')->andReturnNull();
        $runner->shouldReceive('runCommand')->andReturnNull();
        $this->instance(DockerComposeRunner::class, $runner);

        $this->mock(TenantGoogleWorkspaceSmokeTestService::class, function ($mock): void {
            $mock->shouldReceive('run')
                ->once()
                ->andThrow(new RuntimeException('gmail-cli failed: Gmail CLI failed: missing required query argument.'));
        });

        $this->artisan('sync360:sync-runtime-capabilities '.$tenant->slug.' gog')
            ->expectsOutputToContain('Failed: gmail-cli failed: Gmail CLI failed: missing required query argument.')
            ->assertExitCode(0);

        $credential = $tenant->fresh('googleCredential')->googleCredential;

        $this->assertSame(TenantGoogleCredential::RUNTIME_SYNC_FAILED, $credential?->runtime_sync_status);
        $this->assertSame('gmail-cli failed: Gmail CLI failed: missing required query argument.', $credential?->last_error);
    }

    public function test_google_workspace_smoke_command_reports_cli_suite_stages(): void
    {
        $tenant = $this->seedReadyTenant();

        $this->mock(TenantGoogleWorkspaceSmokeTestService::class, function ($mock): void {
            $mock->shouldReceive('run')
                ->once()
                ->andReturn([
                    'tenant_slug' => 'acme-plumbing',
                    'google_email' => 'owner@example.com',
                    'compose_file' => '/srv/sync360/runtime/tenants/acme-plumbing/compose.yaml',
                    'xdg_config_home_expected' => '/home/node/.openclaw/.openclaw',
                    'host_capability_verified' => true,
                    'container_binary_verified' => true,
                    'gog_env_verified' => true,
                    'gmail_cli_verified' => true,
                    'calendar_cli_verified' => true,
                    'drive_cli_verified' => true,
                    'contacts_cli_verified' => true,
                    'help_probes_verified' => true,
                    'runtime_artifacts_verified' => true,
                    'container_smoke_passed' => true,
                ]);
        });

        $this->artisan('sync360:test-google-workspace '.$tenant->slug)
            ->expectsOutputToContain('GOG runtime env')
            ->expectsOutputToContain('Gmail CLI')
            ->expectsOutputToContain('Calendar CLI')
            ->expectsOutputToContain('Drive CLI')
            ->expectsOutputToContain('Contacts CLI')
            ->expectsOutputToContain('Help probes')
            ->assertExitCode(0);
    }

    private function seedReadyTenant(): Tenant
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
            'skill_pack' => 'Client Support',
            'user_id' => $user->id,
            'server_id' => Server::query()->firstOrFail()->id,
            'trial_status' => TrialStatus::Active,
            'provisioning_status' => TenantProvisioningStatus::Ready,
            'assigned_port' => 4100,
            'runtime_path' => '/srv/sync360/runtime/tenants/acme-plumbing',
            'workspace_url' => 'https://acme-plumbing.workspace.test',
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

        return $tenant;
    }
}
