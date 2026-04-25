<?php

namespace Tests\Feature;

use App\Enums\ProvisioningJobStatus;
use App\Enums\TenantProvisioningStatus;
use App\Jobs\ResyncLiveTenantWorkspaceAfterSkillRollout;
use App\Models\BusinessProfile;
use App\Models\BusinessProfileFiles;
use App\Models\ProvisioningJob;
use App\Models\Server;
use App\Models\Tenant;
use App\Models\User;
use App\Services\TenantProfileSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class ResyncLiveTenantWorkspaceAfterSkillRolloutJobTest extends TestCase
{
    use RefreshDatabase;

    public function test_resync_job_uses_workspace_only_profile_sync_path_and_marks_job_completed(): void
    {
        $tenant = $this->seedTenant('rollout-resync-job');
        $jobRow = ProvisioningJob::query()->create([
            'tenant_id' => $tenant->id,
            'job_type' => ResyncLiveTenantWorkspaceAfterSkillRollout::JOB_TYPE,
            'status' => ProvisioningJobStatus::Queued,
            'payload_json' => [
                'source' => 'skill_rollout_auto_resync',
                'skill_key' => 'hello-world',
                'skill_catalog_version_id' => 123,
                'source_provisioning_job_id' => 99,
            ],
        ]);

        $profileSync = Mockery::mock(TenantProfileSyncService::class);
        $profileSync->shouldReceive('regenerateAndSyncWorkspaceOnly')
            ->once()
            ->withArgs(fn (Tenant $candidate): bool => $candidate->is($tenant))
            ->andReturnNull();
        $profileSync->shouldNotReceive('regenerateAndSync');
        $this->instance(TenantProfileSyncService::class, $profileSync);

        $job = new ResyncLiveTenantWorkspaceAfterSkillRollout(
            $tenant->id,
            'hello-world',
            123,
            $jobRow->id,
            99,
        );

        $job->handle($profileSync);

        $jobRow->refresh();

        $this->assertSame(ProvisioningJobStatus::Completed, $jobRow->status);
        $this->assertNotNull($jobRow->started_at);
        $this->assertNotNull($jobRow->completed_at);
        $this->assertNull($jobRow->error_message);
    }

    private function seedTenant(string $slug): Tenant
    {
        $user = User::query()->create([
            'name' => ucfirst(str_replace('-', ' ', $slug)),
            'email' => $slug.'@example.com',
            'password' => 'super-secret',
        ]);

        $tenant = Tenant::query()->create([
            'tenant_id' => 'tenant_'.$slug,
            'slug' => $slug,
            'business_name' => ucfirst(str_replace('-', ' ', $slug)),
            'industry' => 'Services',
            'skill_pack' => 'Client Support',
            'user_id' => $user->id,
            'server_id' => Server::query()->firstOrFail()->id,
            'trial_status' => \App\Enums\TrialStatus::Active,
            'provisioning_status' => TenantProvisioningStatus::Ready,
            'onboarding_status' => 'complete',
            'onboarding_step' => 7,
            'agent_status' => 'live',
            'runtime_path' => '/srv/sync360/runtime/tenants/'.$slug,
            'workspace_url' => 'https://'.$slug.'.workspace.test',
            'tone' => 'friendly',
            'channel' => 'telegram',
            'channel_config' => ['telegram_bot_token' => 'telegram-bot-token'],
        ]);

        BusinessProfile::query()->create([
            'tenant_id' => $tenant->id,
            'business_name' => $tenant->business_name,
            'industry' => $tenant->industry,
            'description' => 'Helpful business description.',
            'contact_email' => $user->email,
            'services' => ['General support'],
        ]);

        BusinessProfileFiles::query()->create([
            'tenant_id' => $tenant->id,
            'identity_markdown' => '# Identity',
            'soul_markdown' => '# Soul',
            'user_markdown' => '# User',
            'bootstrap_markdown' => '# Bootstrap',
            'generated_at' => now(),
        ]);

        return $tenant;
    }
}
