<?php

namespace Tests\Feature;

use App\Enums\BillingStatus;
use App\Enums\TenantProvisioningStatus;
use App\Enums\TrialStatus;
use App\Models\Server;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminBillingOverviewTest extends TestCase
{
    use RefreshDatabase;

    public function test_paid_tenant_overview_shows_billing_section_and_hides_trial_controls(): void
    {
        $admin = User::query()->create([
            'name' => 'Billing Admin',
            'email' => 'billing-admin@example.com',
            'password' => 'super-secret',
            'is_admin' => true,
        ]);

        $user = User::query()->create([
            'name' => 'Customer User',
            'email' => 'customer-billing@example.com',
            'password' => 'super-secret',
            'is_admin' => false,
            'stripe_id' => 'cus_12345',
        ]);

        $tenant = Tenant::query()->create([
            'tenant_id' => 'tenant_paid_billing_01',
            'slug' => 'paid-billing-overview',
            'business_name' => 'Paid Billing Overview',
            'industry' => 'Retail',
            'skill_pack' => 'Client Support',
            'user_id' => $user->id,
            'server_id' => Server::query()->firstOrFail()->id,
            'trial_status' => TrialStatus::Expired,
            'provisioning_status' => TenantProvisioningStatus::Ready,
            'billing_status' => BillingStatus::Active,
            'billing_plan' => 'standard',
            'billing_started_at' => now()->subMonth(),
            'billing_first_paid_at' => now()->subMonth(),
            'billing_cycle_anchor_at' => now()->startOfMonth(),
            'billing_cycle_ends_at' => now()->endOfMonth(),
        ]);

        $this->actingAs($admin)
            ->get(route('admin.tenants.show', ['tenant' => $tenant, 'tab' => 'overview']))
            ->assertOk()
            ->assertSee('Billing')
            ->assertSee('Billing Status')
            ->assertDontSee('Expired-trial Runtime Overrides')
            ->assertDontSee('Add 7 Days');
    }
}
