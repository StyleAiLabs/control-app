<?php

namespace Tests\Feature;

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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TenantSetupFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_tenant_setup_does_not_redirect_to_workspace_ready_until_customer_ready(): void
    {
        [$user] = $this->seedProvisionedTenant();

        $this->actingAs($user);

        $this->get('/tenant/setup')
            ->assertOk()
            ->assertSee('Getting Ready');
    }

    public function test_tenant_status_hides_ready_redirect_until_google_is_verified(): void
    {
        [$user] = $this->seedProvisionedTenant();

        $this->actingAs($user);

        $this->get('/tenant/status')
            ->assertOk()
            ->assertJsonPath('provisioning_status', 'ready')
            ->assertJsonPath('ready_redirect', null)
            ->assertJsonPath('workspace_route', null)
            ->assertJsonPath('runtime_ready', true)
            ->assertJsonPath('customer_ready', false)
            ->assertJsonPath('blocking_code', 'google_connect_required');
    }

    public function test_workspace_ready_route_shows_google_blocker_until_customer_ready(): void
    {
        [$user] = $this->seedProvisionedTenant();

        $this->actingAs($user);

        $this->get('/tenant/workspace-ready')
            ->assertOk()
            ->assertSee('Connect Google Workspace')
            ->assertSee('Continue Setup')
            ->assertDontSee('Open Your Sync360 Workspace');
    }

    public function test_workspace_ready_route_stays_available_for_grandfathered_live_skipped_tenant(): void
    {
        [$user, $tenant] = $this->seedProvisionedTenant();

        $tenant->forceFill([
            'onboarding_status' => 'complete',
            'onboarding_step' => 7,
            'agent_status' => 'live',
        ])->save();

        $tenant->googleCredential()->update([
            'status' => TenantGoogleCredential::STATUS_SKIPPED,
        ]);

        $this->actingAs($user);

        $this->get('/tenant/workspace-ready')
            ->assertOk()
            ->assertSee('your workspace is ready.', escape: false)
            ->assertSee('Open Your Sync360 Workspace');
    }

    public function test_tenant_setup_page_shows_dependency_health_when_google_requires_reconnect(): void
    {
        [$user, $tenant] = $this->seedProvisionedTenant();

        $tenant->googleCredential->forceFill([
            'status' => TenantGoogleCredential::STATUS_CONNECTED,
            'runtime_sync_status' => TenantGoogleCredential::RUNTIME_SYNC_FAILED,
            'refresh_token' => 'refresh-token',
            'google_email' => 'owner@example.com',
            'health_status' => TenantGoogleCredential::HEALTH_RECONNECT_REQUIRED,
            'health_checked_at' => now(),
            'last_error' => 'invalid_grant: Token has been expired or revoked.',
        ])->save();

        $tenant->inboxMonitorState()->create([
            'skill_key' => 'inbox-triage',
            'is_enabled' => true,
            'health_status' => TenantInboxMonitorState::HEALTH_DOWN,
            'last_error' => 'invalid_grant: Token has been expired or revoked.',
            'last_checked_at' => now()->subMinutes(30),
            'health_checked_at' => now(),
        ]);

        $this->actingAs($user);

        $this->get('/tenant/setup')
            ->assertOk()
            ->assertSee('Dependency Health')
            ->assertSee('Reconnect Google Workspace to restore inbox monitoring and live tools.');

        $this->get('/tenant/status')
            ->assertOk()
            ->assertJsonPath('dependency_health.google_workspace.health_status', TenantGoogleCredential::HEALTH_RECONNECT_REQUIRED)
            ->assertJsonPath('dependency_health.inbox_monitor.health_status', TenantInboxMonitorState::HEALTH_NOT_ENABLED);
    }

    public function test_workspace_ready_route_shows_workspace_dependencies_when_inbox_monitor_is_down(): void
    {
        [$user, $tenant] = $this->seedProvisionedTenant();

        $tenant->forceFill([
            'onboarding_status' => 'complete',
            'onboarding_step' => 7,
            'agent_status' => 'live',
        ])->save();

        $tenant->googleCredential->forceFill([
            'status' => TenantGoogleCredential::STATUS_CONNECTED,
            'runtime_sync_status' => TenantGoogleCredential::RUNTIME_SYNC_VERIFIED,
            'refresh_token' => 'refresh-token',
            'google_email' => 'owner@example.com',
            'health_status' => TenantGoogleCredential::HEALTH_HEALTHY,
            'health_checked_at' => now(),
            'last_verified_at' => now(),
        ])->save();

        $tenant->inboxMonitorState()->create([
            'skill_key' => 'inbox-triage',
            'is_enabled' => true,
            'health_status' => TenantInboxMonitorState::HEALTH_DOWN,
            'last_error' => 'Polling stalled for too long.',
            'last_checked_at' => now()->subHours(2),
            'health_checked_at' => now(),
            'incident_alert_sent_at' => now()->subHour(),
            'incident_alert_reason' => 'polling_down',
        ]);
        $this->assignInboxTriage($tenant, $user);

        $this->actingAs($user);

        $this->get('/tenant/workspace-ready')
            ->assertOk()
            ->assertSee('Workspace Dependencies')
            ->assertSee('We’re not currently checking your inbox. Reconnect Google Workspace or review setup.')
            ->assertSee('Reconnect Google Workspace');
    }

    /**
     * @return array{0: User, 1: Tenant}
     */
    private function seedProvisionedTenant(): array
    {
        $user = User::query()->create([
            'name' => 'Alice Admin',
            'email' => 'alice@example.com',
            'password' => 'super-secret',
        ]);

        $tenant = Tenant::query()->create([
            'tenant_id' => 'tenant_setup_01',
            'slug' => 'acme-plumbing',
            'business_name' => 'Acme Plumbing',
            'industry' => 'Trades',
            'skill_pack' => 'Operations Core',
            'user_id' => $user->id,
            'server_id' => Server::query()->firstOrFail()->id,
            'trial_status' => TrialStatus::Active,
            'provisioning_status' => TenantProvisioningStatus::Ready,
            'workspace_url' => 'https://acme-plumbing.workspace.test',
            'runtime_path' => '/srv/sync360/runtime/tenants/acme-plumbing',
            'onboarding_status' => 'in_progress',
            'onboarding_step' => 5,
            'agent_status' => 'offline',
        ]);

        $tenant->googleCredential()->create([
            'status' => TenantGoogleCredential::STATUS_PENDING,
            'runtime_sync_status' => TenantGoogleCredential::RUNTIME_SYNC_PENDING,
        ]);

        return [$user, $tenant];
    }

    private function assignInboxTriage(Tenant $tenant, User $user): void
    {
        $skill = SkillCatalogItem::query()->firstOrCreate(
            ['skill_key' => 'inbox-triage'],
            [
                'label' => 'Inbox Triage (by Sync360)',
                'description' => 'Inbox triage',
                'category' => 'operations',
                'onboarding_role' => 'core',
                'is_assignable' => true,
                'is_orphaned' => false,
            ]
        );

        $version = SkillCatalogVersion::query()->firstOrCreate(
            ['skill_key' => 'inbox-triage', 'version' => '1.5.8'],
            [
                'skill_catalog_item_id' => $skill->id,
                'manifest_json' => [
                    'skill_id' => 'inbox-triage',
                    'version' => '1.5.8',
                    'label' => 'Inbox Triage (by Sync360)',
                    'description' => 'Inbox triage',
                    'onboarding_role' => 'core',
                ],
                'is_active_published' => true,
                'is_archived' => false,
                'is_available' => true,
                'discovered_at' => now(),
                'last_imported_at' => now(),
            ]
        );

        TenantSkillAssignment::query()->updateOrCreate(
            [
                'tenant_id' => $tenant->id,
                'skill_key' => 'inbox-triage',
            ],
            [
                'skill_catalog_version_id' => $version->id,
                'assigned_by' => $user->id,
                'assigned_at' => now(),
                'is_enabled' => true,
            ]
        );
    }
}
