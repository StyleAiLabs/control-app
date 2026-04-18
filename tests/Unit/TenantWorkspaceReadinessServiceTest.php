<?php

namespace Tests\Unit;

use App\Enums\TenantProvisioningStatus;
use App\Enums\TrialStatus;
use App\Models\Server;
use App\Models\Tenant;
use App\Models\TenantGoogleCredential;
use App\Models\User;
use App\Services\TenantWorkspaceReadinessService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class TenantWorkspaceReadinessServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_evaluate_is_pure_over_preloaded_inputs(): void
    {
        config()->set('services.google.client_id', 'google-client-id');
        config()->set('services.google.client_secret', 'google-client-secret');
        config()->set('services.google.redirect_uri', 'https://app.sync360.test/auth/google/callback');

        $user = User::query()->create([
            'name' => 'Alice Admin',
            'email' => 'alice@example.com',
            'password' => 'super-secret',
        ]);

        $tenant = Tenant::query()->create([
            'tenant_id' => 'tenant_readiness_01',
            'slug' => 'acme-plumbing',
            'business_name' => 'Acme Plumbing',
            'industry' => 'Trades',
            'skill_pack' => 'Operations Core',
            'user_id' => $user->id,
            'server_id' => Server::query()->firstOrFail()->id,
            'trial_status' => TrialStatus::Active,
            'provisioning_status' => TenantProvisioningStatus::Ready,
            'runtime_path' => '/srv/sync360/runtime/tenants/acme-plumbing',
            'onboarding_status' => 'in_progress',
            'onboarding_step' => 6,
            'agent_status' => 'offline',
        ]);

        $tenant->googleCredential()->create([
            'status' => TenantGoogleCredential::STATUS_CONNECTED,
            'runtime_sync_status' => TenantGoogleCredential::RUNTIME_SYNC_VERIFIED,
            'google_email' => 'owner@example.com',
            'access_token' => 'google-access-token',
            'refresh_token' => 'google-refresh-token',
            'scopes' => ['openid', 'email'],
            'connected_at' => now(),
        ]);

        $tenant->businessProfile()->create([
            'business_name' => 'Acme Plumbing',
            'industry' => 'Trades',
            'description' => 'Acme Plumbing helps homeowners with urgent repairs.',
            'services' => ['Emergency plumbing'],
        ]);

        $tenant->businessProfileFiles()->create([
            'identity_markdown' => '# Identity',
            'soul_markdown' => '# Soul',
            'user_markdown' => '# User',
            'bootstrap_markdown' => '# Bootstrap',
            'generated_at' => now(),
        ]);

        $tenant->load(['googleCredential', 'businessProfile', 'businessProfileFiles', 'server']);

        DB::enableQueryLog();
        DB::flushQueryLog();

        $result = app(TenantWorkspaceReadinessService::class)->evaluate($tenant, true);

        $this->assertSame([], DB::getQueryLog());
        $this->assertTrue($result['customer_ready']);
    }
}
