<?php

namespace Tests\Feature;

use App\Enums\TenantProvisioningStatus;
use App\Enums\TrialStatus;
use App\Models\BusinessProfile;
use App\Models\ConversationLog;
use App\Models\SkillCatalogItem;
use App\Models\SkillCatalogVersion;
use App\Models\Tenant;
use App\Models\TenantGoogleCredential;
use App\Models\TenantInboxMonitorMessage;
use App\Models\TenantInboxMonitorState;
use App\Models\TenantSkillAssignment;
use App\Models\TenantSkillConversionEvent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_dashboard_shows_condensed_setup_wizard_for_incomplete_onboarding(): void
    {
        [$user, $tenant] = $this->seedTenant();

        $tenant->forceFill([
            'provisioning_status' => TenantProvisioningStatus::Ready,
            'onboarding_status' => 'in_progress',
            'onboarding_step' => 3,
            'tone' => 'friendly',
        ])->save();

        $this->actingAs($user);

        $response = $this->get('/dashboard');

        $response
            ->assertOk()
            ->assertSee('Finish setup')
            ->assertSee('Continue Setup')
            ->assertSee('step 4 is next', escape: false)
            ->assertSee('Website')
            ->assertSee('Business Info')
            ->assertSee('Tone')
            ->assertSee('Modules')
            ->assertSee('Assistant')
            ->assertSee('Workspace')
            ->assertSee('Trial')
            ->assertDontSee('Your Business')
            ->assertDontSee('Conversation Activity')
            ->assertDontSee('Business Website')
            ->assertDontSee('Capabilities');

        $this->assertSame('no-cache', $response->headers->get('Pragma'));
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        $this->assertStringContainsString('no-cache', (string) $response->headers->get('Cache-Control'));
        $this->assertStringContainsString('must-revalidate', (string) $response->headers->get('Cache-Control'));
        $this->assertStringContainsString('private', (string) $response->headers->get('Cache-Control'));
    }

    public function test_dashboard_shows_outcomes_first_live_dashboard(): void
    {
        [$user, $tenant, $profile] = $this->seedTenant();

        $tenant->forceFill([
            'provisioning_status' => TenantProvisioningStatus::Ready,
            'onboarding_status' => 'complete',
            'onboarding_step' => 7,
            'agent_status' => 'live',
            'workspace_url' => 'https://acme-plumbing.workspace.test',
            'channel' => 'telegram',
            'tone' => 'professional',
            'capabilities' => ['faqs', 'messages'],
        ])->save();
        TenantGoogleCredential::query()->create([
            'tenant_id' => $tenant->id,
            'status' => TenantGoogleCredential::STATUS_SKIPPED,
            'runtime_sync_status' => TenantGoogleCredential::RUNTIME_SYNC_PENDING,
        ]);

        $profile->forceFill([
            'contact_email' => 'support@acme.example',
            'contact_phone' => '+64 21 999 9999',
            'last_synced_to_agent' => now()->subMinutes(10),
        ])->save();

        ConversationLog::query()->create([
            'tenant_id' => $tenant->id,
            'channel' => 'telegram',
            'external_message_id' => 'telegram.1',
            'from_identifier' => '@acme_owner',
            'message_in' => 'Do you do emergency callouts?',
            'message_out' => 'Yes, we do emergency callouts across Auckland.',
            'meta_json' => ['provider' => 'telegram'],
            'responded_at' => now()->subMinutes(5),
        ]);

        $this->actingAs($user);

        $response = $this->get('/dashboard');

        $response
            ->assertOk()
            ->assertSee('Live')
            ->assertSee('Open Sync360 Workspace')
            ->assertSee('Open Profile')
            ->assertSee('Last 7 days')
            ->assertSee('Last 30 days')
            ->assertSee('Last 90 days')
            ->assertSee('Year to date')
            ->assertSee('Performance Overview')
            ->assertSee('Trial Runway')
            ->assertSee('Top Skills')
            ->assertSee('Estimated Time Saved')
            ->assertSee('Estimated ROI')
            ->assertSee('Assistant')
            ->assertSee('Workspace')
            ->assertSee('Trial')
            ->assertSee('Inbox')
            ->assertDontSee('Your Business')
            ->assertDontSee('Conversation Activity')
            ->assertDontSee('Do you do emergency callouts?')
            ->assertDontSee('support@acme.example')
            ->assertDontSee('Setup Progress');

        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
    }

    public function test_dashboard_uses_single_urgent_banner_for_expired_trial_state(): void
    {
        [$user, $tenant] = $this->seedTenant();

        $tenant->forceFill([
            'trial_status' => TrialStatus::Expired,
            'provisioning_status' => TenantProvisioningStatus::Ready,
            'onboarding_status' => 'complete',
            'onboarding_step' => 7,
            'agent_status' => 'live',
            'workspace_url' => 'https://acme-plumbing.workspace.test',
            'litellm_spend' => 5.00,
            'litellm_max_budget' => 5.00,
        ])->save();

        $this->actingAs($user);

        $this->get('/dashboard')
            ->assertOk()
            ->assertSee('Your trial has ended.')
            ->assertSee('Inbox monitoring, customer replies, and live assistant work stay paused until expired-trial access is fully restored.')
            ->assertSee('Acme Plumbing is paused until expired-trial access is restored.')
            ->assertSee('Assistant')
            ->assertSee('Paused')
            ->assertSee('Trial')
            ->assertSee('Expired')
            ->assertSee('Reactivate workspace')
            ->assertSee('Contact Sync360')
            ->assertDontSee('Contact us to reactivate your digital employee.')
            ->assertDontSee('Your trial has ended and the assistant is paused.')
            ->assertDontSee('Trial ended');
    }

    public function test_dashboard_keeps_customer_state_paused_when_expired_trial_only_allows_monitoring(): void
    {
        [$user, $tenant] = $this->seedTenant();

        $this->makeInboxTriageEligible($tenant, [
            'trial_status' => TrialStatus::Expired,
            'allow_polling_when_trial_expired' => true,
            'allow_runtime_replies_when_trial_expired' => false,
            'allow_litellm_when_trial_expired' => false,
        ]);

        TenantInboxMonitorState::query()->create([
            'tenant_id' => $tenant->id,
            'enabled' => true,
            'status' => TenantInboxMonitorState::STATUS_IDLE,
            'last_checked_at' => now()->subMinutes(4),
        ]);

        $this->actingAs($user);

        $this->get('/dashboard')
            ->assertOk()
            ->assertSee('Paused while expired')
            ->assertSee('Inbox monitoring and customer replies are paused while expired-trial access is off.')
            ->assertSee('No inbox reads or replies happen until expired-trial access is fully restored.')
            ->assertDontSee('Watching your inbox');
    }

    public function test_dashboard_keeps_normal_inbox_and_assistant_states_when_all_expired_trial_overrides_are_enabled(): void
    {
        [$user, $tenant] = $this->seedTenant();

        $this->makeInboxTriageEligible($tenant, [
            'trial_status' => TrialStatus::Expired,
            'allow_polling_when_trial_expired' => true,
            'allow_runtime_replies_when_trial_expired' => true,
            'allow_litellm_when_trial_expired' => true,
        ]);

        TenantInboxMonitorState::query()->create([
            'tenant_id' => $tenant->id,
            'enabled' => true,
            'status' => TenantInboxMonitorState::STATUS_IDLE,
            'last_checked_at' => now()->subMinutes(4),
        ]);

        $this->actingAs($user);

        $this->get('/dashboard')
            ->assertOk()
            ->assertSee('Your trial has ended.')
            ->assertSee('Expired-trial access is still enabled, so inbox monitoring and customer replies stay live for now.')
            ->assertSee('Acme Plumbing is still active while expired-trial access remains enabled.')
            ->assertSee('Assistant')
            ->assertSee('Live')
            ->assertSee('Watching your inbox')
            ->assertDontSee('Paused while expired');
    }

    public function test_dashboard_shows_inbox_empty_state_when_inbox_triage_is_not_enabled(): void
    {
        [$user, $tenant] = $this->seedTenant();

        $tenant->forceFill([
            'provisioning_status' => TenantProvisioningStatus::Ready,
            'onboarding_status' => 'complete',
            'onboarding_step' => 7,
            'agent_status' => 'live',
            'runtime_path' => '/srv/sync360/runtime/tenants/acme-plumbing',
            'workspace_url' => 'https://acme-plumbing.workspace.test',
        ])->save();

        TenantGoogleCredential::query()->create([
            'tenant_id' => $tenant->id,
            'status' => TenantGoogleCredential::STATUS_CONNECTED,
            'runtime_sync_status' => TenantGoogleCredential::RUNTIME_SYNC_VERIFIED,
            'google_email' => 'alice@gmail.test',
            'refresh_token' => 'refresh-token',
            'scopes' => ['https://www.googleapis.com/auth/gmail.readonly'],
        ]);

        $this->actingAs($user);

        $this->get('/dashboard')
            ->assertOk()
            ->assertSee('Inbox Performance')
            ->assertSee('Inbox analytics will show up here.')
            ->assertSee('Open setup')
            ->assertDontSee('Watching your inbox');
    }

    public function test_dashboard_shows_healthy_inbox_overview_with_conversion_summary(): void
    {
        [$user, $tenant] = $this->seedTenant();

        $this->makeInboxTriageEligible($tenant);

        TenantInboxMonitorState::query()->create([
            'tenant_id' => $tenant->id,
            'enabled' => true,
            'status' => TenantInboxMonitorState::STATUS_IDLE,
            'last_checked_at' => now()->subMinutes(6),
        ]);

        TenantSkillConversionEvent::query()->create([
            'tenant_id' => $tenant->id,
            'event_id' => 'inbox-triage-event-1',
            'skill_key' => 'inbox-triage',
            'skill_version' => '1.5.8',
            'event_type' => 'conversion_succeeded',
            'conversion_type' => 'lead_qualified',
            'conversion_id' => 'gmail-1',
            'occurred_at' => now()->subDays(2),
            'customer_label' => 'Acme Plumbing',
            'human_effort_minutes' => 12,
            'agent_effort_minutes' => 2,
            'net_minutes_saved' => 10,
            'outcome_json' => ['lead_quality' => 'high'],
        ]);

        TenantSkillConversionEvent::query()->create([
            'tenant_id' => $tenant->id,
            'event_id' => 'inbox-triage-event-2',
            'skill_key' => 'inbox-triage',
            'skill_version' => '1.5.8',
            'event_type' => 'conversion_succeeded',
            'conversion_type' => 'lead_qualified',
            'conversion_id' => 'gmail-2',
            'occurred_at' => now()->subDays(1),
            'customer_label' => 'Acme Plumbing',
            'human_effort_minutes' => 10,
            'agent_effort_minutes' => 2,
            'net_minutes_saved' => 8,
            'outcome_json' => ['lead_quality' => 'medium'],
        ]);

        $this->actingAs($user);

        $this->get('/dashboard')
            ->assertOk()
            ->assertSee('Inbox Performance')
            ->assertSee('Watching your inbox')
            ->assertSee('Last checked 6 minutes ago')
            ->assertSee('2 qualified leads in last 30 days')
            ->assertDontSee('Reconnect Google Workspace')
            ->assertDontSee('We’re having trouble checking your inbox right now.');
    }

    public function test_dashboard_window_filter_updates_analytics_stats(): void
    {
        [$user, $tenant] = $this->seedTenant();

        $this->makeInboxTriageEligible($tenant);

        TenantInboxMonitorState::query()->create([
            'tenant_id' => $tenant->id,
            'enabled' => true,
            'status' => TenantInboxMonitorState::STATUS_IDLE,
            'last_checked_at' => now()->subMinutes(5),
        ]);

        TenantSkillConversionEvent::query()->create([
            'tenant_id' => $tenant->id,
            'event_id' => 'dashboard-window-recent',
            'skill_key' => 'inbox-triage',
            'skill_version' => '1.5.8',
            'event_type' => 'conversion_succeeded',
            'conversion_type' => 'lead_qualified',
            'conversion_id' => 'window-recent',
            'occurred_at' => now()->subDays(2),
            'customer_label' => 'Acme Plumbing',
            'human_effort_minutes' => 30,
            'agent_effort_minutes' => 5,
            'net_minutes_saved' => 25,
            'estimated_value_amount' => 250,
            'outcome_json' => ['lead_quality' => 'high'],
        ]);

        TenantSkillConversionEvent::query()->create([
            'tenant_id' => $tenant->id,
            'event_id' => 'dashboard-window-older',
            'skill_key' => 'inbox-triage',
            'skill_version' => '1.5.8',
            'event_type' => 'conversion_succeeded',
            'conversion_type' => 'lead_qualified',
            'conversion_id' => 'window-older',
            'occurred_at' => now()->subDays(40),
            'customer_label' => 'Acme Plumbing',
            'human_effort_minutes' => 45,
            'agent_effort_minutes' => 9,
            'net_minutes_saved' => 36,
            'estimated_value_amount' => 360,
            'outcome_json' => ['lead_quality' => 'medium'],
        ]);

        TenantInboxMonitorMessage::query()->create([
            'tenant_id' => $tenant->id,
            'gmail_message_id' => 'reviewed-recent',
            'gmail_thread_id' => 'thread-recent',
            'sender_domain' => 'example.com',
            'subject_preview' => 'Recent enquiry',
            'subject_hash' => hash('sha256', 'Recent enquiry'),
            'status' => TenantInboxMonitorMessage::STATUS_SENT_TO_AGENT,
            'detected_at' => now()->subDays(1),
            'delivered_to_agent_at' => now()->subDays(1),
        ]);

        TenantInboxMonitorMessage::query()->create([
            'tenant_id' => $tenant->id,
            'gmail_message_id' => 'reviewed-older',
            'gmail_thread_id' => 'thread-older',
            'sender_domain' => 'example.org',
            'subject_preview' => 'Older enquiry',
            'subject_hash' => hash('sha256', 'Older enquiry'),
            'status' => TenantInboxMonitorMessage::STATUS_SENT_TO_AGENT,
            'detected_at' => now()->subDays(40),
            'delivered_to_agent_at' => now()->subDays(40),
        ]);

        $this->actingAs($user);

        $this->get('/dashboard?window=7d')
            ->assertOk()
            ->assertSee('Reviewed work and successful outcomes in Last 7 days.')
            ->assertSee('1 reviewed items and 1 successful outcomes in last 7 days.')
            ->assertSee('25 min')
            ->assertSee('6.0x')
            ->assertSee('1 qualified lead in last 7 days')
            ->assertDontSee('2 reviewed items and 2 successful outcomes');

        $this->get('/dashboard?window=90d')
            ->assertOk()
            ->assertSee('Reviewed work and successful outcomes in Last 90 days.')
            ->assertSee('2 reviewed items and 2 successful outcomes in last 90 days.')
            ->assertSee('1h 1m')
            ->assertSee('2 qualified leads in last 90 days');
    }

    public function test_dashboard_shows_setup_incomplete_when_google_workspace_is_not_verified(): void
    {
        [$user, $tenant] = $this->seedTenant();

        $this->assignInboxTriageSkill($tenant, true);

        $tenant->forceFill([
            'provisioning_status' => TenantProvisioningStatus::Ready,
            'onboarding_status' => 'complete',
            'onboarding_step' => 7,
            'agent_status' => 'live',
            'runtime_path' => '/srv/sync360/runtime/tenants/acme-plumbing',
            'workspace_url' => 'https://acme-plumbing.workspace.test',
        ])->save();

        TenantGoogleCredential::query()->create([
            'tenant_id' => $tenant->id,
            'status' => TenantGoogleCredential::STATUS_CONNECTED,
            'runtime_sync_status' => TenantGoogleCredential::RUNTIME_SYNC_PENDING,
            'google_email' => 'alice@gmail.test',
            'refresh_token' => 'refresh-token',
            'scopes' => ['https://www.googleapis.com/auth/gmail.readonly'],
        ]);

        $this->actingAs($user);

        $this->get('/dashboard')
            ->assertOk()
            ->assertSee('Inbox Performance')
            ->assertSee('Setup incomplete')
            ->assertSee('Reconnect Google Workspace to resume inbox monitoring')
            ->assertSee('Open setup')
            ->assertDontSee('Watching your inbox');
    }

    public function test_dashboard_shows_needs_attention_when_inbox_monitor_is_failed(): void
    {
        [$user, $tenant] = $this->seedTenant();

        $this->makeInboxTriageEligible($tenant);

        TenantInboxMonitorState::query()->create([
            'tenant_id' => $tenant->id,
            'enabled' => true,
            'status' => TenantInboxMonitorState::STATUS_FAILED,
            'last_checked_at' => now()->subMinutes(4),
            'last_failed_at' => now()->subMinutes(2),
            'last_error' => 'gmail timeout',
        ]);

        TenantInboxMonitorMessage::query()->create([
            'tenant_id' => $tenant->id,
            'gmail_message_id' => 'gmail-fallback-1',
            'gmail_thread_id' => 'thread-1',
            'sender_domain' => 'example.com',
            'subject_preview' => 'Need a quote',
            'subject_hash' => hash('sha256', 'Need a quote'),
            'status' => TenantInboxMonitorMessage::STATUS_SENT_TO_AGENT,
            'detected_at' => now()->subDays(1),
            'delivered_to_agent_at' => now()->subDays(1),
        ]);

        $this->actingAs($user);

        $this->get('/dashboard')
            ->assertOk()
            ->assertSee('Inbox')
            ->assertSee('Needs attention')
            ->assertSee('We’re having trouble checking your inbox right now.')
            ->assertSee('1 inbox item reviewed in last 30 days')
            ->assertSee('Open setup');
    }

    public function test_dashboard_shows_needs_attention_when_inbox_check_is_stale(): void
    {
        [$user, $tenant] = $this->seedTenant();

        $this->makeInboxTriageEligible($tenant);

        TenantInboxMonitorState::query()->create([
            'tenant_id' => $tenant->id,
            'enabled' => true,
            'status' => TenantInboxMonitorState::STATUS_IDLE,
            'last_checked_at' => now()->subMinutes(20),
        ]);

        $this->actingAs($user);

        $this->get('/dashboard')
            ->assertOk()
            ->assertSee('Needs attention')
            ->assertSee('We’re having trouble checking your inbox right now.');
    }

    public function test_dashboard_shows_fallback_activity_when_no_conversions_exist(): void
    {
        [$user, $tenant] = $this->seedTenant();

        $this->makeInboxTriageEligible($tenant);

        TenantInboxMonitorState::query()->create([
            'tenant_id' => $tenant->id,
            'enabled' => true,
            'status' => TenantInboxMonitorState::STATUS_IDLE,
            'last_checked_at' => now()->subMinutes(3),
        ]);

        TenantInboxMonitorMessage::query()->create([
            'tenant_id' => $tenant->id,
            'gmail_message_id' => 'gmail-reviewed-1',
            'gmail_thread_id' => 'thread-1',
            'sender_domain' => 'example.com',
            'subject_preview' => 'Need a plumber',
            'subject_hash' => hash('sha256', 'Need a plumber'),
            'status' => TenantInboxMonitorMessage::STATUS_SENT_TO_AGENT,
            'detected_at' => now()->subDays(1),
            'delivered_to_agent_at' => now()->subDays(1),
        ]);

        TenantInboxMonitorMessage::query()->create([
            'tenant_id' => $tenant->id,
            'gmail_message_id' => 'gmail-reviewed-2',
            'gmail_thread_id' => 'thread-2',
            'sender_domain' => 'example.org',
            'subject_preview' => 'Kitchen install',
            'subject_hash' => hash('sha256', 'Kitchen install'),
            'status' => TenantInboxMonitorMessage::STATUS_SENT_TO_AGENT,
            'detected_at' => now()->subHours(12),
            'delivered_to_agent_at' => now()->subHours(12),
        ]);

        $this->actingAs($user);

        $this->get('/dashboard')
            ->assertOk()
            ->assertSee('2 inbox items reviewed in last 30 days')
            ->assertDontSee('qualified leads in last 30 days');
    }

    public function test_dashboard_shows_soft_telegram_note_without_downgrading_healthy_status(): void
    {
        [$user, $tenant] = $this->seedTenant();

        $this->makeInboxTriageEligible($tenant, [
            'channel' => 'telegram',
            'channel_config' => ['telegram_bot_token' => 'telegram-token'],
        ]);

        TenantInboxMonitorState::query()->create([
            'tenant_id' => $tenant->id,
            'enabled' => true,
            'status' => TenantInboxMonitorState::STATUS_IDLE,
            'last_checked_at' => now()->subMinutes(4),
        ]);

        $this->actingAs($user);

        $this->get('/dashboard')
            ->assertOk()
            ->assertSee('Watching your inbox')
            ->assertSee('Urgent Telegram alerts are not set up yet.')
            ->assertDontSee('Needs attention')
            ->assertDontSee('Setup incomplete');
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

    private function makeInboxTriageEligible(Tenant $tenant, array $overrides = []): void
    {
        $tenant->forceFill(array_merge([
            'provisioning_status' => TenantProvisioningStatus::Ready,
            'onboarding_status' => 'complete',
            'onboarding_step' => 7,
            'agent_status' => 'live',
            'channel' => 'telegram',
            'channel_config' => [
                'telegram_bot_token' => 'telegram-token',
                'telegram_default_chat_id' => '12345',
            ],
            'runtime_path' => '/srv/sync360/runtime/tenants/'.$tenant->slug,
            'workspace_url' => 'https://'.$tenant->slug.'.workspace.test',
        ], $overrides))->save();

        TenantGoogleCredential::query()->create([
            'tenant_id' => $tenant->id,
            'status' => TenantGoogleCredential::STATUS_CONNECTED,
            'runtime_sync_status' => TenantGoogleCredential::RUNTIME_SYNC_VERIFIED,
            'google_email' => $tenant->slug.'@gmail.test',
            'refresh_token' => 'refresh-token',
            'scopes' => ['https://www.googleapis.com/auth/gmail.readonly'],
        ]);

        $this->assignInboxTriageSkill($tenant, true);
    }

    private function assignInboxTriageSkill(Tenant $tenant, bool $enabled): void
    {
        $item = SkillCatalogItem::query()->firstOrCreate(
            ['skill_key' => 'inbox-triage'],
            [
                'label' => 'Inbox Triage',
                'description' => 'Inbox Triage',
                'category' => 'operations',
                'is_assignable' => true,
                'is_orphaned' => false,
            ],
        );

        $version = SkillCatalogVersion::query()->firstOrCreate(
            ['skill_catalog_item_id' => $item->id, 'version' => '1.5.8'],
            [
                'skill_key' => 'inbox-triage',
                'manifest_json' => ['skill_id' => 'inbox-triage', 'version' => '1.6.2'],
                'is_active_published' => true,
                'is_archived' => false,
                'is_available' => true,
                'discovered_at' => now(),
                'last_imported_at' => now(),
            ],
        );

        TenantSkillAssignment::query()->create([
            'tenant_id' => $tenant->id,
            'skill_catalog_version_id' => $version->id,
            'skill_key' => 'inbox-triage',
            'assigned_by' => $tenant->user_id,
            'assigned_at' => now(),
            'is_enabled' => $enabled,
            'last_apply_status' => 'completed',
        ]);
    }
}
