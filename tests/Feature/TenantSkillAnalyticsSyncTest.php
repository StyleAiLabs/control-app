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
use App\Services\TenantSkillAnalyticsRuntimeService;
use App\Services\TenantSkillAnalyticsRuntimeStorageService;
use App\Models\User;
use App\Services\TenantRuntimeCustomizationComposer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use PDO;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class TenantSkillAnalyticsSyncTest extends TestCase
{
    use RefreshDatabase;

    public function test_runtime_helper_logs_conversion_events_and_sync_populates_tenant_and_admin_analytics_views(): void
    {
        [$tenant, $owner] = $this->seedTenant();
        $admin = User::query()->create([
            'name' => 'Admin',
            'email' => 'analytics-admin@example.com',
            'password' => 'secret',
            'is_admin' => true,
        ]);

        $this->artisan('sync360:skills:import')->assertExitCode(0);
        $this->assignHelloWorldSkill($tenant, $owner);
        $this->materializeWorkspaceArtifacts($tenant);

        $workspacePath = config('sync360.runtime_root').'/'.$tenant->slug.'/.openclaw/workspace';
        $payload = json_encode([
            'event_id' => 'hello-event-001',
            'occurred_at' => '2026-04-20T10:15:00+00:00',
            'session_id' => 'session-001',
            'customer_label' => 'Jane Doe',
            'contact_masked' => 'j***@example.com',
            'outcome' => [
                'greeting' => 'Hello, world!',
            ],
        ], JSON_UNESCAPED_SLASHES);

        $process = new Process([
            'sh',
            '.sync360/bin/log-skill-conversion',
            '--skill',
            'hello-world',
            '--conversion-id',
            'hello-ref-001',
            '--payload-json',
            $payload,
        ], $workspacePath);
        $process->mustRun();
        $result = json_decode(trim($process->getOutput()), true);

        $this->assertSame([
            'ok' => true,
            'mode' => 'log',
            'event_id' => 'hello-event-001',
            'conversion_id' => 'hello-ref-001',
            'skill_key' => 'hello-world',
            'inserted' => true,
        ], array_intersect_key($result, array_flip(['ok', 'mode', 'event_id', 'conversion_id', 'skill_key', 'inserted'])));
        $this->assertStringEndsWith('/.openclaw/data/analytics/skill-events.sqlite', $result['db_path']);

        $duplicate = new Process([
            'sh',
            '.sync360/bin/log-skill-conversion',
            '--skill',
            'hello-world',
            '--conversion-id',
            'hello-ref-001',
            '--payload-json',
            $payload,
        ], $workspacePath);
        $duplicate->mustRun();
        $duplicateResult = json_decode(trim($duplicate->getOutput()), true);

        $this->assertTrue($duplicateResult['ok']);
        $this->assertFalse($duplicateResult['inserted']);

        $this->artisan('sync360:sync-skill-conversions', ['tenantSelector' => $tenant->slug])
            ->assertExitCode(0)
            ->expectsOutputToContain('Imported 1');

        $this->assertDatabaseHas('tenant_skill_conversion_events', [
            'tenant_id' => $tenant->id,
            'event_id' => 'hello-event-001',
            'skill_key' => 'hello-world',
            'skill_version' => '1.0.5',
            'conversion_type' => 'hello_world_completed',
            'conversion_id' => 'hello-ref-001',
            'customer_label' => 'Jane Doe',
            'contact_masked' => 'j***@example.com',
            'human_effort_minutes' => 1,
            'agent_effort_minutes' => 1,
            'productivity_score' => 1,
        ]);
        $this->assertDatabaseHas('tenant_skill_analytics_sync_states', [
            'tenant_id' => $tenant->id,
            'last_runtime_row_id' => 1,
        ]);

        $this->actingAs($owner)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Skill Outcomes')
            ->assertSee('Estimated Time Saved')
            ->assertSee('Productivity Score')
            ->assertSee('Hello World')
            ->assertSee('1 successful conversion')
            ->assertDontSee('Estimated Value Created');

        $this->actingAs($admin)
            ->get(route('admin.analytics.skills'))
            ->assertOk()
            ->assertSee('Skill Analytics')
            ->assertSee('Hello World')
            ->assertSee('Acme Analytics')
            ->assertSee('hello-ref-001');

        $this->actingAs($admin)
            ->get(route('admin.tenants.show', ['tenant' => $tenant, 'tab' => 'analytics']))
            ->assertOk()
            ->assertSee('Estimated Skill Impact')
            ->assertSee('hello-ref-001')
            ->assertSee('session-001');
    }

    public function test_runtime_helper_init_only_creates_empty_sqlite_schema(): void
    {
        [$tenant, $owner] = $this->seedTenant('analytics-init', 'Analytics Init');

        $this->artisan('sync360:skills:import')->assertExitCode(0);
        $this->assignHelloWorldSkill($tenant, $owner);
        $this->materializeWorkspaceArtifacts($tenant);

        $workspacePath = config('sync360.runtime_root').'/'.$tenant->slug.'/.openclaw/workspace';
        $dbPath = config('sync360.runtime_root').'/'.$tenant->slug.'/.openclaw/data/analytics/skill-events.sqlite';

        $this->assertFileDoesNotExist($dbPath);

        $process = new Process([
            'sh',
            '.sync360/bin/log-skill-conversion',
            '--init-only',
        ], $workspacePath);
        $process->mustRun();
        $result = json_decode(trim($process->getOutput()), true);

        $this->assertSame([
            'ok' => true,
            'mode' => 'init-only',
            'skill_count' => 1,
        ], array_intersect_key($result, array_flip(['ok', 'mode', 'skill_count'])));
        $this->assertSame($dbPath, $result['db_path']);
        $this->assertFileExists($dbPath);

        $pdo = new PDO('sqlite:'.$dbPath);
        $this->assertSame(0, (int) $pdo->query('SELECT COUNT(*) FROM skill_conversion_events')->fetchColumn());
    }

    public function test_init_skill_analytics_command_initializes_local_runtime_database(): void
    {
        [$tenant, $owner] = $this->seedTenant('analytics-command', 'Analytics Command');

        $this->artisan('sync360:skills:import')->assertExitCode(0);
        $this->assignHelloWorldSkill($tenant, $owner);
        $this->materializeWorkspaceArtifacts($tenant);

        $dbPath = config('sync360.runtime_root').'/'.$tenant->slug.'/.openclaw/data/analytics/skill-events.sqlite';

        $this->assertFileDoesNotExist($dbPath);

        $this->artisan('sync360:init-skill-analytics', ['tenantSelector' => $tenant->slug])
            ->assertExitCode(0)
            ->expectsOutputToContain('Skill analytics initialization finished');

        $this->assertFileExists($dbPath);
    }

    public function test_sync_warns_when_analytics_enabled_tenant_has_no_runtime_database(): void
    {
        [$tenant, $owner] = $this->seedTenant('analytics-missing-db', 'Analytics Missing DB');

        $this->artisan('sync360:skills:import')->assertExitCode(0);
        $this->assignHelloWorldSkill($tenant, $owner);
        $this->materializeWorkspaceArtifacts($tenant);

        Log::spy();

        $this->artisan('sync360:sync-skill-conversions', ['tenantSelector' => $tenant->slug])
            ->assertExitCode(0)
            ->expectsOutputToContain('Missing runtime DBs 1');

        Log::shouldHaveReceived('warning')
            ->once()
            ->withArgs(fn (string $message, array $context): bool => $message === 'sync360:sync-skill-conversions found analytics-enabled tenant without a runtime SQLite database.'
                && ($context['tenant_slug'] ?? null) === $tenant->slug
                && str_ends_with((string) ($context['runtime_db_path'] ?? ''), '/.openclaw/data/analytics/skill-events.sqlite'));
    }

    public function test_sync_skips_malformed_rows_advances_cursor_and_prunes_old_synced_runtime_rows(): void
    {
        [$tenant, $owner] = $this->seedTenant('analytics-prune', 'Analytics Prune');

        $this->artisan('sync360:skills:import')->assertExitCode(0);
        $this->assignHelloWorldSkill($tenant, $owner);
        $this->materializeWorkspaceArtifacts($tenant);

        $dbPath = config('sync360.runtime_root').'/'.$tenant->slug.'/.openclaw/data/analytics/skill-events.sqlite';
        File::ensureDirectoryExists(dirname($dbPath));

        $pdo = new PDO('sqlite:'.$dbPath);
        $pdo->exec('PRAGMA journal_mode=WAL;');
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS skill_conversion_events (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                event_id TEXT NOT NULL UNIQUE,
                skill_key TEXT NOT NULL,
                skill_version TEXT NOT NULL,
                event_type TEXT NOT NULL,
                conversion_type TEXT NOT NULL,
                conversion_id TEXT NOT NULL,
                occurred_at TEXT NOT NULL,
                session_id TEXT NULL,
                customer_label TEXT NOT NULL,
                contact_masked TEXT NULL,
                estimated_value_amount REAL NULL,
                currency TEXT NULL,
                effort_override_json TEXT NULL,
                outcome_json TEXT NOT NULL,
                created_at TEXT NOT NULL
            )'
        );

        $statement = $pdo->prepare('
            INSERT INTO skill_conversion_events (
                event_id, skill_key, skill_version, event_type, conversion_type, conversion_id, occurred_at,
                session_id, customer_label, contact_masked, estimated_value_amount, currency, effort_override_json,
                outcome_json, created_at
            ) VALUES (
                :event_id, :skill_key, :skill_version, :event_type, :conversion_type, :conversion_id, :occurred_at,
                :session_id, :customer_label, :contact_masked, :estimated_value_amount, :currency, :effort_override_json,
                :outcome_json, :created_at
            )
        ');

        $statement->execute([
            'event_id' => 'broken-event-001',
            'skill_key' => 'hello-world',
            'skill_version' => '1.0.5',
            'event_type' => 'conversion_succeeded',
            'conversion_type' => 'hello_world_completed',
            'conversion_id' => 'broken-ref',
            'occurred_at' => now()->subDays(10)->toIso8601String(),
            'session_id' => null,
            'customer_label' => 'Broken Row',
            'contact_masked' => null,
            'estimated_value_amount' => null,
            'currency' => null,
            'effort_override_json' => '{',
            'outcome_json' => '{',
            'created_at' => now()->subDays(10)->toIso8601String(),
        ]);

        $this->artisan('sync360:sync-skill-conversions', ['tenantSelector' => $tenant->slug])
            ->assertExitCode(0)
            ->expectsOutputToContain('Imported 0');

        $this->assertDatabaseMissing('tenant_skill_conversion_events', [
            'tenant_id' => $tenant->id,
            'event_id' => 'broken-event-001',
        ]);
        $this->assertDatabaseHas('tenant_skill_analytics_sync_states', [
            'tenant_id' => $tenant->id,
            'last_runtime_row_id' => 1,
        ]);

        $remaining = (int) $pdo->query('SELECT COUNT(*) FROM skill_conversion_events')->fetchColumn();
        $this->assertSame(0, $remaining);
    }

    public function test_remote_sync_imports_rows_without_host_sqlite3_and_prunes_with_runtime_storage_service(): void
    {
        [$tenant, $owner] = $this->seedTenant('analytics-remote', 'Analytics Remote');
        config()->set('sync360.infrastructure.driver', 'ssh');

        $this->artisan('sync360:skills:import')->assertExitCode(0);
        $this->assignHelloWorldSkill($tenant, $owner);

        $this->mock(TenantSkillAnalyticsRuntimeService::class, function ($mock): void {
            $mock->shouldReceive('tenantHasAnalyticsSkills')->andReturn(true);
            $mock->shouldReceive('runtimeDatabaseExists')->andReturn(true);
        });

        $this->mock(TenantSkillAnalyticsRuntimeStorageService::class, function ($mock): void {
            $mock->shouldReceive('rowsForTenant')
                ->once()
                ->andReturn([
                    [
                        'id' => 7,
                        'event_id' => 'remote-event-001',
                        'skill_key' => 'hello-world',
                        'skill_version' => '1.0.5',
                        'event_type' => 'conversion_succeeded',
                        'conversion_type' => 'hello_world_completed',
                        'conversion_id' => 'remote-ref-001',
                        'occurred_at' => '2026-04-21T05:10:00+00:00',
                        'session_id' => 'remote-session-001',
                        'customer_label' => 'Remote Jane',
                        'contact_masked' => 'r***@example.com',
                        'estimated_value_amount' => null,
                        'currency' => null,
                        'effort_override_json' => null,
                        'outcome_json' => json_encode(['greeting' => 'Hello remotely'], JSON_UNESCAPED_SLASHES),
                        'created_at' => '2026-04-21T05:10:00+00:00',
                    ],
                ]);
            $mock->shouldReceive('pruneRows')
                ->once()
                ->withArgs(fn (Tenant $tenant, int $lastRuntimeRowId, string $cutoff): bool => $tenant->slug === 'analytics-remote' && $lastRuntimeRowId === 7 && $cutoff !== '')
                ->andReturn(1);
        });

        $this->artisan('sync360:sync-skill-conversions', ['tenantSelector' => $tenant->slug])
            ->assertExitCode(0)
            ->expectsOutputToContain('Imported 1');

        $this->assertDatabaseHas('tenant_skill_conversion_events', [
            'tenant_id' => $tenant->id,
            'event_id' => 'remote-event-001',
            'conversion_id' => 'remote-ref-001',
            'skill_key' => 'hello-world',
        ]);
        $this->assertDatabaseHas('tenant_skill_analytics_sync_states', [
            'tenant_id' => $tenant->id,
            'last_runtime_row_id' => 7,
            'last_error_message' => null,
        ]);
    }

    public function test_sync_continues_after_one_remote_tenant_fails_and_records_failure_state(): void
    {
        [$tenantOne, $ownerOne] = $this->seedTenant('analytics-good', 'Analytics Good');
        [$tenantTwo, $ownerTwo] = $this->seedTenant('analytics-bad', 'Analytics Bad');
        config()->set('sync360.infrastructure.driver', 'ssh');

        $this->artisan('sync360:skills:import')->assertExitCode(0);
        $this->assignHelloWorldSkill($tenantOne, $ownerOne);
        $this->assignHelloWorldSkill($tenantTwo, $ownerTwo);

        $this->mock(TenantSkillAnalyticsRuntimeService::class, function ($mock): void {
            $mock->shouldReceive('tenantHasAnalyticsSkills')->andReturn(false);
        });

        $this->mock(TenantSkillAnalyticsRuntimeStorageService::class, function ($mock) use ($tenantOne, $tenantTwo): void {
            $mock->shouldReceive('rowsForTenant')
                ->once()
                ->withArgs(fn (Tenant $tenant, int $afterRowId): bool => $tenant->is($tenantOne) && $afterRowId === 0)
                ->andReturn([
                    [
                        'id' => 1,
                        'event_id' => 'good-event-001',
                        'skill_key' => 'hello-world',
                        'skill_version' => '1.0.5',
                        'event_type' => 'conversion_succeeded',
                        'conversion_type' => 'hello_world_completed',
                        'conversion_id' => 'good-ref-001',
                        'occurred_at' => '2026-04-21T05:20:00+00:00',
                        'session_id' => null,
                        'customer_label' => 'Good Tenant',
                        'contact_masked' => null,
                        'estimated_value_amount' => null,
                        'currency' => null,
                        'effort_override_json' => null,
                        'outcome_json' => json_encode(['greeting' => 'ok'], JSON_UNESCAPED_SLASHES),
                        'created_at' => '2026-04-21T05:20:00+00:00',
                    ],
                ]);
            $mock->shouldReceive('rowsForTenant')
                ->once()
                ->withArgs(fn (Tenant $tenant, int $afterRowId): bool => $tenant->is($tenantTwo) && $afterRowId === 0)
                ->andThrow(new \RuntimeException('docker exec node sqlite failed'));
            $mock->shouldReceive('pruneRows')
                ->once()
                ->withArgs(fn (Tenant $tenant, int $lastRuntimeRowId, string $cutoff): bool => $tenant->is($tenantOne) && $lastRuntimeRowId === 1 && $cutoff !== '')
                ->andReturn(0);
        });

        Log::spy();

        $this->artisan('sync360:sync-skill-conversions')
            ->assertExitCode(0)
            ->expectsOutputToContain('Imported 1');

        $this->assertDatabaseHas('tenant_skill_conversion_events', [
            'tenant_id' => $tenantOne->id,
            'event_id' => 'good-event-001',
        ]);
        $this->assertDatabaseHas('tenant_skill_analytics_sync_states', [
            'tenant_id' => $tenantTwo->id,
            'last_error_message' => 'docker exec node sqlite failed',
        ]);

        Log::shouldHaveReceived('warning')
            ->withArgs(fn (string $message, array $context): bool => $message === 'sync360:sync-skill-conversions failed for tenant.'
                && ($context['tenant_slug'] ?? null) === $tenantTwo->slug
                && ($context['error'] ?? null) === 'docker exec node sqlite failed')
            ->once();
    }

    /**
     * @return array{0: Tenant, 1: User}
     */
    private function seedTenant(string $slug = 'acme-analytics', string $businessName = 'Acme Analytics'): array
    {
        $owner = User::query()->create([
            'name' => $businessName.' Owner',
            'email' => $slug.'@example.com',
            'password' => 'secret',
            'is_admin' => false,
        ]);

        $tenant = Tenant::query()->create([
            'tenant_id' => 'tenant_'.$slug,
            'slug' => $slug,
            'business_name' => $businessName,
            'industry' => 'Professional Services',
            'skill_pack' => 'Operations Core',
            'user_id' => $owner->id,
            'server_id' => \App\Models\Server::query()->firstOrFail()->id,
            'trial_status' => TrialStatus::Active,
            'provisioning_status' => TenantProvisioningStatus::Ready,
            'onboarding_status' => 'complete',
            'onboarding_step' => 7,
            'agent_status' => 'live',
            'workspace_url' => 'https://'.$slug.'.workspace.test',
            'runtime_path' => '/srv/sync360/runtime/tenants/'.$slug,
            'channel' => 'telegram',
            'tone' => 'professional',
            'capabilities' => ['appointments'],
        ]);

        BusinessProfile::query()->create([
            'tenant_id' => $tenant->id,
            'business_name' => $businessName,
            'industry' => 'Professional Services',
            'description' => 'Helps customers book consultations.',
            'services' => ['Consultations'],
            'contact_email' => 'hello@example.com',
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
        File::ensureDirectoryExists(config('sync360.runtime_root').'/'.$tenant->slug.'/.openclaw/workspace');
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

        return [$tenant, $owner];
    }

    private function assignHelloWorldSkill(Tenant $tenant, User $owner): void
    {
        $version = SkillCatalogVersion::query()->where('skill_key', 'hello-world')->firstOrFail();

        TenantSkillAssignment::query()->create([
            'tenant_id' => $tenant->id,
            'skill_catalog_version_id' => $version->id,
            'skill_key' => 'hello-world',
            'assigned_by' => $owner->id,
            'assigned_at' => now(),
            'is_enabled' => true,
        ]);
    }

    private function materializeWorkspaceArtifacts(Tenant $tenant): void
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
