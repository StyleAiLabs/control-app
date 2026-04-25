<?php

namespace Tests\Unit;

use App\Enums\TenantProvisioningStatus;
use App\Enums\TrialStatus;
use App\Models\BusinessProfile;
use App\Models\BusinessProfileFiles;
use App\Models\SkillCatalogVersion;
use App\Models\Tenant;
use App\Models\TenantAgentCustomization;
use App\Models\TenantSkillAssignment;
use App\Models\User;
use App\Services\TenantRuntimeCapabilityService;
use App\Services\TenantRuntimeSkillActivationService;
use App\Services\TenantRuntimeSkillDiscoveryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use RuntimeException;
use Tests\TestCase;

class TenantRuntimeSkillActivationServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_sync_expected_contract_includes_native_and_workspace_skills(): void
    {
        $tenant = $this->seedTenantWithSkill('hello-world');

        $this->mock(TenantRuntimeCapabilityService::class, function ($mock): void {
            $mock->shouldReceive('openClawSkills')->andReturn(['gog']);
        });

        $result = app(TenantRuntimeSkillActivationService::class)->syncExpectedContract(
            $tenant->fresh(['agentCustomization', 'skillAssignments.catalogVersion'])
        );

        $this->assertTrue($result['changed']);
        $this->assertTrue(File::exists(config('sync360.runtime_root').'/'.$tenant->slug.'/.openclaw/workspace/.sync360/runtime-skill-contract.json'));
        $this->assertSame(['gog', 'hello-world'], $result['contract']['expected_skill_ids']);
        $this->assertSame(
            hash('sha256', "gog\nhello-world"),
            $result['contract']['skill_set_hash']
        );
        $this->assertSame([], $result['contract']['verified_skill_ids']);
    }

    public function test_ensure_required_skills_ready_reloads_once_and_recovers_when_skill_appears(): void
    {
        $tenant = $this->seedTenantWithSkill('hello-world');

        $this->mock(TenantRuntimeCapabilityService::class, function ($mock): void {
            $mock->shouldReceive('openClawSkills')->andReturn(['gog']);
            $mock->shouldReceive('reloadRuntime')->once()->withArgs(
                fn (Tenant $candidate, bool $recreate): bool => $candidate->slug === 'activation-shop' && $recreate === false
            );
        });
        $this->mock(TenantRuntimeSkillDiscoveryService::class, function ($mock) use ($tenant): void {
            $mock->shouldReceive('inspect')
                ->twice()
                ->withArgs(fn (Tenant $candidate): bool => $candidate->is($tenant))
                ->andReturn(
                    [
                        'workspace_state' => 'running',
                        'refreshed_at' => '2026-04-25 11:30:00',
                        'skills' => ['gog'],
                        'raw_output' => "gog\n",
                    ],
                    [
                        'workspace_state' => 'running',
                        'refreshed_at' => '2026-04-25 11:31:00',
                        'skills' => ['gog', 'hello-world'],
                        'raw_output' => "gog\nhello-world\n",
                    ],
                );
        });

        $contract = app(TenantRuntimeSkillActivationService::class)->ensureRequiredSkillsReady(
            $tenant->fresh(['server', 'agentCustomization', 'skillAssignments.catalogVersion']),
            ['hello-world'],
        );

        $this->assertSame(['gog', 'hello-world'], $contract['verified_skill_ids']);
        $this->assertSame($contract['skill_set_hash'], $contract['verified_skill_set_hash']);
        $this->assertNull($contract['last_verification_error']);
    }

    public function test_ensure_required_skills_ready_fails_closed_when_skill_never_appears(): void
    {
        $tenant = $this->seedTenantWithSkill('hello-world');

        $this->mock(TenantRuntimeCapabilityService::class, function ($mock): void {
            $mock->shouldReceive('openClawSkills')->andReturn(['gog']);
            $mock->shouldReceive('reloadRuntime')->once();
        });
        $this->mock(TenantRuntimeSkillDiscoveryService::class, function ($mock) use ($tenant): void {
            $mock->shouldReceive('inspect')
                ->twice()
                ->withArgs(fn (Tenant $candidate): bool => $candidate->is($tenant))
                ->andReturn([
                    'workspace_state' => 'running',
                    'refreshed_at' => '2026-04-25 11:30:00',
                    'skills' => ['gog'],
                    'raw_output' => "gog\n",
                ]);
        });

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Missing: hello-world');

        try {
            app(TenantRuntimeSkillActivationService::class)->ensureRequiredSkillsReady(
                $tenant->fresh(['server', 'agentCustomization', 'skillAssignments.catalogVersion']),
                ['hello-world'],
            );
        } finally {
            $contract = app(TenantRuntimeSkillActivationService::class)->readLocalContract($tenant);
            $this->assertSame(['gog'], $contract['verified_skill_ids']);
            $this->assertNotNull($contract['last_verification_error']);
        }
    }

    private function seedTenantWithSkill(string $skillKey): Tenant
    {
        $owner = User::query()->create([
            'name' => 'Activation Owner',
            'email' => 'activation-owner@example.com',
            'password' => 'secret',
        ]);

        $tenant = Tenant::query()->create([
            'tenant_id' => 'tenant_activation_01',
            'slug' => 'activation-shop',
            'business_name' => 'Activation Shop',
            'industry' => 'Services',
            'skill_pack' => 'Client Support',
            'channel' => 'telegram',
            'agent_status' => 'live',
            'trial_status' => TrialStatus::Active,
            'provisioning_status' => TenantProvisioningStatus::Ready,
            'workspace_url' => 'https://activation-shop.workspace.test',
            'runtime_path' => '/srv/sync360/runtime/tenants/activation-shop',
            'server_id' => 1,
            'user_id' => $owner->id,
        ]);

        BusinessProfile::query()->create([
            'tenant_id' => $tenant->id,
            'business_name' => 'Activation Shop',
            'industry' => 'Services',
            'description' => 'Helpful service business.',
            'services' => ['Customer support'],
        ]);

        BusinessProfileFiles::query()->create([
            'tenant_id' => $tenant->id,
            'identity_markdown' => "# Identity\n\nActivation Shop",
            'soul_markdown' => "# Soul\n\nSteady and helpful.",
            'user_markdown' => "# User\n\nHelp customers quickly.",
            'bootstrap_markdown' => "# Bootstrap\n\nUse the profile first.",
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

        $this->artisan('sync360:skills:import', ['--skill' => $skillKey])->assertExitCode(0);
        $version = SkillCatalogVersion::query()->where('skill_key', $skillKey)->firstOrFail();

        TenantSkillAssignment::query()->create([
            'tenant_id' => $tenant->id,
            'skill_catalog_version_id' => $version->id,
            'skill_key' => $skillKey,
            'assigned_by' => $owner->id,
            'assigned_at' => now(),
            'is_enabled' => true,
        ]);

        File::ensureDirectoryExists(config('sync360.runtime_root').'/'.$tenant->slug.'/config');
        File::put(config('sync360.runtime_root').'/'.$tenant->slug.'/config/openclaw.json', json_encode([
            'agents' => [
                'defaults' => [
                    'model' => 'gpt-4o',
                    'skills' => ['gog', $skillKey],
                ],
            ],
            'skills' => [
                'entries' => [
                    'gog' => ['enabled' => true],
                    $skillKey => ['enabled' => true],
                ],
            ],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL);

        return $tenant;
    }
}
