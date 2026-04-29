<?php

namespace Tests\Feature;

use App\Enums\BillingStatus;
use App\Enums\TenantProvisioningStatus;
use App\Enums\TrialStatus;
use App\Models\Server;
use App\Models\Tenant;
use App\Models\TenantRuntimeDispatch;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BillingPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_trial_tenant_can_open_billing_page_and_see_plan_choices(): void
    {
        $tenant = $this->makeTenant([
            'trial_status' => TrialStatus::Active,
            'trial_ends_at' => now()->addDays(5)->addMinute(),
            'billing_status' => BillingStatus::Trialing,
            'billing_first_paid_at' => null,
        ]);

        $this->actingAs($tenant->user)
            ->get('/billing')
            ->assertOk()
            ->assertSee('Choose Standard')
            ->assertSee('Choose Flex')
            ->assertSee('Included soon')
            ->assertSee('5 days left');
    }

    public function test_active_paid_tenant_sees_usage_summary_and_manage_subscription_action(): void
    {
        config()->set('sync360.billing.plans.standard.interaction_limit', 300);

        $tenant = $this->makeTenant([
            'trial_status' => TrialStatus::Expired,
            'billing_status' => BillingStatus::Active,
            'billing_plan' => 'standard',
            'billing_started_at' => now()->subMonth(),
            'billing_first_paid_at' => now()->subMonth(),
            'billing_cycle_anchor_at' => now()->startOfMonth(),
            'billing_cycle_ends_at' => now()->endOfMonth(),
        ]);

        TenantRuntimeDispatch::query()->create([
            'tenant_id' => $tenant->id,
            'use_case' => 'inbox_triage',
            'trigger_source' => 'sync360:poll-inbox-triage',
            'dispatch_status' => 'sent',
            'request_correlation_key' => 'billing-page-usage',
            'occurred_at' => now()->startOfMonth()->addHour(),
            'dispatched_at' => now()->startOfMonth()->addHour(),
        ]);

        $this->actingAs($tenant->user)
            ->get('/billing')
            ->assertOk()
            ->assertSee('Manage Subscription')
            ->assertSee('1 of 300 used this month')
            ->assertSee('Active');
    }

    public function test_customer_sidebar_includes_billing_navigation_link(): void
    {
        $tenant = $this->makeTenant();

        $this->actingAs($tenant->user)
            ->get('/dashboard')
            ->assertOk()
            ->assertSee('Billing');
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function makeTenant(array $overrides = []): Tenant
    {
        $user = User::factory()->create();

        return Tenant::query()->create(array_merge([
            'tenant_id' => 'tenant-billing-page-'.str()->lower(str()->random(6)),
            'slug' => 'billing-page-'.str()->lower(str()->random(6)),
            'business_name' => 'Billing Portal Tenant',
            'industry' => 'Testing',
            'skill_pack' => 'Core Modules',
            'user_id' => $user->id,
            'server_id' => Server::query()->firstOrFail()->id,
            'trial_status' => TrialStatus::Active,
            'trial_ends_at' => now()->addDays(7),
            'provisioning_status' => TenantProvisioningStatus::Ready,
            'agent_status' => 'live',
            'billing_status' => BillingStatus::Trialing,
        ], $overrides));
    }
}
