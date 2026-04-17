<?php

namespace Tests\Feature;

use App\Contracts\DockerComposeRunner;
use App\Enums\TenantProvisioningStatus;
use App\Enums\TrialStatus;
use App\Models\BusinessProfile;
use App\Models\BusinessProfileFiles;
use App\Models\Server;
use App\Models\Tenant;
use App\Models\TenantGoogleCredential;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
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
            'capabilities' => ['faqs', 'messages'],
            'channel' => 'telegram',
            'channel_config' => ['telegram_bot_token' => 'telegram-bot-token'],
        ])->save();

        $this->actingAs($user);

        $this->get('/profile')
            ->assertOk()
            ->assertSee('Sync Assistant Now')
            ->assertSee('We’ll show assistant sync progress here while a live sync is running.')
            ->assertSee('Saving your business profile and syncing the live assistant.', false)
            ->assertSee('id="sync-progress-note"', false);
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
            'capabilities' => ['faqs', 'after_hours'],
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

            public function syncRuntime(Server $server, string $localRuntimePath, string $remoteRuntimePath): void
            {
                $this->syncCalls[] = compact('localRuntimePath', 'remoteRuntimePath');
            }

            public function syncWorkspaceFiles(Server $server, string $localWorkspacePath, string $remoteWorkspacePath): void
            {
                $this->syncCalls[] = compact('localWorkspacePath', 'remoteWorkspacePath');
            }

            public function httpRequest(Server $server, string $method, string $url, ?array $json = null, int $timeoutSeconds = 15): array
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

        $this->instance(DockerComposeRunner::class, $runnerSpy);
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
        $this->assertCount(1, $runnerSpy->syncCalls);
        $this->assertCount(1, $runnerSpy->upCalls);
    }

    /**
     * @return array{0: User, 1: Tenant, 2: BusinessProfile, 3: BusinessProfileFiles}
     */
    private function seedTenantProfile(): array
    {
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
