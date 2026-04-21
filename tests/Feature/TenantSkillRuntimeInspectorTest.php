<?php

namespace Tests\Feature;

use App\Enums\TenantProvisioningStatus;
use App\Enums\TrialStatus;
use App\Models\BusinessProfile;
use App\Models\BusinessProfileFiles;
use App\Models\SkillCatalogVersion;
use App\Models\Tenant;
use App\Models\TenantAgentCustomization;
use App\Models\TenantSkillAssignment;
use App\Models\User;
use App\Services\TenantRuntimeCustomizationComposer;
use App\Services\TenantSkillRuntimeInspectorService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class TenantSkillRuntimeInspectorTest extends TestCase
{
    use RefreshDatabase;

    public function test_inspect_tenant_skills_reports_runtime_mismatch_warnings(): void
    {
        [$tenant, $owner] = $this->seedTenant();

        $this->artisan('sync360:skills:import')->assertExitCode(0);
        $version = SkillCatalogVersion::query()->where('skill_key', 'hello-world')->firstOrFail();

        TenantSkillAssignment::query()->create([
            'tenant_id' => $tenant->id,
            'skill_catalog_version_id' => $version->id,
            'skill_key' => 'hello-world',
            'assigned_by' => $owner->id,
            'assigned_at' => now(),
            'is_enabled' => true,
        ]);

        $this->materializeTenantRuntime($tenant);

        $report = app(TenantSkillRuntimeInspectorService::class)->inspect($tenant->fresh([
            'server',
            'skillAssignments.catalogVersion',
        ]));

        $this->assertSame('sync360_workspace', data_get($report, 'assigned_skills.0.runtime_type'));
        $this->assertSame(['gog', 'hello-world'], $report['openclaw_config_skill_ids']);
        $fileStates = $report['materialized_workspace_skill_files'][0]['files'];
        $this->assertTrue($fileStates['SKILL.md']);
        $this->assertTrue($fileStates['agent-instructions.md']);
        $this->assertSame(['hello-world'], data_get($report, 'analytics_registry.skills'));
        $this->assertFalse(data_get($report, 'sqlite_db.exists'));
        $this->assertFalse(data_get($report, 'runtime_skill_visibility.checked'));
        $this->assertNotContains('hello-world is missing from OpenClaw agent skill allowlists.', $report['warnings']);
        $this->assertFalse(collect($report['warnings'])->contains(
            fn (string $warning): bool => str_contains($warning, 'must not appear in openclaw.json')
        ));

        $this->artisan('sync360:inspect-tenant-skills', ['tenantSelector' => $tenant->slug])
            ->assertExitCode(0)
            ->expectsOutputToContain('Assigned Sync360 skills')
            ->expectsOutputToContain('hello-world')
            ->expectsOutputToContain('Materialized workspace skill files')
            ->expectsOutputToContain('Analytics registry entries')
            ->expectsOutputToContain('SQLite DB state')
            ->expectsOutputToContain('OpenClaw config skill IDs')
            ->expectsOutputToContain('Runtime OpenClaw skills visible')
            ->expectsOutputToContain('Mismatch warnings');
    }

    public function test_inspect_tenant_skills_warns_when_workspace_skill_is_not_allowlisted_or_is_disabled(): void
    {
        [$tenant, $owner] = $this->seedTenant();

        $this->artisan('sync360:skills:import')->assertExitCode(0);
        $version = SkillCatalogVersion::query()->where('skill_key', 'hello-world')->firstOrFail();

        TenantSkillAssignment::query()->create([
            'tenant_id' => $tenant->id,
            'skill_catalog_version_id' => $version->id,
            'skill_key' => 'hello-world',
            'assigned_by' => $owner->id,
            'assigned_at' => now(),
            'is_enabled' => true,
        ]);

        $this->materializeTenantRuntime($tenant);
        File::put(config('sync360.runtime_root').'/'.$tenant->slug.'/config/openclaw.json', json_encode([
            'agents' => [
                'defaults' => [
                    'model' => 'gpt-4o',
                    'skills' => ['gog'],
                ],
            ],
            'skills' => [
                'entries' => [
                    'hello-world' => ['enabled' => false],
                    'gog' => ['enabled' => true],
                ],
            ],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL);

        $report = app(TenantSkillRuntimeInspectorService::class)->inspect($tenant->fresh([
            'server',
            'skillAssignments.catalogVersion',
        ]));

        $this->assertContains('hello-world is missing from OpenClaw agent skill allowlists.', $report['warnings']);
        $this->assertContains('hello-world is disabled in openclaw.json skills.entries.', $report['warnings']);
    }

    /**
     * @return array{0: Tenant, 1: User}
     */
    private function seedTenant(): array
    {
        $owner = User::query()->create([
            'name' => 'Inspector Owner',
            'email' => 'inspector-owner@example.com',
            'password' => 'secret',
            'is_admin' => false,
        ]);

        $tenant = Tenant::query()->create([
            'tenant_id' => 'tenant_inspector_01',
            'slug' => 'inspector-shop',
            'business_name' => 'Inspector Shop',
            'industry' => 'Retail',
            'skill_pack' => 'Client Support',
            'user_id' => $owner->id,
            'server_id' => 1,
            'trial_status' => TrialStatus::Active,
            'provisioning_status' => TenantProvisioningStatus::Ready,
            'agent_status' => 'live',
            'workspace_url' => 'https://inspector-shop.workspace.test',
            'runtime_path' => '/srv/sync360/runtime/tenants/inspector-shop',
            'channel' => 'telegram',
        ]);

        BusinessProfile::query()->create([
            'tenant_id' => $tenant->id,
            'business_name' => 'Inspector Shop',
            'industry' => 'Retail',
            'description' => 'Helpful retail support.',
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

        TenantAgentCustomization::query()->create([
            'tenant_id' => $tenant->id,
            'prompt_overrides_json' => [],
            'agent_defaults_json' => [],
            'draft_version' => 1,
            'draft_updated_by' => $owner->id,
            'draft_updated_at' => now(),
        ]);

        File::ensureDirectoryExists(config('sync360.runtime_root').'/'.$tenant->slug.'/config');
        File::put(config('sync360.runtime_root').'/'.$tenant->slug.'/config/openclaw.json', json_encode([
            'agents' => [
                'defaults' => [
                    'model' => 'gpt-4o',
                    'skills' => ['hello-world', 'gog'],
                ],
            ],
            'skills' => [
                'entries' => [
                    'hello-world' => ['enabled' => true],
                    'gog' => ['enabled' => true],
                ],
            ],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL);

        return [$tenant, $owner];
    }

    private function materializeTenantRuntime(Tenant $tenant): void
    {
        $composed = app(TenantRuntimeCustomizationComposer::class)->compose($tenant->fresh([
            'businessProfile',
            'businessProfileFiles',
            'googleCredential',
            'agentCustomization',
            'skillAssignments.catalogVersion',
        ]));
        $workspaceRoot = config('sync360.runtime_root').'/'.$tenant->slug.'/.openclaw/workspace';

        foreach (array_merge($composed->workspaceFiles, $composed->skillFiles) as $path => $contents) {
            File::ensureDirectoryExists(dirname($workspaceRoot.'/'.$path));
            File::put($workspaceRoot.'/'.$path, $contents);
        }
    }
}
