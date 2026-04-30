<?php

namespace Tests\Feature;

use App\Enums\BillingStatus;
use App\Enums\TenantProvisioningStatus;
use App\Enums\TrialStatus;
use App\Models\Server;
use App\Models\SkillCatalogItem;
use App\Models\SkillCatalogVersion;
use App\Models\Tenant;
use App\Models\TenantSkillAssignment;
use App\Models\User;
use App\Services\LiteLlmTenantKeyService;
use App\Services\SubscriptionLifecycleService;
use App\Services\TenantAgentSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Stripe\Event;
use Tests\TestCase;

class SubscriptionLifecycleServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_subscription_update_resolves_plan_from_stripe_price_and_preserves_unrelated_skills(): void
    {
        config()->set('sync360.billing.plans.standard', [
            'name' => 'Standard',
            'stripe_price_id' => 'price_standard',
            'included_skill_keys' => ['inbox-triage'],
            'litellm_plan' => 'starter',
            'litellm_budget_ceiling' => 25,
        ]);
        config()->set('sync360.billing.plans.flex', [
            'name' => 'Flex',
            'stripe_price_id' => 'price_flex',
            'included_skill_keys' => ['inbox-triage', 'pdf-generation'],
            'litellm_plan' => 'growth',
            'litellm_budget_ceiling' => 55,
        ]);

        $tenant = $this->tenant();
        $this->seedSkill('inbox-triage');
        $this->seedSkill('pdf-generation');
        $this->seedSkill('hello-world');

        $tenant->forceFill([
            'billing_status' => BillingStatus::Active,
            'billing_plan' => 'standard',
            'billing_started_at' => now()->subMonth(),
            'billing_first_paid_at' => now()->subMonth(),
            'litellm_virtual_key' => 'sk-test-tenant',
        ])->save();

        TenantSkillAssignment::query()->create([
            'tenant_id' => $tenant->id,
            'skill_catalog_version_id' => $this->catalogVersionId('inbox-triage'),
            'skill_key' => 'inbox-triage',
            'assigned_by' => $tenant->user_id,
            'assigned_at' => now()->subMonth(),
            'is_enabled' => true,
        ]);
        TenantSkillAssignment::query()->create([
            'tenant_id' => $tenant->id,
            'skill_catalog_version_id' => $this->catalogVersionId('hello-world'),
            'skill_key' => 'hello-world',
            'assigned_by' => $tenant->user_id,
            'assigned_at' => now()->subMonth(),
            'is_enabled' => true,
        ]);

        $liteLlm = Mockery::mock(LiteLlmTenantKeyService::class);
        $liteLlm->shouldReceive('updateTenantBudget')
            ->once()
            ->withArgs(fn (Tenant $updatedTenant, string $planName, float $budget, ?string $duration): bool => $updatedTenant->is($tenant)
                && $planName === 'growth'
                && $budget === 55.0
                && $duration === 'monthly');
        $agentSync = Mockery::mock(TenantAgentSyncService::class);
        $agentSync->shouldReceive('syncSavedChannelIfReady')
            ->once()
            ->withArgs(fn (Tenant $updatedTenant): bool => $updatedTenant->is($tenant));

        $this->instance(LiteLlmTenantKeyService::class, $liteLlm);
        $this->instance(TenantAgentSyncService::class, $agentSync);

        app(SubscriptionLifecycleService::class)->handleStripeEvent($this->stripeEvent('customer.subscription.updated', [
            'customer' => 'cus_test_123',
            'status' => 'active',
            'current_period_start' => now()->subDay()->timestamp,
            'current_period_end' => now()->addMonth()->timestamp,
            'metadata' => [
                'tenant_id' => (string) $tenant->id,
                'tenant_ulid' => $tenant->tenant_id,
                'selected_plan' => 'standard',
            ],
            'items' => [
                'data' => [
                    [
                        'price' => ['id' => 'price_flex'],
                    ],
                ],
            ],
        ]));

        $tenant->refresh();

        $this->assertSame(BillingStatus::Active, $tenant->billing_status);
        $this->assertSame('flex', $tenant->billing_plan);
        $this->assertTrue($tenant->skillAssignments()->where('skill_key', 'inbox-triage')->value('is_enabled'));
        $this->assertTrue($tenant->skillAssignments()->where('skill_key', 'pdf-generation')->value('is_enabled'));
        $this->assertTrue($tenant->skillAssignments()->where('skill_key', 'hello-world')->value('is_enabled'));
    }

    public function test_subscription_downgrade_disables_only_billing_managed_skills_removed_by_new_plan(): void
    {
        config()->set('sync360.billing.plans.standard', [
            'name' => 'Standard',
            'stripe_price_id' => 'price_standard',
            'included_skill_keys' => ['inbox-triage'],
            'litellm_plan' => 'starter',
            'litellm_budget_ceiling' => 25,
        ]);
        config()->set('sync360.billing.plans.flex', [
            'name' => 'Flex',
            'stripe_price_id' => 'price_flex',
            'included_skill_keys' => ['inbox-triage', 'pdf-generation'],
            'litellm_plan' => 'growth',
            'litellm_budget_ceiling' => 55,
        ]);

        $tenant = $this->tenant();
        $this->seedSkill('inbox-triage');
        $this->seedSkill('pdf-generation');
        $this->seedSkill('hello-world');

        $tenant->forceFill([
            'billing_status' => BillingStatus::Active,
            'billing_plan' => 'flex',
            'billing_started_at' => now()->subMonth(),
            'billing_first_paid_at' => now()->subMonth(),
            'litellm_virtual_key' => 'sk-test-tenant',
        ])->save();

        foreach (['inbox-triage', 'pdf-generation', 'hello-world'] as $skillKey) {
            TenantSkillAssignment::query()->create([
                'tenant_id' => $tenant->id,
                'skill_catalog_version_id' => $this->catalogVersionId($skillKey),
                'skill_key' => $skillKey,
                'assigned_by' => $tenant->user_id,
                'assigned_at' => now()->subMonth(),
                'is_enabled' => true,
            ]);
        }

        $liteLlm = Mockery::mock(LiteLlmTenantKeyService::class);
        $liteLlm->shouldReceive('updateTenantBudget')
            ->once()
            ->withArgs(fn (Tenant $updatedTenant, string $planName, float $budget, ?string $duration): bool => $updatedTenant->is($tenant)
                && $planName === 'starter'
                && $budget === 25.0
                && $duration === 'monthly');
        $agentSync = Mockery::mock(TenantAgentSyncService::class);
        $agentSync->shouldReceive('syncSavedChannelIfReady')
            ->once()
            ->withArgs(fn (Tenant $updatedTenant): bool => $updatedTenant->is($tenant));

        $this->instance(LiteLlmTenantKeyService::class, $liteLlm);
        $this->instance(TenantAgentSyncService::class, $agentSync);

        app(SubscriptionLifecycleService::class)->handleStripeEvent($this->stripeEvent('customer.subscription.updated', [
            'customer' => 'cus_test_456',
            'status' => 'active',
            'current_period_start' => now()->subDay()->timestamp,
            'current_period_end' => now()->addMonth()->timestamp,
            'metadata' => [
                'tenant_id' => (string) $tenant->id,
                'tenant_ulid' => $tenant->tenant_id,
                'selected_plan' => 'flex',
            ],
            'items' => [
                'data' => [
                    [
                        'price' => ['id' => 'price_standard'],
                    ],
                ],
            ],
        ]));

        $tenant->refresh();

        $this->assertSame('standard', $tenant->billing_plan);
        $this->assertTrue($tenant->skillAssignments()->where('skill_key', 'inbox-triage')->value('is_enabled'));
        $this->assertFalse($tenant->skillAssignments()->where('skill_key', 'pdf-generation')->value('is_enabled'));
        $this->assertTrue($tenant->skillAssignments()->where('skill_key', 'hello-world')->value('is_enabled'));
    }

    public function test_subscription_deleted_clears_stale_plan_state_and_suspends_runtime_budget(): void
    {
        $tenant = $this->tenant();
        $tenant->forceFill([
            'billing_status' => BillingStatus::Active,
            'billing_plan' => 'standard',
            'billing_started_at' => now()->subMonth(),
            'billing_first_paid_at' => now()->subMonth(),
            'billing_cycle_anchor_at' => now()->startOfMonth(),
            'billing_cycle_ends_at' => now()->endOfMonth(),
            'billing_grace_ends_at' => now()->addDays(2),
            'litellm_virtual_key' => 'sk-test-tenant',
        ])->save();
        $tenant->user()->update(['stripe_id' => 'cus_test_789']);

        $liteLlm = Mockery::mock(LiteLlmTenantKeyService::class);
        $liteLlm->shouldReceive('suspendTenant')
            ->once()
            ->withArgs(fn (Tenant $updatedTenant): bool => $updatedTenant->is($tenant));
        $agentSync = Mockery::mock(TenantAgentSyncService::class);
        $agentSync->shouldReceive('syncSavedChannelIfReady')
            ->once()
            ->withArgs(fn (Tenant $updatedTenant): bool => $updatedTenant->is($tenant));

        $this->instance(LiteLlmTenantKeyService::class, $liteLlm);
        $this->instance(TenantAgentSyncService::class, $agentSync);

        app(SubscriptionLifecycleService::class)->handleStripeEvent($this->stripeEvent('customer.subscription.deleted', [
            'customer' => 'cus_test_789',
            'status' => 'canceled',
            'metadata' => [
                'tenant_id' => (string) $tenant->id,
                'tenant_ulid' => $tenant->tenant_id,
            ],
        ]));

        $tenant->refresh();

        $this->assertSame(BillingStatus::Cancelled, $tenant->billing_status);
        $this->assertNull($tenant->billing_plan);
        $this->assertNull($tenant->billing_grace_ends_at);
        $this->assertNull($tenant->billing_cycle_anchor_at);
        $this->assertNull($tenant->billing_cycle_ends_at);
    }

    private function tenant(): Tenant
    {
        $user = User::query()->create([
            'name' => 'Alice Admin',
            'email' => 'alice@example.com',
            'password' => 'super-secret',
        ]);

        return Tenant::query()->create([
            'tenant_id' => 'tenant_billing_01',
            'slug' => 'billing-tenant',
            'business_name' => 'Billing Tenant',
            'industry' => 'Trades',
            'skill_pack' => 'Operations Core',
            'user_id' => $user->id,
            'server_id' => Server::query()->firstOrFail()->id,
            'trial_status' => TrialStatus::Active,
            'provisioning_status' => TenantProvisioningStatus::Ready,
        ]);
    }

    private function seedSkill(string $skillKey): void
    {
        $item = SkillCatalogItem::query()->create([
            'skill_key' => $skillKey,
            'label' => str($skillKey)->replace('-', ' ')->headline()->value(),
            'description' => 'Test skill',
            'category' => 'operations',
            'is_assignable' => true,
            'is_orphaned' => false,
            'onboarding_role' => 'featured',
        ]);

        SkillCatalogVersion::query()->create([
            'skill_catalog_item_id' => $item->id,
            'skill_key' => $skillKey,
            'version' => '1.0.0',
            'manifest_json' => [
                'skill_id' => $skillKey,
                'version' => '1.0.0',
                'label' => $item->label,
                'description' => 'Test skill',
                'onboarding_role' => 'featured',
            ],
            'is_active_published' => true,
            'is_archived' => false,
            'is_available' => true,
            'discovered_at' => now(),
            'last_imported_at' => now(),
        ]);
    }

    private function catalogVersionId(string $skillKey): int
    {
        return (int) SkillCatalogVersion::query()
            ->where('skill_key', $skillKey)
            ->value('id');
    }

    /**
     * @param  array<string, mixed>  $object
     */
    private function stripeEvent(string $type, array $object): Event
    {
        return Event::constructFrom([
            'id' => 'evt_'.str()->random(12),
            'object' => 'event',
            'type' => $type,
            'data' => [
                'object' => $object,
            ],
        ]);
    }
}
