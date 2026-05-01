<?php

namespace Tests\Unit;

use App\Enums\TenantProvisioningStatus;
use App\Enums\TrialStatus;
use App\Models\BusinessProfile;
use App\Models\BusinessProfileFiles;
use App\Models\TenantGoogleCredential;
use App\Models\SkillCatalogVersion;
use App\Models\Tenant;
use App\Models\TenantAgentCustomization;
use App\Models\TenantSkillAssignment;
use App\Models\TenantWorkspaceContentItem;
use App\Models\User;
use App\Services\TenantRuntimeCustomizationComposer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class TenantRuntimeCustomizationComposerTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_composes_runtime_output_with_prompt_overrides_and_canonical_skill_shape(): void
    {
        $tenant = $this->seedTenant();
        $this->artisan('sync360:skills:import')->assertExitCode(0);
        $helloWorldVersion = SkillCatalogVersion::query()->where('skill_key', 'hello-world')->firstOrFail();

        BusinessProfile::query()->create([
            'tenant_id' => $tenant->id,
            'business_name' => 'Acme Plumbing',
            'industry' => 'Home Services',
            'description' => 'Fast local plumbing support.',
            'services' => ['Emergency plumbing'],
        ]);

        BusinessProfileFiles::query()->create([
            'tenant_id' => $tenant->id,
            'identity_markdown' => "# Identity\n\nBase identity",
            'soul_markdown' => "# Soul\n\nBase soul",
            'user_markdown' => "# User\n\nBase user",
            'bootstrap_markdown' => "# Bootstrap\n\nBase bootstrap",
            'generated_at' => now(),
        ]);

        TenantAgentCustomization::query()->create([
            'tenant_id' => $tenant->id,
            'prompt_overrides_json' => [
                'identity' => [
                    'mode' => 'append',
                    'content' => "Admin identity notes",
                    'base_snapshot' => "# Identity\n\nBase identity",
                ],
                'bootstrap' => [
                    'mode' => 'replace',
                    'content' => "# Bootstrap\n\nReplacement bootstrap",
                    'base_snapshot' => "# Bootstrap\n\nBase bootstrap",
                ],
            ],
            'agent_defaults_json' => [
                'model' => 'gpt-4.1',
                'default_skill_ids' => ['custom-default-skill'],
            ],
            'draft_version' => 1,
            'draft_updated_by' => $tenant->user_id,
            'draft_updated_at' => now(),
        ]);

        File::ensureDirectoryExists(dirname($this->runtimeConfigPath($tenant)));
        File::put($this->runtimeConfigPath($tenant), json_encode([
            'agents' => [
                'defaults' => [
                    'model' => 'gpt-4o',
                    'skills' => ['existing-skill'],
                ],
                'list' => [
                    [
                        'name' => 'assistant',
                        'skills' => ['tenant-existing-skill'],
                    ],
                ],
            ],
            'skills' => [
                'entries' => [
                    'existing-skill' => ['enabled' => true],
                ],
            ],
            'gateway' => [
                'auth' => [
                    'token' => 'keep-me',
                ],
            ],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL);

        TenantSkillAssignment::query()->create([
            'tenant_id' => $tenant->id,
            'skill_catalog_version_id' => $helloWorldVersion->id,
            'skill_key' => 'hello-world',
            'assigned_by' => $tenant->user_id,
            'assigned_at' => now(),
            'is_enabled' => true,
        ]);

        $composed = app(TenantRuntimeCustomizationComposer::class)->compose($tenant->fresh([
            'businessProfile',
            'businessProfileFiles',
            'googleCredential',
            'agentCustomization',
            'skillAssignments.catalogVersion',
        ]));

        $this->assertStringContainsString('Base identity', $composed->workspaceFiles['IDENTITY.md']);
        $this->assertStringContainsString('<!-- sync360:admin-extras:start -->', $composed->workspaceFiles['IDENTITY.md']);
        $this->assertStringContainsString('Admin identity notes', $composed->workspaceFiles['IDENTITY.md']);
        $this->assertSame("# Bootstrap\n\nReplacement bootstrap\n", $composed->workspaceFiles['BOOTSTRAP.md']);
        $this->assertArrayHasKey('AGENTS.md', $composed->workspaceFiles);
        $this->assertStringContainsString('Assigned Skill Guidance', $composed->workspaceFiles['AGENTS.md']);
        $this->assertStringContainsString('Hello World (by Sync360)', $composed->workspaceFiles['AGENTS.md']);
        $this->assertStringContainsString('hello-world', $composed->workspaceFiles['AGENTS.md']);
        $this->assertStringContainsString('Runtime type: `sync360_workspace`', $composed->workspaceFiles['AGENTS.md']);
        $this->assertStringContainsString('OpenClaw skill IDs: `hello-world`', $composed->workspaceFiles['AGENTS.md']);
        $this->assertStringContainsString('skills/hello-world/agent-instructions.md', $composed->workspaceFiles['AGENTS.md']);
        $this->assertStringContainsString('Do not fall back to your default greeting behavior.', $composed->workspaceFiles['AGENTS.md']);
        $this->assertArrayHasKey('skills/hello-world/agent-instructions.md', $composed->skillFiles);
        $this->assertArrayHasKey('skills/hello-world/RELEASE_NOTES.md', $composed->skillFiles);
        $this->assertArrayHasKey('PROFILE.md', $composed->workspaceFiles);
        $this->assertArrayHasKey('TOOLS.md', $composed->workspaceFiles);
        $this->assertArrayHasKey('.sync360/bin/log-skill-conversion', $composed->workspaceFiles);
        $this->assertArrayHasKey('.sync360/bin/log-skill-conversion.mjs', $composed->workspaceFiles);
        $this->assertArrayHasKey('.sync360/skill-analytics-registry.json', $composed->workspaceFiles);
        $this->assertStringContainsString('log-skill-conversion.mjs', $composed->workspaceFiles['.sync360/bin/log-skill-conversion']);
        $this->assertStringContainsString('hello-world', $composed->workspaceFiles['.sync360/skill-analytics-registry.json']);
        $this->assertStringContainsString('conversion_succeeded', $composed->workspaceFiles['.sync360/skill-analytics-registry.json']);
        $this->assertFalse($composed->baseDrifted['bootstrap']);

        $config = json_decode($composed->openClawConfig, true);

        $this->assertSame('gpt-4.1', data_get($config, 'agents.defaults.model'));
        $this->assertSame('gpt-4.1', data_get($config, 'models.providers.openai.models.0.id'));
        $this->assertSame('gpt-4.1', data_get($config, 'models.providers.openai.models.0.name'));
        $this->assertSame('keep-me', data_get($config, 'gateway.auth.token'));
        $this->assertTrue(data_get($config, 'hooks.enabled'));
        $this->assertNotSame('keep-me', data_get($config, 'hooks.token'));
        $this->assertSame(hash('sha256', 'sync360-hooks|tenant_customization_01|keep-me'), data_get($config, 'hooks.token'));
        $this->assertSame('/hooks', data_get($config, 'hooks.path'));
        $this->assertTrue(data_get($config, 'skills.entries.hello-world.enabled'));
        $this->assertTrue(data_get($config, 'skills.entries.custom-default-skill.enabled'));
        $this->assertEqualsCanonicalizing(
            ['existing-skill', 'hello-world', 'custom-default-skill', 'gog'],
            data_get($config, 'agents.defaults.skills')
        );
        $this->assertEqualsCanonicalizing(
            ['tenant-existing-skill', 'hello-world', 'custom-default-skill', 'gog'],
            data_get($config, 'agents.list.0.skills')
        );
        $this->assertNotSame('', $composed->contentHash);
    }

    public function test_it_includes_verified_gmail_reply_guidance_when_google_workspace_is_connected(): void
    {
        $tenant = $this->seedTenant();
        $this->artisan('sync360:skills:import', ['--skill' => 'inbox-triage'])->assertExitCode(0);
        $version = SkillCatalogVersion::query()->where('skill_key', 'inbox-triage')->firstOrFail();

        BusinessProfile::query()->create([
            'tenant_id' => $tenant->id,
            'business_name' => 'Acme Plumbing',
            'industry' => 'Home Services',
            'description' => 'Fast local plumbing support.',
            'services' => ['Emergency plumbing'],
        ]);

        BusinessProfileFiles::query()->create([
            'tenant_id' => $tenant->id,
            'identity_markdown' => "# Identity\n\nBase identity",
            'soul_markdown' => "# Soul\n\nBase soul",
            'user_markdown' => "# User\n\nBase user",
            'bootstrap_markdown' => "# Bootstrap\n\nBase bootstrap",
            'generated_at' => now(),
        ]);

        TenantAgentCustomization::query()->create([
            'tenant_id' => $tenant->id,
            'prompt_overrides_json' => [],
            'agent_defaults_json' => [],
            'draft_version' => 1,
            'draft_updated_by' => $tenant->user_id,
            'draft_updated_at' => now(),
        ]);

        TenantGoogleCredential::query()->create([
            'tenant_id' => $tenant->id,
            'google_email' => 'owner@example.com',
            'scopes' => ['gmail.readonly'],
            'status' => TenantGoogleCredential::STATUS_CONNECTED,
            'refresh_token' => 'refresh-token',
            'connected_at' => now(),
            'runtime_sync_status' => TenantGoogleCredential::RUNTIME_SYNC_VERIFIED,
        ]);

        TenantSkillAssignment::query()->create([
            'tenant_id' => $tenant->id,
            'skill_catalog_version_id' => $version->id,
            'skill_key' => 'inbox-triage',
            'assigned_by' => $tenant->user_id,
            'assigned_at' => now(),
            'is_enabled' => true,
        ]);

        File::ensureDirectoryExists(dirname($this->runtimeConfigPath($tenant)));
        File::put($this->runtimeConfigPath($tenant), json_encode(['agents' => ['defaults' => []]], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL);

        $composed = app(TenantRuntimeCustomizationComposer::class)->compose($tenant->fresh([
            'businessProfile',
            'businessProfileFiles',
            'googleCredential',
            'agentCustomization',
            'skillAssignments.catalogVersion.item',
        ]));

        $this->assertStringContainsString('Inbox Triage must execute exactly one Gmail send action for a low-risk basic support or business-information enquiry when that branch applies', $composed->workspaceFiles['HEARTBEAT.md']);
        $this->assertStringContainsString('Do not claim that Inbox Triage will email a clarifying question or follow-up later unless `gog gmail send` or `gog gmail drafts create` already succeeded in the current run.', $composed->workspaceFiles['HEARTBEAT.md']);
        $this->assertStringContainsString('Inbox Triage auto-replies must follow the tenant tone chosen during onboarding, stay concise, and use plain ASCII body text without literal escape sequences such as `\\n`, `\\r`, or `\\t`.', $composed->workspaceFiles['HEARTBEAT.md']);
        $this->assertStringContainsString('Questions about services, opening hours, location coverage, or simple documented support do not qualify for `High-Value Lead Detected` unless the same message also shows clear commercial buying intent.', $composed->workspaceFiles['HEARTBEAT.md']);
        $this->assertStringContainsString('Verified Gmail direct-reply surface: `gog gmail send --reply-to-message-id <gmail_message_id> --subject "<subject>" --body "<plain-text-body>"`.', $composed->workspaceFiles['TOOLS.md']);
        $this->assertStringContainsString('do not combine `--reply-to-message-id` with `--thread-id` in the standard direct reply flow', strtolower($composed->workspaceFiles['TOOLS.md']));
        $this->assertStringContainsString('Verified Gmail draft surface: `gog gmail drafts create --reply-to-message-id <gmail_message_id> --subject "<subject>" --body "<plain-text-body>"` creates a reply draft without sending it.', $composed->workspaceFiles['TOOLS.md']);
    }

    public function test_skill_pack_guidance_uses_pdf_generation_action_and_explains_template_control_blocks(): void
    {
        $tenant = $this->seedTenant();
        $this->artisan('sync360:skills:import', ['--skill' => 'inbox-triage'])->assertExitCode(0);
        $this->artisan('sync360:skills:import', ['--skill' => 'pdf-generation'])->assertExitCode(0);
        $inboxVersion = SkillCatalogVersion::query()->where('skill_key', 'inbox-triage')->firstOrFail();
        $pdfVersion = SkillCatalogVersion::query()->where('skill_key', 'pdf-generation')->firstOrFail();

        BusinessProfile::query()->create([
            'tenant_id' => $tenant->id,
            'business_name' => 'Acme Plumbing',
            'industry' => 'Home Services',
            'description' => 'Fast local plumbing support.',
        ]);

        BusinessProfileFiles::query()->create([
            'tenant_id' => $tenant->id,
            'identity_markdown' => "# Identity\n\nBase identity",
            'soul_markdown' => "# Soul\n\nBase soul",
            'user_markdown' => "# User\n\nBase user",
            'bootstrap_markdown' => "# Bootstrap\n\nBase bootstrap",
            'generated_at' => now(),
        ]);

        TenantSkillAssignment::query()->create([
            'tenant_id' => $tenant->id,
            'skill_catalog_version_id' => $inboxVersion->id,
            'skill_key' => 'inbox-triage',
            'assigned_by' => $tenant->user_id,
            'assigned_at' => now(),
            'is_enabled' => true,
        ]);

        TenantSkillAssignment::query()->create([
            'tenant_id' => $tenant->id,
            'skill_catalog_version_id' => $pdfVersion->id,
            'skill_key' => 'pdf-generation',
            'assigned_by' => $tenant->user_id,
            'assigned_at' => now(),
            'is_enabled' => true,
        ]);

        File::ensureDirectoryExists(dirname($this->runtimeConfigPath($tenant)));
        File::put($this->runtimeConfigPath($tenant), json_encode(['agents' => ['defaults' => []]], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL);

        $composed = app(TenantRuntimeCustomizationComposer::class)->compose($tenant->fresh([
            'businessProfile',
            'businessProfileFiles',
            'googleCredential',
            'agentCustomization',
            'skillAssignments.catalogVersion',
        ]));

        $this->assertStringContainsString('"suggested_action":"pdf-generation"', $composed->skillFiles['skills/inbox-triage/SKILL.md']);
        $this->assertStringContainsString('"suggested_action": "<pdf-generation|google-calendar-booking|human-review|follow-up>"', $composed->skillFiles['skills/inbox-triage/SKILL.md']);
        $this->assertStringContainsString('owner-provided prices or line items are inputs to **pdf-generation**, not permission to send a plain-text Gmail quote reply directly from inbox-triage', $composed->skillFiles['skills/inbox-triage/SKILL.md']);
        $this->assertStringContainsString('Do not treat them as permission to skip PDF generation and send a plain-text quote email first.', $composed->skillFiles['skills/pdf-generation/SKILL.md']);
        $this->assertStringContainsString('Render the template control blocks yourself before calling aPDF.io.', $composed->skillFiles['skills/pdf-generation/SKILL.md']);
        $this->assertStringContainsString('`{{#if field}}...{{/if}}` includes the enclosed HTML only when the field has a non-empty value', $composed->skillFiles['skills/pdf-generation/SKILL.md']);
        $this->assertStringContainsString('`{{#each line_items}}...{{/each}}` repeats the enclosed row once per item', $composed->skillFiles['skills/pdf-generation/SKILL.md']);
        $this->assertStringContainsString('The final HTML sent to aPDF.io must not contain raw `{{` template tags.', $composed->skillFiles['skills/pdf-generation/SKILL.md']);
    }

    public function test_it_emits_workspace_content_index_and_knowledge_files(): void
    {
        $tenant = $this->seedTenant();

        BusinessProfile::query()->create([
            'tenant_id' => $tenant->id,
            'business_name' => 'Acme Plumbing',
            'trading_name' => 'Acme',
            'industry' => 'Home Services',
            'description' => 'Fast local plumbing support.',
            'tax_number' => 'GST-123',
            'services' => ['Emergency plumbing'],
        ]);

        BusinessProfileFiles::query()->create([
            'tenant_id' => $tenant->id,
            'identity_markdown' => "# Identity\n\nBase identity",
            'soul_markdown' => "# Soul\n\nBase soul",
            'user_markdown' => "# User\n\nBase user",
            'bootstrap_markdown' => "# Bootstrap\n\nBase bootstrap",
            'generated_at' => now(),
        ]);

        TenantWorkspaceContentItem::query()->create([
            'tenant_id' => $tenant->id,
            'source_type' => TenantWorkspaceContentItem::SOURCE_TYPE_TEXT_BLOCK,
            'slug' => 'pricing-guidance',
            'title' => 'Pricing guidance',
            'status' => TenantWorkspaceContentItem::STATUS_ACTIVE,
            'summary' => 'Pinned pricing notes for callouts.',
            'content_markdown' => "# Pricing guidance\n\nCallouts start from $120.",
            'workspace_path' => 'knowledge/text/pricing-guidance.md',
            'source_hash' => hash('sha256', 'pricing'),
            'last_imported_at' => now(),
            'last_published_at' => now(),
        ]);

        TenantWorkspaceContentItem::query()->create([
            'tenant_id' => $tenant->id,
            'source_type' => TenantWorkspaceContentItem::SOURCE_TYPE_DOCUMENT,
            'slug' => 'rate-sheet',
            'title' => 'Rate sheet',
            'status' => TenantWorkspaceContentItem::STATUS_ACTIVE,
            'summary' => 'Imported rate sheet.',
            'content_markdown' => "# Rate sheet\n\n| Service | Price |\n| --- | --- |\n| Callout | 120 |",
            'content_json' => [
                'columns' => ['Service', 'Price'],
                'rows' => [['Service' => 'Callout', 'Price' => '120']],
            ],
            'workspace_path' => 'knowledge/documents/rate-sheet.md',
            'structured_data_workspace_path' => 'knowledge/data/rate-sheet.json',
            'source_hash' => hash('sha256', 'rate-sheet'),
            'last_imported_at' => now(),
            'last_published_at' => now(),
        ]);

        File::ensureDirectoryExists(dirname($this->runtimeConfigPath($tenant)));
        File::put($this->runtimeConfigPath($tenant), json_encode(['agents' => ['defaults' => []]], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL);

        $composed = app(TenantRuntimeCustomizationComposer::class)->compose($tenant->fresh([
            'businessProfile',
            'businessProfileFiles',
            'workspaceContentItems',
            'googleCredential',
            'agentCustomization',
            'skillAssignments.catalogVersion.item',
        ]));

        $this->assertArrayHasKey('WORKSPACE_CONTENT_INDEX.json', $composed->workspaceFiles);
        $this->assertArrayHasKey('knowledge/README.md', $composed->workspaceFiles);
        $this->assertArrayHasKey('knowledge/text/pricing-guidance.md', $composed->workspaceFiles);
        $this->assertArrayHasKey('knowledge/documents/rate-sheet.md', $composed->workspaceFiles);
        $this->assertArrayHasKey('knowledge/data/rate-sheet.json', $composed->workspaceFiles);
        $this->assertStringContainsString('"slug": "pricing-guidance"', $composed->workspaceFiles['WORKSPACE_CONTENT_INDEX.json']);
        $this->assertStringContainsString('"contains_pricing": true', $composed->workspaceFiles['WORKSPACE_CONTENT_INDEX.json']);
        $this->assertStringContainsString('knowledge/text/pricing-guidance.md', $composed->workspaceFiles['knowledge/README.md']);
        $this->assertStringContainsString('Callouts start from $120.', $composed->workspaceFiles['knowledge/text/pricing-guidance.md']);
        $this->assertStringContainsString('"rows"', $composed->workspaceFiles['knowledge/data/rate-sheet.json']);
        $this->assertStringContainsString('WORKSPACE_CONTENT_INDEX.json', $composed->workspaceFiles['HEARTBEAT.md']);
        $this->assertStringContainsString('knowledge/*', $composed->workspaceFiles['TOOLS.md']);
        $this->assertStringContainsString('## Workspace Content', $composed->workspaceFiles['PROFILE.md']);
    }

    public function test_it_emits_business_profile_json_and_logo_asset_for_custom_skills(): void
    {
        $tenant = $this->seedTenant();
        $this->artisan('sync360:skills:import', ['--skill' => 'pdf-generation'])->assertExitCode(0);
        $pdfVersion = SkillCatalogVersion::query()->where('skill_key', 'pdf-generation')->firstOrFail();
        $logoStoragePath = 'tenant-business-profile-assets/'.$tenant->tenant_id.'/logo.png';
        Storage::disk('local')->delete($logoStoragePath);

        BusinessProfile::query()->create([
            'tenant_id' => $tenant->id,
            'business_name' => 'Acme Plumbing',
            'trading_name' => 'Acme',
            'industry' => 'Home Services',
            'description' => 'Fast local plumbing support.',
            'contact_email' => 'support@acme.test',
            'contact_phone' => '+64 21 555 0101',
            'tax_number' => 'GST-123',
            'services' => ['Emergency plumbing', 'Maintenance'],
            'faqs' => ['Do you do callouts?'],
            'pricing_notes' => 'Quote before work starts.',
        ]);

        Storage::disk('local')->put($logoStoragePath, 'logo-bytes');

        BusinessProfileFiles::query()->create([
            'tenant_id' => $tenant->id,
            'identity_markdown' => "# Identity\n\nBase identity",
            'soul_markdown' => "# Soul\n\nBase soul",
            'user_markdown' => "# User\n\nBase user",
            'bootstrap_markdown' => "# Bootstrap\n\nBase bootstrap",
            'logo_storage_path' => $logoStoragePath,
            'logo_original_filename' => 'acme-logo.png',
            'logo_mime_type' => 'image/png',
            'logo_size_bytes' => 10,
            'logo_uploaded_at' => now(),
            'generated_at' => now(),
        ]);

        TenantSkillAssignment::query()->create([
            'tenant_id' => $tenant->id,
            'skill_catalog_version_id' => $pdfVersion->id,
            'skill_key' => 'pdf-generation',
            'assigned_by' => $tenant->user_id,
            'assigned_at' => now(),
            'is_enabled' => true,
        ]);

        File::ensureDirectoryExists(dirname($this->runtimeConfigPath($tenant)));
        File::put($this->runtimeConfigPath($tenant), json_encode(['agents' => ['defaults' => []]], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL);

        $composed = app(TenantRuntimeCustomizationComposer::class)->compose($tenant->fresh([
            'businessProfile',
            'businessProfileFiles',
            'googleCredential',
            'agentCustomization',
            'skillAssignments.catalogVersion.item',
        ]));

        $this->assertArrayHasKey('BUSINESS_PROFILE.json', $composed->workspaceFiles);
        $this->assertArrayHasKey('business-assets/logo.png', $composed->workspaceFiles);
        $this->assertSame('logo-bytes', $composed->workspaceFiles['business-assets/logo.png']);
        $this->assertStringContainsString('"business_name": "Acme Plumbing"', $composed->workspaceFiles['BUSINESS_PROFILE.json']);
        $this->assertStringContainsString('"trading_name": "Acme"', $composed->workspaceFiles['BUSINESS_PROFILE.json']);
        $this->assertStringContainsString('"tax_number": "GST-123"', $composed->workspaceFiles['BUSINESS_PROFILE.json']);
        $this->assertStringContainsString('"path": "business-assets/logo.png"', $composed->workspaceFiles['BUSINESS_PROFILE.json']);
        $this->assertStringContainsString('"enabled_modules"', $composed->workspaceFiles['BUSINESS_PROFILE.json']);
        $this->assertStringContainsString('- Logo Asset: business-assets/logo.png', $composed->workspaceFiles['PROFILE.md']);
    }

    public function test_it_flags_base_drift_for_replace_overrides(): void
    {
        $tenant = $this->seedTenant();

        BusinessProfile::query()->create([
            'tenant_id' => $tenant->id,
            'business_name' => 'Acme Plumbing',
            'industry' => 'Home Services',
            'description' => 'Fast local plumbing support.',
        ]);

        BusinessProfileFiles::query()->create([
            'tenant_id' => $tenant->id,
            'identity_markdown' => "# Identity\n\nUpdated identity",
            'soul_markdown' => "# Soul\n\nBase soul",
            'user_markdown' => "# User\n\nBase user",
            'bootstrap_markdown' => "# Bootstrap\n\nBase bootstrap",
            'generated_at' => now(),
        ]);

        TenantAgentCustomization::query()->create([
            'tenant_id' => $tenant->id,
            'prompt_overrides_json' => [
                'identity' => [
                    'mode' => 'replace',
                    'content' => "# Identity\n\nReplaced identity",
                    'base_snapshot' => "# Identity\n\nOld identity",
                ],
            ],
            'agent_defaults_json' => [],
            'draft_version' => 1,
            'draft_updated_by' => $tenant->user_id,
            'draft_updated_at' => now(),
        ]);

        File::ensureDirectoryExists(dirname($this->runtimeConfigPath($tenant)));
        File::put($this->runtimeConfigPath($tenant), json_encode(['agents' => ['defaults' => []]], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL);

        $composed = app(TenantRuntimeCustomizationComposer::class)->compose($tenant->fresh([
            'businessProfile',
            'businessProfileFiles',
            'googleCredential',
            'agentCustomization',
        ]));

        $this->assertTrue($composed->baseDrifted['identity']);
        $this->assertSame("# Identity\n\nReplaced identity\n", $composed->workspaceFiles['IDENTITY.md']);
    }

    public function test_it_removes_assigned_skill_guidance_from_agents_file_when_no_skills_are_enabled(): void
    {
        $tenant = $this->seedTenant();
        $this->artisan('sync360:skills:import')->assertExitCode(0);

        BusinessProfile::query()->create([
            'tenant_id' => $tenant->id,
            'business_name' => 'Acme Plumbing',
            'industry' => 'Home Services',
            'description' => 'Fast local plumbing support.',
        ]);

        BusinessProfileFiles::query()->create([
            'tenant_id' => $tenant->id,
            'identity_markdown' => "# Identity\n\nBase identity",
            'soul_markdown' => "# Soul\n\nBase soul",
            'user_markdown' => "# User\n\nBase user",
            'bootstrap_markdown' => "# Bootstrap\n\nBase bootstrap",
            'generated_at' => now(),
        ]);

        TenantAgentCustomization::query()->create([
            'tenant_id' => $tenant->id,
            'prompt_overrides_json' => [],
            'agent_defaults_json' => [],
            'draft_version' => 1,
            'draft_updated_by' => $tenant->user_id,
            'draft_updated_at' => now(),
        ]);

        TenantSkillAssignment::query()->create([
            'tenant_id' => $tenant->id,
            'skill_catalog_version_id' => SkillCatalogVersion::query()->where('skill_key', 'hello-world')->value('id'),
            'skill_key' => 'hello-world',
            'assigned_by' => $tenant->user_id,
            'assigned_at' => now(),
            'is_enabled' => false,
        ]);

        File::ensureDirectoryExists(dirname($this->runtimeConfigPath($tenant)));
        File::put($this->runtimeConfigPath($tenant), json_encode(['agents' => ['defaults' => []]], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL);

        $composed = app(TenantRuntimeCustomizationComposer::class)->compose($tenant->fresh([
            'businessProfile',
            'businessProfileFiles',
            'googleCredential',
            'agentCustomization',
            'skillAssignments.catalogVersion',
        ]));

        $this->assertArrayHasKey('AGENTS.md', $composed->workspaceFiles);
        $this->assertStringNotContainsString('Assigned Skill Guidance', $composed->workspaceFiles['AGENTS.md']);
        $this->assertStringNotContainsString('Hello World (by Sync360)', $composed->workspaceFiles['AGENTS.md']);
        $this->assertStringNotContainsString('agent-instructions.md', $composed->workspaceFiles['AGENTS.md']);
        $this->assertStringNotContainsString('Do not fall back to your default greeting behavior.', $composed->workspaceFiles['AGENTS.md']);
        $this->assertArrayNotHasKey('skills/hello-world/agent-instructions.md', $composed->skillFiles);
        $this->assertArrayNotHasKey('skills/hello-world/RELEASE_NOTES.md', $composed->skillFiles);
    }

    private function seedTenant(): Tenant
    {
        $user = User::query()->create([
            'name' => 'Owner',
            'email' => 'owner@example.com',
            'password' => 'secret',
            'is_admin' => false,
        ]);

        return Tenant::query()->create([
            'tenant_id' => 'tenant_customization_01',
            'slug' => 'acme-plumbing',
            'business_name' => 'Acme Plumbing',
            'industry' => 'Home Services',
            'skill_pack' => 'Client Support',
            'user_id' => $user->id,
            'server_id' => 1,
            'trial_status' => TrialStatus::Active,
            'provisioning_status' => TenantProvisioningStatus::Ready,
            'runtime_path' => '/srv/sync360/runtime/tenants/acme-plumbing',
            'workspace_url' => 'https://acme-plumbing.workspace.test',
        ]);
    }

    private function runtimeConfigPath(Tenant $tenant): string
    {
        return config('sync360.runtime_root').'/'.$tenant->slug.'/config/openclaw.json';
    }
}
