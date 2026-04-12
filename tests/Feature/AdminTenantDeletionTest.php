<?php

namespace Tests\Feature;

use App\Contracts\DockerComposeRunner;
use App\Enums\TenantProvisioningStatus;
use App\Enums\TrialStatus;
use App\Models\BusinessProfile;
use App\Models\BusinessProfileFiles;
use App\Models\ConversationLog;
use App\Models\ProvisioningJob;
use App\Models\Server;
use App\Models\Tenant;
use App\Models\User;
use App\Services\LiteLlmTenantKeyService;
use App\Services\TenantDeletionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class AdminTenantDeletionTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_permanently_delete_tenant_and_customer_account(): void
    {
        $admin = $this->createAdmin();
        [$user, $tenant] = $this->seedTenantForDeletion();

        $runner = Mockery::mock(DockerComposeRunner::class);
        $runner->shouldReceive('runCommand')
            ->once()
            ->ordered()
            ->withArgs(fn (Server $server, string $command, bool $sudo = false): bool => $server->is($tenant->server) && str_contains($command, 'down --remove-orphans') && ! $sudo)
            ->andReturnNull();
        $runner->shouldReceive('removeFile')
            ->once()
            ->ordered()
            ->withArgs(fn (Server $server, string $path, bool $sudo = false): bool => $server->is($tenant->server) && str_contains($path, $tenant->slug.'.caddy') && $sudo)
            ->andReturnNull();
        $runner->shouldReceive('runCommand')
            ->once()
            ->ordered()
            ->withArgs(fn (Server $server, string $command, bool $sudo = false): bool => $server->is($tenant->server) && $command === 'systemctl reload caddy' && $sudo)
            ->andReturnNull();
        $runner->shouldReceive('removeDirectory')
            ->once()
            ->ordered()
            ->withArgs(fn (Server $server, string $path, bool $sudo = false): bool => $server->is($tenant->server) && $path === $tenant->runtime_path && ! $sudo)
            ->andReturnNull();

        $liteLlm = Mockery::mock(LiteLlmTenantKeyService::class);
        $liteLlm->shouldReceive('deleteTenantKey')
            ->once()
            ->withArgs(fn (Tenant $deletingTenant): bool => $deletingTenant->is($tenant))
            ->andReturnNull();

        $this->instance(DockerComposeRunner::class, $runner);
        $this->instance(LiteLlmTenantKeyService::class, $liteLlm);

        $this->withoutMiddleware(VerifyCsrfToken::class);
        $this->actingAs($admin);

        $this->delete(route('admin.tenants.destroy', $tenant), [
            'confirmation_slug' => $tenant->slug,
        ])->assertRedirect(route('admin.tenants'))
            ->assertSessionHas('status', 'Tenant delete-me and its linked customer account were permanently deleted.');

        $this->assertDatabaseMissing('users', ['id' => $user->id]);
        $this->assertDatabaseMissing('tenants', ['id' => $tenant->id]);
        $this->assertDatabaseCount('provisioning_jobs', 0);
        $this->assertDatabaseCount('business_profiles', 0);
        $this->assertDatabaseCount('business_profile_files', 0);
        $this->assertDatabaseCount('conversation_logs', 0);
    }

    public function test_delete_is_blocked_when_remote_cleanup_fails(): void
    {
        $admin = $this->createAdmin();
        [$user, $tenant] = $this->seedTenantForDeletion();

        $runner = Mockery::mock(DockerComposeRunner::class);
        $runner->shouldReceive('runCommand')
            ->once()
            ->andThrow(new RuntimeException('Remote cleanup failed.'));

        $liteLlm = Mockery::mock(LiteLlmTenantKeyService::class);
        $liteLlm->shouldNotReceive('deleteTenantKey');

        $this->instance(DockerComposeRunner::class, $runner);
        $this->instance(LiteLlmTenantKeyService::class, $liteLlm);

        $this->actingAs($admin);

        $this->from(route('admin.tenants.show', $tenant))
            ->delete(route('admin.tenants.destroy', $tenant), [
                'confirmation_slug' => $tenant->slug,
            ])
            ->assertRedirect(route('admin.tenants.show', $tenant))
            ->assertSessionHas('status', 'Remote cleanup failed.');

        $this->assertDatabaseHas('users', ['id' => $user->id]);
        $this->assertDatabaseHas('tenants', ['id' => $tenant->id]);
    }

    public function test_delete_is_blocked_when_litellm_cleanup_fails(): void
    {
        $admin = $this->createAdmin();
        [$user, $tenant] = $this->seedTenantForDeletion();

        $runner = Mockery::mock(DockerComposeRunner::class);
        $runner->shouldReceive('runCommand')->twice()->andReturnNull();
        $runner->shouldReceive('removeFile')->once()->andReturnNull();
        $runner->shouldReceive('removeDirectory')->once()->andReturnNull();

        $liteLlm = Mockery::mock(LiteLlmTenantKeyService::class);
        $liteLlm->shouldReceive('deleteTenantKey')
            ->once()
            ->andThrow(new RuntimeException('LiteLLM delete failed.'));

        $this->instance(DockerComposeRunner::class, $runner);
        $this->instance(LiteLlmTenantKeyService::class, $liteLlm);

        $this->actingAs($admin);

        $this->from(route('admin.tenants.show', $tenant))
            ->delete(route('admin.tenants.destroy', $tenant), [
                'confirmation_slug' => $tenant->slug,
            ])
            ->assertRedirect(route('admin.tenants.show', $tenant))
            ->assertSessionHas('status', 'LiteLLM delete failed.');

        $this->assertDatabaseHas('users', ['id' => $user->id]);
        $this->assertDatabaseHas('tenants', ['id' => $tenant->id]);
    }

    public function test_delete_succeeds_for_unprovisioned_tenant_with_missing_runtime_artifacts(): void
    {
        $admin = $this->createAdmin();
        [$user, $tenant] = $this->seedTenantForDeletion([
            'runtime_path' => null,
            'workspace_url' => null,
            'assigned_port' => null,
            'provisioning_status' => TenantProvisioningStatus::Pending,
            'litellm_virtual_key' => null,
        ]);

        $runner = Mockery::mock(DockerComposeRunner::class);
        $runner->shouldReceive('runCommand')->twice()->andReturnNull();
        $runner->shouldReceive('removeFile')->once()->andReturnNull();
        $runner->shouldReceive('removeDirectory')
            ->once()
            ->withArgs(fn (Server $server, string $path, bool $sudo = false): bool => $server->is($tenant->server) && $path === '/srv/sync360/runtime/tenants/'.$tenant->slug && ! $sudo)
            ->andReturnNull();

        $liteLlm = Mockery::mock(LiteLlmTenantKeyService::class);
        $liteLlm->shouldReceive('deleteTenantKey')->once()->andReturnNull();

        $this->instance(DockerComposeRunner::class, $runner);
        $this->instance(LiteLlmTenantKeyService::class, $liteLlm);

        $this->actingAs($admin);

        $this->delete(route('admin.tenants.destroy', $tenant), [
            'confirmation_slug' => $tenant->slug,
        ])->assertRedirect(route('admin.tenants'));

        $this->assertDatabaseMissing('users', ['id' => $user->id]);
        $this->assertDatabaseMissing('tenants', ['id' => $tenant->id]);
    }

    public function test_delete_is_blocked_for_tenant_linked_to_admin_account(): void
    {
        $admin = $this->createAdmin();
        [$user, $tenant] = $this->seedTenantForDeletion();

        $user->forceFill(['is_admin' => true])->save();

        $runner = Mockery::mock(DockerComposeRunner::class);
        $runner->shouldNotReceive('runCommand');
        $runner->shouldNotReceive('removeFile');
        $runner->shouldNotReceive('removeDirectory');

        $liteLlm = Mockery::mock(LiteLlmTenantKeyService::class);
        $liteLlm->shouldNotReceive('deleteTenantKey');

        $this->instance(DockerComposeRunner::class, $runner);
        $this->instance(LiteLlmTenantKeyService::class, $liteLlm);

        $this->actingAs($admin);

        $this->from(route('admin.tenants.show', $tenant))
            ->delete(route('admin.tenants.destroy', $tenant), [
                'confirmation_slug' => $tenant->slug,
            ])
            ->assertRedirect(route('admin.tenants.show', $tenant))
            ->assertSessionHas('status', 'This tenant is linked to an admin account and must be handled manually.');

        $this->assertDatabaseHas('users', ['id' => $user->id]);
        $this->assertDatabaseHas('tenants', ['id' => $tenant->id]);
    }

    public function test_delete_uses_local_bypass_in_local_environment(): void
    {
        [$user, $tenant] = $this->seedTenantForDeletion([
            'runtime_path' => $this->testProvisioningBase.'/runtime/delete-me',
        ]);

        $this->app['env'] = 'local';

        File::ensureDirectoryExists($tenant->runtime_path);
        File::put($tenant->runtime_path.'/compose.yaml', "services:\n  demo:\n    image: alpine:latest\n");

        Process::fake([
            '*' => Process::result(),
        ]);

        $runner = Mockery::mock(DockerComposeRunner::class);
        $runner->shouldNotReceive('runCommand');
        $runner->shouldNotReceive('removeFile');
        $runner->shouldNotReceive('removeDirectory');

        $liteLlm = Mockery::mock(LiteLlmTenantKeyService::class);
        $liteLlm->shouldReceive('deleteTenantKey')->once()->andReturnNull();

        $this->instance(DockerComposeRunner::class, $runner);
        $this->instance(LiteLlmTenantKeyService::class, $liteLlm);

        app(TenantDeletionService::class)->deletePermanently($tenant);

        Process::assertRan(fn ($process) => str_contains($process->command, 'docker compose -f'));
        $this->assertDatabaseMissing('users', ['id' => $user->id]);
        $this->assertDatabaseMissing('tenants', ['id' => $tenant->id]);
    }

    private function createAdmin(): User
    {
        return User::query()->create([
            'name' => 'Delete Admin',
            'email' => 'delete-admin@example.com',
            'password' => 'super-secret',
            'is_admin' => true,
        ]);
    }

    /**
     * @param  array<string, mixed>  $tenantOverrides
     * @return array{0: User, 1: Tenant}
     */
    private function seedTenantForDeletion(array $tenantOverrides = []): array
    {
        $server = Server::query()->firstOrFail();
        $server->forceFill([
            'workspace_base_domain' => 'workspace.test',
            'caddy_sites_path' => '/etc/caddy/sites',
            'caddy_reload_command' => 'systemctl reload caddy',
            'runtime_root' => '/srv/sync360/runtime',
            'docker_compose_bin' => 'docker compose',
        ])->save();

        $user = User::query()->create([
            'name' => 'Delete Me',
            'email' => 'delete-me@example.com',
            'password' => 'super-secret',
            'is_admin' => false,
        ]);

        $tenant = Tenant::query()->create(array_merge([
            'tenant_id' => 'tenant_delete_01',
            'slug' => 'delete-me',
            'business_name' => 'Delete Me Plumbing',
            'industry' => 'Trades',
            'skill_pack' => 'Operations Core',
            'user_id' => $user->id,
            'server_id' => $server->id,
            'trial_status' => TrialStatus::Active,
            'provisioning_status' => TenantProvisioningStatus::Ready,
            'agent_status' => 'live',
            'workspace_url' => 'https://delete-me.workspace.test',
            'runtime_path' => '/srv/sync360/runtime/tenants/delete-me',
            'assigned_port' => 4101,
            'litellm_virtual_key' => 'encrypted-tenant-key',
            'litellm_key_alias' => 'openclaw-tenant_delete_01',
            'litellm_plan_name' => 'trial',
            'litellm_max_budget' => 25,
            'litellm_budget_duration' => 'monthly',
        ], $tenantOverrides));

        ProvisioningJob::query()->create([
            'tenant_id' => $tenant->id,
            'job_type' => 'provision_tenant',
            'status' => \App\Enums\ProvisioningJobStatus::Failed,
            'error_message' => 'Latest provisioning issue.',
        ]);

        BusinessProfile::query()->create([
            'tenant_id' => $tenant->id,
            'business_name' => $tenant->business_name,
            'industry' => $tenant->industry,
            'description' => 'A tenant being prepared for deletion tests.',
        ]);

        BusinessProfileFiles::query()->create([
            'tenant_id' => $tenant->id,
            'identity_markdown' => '# Identity',
        ]);

        ConversationLog::query()->create([
            'tenant_id' => $tenant->id,
            'channel' => 'telegram',
            'external_message_id' => 'telegram-delete-1',
            'from_identifier' => '@customer',
            'message_in' => 'Need help',
        ]);

        return [$user, $tenant];
    }
}
