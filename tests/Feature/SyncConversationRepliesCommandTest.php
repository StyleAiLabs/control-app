<?php

namespace Tests\Feature;

use App\Enums\TenantProvisioningStatus;
use App\Enums\TrialStatus;
use App\Models\Server;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class SyncConversationRepliesCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_sync_replies_command_fails_with_remediation_when_conversation_log_schema_has_drift(): void
    {
        $this->seedLiveTenant();

        Schema::table('conversation_logs', function ($table): void {
            $table->dropColumn('ai_summary');
        });

        $this->artisan('sync360:sync-replies')
            ->expectsOutputToContain('Conversation log schema drift detected')
            ->assertExitCode(1);
    }

    private function seedLiveTenant(): Tenant
    {
        $user = User::query()->create([
            'name' => 'Owner',
            'email' => 'owner-sync@example.com',
            'password' => 'secret',
        ]);

        return Tenant::query()->create([
            'tenant_id' => 'tenant-sync-schema',
            'slug' => 'sync-schema-shop',
            'business_name' => 'Sync Schema Shop',
            'industry' => 'Services',
            'skill_pack' => 'Core Modules',
            'channel' => 'telegram',
            'user_id' => $user->id,
            'server_id' => Server::query()->firstOrFail()->id,
            'trial_status' => TrialStatus::Active,
            'provisioning_status' => TenantProvisioningStatus::Ready,
            'agent_status' => 'live',
            'assigned_port' => 4100,
            'workspace_url' => 'https://sync-schema-shop.workspace.test',
            'runtime_path' => '/srv/sync360/runtime/tenants/sync-schema-shop',
        ]);
    }
}
