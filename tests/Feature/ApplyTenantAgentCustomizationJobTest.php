<?php

namespace Tests\Feature;

use App\Contracts\DockerComposeRunner;
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
use App\Models\TenantSkillAssignment;
use App\Models\User;
use App\Services\TenantRuntimeSkillActivationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ApplyTenantAgentCustomizationJobTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->mock(TenantRuntimeSkillActivationService::class, function ($mock): void {
            $mock->shouldReceive('syncExpectedContract')
                ->andReturn([
                    'changed' => false,
                    'skill_set_changed' => false,
                    'contract' => [
                        'expected_skill_ids' => ['gog', 'hello-world'],
                    ],
                ]);
            $mock->shouldReceive('activateExpectedSkills')->andReturn([
                'expected_skill_ids' => ['gog', 'hello-world'],
                'missing_expected_skill_ids' => [],
            ]);
        });
    }

    public function test_apply_job_writes_runtime_artifacts_updates_hash_and_records_audit_row(): void
    {
        [$tenant, $customization] = $this->seedTenantAndCustomization();
        config()->set('sync360.infrastructure.driver', 'ssh');
        $analyticsDb = config('sync360.runtime_root').'/'.$tenant->slug.'/.openclaw/data/analytics/skill-events.sqlite';

        $this->mock(\App\Services\TenantSkillAnalyticsRuntimeService::class, function ($mock) use ($analyticsDb): void {
            $mock->shouldReceive('initializeTenant')
                ->once()
                ->andReturnUsing(function () use ($analyticsDb): array {
                    File::ensureDirectoryExists(dirname($analyticsDb));
                    File::put($analyticsDb, '');

                    return [
                        'initialized' => true,
                        'skill_count' => 1,
                    ];
                });
        });

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
            app(\App\Services\TenantSkillRolloutWorkspaceResyncService::class),
        );

        $customization->refresh();
        $provisioningJob->refresh();

        $skillPackFile = config('sync360.runtime_root').'/'.$tenant->slug.'/.openclaw/workspace/skills/hello-world/SKILL.md';
        $identityFile = config('sync360.runtime_root').'/'.$tenant->slug.'/.openclaw/workspace/IDENTITY.md';

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
        $this->assertTrue(collect($runner->putFiles)->contains(
            fn (array $upload): bool => $upload['remotePath'] === '/srv/sync360/runtime/tenants/apply-shop/.env'
                && str_contains($upload['contents'], 'OPENAI_API_KEY=sk-tenant-acme')
        ));
        $this->assertTrue(collect($runner->putFiles)->contains(
            fn (array $upload): bool => $upload['remotePath'] === '/srv/sync360/runtime/tenants/apply-shop/compose.yaml'
                && str_contains($upload['contents'], 'OPENAI_API_KEY: "sk-tenant-acme"')
        ));
        $this->assertTrue(collect($runner->commands)->contains(
            fn (string $command): bool => str_contains($command, 'docker compose')
                && (str_contains($command, 'restart') || str_contains($command, 'up -d --force-recreate'))
        ));

        $applyLog = TenantAgentCustomizationApply::query()->latest('id')->first();

        $this->assertNotNull($applyLog);
        $this->assertSame(TenantAgentCustomizationApply::ACTION_APPLY, $applyLog->action);
        $this->assertSame(TenantAgentCustomizationApply::STATUS_APPLIED, $applyLog->status);
        $this->assertSame($customization->applied_snapshot_hash, $applyLog->after_output_hash);
    }

    public function test_apply_job_records_binary_workspace_assets_without_json_encoding_failure(): void
    {
        [$tenant] = $this->seedTenantAndCustomization();

        $logoPath = 'tenant-business-profile-assets/'.$tenant->tenant_id.'/logo.png';
        Storage::disk('local')->put($logoPath, "\x89PNG\x0D\x0A\x1A\x0A\x00\x80binary-logo");

        $tenant->businessProfileFiles()->firstOrFail()->forceFill([
            'logo_storage_path' => $logoPath,
            'logo_original_filename' => 'logo.png',
            'logo_mime_type' => 'image/png',
            'logo_size_bytes' => Storage::disk('local')->size($logoPath),
            'logo_uploaded_at' => now(),
        ])->save();

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
            app(\App\Services\TenantSkillRolloutWorkspaceResyncService::class),
        );

        $applyLog = TenantAgentCustomizationApply::query()->latest('id')->firstOrFail();
        $logoDiagnostic = $applyLog->composed_output_json['workspace_files']['business-assets/logo.png'] ?? null;

        $this->assertSame(TenantAgentCustomizationApply::STATUS_APPLIED, $applyLog->status);
        $this->assertIsArray($logoDiagnostic);
        $this->assertSame('binary', $logoDiagnostic['kind'] ?? null);
        $this->assertSame(hash('sha256', "\x89PNG\x0D\x0A\x1A\x0A\x00\x80binary-logo"), $logoDiagnostic['sha256'] ?? null);
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
            app(\App\Services\TenantSkillRolloutWorkspaceResyncService::class),
        );

        $customization->refresh();

        $this->assertSame('Restored snapshot', data_get($customization->prompt_overrides_json, 'identity.content'));
        $this->assertSame(TenantAgentCustomizationApply::STATUS_APPLIED, $customization->last_apply_status);
        $this->assertDatabaseHas('tenant_agent_customization_applies', [
            'tenant_id' => $tenant->id,
            'action' => TenantAgentCustomizationApply::ACTION_REVERT,
        ]);
    }

    public function test_revert_job_restores_last_applied_runtime_api_key_override(): void
    {
        [$tenant, $customization] = $this->seedTenantAndCustomization();

        $customization->forceFill([
            'runtime_api_key_override' => 'sk-draft-override',
            'last_applied_runtime_api_key_override' => 'sk-applied-override',
            'last_applied_input_snapshot_json' => [
                'prompt_overrides' => [],
                'assigned_skills' => [
                    [
                        'skill_key' => 'hello-world',
                        'skill_catalog_version_id' => SkillCatalogVersion::query()->where('skill_key', 'hello-world')->value('id'),
                        'openclaw_skill_ids' => ['hello-world'],
                        'default_agent_skill_ids' => ['hello-world'],
                    ],
                ],
                'agent_defaults' => [
                    'model' => 'gpt-4.1',
                ],
                'runtime_api_key_override_enabled' => true,
            ],
        ])->save();

        $runner = new class implements DockerComposeRunner
        {
            public array $putFiles = [];

            public function syncRuntime(\App\Models\Server $server, string $localRuntimePath, string $remoteRuntimePath): void {}
            public function syncWorkspaceFiles(\App\Models\Server $server, string $localWorkspacePath, string $remoteWorkspacePath): void {}
            public function httpRequest(\App\Models\Server $server, string $method, string $url, ?array $json = null, int $timeoutSeconds = 15, array $headers = []): array { return ['status' => 200, 'body' => '']; }
            public function putFile(\App\Models\Server $server, string $remotePath, string $contents, bool $sudo = false): void
            {
                $this->putFiles[] = compact('remotePath', 'contents');
            }
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
                'action' => TenantAgentCustomizationApply::ACTION_REVERT,
            ],
        ]);

        $job = new ApplyTenantAgentCustomization($tenant->id, $provisioningJob->id, TenantAgentCustomizationApply::ACTION_REVERT);
        $job->handle(
            app(\App\Services\TenantAgentCustomizationService::class),
            app(\App\Services\TenantRuntimeCustomizationComposer::class),
            app(\App\Services\TenantSkillRolloutWorkspaceResyncService::class),
        );

        $customization->refresh();

        $this->assertSame('sk-applied-override', $customization->runtime_api_key_override);
        $this->assertTrue(collect($runner->putFiles)->contains(
            fn (array $upload): bool => $upload['remotePath'] === '/srv/sync360/runtime/tenants/apply-shop/.env'
                && str_contains($upload['contents'], 'OPENAI_API_KEY=sk-applied-override')
        ));
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
            app(\App\Services\TenantSkillRolloutWorkspaceResyncService::class),
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
            app(\App\Services\TenantSkillRolloutWorkspaceResyncService::class),
        );

        $this->assertTrue(collect($runner->commands)->contains(
            fn (string $command): bool => str_contains($command, 'docker exec')
                && str_contains($command, 'sync360-apply-shop')
                && str_contains($command, 'log-skill-conversion --init-only')
        ));
    }

    public function test_apply_rotates_agent_sessions_when_model_override_changes_without_skill_set_change(): void
    {
        [$tenant, $customization] = $this->seedTenantAndCustomization();
        config()->set('sync360.infrastructure.driver', 'ssh');

        $customization->forceFill([
            'agent_defaults_json' => [
                'model' => 'claude-sonnet-4-6',
            ],
            'last_applied_input_snapshot_json' => [
                'prompt_overrides' => [],
                'assigned_skills' => [
                    [
                        'skill_key' => 'hello-world',
                        'skill_catalog_version_id' => SkillCatalogVersion::query()->where('skill_key', 'hello-world')->value('id'),
                        'openclaw_skill_ids' => ['hello-world'],
                        'default_agent_skill_ids' => ['hello-world'],
                    ],
                ],
                'agent_defaults' => [
                    'model' => 'gpt-4o-mini',
                ],
            ],
            'last_applied_runtime_api_key_override' => null,
        ])->save();

        $this->mock(TenantRuntimeSkillActivationService::class, function ($mock): void {
            $mock->shouldReceive('syncExpectedContract')
                ->once()
                ->andReturn([
                    'changed' => false,
                    'skill_set_changed' => false,
                    'contract' => [
                        'expected_skill_ids' => ['gog', 'hello-world'],
                    ],
                ]);
            $mock->shouldReceive('activateExpectedSkills')
                ->once()
                ->withArgs(function (Tenant $tenant, bool $recreate, bool $forceSessionRotation): bool {
                    return $tenant->slug === 'apply-shop'
                        && $recreate === false
                        && $forceSessionRotation === true;
                })
                ->andReturn([
                    'expected_skill_ids' => ['gog', 'hello-world'],
                    'missing_expected_skill_ids' => [],
                ]);
        });

        $this->mock(\App\Services\TenantSkillAnalyticsRuntimeService::class, function ($mock): void {
            $mock->shouldReceive('initializeTenant')
                ->once()
                ->andReturn([
                    'initialized' => true,
                    'skill_count' => 1,
                ]);
        });

        $runner = new class implements DockerComposeRunner
        {
            public function syncRuntime(\App\Models\Server $server, string $localRuntimePath, string $remoteRuntimePath): void {}
            public function syncWorkspaceFiles(\App\Models\Server $server, string $localWorkspacePath, string $remoteWorkspacePath): void {}
            public function httpRequest(\App\Models\Server $server, string $method, string $url, ?array $json = null, int $timeoutSeconds = 15, array $headers = []): array { return ['status' => 200, 'body' => '']; }
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
            app(\App\Services\TenantSkillRolloutWorkspaceResyncService::class),
        );

        $this->assertSame(ProvisioningJobStatus::Completed, $provisioningJob->fresh()->status);
    }

    public function test_successful_live_skill_rollout_apply_queues_follow_up_workspace_resync(): void
    {
        Queue::fake([ResyncLiveTenantWorkspaceAfterSkillRollout::class]);

        [$tenant] = $this->seedTenantAndCustomization();
        config()->set('sync360.infrastructure.driver', 'ssh');

        $runner = new class implements DockerComposeRunner
        {
            public function syncRuntime(\App\Models\Server $server, string $localRuntimePath, string $remoteRuntimePath): void {}
            public function syncWorkspaceFiles(\App\Models\Server $server, string $localWorkspacePath, string $remoteWorkspacePath): void {}
            public function httpRequest(\App\Models\Server $server, string $method, string $url, ?array $json = null, int $timeoutSeconds = 15, array $headers = []): array { return ['status' => 200, 'body' => '']; }
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

        $version = SkillCatalogVersion::query()->where('skill_key', 'hello-world')->firstOrFail();
        $provisioningJob = ProvisioningJob::query()->create([
            'tenant_id' => $tenant->id,
            'job_type' => ApplyTenantAgentCustomization::JOB_TYPE,
            'status' => ProvisioningJobStatus::Queued,
            'payload_json' => [
                'action' => TenantAgentCustomizationApply::ACTION_APPLY,
                'source' => 'skill_rollout',
                'skill_key' => 'hello-world',
                'skill_catalog_version_id' => $version->id,
            ],
        ]);

        $job = new ApplyTenantAgentCustomization($tenant->id, $provisioningJob->id, TenantAgentCustomizationApply::ACTION_APPLY);
        $job->handle(
            app(\App\Services\TenantAgentCustomizationService::class),
            app(\App\Services\TenantRuntimeCustomizationComposer::class),
            app(\App\Services\TenantSkillRolloutWorkspaceResyncService::class),
        );

        Queue::assertPushed(ResyncLiveTenantWorkspaceAfterSkillRollout::class, function (ResyncLiveTenantWorkspaceAfterSkillRollout $job) use ($tenant, $version): bool {
            return $job->tenantId === $tenant->id
                && $job->skillKey === 'hello-world'
                && $job->skillCatalogVersionId === $version->id
                && $job->sourceProvisioningJobId > 0;
        });
    }

    public function test_recovery_command_backfills_missing_rollout_workspace_resync_jobs(): void
    {
        Queue::fake([ResyncLiveTenantWorkspaceAfterSkillRollout::class]);

        [$tenant] = $this->seedTenantAndCustomization();
        $version = SkillCatalogVersion::query()->where('skill_key', 'hello-world')->firstOrFail();
        $applyJob = ProvisioningJob::query()->create([
            'tenant_id' => $tenant->id,
            'job_type' => ApplyTenantAgentCustomization::JOB_TYPE,
            'status' => ProvisioningJobStatus::Completed,
            'started_at' => now()->subMinute(),
            'completed_at' => now()->subSeconds(30),
            'payload_json' => [
                'action' => TenantAgentCustomizationApply::ACTION_APPLY,
                'source' => 'skill_rollout',
                'skill_key' => 'hello-world',
                'skill_catalog_version_id' => $version->id,
            ],
        ]);

        Artisan::call('sync360:recover-missing-rollout-resyncs', [
            '--limit' => 10,
        ]);

        $resyncJob = ProvisioningJob::query()
            ->where('tenant_id', $tenant->id)
            ->where('job_type', ResyncLiveTenantWorkspaceAfterSkillRollout::JOB_TYPE)
            ->latest('id')
            ->first();

        $this->assertNotNull($resyncJob);
        $this->assertSame(ProvisioningJobStatus::Queued, $resyncJob->status);
        $this->assertSame('skill_rollout_auto_resync', data_get($resyncJob->payload_json, 'source'));
        $this->assertSame($applyJob->id, data_get($resyncJob->payload_json, 'source_provisioning_job_id'));

        Queue::assertPushed(ResyncLiveTenantWorkspaceAfterSkillRollout::class, function (ResyncLiveTenantWorkspaceAfterSkillRollout $job) use ($tenant, $version, $applyJob): bool {
            return $job->tenantId === $tenant->id
                && $job->skillKey === 'hello-world'
                && $job->skillCatalogVersionId === $version->id
                && $job->sourceProvisioningJobId === $applyJob->id;
        });
    }

    public function test_non_rollout_apply_does_not_queue_follow_up_workspace_resync(): void
    {
        Queue::fake([ResyncLiveTenantWorkspaceAfterSkillRollout::class]);

        [$tenant] = $this->seedTenantAndCustomization();
        config()->set('sync360.infrastructure.driver', 'ssh');

        $runner = new class implements DockerComposeRunner
        {
            public function syncRuntime(\App\Models\Server $server, string $localRuntimePath, string $remoteRuntimePath): void {}
            public function syncWorkspaceFiles(\App\Models\Server $server, string $localWorkspacePath, string $remoteWorkspacePath): void {}
            public function httpRequest(\App\Models\Server $server, string $method, string $url, ?array $json = null, int $timeoutSeconds = 15, array $headers = []): array { return ['status' => 200, 'body' => '']; }
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

        $version = SkillCatalogVersion::query()->where('skill_key', 'hello-world')->firstOrFail();
        $provisioningJob = ProvisioningJob::query()->create([
            'tenant_id' => $tenant->id,
            'job_type' => ApplyTenantAgentCustomization::JOB_TYPE,
            'status' => ProvisioningJobStatus::Queued,
            'payload_json' => [
                'action' => TenantAgentCustomizationApply::ACTION_APPLY,
                'source' => 'tenant_apply',
                'skill_key' => 'hello-world',
                'skill_catalog_version_id' => $version->id,
            ],
        ]);

        $job = new ApplyTenantAgentCustomization($tenant->id, $provisioningJob->id, TenantAgentCustomizationApply::ACTION_APPLY);
        $job->handle(
            app(\App\Services\TenantAgentCustomizationService::class),
            app(\App\Services\TenantRuntimeCustomizationComposer::class),
            app(\App\Services\TenantSkillRolloutWorkspaceResyncService::class),
        );

        Queue::assertNothingPushed();
    }

    public function test_non_live_rollout_apply_does_not_queue_follow_up_workspace_resync(): void
    {
        Queue::fake([ResyncLiveTenantWorkspaceAfterSkillRollout::class]);

        [$tenant] = $this->seedTenantAndCustomization();
        config()->set('sync360.infrastructure.driver', 'ssh');

        $runner = new class implements DockerComposeRunner
        {
            public function syncRuntime(\App\Models\Server $server, string $localRuntimePath, string $remoteRuntimePath): void {}
            public function syncWorkspaceFiles(\App\Models\Server $server, string $localWorkspacePath, string $remoteWorkspacePath): void {}
            public function httpRequest(\App\Models\Server $server, string $method, string $url, ?array $json = null, int $timeoutSeconds = 15, array $headers = []): array { return ['status' => 200, 'body' => '']; }
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

        $tenant->forceFill([
            'agent_status' => 'offline',
        ])->save();

        $version = SkillCatalogVersion::query()->where('skill_key', 'hello-world')->firstOrFail();
        $provisioningJob = ProvisioningJob::query()->create([
            'tenant_id' => $tenant->id,
            'job_type' => ApplyTenantAgentCustomization::JOB_TYPE,
            'status' => ProvisioningJobStatus::Queued,
            'payload_json' => [
                'action' => TenantAgentCustomizationApply::ACTION_APPLY,
                'source' => 'skill_rollout',
                'skill_key' => 'hello-world',
                'skill_catalog_version_id' => $version->id,
            ],
        ]);

        $job = new ApplyTenantAgentCustomization($tenant->id, $provisioningJob->id, TenantAgentCustomizationApply::ACTION_APPLY);
        $job->handle(
            app(\App\Services\TenantAgentCustomizationService::class),
            app(\App\Services\TenantRuntimeCustomizationComposer::class),
            app(\App\Services\TenantSkillRolloutWorkspaceResyncService::class),
        );

        Queue::assertNothingPushed();
    }

    public function test_non_workspace_runtime_rollout_apply_does_not_queue_follow_up_workspace_resync(): void
    {
        Queue::fake([ResyncLiveTenantWorkspaceAfterSkillRollout::class]);

        [$tenant] = $this->seedTenantAndCustomization();
        config()->set('sync360.infrastructure.driver', 'ssh');

        $runner = new class implements DockerComposeRunner
        {
            public function syncRuntime(\App\Models\Server $server, string $localRuntimePath, string $remoteRuntimePath): void {}
            public function syncWorkspaceFiles(\App\Models\Server $server, string $localWorkspacePath, string $remoteWorkspacePath): void {}
            public function httpRequest(\App\Models\Server $server, string $method, string $url, ?array $json = null, int $timeoutSeconds = 15, array $headers = []): array { return ['status' => 200, 'body' => '']; }
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

        $version = SkillCatalogVersion::query()->where('skill_key', 'hello-world')->firstOrFail();
        $version->forceFill([
            'manifest_json' => array_merge($version->manifest_json ?? [], [
                'runtime_type' => 'openclaw_native',
            ]),
        ])->save();

        $provisioningJob = ProvisioningJob::query()->create([
            'tenant_id' => $tenant->id,
            'job_type' => ApplyTenantAgentCustomization::JOB_TYPE,
            'status' => ProvisioningJobStatus::Queued,
            'payload_json' => [
                'action' => TenantAgentCustomizationApply::ACTION_APPLY,
                'source' => 'skill_rollout',
                'skill_key' => 'hello-world',
                'skill_catalog_version_id' => $version->id,
            ],
        ]);

        $job = new ApplyTenantAgentCustomization($tenant->id, $provisioningJob->id, TenantAgentCustomizationApply::ACTION_APPLY);
        $job->handle(
            app(\App\Services\TenantAgentCustomizationService::class),
            app(\App\Services\TenantRuntimeCustomizationComposer::class),
            app(\App\Services\TenantSkillRolloutWorkspaceResyncService::class),
        );

        Queue::assertNothingPushed();
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
                app(\App\Services\TenantSkillRolloutWorkspaceResyncService::class),
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
            'assigned_port' => 4100,
            'workspace_url' => 'https://apply-shop.workspace.test',
            'runtime_path' => '/srv/sync360/runtime/tenants/apply-shop',
            'litellm_virtual_key' => 'sk-tenant-acme',
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
        File::put($runtimeRoot.'/.env', implode(PHP_EOL, [
            'OPENCLAW_GATEWAY_TOKEN=keep-me',
            'OPENAI_API_KEY=sk-tenant-acme',
            'OPENAI_BASE_URL=https://litellm.stylesoftware.co.nz',
            '',
        ]));
        File::put($runtimeRoot.'/compose.yaml', implode(PHP_EOL, [
            'services:',
            '  openclaw-gateway:',
            '    image: ghcr.io/openclaw/openclaw:latest',
            '    environment:',
            '      OPENAI_API_KEY: "sk-tenant-acme"',
            '      OPENAI_BASE_URL: "https://litellm.stylesoftware.co.nz"',
            '',
        ]));
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
