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
use App\Models\SkillCatalogVersion;
use App\Models\Tenant;
use App\Models\TenantAgentCustomization;
use App\Models\TenantAgentCustomizationApply;
use App\Models\TenantSkillAssignment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class ApplyTenantAgentCustomizationJobTest extends TestCase
{
    use RefreshDatabase;

    public function test_apply_job_writes_runtime_artifacts_updates_hash_and_records_audit_row(): void
    {
        [$tenant, $customization] = $this->seedTenantAndCustomization();

        $runner = new class implements DockerComposeRunner
        {
            public array $workspaceSyncs = [];

            public array $putFiles = [];

            public array $commands = [];

            public function syncRuntime(\App\Models\Server $server, string $localRuntimePath, string $remoteRuntimePath): void {}
            public function syncWorkspaceFiles(\App\Models\Server $server, string $localWorkspacePath, string $remoteWorkspacePath): void
            {
                $this->workspaceSyncs[] = compact('localWorkspacePath', 'remoteWorkspacePath');
            }
            public function httpRequest(\App\Models\Server $server, string $method, string $url, ?array $json = null, int $timeoutSeconds = 15, array $headers = []): array { return ['status' => 200, 'body' => '']; }
            public function putFile(\App\Models\Server $server, string $remotePath, string $contents, bool $sudo = false): void
            {
                $this->putFiles[] = compact('remotePath', 'contents');
            }
            public function removeFile(\App\Models\Server $server, string $remotePath, bool $sudo = false): void {}
            public function removeDirectory(\App\Models\Server $server, string $remotePath, bool $sudo = false): void {}
            public function runCommand(\App\Models\Server $server, string $command, bool $sudo = false): void
            {
                $this->commands[] = $command;
            }
            public function up(\App\Models\Server $server, string $composeFile, string $projectName): void {}
            public function down(\App\Models\Server $server, string $composeFile, string $projectName): void {}
            public function start(\App\Models\Server $server, string $composeFile, string $projectName): void {}
            public function stop(\App\Models\Server $server, string $composeFile, string $projectName): void {}
            public function isRunning(\App\Models\Server $server, string $composeFile, string $projectName): bool { return false; }
            public function isHostPortInUse(\App\Models\Server $server, int $port): bool { return false; }
            public function waitForHttpReady(\App\Models\Server $server, string $url, int $timeoutSeconds, int $pollIntervalMs): void {}
        };

        $this->instance(DockerComposeRunner::class, $runner);

        $analyticsSentinel = config('sync360.runtime_root').'/'.$tenant->slug.'/.openclaw/data/analytics/keep.txt';
        File::ensureDirectoryExists(dirname($analyticsSentinel));
        File::put($analyticsSentinel, 'keep analytics data');

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

        $customization->refresh();
        $provisioningJob->refresh();

        $skillPackFile = config('sync360.runtime_root').'/'.$tenant->slug.'/.openclaw/workspace/skills/hello-world/SKILL.md';
        $identityFile = config('sync360.runtime_root').'/'.$tenant->slug.'/.openclaw/workspace/IDENTITY.md';
        $analyticsDb = config('sync360.runtime_root').'/'.$tenant->slug.'/.openclaw/data/analytics/skill-events.sqlite';

        $this->assertFileExists($skillPackFile);
        $this->assertFileExists($identityFile);
        $this->assertFileExists($analyticsSentinel);
        $this->assertFileExists($analyticsDb);
        $this->assertStringContainsString('Admin identity notes', File::get($identityFile));
        $this->assertNotNull($customization->applied_snapshot_hash);
        $this->assertNotNull($customization->last_applied_input_snapshot_json);
        $this->assertSame('applied', $customization->last_apply_status);
        $this->assertSame(ProvisioningJobStatus::Completed, $provisioningJob->status);
        $this->assertCount(1, $runner->workspaceSyncs);
        $this->assertNotEmpty($runner->putFiles);
        $this->assertTrue(collect($runner->commands)->contains(
            fn (string $command): bool => str_contains($command, 'docker compose')
                && str_contains($command, 'restart')
        ));

        $applyLog = TenantAgentCustomizationApply::query()->latest('id')->first();

        $this->assertNotNull($applyLog);
        $this->assertSame(TenantAgentCustomizationApply::ACTION_APPLY, $applyLog->action);
        $this->assertSame(TenantAgentCustomizationApply::STATUS_APPLIED, $applyLog->status);
        $this->assertSame($customization->applied_snapshot_hash, $applyLog->after_output_hash);
    }

    public function test_revert_job_restores_last_applied_input_snapshot_before_composing(): void
    {
        [$tenant, $customization] = $this->seedTenantAndCustomization();

        $customization->forceFill([
            'prompt_overrides_json' => [
                'identity' => [
                    'mode' => 'append',
                    'content' => 'Changed draft',
                    'base_snapshot' => '# Identity'.PHP_EOL.PHP_EOL.'Base identity',
                ],
            ],
            'last_applied_input_snapshot_json' => [
                'prompt_overrides' => [
                    'identity' => [
                        'mode' => 'append',
                        'content' => 'Restored snapshot',
                        'base_snapshot' => '# Identity'.PHP_EOL.PHP_EOL.'Base identity',
                    ],
                ],
                'assigned_skills' => [
                    [
                        'skill_key' => 'hello-world',
                        'skill_catalog_version_id' => SkillCatalogVersion::query()->where('skill_key', 'hello-world')->value('id'),
                        'openclaw_skill_ids' => ['hello-world'],
                        'default_agent_skill_ids' => ['hello-world'],
                    ],
                ],
                'agent_defaults' => [],
            ],
        ])->save();

        $provisioningJob = ProvisioningJob::query()->create([
            'tenant_id' => $tenant->id,
            'job_type' => ApplyTenantAgentCustomization::JOB_TYPE,
            'status' => ProvisioningJobStatus::Queued,
            'payload_json' => [
                'action' => TenantAgentCustomizationApply::ACTION_REVERT,
            ],
        ]);

        $job = new ApplyTenantAgentCustomization($tenant->id, $provisioningJob->id, TenantAgentCustomizationApply::ACTION_REVERT);
        $job->handle(
            app(\App\Services\TenantAgentCustomizationService::class),
            app(\App\Services\TenantRuntimeCustomizationComposer::class),
        );

        $customization->refresh();

        $this->assertSame('Restored snapshot', data_get($customization->prompt_overrides_json, 'identity.content'));
        $this->assertSame(TenantAgentCustomizationApply::STATUS_APPLIED, $customization->last_apply_status);
        $this->assertDatabaseHas('tenant_agent_customization_applies', [
            'tenant_id' => $tenant->id,
            'action' => TenantAgentCustomizationApply::ACTION_REVERT,
        ]);
    }

    public function test_remote_apply_disables_removed_skill_in_config_without_deleting_remote_workspace_skill_folders(): void
    {
        [$tenant, $customization] = $this->seedTenantAndCustomization();

        $customization->forceFill([
            'last_applied_input_snapshot_json' => [
                'assigned_skills' => [
                    [
                        'skill_key' => 'hello-world',
                        'skill_catalog_version_id' => SkillCatalogVersion::query()->where('skill_key', 'hello-world')->value('id'),
                        'openclaw_skill_ids' => ['hello-world'],
                        'default_agent_skill_ids' => ['hello-world'],
                    ],
                ],
                'agent_defaults' => [],
            ],
        ])->save();

        TenantSkillAssignment::query()
            ->where('tenant_id', $tenant->id)
            ->update([
                'is_enabled' => false,
            ]);

        $this->app->detectEnvironment(fn (): string => 'production');

        $runner = new class implements DockerComposeRunner
        {
            public array $removedDirectories = [];

            public array $workspaceSyncs = [];

            public array $putFiles = [];

            public function syncRuntime(\App\Models\Server $server, string $localRuntimePath, string $remoteRuntimePath): void {}
            public function syncWorkspaceFiles(\App\Models\Server $server, string $localWorkspacePath, string $remoteWorkspacePath): void
            {
                $this->workspaceSyncs[] = compact('localWorkspacePath', 'remoteWorkspacePath');
            }
            public function httpRequest(\App\Models\Server $server, string $method, string $url, ?array $json = null, int $timeoutSeconds = 15, array $headers = []): array { return ['status' => 200, 'body' => '']; }
            public function putFile(\App\Models\Server $server, string $remotePath, string $contents, bool $sudo = false): void
            {
                $this->putFiles[] = compact('remotePath', 'contents');
            }
            public function removeFile(\App\Models\Server $server, string $remotePath, bool $sudo = false): void {}
            public function removeDirectory(\App\Models\Server $server, string $remotePath, bool $sudo = false): void
            {
                $this->removedDirectories[] = $remotePath;
            }
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

        $configUpload = collect($runner->putFiles)
            ->firstWhere('remotePath', '/srv/sync360/runtime/tenants/apply-shop/config/openclaw.json');

        $this->assertNotNull($configUpload);
        $config = json_decode($configUpload['contents'], true);

        $this->assertFalse(data_get($config, 'skills.entries.hello-world.enabled'));
        $this->assertSame([], $runner->removedDirectories);
        $this->assertNotEmpty($runner->workspaceSyncs);
    }

    public function test_remote_apply_initializes_skill_analytics_inside_tenant_container(): void
    {
        [$tenant] = $this->seedTenantAndCustomization();
        config()->set('sync360.infrastructure.driver', 'ssh');

        $runner = new class implements DockerComposeRunner
        {
            public array $commands = [];

            public function syncRuntime(\App\Models\Server $server, string $localRuntimePath, string $remoteRuntimePath): void {}
            public function syncWorkspaceFiles(\App\Models\Server $server, string $localWorkspacePath, string $remoteWorkspacePath): void {}
            public function httpRequest(\App\Models\Server $server, string $method, string $url, ?array $json = null, int $timeoutSeconds = 15, array $headers = []): array { return ['status' => 200, 'body' => '']; }
            public function putFile(\App\Models\Server $server, string $remotePath, string $contents, bool $sudo = false): void {}
            public function removeFile(\App\Models\Server $server, string $remotePath, bool $sudo = false): void {}
            public function removeDirectory(\App\Models\Server $server, string $remotePath, bool $sudo = false): void {}
            public function runCommand(\App\Models\Server $server, string $command, bool $sudo = false): void
            {
                $this->commands[] = $command;
            }
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

        $this->assertTrue(collect($runner->commands)->contains(
            fn (string $command): bool => str_contains($command, 'docker exec')
                && str_contains($command, 'sync360-apply-shop')
                && str_contains($command, 'log-skill-conversion --init-only')
        ));
    }

    public function test_remote_apply_failure_marks_job_failed_when_skill_analytics_initialization_fails(): void
    {
        [$tenant, $customization] = $this->seedTenantAndCustomization();
        config()->set('sync360.infrastructure.driver', 'ssh');

        $runner = new class implements DockerComposeRunner
        {
            public function syncRuntime(\App\Models\Server $server, string $localRuntimePath, string $remoteRuntimePath): void {}
            public function syncWorkspaceFiles(\App\Models\Server $server, string $localWorkspacePath, string $remoteWorkspacePath): void {}
            public function httpRequest(\App\Models\Server $server, string $method, string $url, ?array $json = null, int $timeoutSeconds = 15, array $headers = []): array { return ['status' => 200, 'body' => '']; }
            public function putFile(\App\Models\Server $server, string $remotePath, string $contents, bool $sudo = false): void {}
            public function removeFile(\App\Models\Server $server, string $remotePath, bool $sudo = false): void {}
            public function removeDirectory(\App\Models\Server $server, string $remotePath, bool $sudo = false): void {}
            public function runCommand(\App\Models\Server $server, string $command, bool $sudo = false): void
            {
                if (str_contains($command, 'log-skill-conversion --init-only')) {
                    throw new \RuntimeException('analytics init failed');
                }
            }
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

        try {
            $job = new ApplyTenantAgentCustomization($tenant->id, $provisioningJob->id, TenantAgentCustomizationApply::ACTION_APPLY);
            $job->handle(
                app(\App\Services\TenantAgentCustomizationService::class),
                app(\App\Services\TenantRuntimeCustomizationComposer::class),
            );
            $this->fail('Expected analytics initialization failure.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('analytics init failed', $exception->getMessage());
            $this->assertSame(ProvisioningJobStatus::Failed, $provisioningJob->fresh()->status);
            $this->assertSame('failed', $customization->fresh()->last_apply_status);
        }
    }

    private function seedTenantAndCustomization(): array
    {
        $user = User::query()->create([
            'name' => 'Owner',
            'email' => 'owner@example.com',
            'password' => 'secret',
            'is_admin' => false,
        ]);

        $tenant = Tenant::query()->create([
            'tenant_id' => 'tenant_customization_03',
            'slug' => 'apply-shop',
            'business_name' => 'Apply Shop',
            'industry' => 'Retail',
            'skill_pack' => 'Client Support',
            'user_id' => $user->id,
            'server_id' => 1,
            'trial_status' => TrialStatus::Active,
            'provisioning_status' => TenantProvisioningStatus::Ready,
            'agent_status' => 'live',
            'workspace_url' => 'https://apply-shop.workspace.test',
            'runtime_path' => '/srv/sync360/runtime/tenants/apply-shop',
        ]);

        BusinessProfile::query()->create([
            'tenant_id' => $tenant->id,
            'business_name' => 'Apply Shop',
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
        $catalogVersionId = SkillCatalogVersion::query()->where('skill_key', 'hello-world')->value('id');

        $customization = TenantAgentCustomization::query()->create([
            'tenant_id' => $tenant->id,
            'prompt_overrides_json' => [
                'identity' => [
                    'mode' => 'append',
                    'content' => 'Admin identity notes',
                    'base_snapshot' => "# Identity\n\nBase identity",
                ],
            ],
            'agent_defaults_json' => [
                'model' => 'gpt-4.1',
            ],
            'draft_version' => 1,
            'draft_updated_by' => $user->id,
            'draft_updated_at' => now(),
        ]);

        TenantSkillAssignment::query()->create([
            'tenant_id' => $tenant->id,
            'skill_catalog_version_id' => $catalogVersionId,
            'skill_key' => 'hello-world',
            'assigned_by' => $user->id,
            'assigned_at' => now(),
            'is_enabled' => true,
        ]);

        $runtimeRoot = config('sync360.runtime_root').'/'.$tenant->slug;
        File::ensureDirectoryExists($runtimeRoot.'/config');
        File::ensureDirectoryExists($runtimeRoot.'/.openclaw/workspace');
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
            'gateway' => [
                'auth' => [
                    'token' => 'keep-me',
                ],
            ],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL);

        return [$tenant, $customization];
    }
}
