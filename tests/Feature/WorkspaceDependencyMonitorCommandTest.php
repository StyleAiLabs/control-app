<?php

namespace Tests\Feature;

use App\Enums\TenantProvisioningStatus;
use App\Enums\TrialStatus;
use App\Models\Server;
use App\Models\Tenant;
use App\Models\TenantGoogleCredential;
use App\Models\TenantInboxMonitorState;
use App\Models\User;
use App\Services\Channels\TelegramSender;
use App\Services\TenantGoogleWorkspaceSmokeTestService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class WorkspaceDependencyMonitorCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_monitor_command_marks_reconnect_required_on_auth_failure(): void
    {
        config()->set('services.google.oauth_app_mode', 'live');

        $tenant = $this->makeTenant();
        $tenant->googleCredential()->create([
            'status' => TenantGoogleCredential::STATUS_CONNECTED,
            'runtime_sync_status' => TenantGoogleCredential::RUNTIME_SYNC_VERIFIED,
            'google_email' => 'owner@example.com',
            'refresh_token' => 'refresh-token',
            'connected_at' => now()->subDays(2),
            'last_verified_at' => now(),
        ]);
        $tenant->inboxMonitorState()->create([
            'enabled' => true,
            'status' => TenantInboxMonitorState::STATUS_IDLE,
            'last_checked_at' => now()->subMinutes(5),
        ]);

        $smokeTests = Mockery::mock(TenantGoogleWorkspaceSmokeTestService::class);
        $smokeTests->shouldReceive('run')
            ->once()
            ->andThrow(new \RuntimeException('oauth2: "invalid_grant" "Token has been expired or revoked."'));
        $this->instance(TenantGoogleWorkspaceSmokeTestService::class, $smokeTests);

        $telegram = Mockery::mock(TelegramSender::class);
        $telegram->shouldReceive('send')->never();
        $this->instance(TelegramSender::class, $telegram);

        $this->artisan('sync360:monitor-workspace-dependencies '.$tenant->slug)
            ->assertExitCode(0)
            ->expectsOutputToContain('Google status: reconnect_required');

        $this->assertDatabaseHas('tenant_google_credentials', [
            'tenant_id' => $tenant->id,
            'health_status' => TenantGoogleCredential::HEALTH_RECONNECT_REQUIRED,
            'incident_alert_reason' => 'google_reconnect_required',
        ]);
    }

    public function test_monitor_command_sends_testing_mode_expiry_warning_once(): void
    {
        config()->set('services.google.oauth_app_mode', 'testing');

        $tenant = $this->makeTenant([
            'channel' => 'telegram',
            'channel_config' => [
                'telegram_bot_token' => 'telegram-token',
                'telegram_default_chat_id' => '12345',
            ],
        ]);
        $tenant->googleCredential()->create([
            'status' => TenantGoogleCredential::STATUS_CONNECTED,
            'runtime_sync_status' => TenantGoogleCredential::RUNTIME_SYNC_VERIFIED,
            'google_email' => 'owner@example.com',
            'refresh_token' => 'refresh-token',
            'connected_at' => now()->subDays(6)->subHours(12),
            'last_verified_at' => now(),
        ]);

        $smokeTests = Mockery::mock(TenantGoogleWorkspaceSmokeTestService::class);
        $smokeTests->shouldReceive('run')->twice()->andReturn([
            'container_smoke_passed' => true,
        ]);
        $this->instance(TenantGoogleWorkspaceSmokeTestService::class, $smokeTests);

        $telegram = Mockery::mock(TelegramSender::class);
        $telegram->shouldReceive('send')->once();
        $this->instance(TelegramSender::class, $telegram);

        $this->artisan('sync360:monitor-workspace-dependencies '.$tenant->slug)
            ->assertExitCode(0);
        $this->artisan('sync360:monitor-workspace-dependencies '.$tenant->slug)
            ->assertExitCode(0);

        $this->assertDatabaseHas('tenant_google_credentials', [
            'tenant_id' => $tenant->id,
            'health_status' => TenantGoogleCredential::HEALTH_EXPIRING_SOON,
        ]);
        $this->assertNotNull($tenant->fresh('googleCredential')->googleCredential?->expiry_warning_1day_sent_at);
        $this->assertNull($tenant->fresh('googleCredential')->googleCredential?->expiry_warning_3day_sent_at);
    }

    public function test_monitor_command_uses_in_app_alerts_when_telegram_is_missing(): void
    {
        config()->set('services.google.oauth_app_mode', 'testing');

        $tenant = $this->makeTenant();
        $tenant->googleCredential()->create([
            'status' => TenantGoogleCredential::STATUS_CONNECTED,
            'runtime_sync_status' => TenantGoogleCredential::RUNTIME_SYNC_VERIFIED,
            'google_email' => 'owner@example.com',
            'refresh_token' => 'refresh-token',
            'connected_at' => now()->subDays(6)->subHours(12),
            'last_verified_at' => now(),
        ]);

        $smokeTests = Mockery::mock(TenantGoogleWorkspaceSmokeTestService::class);
        $smokeTests->shouldReceive('run')->once()->andReturn([
            'container_smoke_passed' => true,
        ]);
        $this->instance(TenantGoogleWorkspaceSmokeTestService::class, $smokeTests);

        $telegram = Mockery::mock(TelegramSender::class);
        $telegram->shouldReceive('send')->never();
        $this->instance(TelegramSender::class, $telegram);

        $this->artisan('sync360:monitor-workspace-dependencies '.$tenant->slug)
            ->assertExitCode(0);

        $credential = $tenant->fresh('googleCredential')->googleCredential;

        $this->assertSame(TenantGoogleCredential::HEALTH_EXPIRING_SOON, $credential?->health_status);
        $this->assertNull($credential?->expiry_warning_1day_sent_at);
    }

    public function test_monitor_command_still_sends_operational_alerts_for_expired_trials(): void
    {
        config()->set('services.google.oauth_app_mode', 'testing');

        $tenant = $this->makeTenant([
            'trial_status' => TrialStatus::Expired,
            'channel' => 'telegram',
            'channel_config' => [
                'telegram_bot_token' => 'telegram-token',
                'telegram_default_chat_id' => '12345',
            ],
        ]);
        $tenant->googleCredential()->create([
            'status' => TenantGoogleCredential::STATUS_CONNECTED,
            'runtime_sync_status' => TenantGoogleCredential::RUNTIME_SYNC_VERIFIED,
            'google_email' => 'owner@example.com',
            'refresh_token' => 'refresh-token',
            'connected_at' => now()->subDays(6)->subHours(12),
            'last_verified_at' => now(),
        ]);

        $smokeTests = Mockery::mock(TenantGoogleWorkspaceSmokeTestService::class);
        $smokeTests->shouldReceive('run')->once()->andReturn([
            'container_smoke_passed' => true,
        ]);
        $this->instance(TenantGoogleWorkspaceSmokeTestService::class, $smokeTests);

        $telegram = Mockery::mock(TelegramSender::class);
        $telegram->shouldReceive('send')->once();
        $this->instance(TelegramSender::class, $telegram);

        $this->artisan('sync360:monitor-workspace-dependencies '.$tenant->slug)
            ->assertExitCode(0);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function makeTenant(array $overrides = []): Tenant
    {
        $user = User::factory()->create();

        return Tenant::query()->create(array_merge([
            'tenant_id' => 'tenant-monitor-'.str()->random(8),
            'slug' => 'monitor-'.str()->random(8),
            'business_name' => 'Monitor Tenant',
            'industry' => 'Testing',
            'skill_pack' => 'Core Modules',
            'user_id' => $user->id,
            'server_id' => Server::query()->firstOrFail()->id,
            'trial_status' => TrialStatus::Active->value,
            'provisioning_status' => TenantProvisioningStatus::Ready->value,
            'onboarding_status' => 'complete',
            'onboarding_step' => 7,
            'agent_status' => 'live',
            'runtime_path' => '/srv/sync360/runtime/tenants/monitor-tenant',
            'workspace_url' => 'https://monitor-tenant.workspace.test',
        ], $overrides));
    }
}
