<?php

namespace Tests\Unit;

use App\Enums\TenantProvisioningStatus;
use App\Enums\TrialStatus;
use App\Models\Server;
use App\Models\Tenant;
use App\Models\User;
use App\Services\TenantAgentSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class TenantAgentSyncServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_sync_saved_channel_if_ready_configures_telegram_for_active_tenant(): void
    {
        $tenant = $this->seedTenant();
        $this->writeBaseConfig($tenant);

        $configured = app(TenantAgentSyncService::class)->syncSavedChannelIfReady($tenant);

        $config = json_decode(File::get(config('sync360.runtime_root').'/'.$tenant->slug.'/config/openclaw.json'), true);

        $this->assertTrue($configured);
        $this->assertTrue(data_get($config, 'channels.telegram.enabled'));
        $this->assertSame('telegram-bot-token', data_get($config, 'channels.telegram.botToken'));
    }

    public function test_sync_saved_channel_if_ready_pauses_runtime_telegram_for_expired_tenant_without_override(): void
    {
        $tenant = $this->seedTenant([
            'trial_status' => TrialStatus::Expired,
            'allow_runtime_replies_when_trial_expired' => false,
        ]);
        $this->writeBaseConfig($tenant, [
            'channels' => [
                'telegram' => [
                    'enabled' => true,
                    'botToken' => 'telegram-bot-token',
                    'dmPolicy' => 'open',
                    'allowFrom' => ['*'],
                ],
            ],
        ]);

        $configured = app(TenantAgentSyncService::class)->syncSavedChannelIfReady($tenant);

        $config = json_decode(File::get(config('sync360.runtime_root').'/'.$tenant->slug.'/config/openclaw.json'), true);

        $this->assertFalse($configured);
        $this->assertNull(data_get($config, 'channels.telegram'));
    }

    public function test_sync_saved_channel_if_ready_restores_telegram_for_expired_tenant_with_override(): void
    {
        $tenant = $this->seedTenant([
            'trial_status' => TrialStatus::Expired,
            'allow_runtime_replies_when_trial_expired' => true,
        ]);
        $this->writeBaseConfig($tenant);

        $configured = app(TenantAgentSyncService::class)->syncSavedChannelIfReady($tenant);

        $config = json_decode(File::get(config('sync360.runtime_root').'/'.$tenant->slug.'/config/openclaw.json'), true);

        $this->assertTrue($configured);
        $this->assertTrue(data_get($config, 'channels.telegram.enabled'));
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function seedTenant(array $overrides = []): Tenant
    {
        $user = User::query()->create([
            'name' => 'Owner',
            'email' => 'owner@example.com',
            'password' => 'secret',
        ]);

        return Tenant::query()->create(array_merge([
            'tenant_id' => 'tenant-agent-sync',
            'slug' => 'agent-sync-shop',
            'business_name' => 'Agent Sync Shop',
            'industry' => 'Services',
            'skill_pack' => 'Client Support',
            'channel' => 'telegram',
            'channel_config' => ['telegram_bot_token' => 'telegram-bot-token'],
            'agent_status' => 'live',
            'trial_status' => TrialStatus::Active,
            'provisioning_status' => TenantProvisioningStatus::Ready,
            'assigned_port' => 4100,
            'workspace_url' => 'https://agent-sync-shop.workspace.test',
            'runtime_path' => '/srv/sync360/runtime/tenants/agent-sync-shop',
            'server_id' => Server::query()->firstOrFail()->id,
            'user_id' => $user->id,
        ], $overrides));
    }

    /**
     * @param  array<string, mixed>  $extraConfig
     */
    private function writeBaseConfig(Tenant $tenant, array $extraConfig = []): void
    {
        $path = config('sync360.runtime_root').'/'.$tenant->slug.'/config';
        File::ensureDirectoryExists($path);
        File::put($path.'/openclaw.json', json_encode(array_replace_recursive([
            'gateway' => [
                'auth' => [
                    'mode' => 'token',
                    'token' => 'test-token',
                ],
            ],
        ], $extraConfig), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL);
    }
}
