<?php

namespace Tests\Feature;

use App\Enums\TenantProvisioningStatus;
use App\Enums\TrialStatus;
use App\Models\Server;
use App\Models\Tenant;
use App\Models\TenantGoogleCredential;
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
}
