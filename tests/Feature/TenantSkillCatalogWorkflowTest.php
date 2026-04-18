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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
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
            ->expectsOutputToContain('appointment-booking');

        $this->assertDatabaseCount('skill_catalog_items', 0);
        $this->assertDatabaseCount('skill_catalog_versions', 0);
    }

    public function test_scan_command_can_filter_to_one_skill(): void
    {
        $this->artisan('sync360:skills:scan', ['--skill' => 'appointment-booking'])
            ->assertExitCode(0)
            ->expectsOutputToContain('appointment-booking');
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

    public function test_local_import_includes_non_production_ready_skills(): void
    {
        $manifestPath = base_path('resources/skill-packs/appointment-booking/manifest.json');
        $originalManifest = json_decode(File::get($manifestPath), true);
        $modifiedManifest = $originalManifest;
        $modifiedManifest['production_ready'] = false;
        File::put($manifestPath, json_encode($modifiedManifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL);

        try {
            $this->app['env'] = 'local';

            $this->artisan('sync360:skills:import')
                ->assertExitCode(0)
                ->expectsOutputToContain('Imported appointment-booking@1.0.0');

            $this->assertDatabaseHas('skill_catalog_items', [
                'skill_key' => 'appointment-booking',
            ]);
        } finally {
            File::put($manifestPath, json_encode($originalManifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL);
        }
    }

    public function test_non_local_import_skips_non_production_ready_skills(): void
    {
        $manifestPath = base_path('resources/skill-packs/appointment-booking/manifest.json');
        $originalManifest = json_decode(File::get($manifestPath), true);
        $modifiedManifest = $originalManifest;
        $modifiedManifest['production_ready'] = false;
        File::put($manifestPath, json_encode($modifiedManifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL);

        try {
            $this->app['env'] = 'production';

            $this->artisan('sync360:skills:import')
                ->assertExitCode(0)
                ->expectsOutputToContain('skipped appointment-booking');

            $this->assertDatabaseMissing('skill_catalog_items', [
                'skill_key' => 'appointment-booking',
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
            ->where('skill_key', 'appointment-booking')
            ->first();

        $this->assertNotNull($publishedVersion);

        foreach ([$tenantA, $tenantB] as $tenant) {
            \App\Models\TenantSkillAssignment::query()->create([
                'tenant_id' => $tenant->id,
                'skill_catalog_version_id' => $publishedVersion->id,
                'skill_key' => 'appointment-booking',
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
            'skill' => 'appointment-booking',
            'version' => $publishedVersion->id,
        ]), [
            'tenant_ids' => [$tenantA->id, $tenantB->id],
        ])->assertRedirect(route('admin.skills.show', 'appointment-booking'));

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

        $skill = \App\Models\SkillCatalogItem::query()->firstWhere('skill_key', 'appointment-booking');
        $this->assertNotNull($skill);

        $orphanedHoldingPath = storage_path('framework/testing/appointment-booking-orphaned-test');
        File::ensureDirectoryExists(dirname($orphanedHoldingPath));
        File::move(
            base_path('resources/skill-packs/appointment-booking'),
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
                    base_path('resources/skill-packs/appointment-booking')
                );
            }
        }
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
