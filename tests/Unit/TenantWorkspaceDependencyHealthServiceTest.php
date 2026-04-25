<?php

namespace Tests\Unit;

use App\Enums\TenantProvisioningStatus;
use App\Enums\TrialStatus;
use App\Models\Server;
use App\Models\SkillCatalogItem;
use App\Models\SkillCatalogVersion;
use App\Models\Tenant;
use App\Models\TenantGoogleCredential;
use App\Models\TenantInboxMonitorState;
use App\Models\TenantSkillAssignment;
use App\Models\User;
use App\Services\TenantWorkspaceDependencyHealthService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TenantWorkspaceDependencyHealthServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_connected_but_stale_google_workspace_is_not_reported_as_healthy(): void
    {
        $tenant = $this->makeTenant();

        TenantGoogleCredential::query()->create([
            'tenant_id' => $tenant->id,
            'status' => TenantGoogleCredential::STATUS_CONNECTED,
            'runtime_sync_status' => TenantGoogleCredential::RUNTIME_SYNC_VERIFIED,
            'google_email' => 'owner@example.com',
            'refresh_token' => 'refresh-token',
            'connected_at' => now()->subDays(2),
            'last_synced_at' => now()->subHours(5),
        ]);

        $health = app(TenantWorkspaceDependencyHealthService::class)->evaluate($tenant->fresh([
            'googleCredential',
            'inboxMonitorState',
            'skillAssignments.catalogVersion',
        ]));

        $this->assertSame(TenantGoogleCredential::HEALTH_DEGRADED, $health['google_workspace']['health_status']);
        $this->assertSame('Needs attention', $health['google_workspace']['health_label']);
    }

    public function test_auth_failure_maps_to_reconnect_required_and_inbox_down(): void
    {
        $tenant = $this->makeInboxTenant();

        TenantGoogleCredential::query()->create([
            'tenant_id' => $tenant->id,
            'status' => TenantGoogleCredential::STATUS_CONNECTED,
            'runtime_sync_status' => TenantGoogleCredential::RUNTIME_SYNC_FAILED,
            'google_email' => 'owner@example.com',
            'refresh_token' => 'refresh-token',
            'connected_at' => now()->subDays(1),
            'last_error' => 'oauth2: "invalid_grant" "Token has been expired or revoked."',
        ]);

        TenantInboxMonitorState::query()->create([
            'tenant_id' => $tenant->id,
            'enabled' => true,
            'status' => TenantInboxMonitorState::STATUS_FAILED,
            'last_checked_at' => now()->subMinutes(20),
            'last_error' => 'invalid_grant while searching Gmail',
        ]);

        $health = app(TenantWorkspaceDependencyHealthService::class)->evaluate($tenant->fresh([
            'googleCredential',
            'inboxMonitorState',
            'skillAssignments.catalogVersion',
        ]));

        $this->assertSame(TenantGoogleCredential::HEALTH_RECONNECT_REQUIRED, $health['google_workspace']['health_status']);
        $this->assertTrue($health['google_workspace']['requires_reconnect']);
        $this->assertSame(TenantInboxMonitorState::HEALTH_DOWN, $health['inbox_monitor']['health_status']);
    }

    public function test_testing_mode_predicts_expiry_from_connected_at(): void
    {
        config()->set('services.google.oauth_app_mode', 'testing');

        $tenant = $this->makeTenant();

        TenantGoogleCredential::query()->create([
            'tenant_id' => $tenant->id,
            'status' => TenantGoogleCredential::STATUS_CONNECTED,
            'runtime_sync_status' => TenantGoogleCredential::RUNTIME_SYNC_VERIFIED,
            'google_email' => 'owner@example.com',
            'refresh_token' => 'refresh-token',
            'connected_at' => now()->subDays(6)->subHours(2),
            'last_verified_at' => now(),
        ]);

        $health = app(TenantWorkspaceDependencyHealthService::class)->evaluate($tenant->fresh([
            'googleCredential',
            'inboxMonitorState',
            'skillAssignments.catalogVersion',
        ]));

        $this->assertSame(TenantGoogleCredential::HEALTH_EXPIRING_SOON, $health['google_workspace']['health_status']);
        $this->assertNotNull($health['google_workspace']['predicted_expiry_at']);
    }

    public function test_live_mode_does_not_compute_predictive_expiry(): void
    {
        config()->set('services.google.oauth_app_mode', 'live');

        $tenant = $this->makeTenant();

        TenantGoogleCredential::query()->create([
            'tenant_id' => $tenant->id,
            'status' => TenantGoogleCredential::STATUS_CONNECTED,
            'runtime_sync_status' => TenantGoogleCredential::RUNTIME_SYNC_VERIFIED,
            'google_email' => 'owner@example.com',
            'refresh_token' => 'refresh-token',
            'connected_at' => now()->subDays(6)->subHours(2),
            'last_verified_at' => now(),
        ]);

        $health = app(TenantWorkspaceDependencyHealthService::class)->evaluate($tenant->fresh([
            'googleCredential',
            'inboxMonitorState',
            'skillAssignments.catalogVersion',
        ]));

        $this->assertSame(TenantGoogleCredential::HEALTH_HEALTHY, $health['google_workspace']['health_status']);
        $this->assertNull($health['google_workspace']['predicted_expiry_at']);
    }

    public function test_inbox_health_uses_fresh_google_evaluation_not_stale_persisted_google_health(): void
    {
        config()->set('services.google.oauth_app_mode', 'live');

        $tenant = $this->makeInboxTenant();

        TenantGoogleCredential::query()->create([
            'tenant_id' => $tenant->id,
            'status' => TenantGoogleCredential::STATUS_CONNECTED,
            'runtime_sync_status' => TenantGoogleCredential::RUNTIME_SYNC_VERIFIED,
            'health_status' => TenantGoogleCredential::HEALTH_RECONNECT_REQUIRED,
            'google_email' => 'owner@example.com',
            'refresh_token' => 'refresh-token',
            'connected_at' => now()->subDay(),
            'last_verified_at' => now(),
            'health_checked_at' => now()->subHour(),
            'last_error' => null,
        ]);

        TenantInboxMonitorState::query()->create([
            'tenant_id' => $tenant->id,
            'enabled' => true,
            'status' => TenantInboxMonitorState::STATUS_IDLE,
            'health_status' => TenantInboxMonitorState::HEALTH_DOWN,
            'last_checked_at' => now()->subMinutes(5),
            'health_checked_at' => now()->subHour(),
            'consecutive_failures' => 0,
            'last_error' => null,
        ]);

        $health = app(TenantWorkspaceDependencyHealthService::class)->evaluate($tenant->fresh([
            'googleCredential',
            'inboxMonitorState',
            'skillAssignments.catalogVersion',
        ]));

        $this->assertSame(TenantGoogleCredential::HEALTH_HEALTHY, $health['google_workspace']['health_status']);
        $this->assertSame(TenantInboxMonitorState::HEALTH_HEALTHY, $health['inbox_monitor']['health_status']);
        $this->assertSame('Watching your inbox', $health['inbox_monitor']['health_label']);
    }

    private function makeTenant(): Tenant
    {
        $user = User::factory()->create();

        return Tenant::query()->create([
            'tenant_id' => 'tenant-health-'.str()->random(8),
            'slug' => 'health-'.str()->random(8),
            'business_name' => 'Health Tenant',
            'industry' => 'Testing',
            'skill_pack' => 'Core Modules',
            'user_id' => $user->id,
            'server_id' => Server::query()->firstOrFail()->id,
            'trial_status' => TrialStatus::Active->value,
            'provisioning_status' => TenantProvisioningStatus::Ready->value,
            'onboarding_status' => 'complete',
            'onboarding_step' => 7,
            'agent_status' => 'live',
            'runtime_path' => '/srv/sync360/runtime/tenants/health-tenant',
            'workspace_url' => 'https://health-tenant.workspace.test',
        ]);
    }

    private function makeInboxTenant(): Tenant
    {
        $tenant = $this->makeTenant();
        $item = SkillCatalogItem::query()->create([
            'skill_key' => 'inbox-triage',
            'label' => 'Inbox Triage',
            'description' => 'Inbox',
            'onboarding_role' => 'core',
        ]);
        $version = SkillCatalogVersion::query()->create([
            'skill_catalog_item_id' => $item->id,
            'skill_key' => 'inbox-triage',
            'version' => '1.5.9',
            'manifest_json' => ['key' => 'inbox-triage'],
            'is_available' => true,
            'is_active_published' => true,
        ]);

        TenantSkillAssignment::query()->create([
            'tenant_id' => $tenant->id,
            'skill_key' => 'inbox-triage',
            'skill_catalog_version_id' => $version->id,
            'is_enabled' => true,
        ]);

        return $tenant;
    }
}
