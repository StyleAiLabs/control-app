<?php

namespace Tests\Feature;

use App\Enums\ProvisioningJobStatus;
use App\Enums\TenantProvisioningStatus;
use App\Enums\TrialStatus;
use App\Jobs\ApplyTenantAgentCustomization;
use App\Jobs\ResyncLiveTenantWorkspaceAfterSkillRollout;
use App\Models\BusinessProfile;
use App\Models\BusinessProfileFiles;
use App\Models\ProvisioningJob;
use App\Models\SkillCatalogVersion;
use App\Models\Tenant;
use App\Models\TenantAgentCustomization;
use App\Models\TenantAgentCustomizationApply;
use App\Models\TenantGoogleCredential;
use App\Models\TenantInboxMonitorMessage;
use App\Models\TenantInboxMonitorState;
use App\Models\TenantSkillAssignment;
use App\Models\User;
use App\Services\TenantHealthCheckService;
use App\Services\TenantRuntimeSkillDiscoveryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class AdminTenantCustomizationFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_save_draft_preview_and_queue_apply(): void
    {
        Queue::fake([ApplyTenantAgentCustomization::class]);

        [$admin, $tenant] = $this->seedAdminAndTenant();

        $this->actingAs($admin);

        $this->patch(route('admin.tenants.agent-customization.update', $tenant), [
            'return_tab' => 'agent-runtime',
            'prompt_overrides' => [
                'identity' => [
                    'mode' => 'append',
                    'content' => 'Custom admin identity guidance',
                ],
            ],
            'agent_defaults' => [
                'model' => 'gpt-4.1',
            ],
        ])->assertRedirect(route('admin.tenants.show', ['tenant' => $tenant, 'tab' => 'agent-runtime']))
            ->assertSessionHas('status', 'Saved tenant runtime draft. Runtime has not changed yet.');

        $this->patch(route('admin.tenants.agent-customization.update', $tenant), [
            'return_tab' => 'skills',
            'assigned_skill_keys' => ['hello-world'],
            'agent_defaults' => [
                'default_skill_ids' => 'custom-default-skill, follow-up-skill',
            ],
        ])->assertRedirect(route('admin.tenants.show', ['tenant' => $tenant, 'tab' => 'skills']))
            ->assertSessionHas('status', 'Saved tenant skill draft. Runtime has not changed yet.');

        $customization = TenantAgentCustomization::query()->firstOrFail();

        $this->assertSame(2, $customization->draft_version);
        $this->assertSame('append', data_get($customization->prompt_overrides_json, 'identity.mode'));
        $this->assertSame(['hello-world'], $tenant->skillAssignments()->where('is_enabled', true)->pluck('skill_key')->all());
        $this->assertSame('gpt-4.1', data_get($customization->agent_defaults_json, 'model'));
        $this->assertSame(
            ['custom-default-skill', 'follow-up-skill'],
            data_get($customization->agent_defaults_json, 'default_skill_ids')
        );

        $preview = $this->postJson(route('admin.tenants.agent-customization.preview', $tenant));

        $preview->assertOk()
            ->assertJsonPath('base_drifted.identity', false);

        $workspaceFiles = $preview->json('workspace_files');

        $this->assertStringContainsString(
            'Custom admin identity guidance',
            (string) ($workspaceFiles['IDENTITY.md'] ?? '')
        );

        $this->post(route('admin.tenants.agent-customization.apply', $tenant), [
            'return_tab' => 'skills',
        ])->assertRedirect(route('admin.tenants.show', ['tenant' => $tenant, 'tab' => 'skills']))
            ->assertSessionHas('status', 'Queued runtime apply for customization-shop using assigned skill versions.');

        $job = ProvisioningJob::query()->latest('id')->first();

        $this->assertNotNull($job);
        $this->assertSame(ProvisioningJobStatus::Queued, $job->status);
        $this->assertSame(ApplyTenantAgentCustomization::JOB_TYPE, $job->job_type);

        Queue::assertPushed(ApplyTenantAgentCustomization::class, function (ApplyTenantAgentCustomization $queuedJob) use ($tenant, $job): bool {
            return $queuedJob->tenantId === $tenant->id
                && $queuedJob->provisioningJobId === $job?->id
                && $queuedJob->action === TenantAgentCustomizationApply::ACTION_APPLY;
        });
    }

    public function test_admin_without_apply_permission_cannot_apply_or_revert(): void
    {
        Queue::fake([ApplyTenantAgentCustomization::class]);
        config()->set('sync360.runtime_customization.apply_authorized_emails', ['allowed@example.com']);

        [$admin, $tenant] = $this->seedAdminAndTenant(email: 'blocked@example.com');

        TenantAgentCustomization::query()->create([
            'tenant_id' => $tenant->id,
            'prompt_overrides_json' => [],
            'agent_defaults_json' => [],
            'draft_version' => 1,
            'draft_updated_by' => $admin->id,
            'draft_updated_at' => now(),
            'last_applied_input_snapshot_json' => [
                'prompt_overrides' => [],
                'assigned_skills' => [],
                'agent_defaults' => [],
            ],
        ]);

        $this->actingAs($admin);

        $this->post(route('admin.tenants.agent-customization.apply', $tenant))->assertForbidden();
        $this->post(route('admin.tenants.agent-customization.revert', $tenant))->assertForbidden();

        Queue::assertNothingPushed();
    }

    public function test_admin_can_view_apply_log_history(): void
    {
        [$admin, $tenant] = $this->seedAdminAndTenant();

        $customization = TenantAgentCustomization::query()->create([
            'tenant_id' => $tenant->id,
            'prompt_overrides_json' => [],
            'agent_defaults_json' => [],
            'draft_version' => 1,
            'draft_updated_by' => $admin->id,
            'draft_updated_at' => now(),
        ]);

        TenantAgentCustomizationApply::query()->create([
            'tenant_agent_customization_id' => $customization->id,
            'tenant_id' => $tenant->id,
            'applied_by' => $admin->id,
            'action' => TenantAgentCustomizationApply::ACTION_APPLY,
            'draft_version_applied' => 1,
            'input_snapshot_json' => ['assigned_skills' => []],
            'before_output_hash' => null,
            'after_output_hash' => 'hash-1',
            'status' => TenantAgentCustomizationApply::STATUS_APPLIED,
        ]);

        $this->actingAs($admin);

        $this->getJson(route('admin.tenants.agent-customization.apply-log', $tenant))
            ->assertOk()
            ->assertJsonPath('data.0.after_output_hash', 'hash-1')
            ->assertJsonPath('data.0.action', TenantAgentCustomizationApply::ACTION_APPLY);
    }

    public function test_skills_tab_shows_skill_change_history_and_runtime_tab_keeps_apply_history(): void
    {
        [$admin, $tenant] = $this->seedAdminAndTenant();

        $customization = TenantAgentCustomization::query()->create([
            'tenant_id' => $tenant->id,
            'prompt_overrides_json' => [],
            'agent_defaults_json' => [
                'model' => 'gpt-4o',
                'default_skill_ids' => ['booking-skill'],
            ],
            'draft_version' => 2,
            'draft_updated_by' => $admin->id,
            'draft_updated_at' => now(),
        ]);

        TenantSkillAssignment::query()->create([
            'tenant_id' => $tenant->id,
            'skill_catalog_version_id' => SkillCatalogVersion::query()->where('skill_key', 'hello-world')->value('id'),
            'skill_key' => 'hello-world',
            'assigned_by' => $admin->id,
            'assigned_at' => now(),
            'is_enabled' => true,
        ]);

        TenantAgentCustomizationApply::query()->create([
            'tenant_agent_customization_id' => $customization->id,
            'tenant_id' => $tenant->id,
            'applied_by' => $admin->id,
            'action' => TenantAgentCustomizationApply::ACTION_APPLY,
            'draft_version_applied' => 1,
            'input_snapshot_json' => [
                'prompt_overrides' => [],
                'assigned_skills' => [],
                'agent_defaults' => [],
            ],
            'before_output_hash' => null,
            'after_output_hash' => 'hash-0',
            'status' => TenantAgentCustomizationApply::STATUS_APPLIED,
            'created_at' => now()->subMinute(),
        ]);

        TenantAgentCustomizationApply::query()->create([
            'tenant_agent_customization_id' => $customization->id,
            'tenant_id' => $tenant->id,
            'applied_by' => $admin->id,
            'action' => TenantAgentCustomizationApply::ACTION_APPLY,
            'draft_version_applied' => 2,
            'input_snapshot_json' => [
                'prompt_overrides' => [],
                'assigned_skills' => [
                    [
                        'skill_key' => 'hello-world',
                        'openclaw_skill_ids' => ['hello-world'],
                        'default_agent_skill_ids' => ['hello-world'],
                    ],
                ],
                'agent_defaults' => [
                    'model' => 'gpt-4o',
                    'default_skill_ids' => ['booking-skill'],
                ],
            ],
            'before_output_hash' => 'hash-0',
            'after_output_hash' => 'hash-1',
            'status' => TenantAgentCustomizationApply::STATUS_APPLIED,
            'created_at' => now(),
        ]);

        $this->actingAs($admin);

        $this->get(route('admin.tenants.show', ['tenant' => $tenant, 'tab' => 'skills']))
            ->assertOk()
            ->assertSee('applied')
            ->assertSee('1 assigned')
            ->assertSee('Skill Change History')
            ->assertSee('Enabled skill pack: Hello World (by Sync360)')
            ->assertSee('Added default skill ID: booking-skill')
            ->assertDontSee('Before: hash-0')
            ->assertDontSee('After: hash-1');

        $this->get(route('admin.tenants.show', ['tenant' => $tenant, 'tab' => 'agent-runtime']))
            ->assertOk()
            ->assertSee('Apply History')
            ->assertSee('Before: hash-0')
            ->assertSee('After: hash-1')
            ->assertDontSee('Skill Change History');
    }

    public function test_admin_tenant_page_shows_current_base_prompt_content_in_customization_panel(): void
    {
        [$admin, $tenant] = $this->seedAdminAndTenant();

        $this->actingAs($admin);

        $this->get(route('admin.tenants.show', ['tenant' => $tenant, 'tab' => 'agent-runtime']))
            ->assertOk()
            ->assertSee('Current IDENTITY.md')
            ->assertSee('Base identity')
            ->assertSee('Current AGENTS.md')
            ->assertSee('Current BOOTSTRAP.md')
            ->assertSee('Base bootstrap');
    }

    public function test_saving_skills_tab_preserves_existing_runtime_customization_fields(): void
    {
        [$admin, $tenant] = $this->seedAdminAndTenant();

        TenantAgentCustomization::query()->create([
            'tenant_id' => $tenant->id,
            'prompt_overrides_json' => [
                'identity' => [
                    'mode' => 'append',
                    'content' => 'Keep this prompt override',
                    'base_snapshot' => '# Identity'.PHP_EOL.PHP_EOL.'Base identity',
                ],
            ],
            'agent_defaults_json' => [
                'model' => 'gpt-4.1',
            ],
            'draft_version' => 1,
            'draft_updated_by' => $admin->id,
            'draft_updated_at' => now(),
        ]);

        $this->actingAs($admin);

        $this->patch(route('admin.tenants.agent-customization.update', $tenant), [
            'return_tab' => 'skills',
            'assigned_skill_keys' => ['hello-world'],
            'agent_defaults' => [
                'default_skill_ids' => 'booking-skill',
            ],
        ])->assertRedirect(route('admin.tenants.show', ['tenant' => $tenant, 'tab' => 'skills']));

        $customization = TenantAgentCustomization::query()->firstOrFail();

        $this->assertSame('Keep this prompt override', data_get($customization->prompt_overrides_json, 'identity.content'));
        $this->assertSame('gpt-4.1', data_get($customization->agent_defaults_json, 'model'));
        $this->assertSame(['hello-world'], $tenant->skillAssignments()->where('is_enabled', true)->pluck('skill_key')->all());
        $this->assertSame(['booking-skill'], data_get($customization->agent_defaults_json, 'default_skill_ids'));
    }

    public function test_saving_runtime_tab_preserves_existing_skill_customization_fields(): void
    {
        [$admin, $tenant] = $this->seedAdminAndTenant();

        TenantAgentCustomization::query()->create([
            'tenant_id' => $tenant->id,
            'prompt_overrides_json' => [],
            'agent_defaults_json' => [
                'model' => 'gpt-4.1',
                'default_skill_ids' => ['booking-skill'],
            ],
            'draft_version' => 1,
            'draft_updated_by' => $admin->id,
            'draft_updated_at' => now(),
        ]);

        TenantSkillAssignment::query()->create([
            'tenant_id' => $tenant->id,
            'skill_catalog_version_id' => SkillCatalogVersion::query()->where('skill_key', 'hello-world')->value('id'),
            'skill_key' => 'hello-world',
            'assigned_by' => $admin->id,
            'assigned_at' => now(),
            'is_enabled' => true,
        ]);

        $this->actingAs($admin);

        $this->patch(route('admin.tenants.agent-customization.update', $tenant), [
            'return_tab' => 'agent-runtime',
            'prompt_overrides' => [
                'identity' => [
                    'mode' => 'append',
                    'content' => 'Runtime-only update',
                ],
            ],
            'agent_defaults' => [
                'model' => 'gpt-4o',
            ],
        ])->assertRedirect(route('admin.tenants.show', ['tenant' => $tenant, 'tab' => 'agent-runtime']));

        $customization = TenantAgentCustomization::query()->firstOrFail();

        $this->assertSame(['hello-world'], $tenant->skillAssignments()->where('is_enabled', true)->pluck('skill_key')->all());
        $this->assertSame(['booking-skill'], data_get($customization->agent_defaults_json, 'default_skill_ids'));
        $this->assertSame('gpt-4o', data_get($customization->agent_defaults_json, 'model'));
        $this->assertSame('Runtime-only update', data_get($customization->prompt_overrides_json, 'identity.content'));
    }

    public function test_reenabling_disabled_skill_uses_latest_published_version(): void
    {
        [$admin, $tenant] = $this->seedAdminAndTenant();

        $oldVersion = SkillCatalogVersion::query()->where('skill_key', 'hello-world')->firstOrFail();
        $newVersion = $this->createSkillCatalogVersion('hello-world', '1.1.0');
        app(\App\Services\SkillCatalogService::class)->publishVersion($newVersion);

        TenantSkillAssignment::query()->create([
            'tenant_id' => $tenant->id,
            'skill_catalog_version_id' => $oldVersion->id,
            'skill_key' => 'hello-world',
            'assigned_by' => $admin->id,
            'assigned_at' => now()->subDay(),
            'is_enabled' => false,
        ]);

        $this->actingAs($admin)
            ->patch(route('admin.tenants.agent-customization.update', $tenant), [
                'return_tab' => 'skills',
                'assigned_skill_keys' => ['hello-world'],
                'agent_defaults' => [
                    'default_skill_ids' => '',
                ],
            ])
            ->assertRedirect(route('admin.tenants.show', ['tenant' => $tenant, 'tab' => 'skills']))
            ->assertSessionHas('status', 'Saved tenant skill draft. Runtime has not changed yet.');

        $assignment = TenantSkillAssignment::query()
            ->where('tenant_id', $tenant->id)
            ->where('skill_key', 'hello-world')
            ->firstOrFail();

        $this->assertTrue((bool) $assignment->is_enabled);
        $this->assertSame($newVersion->id, $assignment->skill_catalog_version_id);
    }

    public function test_tenant_skills_tab_shows_assigned_and_latest_versions_when_update_available(): void
    {
        [$admin, $tenant] = $this->seedAdminAndTenant();

        $oldVersion = SkillCatalogVersion::query()->where('skill_key', 'hello-world')->firstOrFail();
        $newVersion = $this->createSkillCatalogVersion('hello-world', '1.1.0');
        app(\App\Services\SkillCatalogService::class)->publishVersion($newVersion);

        TenantSkillAssignment::query()->create([
            'tenant_id' => $tenant->id,
            'skill_catalog_version_id' => $oldVersion->id,
            'skill_key' => 'hello-world',
            'assigned_by' => $admin->id,
            'assigned_at' => now(),
            'is_enabled' => true,
            'last_apply_status' => 'applied',
            'last_applied_at' => now(),
        ]);

        $this->actingAs($admin)
            ->get(route('admin.tenants.show', ['tenant' => $tenant, 'tab' => 'skills']))
            ->assertOk()
            ->assertSee('Assigned Skill Versions')
            ->assertSee('assigned 1.0.5')
            ->assertSee('latest 1.1.0')
            ->assertSee('Update available')
            ->assertSee('Manage Rollout');
    }

    public function test_tenant_skills_progress_endpoint_reports_latest_apply_job_status(): void
    {
        [$admin, $tenant] = $this->seedAdminAndTenant();

        ProvisioningJob::query()->create([
            'tenant_id' => $tenant->id,
            'job_type' => ApplyTenantAgentCustomization::JOB_TYPE,
            'status' => ProvisioningJobStatus::Queued,
            'payload_json' => [
                'action' => TenantAgentCustomizationApply::ACTION_APPLY,
                'source' => 'tenant_apply',
            ],
        ]);

        $this->actingAs($admin)
            ->getJson(route('admin.tenants.skills.progress', $tenant))
            ->assertOk()
            ->assertJsonPath('tenant_id', $tenant->id)
            ->assertJsonPath('should_poll', true)
            ->assertJsonPath('job.status', ProvisioningJobStatus::Queued->value)
            ->assertJsonPath('job.action', TenantAgentCustomizationApply::ACTION_APPLY);
    }

    public function test_tenant_skills_progress_endpoint_reports_auto_resync_job_status(): void
    {
        [$admin, $tenant] = $this->seedAdminAndTenant();

        ProvisioningJob::query()->create([
            'tenant_id' => $tenant->id,
            'job_type' => ApplyTenantAgentCustomization::JOB_TYPE,
            'status' => ProvisioningJobStatus::Completed,
            'payload_json' => [
                'action' => TenantAgentCustomizationApply::ACTION_APPLY,
                'source' => 'skill_rollout',
            ],
            'completed_at' => now(),
        ]);

        ProvisioningJob::query()->create([
            'tenant_id' => $tenant->id,
            'job_type' => ResyncLiveTenantWorkspaceAfterSkillRollout::JOB_TYPE,
            'status' => ProvisioningJobStatus::Queued,
            'payload_json' => [
                'source' => 'skill_rollout_auto_resync',
            ],
        ]);

        $this->actingAs($admin)
            ->getJson(route('admin.tenants.skills.progress', $tenant))
            ->assertOk()
            ->assertJsonPath('tenant_id', $tenant->id)
            ->assertJsonPath('should_poll', true)
            ->assertJsonPath('auto_resync_job.status', ProvisioningJobStatus::Queued->value);
    }

    public function test_admin_tenant_page_defaults_to_overview_and_falls_back_for_invalid_tab(): void
    {
        [$admin, $tenant] = $this->seedAdminAndTenant();

        $this->actingAs($admin);

        $this->get(route('admin.tenants.show', $tenant))
            ->assertOk()
            ->assertSee('Tenant Summary')
            ->assertDontSee('Profile &amp; Channel', false)
            ->assertSee('data-active-tab="overview"', false);

        $this->get(route('admin.tenants.show', ['tenant' => $tenant, 'tab' => 'not-real']))
            ->assertOk()
            ->assertSee('Tenant Summary')
            ->assertSee('data-active-tab="overview"', false);
    }

    public function test_admin_tenant_page_renders_requested_tab_only(): void
    {
        [$admin, $tenant] = $this->seedAdminAndTenant();

        $this->actingAs($admin);

        $this->get(route('admin.tenants.show', ['tenant' => $tenant, 'tab' => 'google']))
            ->assertOk()
            ->assertSee('Google Workspace Connection')
            ->assertSee('data-active-tab="google"', false)
            ->assertSee('aria-current="page"', false)
            ->assertDontSee('Agent Runtime Customization')
            ->assertDontSee('Tenant Skills')
            ->assertDontSee('Permanent Delete');

        $this->get(route('admin.tenants.show', ['tenant' => $tenant, 'tab' => 'skills']))
            ->assertOk()
            ->assertSee('Tenant Skills')
            ->assertSee('Assignment Workflow')
            ->assertSee('Assigned Skill Versions')
            ->assertSee('Available Skills')
            ->assertSee('Advanced Agent Mapping')
            ->assertSee('Version upgrades')
            ->assertSee('Runtime Apply Progress')
            ->assertSee('Runtime Available Skills')
            ->assertSee('Refresh Runtime Skills')
            ->assertSee('data-skill-catalog-layout="full-width"', false)
            ->assertSee('no skills assigned')
            ->assertSee('none assigned')
            ->assertSee('data-active-tab="skills"', false)
            ->assertDontSee('draft only')
            ->assertDontSee('draft v0')
            ->assertDontSee('Current IDENTITY.md')
            ->assertDontSee('Google Workspace Connection')
            ->assertDontSee('Permanent Delete');

        $this->assertSame(
            1,
            substr_count(
                $this->get(route('admin.tenants.show', ['tenant' => $tenant, 'tab' => 'skills']))->getContent(),
                'no skills assigned'
            )
        );

        $this->get(route('admin.tenants.show', ['tenant' => $tenant, 'tab' => 'agent-runtime']))
            ->assertOk()
            ->assertSee('Agent Runtime')
            ->assertSee('Current IDENTITY.md')
            ->assertSee('Current AGENTS.md')
            ->assertSee('data-active-tab="agent-runtime"', false)
            ->assertDontSee('Skill Packs')
            ->assertDontSee('Default Skill IDs')
            ->assertDontSee('Google Workspace Connection')
            ->assertDontSee('Permanent Delete');

        TenantInboxMonitorState::query()->create([
            'tenant_id' => $tenant->id,
            'enabled' => true,
            'status' => TenantInboxMonitorState::STATUS_FAILED,
            'last_checked_at' => now()->subMinutes(12),
            'last_failed_at' => now()->subMinutes(5),
            'last_error' => 'gog gmail search failed',
            'backoff_until' => now()->addMinutes(9),
            'consecutive_failures' => 3,
        ]);
        TenantInboxMonitorMessage::query()->create([
            'tenant_id' => $tenant->id,
            'gmail_message_id' => 'gmail-msg-1',
            'gmail_thread_id' => 'gmail-thread-1',
            'sender_domain' => 'example.com',
            'subject_preview' => 'Need service area details',
            'subject_hash' => hash('sha256', 'Need service area details'),
            'status' => TenantInboxMonitorMessage::STATUS_FAILED,
            'attempts' => 2,
            'detected_at' => now()->subMinutes(10),
            'last_attempted_at' => now()->subMinutes(5),
            'last_error' => 'gateway timeout',
        ]);

        $this->get(route('admin.tenants.show', ['tenant' => $tenant, 'tab' => 'inbox-monitor']))
            ->assertOk()
            ->assertSee('Inbox Monitor')
            ->assertSee('Monitor State')
            ->assertSee('Recent Inbox Events')
            ->assertSee('gog gmail search failed')
            ->assertSee('Need service area details')
            ->assertSee('data-active-tab="inbox-monitor"', false)
            ->assertDontSee('Agent Runtime Customization')
            ->assertDontSee('Permanent Delete');
    }

    public function test_admin_google_and_inbox_tabs_show_dependency_health_metadata(): void
    {
        config()->set('services.google.oauth_app_mode', 'testing');

        [$admin, $tenant] = $this->seedAdminAndTenant();

        $tenant->googleCredential()->create([
            'status' => TenantGoogleCredential::STATUS_CONNECTED,
            'runtime_sync_status' => TenantGoogleCredential::RUNTIME_SYNC_VERIFIED,
            'refresh_token' => 'refresh-token',
            'google_email' => 'owner@example.com',
            'connected_at' => now()->subDays(6)->subHours(2),
            'last_verified_at' => now()->subMinutes(15),
            'health_status' => TenantGoogleCredential::HEALTH_EXPIRING_SOON,
            'health_checked_at' => now()->subMinutes(10),
            'predicted_testing_expiry_at' => now()->addHours(22),
            'incident_alert_sent_at' => now()->subHour(),
            'incident_alert_reason' => 'google_expiring_soon',
        ]);

        TenantInboxMonitorState::query()->create([
            'tenant_id' => $tenant->id,
            'skill_key' => 'inbox-triage',
            'is_enabled' => true,
            'health_status' => TenantInboxMonitorState::HEALTH_DOWN,
            'last_checked_at' => now()->subHours(2),
            'health_checked_at' => now()->subMinutes(10),
            'incident_alert_sent_at' => now()->subMinutes(45),
            'incident_alert_reason' => 'google_auth_failure',
            'last_error' => 'invalid_grant: Token has been expired or revoked.',
        ]);

        $this->actingAs($admin);

        $this->get(route('admin.tenants.show', ['tenant' => $tenant, 'tab' => 'google']))
            ->assertOk()
            ->assertSee('Predicted Expiry')
            ->assertSee('Health')
            ->assertSee('Last Health Check')
            ->assertSee('Last Verified');

        $this->get(route('admin.tenants.show', ['tenant' => $tenant, 'tab' => 'inbox-monitor']))
            ->assertOk()
            ->assertSee('Inbox Monitor')
            ->assertSee('Google health')
            ->assertSee('Last customer alert')
            ->assertSee('Customer alert reason: google_auth_failure')
            ->assertSee('invalid_grant: Token has been expired or revoked.');
    }

    public function test_admin_can_refresh_runtime_available_skills_from_skills_tab(): void
    {
        [$admin, $tenant] = $this->seedAdminAndTenant();

        $this->mock(TenantRuntimeSkillDiscoveryService::class, function ($mock) use ($tenant): void {
            $mock->shouldReceive('inspect')
                ->once()
                ->withArgs(fn (Tenant $candidate): bool => $candidate->is($tenant))
                ->andReturn([
                    'workspace_state' => 'running',
                    'refreshed_at' => '2026-04-18 20:05:00',
                    'skills' => ['hello-world', 'gog'],
                    'raw_output' => "hello-world\ngog",
                ]);
        });

        $this->actingAs($admin);

        $response = $this->post(route('admin.tenants.skills.runtime-refresh', $tenant), [
            'return_tab' => 'skills',
        ]);

        $response->assertRedirect(route('admin.tenants.show', ['tenant' => $tenant, 'tab' => 'skills']));

        $this->followRedirects($response)
            ->assertOk()
            ->assertSee('Runtime skills refreshed.')
            ->assertSee('Runtime Available Skills')
            ->assertSee('hello-world')
            ->assertSee('gog')
            ->assertSee('Workspace state')
            ->assertSee('running')
            ->assertSee('Raw command output');
    }

    public function test_admin_tenant_page_uses_compact_sidebar_navigation(): void
    {
        [$admin, $tenant] = $this->seedAdminAndTenant();

        $this->actingAs($admin);

        $this->get(route('admin.tenants.show', $tenant))
            ->assertOk()
            ->assertSee('Overview')
            ->assertSee('Workspace')
            ->assertSee('Google')
            ->assertSee('Skills')
            ->assertSee('Inbox Monitor')
            ->assertSee('Agent Runtime')
            ->assertSee('Support')
            ->assertDontSee('Status, identifiers, and the latest job snapshot.')
            ->assertDontSee('Profile, channel, runtime metadata, and provisioning context.');
    }

    public function test_admin_tenant_page_uses_labeled_status_badges(): void
    {
        [$admin, $tenant] = $this->seedAdminAndTenant();

        $this->actingAs($admin);

        $this->get(route('admin.tenants.show', $tenant))
            ->assertOk()
            ->assertSee('Provisioning')
            ->assertSee('ready')
            ->assertSee('Agent')
            ->assertSee('live')
            ->assertSee('Health')
            ->assertSee('unchecked')
            ->assertSee('Workspace')
            ->assertSee('Google');
    }

    public function test_tenant_actions_redirect_back_to_active_tab(): void
    {
        Queue::fake([ApplyTenantAgentCustomization::class]);
        [$admin, $tenant] = $this->seedAdminAndTenant();

        $this->mock(TenantHealthCheckService::class, function ($mock): void {
            $mock->shouldReceive('check')
                ->once()
                ->andReturn(['message' => 'Health check passed.']);
        });

        $this->actingAs($admin);

        $this->patch(route('admin.tenants.agent-customization.update', $tenant), [
            'return_tab' => 'skills',
            'assigned_skill_keys' => ['hello-world'],
            'agent_defaults' => [
                'default_skill_ids' => 'custom-default-skill',
            ],
        ])->assertRedirect(route('admin.tenants.show', ['tenant' => $tenant, 'tab' => 'skills']));

        $this->patch(route('admin.tenants.agent-customization.update', $tenant), [
            'return_tab' => 'agent-runtime',
            'prompt_overrides' => [
                'identity' => [
                    'mode' => 'append',
                    'content' => 'Custom admin identity guidance',
                ],
            ],
            'agent_defaults' => [
                'model' => 'gpt-4.1',
            ],
        ])->assertRedirect(route('admin.tenants.show', ['tenant' => $tenant, 'tab' => 'agent-runtime']));

        $this->post(route('admin.tenants.google.sync', $tenant), [
            'return_tab' => 'google',
        ])->assertRedirect(route('admin.tenants.show', ['tenant' => $tenant, 'tab' => 'google']));

        $this->post(route('admin.tenants.health-check', $tenant), [
            'return_tab' => 'support',
        ])->assertRedirect(route('admin.tenants.show', ['tenant' => $tenant, 'tab' => 'support']));

        $this->post(route('admin.tenants.agent-customization.apply', $tenant), [
            'return_tab' => 'skills',
        ])->assertRedirect(route('admin.tenants.show', ['tenant' => $tenant, 'tab' => 'skills']));
    }

    public function test_admin_tenant_page_handles_missing_agent_customization_tables_gracefully(): void
    {
        [$admin, $tenant] = $this->seedAdminAndTenant();

        Schema::dropIfExists('tenant_agent_customization_applies');
        Schema::dropIfExists('tenant_agent_customizations');

        $this->actingAs($admin);

        $this->get(route('admin.tenants.show', ['tenant' => $tenant, 'tab' => 'agent-runtime']))
            ->assertOk()
            ->assertSee('Agent runtime customization is unavailable')
            ->assertDontSee('SQLSTATE');
    }

    public function test_admin_tenant_page_handles_missing_skill_catalog_tables_gracefully(): void
    {
        [$admin, $tenant] = $this->seedAdminAndTenant();

        Schema::dropIfExists('tenant_skill_assignments');
        Schema::dropIfExists('skill_catalog_versions');
        Schema::dropIfExists('skill_catalog_items');

        $this->actingAs($admin);

        $this->get(route('admin.tenants.show', ['tenant' => $tenant, 'tab' => 'skills']))
            ->assertOk()
            ->assertSee('Tenant skills are unavailable')
            ->assertDontSee('SQLSTATE');

        $this->get(route('admin.tenants.show', ['tenant' => $tenant, 'tab' => 'agent-runtime']))
            ->assertOk()
            ->assertSee('Agent runtime customization is unavailable')
            ->assertDontSee('SQLSTATE');
    }

    private function seedAdminAndTenant(string $email = 'allowed@example.com'): array
    {
        $admin = User::query()->create([
            'name' => 'Debug Admin',
            'email' => $email,
            'password' => 'super-secret',
            'is_admin' => true,
        ]);

        $user = User::query()->create([
            'name' => 'Customer User',
            'email' => 'customer@example.com',
            'password' => 'super-secret',
            'is_admin' => false,
        ]);

        $tenant = Tenant::query()->create([
            'tenant_id' => 'tenant_customization_02',
            'slug' => 'customization-shop',
            'business_name' => 'Customization Shop',
            'industry' => 'Retail',
            'skill_pack' => 'Client Support',
            'user_id' => $user->id,
            'server_id' => 1,
            'trial_status' => TrialStatus::Active,
            'provisioning_status' => TenantProvisioningStatus::Ready,
            'agent_status' => 'live',
            'workspace_url' => 'https://customization-shop.workspace.test',
            'runtime_path' => '/srv/sync360/runtime/tenants/customization-shop',
        ]);

        BusinessProfile::query()->create([
            'tenant_id' => $tenant->id,
            'business_name' => 'Customization Shop',
            'industry' => 'Retail',
            'description' => 'Helpful team.',
            'services' => ['Customer support'],
        ]);

        BusinessProfileFiles::query()->create([
            'tenant_id' => $tenant->id,
            'identity_markdown' => "# Identity\n\nBase identity",
            'soul_markdown' => "# Soul\n\nBase soul",
            'user_markdown' => "# User\n\nBase user",
            'bootstrap_markdown' => "# Bootstrap\n\nBase bootstrap",
            'generated_at' => now(),
        ]);

        Artisan::call('sync360:skills:import');
        $version = SkillCatalogVersion::query()->where('skill_key', 'hello-world')->first();
        $version?->forceFill(['is_active_published' => true])->save();

        $runtimeRoot = config('sync360.runtime_root').'/'.$tenant->slug;
        File::ensureDirectoryExists($runtimeRoot.'/config');
        File::put($runtimeRoot.'/config/openclaw.json', json_encode([
            'agents' => [
                'defaults' => [
                    'model' => 'gpt-4o',
                    'skills' => [],
                ],
            ],
            'skills' => [
                'entries' => [],
            ],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL);

        return [$admin, $tenant];
    }

    private function createSkillCatalogVersion(string $skillKey, string $version): SkillCatalogVersion
    {
        $baseVersion = SkillCatalogVersion::query()
            ->where('skill_key', $skillKey)
            ->firstOrFail();
        $manifest = $baseVersion->manifest_json;
        $manifest['version'] = $version;

        return SkillCatalogVersion::query()->create([
            'skill_catalog_item_id' => $baseVersion->skill_catalog_item_id,
            'skill_key' => $skillKey,
            'version' => $version,
            'manifest_json' => $manifest,
            'is_active_published' => false,
            'is_archived' => false,
            'is_available' => true,
            'discovered_at' => now(),
            'last_imported_at' => now(),
        ]);
    }
}
