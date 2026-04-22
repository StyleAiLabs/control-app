<?php

namespace Tests\Feature;

use App\Enums\TenantProvisioningStatus;
use App\Enums\TrialStatus;
use App\Jobs\ProcessTenantInboxTriage;
use App\Models\Server;
use App\Models\SkillCatalogItem;
use App\Models\SkillCatalogVersion;
use App\Models\Tenant;
use App\Models\TenantGoogleCredential;
use App\Models\TenantInboxMonitorMessage;
use App\Models\TenantInboxMonitorState;
use App\Models\TenantSkillAssignment;
use App\Models\User;
use App\Services\TenantInboxGmailRuntimeService;
use App\Services\TenantInboxTriagePollingService;
use App\Services\TenantWorkspaceMessenger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class InboxTriagePollingTest extends TestCase
{
    use RefreshDatabase;

    public function test_poll_command_queues_only_eligible_tenants_not_in_backoff(): void
    {
        Queue::fake();

        $eligible = $this->seedInboxTenant('eligible-shop');
        $this->seedInboxTenant('broken-google', googleVerified: false);
        $backoff = $this->seedInboxTenant('backoff-shop');
        TenantInboxMonitorState::query()->create([
            'tenant_id' => $backoff->id,
            'enabled' => true,
            'status' => TenantInboxMonitorState::STATUS_FAILED,
            'backoff_until' => now()->addMinutes(10),
        ]);

        $this->artisan('sync360:poll-inbox-triage')
            ->assertExitCode(0)
            ->expectsOutputToContain('Queued inbox triage polling for 1 eligible tenants.');

        Queue::assertPushed(ProcessTenantInboxTriage::class, 1);
        Queue::assertPushed(ProcessTenantInboxTriage::class, fn (ProcessTenantInboxTriage $job): bool => $job->tenantId === $eligible->id);
    }

    public function test_poll_tenant_sends_neutral_business_plausible_event_without_persisting_body(): void
    {
        $tenant = $this->seedInboxTenant('lead-shop');
        $gmail = Mockery::mock(TenantInboxGmailRuntimeService::class);
        $messenger = Mockery::mock(TenantWorkspaceMessenger::class);

        $gmail->shouldReceive('searchRecentInbox')
            ->once()
            ->with(Mockery::on(fn (Tenant $value): bool => $value->is($tenant)))
            ->andReturn([[
                'id' => 'msg-001',
                'thread_id' => 'thread-001',
                'from' => 'Jane Buyer <jane@example.com>',
                'subject' => 'Quote needed for fit-out',
                'labels' => ['INBOX'],
            ]]);
        $gmail->shouldReceive('getMessage')
            ->once()
            ->with(Mockery::on(fn (Tenant $value): bool => $value->is($tenant)), 'msg-001')
            ->andReturn([
                'raw' => "id\tmsg-001\nthread_id\tthread-001\nlabel_ids\tUNREAD,INBOX\nfrom\tJane Buyer <jane@example.com>\nsubject\tQuote needed for fit-out\ndate\tTue, 21 Apr 2026 08:36:00 +0000\n\nSubmitted from the website contact form. We need a quote for a commercial fit-out next month.",
                'metadata' => [
                    'id' => 'msg-001',
                    'thread_id' => 'thread-001',
                    'label_ids' => 'UNREAD,INBOX',
                    'from' => 'Jane Buyer <jane@example.com>',
                    'subject' => 'Quote needed for fit-out',
                    'date' => 'Tue, 21 Apr 2026 08:36:00 +0000',
                ],
                'body' => 'Submitted from the website contact form. We need a quote for a commercial fit-out next month.',
            ]);
        $messenger->shouldReceive('send')
            ->once()
            ->withArgs(function (Tenant $value, string $channel, string $from, string $message): bool {
                return $value->slug === 'lead-shop'
                    && $channel === TenantInboxTriagePollingService::CHANNEL
                    && $from === TenantInboxTriagePollingService::FROM
                    && str_contains($message, 'business-plausible Gmail message')
                    && str_contains($message, 'This trigger has not classified the email as high-value.')
                    && str_contains($message, 'Read the workspace skill file at ./skills/inbox-triage/SKILL.md')
                    && str_contains($message, 'Critical Runtime Contracts for Telegram, Google Drive triage logging, and analytics')
                    && str_contains($message, 'Do not look under /app/skills')
                    && str_contains($message, 'Execute the inbox-triage workflow now; do not reply with only an assessment, recommendation, or plan.')
                    && str_contains($message, 'Do not browse the public web or research the sender unless the operator explicitly asks for that.')
                    && str_contains($message, '"possible_form_submission"')
                    && ! str_contains($message, 'send the Telegram notification');
            })
            ->andReturn('routed');

        $this->instance(TenantInboxGmailRuntimeService::class, $gmail);
        $this->instance(TenantWorkspaceMessenger::class, $messenger);

        $result = app(TenantInboxTriagePollingService::class)->pollTenant($tenant);

        $this->assertSame(['processed' => 1, 'delivered' => 1, 'skipped' => 0, 'failed' => 0], $result);
        $this->assertDatabaseHas('tenant_inbox_monitor_messages', [
            'tenant_id' => $tenant->id,
            'gmail_message_id' => 'msg-001',
            'gmail_thread_id' => 'thread-001',
            'sender_domain' => 'example.com',
            'status' => TenantInboxMonitorMessage::STATUS_SENT_TO_AGENT,
        ]);

        $record = TenantInboxMonitorMessage::query()->firstOrFail();
        $this->assertSame(hash('sha256', 'Quote needed for fit-out'), $record->subject_hash);
        $this->assertStringNotContainsString('commercial fit-out next month', implode(' ', array_filter($record->getAttributes(), 'is_string')));
    }

    public function test_noise_messages_are_skipped_without_invoking_gateway(): void
    {
        $tenant = $this->seedInboxTenant('noise-shop');
        $gmail = Mockery::mock(TenantInboxGmailRuntimeService::class);
        $messenger = Mockery::mock(TenantWorkspaceMessenger::class);

        $gmail->shouldReceive('searchRecentInbox')->once()->andReturn([[
            'id' => 'promo-001',
            'thread_id' => 'thread-promo',
            'from' => 'Newsletter <news@example.com>',
            'subject' => 'Monthly newsletter',
            'labels' => ['CATEGORY_PROMOTIONS', 'INBOX'],
        ]]);
        $gmail->shouldReceive('getMessage')->once()->andReturn([
            'raw' => "id\tpromo-001\nthread_id\tthread-promo\nlabel_ids\tCATEGORY_PROMOTIONS,INBOX\nfrom\tNewsletter <news@example.com>\nsubject\tMonthly newsletter\n\nRead our update.",
            'metadata' => [
                'id' => 'promo-001',
                'thread_id' => 'thread-promo',
                'label_ids' => 'CATEGORY_PROMOTIONS,INBOX',
                'from' => 'Newsletter <news@example.com>',
                'subject' => 'Monthly newsletter',
            ],
            'body' => 'Read our update.',
        ]);
        $messenger->shouldNotReceive('send');

        $this->instance(TenantInboxGmailRuntimeService::class, $gmail);
        $this->instance(TenantWorkspaceMessenger::class, $messenger);

        $result = app(TenantInboxTriagePollingService::class)->pollTenant($tenant);

        $this->assertSame(['processed' => 1, 'delivered' => 0, 'skipped' => 1, 'failed' => 0], $result);
        $this->assertDatabaseHas('tenant_inbox_monitor_messages', [
            'tenant_id' => $tenant->id,
            'gmail_message_id' => 'promo-001',
            'status' => TenantInboxMonitorMessage::STATUS_SKIPPED,
            'skip_reason' => 'noise_label:CATEGORY_PROMOTIONS',
        ]);
    }

    public function test_duplicate_messages_are_not_sent_to_the_skill_again(): void
    {
        $tenant = $this->seedInboxTenant('dupe-shop');
        TenantInboxMonitorMessage::query()->create([
            'tenant_id' => $tenant->id,
            'gmail_message_id' => 'msg-duplicate',
            'status' => TenantInboxMonitorMessage::STATUS_SENT_TO_AGENT,
            'detected_at' => now(),
            'delivered_to_agent_at' => now(),
        ]);
        $gmail = Mockery::mock(TenantInboxGmailRuntimeService::class);
        $messenger = Mockery::mock(TenantWorkspaceMessenger::class);

        $gmail->shouldReceive('searchRecentInbox')->once()->andReturn([[
            'id' => 'msg-duplicate',
            'thread_id' => 'thread-duplicate',
            'from' => 'Jane Buyer <jane@example.com>',
            'subject' => 'Quote needed',
            'labels' => ['INBOX'],
        ]]);
        $gmail->shouldReceive('getMessage')->once()->with(
            Mockery::on(fn (Tenant $value): bool => $value->is($tenant)),
            'msg-duplicate',
        )->andReturn([
            'raw' => "id\tmsg-duplicate\nthread_id\tthread-duplicate\nlabel_ids\tINBOX\nfrom\tJane Buyer <jane@example.com>\nsubject\tQuote needed\n\nPlease quote this project.",
            'metadata' => [
                'id' => 'msg-duplicate',
                'thread_id' => 'thread-duplicate',
                'label_ids' => 'INBOX',
                'from' => 'Jane Buyer <jane@example.com>',
                'subject' => 'Quote needed',
            ],
            'body' => 'Please quote this project.',
        ]);
        $messenger->shouldNotReceive('send');

        $this->instance(TenantInboxGmailRuntimeService::class, $gmail);
        $this->instance(TenantWorkspaceMessenger::class, $messenger);

        $result = app(TenantInboxTriagePollingService::class)->pollTenant($tenant);

        $this->assertSame(['processed' => 1, 'delivered' => 0, 'skipped' => 1, 'failed' => 0], $result);
    }

    public function test_new_message_in_existing_thread_is_not_skipped_as_a_duplicate(): void
    {
        $tenant = $this->seedInboxTenant('thread-follow-up-shop');
        TenantInboxMonitorMessage::query()->create([
            'tenant_id' => $tenant->id,
            'gmail_message_id' => 'msg-original',
            'gmail_thread_id' => 'thread-quote',
            'status' => TenantInboxMonitorMessage::STATUS_SENT_TO_AGENT,
            'detected_at' => now()->subMinutes(10),
            'delivered_to_agent_at' => now()->subMinutes(10),
        ]);

        $gmail = Mockery::mock(TenantInboxGmailRuntimeService::class);
        $messenger = Mockery::mock(TenantWorkspaceMessenger::class);

        $gmail->shouldReceive('searchRecentInbox')->once()->andReturn([[
            'id' => 'thread-quote',
            'thread_id' => 'thread-quote',
            'from' => 'Major Hartley <james.hartley@wespac.co.nz>',
            'subject' => 'Quote Please',
            'labels' => ['INBOX'],
            'messageCount' => 2,
        ]]);
        $gmail->shouldReceive('getMessage')->once()->with(
            Mockery::on(fn (Tenant $value): bool => $value->is($tenant)),
            'thread-quote',
        )->andReturn([
            'raw' => "id\tmsg-follow-up\nthread_id\tthread-quote\nlabel_ids\tINBOX\nfrom\tMajor Hartley <james.hartley@wespac.co.nz>\nsubject\tQuote Please\ndate\tTue, 21 Apr 2026 23:32:00 +0000\n\nWe need a cleaning and maintenance quote for three newly completed sites.",
            'metadata' => [
                'id' => 'msg-follow-up',
                'thread_id' => 'thread-quote',
                'label_ids' => 'INBOX',
                'from' => 'Major Hartley <james.hartley@wespac.co.nz>',
                'subject' => 'Quote Please',
                'date' => 'Tue, 21 Apr 2026 23:32:00 +0000',
            ],
            'body' => 'We need a cleaning and maintenance quote for three newly completed sites.',
        ]);
        $messenger->shouldReceive('send')->once()->withArgs(function (Tenant $value, string $channel, string $from, string $message): bool {
            return $value->slug === 'thread-follow-up-shop'
                && $channel === TenantInboxTriagePollingService::CHANNEL
                && $from === TenantInboxTriagePollingService::FROM
                && str_contains($message, '"gmail_message_id": "msg-follow-up"');
        })->andReturn('routed');

        $this->instance(TenantInboxGmailRuntimeService::class, $gmail);
        $this->instance(TenantWorkspaceMessenger::class, $messenger);

        $result = app(TenantInboxTriagePollingService::class)->pollTenant($tenant);

        $this->assertSame(['processed' => 1, 'delivered' => 1, 'skipped' => 0, 'failed' => 0], $result);
        $this->assertDatabaseHas('tenant_inbox_monitor_messages', [
            'tenant_id' => $tenant->id,
            'gmail_message_id' => 'msg-follow-up',
            'gmail_thread_id' => 'thread-quote',
            'status' => TenantInboxMonitorMessage::STATUS_SENT_TO_AGENT,
        ]);
    }

    public function test_gateway_failures_increment_attempts_and_stop_after_cap(): void
    {
        $tenant = $this->seedInboxTenant('retry-shop');
        TenantInboxMonitorMessage::query()->create([
            'tenant_id' => $tenant->id,
            'gmail_message_id' => 'msg-retry',
            'status' => TenantInboxMonitorMessage::STATUS_FAILED,
            'attempts' => 2,
            'detected_at' => now(),
            'last_attempted_at' => now()->subMinutes(5),
            'last_error' => 'previous failure',
        ]);

        $gmail = Mockery::mock(TenantInboxGmailRuntimeService::class);
        $messenger = Mockery::mock(TenantWorkspaceMessenger::class);
        $gmail->shouldReceive('searchRecentInbox')->once()->andReturn([[
            'id' => 'msg-retry',
            'thread_id' => 'thread-retry',
            'from' => 'Jane Buyer <jane@example.com>',
            'subject' => 'Quote needed',
            'labels' => ['INBOX'],
        ]]);
        $gmail->shouldReceive('getMessage')->once()->andReturn([
            'raw' => "id\tmsg-retry\nthread_id\tthread-retry\nlabel_ids\tINBOX\nfrom\tJane Buyer <jane@example.com>\nsubject\tQuote needed\n\nPlease quote this project.",
            'metadata' => [
                'id' => 'msg-retry',
                'thread_id' => 'thread-retry',
                'label_ids' => 'INBOX',
                'from' => 'Jane Buyer <jane@example.com>',
                'subject' => 'Quote needed',
            ],
            'body' => 'Please quote this project.',
        ]);
        $messenger->shouldReceive('send')->once()->andThrow(new RuntimeException('gateway unavailable'));

        $this->instance(TenantInboxGmailRuntimeService::class, $gmail);
        $this->instance(TenantWorkspaceMessenger::class, $messenger);

        $result = app(TenantInboxTriagePollingService::class)->pollTenant($tenant);

        $this->assertSame(['processed' => 1, 'delivered' => 0, 'skipped' => 0, 'failed' => 1], $result);
        $this->assertDatabaseHas('tenant_inbox_monitor_messages', [
            'tenant_id' => $tenant->id,
            'gmail_message_id' => 'msg-retry',
            'status' => TenantInboxMonitorMessage::STATUS_FAILED,
            'attempts' => 3,
            'last_error' => 'gateway unavailable',
        ]);

        $gmail = Mockery::mock(TenantInboxGmailRuntimeService::class);
        $messenger = Mockery::mock(TenantWorkspaceMessenger::class);
        $gmail->shouldReceive('searchRecentInbox')->once()->andReturn([[
            'id' => 'msg-retry',
            'thread_id' => 'thread-retry',
            'from' => 'Jane Buyer <jane@example.com>',
            'subject' => 'Quote needed',
            'labels' => ['INBOX'],
        ]]);
        $gmail->shouldReceive('getMessage')->once()->with(
            Mockery::on(fn (Tenant $value): bool => $value->id === $tenant->id),
            'msg-retry',
        )->andReturn([
            'raw' => "id\tmsg-retry\nthread_id\tthread-retry\nlabel_ids\tINBOX\nfrom\tJane Buyer <jane@example.com>\nsubject\tQuote needed\n\nPlease quote this project.",
            'metadata' => [
                'id' => 'msg-retry',
                'thread_id' => 'thread-retry',
                'label_ids' => 'INBOX',
                'from' => 'Jane Buyer <jane@example.com>',
                'subject' => 'Quote needed',
            ],
            'body' => 'Please quote this project.',
        ]);
        $messenger->shouldNotReceive('send');

        $this->instance(TenantInboxGmailRuntimeService::class, $gmail);
        $this->instance(TenantWorkspaceMessenger::class, $messenger);

        $result = app(TenantInboxTriagePollingService::class)->pollTenant($tenant->fresh());

        $this->assertSame(['processed' => 1, 'delivered' => 0, 'skipped' => 1, 'failed' => 0], $result);
    }

    public function test_gmail_runtime_failure_marks_monitor_failed_with_backoff(): void
    {
        $tenant = $this->seedInboxTenant('gmail-failure-shop');
        $gmail = Mockery::mock(TenantInboxGmailRuntimeService::class);
        $messenger = Mockery::mock(TenantWorkspaceMessenger::class);

        $gmail->shouldReceive('searchRecentInbox')
            ->once()
            ->andThrow(new RuntimeException('gog gmail search failed'));
        $messenger->shouldNotReceive('send');

        $this->instance(TenantInboxGmailRuntimeService::class, $gmail);
        $this->instance(TenantWorkspaceMessenger::class, $messenger);

        $result = app(TenantInboxTriagePollingService::class)->pollTenant($tenant);

        $this->assertSame(['processed' => 0, 'delivered' => 0, 'skipped' => 0, 'failed' => 1], $result);

        $state = TenantInboxMonitorState::query()->where('tenant_id', $tenant->id)->firstOrFail();
        $this->assertSame(TenantInboxMonitorState::STATUS_FAILED, $state->status);
        $this->assertSame('gog gmail search failed', $state->last_error);
        $this->assertNotNull($state->backoff_until);
        $this->assertTrue($state->backoff_until->isFuture());
    }

    private function seedInboxTenant(string $slug, bool $googleVerified = true): Tenant
    {
        $owner = User::query()->create([
            'name' => $slug.' Owner',
            'email' => $slug.'@example.com',
            'password' => 'secret',
        ]);
        $server = Server::query()->firstOrFail();
        $tenant = Tenant::query()->create([
            'tenant_id' => 'tenant-'.$slug,
            'slug' => $slug,
            'business_name' => ucfirst(str_replace('-', ' ', $slug)),
            'industry' => 'services',
            'skill_pack' => 'standard',
            'channel' => 'telegram',
            'channel_config' => ['telegram_bot_token' => 'telegram-token', 'telegram_default_chat_id' => '12345'],
            'agent_status' => 'live',
            'trial_status' => TrialStatus::Active,
            'provisioning_status' => TenantProvisioningStatus::Ready,
            'onboarding_status' => 'complete',
            'onboarding_step' => 7,
            'assigned_port' => 4100 + Tenant::query()->count(),
            'workspace_url' => 'https://'.$slug.'.workspace.test',
            'runtime_path' => '/srv/sync360/runtime/tenants/'.$slug,
            'server_id' => $server->id,
            'user_id' => $owner->id,
        ]);

        TenantGoogleCredential::query()->create([
            'tenant_id' => $tenant->id,
            'status' => TenantGoogleCredential::STATUS_CONNECTED,
            'runtime_sync_status' => $googleVerified
                ? TenantGoogleCredential::RUNTIME_SYNC_VERIFIED
                : TenantGoogleCredential::RUNTIME_SYNC_FAILED,
            'google_email' => $slug.'@gmail.test',
            'refresh_token' => 'refresh-token',
            'scopes' => ['https://www.googleapis.com/auth/gmail.readonly'],
        ]);

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
            ['skill_catalog_item_id' => $item->id, 'version' => '1.5.6'],
            [
                'skill_key' => 'inbox-triage',
                'manifest_json' => ['skill_id' => 'inbox-triage', 'version' => '1.5.6'],
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
            'assigned_by' => $owner->id,
            'assigned_at' => now(),
            'is_enabled' => true,
            'last_apply_status' => 'completed',
        ]);

        return $tenant->fresh(['server', 'googleCredential', 'skillAssignments.catalogVersion']);
    }
}
