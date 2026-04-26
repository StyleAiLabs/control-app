<?php

namespace App\Jobs;

use App\Models\ProvisioningJob;
use App\Models\Tenant;
use App\Models\TenantAgentCustomizationApply;
use App\Services\TenantAgentCustomizationService;
use App\Services\TenantRuntimeCustomizationComposer;
use App\Services\TenantSkillRolloutWorkspaceResyncService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ApplyTenantAgentCustomization implements ShouldQueue
{
    use Queueable;

    public const JOB_TYPE = 'tenant_agent_customization_apply';

    public int $tries = 1;

    public int $timeout = 120;

    public function __construct(
        public readonly int $tenantId,
        public readonly int $provisioningJobId,
        public readonly string $action = TenantAgentCustomizationApply::ACTION_APPLY,
    ) {}

    public function handle(
        TenantAgentCustomizationService $customizations,
        TenantRuntimeCustomizationComposer $composer,
        TenantSkillRolloutWorkspaceResyncService $workspaceResyncs,
    ): void {
        $tenant = Tenant::query()->findOrFail($this->tenantId);
        $job = ProvisioningJob::query()->findOrFail($this->provisioningJobId);

        $customizations->apply($tenant, $job, $composer, $this->action);
        $workspaceResyncs->queueFollowUpForCompletedApplyJob($tenant->fresh(), $job->fresh());
    }
}
