<?php

namespace Tests\Feature;

use App\Enums\TenantProvisioningStatus;
use App\Enums\TenantRuntimeUsageUseCase;
use App\Enums\TrialStatus;
use App\Models\Server;
use App\Models\Tenant;
use App\Models\TenantRuntimeDispatch;
use App\Models\TenantRuntimeUsageEvent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class TenantRuntimeCostObservabilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_view_fleet_runtime_cost_dashboard_breakdowns(): void
    {
        $admin = $this->adminUser();
        $tenant = $this->tenant('cost-tenant-1', 'Acme Services');
        $otherTenant = $this->tenant('cost-tenant-2', 'Beta Plumbing');

        TenantRuntimeUsageEvent::query()->create([
            'tenant_id' => $tenant->id,
            'use_case' => TenantRuntimeUsageUseCase::InboxTriage,
            'trigger_source' => 'sync360:poll-inbox-triage',
            'effective_model' => 'gpt-4o-mini',
            'request_count' => 1,
            'prompt_tokens' => 1500,
            'completion_tokens' => 300,
            'total_tokens' => 1800,
            'cost_amount' => 0.0123,
            'currency' => 'USD',
            'occurred_at' => Carbon::parse('2026-04-27 10:00:00'),
        ]);

        TenantRuntimeUsageEvent::query()->create([
            'tenant_id' => $otherTenant->id,
            'use_case' => TenantRuntimeUsageUseCase::TelegramChat,
            'trigger_source' => 'litellm-spend-log',
            'effective_model' => 'gpt-4o',
            'request_count' => 2,
            'prompt_tokens' => 2400,
            'completion_tokens' => 900,
            'total_tokens' => 3300,
            'cost_amount' => 0.0456,
            'currency' => 'USD',
            'occurred_at' => Carbon::parse('2026-04-28 08:15:00'),
        ]);

        TenantRuntimeUsageEvent::query()->create([
            'tenant_id' => $otherTenant->id,
            'use_case' => TenantRuntimeUsageUseCase::UnknownRuntime,
            'trigger_source' => 'litellm-spend-log',
            'effective_model' => 'gpt-4.1',
            'request_count' => 1,
            'prompt_tokens' => 500,
            'completion_tokens' => 150,
            'total_tokens' => 650,
            'cost_amount' => 0.01,
            'currency' => 'USD',
            'occurred_at' => Carbon::parse('2026-04-28 09:00:00'),
        ]);

        $this->actingAs($admin)
            ->get(route('admin.analytics.costs', ['window' => '30d']))
            ->assertOk()
            ->assertSee('Cost Observability')
            ->assertSee('$0.07', false)
            ->assertSee('Inbox Triage')
            ->assertSee('Telegram Chat')
            ->assertSee('Unknown Runtime')
            ->assertSee('Acme Services')
            ->assertSee('Beta Plumbing')
            ->assertSee('gpt-4o-mini')
            ->assertSee('gpt-4o');
    }

    public function test_tenant_cost_tab_scopes_runtime_usage_to_the_selected_tenant(): void
    {
        $admin = $this->adminUser();
        $tenant = $this->tenant('cost-tenant-3', 'Gamma Electrics');
        $otherTenant = $this->tenant('cost-tenant-4', 'Delta HVAC');

        $dispatch = TenantRuntimeDispatch::query()->create([
            'tenant_id' => $tenant->id,
            'use_case' => TenantRuntimeUsageUseCase::InboxTriage,
            'trigger_source' => 'sync360:poll-inbox-triage',
            'source_channel' => 'gmail_inbox_monitor',
            'source_name' => 'sync360-inbox-monitor',
            'effective_model' => 'gpt-4o-mini',
            'request_correlation_key' => 'sync360-correlation-1',
            'dispatch_status' => 'sent',
            'dispatched_at' => Carbon::parse('2026-04-28 11:00:00'),
            'occurred_at' => Carbon::parse('2026-04-28 11:00:00'),
        ]);

        TenantRuntimeUsageEvent::query()->create([
            'tenant_id' => $tenant->id,
            'tenant_runtime_dispatch_id' => $dispatch->id,
            'use_case' => TenantRuntimeUsageUseCase::InboxTriage,
            'trigger_source' => 'sync360:poll-inbox-triage',
            'effective_model' => 'gpt-4o-mini',
            'request_count' => 1,
            'prompt_tokens' => 900,
            'completion_tokens' => 120,
            'total_tokens' => 1020,
            'cost_amount' => 0.005,
            'currency' => 'USD',
            'occurred_at' => Carbon::parse('2026-04-28 11:05:00'),
            'litellm_call_id' => 'call-tenant-1',
        ]);

        TenantRuntimeUsageEvent::query()->create([
            'tenant_id' => $otherTenant->id,
            'use_case' => TenantRuntimeUsageUseCase::TelegramChat,
            'trigger_source' => 'litellm-spend-log',
            'effective_model' => 'gpt-4o',
            'request_count' => 1,
            'prompt_tokens' => 2000,
            'completion_tokens' => 500,
            'total_tokens' => 2500,
            'cost_amount' => 0.03,
            'currency' => 'USD',
            'occurred_at' => Carbon::parse('2026-04-28 11:10:00'),
            'litellm_call_id' => 'call-tenant-2',
        ]);

        $this->actingAs($admin)
            ->get(route('admin.tenants.show', ['tenant' => $tenant, 'tab' => 'costs', 'window' => '30d']))
            ->assertOk()
            ->assertSee('Runtime Cost Observability')
            ->assertSee('Gamma Electrics')
            ->assertSee('$0.01', false)
            ->assertSee('Inbox Triage')
            ->assertSee('gpt-4o-mini')
            ->assertDontSee('Delta HVAC');
    }

    public function test_cost_observability_pages_remain_admin_only(): void
    {
        $owner = User::query()->create([
            'name' => 'Owner',
            'email' => 'owner@example.com',
            'password' => 'secret',
            'is_admin' => false,
        ]);

        $tenant = $this->tenant('cost-tenant-5', 'Epsilon Roofing', $owner);

        $this->actingAs($owner)
            ->get(route('admin.analytics.costs'))
            ->assertForbidden();

        $this->actingAs($owner)
            ->get(route('admin.tenants.show', ['tenant' => $tenant, 'tab' => 'costs']))
            ->assertForbidden();
    }

    private function adminUser(): User
    {
        return User::query()->create([
            'name' => 'Admin',
            'email' => 'admin@example.com',
            'password' => 'secret',
            'is_admin' => true,
        ]);
    }

    private function tenant(string $tenantId, string $businessName, ?User $user = null): Tenant
    {
        $owner = $user ?? User::query()->create([
            'name' => $businessName.' Owner',
            'email' => $tenantId.'@example.com',
            'password' => 'secret',
            'is_admin' => false,
        ]);

        return Tenant::query()->create([
            'tenant_id' => $tenantId,
            'slug' => $tenantId,
            'business_name' => $businessName,
            'industry' => 'Services',
            'skill_pack' => 'Client Support',
            'user_id' => $owner->id,
            'server_id' => Server::query()->firstOrFail()->id,
            'trial_status' => TrialStatus::Active,
            'provisioning_status' => TenantProvisioningStatus::Ready,
            'agent_status' => 'live',
            'workspace_url' => 'https://'.$tenantId.'.workspace.test',
            'runtime_path' => '/srv/sync360/runtime/tenants/'.$tenantId,
        ]);
    }
}
