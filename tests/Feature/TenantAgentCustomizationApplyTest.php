<?php

namespace Tests\Feature;

use App\Enums\ProvisioningJobStatus;
use App\Enums\TenantProvisioningStatus;
use App\Enums\TrialStatus;
use App\Models\BusinessProfile;
use App\Models\BusinessProfileFiles;
use App\Models\ProvisioningJob;
use App\Models\SkillCatalogVersion;
use App\Models\Tenant;
use App\Models\TenantAgentCustomization;
use App\Models\TenantSkillAssignment;
use App\Models\User;
use App\Services\TenantAgentCustomizationService;
use App\Services\TenantRuntimeCustomizationComposer;
use App\Services\TenantRuntimeSkillActivationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use RuntimeException;
use Tests\TestCase;

class TenantAgentCustomizationApplyTest extends TestCase
{
    use RefreshDatabase;

    public function test_apply_marks_customization_failed_when_runtime_skill_activation_verification_fails(): void
    {
        [$tenant, $customization] = $this->seedTenantWithSkill('hello-world');
        $job = ProvisioningJob::query()->create([
            'tenant_id' => $tenant->id,
            'job_type' => 'tenant_agent_customization_apply',
            'status' => ProvisioningJobStatus::Queued,
        ]);

        $this->mock(TenantRuntimeSkillActivationService::class, function ($mock) use ($tenant, $customization): void {
            $mock->shouldReceive('syncExpectedContract')
                ->once()
                ->withArgs(fn (Tenant $candidate, ?TenantAgentCustomization $candidateCustomization): bool => $candidate->is($tenant) && $candidateCustomization?->is($customization))
                ->andReturn([
                    'changed' => true,
                    'skill_set_changed' => false,
                    'contract' => [
                        'expected_skill_ids' => ['gog', 'hello-world'],
                        'skill_set_hash' => 'expected-hash',
                        'verified_skill_ids' => [],
                        'verified_skill_set_hash' => null,
                        'last_verified_at' => null,
                        'last_verification_error' => null,
                    ],
                ]);
            $mock->shouldReceive('activateExpectedSkills')
                ->once()
                ->andThrow(new RuntimeException('Runtime skill activation verification failed. Missing expected skills: hello-world.'));
        });

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Missing expected skills: hello-world');

        try {
            app(TenantAgentCustomizationService::class)->apply(
                $tenant->fresh(['server', 'agentCustomization', 'skillAssignments.catalogVersion']),
                $job,
                app(TenantRuntimeCustomizationComposer::class),
            );
        } finally {
            $job->refresh();
            $customization->refresh();
            $assignment = TenantSkillAssignment::query()->where('tenant_id', $tenant->id)->firstOrFail();

            $this->assertSame(ProvisioningJobStatus::Failed, $job->status);
            $this->assertSame('failed', $customization->last_apply_status);
            $this->assertStringContainsString('Missing expected skills: hello-world', (string) $customization->last_apply_error);
            $this->assertSame('failed', $assignment->last_apply_status);
        }
    }

    /**
     * @return array{0: Tenant, 1: TenantAgentCustomization}
     */
    private function seedTenantWithSkill(string $skillKey): array
    {
        $owner = User::query()->create([
            'name' => 'Apply Owner',
            'email' => 'apply-owner@example.com',
            'password' => 'secret',
        ]);

        $tenant = Tenant::query()->create([
            'tenant_id' => 'tenant_apply_01',
            'slug' => 'apply-shop',
            'business_name' => 'Apply Shop',
            'industry' => 'Services',
            'skill_pack' => 'Client Support',
            'channel' => 'telegram',
            'agent_status' => 'live',
            'trial_status' => TrialStatus::Active,
            'provisioning_status' => TenantProvisioningStatus::Ready,
            'workspace_url' => 'https://apply-shop.workspace.test',
            'runtime_path' => '/srv/sync360/runtime/tenants/apply-shop',
            'server_id' => 1,
            'user_id' => $owner->id,
        ]);

        BusinessProfile::query()->create([
            'tenant_id' => $tenant->id,
            'business_name' => 'Apply Shop',
            'industry' => 'Services',
            'description' => 'Helpful service business.',
            'services' => ['Customer support'],
        ]);

        BusinessProfileFiles::query()->create([
            'tenant_id' => $tenant->id,
            'identity_markdown' => "# Identity\n\nApply Shop",
            'soul_markdown' => "# Soul\n\nSteady and helpful.",
            'user_markdown' => "# User\n\nHelp customers quickly.",
            'bootstrap_markdown' => "# Bootstrap\n\nUse the profile first.",
            'generated_at' => now(),
        ]);

        $customization = TenantAgentCustomization::query()->create([
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

        return [$tenant, $customization];
    }
}
