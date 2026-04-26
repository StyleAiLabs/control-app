<?php

namespace Tests\Feature;

use App\Enums\TenantProvisioningStatus;
use App\Enums\TrialStatus;
use App\Models\Server;
use App\Models\Tenant;
use App\Models\User;
use App\Services\LiteLlmTenantKeyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class LiteLlmTenantKeyServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_generate_update_suspend_and_delete_litellm_key_for_a_tenant(): void
    {
        config()->set('services.litellm.base_url', 'https://litellm.stylesoftware.co.nz');
        config()->set('services.litellm.master_key', 'litellm-master');
        config()->set('sync360.litellm.default_plan_name', 'trial');
        config()->set('sync360.litellm.default_budget', 25);
        config()->set('sync360.litellm.plan_budgets.trial', 25);

        Http::fake([
            'https://litellm.stylesoftware.co.nz/key/generate' => Http::response(['key' => 'sk-tenant-acme'], 200),
            'https://litellm.stylesoftware.co.nz/key/update' => Http::response(['ok' => true], 200),
            'https://litellm.stylesoftware.co.nz/key/delete' => Http::response(['ok' => true], 200),
        ]);

        $tenant = $this->tenant();
        $tenant->forceFill([
            'provisioning_status' => TenantProvisioningStatus::Provisioning,
        ])->save();
        $service = app(LiteLlmTenantKeyService::class);

        $generated = $service->ensureTenantKey($tenant);

        $tenant->refresh();

        $this->assertSame('sk-tenant-acme', $generated['key']);
        $this->assertSame('openclaw-tenant_01', $tenant->litellm_key_alias);
        $this->assertSame('trial', $tenant->litellm_plan_name);
        $this->assertSame('25.00', $tenant->litellm_max_budget);
        $this->assertSame('monthly', $tenant->litellm_budget_duration);
        $this->assertNotNull($tenant->litellm_last_synced_at);

        $service->updateTenantBudget($tenant, 'growth', 99, 'monthly');
        $tenant->refresh();

        $this->assertSame('growth', $tenant->litellm_plan_name);
        $this->assertSame('99.00', $tenant->litellm_max_budget);
        $this->assertSame('monthly', $tenant->litellm_budget_duration);

        $service->suspendTenant($tenant);
        $tenant->refresh();

        $this->assertSame('0.00', $tenant->litellm_max_budget);
        $this->assertNull($tenant->litellm_budget_duration);

        $service->deleteTenantKey($tenant);
        $tenant->refresh();

        $this->assertNull($tenant->litellm_virtual_key);
        $this->assertNull($tenant->litellm_key_alias);
        $this->assertNull($tenant->litellm_plan_name);

        Http::assertSentCount(4);
        Http::assertSent(fn ($request) => $request->url() === 'https://litellm.stylesoftware.co.nz/key/generate'
            && $request['key_alias'] === 'openclaw-tenant_01'
            && (float) $request['max_budget'] === 25.0
            && $request['budget_duration'] === 'monthly'
            && data_get($request->data(), 'metadata.plan') === 'trial');
        Http::assertSent(fn ($request) => $request->url() === 'https://litellm.stylesoftware.co.nz/key/update'
            && $request['key'] === 'sk-tenant-acme'
            && (float) $request['max_budget'] === 99.0
            && $request['budget_duration'] === 'monthly');
        Http::assertSent(fn ($request) => $request->url() === 'https://litellm.stylesoftware.co.nz/key/update'
            && $request['key'] === 'sk-tenant-acme'
            && (float) $request['max_budget'] === 0.0
            && array_key_exists('budget_duration', $request->data())
            && $request['budget_duration'] === null);
        Http::assertSent(fn ($request) => $request->url() === 'https://litellm.stylesoftware.co.nz/key/delete'
            && $request['keys'] === ['sk-tenant-acme']);
    }

    public function test_restore_tenant_restores_saved_budget_and_duration_after_suspend(): void
    {
        config()->set('services.litellm.base_url', 'https://litellm.stylesoftware.co.nz');
        config()->set('services.litellm.master_key', 'litellm-master');

        Http::fake([
            'https://litellm.stylesoftware.co.nz/key/update' => Http::response(['ok' => true], 200),
        ]);

        $tenant = $this->tenant();
        $tenant->forceFill([
            'litellm_virtual_key' => 'sk-tenant-acme',
            'litellm_plan_name' => 'trial',
            'litellm_max_budget' => 0,
            'litellm_budget_duration' => null,
            'litellm_default_max_budget' => 12,
            'litellm_default_budget_duration' => 'monthly',
        ])->save();

        app(LiteLlmTenantKeyService::class)->restoreTenant($tenant);

        $tenant->refresh();

        $this->assertSame('12.00', $tenant->litellm_max_budget);
        $this->assertSame('monthly', $tenant->litellm_budget_duration);

        Http::assertSent(fn ($request) => $request->url() === 'https://litellm.stylesoftware.co.nz/key/update'
            && $request['key'] === 'sk-tenant-acme'
            && (float) $request['max_budget'] === 12.0
            && $request['budget_duration'] === 'monthly');
    }

    public function test_ensure_tenant_key_rejects_generation_outside_provisioning(): void
    {
        config()->set('services.litellm.base_url', 'https://litellm.stylesoftware.co.nz');
        config()->set('services.litellm.master_key', 'litellm-master');

        Http::fake([
            'https://litellm.stylesoftware.co.nz/key/generate' => Http::response(['key' => 'sk-tenant-acme'], 200),
        ]);

        $tenant = $this->tenant();
        $service = app(LiteLlmTenantKeyService::class);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('LiteLLM key generation is only allowed during active tenant provisioning.');

        $service->ensureTenantKey($tenant);
    }

    private function tenant(): Tenant
    {
        $user = User::query()->create([
            'name' => 'Alice Admin',
            'email' => 'alice@example.com',
            'password' => 'super-secret',
        ]);

        return Tenant::query()->create([
            'tenant_id' => 'tenant_01',
            'slug' => 'acme-plumbing',
            'business_name' => 'Acme Plumbing',
            'industry' => 'Trades',
            'skill_pack' => 'Operations Core',
            'user_id' => $user->id,
            'server_id' => Server::query()->firstOrFail()->id,
            'trial_status' => TrialStatus::Active,
            'provisioning_status' => TenantProvisioningStatus::Pending,
        ]);
    }
}
