<?php

namespace Tests\Feature;

use App\Enums\ProvisioningJobStatus;
use App\Enums\TenantProvisioningStatus;
use App\Enums\TrialStatus;
use App\Jobs\ApplyTenantAgentCustomization;
use App\Models\BusinessProfile;
use App\Models\BusinessProfileFiles;
use App\Models\ProvisioningJob;
use App\Models\Tenant;
use App\Models\TenantAgentCustomization;
use App\Models\User;
use App\Services\TenantSkillRegistryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class TenantSkillCatalogWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_scan_command_returns_repo_skills_without_writing_catalog_rows(): void
    {
        $this->artisan('sync360:skills:scan')
            ->assertExitCode(0)
            ->expectsOutputToContain('hello-world');

        $this->assertDatabaseCount('skill_catalog_items', 0);
        $this->assertDatabaseCount('skill_catalog_versions', 0);
    }

    public function test_scan_command_can_filter_to_one_skill(): void
    {
        $this->artisan('sync360:skills:scan', ['--skill' => 'hello-world'])
            ->assertExitCode(0)
            ->expectsOutputToContain('hello-world');
    }

    public function test_scan_command_surfaces_invalid_manifest_instead_of_silently_skipping(): void
    {
        $invalidSkillPath = base_path('resources/skill-packs/invalid-scan-skill');
        File::ensureDirectoryExists($invalidSkillPath);
        File::put($invalidSkillPath.'/manifest.json', json_encode([
            'label' => 'Broken Skill',
            'version' => '0.0.1',
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        try {
            $this->artisan('sync360:skills:scan')
                ->assertExitCode(1)
                ->expectsOutputToContain('invalid');
        } finally {
            File::deleteDirectory($invalidSkillPath);
        }
    }

    public function test_scan_command_requires_agent_instructions_file(): void
    {
        $instructionsPath = base_path('resources/skill-packs/hello-world/agent-instructions.md');
        $holdingPath = storage_path('framework/testing/hello-world-agent-instructions.md');
        File::ensureDirectoryExists(dirname($holdingPath));
        File::move($instructionsPath, $holdingPath);

        try {
            $this->artisan('sync360:skills:scan', ['--skill' => 'hello-world'])
                ->assertExitCode(1)
                ->expectsOutputToContain('agent-instructions.md');
        } finally {
            if (File::exists($holdingPath)) {
                File::move($holdingPath, $instructionsPath);
            }
        }
    }

    public function test_scan_command_requires_release_notes_file(): void
    {
        $releaseNotesPath = base_path('resources/skill-packs/hello-world/RELEASE_NOTES.md');
        $holdingPath = storage_path('framework/testing/hello-world-release-notes.md');
        File::ensureDirectoryExists(dirname($holdingPath));
        File::move($releaseNotesPath, $holdingPath);

        try {
            $this->artisan('sync360:skills:scan', ['--skill' => 'hello-world'])
                ->assertExitCode(1)
                ->expectsOutputToContain('RELEASE_NOTES.md');
        } finally {
            if (File::exists($holdingPath)) {
                File::move($holdingPath, $releaseNotesPath);
            }
        }
    }

    public function test_scan_command_requires_release_notes_to_include_current_manifest_version(): void
    {
        $releaseNotesPath = base_path('resources/skill-packs/hello-world/RELEASE_NOTES.md');
        $originalReleaseNotes = File::get($releaseNotesPath);
        File::put($releaseNotesPath, str_replace('1.0.5', '1.0.2', $originalReleaseNotes));

        try {
            $this->artisan('sync360:skills:scan', ['--skill' => 'hello-world'])
                ->assertExitCode(1)
                ->expectsOutputToContain('current manifest version');
        } finally {
            File::put($releaseNotesPath, $originalReleaseNotes);
        }
    }

    public function test_local_import_includes_non_production_ready_skills(): void
    {
        $manifestPath = base_path('resources/skill-packs/hello-world/manifest.json');
        $originalManifest = json_decode(File::get($manifestPath), true);
        $modifiedManifest = $originalManifest;
        $modifiedManifest['production_ready'] = false;
        File::put($manifestPath, json_encode($modifiedManifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL);

        try {
            $this->app['env'] = 'local';

            $this->artisan('sync360:skills:import')
                ->assertExitCode(0)
                ->expectsOutputToContain('Imported hello-world@1.0.5');

            $this->assertDatabaseHas('skill_catalog_items', [
                'skill_key' => 'hello-world',
                'is_assignable' => false,
            ]);
        } finally {
            File::put($manifestPath, json_encode($originalManifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL);
        }
    }

    public function test_imported_workspace_skill_manifest_declares_sync360_runtime_type(): void
    {
        $this->artisan('sync360:skills:import', ['--skill' => 'hello-world'])
            ->assertExitCode(0)
            ->expectsOutputToContain('Imported hello-world@1.0.5');

        $manifest = \App\Models\SkillCatalogVersion::query()
            ->where('skill_key', 'hello-world')
            ->firstOrFail()
            ->manifest_json;

        $this->assertIsArray($manifest);
        $this->assertSame('sync360_workspace', $manifest['runtime_type'] ?? null);
        $this->assertSame(['hello-world'], $manifest['openclaw_skill_ids'] ?? null);
        $this->assertSame(['hello-world'], $manifest['default_agent_skill_ids'] ?? null);
    }

    public function test_scan_rejects_missing_or_invalid_runtime_type(): void
    {
        $manifestPath = base_path('resources/skill-packs/hello-world/manifest.json');
        $originalManifest = json_decode(File::get($manifestPath), true);
        $modifiedManifest = $originalManifest;
        unset($modifiedManifest['runtime_type']);

        File::put($manifestPath, json_encode($modifiedManifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL);

        try {
            $this->artisan('sync360:skills:scan', ['--skill' => 'hello-world'])
                ->assertExitCode(1)
                ->expectsOutputToContain('runtime_type');
        } finally {
            File::put($manifestPath, json_encode($originalManifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL);
        }

        $modifiedManifest = $originalManifest;
        $modifiedManifest['runtime_type'] = 'unsupported_runtime';
        File::put($manifestPath, json_encode($modifiedManifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL);

        try {
            $this->artisan('sync360:skills:scan', ['--skill' => 'hello-world'])
                ->assertExitCode(1)
                ->expectsOutputToContain('runtime_type');
        } finally {
            File::put($manifestPath, json_encode($originalManifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL);
        }
    }

    public function test_scan_allows_workspace_skill_that_registers_its_workspace_skill_id(): void
    {
        $manifestPath = base_path('resources/skill-packs/hello-world/manifest.json');
        $originalManifest = json_decode(File::get($manifestPath), true);
        $modifiedManifest = $originalManifest;
        $modifiedManifest['runtime_type'] = TenantSkillRegistryService::RUNTIME_TYPE_SYNC360_WORKSPACE;
        $modifiedManifest['openclaw_skill_ids'] = ['hello-world'];
        $modifiedManifest['default_agent_skill_ids'] = ['hello-world'];
        File::put($manifestPath, json_encode($modifiedManifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL);

        try {
            $this->artisan('sync360:skills:scan', ['--skill' => 'hello-world'])
                ->assertExitCode(0)
                ->expectsOutputToContain('hello-world');
        } finally {
            File::put($manifestPath, json_encode($originalManifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL);
        }
    }

    public function test_scan_rejects_workspace_skill_that_registers_a_different_skill_id(): void
    {
        $manifestPath = base_path('resources/skill-packs/hello-world/manifest.json');
        $originalManifest = json_decode(File::get($manifestPath), true);
        $modifiedManifest = $originalManifest;
        $modifiedManifest['runtime_type'] = TenantSkillRegistryService::RUNTIME_TYPE_SYNC360_WORKSPACE;
        $modifiedManifest['openclaw_skill_ids'] = ['not-hello-world'];
        $modifiedManifest['default_agent_skill_ids'] = ['not-hello-world'];
        File::put($manifestPath, json_encode($modifiedManifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL);

        try {
            $this->artisan('sync360:skills:scan', ['--skill' => 'hello-world'])
                ->assertExitCode(1)
                ->expectsOutputToContain('workspace skill_id');
        } finally {
            File::put($manifestPath, json_encode($originalManifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL);
        }
    }

    public function test_scan_allows_openclaw_native_skill_with_native_ids(): void
    {
        $manifestPath = base_path('resources/skill-packs/hello-world/manifest.json');
        $originalManifest = json_decode(File::get($manifestPath), true);
        $modifiedManifest = $originalManifest;
        $modifiedManifest['runtime_type'] = TenantSkillRegistryService::RUNTIME_TYPE_OPENCLAW_NATIVE;
        $modifiedManifest['openclaw_skill_ids'] = ['hello-world'];
        $modifiedManifest['default_agent_skill_ids'] = ['hello-world'];
        File::put($manifestPath, json_encode($modifiedManifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL);

        try {
            $this->artisan('sync360:skills:scan', ['--skill' => 'hello-world'])
                ->assertExitCode(0)
                ->expectsOutputToContain('hello-world');
        } finally {
            File::put($manifestPath, json_encode($originalManifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL);
        }
    }

    public function test_imported_skills_are_unavailable_until_published_and_archive_removes_assignability(): void
    {
        $admin = User::query()->create([
            'name' => 'Admin',
            'email' => 'skill-status-admin@example.com',
            'password' => 'secret',
            'is_admin' => true,
        ]);

        $this->artisan('sync360:skills:import')->assertExitCode(0);

        $skill = \App\Models\SkillCatalogItem::query()->firstWhere('skill_key', 'hello-world');
        $version = \App\Models\SkillCatalogVersion::query()->where('skill_key', 'hello-world')->first();

        $this->assertNotNull($skill);
        $this->assertNotNull($version);
        $this->assertFalse((bool) $skill->is_assignable);

        $this->actingAs($admin)
            ->get(route('admin.skills.index'))
            ->assertOk()
            ->assertSee('Not published')
            ->assertSee('unavailable');

        app(\App\Services\SkillCatalogService::class)->publishVersion($version);

        $skill->refresh();
        $this->assertTrue((bool) $skill->is_assignable);

        $this->actingAs($admin)
            ->get(route('admin.skills.index'))
            ->assertOk()
            ->assertSee('1.0.5')
            ->assertSee('assignable');

        app(\App\Services\SkillCatalogService::class)->archiveVersion($version->fresh());

        $skill->refresh();
        $this->assertFalse((bool) $skill->is_assignable);

        $this->actingAs($admin)
            ->get(route('admin.skills.index'))
            ->assertOk()
            ->assertSee('Not published')
            ->assertSee('unavailable');
    }

    public function test_non_local_import_skips_non_production_ready_skills(): void
    {
        $manifestPath = base_path('resources/skill-packs/hello-world/manifest.json');
        $originalManifest = json_decode(File::get($manifestPath), true);
        $modifiedManifest = $originalManifest;
        $modifiedManifest['production_ready'] = false;
        File::put($manifestPath, json_encode($modifiedManifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL);

        try {
            $this->app['env'] = 'production';

            $this->artisan('sync360:skills:import')
                ->assertExitCode(0)
                ->expectsOutputToContain('skipped hello-world');

            $this->assertDatabaseMissing('skill_catalog_items', [
                'skill_key' => 'hello-world',
            ]);
        } finally {
            File::put($manifestPath, json_encode($originalManifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL);
        }
    }

    public function test_skill_state_is_first_class_and_not_stored_on_tenant_agent_customizations(): void
    {
        $this->assertTrue(class_exists(\App\Models\TenantSkillAssignment::class));
        $this->assertTrue(class_exists(\App\Models\SkillCatalogItem::class));
        $this->assertTrue(class_exists(\App\Models\SkillCatalogVersion::class));
        $this->assertTrue(Schema::hasTable('tenant_skill_assignments'));
        $this->assertTrue(Schema::hasTable('skill_catalog_items'));
        $this->assertTrue(Schema::hasTable('skill_catalog_versions'));
        $this->assertFalse(Schema::hasColumn('tenant_agent_customizations', 'assigned_skill_pack_ids'));
    }

    public function test_bulk_rollout_creates_one_provisioning_job_per_selected_tenant(): void
    {
        Queue::fake([ApplyTenantAgentCustomization::class]);

        $admin = User::query()->create([
            'name' => 'Admin',
            'email' => 'admin@example.com',
            'password' => 'secret',
            'is_admin' => true,
        ]);

        [$tenantA, $tenantB] = [
            $this->seedTenant('rollout-a', 'Rollout A'),
            $this->seedTenant('rollout-b', 'Rollout B'),
        ];

        $this->artisan('sync360:skills:import')->assertExitCode(0);

        $publishedVersion = \App\Models\SkillCatalogVersion::query()
            ->where('skill_key', 'hello-world')
            ->first();

        $this->assertNotNull($publishedVersion);

        foreach ([$tenantA, $tenantB] as $tenant) {
            \App\Models\TenantSkillAssignment::query()->create([
                'tenant_id' => $tenant->id,
                'skill_catalog_version_id' => $publishedVersion->id,
                'skill_key' => 'hello-world',
                'assigned_by' => $admin->id,
                'assigned_at' => now(),
                'is_enabled' => true,
            ]);

            TenantAgentCustomization::query()->create([
                'tenant_id' => $tenant->id,
                'prompt_overrides_json' => [],
                'agent_defaults_json' => [],
                'draft_version' => 1,
                'draft_updated_by' => $admin->id,
                'draft_updated_at' => now(),
            ]);
        }

        $this->actingAs($admin);

        $this->post(route('admin.skills.versions.rollout', [
            'skill' => 'hello-world',
            'version' => $publishedVersion->id,
        ]), [
            'tenant_ids' => [$tenantA->id, $tenantB->id],
        ])->assertRedirect(route('admin.skills.show', 'hello-world'));

        $jobs = ProvisioningJob::query()
            ->where('job_type', ApplyTenantAgentCustomization::JOB_TYPE)
            ->orderBy('tenant_id')
            ->get();

        $this->assertCount(2, $jobs);
        $this->assertEqualsCanonicalizing([$tenantA->id, $tenantB->id], $jobs->pluck('tenant_id')->all());
        $this->assertTrue($jobs->every(fn (ProvisioningJob $job): bool => $job->status === ProvisioningJobStatus::Queued));
    }

    public function test_repo_import_marks_missing_repo_skills_as_orphaned_warnings(): void
    {
        $this->assertTrue(Artisan::call('sync360:skills:import') === 0);

        $skill = \App\Models\SkillCatalogItem::query()->firstWhere('skill_key', 'hello-world');
        $this->assertNotNull($skill);

        $orphanedHoldingPath = storage_path('framework/testing/hello-world-orphaned-test');
        File::ensureDirectoryExists(dirname($orphanedHoldingPath));
        File::move(
            base_path('resources/skill-packs/hello-world'),
            $orphanedHoldingPath
        );

        try {
            $this->artisan('sync360:skills:import')
                ->assertExitCode(0);

            $skill->refresh();

            $this->assertTrue((bool) $skill->is_orphaned);
            $this->assertFalse((bool) $skill->is_assignable);
        } finally {
            if (File::isDirectory($orphanedHoldingPath)) {
                File::move(
                    $orphanedHoldingPath,
                    base_path('resources/skill-packs/hello-world')
                );
            }
        }
    }

    public function test_appointment_booking_to_hello_world_migration_updates_catalog_assignments_events_and_snapshots(): void
    {
        $tenant = $this->seedTenant('migration-shop', 'Migration Shop');
        $now = now();

        $itemId = DB::table('skill_catalog_items')->insertGetId([
            'skill_key' => 'appointment-booking',
            'label' => 'Appointment Booking',
            'description' => 'Guides customers through booking requests and next-step confirmation.',
            'category' => 'operations',
            'is_assignable' => true,
            'is_orphaned' => false,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $versionId = DB::table('skill_catalog_versions')->insertGetId([
            'skill_catalog_item_id' => $itemId,
            'skill_key' => 'appointment-booking',
            'version' => '1.0.3',
            'manifest_json' => json_encode([
                'skill_id' => 'appointment-booking',
                'version' => '1.0.3',
                'label' => 'Appointment Booking',
                'analytics' => [
                    'enabled' => true,
                    'conversion_type' => 'appointment_booked',
                ],
            ], JSON_UNESCAPED_SLASHES),
            'is_active_published' => true,
            'is_archived' => false,
            'is_available' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        \App\Models\TenantSkillAssignment::query()->create([
            'tenant_id' => $tenant->id,
            'skill_catalog_version_id' => $versionId,
            'skill_key' => 'appointment-booking',
            'assigned_by' => $tenant->user_id,
            'assigned_at' => $now,
            'is_enabled' => true,
        ]);

        DB::table('tenant_skill_conversion_events')->insert([
            'tenant_id' => $tenant->id,
            'event_id' => 'migration-event-001',
            'skill_key' => 'appointment-booking',
            'skill_version' => '1.0.3',
            'event_type' => 'conversion_succeeded',
            'conversion_type' => 'appointment_booked',
            'conversion_id' => 'migration-conversion-001',
            'occurred_at' => $now,
            'customer_label' => 'Migration Customer',
            'human_effort_minutes' => 10,
            'agent_effort_minutes' => 1,
            'net_minutes_saved' => 9,
            'productivity_score' => 1,
            'outcome_json' => json_encode(['service_name' => 'Migration'], JSON_UNESCAPED_SLASHES),
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $customization = TenantAgentCustomization::query()->create([
            'tenant_id' => $tenant->id,
            'prompt_overrides_json' => [],
            'agent_defaults_json' => [
                'default_skill_ids' => ['appointment-booking'],
            ],
            'draft_version' => 1,
            'draft_updated_by' => $tenant->user_id,
            'draft_updated_at' => $now,
            'last_applied_input_snapshot_json' => [
                'assigned_skills' => [
                    [
                        'skill_key' => 'appointment-booking',
                        'openclaw_skill_ids' => ['appointment-booking'],
                        'default_agent_skill_ids' => ['appointment-booking'],
                    ],
                ],
            ],
        ]);

        DB::table('tenant_agent_customization_applies')->insert([
            'tenant_agent_customization_id' => $customization->id,
            'tenant_id' => $tenant->id,
            'applied_by' => $tenant->user_id,
            'action' => 'apply',
            'draft_version_applied' => 1,
            'input_snapshot_json' => json_encode([
                'assigned_skills' => [
                    [
                        'skill_key' => 'appointment-booking',
                        'openclaw_skill_ids' => ['appointment-booking'],
                    ],
                ],
            ], JSON_UNESCAPED_SLASHES),
            'composed_output_json' => json_encode([
                'workspace_files' => [
                    'AGENTS.md' => 'Appointment Booking uses appointment-booking and appointment_booked.',
                ],
            ], JSON_UNESCAPED_SLASHES),
            'status' => 'applied',
            'created_at' => $now,
        ]);

        $migration = require base_path('database/migrations/2026_04_21_103602_migrate_appointment_booking_to_hello_world_skill.php');
        $migration->up();

        $this->assertDatabaseHas('skill_catalog_items', [
            'skill_key' => 'hello-world',
            'label' => 'Hello World (by Sync360)',
        ]);
        $this->assertDatabaseHas('skill_catalog_versions', [
            'skill_key' => 'hello-world',
            'version' => '1.0.3',
        ]);
        $this->assertDatabaseHas('tenant_skill_assignments', [
            'tenant_id' => $tenant->id,
            'skill_key' => 'hello-world',
        ]);
        $this->assertDatabaseHas('tenant_skill_conversion_events', [
            'tenant_id' => $tenant->id,
            'skill_key' => 'hello-world',
            'conversion_type' => 'hello_world_completed',
        ]);

        $manifest = json_decode((string) DB::table('skill_catalog_versions')->value('manifest_json'), true);
        $this->assertSame('hello-world', $manifest['skill_id']);
        $this->assertSame('1.0.3', $manifest['version']);
        $this->assertSame('Hello World (by Sync360)', $manifest['label']);
        $this->assertSame('hello_world_completed', data_get($manifest, 'analytics.conversion_type'));

        $customization->refresh();
        $this->assertSame(['hello-world'], data_get($customization->agent_defaults_json, 'default_skill_ids'));
        $this->assertSame('hello-world', data_get($customization->last_applied_input_snapshot_json, 'assigned_skills.0.skill_key'));

        $apply = DB::table('tenant_agent_customization_applies')->first();
        $this->assertStringContainsString('hello-world', (string) $apply->input_snapshot_json);
        $this->assertStringContainsString('Hello World (by Sync360)', (string) $apply->composed_output_json);
        $this->assertStringContainsString('hello_world_completed', (string) $apply->composed_output_json);
    }

    public function test_skill_catalog_assignability_migration_syncs_stale_flags_to_published_versions(): void
    {
        $now = now();

        $unpublishedItemId = DB::table('skill_catalog_items')->insertGetId([
            'skill_key' => 'unpublished-skill',
            'label' => 'Unpublished Skill',
            'description' => 'No published version yet.',
            'is_assignable' => true,
            'is_orphaned' => false,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        DB::table('skill_catalog_versions')->insert([
            'skill_catalog_item_id' => $unpublishedItemId,
            'skill_key' => 'unpublished-skill',
            'version' => '1.0.0',
            'manifest_json' => json_encode(['skill_id' => 'unpublished-skill'], JSON_UNESCAPED_SLASHES),
            'is_active_published' => false,
            'is_archived' => false,
            'is_available' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $publishedItemId = DB::table('skill_catalog_items')->insertGetId([
            'skill_key' => 'published-skill',
            'label' => 'Published Skill',
            'description' => 'Has a published version.',
            'is_assignable' => false,
            'is_orphaned' => false,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        DB::table('skill_catalog_versions')->insert([
            'skill_catalog_item_id' => $publishedItemId,
            'skill_key' => 'published-skill',
            'version' => '1.0.0',
            'manifest_json' => json_encode(['skill_id' => 'published-skill'], JSON_UNESCAPED_SLASHES),
            'is_active_published' => true,
            'is_archived' => false,
            'is_available' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $migration = require base_path('database/migrations/2026_04_21_120000_sync_skill_catalog_assignability_to_published_versions.php');
        $migration->up();

        $this->assertDatabaseHas('skill_catalog_items', [
            'skill_key' => 'unpublished-skill',
            'is_assignable' => false,
        ]);
        $this->assertDatabaseHas('skill_catalog_items', [
            'skill_key' => 'published-skill',
            'is_assignable' => true,
        ]);
    }

    public function test_skill_analytics_discovery_command_writes_a_finding_document(): void
    {
        $path = base_path('artifacts/skill-analytics-discovery.md');
        File::delete($path);

        $this->artisan('sync360:skills:discover-analytics')
            ->assertExitCode(0);

        $this->assertFileExists($path);
        $this->assertStringContainsString('Runtime log path', File::get($path));
        $this->assertStringContainsString('Format sample', File::get($path));
        $this->assertStringContainsString('Stable dedup ID', File::get($path));
    }

    public function test_import_fails_when_analytics_enabled_skill_manifest_is_missing_required_fields(): void
    {
        $manifestPath = base_path('resources/skill-packs/hello-world/manifest.json');
        $originalManifest = json_decode(File::get($manifestPath), true);
        $modifiedManifest = $originalManifest;
        $modifiedManifest['analytics'] = [
            'enabled' => true,
            'conversion_type' => 'hello_world_completed',
        ];
        File::put($manifestPath, json_encode($modifiedManifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL);

        try {
            $this->artisan('sync360:skills:scan')
                ->assertExitCode(1)
                ->expectsOutputToContain('analytics');

            $this->artisan('sync360:skills:import')
                ->assertExitCode(0)
                ->expectsOutputToContain('skipped hello-world');

            $this->assertDatabaseMissing('skill_catalog_items', [
                'skill_key' => 'hello-world',
            ]);
        } finally {
            File::put($manifestPath, json_encode($originalManifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL);
        }
    }

    private function seedTenant(string $slug, string $businessName): Tenant
    {
        $user = User::query()->create([
            'name' => $businessName.' Owner',
            'email' => $slug.'@example.com',
            'password' => 'secret',
            'is_admin' => false,
        ]);

        $tenant = Tenant::query()->create([
            'tenant_id' => 'tenant_'.$slug,
            'slug' => $slug,
            'business_name' => $businessName,
            'industry' => 'Retail',
            'skill_pack' => 'Client Support',
            'user_id' => $user->id,
            'server_id' => 1,
            'trial_status' => TrialStatus::Active,
            'provisioning_status' => TenantProvisioningStatus::Ready,
            'agent_status' => 'live',
            'workspace_url' => 'https://'.$slug.'.workspace.test',
            'runtime_path' => '/srv/sync360/runtime/tenants/'.$slug,
        ]);

        BusinessProfile::query()->create([
            'tenant_id' => $tenant->id,
            'business_name' => $businessName,
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

        File::ensureDirectoryExists(config('sync360.runtime_root').'/'.$tenant->slug.'/config');
        File::put(config('sync360.runtime_root').'/'.$tenant->slug.'/config/openclaw.json', json_encode([
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

        return $tenant;
    }
}
