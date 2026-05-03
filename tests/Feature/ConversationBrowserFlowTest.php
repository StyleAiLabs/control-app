<?php

namespace Tests\Feature;

use App\Enums\TenantProvisioningStatus;
use App\Enums\TrialStatus;
use App\Models\BusinessProfile;
use App\Models\Tenant;
use App\Models\TenantGoogleCredential;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ConversationBrowserFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_authenticated_tenant_does_not_see_conversations_navigation(): void
    {
        [$user] = $this->seedTenant();

        $this->actingAs($user);

        $this->get('/dashboard')
            ->assertOk()
            ->assertDontSee('>Conversations<', false);
    }

    public function test_authenticated_tenant_cannot_access_conversations_route_directly(): void
    {
        [$user] = $this->seedTenant();

        $this->actingAs($user);

        $this->get('/conversations')
            ->assertNotFound();
    }

    /**
     * @return array{0: User, 1: Tenant}
     */
    private function seedTenant(): array
    {
        $user = User::query()->create([
            'name' => 'Alice Admin',
            'email' => 'alice@example.com',
            'password' => 'super-secret',
        ]);

        $tenant = Tenant::query()->create([
            'tenant_id' => 'tenant_conversations_01',
            'slug' => 'acme-plumbing',
            'business_name' => 'Acme Plumbing',
            'industry' => 'Trades',
            'skill_pack' => 'Operations Core',
            'user_id' => $user->id,
            'server_id' => \App\Models\Server::query()->firstOrFail()->id,
            'trial_status' => TrialStatus::Active,
            'provisioning_status' => TenantProvisioningStatus::Ready,
            'onboarding_status' => 'complete',
            'onboarding_step' => 7,
            'agent_status' => 'live',
            'channel' => 'telegram',
        ]);
        TenantGoogleCredential::query()->create([
            'tenant_id' => $tenant->id,
            'status' => TenantGoogleCredential::STATUS_SKIPPED,
            'runtime_sync_status' => TenantGoogleCredential::RUNTIME_SYNC_PENDING,
        ]);

        BusinessProfile::query()->create([
            'tenant_id' => $tenant->id,
            'business_name' => 'Acme Plumbing',
            'industry' => 'Trades',
            'description' => 'Acme Plumbing helps homeowners with urgent repairs and maintenance work.',
            'services' => ['Emergency plumbing', 'Maintenance'],
            'contact_email' => 'alice@example.com',
            'contact_phone' => '+64 21 555 0101',
        ]);

        return [$user, $tenant];
    }
}
