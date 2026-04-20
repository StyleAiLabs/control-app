<?php

namespace Tests\Feature;

use App\Contracts\DockerComposeRunner;
use App\Enums\ProvisioningJobStatus;
use App\Enums\TenantProvisioningStatus;
use App\Enums\TrialStatus;
use App\Jobs\ApplyTenantAgentCustomization;
use App\Models\BusinessProfile;
use App\Models\BusinessProfileFiles;
use App\Models\ProvisioningJob;
use App\Models\Tenant;
use App\Models\TenantAgentCustomization;
use App\Models\TenantAgentCustomizationApply;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class TenantSkillRuntimeLayoutTest extends TestCase
{
    use RefreshDatabase;

    public function test_apply_moves_live_skill_assets_to_workspace_skills_root_and_cleans_legacy_skill_packs_directory(): void
    {
        $this->artisan('sync360:skills:import')->assertExitCode(0);

        [$tenant, $customization] = $this->seedTenantAndCustomization();

        $version = \App\Models\SkillCatalogVersion::query()
            ->where('skill_key', 'appointment-booking')
            ->firstOrFail();

        \App\Models\TenantSkillAssignment::query()->create([
            'tenant_id' => $tenant->id,
            'skill_catalog_version_id' => $version->id,
            'skill_key' => 'appointment-booking',
            'assigned_by' => $customization->draft_updated_by,
            'assigned_at' => now(),
            'is_enabled' => true,
        ]);

        File::ensureDirectoryExists(config('sync360.runtime_root').'/'.$tenant->slug.'/.openclaw/workspace/skill-packs/appointment-booking');
        File::put(config('sync360.runtime_root').'/'.$tenant->slug.'/.openclaw/workspace/skill-packs/appointment-booking/STALE.md', 'stale');

        $runner = new class implements DockerComposeRunner
        {
            public function syncRuntime(\App\Models\Server $server, string $localRuntimePath, string $remoteRuntimePath): void {}
            public function syncWorkspaceFiles(\App\Models\Server $server, string $localWorkspacePath, string $remoteWorkspacePath): void {}
            public function httpRequest(\App\Models\Server $server, string $method, string $url, ?array $json = null, int $timeoutSeconds = 15): array { return ['status' => 200, 'body' => '']; }
            public function putFile(\App\Models\Server $server, string $remotePath, string $contents, bool $sudo = false): void {}
            public function removeFile(\App\Models\Server $server, string $remotePath, bool $sudo = false): void {}
            public function removeDirectory(\App\Models\Server $server, string $remotePath, bool $sudo = false): void {}
            public function runCommand(\App\Models\Server $server, string $command, bool $sudo = false): void {}
            public function up(\App\Models\Server $server, string $composeFile, string $projectName): void {}
            public function down(\App\Models\Server $server, string $composeFile, string $projectName): void {}
            public function start(\App\Models\Server $server, string $composeFile, string $projectName): void {}
            public function stop(\App\Models\Server $server, string $composeFile, string $projectName): void {}
            public function isRunning(\App\Models\Server $server, string $composeFile, string $projectName): bool { return false; }
            public function isHostPortInUse(\App\Models\Server $server, int $port): bool { return false; }
            public function waitForHttpReady(\App\Models\Server $server, string $url, int $timeoutSeconds, int $pollIntervalMs): void {}
        };

        $this->instance(DockerComposeRunner::class, $runner);

        $provisioningJob = ProvisioningJob::query()->create([
            'tenant_id' => $tenant->id,
            'job_type' => ApplyTenantAgentCustomization::JOB_TYPE,
            'status' => ProvisioningJobStatus::Queued,
            'payload_json' => [
                'action' => TenantAgentCustomizationApply::ACTION_APPLY,
            ],
        ]);

        $job = new ApplyTenantAgentCustomization($tenant->id, $provisioningJob->id, TenantAgentCustomizationApply::ACTION_APPLY);
        $job->handle(
            app(\App\Services\TenantAgentCustomizationService::class),
            app(\App\Services\TenantRuntimeCustomizationComposer::class),
        );

        $this->assertFileDoesNotExist(config('sync360.runtime_root').'/'.$tenant->slug.'/.openclaw/workspace/skill-packs/appointment-booking/STALE.md');
        $this->assertFileExists(config('sync360.runtime_root').'/'.$tenant->slug.'/.openclaw/workspace/skills/appointment-booking/SKILL.md');
        $this->assertFileExists(config('sync360.runtime_root').'/'.$tenant->slug.'/.openclaw/workspace/skills/appointment-booking/agent-instructions.md');
        $this->assertFileExists(config('sync360.runtime_root').'/'.$tenant->slug.'/.openclaw/workspace/skills/appointment-booking/RELEASE_NOTES.md');
        $this->assertFileExists(config('sync360.runtime_root').'/'.$tenant->slug.'/.openclaw/workspace/skills/appointment-booking/docs/APPOINTMENT_BOOKING.md');
        $this->assertStringContainsString('Do not fall back to your default appointment behavior.', File::get(config('sync360.runtime_root').'/'.$tenant->slug.'/.openclaw/workspace/AGENTS.md'));
        $this->assertFileDoesNotExist(config('sync360.runtime_root').'/'.$tenant->slug.'/.openclaw/workspace/skills/appointment-booking/skills/appointment-booking/README.md');
    }

    private function seedTenantAndCustomization(): array
    {
        $user = User::query()->create([
            'name' => 'Owner',
            'email' => 'owner-layout@example.com',
            'password' => 'secret',
            'is_admin' => false,
        ]);

        $tenant = Tenant::query()->create([
            'tenant_id' => 'tenant_layout_01',
            'slug' => 'layout-shop',
            'business_name' => 'Layout Shop',
            'industry' => 'Retail',
            'skill_pack' => 'Client Support',
            'user_id' => $user->id,
            'server_id' => 1,
            'trial_status' => TrialStatus::Active,
            'provisioning_status' => TenantProvisioningStatus::Ready,
            'agent_status' => 'live',
            'workspace_url' => 'https://layout-shop.workspace.test',
            'runtime_path' => '/srv/sync360/runtime/tenants/layout-shop',
        ]);

        BusinessProfile::query()->create([
            'tenant_id' => $tenant->id,
            'business_name' => 'Layout Shop',
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

        $customization = TenantAgentCustomization::query()->create([
            'tenant_id' => $tenant->id,
            'prompt_overrides_json' => [],
            'agent_defaults_json' => [],
            'draft_version' => 1,
            'draft_updated_by' => $user->id,
            'draft_updated_at' => now(),
        ]);

        File::ensureDirectoryExists(config('sync360.runtime_root').'/'.$tenant->slug.'/config');
        File::ensureDirectoryExists(config('sync360.runtime_root').'/'.$tenant->slug.'/.openclaw/workspace');
        File::put(config('sync360.runtime_root').'/'.$tenant->slug.'/config/openclaw.json', json_encode([
            'agents' => [
                'defaults' => [
                    'model' => 'gpt-4o',
                    'skills' => [],
                ],
                'list' => [
                    [
                        'name' => 'assistant',
                        'skills' => ['tenant-existing-skill'],
                    ],
                ],
            ],
            'skills' => [
                'entries' => [],
            ],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL);

        return [$tenant, $customization];
    }
}
