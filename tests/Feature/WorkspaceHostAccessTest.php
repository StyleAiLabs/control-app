<?php

namespace Tests\Feature;

use App\Enums\TenantProvisioningStatus;
use App\Enums\TrialStatus;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WorkspaceHostAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_visiting_workspace_host_root_is_redirected_to_login(): void
    {
        $this->seedTenant('acme-plumbing', 'alice@example.com');

        $this->get('http://acme-plumbing.workspace.test/')
            ->assertRedirect('http://acme-plumbing.workspace.test/login');
    }

    public function test_matching_customer_visiting_workspace_host_root_reaches_dashboard(): void
    {
        [$user] = $this->seedTenant('acme-plumbing', 'alice@example.com');

        $this->actingAs($user)
            ->get('http://acme-plumbing.workspace.test/')
            ->assertRedirect('http://acme-plumbing.workspace.test/dashboard');
    }

    public function test_mismatched_customer_cannot_open_another_tenant_dashboard_host(): void
    {
        [$user] = $this->seedTenant('beta-electric', 'bob@example.com');
        $this->seedTenant('acme-plumbing', 'alice@example.com');

        $this->actingAs($user)
            ->get('http://acme-plumbing.workspace.test/dashboard')
            ->assertForbidden()
            ->assertSee('Use the correct workspace link')
            ->assertSee('Open Your Workspace');
    }

    /**
     * @return array{0: User, 1: Tenant}
     */
    private function seedTenant(string $slug, string $email): array
    {
        $user = User::query()->create([
            'name' => ucfirst(str_replace('-', ' ', $slug)),
            'email' => $email,
            'password' => 'super-secret',
        ]);

        $tenant = Tenant::query()->create([
            'tenant_id' => 'tenant_'.str_replace('-', '_', $slug),
            'slug' => $slug,
            'business_name' => ucfirst(str_replace('-', ' ', $slug)),
            'industry' => 'Trades',
            'skill_pack' => 'Operations Core',
            'user_id' => $user->id,
            'server_id' => \App\Models\Server::query()->firstOrFail()->id,
            'trial_status' => TrialStatus::Active,
            'provisioning_status' => TenantProvisioningStatus::Ready,
            'workspace_url' => sprintf('https://%s.workspace.test', $slug),
        ]);

        return [$user, $tenant];
    }
}
