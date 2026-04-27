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
use App\Models\TenantInboxMonitorState;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Http\UploadedFile;
use Mockery;
use Tests\TestCase;

class ProfileFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_authenticated_tenant_can_view_profile_page(): void
    {
        [$user] = $this->seedTenantProfile();

        $this->actingAs($user);

        $this->get('/profile')
            ->assertOk()
            ->assertSee('Business Profile')
            ->assertSee('Save Business Profile')
            ->assertSee('Sync Status');
    }

    public function test_profile_page_shows_sync_progress_feedback_for_live_tenants(): void
    {
        [$user, $tenant] = $this->seedTenantProfile();

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

        $this->actingAs($user);

        $this->get('/profile')
            ->assertOk()
            ->assertSee('Sync Assistant Now')
            ->assertSee('We’ll show assistant sync progress here while a live sync is running.')
            ->assertSee('Saving your business profile and syncing the live assistant.', false)
            ->assertSee('id="sync-progress-note"', false)
            ->assertSee('Business logo');
    }

    public function test_profile_page_shows_dependency_alerts_in_sidebar_when_google_needs_reconnect(): void
    {
        [$user, $tenant] = $this->seedTenantProfile();

        $tenant->googleCredential()->create([
            'status' => TenantGoogleCredential::STATUS_CONNECTED,
            'runtime_sync_status' => TenantGoogleCredential::RUNTIME_SYNC_FAILED,
            'refresh_token' => 'refresh-token',
            'google_email' => 'owner@example.com',
            'health_status' => TenantGoogleCredential::HEALTH_RECONNECT_REQUIRED,
            'health_checked_at' => now(),
            'last_error' => 'invalid_grant: Token has been expired or revoked.',
        ]);

        $tenant->inboxMonitorState()->create([
            'skill_key' => 'inbox-triage',
            'is_enabled' => true,
            'health_status' => TenantInboxMonitorState::HEALTH_DOWN,
            'last_error' => 'invalid_grant: Token has been expired or revoked.',
            'last_checked_at' => now()->subMinutes(20),
            'health_checked_at' => now(),
        ]);

        $this->actingAs($user);

        $this->get('/profile')
            ->assertOk()
            ->assertSee('Reconnect Google Workspace')
            ->assertSee('Reconnect Google Workspace to restore inbox monitoring and live tools.');
    }

    public function test_profile_update_persists_business_details(): void
    {
        [$user, $tenant, $profile] = $this->seedTenantProfile();

        $this->actingAs($user);

        $this->patch('/profile', [
            'business_name' => 'Acme Plumbing & Gas',
            'trading_name' => 'Acme Plumbing',
            'website_url' => 'https://acme.example',
            'industry' => 'Trades',
            'description' => 'We handle maintenance, installs, and urgent plumbing callouts.',
            'tagline' => 'Fast local plumbing help',
            'contact_email' => 'support@acme.example',
            'contact_phone' => '+64 21 999 9999',
            'contact_mobile' => '+64 22 111 2222',
            'physical_address' => '123 Main Street',
            'postal_address' => 'PO Box 20',
            'city' => 'Auckland',
            'country' => 'New Zealand',
            'tax_number' => 'GST-123',
            'company_reg_number' => 'NZBN-456',
            'owner_name' => 'Alice Admin',
            'owner_email' => 'alice.owner@example.com',
            'owner_phone' => '+64 27 000 0000',
            'services_text' => "Emergency plumbing\nGas fitting",
            'business_hours_text' => "Mon-Fri: 8am - 5pm\nSat: 9am - 1pm",
            'after_hours_policy' => 'Collect the issue and promise a next-business-day callback.',
            'primary_language' => 'English',
            'faqs_text' => "Do you do callouts?\nWhat areas do you cover?",
            'target_customers' => 'Homeowners and property managers',
            'pricing_notes' => 'Pricing depends on the job scope.',
        ])
            ->assertRedirect('/profile');
        $tenant->refresh();
        $profile->refresh();

        $this->assertSame('Acme Plumbing & Gas', $tenant->business_name);
        $this->assertSame('Acme Plumbing & Gas', $profile->business_name);
        $this->assertSame('support@acme.example', $profile->contact_email);
        $this->assertSame(['Emergency plumbing', 'Gas fitting'], $profile->services);
        $this->assertSame(['Mon-Fri: 8am - 5pm', 'Sat: 9am - 1pm'], $profile->business_hours);
        $this->assertSame('GST-123', $profile->tax_number);
        $this->assertGreaterThan(0, $profile->profile_completeness);
    }

    public function test_profile_update_auto_syncs_live_assistant(): void
    {
        [$user, $tenant, $profile, $files] = $this->seedTenantProfile();

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

        $this->patch('/profile', [
            'business_name' => 'Acme Plumbing & Gas',
            'trading_name' => 'Acme Plumbing',
            'website_url' => 'https://acme.example',
            'industry' => 'Trades',
            'description' => 'We handle maintenance, installs, and urgent plumbing callouts.',
            'tagline' => 'Fast local plumbing help',
            'contact_email' => 'support@acme.example',
            'contact_phone' => '+64 21 999 9999',
            'contact_mobile' => '+64 22 111 2222',
            'physical_address' => '123 Main Street',
            'postal_address' => 'PO Box 20',
            'city' => 'Auckland',
            'country' => 'New Zealand',
            'tax_number' => 'GST-123',
            'company_reg_number' => 'NZBN-456',
            'owner_name' => 'Alice Admin',
            'owner_email' => 'alice.owner@example.com',
            'owner_phone' => '+64 27 000 0000',
            'services_text' => "Emergency plumbing\nGas fitting",
            'business_hours_text' => "Mon-Fri: 8am - 5pm\nSat: 9am - 1pm",
            'after_hours_policy' => 'Collect the issue and promise a next-business-day callback.',
            'primary_language' => 'English',
            'faqs_text' => "Do you do callouts?\nWhat areas do you cover?",
            'target_customers' => 'Homeowners and property managers',
            'pricing_notes' => 'Pricing depends on the job scope.',
        ])
            ->assertRedirect('/profile');

        $tenant->refresh();
        $profile->refresh();
        $files->refresh();

        $this->assertSame('live', $tenant->agent_status);
        $this->assertNotNull($files->generated_at);
        $this->assertNotNull($files->synced_at);
        $this->assertNotNull($profile->last_synced_to_agent);
        $this->assertStringContainsString('GST-123', File::get($localRuntimePath.'/.openclaw/workspace/PROFILE.md'));
        $this->assertStringContainsString('Mon-Fri: 8am - 5pm', File::get($localRuntimePath.'/.openclaw/workspace/PROFILE.md'));
        $this->assertStringContainsString('Google Workspace is not connected for this tenant yet.', File::get($localRuntimePath.'/.openclaw/workspace/TOOLS.md'));
        $this->assertStringContainsString('"business_name": "Acme Plumbing & Gas"', File::get($localRuntimePath.'/.openclaw/workspace/BUSINESS_PROFILE.json'));
        $this->assertStringContainsString('"tax_number": "GST-123"', File::get($localRuntimePath.'/.openclaw/workspace/BUSINESS_PROFILE.json'));
        $this->assertCount(1, $runnerSpy->syncCalls);
        $this->assertCount(0, $runnerSpy->syncRuntimeCalls);
        $this->assertCount(1, $runnerSpy->upCalls);
    }

    public function test_authenticated_tenant_can_upload_logo_without_refresh(): void
    {
        [$user, $tenant,, $files] = $this->seedTenantProfile();
        Storage::disk('local')->deleteDirectory('tenant-business-profile-assets/'.$tenant->tenant_id);

        $this->actingAs($user);

        $response = $this->post(route('profile.logo.upload'), [
            'logo' => UploadedFile::fake()->image('acme-logo.png', 300, 200)->size(256),
        ], [
            'Accept' => 'application/json',
            'X-Requested-With' => 'XMLHttpRequest',
        ]);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('business_profile_updated', true)
            ->assertJsonPath('synced', false)
            ->assertJsonPath('logo.present', true)
            ->assertJsonPath('logo.original_filename', 'acme-logo.png')
            ->assertJsonPath('logo.workspace_path', 'business-assets/logo.png');

        $files->refresh();

        $this->assertNotNull($files->logo_storage_path);
        Storage::disk('local')->assertExists($files->logo_storage_path);
    }

    public function test_logo_upload_rejects_invalid_files(): void
    {
        [$user] = $this->seedTenantProfile();

        $this->actingAs($user);

        $this->post(route('profile.logo.upload'), [
            'logo' => UploadedFile::fake()->create('notes.txt', 10, 'text/plain'),
        ], [
            'Accept' => 'application/json',
            'X-Requested-With' => 'XMLHttpRequest',
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('logo');
    }

    public function test_tenant_can_remove_and_replace_logo(): void
    {
        [$user, $tenant,, $files] = $this->seedTenantProfile();
        Storage::disk('local')->deleteDirectory('tenant-business-profile-assets/'.$tenant->tenant_id);
        Storage::disk('local')->put('tenant-business-profile-assets/'.$tenant->tenant_id.'/logo.png', 'old-logo');

        $files->forceFill([
            'logo_storage_path' => 'tenant-business-profile-assets/'.$tenant->tenant_id.'/logo.png',
            'logo_original_filename' => 'old-logo.png',
            'logo_mime_type' => 'image/png',
            'logo_size_bytes' => 8,
            'logo_uploaded_at' => now()->subMinute(),
        ])->save();

        $this->actingAs($user);

        $this->delete(route('profile.logo.delete'), [], [
            'Accept' => 'application/json',
            'X-Requested-With' => 'XMLHttpRequest',
        ])
            ->assertOk()
            ->assertJsonPath('logo.present', false);

        $files->refresh();
        $this->assertNull($files->logo_storage_path);
        Storage::disk('local')->assertMissing('tenant-business-profile-assets/'.$tenant->tenant_id.'/logo.png');

        $this->post(route('profile.logo.upload'), [
            'logo' => UploadedFile::fake()->image('replacement-logo.webp', 200, 200)->size(128),
        ], [
            'Accept' => 'application/json',
            'X-Requested-With' => 'XMLHttpRequest',
        ])
            ->assertOk()
            ->assertJsonPath('logo.present', true)
            ->assertJsonPath('logo.workspace_path', 'business-assets/logo.webp');
    }

    public function test_logo_preview_route_is_tenant_scoped(): void
    {
        [$user, $tenant,, $files] = $this->seedTenantProfile();
        Storage::disk('local')->deleteDirectory('tenant-business-profile-assets/'.$tenant->tenant_id);

        $storagePath = 'tenant-business-profile-assets/'.$tenant->tenant_id.'/logo.png';
        Storage::disk('local')->put($storagePath, 'logo-bytes');
        $files->forceFill([
            'logo_storage_path' => $storagePath,
            'logo_original_filename' => 'tenant-logo.png',
            'logo_mime_type' => 'image/png',
            'logo_size_bytes' => 10,
            'logo_uploaded_at' => now(),
        ])->save();

        $this->actingAs($user);
        $this->get(route('profile.logo.show'))
            ->assertOk()
            ->assertHeader('Content-Type', 'image/png');

        $otherUser = User::query()->create([
            'name' => 'Other Owner',
            'email' => 'other-owner@example.com',
            'password' => 'secret',
        ]);

        Tenant::query()->create([
            'tenant_id' => 'tenant_profile_02',
            'slug' => 'other-business',
            'business_name' => 'Other Business',
            'industry' => 'Trades',
            'skill_pack' => 'Operations Core',
            'onboarding_status' => 'pending',
            'onboarding_step' => 0,
            'agent_status' => 'offline',
            'user_id' => $otherUser->id,
            'server_id' => \App\Models\Server::query()->firstOrFail()->id,
            'trial_status' => TrialStatus::Active,
            'provisioning_status' => TenantProvisioningStatus::Pending,
        ]);
        BusinessProfile::query()->create([
            'tenant_id' => Tenant::query()->where('tenant_id', 'tenant_profile_02')->value('id'),
            'business_name' => 'Other Business',
            'industry' => 'Trades',
            'contact_email' => 'other-owner@example.com',
        ]);
        BusinessProfileFiles::query()->create([
            'tenant_id' => Tenant::query()->where('tenant_id', 'tenant_profile_02')->value('id'),
        ]);

        $this->actingAs($otherUser);
        $this->get(route('profile.logo.show'))->assertNotFound();
    }

    public function test_live_logo_upload_uses_workspace_only_sync(): void
    {
        [$user, $tenant, $profile, $files] = $this->seedTenantProfile();
        Storage::disk('local')->deleteDirectory('tenant-business-profile-assets/'.$tenant->tenant_id);

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
            public array $syncWorkspaceCalls = [];
            public array $syncRuntimeCalls = [];
            public array $upCalls = [];

            public function syncRuntime(Server $server, string $localRuntimePath, string $remoteRuntimePath): void
            {
                $this->syncRuntimeCalls[] = compact('localRuntimePath', 'remoteRuntimePath');
            }

            public function syncWorkspaceFiles(Server $server, string $localWorkspacePath, string $remoteWorkspacePath): void
            {
                $this->syncWorkspaceCalls[] = compact('localWorkspacePath', 'remoteWorkspacePath');
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
        $runtimeSkillActivation->shouldReceive('syncExpectedContract')->once()->andReturn([
            'changed' => false,
            'skill_set_changed' => false,
            'contract' => [
                'expected_skill_ids' => ['inbox-triage'],
                'skill_set_hash' => 'test-hash',
            ],
        ]);
        $runtimeSkillActivation->shouldReceive('verifyRuntimeSkills')->once()->andReturn([
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

        $this->post(route('profile.logo.upload'), [
            'logo' => UploadedFile::fake()->image('live-logo.png', 280, 180)->size(256),
        ], [
            'Accept' => 'application/json',
            'X-Requested-With' => 'XMLHttpRequest',
        ])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('synced', true);

        $profile->refresh();
        $files->refresh();

        $this->assertCount(1, $runnerSpy->syncWorkspaceCalls);
        $this->assertCount(0, $runnerSpy->syncRuntimeCalls);
        $this->assertCount(1, $runnerSpy->upCalls);
        $this->assertStringContainsString('"path": "business-assets/logo.png"', File::get($localRuntimePath.'/.openclaw/workspace/BUSINESS_PROFILE.json'));
        $this->assertStringContainsString('- Logo Asset: business-assets/logo.png', File::get($localRuntimePath.'/.openclaw/workspace/PROFILE.md'));
        $this->assertTrue(File::exists($localRuntimePath.'/.openclaw/workspace/business-assets/logo.png'));
    }

    /**
     * @return array{0: User, 1: Tenant, 2: BusinessProfile, 3: BusinessProfileFiles}
     */
    private function seedTenantProfile(): array
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
            'tenant_id' => 'tenant_profile_01',
            'slug' => 'acme-plumbing',
            'business_name' => 'Acme Plumbing',
            'industry' => 'Trades',
            'skill_pack' => 'Operations Core',
            'onboarding_status' => 'pending',
            'onboarding_step' => 0,
            'agent_status' => 'offline',
            'user_id' => $user->id,
            'server_id' => \App\Models\Server::query()->firstOrFail()->id,
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
        ]);

        $files = BusinessProfileFiles::query()->create([
            'tenant_id' => $tenant->id,
        ]);

        return [$user, $tenant, $profile, $files];
    }
}
