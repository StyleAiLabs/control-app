<?php

namespace Tests\Unit;

use App\Enums\BillingStatus;
use App\Enums\TenantProvisioningStatus;
use App\Enums\TrialStatus;
use App\Models\Server;
use App\Models\Tenant;
use App\Models\TenantRuntimeDispatch;
use App\Models\User;
use App\Services\CommercialAccessPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CommercialAccessPolicyTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_keeps_pre_conversion_trial_rules_for_expired_tenants(): void
    {
        $tenant = $this->makeTenant([
            'trial_status' => TrialStatus::Expired,
            'billing_status' => BillingStatus::Trialing,
            'billing_first_paid_at' => null,
        ]);

        $policy = app(CommercialAccessPolicy::class);

        $this->assertFalse($policy->canPollInbox($tenant));
        $this->assertFalse($policy->canSendCustomerFacingRuntimeWork($tenant));
        $this->assertFalse($policy->shouldEnableDirectCustomerChannels($tenant));
        $this->assertSame('trial_expired', $policy->runtimePauseReason($tenant));
    }

    public function test_it_allows_paid_active_tenants_to_keep_runtime_paths_open(): void
    {
        $tenant = $this->makeTenant([
            'trial_status' => TrialStatus::Expired,
            'billing_status' => BillingStatus::Active,
            'billing_plan' => 'standard',
            'billing_first_paid_at' => now()->subDay(),
            'billing_started_at' => now()->subDay(),
            'billing_cycle_anchor_at' => now()->startOfMonth(),
            'billing_cycle_ends_at' => now()->endOfMonth(),
        ]);

        $policy = app(CommercialAccessPolicy::class);

        $this->assertTrue($policy->canPollInbox($tenant));
        $this->assertTrue($policy->canSendCustomerFacingRuntimeWork($tenant));
        $this->assertTrue($policy->shouldEnableDirectCustomerChannels($tenant));
        $this->assertFalse($policy->shouldSuspendLiteLlm($tenant));
        $this->assertNull($policy->runtimePauseReason($tenant));
    }

    public function test_it_pauses_paid_tenants_when_their_interaction_limit_is_reached(): void
    {
        config()->set('sync360.billing.plans.standard.interaction_limit', 2);

        $tenant = $this->makeTenant([
            'billing_status' => BillingStatus::Active,
            'billing_plan' => 'standard',
            'billing_first_paid_at' => now()->subMonth(),
            'billing_started_at' => now()->subMonth(),
            'billing_cycle_anchor_at' => now()->startOfMonth(),
            'billing_cycle_ends_at' => now()->endOfMonth(),
        ]);

        TenantRuntimeDispatch::query()->create([
            'tenant_id' => $tenant->id,
            'use_case' => 'inbox_triage',
            'trigger_source' => 'sync360:poll-inbox-triage',
            'dispatch_status' => 'sent',
            'request_correlation_key' => 'dispatch-one',
            'occurred_at' => now()->startOfMonth()->addHour(),
            'dispatched_at' => now()->startOfMonth()->addHour(),
        ]);

        TenantRuntimeDispatch::query()->create([
            'tenant_id' => $tenant->id,
            'use_case' => 'manual_runtime_hook',
            'trigger_source' => 'tenant_workspace_messenger.send',
            'dispatch_status' => 'sent',
            'request_correlation_key' => 'dispatch-two',
            'occurred_at' => now()->startOfMonth()->addHours(2),
            'dispatched_at' => now()->startOfMonth()->addHours(2),
        ]);

        $tenant->refresh();
        $policy = app(CommercialAccessPolicy::class);

        $this->assertTrue($tenant->hasReachedInteractionLimit());
        $this->assertFalse($policy->canPollInbox($tenant));
        $this->assertFalse($policy->canSendCustomerFacingRuntimeWork($tenant));
        $this->assertTrue($policy->shouldSuspendLiteLlm($tenant));
        $this->assertSame('interaction_limit_reached', $policy->runtimePauseReason($tenant));
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function makeTenant(array $overrides = []): Tenant
    {
        $user = User::factory()->create();

        return Tenant::query()->create(array_merge([
            'tenant_id' => 'tenant-billing-'.str()->lower(str()->random(6)),
            'slug' => 'billing-'.str()->lower(str()->random(6)),
            'business_name' => 'Billing Tenant',
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
