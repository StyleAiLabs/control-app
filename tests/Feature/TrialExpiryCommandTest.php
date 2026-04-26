<?php

namespace Tests\Feature;

use App\Enums\TenantProvisioningStatus;
use App\Enums\TrialStatus;
use App\Models\Server;
use App\Models\Tenant;
use App\Models\User;
use App\Services\LiteLlmTenantKeyService;
use App\Services\TenantAgentSyncService;
use App\Services\TrialNotificationEmailService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class TrialExpiryCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_trial_expiry_command_suspends_litellm_when_override_is_disabled(): void
    {
        $tenant = $this->makeTenant([
            'trial_ends_at' => now()->subHour(),
            'allow_litellm_when_trial_expired' => false,
        ]);

        $litellm = Mockery::mock(LiteLlmTenantKeyService::class);
        $litellm->shouldReceive('getKeyInfo')->once()->andReturn([
            'spend' => 1.25,
            'max_budget' => 5.0,
            'budget_reset_at' => now()->addDays(7)->toIso8601String(),
        ]);
        $litellm->shouldReceive('suspendTenant')
            ->once()
            ->withArgs(fn (Tenant $candidate): bool => $candidate->tenant_id === $tenant->tenant_id);
        $this->instance(LiteLlmTenantKeyService::class, $litellm);

        $agentSync = Mockery::mock(TenantAgentSyncService::class);
        $agentSync->shouldReceive('syncSavedChannelIfReady')
            ->once()
            ->withArgs(fn (Tenant $candidate): bool => $candidate->tenant_id === $tenant->tenant_id)
            ->andReturn(false);
        $this->instance(TenantAgentSyncService::class, $agentSync);

        $mailer = Mockery::mock(TrialNotificationEmailService::class);
        $mailer->shouldReceive('sendTrialExpired')
            ->once()
            ->withArgs(fn (Tenant $candidate, string $reason): bool => $candidate->tenant_id === $tenant->tenant_id && $reason === 'time');
        $this->instance(TrialNotificationEmailService::class, $mailer);

        $this->artisan('sync360:check-trial-expiry')->assertExitCode(0);

        $tenant->refresh();

        $this->assertSame(TrialStatus::Expired, $tenant->trial_status);
    }

    public function test_trial_expiry_command_keeps_litellm_active_when_override_is_enabled(): void
    {
        $tenant = $this->makeTenant([
            'trial_ends_at' => now()->subHour(),
            'allow_litellm_when_trial_expired' => true,
        ]);

        $litellm = Mockery::mock(LiteLlmTenantKeyService::class);
        $litellm->shouldReceive('getKeyInfo')->once()->andReturn([
            'spend' => 1.25,
            'max_budget' => 5.0,
            'budget_reset_at' => now()->addDays(7)->toIso8601String(),
        ]);
        $litellm->shouldNotReceive('suspendTenant');
        $this->instance(LiteLlmTenantKeyService::class, $litellm);

        $agentSync = Mockery::mock(TenantAgentSyncService::class);
        $agentSync->shouldReceive('syncSavedChannelIfReady')
            ->once()
            ->withArgs(fn (Tenant $candidate): bool => $candidate->tenant_id === $tenant->tenant_id)
            ->andReturn(false);
        $this->instance(TenantAgentSyncService::class, $agentSync);

        $mailer = Mockery::mock(TrialNotificationEmailService::class);
        $mailer->shouldReceive('sendTrialExpired')->once();
        $this->instance(TrialNotificationEmailService::class, $mailer);

        $this->artisan('sync360:check-trial-expiry')->assertExitCode(0);

        $tenant->refresh();

        $this->assertSame(TrialStatus::Expired, $tenant->trial_status);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function makeTenant(array $overrides = []): Tenant
    {
        $user = User::factory()->create();

        return Tenant::query()->create(array_merge([
            'tenant_id' => 'tenant-trial-'.str()->random(6),
            'slug' => 'trial-'.str()->random(6),
            'business_name' => 'Trial Tenant',
            'industry' => 'Testing',
            'skill_pack' => 'Core Modules',
            'user_id' => $user->id,
            'server_id' => Server::query()->firstOrFail()->id,
            'trial_status' => TrialStatus::Active,
            'trial_ends_at' => now()->addDays(2),
            'provisioning_status' => TenantProvisioningStatus::Ready,
            'channel' => 'telegram',
            'channel_config' => ['telegram_bot_token' => 'telegram-bot-token'],
            'litellm_virtual_key' => 'sk-trial-tenant',
            'litellm_max_budget' => 5,
            'litellm_budget_duration' => 'monthly',
            'litellm_spend' => 1,
        ], $overrides));
    }
}
