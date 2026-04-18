<?php

namespace Tests\Feature;

use App\Enums\ProvisioningJobStatus;
use App\Enums\TenantProvisioningStatus;
use App\Enums\TrialStatus;
use App\Jobs\ApplyTenantAgentCustomization;
use App\Models\BusinessProfile;
use App\Models\BusinessProfileFiles;
use App\Models\ProvisioningJob;
use App\Models\Tenant;
use App\Models\TenantAgentCustomization;
use App\Models\TenantAgentCustomizationApply;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class AdminTenantCustomizationFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_save_draft_preview_and_queue_apply(): void
    {
        Queue::fake([ApplyTenantAgentCustomization::class]);

        [$admin, $tenant] = $this->seedAdminAndTenant();

        $this->actingAs($admin);

        $this->patch(route('admin.tenants.agent-customization.update', $tenant), [
            'prompt_overrides' => [
                'identity' => [
                    'mode' => 'append',
                    'content' => 'Custom admin identity guidance',
                ],
            ],
            'assigned_skill_pack_ids' => ['appointment-booking'],
            'agent_defaults' => [
                'model' => 'gpt-4.1',
                'default_skill_ids' => ['custom-default-skill'],
            ],
        ])->assertRedirect(route('admin.tenants.show', $tenant));

        $customization = TenantAgentCustomization::query()->firstOrFail();

        $this->assertSame(1, $customization->draft_version);
        $this->assertSame('append', data_get($customization->prompt_overrides_json, 'identity.mode'));
        $this->assertSame(['appointment-booking'], $customization->assigned_skill_pack_ids);
        $this->assertSame('gpt-4.1', data_get($customization->agent_defaults_json, 'model'));

        $preview = $this->postJson(route('admin.tenants.agent-customization.preview', $tenant));

        $preview->assertOk()
            ->assertJsonPath('base_drifted.identity', false);

        $workspaceFiles = $preview->json('workspace_files');

        $this->assertStringContainsString(
            'Custom admin identity guidance',
            (string) ($workspaceFiles['IDENTITY.md'] ?? '')
        );

        $this->post(route('admin.tenants.agent-customization.apply', $tenant))
            ->assertRedirect(route('admin.tenants.show', $tenant));

        $job = ProvisioningJob::query()->latest('id')->first();

        $this->assertNotNull($job);
        $this->assertSame(ProvisioningJobStatus::Queued, $job->status);
        $this->assertSame(ApplyTenantAgentCustomization::JOB_TYPE, $job->job_type);

        Queue::assertPushed(ApplyTenantAgentCustomization::class, function (ApplyTenantAgentCustomization $queuedJob) use ($tenant, $job): bool {
            return $queuedJob->tenantId === $tenant->id
                && $queuedJob->provisioningJobId === $job?->id
                && $queuedJob->action === TenantAgentCustomizationApply::ACTION_APPLY;
        });
    }

    public function test_admin_without_apply_permission_cannot_apply_or_revert(): void
    {
        Queue::fake([ApplyTenantAgentCustomization::class]);
        config()->set('sync360.runtime_customization.apply_authorized_emails', ['allowed@example.com']);

        [$admin, $tenant] = $this->seedAdminAndTenant(email: 'blocked@example.com');

        TenantAgentCustomization::query()->create([
            'tenant_id' => $tenant->id,
            'prompt_overrides_json' => [],
            'assigned_skill_pack_ids' => [],
            'agent_defaults_json' => [],
            'draft_version' => 1,
            'draft_updated_by' => $admin->id,
            'draft_updated_at' => now(),
            'last_applied_input_snapshot_json' => [
                'prompt_overrides' => [],
                'assigned_skill_pack_ids' => [],
                'agent_defaults' => [],
            ],
        ]);

        $this->actingAs($admin);

        $this->post(route('admin.tenants.agent-customization.apply', $tenant))->assertForbidden();
        $this->post(route('admin.tenants.agent-customization.revert', $tenant))->assertForbidden();

        Queue::assertNothingPushed();
    }

    public function test_admin_can_view_apply_log_history(): void
    {
        [$admin, $tenant] = $this->seedAdminAndTenant();

        $customization = TenantAgentCustomization::query()->create([
            'tenant_id' => $tenant->id,
            'prompt_overrides_json' => [],
            'assigned_skill_pack_ids' => [],
            'agent_defaults_json' => [],
            'draft_version' => 1,
            'draft_updated_by' => $admin->id,
            'draft_updated_at' => now(),
        ]);

        TenantAgentCustomizationApply::query()->create([
            'tenant_agent_customization_id' => $customization->id,
            'tenant_id' => $tenant->id,
            'applied_by' => $admin->id,
            'action' => TenantAgentCustomizationApply::ACTION_APPLY,
            'draft_version_applied' => 1,
            'input_snapshot_json' => ['assigned_skill_pack_ids' => []],
            'before_output_hash' => null,
            'after_output_hash' => 'hash-1',
            'status' => TenantAgentCustomizationApply::STATUS_APPLIED,
        ]);

        $this->actingAs($admin);

        $this->getJson(route('admin.tenants.agent-customization.apply-log', $tenant))
            ->assertOk()
            ->assertJsonPath('data.0.after_output_hash', 'hash-1')
            ->assertJsonPath('data.0.action', TenantAgentCustomizationApply::ACTION_APPLY);
    }

    public function test_admin_tenant_page_shows_current_base_prompt_content_in_customization_panel(): void
    {
        [$admin, $tenant] = $this->seedAdminAndTenant();

        $this->actingAs($admin);

        $this->get(route('admin.tenants.show', $tenant))
            ->assertOk()
            ->assertSee('Current IDENTITY.md')
            ->assertSee('Base identity')
            ->assertSee('Current BOOTSTRAP.md')
            ->assertSee('Base bootstrap');
    }

    private function seedAdminAndTenant(string $email = 'allowed@example.com'): array
    {
        $admin = User::query()->create([
            'name' => 'Debug Admin',
            'email' => $email,
            'password' => 'super-secret',
            'is_admin' => true,
        ]);

        $user = User::query()->create([
            'name' => 'Customer User',
            'email' => 'customer@example.com',
            'password' => 'super-secret',
            'is_admin' => false,
        ]);

        $tenant = Tenant::query()->create([
            'tenant_id' => 'tenant_customization_02',
            'slug' => 'customization-shop',
            'business_name' => 'Customization Shop',
            'industry' => 'Retail',
            'skill_pack' => 'Client Support',
            'user_id' => $user->id,
            'server_id' => 1,
            'trial_status' => TrialStatus::Active,
            'provisioning_status' => TenantProvisioningStatus::Ready,
            'agent_status' => 'live',
            'workspace_url' => 'https://customization-shop.workspace.test',
            'runtime_path' => '/srv/sync360/runtime/tenants/customization-shop',
        ]);

        BusinessProfile::query()->create([
            'tenant_id' => $tenant->id,
            'business_name' => 'Customization Shop',
            'industry' => 'Retail',
            'description' => 'Helpful team.',
            'services' => ['Customer support'],
        ]);

        BusinessProfileFiles::query()->create([
            'tenant_id' => $tenant->id,
            'identity_markdown' => "# Identity\n\nBase identity",
            'soul_markdown' => "# Soul\n\nBase soul",
            'user_markdown' => "# User\n\nBase user",
            'bootstrap_markdown' => "# Bootstrap\n\nBase bootstrap",
            'generated_at' => now(),
        ]);

        $runtimeRoot = config('sync360.runtime_root').'/'.$tenant->slug;
        File::ensureDirectoryExists($runtimeRoot.'/config');
        File::put($runtimeRoot.'/config/openclaw.json', json_encode([
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

        return [$admin, $tenant];
    }
}
