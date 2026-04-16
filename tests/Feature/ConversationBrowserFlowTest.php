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

class ConversationBrowserFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_authenticated_tenant_can_browse_conversations(): void
    {
        [$user, $tenant] = $this->seedTenant();

        ConversationLog::query()->create([
            'tenant_id' => $tenant->id,
            'channel' => 'telegram',
            'external_message_id' => 'telegram.1',
            'from_identifier' => '@acme_owner',
            'message_in' => 'Do you service West Auckland?',
            'message_out' => 'Yes, we cover West Auckland and surrounding suburbs.',
            'ai_summary' => 'Asked whether West Auckland is covered and received confirmation.',
            'meta_json' => ['provider' => 'telegram'],
            'responded_at' => now()->subMinutes(5),
        ]);

        ConversationLog::query()->create([
            'tenant_id' => $tenant->id,
            'channel' => 'telegram',
            'external_message_id' => 'telegram.2',
            'from_identifier' => '@acme_owner_followup',
            'message_in' => 'Can someone call me back after hours?',
            'message_out' => null,
            'ai_summary' => 'Asked about an after-hours callback and no reply has been sent yet.',
            'meta_json' => ['provider' => 'telegram'],
            'responded_at' => null,
        ]);

        $this->actingAs($user);

        $this->get('/conversations')
            ->assertOk()
            ->assertSee('Conversations')
            ->assertSee('Messages with Your Digital Employee')
            ->assertSee('your conversations with your digital employee')
            ->assertSee('Back to Dashboard')
            ->assertSee('Asked whether West Auckland is covered and received confirmation.')
            ->assertSee('Asked about an after-hours callback and no reply has been sent yet.')
            ->assertSee('No reply')
            ->assertDontSee('customer conversations');
    }

    public function test_conversation_browser_filters_results(): void
    {
        [$user, $tenant] = $this->seedTenant();

        ConversationLog::query()->create([
            'tenant_id' => $tenant->id,
            'channel' => 'telegram',
            'external_message_id' => 'telegram.3',
            'from_identifier' => '@acme_owner',
            'message_in' => 'Need an urgent plumber tonight.',
            'message_out' => 'We can arrange an urgent callout tonight.',
            'ai_summary' => 'Urgent plumbing help was requested for tonight.',
            'meta_json' => ['provider' => 'telegram'],
            'responded_at' => now()->subMinutes(10),
        ]);

        ConversationLog::query()->create([
            'tenant_id' => $tenant->id,
            'channel' => 'telegram',
            'external_message_id' => 'telegram.4',
            'from_identifier' => '@acme_owner_followup',
            'message_in' => 'Do you install hot water cylinders?',
            'message_out' => null,
            'ai_summary' => 'Asked about hot water cylinder installs and is waiting on a reply.',
            'meta_json' => ['provider' => 'telegram'],
            'responded_at' => null,
        ]);

        $this->actingAs($user);

        $this->get('/conversations?channel=telegram&reply_status=replied&search=urgent')
            ->assertOk()
            ->assertSee('Urgent plumbing help was requested for tonight.')
            ->assertSee('Replied')
            ->assertDontSee('Asked about hot water cylinder installs and is waiting on a reply.');
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
            'onboarding_step' => 6,
            'agent_status' => 'live',
            'channel' => 'telegram',
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
