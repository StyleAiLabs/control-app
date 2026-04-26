<?php

namespace Tests\Unit;

use App\Contracts\DockerComposeRunner;
use App\Enums\TenantProvisioningStatus;
use App\Enums\TrialStatus;
use App\Models\Server;
use App\Models\Tenant;
use App\Models\TenantGoogleCredential;
use App\Models\User;
use App\Services\TenantRuntimeCapabilityService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class TenantRuntimeCapabilityServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_capability_catalog_exposes_pinned_gog_metadata(): void
    {
        /** @var TenantRuntimeCapabilityService $service */
        $service = app(TenantRuntimeCapabilityService::class);

        $definition = $service->selectedDefinitions(['gog'])['gog'];

        $this->assertSame('binary_download', $definition['install_strategy']);
        $this->assertSame('v0.12.0', $definition['version']);
        $this->assertSame(
            'https://github.com/steipete/gogcli/releases/download/v0.12.0/gogcli_0.12.0_linux_amd64.tar.gz',
            $definition['download_url'],
        );
        $this->assertSame('a03fccbd67ea2e59a26a56e92de8918577f4bebe4b2f946823419777827cdab2', $definition['sha256']);
        $this->assertSame('/usr/local/bin/gog', $definition['host_install_path']);
        $this->assertSame('gog', $definition['archive_binary_path']);
    }

    public function test_capability_catalog_exposes_pinned_weasyprint_metadata(): void
    {
        /** @var TenantRuntimeCapabilityService $service */
        $service = app(TenantRuntimeCapabilityService::class);

        $definition = $service->selectedDefinitions(['weasyprint'])['weasyprint'];

        $this->assertSame('python_venv', $definition['install_strategy']);
        $this->assertSame('61.2', $definition['version']);
        $this->assertSame('/opt/sync360/weasyprint', $definition['host_root']);
        $this->assertSame('/opt/sync360/weasyprint/venv', $definition['venv_path']);
        $this->assertSame('/opt/sync360/weasyprint/venv/bin/weasyprint', $definition['host_install_path']);
        $this->assertSame('weasyprint==61.2', $definition['python_package']);
    }

    public function test_render_compose_includes_unconditional_gog_mount(): void
    {
        /** @var TenantRuntimeCapabilityService $service */
        $service = app(TenantRuntimeCapabilityService::class);
        $tenant = $this->seedTenant();

        $contents = $service->renderCompose(
            $tenant,
            '/srv/sync360/runtime/tenants/acme-plumbing',
            4100,
            'gateway-token',
            'sk-tenant-acme',
            'https://litellm.stylesoftware.co.nz',
        );

        $this->assertStringContainsString('source: "/usr/local/bin/gog"', $contents);
        $this->assertStringContainsString('target: "/usr/local/bin/gog"', $contents);
        $this->assertStringContainsString('read_only: true', $contents);
        $this->assertStringContainsString('GOG_ENABLE_COMMANDS: "gmail,calendar,drive,contacts,tasks,sheets,docs,slides,people,chat,classroom,forms,appscript,groups"', $contents);
    }

    public function test_render_compose_includes_weasyprint_mount_and_env(): void
    {
        /** @var TenantRuntimeCapabilityService $service */
        $service = app(TenantRuntimeCapabilityService::class);
        $tenant = $this->seedTenant();

        $contents = $service->renderCompose(
            $tenant,
            '/srv/sync360/runtime/tenants/acme-plumbing',
            4100,
            'gateway-token',
            'sk-tenant-acme',
            'https://litellm.stylesoftware.co.nz',
            ['weasyprint'],
        );

        $this->assertStringContainsString('source: "/opt/sync360/weasyprint"', $contents);
        $this->assertStringContainsString('target: "/opt/sync360/weasyprint"', $contents);
        $this->assertStringContainsString('WEASYPRINT_BIN: "/opt/sync360/weasyprint/venv/bin/weasyprint"', $contents);
    }

    public function test_render_compose_includes_gog_account_for_connected_google_workspace(): void
    {
        /** @var TenantRuntimeCapabilityService $service */
        $service = app(TenantRuntimeCapabilityService::class);
        $tenant = $this->seedTenant();

        $tenant->googleCredential()->create([
            'status' => TenantGoogleCredential::STATUS_CONNECTED,
            'runtime_sync_status' => TenantGoogleCredential::RUNTIME_SYNC_VERIFIED,
            'google_email' => 'owner@example.com',
            'access_token' => 'google-access-token',
            'refresh_token' => 'google-refresh-token',
            'scopes' => ['openid', 'email'],
            'connected_at' => now(),
        ]);

        $contents = $service->renderCompose(
            $tenant->fresh('googleCredential'),
            '/srv/sync360/runtime/tenants/acme-plumbing',
            4100,
            'gateway-token',
            'sk-tenant-acme',
            'https://litellm.stylesoftware.co.nz',
        );

        $this->assertStringContainsString('GOG_ACCOUNT: "owner@example.com"', $contents);
    }

    public function test_ensure_installed_on_server_skips_reinstall_when_pinned_version_matches(): void
    {
        config()->set('sync360.infrastructure.driver', 'ssh');

        $runner = Mockery::mock(DockerComposeRunner::class);
        $runner->shouldReceive('runCommand')
            ->once()
            ->withArgs(function (Server $server, string $command, bool $sudo = false): bool {
                return $server->name === 'test-vps'
                    && str_contains($command, '/usr/local/bin/gog')
                    && str_contains($command, 'v0.12.0')
                    && $sudo === true;
            })
            ->andReturnNull();

        $this->instance(DockerComposeRunner::class, $runner);

        /** @var TenantRuntimeCapabilityService $service */
        $service = app(TenantRuntimeCapabilityService::class);
        $server = Server::query()->firstOrFail();

        $this->assertSame(['gog' => 'already-installed'], $service->ensureInstalledOnServer($server, ['gog']));
    }

    public function test_ensure_installed_on_server_installs_weasyprint_python_venv_when_missing(): void
    {
        config()->set('sync360.infrastructure.driver', 'ssh');

        $runner = Mockery::mock(DockerComposeRunner::class);
        $runner->shouldReceive('runCommand')
            ->once()
            ->withArgs(function (Server $server, string $command, bool $sudo = false): bool {
                return $server->name === 'test-vps'
                    && str_contains($command, '/opt/sync360/weasyprint/venv/bin/weasyprint')
                    && str_contains($command, '61.2')
                    && $sudo === true;
            })
            ->andThrow(new \RuntimeException('missing weasyprint'));
        $runner->shouldReceive('runCommand')
            ->once()
            ->withArgs(function (Server $server, string $command, bool $sudo = false): bool {
                return $server->name === 'test-vps'
                    && str_contains($command, 'apt-get update')
                    && str_contains($command, 'python3-venv')
                    && str_contains($command, 'libpangocairo-1.0-0')
                    && $sudo === true;
            })
            ->andReturnNull();
        $runner->shouldReceive('runCommand')
            ->once()
            ->withArgs(function (Server $server, string $command, bool $sudo = false): bool {
                return $server->name === 'test-vps'
                    && str_contains($command, 'python3 -m venv')
                    && str_contains($command, 'weasyprint==61.2')
                    && str_contains($command, '/opt/sync360/weasyprint/venv')
                    && $sudo === true;
            })
            ->andReturnNull();
        $runner->shouldReceive('runCommand')
            ->times(3)
            ->withArgs(function (Server $server, string $command, bool $sudo = false): bool {
                return $server->name === 'test-vps'
                    && (
                        str_contains($command, 'test -x \'/opt/sync360/weasyprint/venv/bin/weasyprint\'')
                        || str_contains($command, '--version 2>&1 | grep -qF \'61.2\'')
                        || str_contains($command, '--info >/dev/null 2>&1')
                    )
                    && $sudo === true;
            })
            ->andReturnNull();

        $this->instance(DockerComposeRunner::class, $runner);

        /** @var TenantRuntimeCapabilityService $service */
        $service = app(TenantRuntimeCapabilityService::class);
        $server = Server::query()->firstOrFail();

        $this->assertSame(['weasyprint' => 'installed'], $service->ensureInstalledOnServer($server, ['weasyprint']));
    }

    private function seedTenant(): Tenant
    {
        $user = User::query()->create([
            'name' => 'Alice Admin',
            'email' => 'alice@example.com',
            'password' => 'super-secret',
        ]);

        return Tenant::query()->create([
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
    }
}
