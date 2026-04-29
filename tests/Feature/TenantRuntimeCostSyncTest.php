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
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class TenantRuntimeCostSyncTest extends TestCase
{
    use RefreshDatabase;

    public function test_runtime_cost_sync_imports_and_reconciles_litellm_usage_rows(): void
    {
        config()->set('services.litellm.base_url', 'https://litellm.stylesoftware.co.nz');
        config()->set('services.litellm.master_key', 'litellm-master');

        $tenant = $this->tenant('runtime-cost-1');

        $dispatch = TenantRuntimeDispatch::query()->create([
            'tenant_id' => $tenant->id,
            'use_case' => TenantRuntimeUsageUseCase::InboxTriage,
            'trigger_source' => 'sync360:poll-inbox-triage',
            'source_channel' => 'gmail_inbox_monitor',
            'source_name' => 'sync360-inbox-monitor',
            'effective_model' => 'gpt-4o-mini',
            'request_correlation_key' => 'sync360-match-me',
            'dispatch_status' => 'sent',
            'dispatched_at' => Carbon::parse('2026-04-28 10:00:00'),
            'occurred_at' => Carbon::parse('2026-04-28 10:00:00'),
        ]);

        Http::fake([
            'https://litellm.stylesoftware.co.nz/spend/logs*' => Http::response([
                'data' => [
                    [
                        'request_id' => 'call-match-1',
                        'api_key_alias' => $tenant->litellm_key_alias,
                        'model' => 'gpt-4o-mini',
                        'spend' => 0.0042,
                        'prompt_tokens' => 900,
                        'completion_tokens' => 150,
                        'total_tokens' => 1050,
                        'startTime' => '2026-04-28T10:03:00Z',
                        'metadata' => [
                            'tenant_id' => $tenant->tenant_id,
                        ],
                    ],
                    [
                        'request_id' => 'call-unmatched-1',
                        'api_key_alias' => $tenant->litellm_key_alias,
                        'model' => 'gpt-4o',
                        'spend' => 0.0081,
                        'prompt_tokens' => 1800,
                        'completion_tokens' => 400,
                        'total_tokens' => 2200,
                        'startTime' => '2026-04-28T12:00:00Z',
                        'metadata' => [
                            'tenant_id' => $tenant->tenant_id,
                        ],
                    ],
                ],
            ], 200),
        ]);

        Artisan::call('sync360:sync-runtime-costs');

        $matched = TenantRuntimeUsageEvent::query()->where('litellm_call_id', 'call-match-1')->sole();
        $this->assertSame($tenant->id, $matched->tenant_id);
        $this->assertSame($dispatch->id, $matched->tenant_runtime_dispatch_id);
        $this->assertSame(TenantRuntimeUsageUseCase::InboxTriage, $matched->use_case);
        $this->assertSame('gpt-4o-mini', $matched->effective_model);
        $this->assertSame('0.004200', $matched->cost_amount);
        $this->assertSame(1050, $matched->total_tokens);

        $unmatched = TenantRuntimeUsageEvent::query()->where('litellm_call_id', 'call-unmatched-1')->sole();
        $this->assertSame($tenant->id, $unmatched->tenant_id);
        $this->assertNull($unmatched->tenant_runtime_dispatch_id);
        $this->assertSame(TenantRuntimeUsageUseCase::UnknownRuntime, $unmatched->use_case);
        $this->assertSame('litellm-spend-log', $unmatched->trigger_source);
    }

    public function test_runtime_cost_sync_is_idempotent_for_duplicate_litellm_rows(): void
    {
        config()->set('services.litellm.base_url', 'https://litellm.stylesoftware.co.nz');
        config()->set('services.litellm.master_key', 'litellm-master');

        $tenant = $this->tenant('runtime-cost-2');

        Http::fake([
            'https://litellm.stylesoftware.co.nz/spend/logs*' => Http::response([
                'data' => [
                    [
                        'request_id' => 'call-duplicate-1',
                        'api_key_alias' => $tenant->litellm_key_alias,
                        'model' => 'gpt-4o',
                        'spend' => 0.01,
                        'prompt_tokens' => 1200,
                        'completion_tokens' => 250,
                        'total_tokens' => 1450,
                        'startTime' => '2026-04-28T08:00:00Z',
                        'metadata' => [
                            'tenant_id' => $tenant->tenant_id,
                        ],
                    ],
                ],
            ], 200),
        ]);

        Artisan::call('sync360:sync-runtime-costs');
        Artisan::call('sync360:sync-runtime-costs');

        $this->assertSame(1, TenantRuntimeUsageEvent::query()->where('litellm_call_id', 'call-duplicate-1')->count());
    }

    private function tenant(string $tenantId): Tenant
    {
        $user = User::query()->create([
            'name' => 'Owner '.$tenantId,
            'email' => $tenantId.'@example.com',
            'password' => 'secret',
            'is_admin' => false,
        ]);

        return Tenant::query()->create([
            'tenant_id' => $tenantId,
            'slug' => $tenantId,
            'business_name' => 'Business '.$tenantId,
            'industry' => 'Services',
            'skill_pack' => 'Client Support',
            'user_id' => $user->id,
            'server_id' => Server::query()->firstOrFail()->id,
            'trial_status' => TrialStatus::Active,
            'provisioning_status' => TenantProvisioningStatus::Ready,
            'agent_status' => 'live',
            'workspace_url' => 'https://'.$tenantId.'.workspace.test',
            'runtime_path' => '/srv/sync360/runtime/tenants/'.$tenantId,
            'litellm_virtual_key' => 'sk-'.$tenantId,
            'litellm_key_alias' => 'openclaw-'.$tenantId,
            'litellm_plan_name' => 'trial',
            'litellm_max_budget' => 25,
            'litellm_budget_duration' => 'monthly',
        ]);
    }
}
