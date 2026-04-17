<?php

namespace Tests\Feature;

use App\Enums\TenantProvisioningStatus;
use App\Enums\TrialStatus;
use App\Models\BusinessProfile;
use App\Models\BusinessProfileFiles;
use App\Models\Server;
use App\Models\Tenant;
use App\Models\User;
use App\Services\TenantProfileSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class LiveTenantResyncCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_live_tenant_resync_command_only_resyncs_eligible_live_tenants(): void
    {
        $liveTenant = $this->seedTenant('live-tenant', [
            'onboarding_status' => 'complete',
            'onboarding_step' => 7,
            'agent_status' => 'live',
            'provisioning_status' => TenantProvisioningStatus::Ready,
            'workspace_url' => 'https://live-tenant.workspace.test',
            'runtime_path' => '/srv/sync360/runtime/tenants/live-tenant',
            'tone' => 'friendly',
            'capabilities' => ['faqs', 'messages'],
            'channel' => 'telegram',
            'channel_config' => ['telegram_bot_token' => 'telegram-bot-token'],
        ]);

        $this->seedTenant('pending-tenant', [
            'onboarding_status' => 'in_progress',
            'onboarding_step' => 4,
            'agent_status' => 'offline',
            'provisioning_status' => TenantProvisioningStatus::Pending,
        ]);

        $profileSync = Mockery::mock(TenantProfileSyncService::class);
        $profileSync->shouldReceive('regenerateAndSync')
            ->once()
            ->withArgs(fn (Tenant $tenant): bool => $tenant->is($liveTenant))
            ->andReturnNull();

        $this->instance(TenantProfileSyncService::class, $profileSync);

        $this->artisan('sync360:resync-live-tenants')
            ->expectsOutputToContain('Live tenant resync finished. Completed: 1. Failed: 0.')
            ->assertExitCode(0);
    }

    public function test_live_tenant_resync_command_can_target_a_specific_tenant(): void
    {
        $firstTenant = $this->seedTenant('first-live', [
            'onboarding_status' => 'complete',
            'onboarding_step' => 7,
            'agent_status' => 'live',
            'provisioning_status' => TenantProvisioningStatus::Ready,
            'workspace_url' => 'https://first-live.workspace.test',
            'runtime_path' => '/srv/sync360/runtime/tenants/first-live',
            'tone' => 'friendly',
            'capabilities' => ['faqs'],
            'channel' => 'telegram',
            'channel_config' => ['telegram_bot_token' => 'telegram-bot-token'],
        ]);

        $secondTenant = $this->seedTenant('second-live', [
            'onboarding_status' => 'complete',
            'onboarding_step' => 7,
            'agent_status' => 'live',
            'provisioning_status' => TenantProvisioningStatus::Ready,
            'workspace_url' => 'https://second-live.workspace.test',
            'runtime_path' => '/srv/sync360/runtime/tenants/second-live',
            'tone' => 'friendly',
            'capabilities' => ['faqs'],
            'channel' => 'telegram',
            'channel_config' => ['telegram_bot_token' => 'telegram-bot-token'],
        ]);

        $profileSync = Mockery::mock(TenantProfileSyncService::class);
        $profileSync->shouldReceive('regenerateAndSync')
            ->once()
            ->withArgs(fn (Tenant $tenant): bool => $tenant->is($secondTenant))
            ->andReturnNull();

        $this->instance(TenantProfileSyncService::class, $profileSync);

        $this->artisan('sync360:resync-live-tenants '.$secondTenant->slug)
            ->expectsOutputToContain('Live tenant resync finished. Completed: 1. Failed: 0.')
            ->assertExitCode(0);
    }

    private function seedTenant(string $slug, array $overrides = []): Tenant
    {
        $user = User::query()->create([
            'name' => ucfirst(str_replace('-', ' ', $slug)),
            'email' => $slug.'@example.com',
            'password' => 'super-secret',
        ]);

        $tenant = Tenant::query()->create(array_merge([
            'tenant_id' => 'tenant_'.$slug,
            'slug' => $slug,
            'business_name' => ucfirst(str_replace('-', ' ', $slug)),
            'industry' => 'Services',
            'skill_pack' => 'Client Support',
            'user_id' => $user->id,
            'server_id' => Server::query()->firstOrFail()->id,
            'trial_status' => TrialStatus::Active,
            'provisioning_status' => TenantProvisioningStatus::Pending,
            'onboarding_status' => 'pending',
            'onboarding_step' => 0,
            'agent_status' => 'offline',
        ], $overrides));

        BusinessProfile::query()->create([
            'tenant_id' => $tenant->id,
            'business_name' => $tenant->business_name,
            'industry' => $tenant->industry,
            'description' => 'Helpful business description.',
            'contact_email' => $user->email,
            'services' => ['General support'],
        ]);

        BusinessProfileFiles::query()->create([
            'tenant_id' => $tenant->id,
            'identity_markdown' => '# Identity',
            'soul_markdown' => '# Soul',
            'user_markdown' => '# User',
            'bootstrap_markdown' => '# Bootstrap',
            'generated_at' => now(),
        ]);

        return $tenant;
    }
}
