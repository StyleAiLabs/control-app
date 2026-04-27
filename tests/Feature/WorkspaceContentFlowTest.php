<?php

namespace Tests\Feature;

use App\Contracts\DockerComposeRunner;
use App\Enums\TenantProvisioningStatus;
use App\Enums\TrialStatus;
use App\Models\BusinessProfile;
use App\Models\BusinessProfileFiles;
use App\Models\Server;
use App\Models\SkillCatalogItem;
use App\Models\SkillCatalogVersion;
use App\Models\Tenant;
use App\Models\TenantGoogleCredential;
use App\Models\TenantWorkspaceContentItem;
use App\Models\User;
use App\Services\WebScraperService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Tests\TestCase;

class WorkspaceContentFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_authenticated_tenant_can_view_workspace_content_page(): void
    {
        [$user] = $this->seedTenantWorkspaceContent();

        $this->actingAs($user);

        $this->get('/workspace-content')
            ->assertOk()
            ->assertSee('Workspace Content')
            ->assertSee('Quick answers')
            ->assertSee('Documents')
            ->assertSee('Website content');
    }

    public function test_onboarding_website_url_appears_in_workspace_website_list_by_default(): void
    {
        [$user] = $this->seedTenantWorkspaceContent();

        $this->actingAs($user);

        $this->get('/workspace-content')
            ->assertOk()
            ->assertSee('https://acme.example')
            ->assertSee('From profile')
            ->assertSee('Review This Website');
    }

    public function test_customer_can_save_quick_answer_text_blocks(): void
    {
        [$user, $tenant] = $this->seedTenantWorkspaceContent();

        $this->actingAs($user);

        $this->patchJson('/workspace-content/text', [
            'blocks' => [
                'pricing-guidance' => 'Standard callout fees start from $120 and after-hours work is quoted case by case.',
                'service-boundaries' => 'We handle residential maintenance only and do not take on new-build fit-outs.',
            ],
        ])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('synced', false)
            ->assertJsonPath('summary.active_count', 2);

        $this->assertDatabaseHas('tenant_workspace_content_items', [
            'tenant_id' => $tenant->id,
            'source_type' => TenantWorkspaceContentItem::SOURCE_TYPE_TEXT_BLOCK,
            'slug' => 'pricing-guidance',
            'status' => TenantWorkspaceContentItem::STATUS_ACTIVE,
        ]);
    }

    public function test_customer_can_upload_supported_document_and_generate_structured_sheet_payload(): void
    {
        [$user, $tenant] = $this->seedTenantWorkspaceContent();
        Storage::disk('local')->deleteDirectory('tenant-workspace-content/'.$tenant->tenant_id);

        $this->actingAs($user);

        $response = $this->post('/workspace-content/documents', [
            'document' => UploadedFile::fake()->createWithContent(
                'rate-sheet.csv',
                "Service,Price\nCallout,120\nHourly labour,95\n"
            ),
        ], ['Accept' => 'application/json']);

        $response
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('item.slug', 'rate-sheet')
            ->assertJsonPath('item.structured_data_workspace_path', 'knowledge/data/rate-sheet.json');

        /** @var TenantWorkspaceContentItem $item */
        $item = TenantWorkspaceContentItem::query()->where('tenant_id', $tenant->id)->firstOrFail();

        $this->assertSame(TenantWorkspaceContentItem::SOURCE_TYPE_DOCUMENT, $item->source_type);
        $this->assertSame('knowledge/documents/rate-sheet.md', $item->workspace_path);
        $this->assertSame('knowledge/data/rate-sheet.json', $item->structured_data_workspace_path);
        $this->assertSame(['Service', 'Price'], $item->content_json['columns']);
        $this->assertSame('Callout', $item->content_json['rows'][0]['Service']);
        Storage::disk('local')->assertExists('tenant-workspace-content/'.$tenant->tenant_id.'/documents/rate-sheet.csv');
    }

    public function test_document_upload_rejects_unsupported_file_types(): void
    {
        [$user] = $this->seedTenantWorkspaceContent();

        $this->actingAs($user);

        $this->post('/workspace-content/documents', [
            'document' => UploadedFile::fake()->create('malware.exe', 10),
        ], ['Accept' => 'application/json'])
            ->assertStatus(422);
    }

    public function test_website_refresh_stays_in_review_until_published(): void
    {
        [$user, $tenant] = $this->seedTenantWorkspaceContent();

        $scraper = Mockery::mock(WebScraperService::class);
        $scraper->shouldReceive('scrape')
            ->once()
            ->with('https://acme.example')
            ->andReturn("# Page: https://acme.example\n\nWe offer emergency plumbing, hot water servicing, and weekday callouts.");
        $this->instance(WebScraperService::class, $scraper);

        $this->actingAs($user);

        $importResponse = $this->postJson('/workspace-content/website/import', [
            'url' => 'https://acme.example',
        ]);

        $importResponse
            ->assertOk()
            ->assertJsonPath('item.status', TenantWorkspaceContentItem::STATUS_NEEDS_REVIEW)
            ->assertJsonCount(1, 'website_items');

        /** @var TenantWorkspaceContentItem $item */
        $item = TenantWorkspaceContentItem::query()
            ->where('tenant_id', $tenant->id)
            ->where('source_type', TenantWorkspaceContentItem::SOURCE_TYPE_WEBSITE_SNAPSHOT)
            ->firstOrFail();

        $this->assertNull($item->content_markdown);
        $this->assertNotNull($item->draft_markdown);

        $this->postJson('/workspace-content/website/'.$item->id.'/publish')
            ->assertOk()
            ->assertJsonPath('item.status', TenantWorkspaceContentItem::STATUS_ACTIVE);

        $item->refresh();
        $this->assertNotNull($item->content_markdown);
        $this->assertNull($item->draft_markdown);
    }

    public function test_customer_can_add_multiple_websites_and_is_limited_to_five_unique_urls_including_the_onboarding_site(): void
    {
        [$user, $tenant] = $this->seedTenantWorkspaceContent();

        $scraper = Mockery::mock(WebScraperService::class);
        $scraper->shouldReceive('scrape')
            ->times(5)
            ->andReturnUsing(fn (string $url): string => "# Page: {$url}\n\nCustomer-facing website content for {$url}.");
        $this->instance(WebScraperService::class, $scraper);

        $this->actingAs($user);

        $urls = [
            'https://acme.example',
            'https://north.example',
            'https://south.example',
            'https://pricing.example',
            'https://faq.example',
        ];

        foreach ($urls as $index => $url) {
            $this->postJson('/workspace-content/website/import', ['url' => $url])
                ->assertOk()
                ->assertJsonCount($index + 1, 'website_items');
        }

        $this->assertSame(5, TenantWorkspaceContentItem::query()
            ->where('tenant_id', $tenant->id)
            ->where('source_type', TenantWorkspaceContentItem::SOURCE_TYPE_WEBSITE_SNAPSHOT)
            ->where('status', TenantWorkspaceContentItem::STATUS_NEEDS_REVIEW)
            ->count());

        $this->postJson('/workspace-content/website/import', [
            'url' => 'https://overflow.example',
        ])
            ->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'You can keep up to 5 websites here at once. Remove one before adding another.');
    }

    public function test_live_workspace_content_changes_use_workspace_only_sync(): void
    {
        [$user, $tenant, $profile, $files] = $this->seedTenantWorkspaceContent();

        config()->set('services.litellm.virtual_key', null);

        $tenant->forceFill([
            'provisioning_status' => TenantProvisioningStatus::Ready,
            'onboarding_status' => 'complete',
            'onboarding_step' => 7,
            'agent_status' => 'live',
            'workspace_url' => 'https://acme-plumbing.workspace.test',
            'runtime_path' => '/srv/sync360/runtime/tenants/acme-plumbing',
            'tone' => 'friendly',
            'channel' => 'telegram',
            'channel_config' => ['telegram_bot_token' => 'telegram-bot-token'],
        ])->save();

        TenantGoogleCredential::query()->create([
            'tenant_id' => $tenant->id,
            'status' => TenantGoogleCredential::STATUS_SKIPPED,
            'runtime_sync_status' => TenantGoogleCredential::RUNTIME_SYNC_PENDING,
        ]);

        $files->forceFill([
            'identity_markdown' => '# Identity',
            'soul_markdown' => '# Soul',
            'user_markdown' => '# User',
            'bootstrap_markdown' => '# Bootstrap',
            'generated_at' => now()->subMinute(),
        ])->save();

        $localRuntimePath = config('sync360.runtime_root').'/'.$tenant->slug;
        File::ensureDirectoryExists($localRuntimePath.'/.openclaw/workspace');
        File::put($localRuntimePath.'/compose.yaml', 'services: {}');

        $runnerSpy = new class implements DockerComposeRunner
        {
            public array $syncCalls = [];
            public array $upCalls = [];
            public array $syncRuntimeCalls = [];

            public function syncRuntime(Server $server, string $localRuntimePath, string $remoteRuntimePath): void
            {
                $this->syncRuntimeCalls[] = compact('localRuntimePath', 'remoteRuntimePath');
            }

            public function syncWorkspaceFiles(Server $server, string $localWorkspacePath, string $remoteWorkspacePath): void
            {
                $this->syncCalls[] = compact('localWorkspacePath', 'remoteWorkspacePath');
            }

            public function httpRequest(Server $server, string $method, string $url, ?array $json = null, int $timeoutSeconds = 15, array $headers = []): array
            {
                $response = Http::timeout($timeoutSeconds)->acceptJson()->send($method, $url, $json !== null ? ['json' => $json] : []);

                return ['status' => $response->status(), 'body' => $response->body()];
            }

            public function putFile(Server $server, string $remotePath, string $contents, bool $sudo = false): void {}
            public function removeFile(Server $server, string $remotePath, bool $sudo = false): void {}
            public function removeDirectory(Server $server, string $remotePath, bool $sudo = false): void {}
            public function runCommand(Server $server, string $command, bool $sudo = false): void {}
            public function up(Server $server, string $composeFile, string $projectName): void
            {
                $this->upCalls[] = compact('composeFile', 'projectName');
            }
            public function down(Server $server, string $composeFile, string $projectName): void {}
            public function start(Server $server, string $composeFile, string $projectName): void {}
            public function stop(Server $server, string $composeFile, string $projectName): void {}
            public function isRunning(Server $server, string $composeFile, string $projectName): bool { return false; }
            public function isHostPortInUse(Server $server, int $port): bool { return false; }
            public function waitForHttpReady(Server $server, string $url, int $timeoutSeconds, int $pollIntervalMs): void {}
        };

        $runtimeSkillActivation = Mockery::mock(\App\Services\TenantRuntimeSkillActivationService::class);
        $runtimeSkillActivation->shouldReceive('syncExpectedContract')
            ->once()
            ->andReturn([
                'changed' => false,
                'skill_set_changed' => false,
                'contract' => [
                    'expected_skill_ids' => ['inbox-triage'],
                    'skill_set_hash' => 'test-hash',
                ],
            ]);
        $runtimeSkillActivation->shouldReceive('verifyRuntimeSkills')
            ->once()
            ->andReturn([
                'ready' => true,
                'contract' => [
                    'expected_skill_ids' => ['inbox-triage'],
                    'verified_skill_ids' => ['inbox-triage'],
                    'skill_set_hash' => 'test-hash',
                    'verified_skill_set_hash' => 'test-hash',
                    'last_verified_at' => now()->toIso8601String(),
                    'last_verification_error' => null,
                ],
                'workspace_state' => 'running',
                'refreshed_at' => now()->toDateTimeString(),
                'skills' => ['inbox-triage'],
                'raw_output' => '',
                'missing_expected_skill_ids' => [],
                'missing_required_skill_ids' => [],
                'error' => null,
            ]);

        $this->instance(DockerComposeRunner::class, $runnerSpy);
        $this->instance(\App\Services\TenantRuntimeSkillActivationService::class, $runtimeSkillActivation);

        $this->actingAs($user);

        $this->patchJson('/workspace-content/text', [
            'blocks' => [
                'pricing-guidance' => 'Standard callout fees start from $120.',
            ],
        ])
            ->assertOk()
            ->assertJsonPath('synced', true);

        $this->assertCount(1, $runnerSpy->syncCalls);
        $this->assertCount(1, $runnerSpy->upCalls);
        $this->assertCount(0, $runnerSpy->syncRuntimeCalls);
        $this->assertTrue(File::exists($localRuntimePath.'/.openclaw/workspace/WORKSPACE_CONTENT_INDEX.json'));
        $this->assertStringContainsString('"slug": "pricing-guidance"', File::get($localRuntimePath.'/.openclaw/workspace/WORKSPACE_CONTENT_INDEX.json'));
        $this->assertStringContainsString('Standard callout fees start from $120.', File::get($localRuntimePath.'/.openclaw/workspace/knowledge/text/pricing-guidance.md'));
    }

    /**
     * @return array{0: User, 1: Tenant, 2: BusinessProfile, 3: BusinessProfileFiles}
     */
    private function seedTenantWorkspaceContent(): array
    {
        $skill = SkillCatalogItem::query()->create([
            'skill_key' => 'inbox-triage',
            'label' => 'Inbox Triage (by Sync360)',
            'description' => 'Inbox triage',
            'category' => 'operations',
            'onboarding_role' => 'core',
            'is_assignable' => true,
            'is_orphaned' => false,
        ]);

        SkillCatalogVersion::query()->create([
            'skill_catalog_item_id' => $skill->id,
            'skill_key' => 'inbox-triage',
            'version' => '1.5.8',
            'manifest_json' => [
                'skill_id' => 'inbox-triage',
                'version' => '1.5.8',
                'label' => 'Inbox Triage (by Sync360)',
                'description' => 'Inbox triage',
                'onboarding_role' => 'core',
            ],
            'is_active_published' => true,
            'is_archived' => false,
            'is_available' => true,
            'discovered_at' => now(),
            'last_imported_at' => now(),
        ]);

        $user = User::query()->create([
            'name' => 'Alice Admin',
            'email' => 'alice@example.com',
            'password' => 'super-secret',
        ]);

        $tenant = Tenant::query()->create([
            'tenant_id' => 'tenant_workspace_content_01',
            'slug' => 'acme-plumbing',
            'business_name' => 'Acme Plumbing',
            'industry' => 'Trades',
            'skill_pack' => 'Operations Core',
            'onboarding_status' => 'pending',
            'onboarding_step' => 0,
            'agent_status' => 'offline',
            'user_id' => $user->id,
            'server_id' => Server::query()->firstOrFail()->id,
            'trial_status' => TrialStatus::Active,
            'provisioning_status' => TenantProvisioningStatus::Pending,
        ]);

        $profile = BusinessProfile::query()->create([
            'tenant_id' => $tenant->id,
            'business_name' => 'Acme Plumbing',
            'industry' => 'Trades',
            'description' => 'Acme Plumbing helps homeowners with urgent repairs and maintenance work.',
            'services' => ['Emergency plumbing', 'Maintenance'],
            'contact_email' => 'alice@example.com',
            'contact_phone' => '+64 21 555 0101',
            'country' => 'New Zealand',
            'website_url' => 'https://acme.example',
        ]);

        $files = BusinessProfileFiles::query()->create([
            'tenant_id' => $tenant->id,
        ]);

        return [$user, $tenant, $profile, $files];
    }
}
