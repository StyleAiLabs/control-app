<?php

namespace Tests\Feature;

use App\Enums\TenantProvisioningStatus;
use App\Enums\TrialStatus;
use App\Models\BusinessProfile;
use App\Models\ConversationLog;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_dashboard_shows_resume_path_for_incomplete_onboarding(): void
    {
        [$user, $tenant] = $this->seedTenant();

        $tenant->forceFill([
            'provisioning_status' => TenantProvisioningStatus::Ready,
            'onboarding_status' => 'in_progress',
            'onboarding_step' => 3,
            'tone' => 'friendly',
        ])->save();

        $this->actingAs($user);

        $this->get('/dashboard')
            ->assertOk()
            ->assertSee('Setup Progress')
            ->assertSee('Continue Setup')
            ->assertSee('step 4 is next', escape: false)
            ->assertSee('Business Website')
            ->assertSee('Capabilities');
    }

    public function test_dashboard_shows_live_agent_and_recent_conversations(): void
    {
        [$user, $tenant, $profile] = $this->seedTenant();

        $tenant->forceFill([
            'provisioning_status' => TenantProvisioningStatus::Ready,
            'onboarding_status' => 'complete',
            'onboarding_step' => 6,
            'agent_status' => 'live',
            'workspace_url' => 'https://acme-plumbing.workspace.test',
            'channel' => 'whatsapp',
            'tone' => 'professional',
            'capabilities' => ['faqs', 'messages'],
        ])->save();

        $profile->forceFill([
            'contact_email' => 'support@acme.example',
            'contact_phone' => '+64 21 999 9999',
            'last_synced_to_agent' => now()->subMinutes(10),
        ])->save();

        ConversationLog::query()->create([
            'tenant_id' => $tenant->id,
            'channel' => 'whatsapp',
            'external_message_id' => 'wamid.1',
            'from_identifier' => '64215550101',
            'message_in' => 'Do you do emergency callouts?',
            'message_out' => 'Yes, we do emergency callouts across Auckland.',
            'meta_json' => ['provider' => 'whatsapp'],
            'responded_at' => now()->subMinutes(5),
        ]);

        $this->actingAs($user);

        $this->get('/dashboard')
            ->assertOk()
            ->assertSee('Live')
            ->assertSee('Conversation Activity')
            ->assertSee('Open Workspace')
            ->assertSee('Do you do emergency callouts?')
            ->assertSee('Yes, we do emergency callouts across Auckland.')
            ->assertSee('WhatsApp')
            ->assertSee('support@acme.example')
            ->assertSee('Total')
            ->assertSee('1');
    }

    /**
     * @return array{0: User, 1: Tenant, 2: BusinessProfile}
     */
    private function seedTenant(): array
    {
        $user = User::query()->create([
            'name' => 'Alice Admin',
            'email' => 'alice@example.com',
            'password' => 'super-secret',
        ]);

        $tenant = Tenant::query()->create([
            'tenant_id' => 'tenant_dashboard_01',
            'slug' => 'acme-plumbing',
            'business_name' => 'Acme Plumbing',
            'industry' => 'Trades',
            'skill_pack' => 'Operations Core',
            'user_id' => $user->id,
            'server_id' => \App\Models\Server::query()->firstOrFail()->id,
            'trial_status' => TrialStatus::Active,
            'provisioning_status' => TenantProvisioningStatus::Pending,
            'onboarding_status' => 'pending',
            'onboarding_step' => 0,
            'agent_status' => 'offline',
        ]);

        $profile = BusinessProfile::query()->create([
            'tenant_id' => $tenant->id,
            'business_name' => 'Acme Plumbing',
            'industry' => 'Trades',
            'description' => 'Acme Plumbing helps homeowners with urgent repairs and maintenance work.',
            'services' => ['Emergency plumbing', 'Maintenance'],
            'contact_email' => 'alice@example.com',
            'contact_phone' => '+64 21 555 0101',
        ]);

        return [$user, $tenant, $profile];
    }
}
