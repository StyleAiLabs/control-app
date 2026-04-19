<?php

namespace Tests\Feature;

use App\Contracts\TenantProvisioner;
use App\Enums\ProvisioningJobStatus;
use App\Enums\TenantProvisioningStatus;
use App\Enums\TrialStatus;
use App\Jobs\ProcessInitialGoogleWorkspaceSync;
use App\Jobs\ProcessTenantProvisioning;
use App\Models\ProvisioningJob;
use App\Models\Server;
use App\Models\Tenant;
use App\Models\TenantGoogleCredential;
use App\Models\User;
use App\Services\WorkspaceReadyEmailService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class ProvisioningFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_provisioning_job_processes_and_creates_runtime_files(): void
    {
        [$user, $tenant, $job] = $this->seedTenantAndJob();

        ProcessTenantProvisioning::dispatchSync($tenant->id, $job->id);

        $tenant->refresh();
        $job->refresh();

        $this->assertSame(TenantProvisioningStatus::Ready, $tenant->provisioning_status);
        $this->assertSame(ProvisioningJobStatus::Completed, $job->status);
        $this->assertSame(4100, $tenant->assigned_port);
        $this->assertSame('https://acme-plumbing.workspace.test', $tenant->workspace_url);
        $this->assertNotNull($tenant->runtime_path);

        $this->assertFileExists($tenant->runtime_path.'/.env');
        $this->assertFileExists($tenant->runtime_path.'/metadata.json');
        $this->assertFileExists($tenant->runtime_path.'/config');
        $this->assertFileExists($tenant->runtime_path.'/data');
        $this->assertFileExists($tenant->runtime_path.'/logs');

        $this->actingAs($user);

        $this->get('/tenant/status')
            ->assertOk()
            ->assertJson([
                'provisioning_status' => 'ready',
                'workspace_url' => 'https://acme-plumbing.workspace.test',
            ])
            ->assertJsonPath('ready_redirect', null)
            ->assertJsonPath('customer_ready', false)
            ->assertJsonPath('blocking_code', 'google_connect_required');

        $this->get('/tenant/workspace-ready')
            ->assertOk()
            ->assertSee('Connect Google Workspace')
            ->assertSee('Continue Setup')
            ->assertDontSee('Assigned Port')
            ->assertDontSee($tenant->runtime_path);

        $this->get('/dashboard')
            ->assertOk()
            ->assertSee('Welcome back, Alice.')
            ->assertSee('workspace is live and step 1 is next', escape: false)
            ->assertDontSee($tenant->tenant_id)
            ->assertDontSee('Assigned Port')
            ->assertDontSee($tenant->runtime_path);
    }

    public function test_provisioning_failure_marks_tenant_and_job_as_failed(): void
    {
        [, $tenant, $job] = $this->seedTenantAndJob();

        $mock = Mockery::mock(TenantProvisioner::class);
        $mock->shouldReceive('provision')
            ->once()
            ->andThrow(new RuntimeException('Runtime folder creation failed.'));

        $this->instance(TenantProvisioner::class, $mock);

        try {
            ProcessTenantProvisioning::dispatchSync($tenant->id, $job->id);
            $this->fail('Provisioning should have thrown an exception.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Runtime folder creation failed.', $exception->getMessage());
        }

        $tenant->refresh();
        $job->refresh();

        $this->assertSame(TenantProvisioningStatus::Failed, $tenant->provisioning_status);
        $this->assertSame(ProvisioningJobStatus::Failed, $job->status);
        $this->assertSame('Runtime folder creation failed.', $job->error_message);
    }

    public function test_provisioning_success_sends_workspace_ready_email_and_scrubs_stored_password(): void
    {
        config()->set('services.brevo.enabled', true);
        config()->set('services.brevo.key', 'test-brevo-key');
        config()->set('services.brevo.sender_email', 'hello@sync360.test');
        config()->set('services.brevo.sender_name', 'Sync360');

        Http::fake([
            'https://api.brevo.com/v3/smtp/email' => Http::response(['messageId' => 'brevo-123'], 201),
        ]);

        [, $tenant, $job] = $this->seedTenantAndJob();

        ProcessTenantProvisioning::dispatchSync($tenant->id, $job->id);

        $job->refresh();

        Http::assertSent(function ($request): bool {
            $data = $request->data();

            return $request->url() === 'https://api.brevo.com/v3/smtp/email'
                && $request->hasHeader('api-key', 'test-brevo-key')
                && ($data['to'][0]['email'] ?? null) === 'alice@example.com'
                && str_contains((string) ($data['subject'] ?? ''), 'workspace has been created')
                && str_contains((string) ($data['htmlContent'] ?? ''), 'continue your setup')
                && str_contains((string) ($data['htmlContent'] ?? ''), 'Username:')
                && str_contains((string) ($data['htmlContent'] ?? ''), 'Password:')
                && str_contains((string) ($data['htmlContent'] ?? ''), 'https://acme-plumbing.workspace.test');
        });

        $this->assertSame('alice@example.com', $job->payload_json['workspace_login_email'] ?? null);
        $this->assertArrayNotHasKey('workspace_password_encrypted', $job->payload_json ?? []);
        $this->assertArrayHasKey('workspace_ready_email_sent_at', $job->payload_json ?? []);
    }

    public function test_provisioning_completion_queues_initial_google_workspace_sync_for_connected_tenant(): void
    {
        [, $tenant, $job] = $this->seedTenantAndJob();

        TenantGoogleCredential::query()->create([
            'tenant_id' => $tenant->id,
            'status' => TenantGoogleCredential::STATUS_CONNECTED,
            'runtime_sync_status' => TenantGoogleCredential::RUNTIME_SYNC_PENDING,
            'google_email' => 'owner@example.com',
            'access_token' => 'google-access-token',
            'refresh_token' => 'google-refresh-token',
            'scopes' => ['openid', 'email'],
            'connected_at' => now(),
        ]);

        Queue::fake([ProcessInitialGoogleWorkspaceSync::class]);

        ProcessTenantProvisioning::dispatchSync($tenant->id, $job->id);

        $tenant->refresh();
        $job->refresh();

        $this->assertSame(TenantProvisioningStatus::Ready, $tenant->provisioning_status);
        $this->assertSame(ProvisioningJobStatus::Completed, $job->status);

        Queue::assertPushed(ProcessInitialGoogleWorkspaceSync::class, function (ProcessInitialGoogleWorkspaceSync $queuedJob) use ($tenant): bool {
            return $queuedJob->tenantId === $tenant->id;
        });

        $syncJob = ProvisioningJob::query()
            ->where('tenant_id', $tenant->id)
            ->where('job_type', ProcessInitialGoogleWorkspaceSync::JOB_TYPE)
            ->latest('id')
            ->first();

        $this->assertNotNull($syncJob);
        $this->assertSame('queued', $syncJob?->status?->value);
    }

    public function test_provisioning_completion_replays_saved_channel_config_when_runtime_is_ready(): void
    {
        [, $tenant, $job] = $this->seedTenantAndJob();

        $tenant->forceFill([
            'channel' => 'telegram',
            'channel_config' => ['telegram_bot_token' => 'telegram-bot-token'],
        ])->save();

        $mock = Mockery::mock(TenantProvisioner::class);
        $mock->shouldReceive('provision')
            ->once()
            ->withArgs(function (Tenant $provisioningTenant, ProvisioningJob $provisioningJob) use ($tenant, $job): bool {
                $localRuntimePath = config('sync360.runtime_root').'/'.$tenant->slug;
                \Illuminate\Support\Facades\File::ensureDirectoryExists($localRuntimePath.'/config');
                \Illuminate\Support\Facades\File::put($localRuntimePath.'/.env', implode(PHP_EOL, [
                    'OPENCLAW_GATEWAY_TOKEN=test-token',
                    'OPENAI_API_KEY=sk-tenant-acme',
                    'OPENAI_BASE_URL=https://litellm.stylesoftware.co.nz',
                    '',
                ]));
                \Illuminate\Support\Facades\File::put($localRuntimePath.'/config/openclaw.json', json_encode([
                    'gateway' => [
                        'auth' => [
                            'mode' => 'token',
                            'token' => 'test-token',
                        ],
                    ],
                ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL);

                $provisioningTenant->forceFill([
                    'assigned_port' => 4100,
                    'runtime_path' => '/srv/sync360/runtime/tenants/acme-plumbing',
                    'workspace_url' => 'https://acme-plumbing.workspace.test',
                    'provisioning_status' => TenantProvisioningStatus::Ready,
                ])->save();

                $provisioningJob->forceFill([
                    'status' => ProvisioningJobStatus::Completed,
                    'completed_at' => now(),
                ])->save();

                return $provisioningTenant->is($tenant) && $provisioningJob->is($job);
            })
            ->andReturnNull();
        $this->instance(TenantProvisioner::class, $mock);

        ProcessTenantProvisioning::dispatchSync($tenant->id, $job->id);

        $config = json_decode(\Illuminate\Support\Facades\File::get(config('sync360.runtime_root').'/'.$tenant->slug.'/config/openclaw.json'), true);

        $this->assertSame('telegram-bot-token', $config['channels']['telegram']['botToken'] ?? null);
        $this->assertSame('open', $config['channels']['telegram']['dmPolicy'] ?? null);
    }

    public function test_brevo_failure_does_not_mark_provisioning_as_failed(): void
    {
        config()->set('services.brevo.enabled', true);
        config()->set('services.brevo.key', 'test-brevo-key');
        config()->set('services.brevo.sender_email', 'hello@sync360.test');
        config()->set('services.brevo.sender_name', 'Sync360');

        Http::fake([
            'https://api.brevo.com/v3/smtp/email' => Http::response(['message' => 'forbidden'], 403),
        ]);

        Log::shouldReceive('warning')
            ->once()
            ->withArgs(fn (string $message, array $context): bool => str_contains($message, 'workspace ready email') && ($context['tenant_id'] ?? null) > 0);

        [, $tenant, $job] = $this->seedTenantAndJob();

        ProcessTenantProvisioning::dispatchSync($tenant->id, $job->id);

        $tenant->refresh();
        $job->refresh();

        $this->assertSame(TenantProvisioningStatus::Ready, $tenant->provisioning_status);
        $this->assertSame(ProvisioningJobStatus::Completed, $job->status);
        $this->assertIsString($job->payload_json['workspace_password_encrypted'] ?? null);
        $this->assertArrayNotHasKey('workspace_ready_email_sent_at', $job->payload_json ?? []);
    }

    private function seedTenantAndJob(): array
    {
        $user = User::query()->create([
            'name' => 'Alice Admin',
            'email' => 'alice@example.com',
            'password' => 'super-secret',
        ]);

        $tenant = Tenant::query()->create([
            'tenant_id' => 'tenant_01',
            'slug' => 'acme-plumbing',
            'business_name' => 'Acme Plumbing',
            'industry' => 'Trades',
            'skill_pack' => 'Operations Core',
            'user_id' => $user->id,
            'server_id' => Server::query()->firstOrFail()->id,
            'trial_status' => TrialStatus::Active,
            'provisioning_status' => TenantProvisioningStatus::Pending,
        ]);

        $job = ProvisioningJob::query()->create([
            'tenant_id' => $tenant->id,
            'job_type' => 'provision_tenant',
            'status' => ProvisioningJobStatus::Queued,
            'payload_json' => app(WorkspaceReadyEmailService::class)->withProvisioningCredentials([
                'requested_from' => 'test',
            ], 'alice@example.com', 'super-secret'),
        ]);

        return [$user, $tenant, $job];
    }
}
