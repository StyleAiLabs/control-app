<?php

namespace App\Jobs;

use App\Enums\ProvisioningJobStatus;
use App\Models\ProvisioningJob;
use App\Models\SkillCatalogVersion;
use App\Models\Tenant;
use App\Models\TenantAgentCustomizationApply;
use App\Services\TenantAgentCustomizationService;
use App\Services\TenantRuntimeCustomizationComposer;
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
    ): void {
        $tenant = Tenant::query()->findOrFail($this->tenantId);
        $job = ProvisioningJob::query()->findOrFail($this->provisioningJobId);

        $customizations->apply($tenant, $job, $composer, $this->action);
        $this->queueWorkspaceResyncAfterRollout($tenant->fresh(), $job);
    }

    private function queueWorkspaceResyncAfterRollout(Tenant $tenant, ProvisioningJob $job): void
    {
        $payload = is_array($job->payload_json) ? $job->payload_json : [];

        if (($payload['source'] ?? null) !== 'skill_rollout') {
            return;
        }

        if ($tenant->agent_status !== 'live') {
            return;
        }

        $versionId = (int) ($payload['skill_catalog_version_id'] ?? 0);
        $skillKey = is_string($payload['skill_key'] ?? null) ? (string) $payload['skill_key'] : '';

        if ($versionId <= 0 || $skillKey === '') {
            return;
        }

        $version = SkillCatalogVersion::query()->find($versionId);
        $runtimeType = is_array($version?->manifest_json) ? ($version->manifest_json['runtime_type'] ?? null) : null;

        if ($runtimeType !== 'sync360_workspace') {
            return;
        }

        $resyncJob = ProvisioningJob::query()->create([
            'tenant_id' => $tenant->id,
            'job_type' => ResyncLiveTenantWorkspaceAfterSkillRollout::JOB_TYPE,
            'status' => ProvisioningJobStatus::Queued,
            'payload_json' => [
                'source' => 'skill_rollout_auto_resync',
                'skill_key' => $skillKey,
                'skill_catalog_version_id' => $versionId,
                'source_provisioning_job_id' => $job->id,
            ],
        ]);

        ResyncLiveTenantWorkspaceAfterSkillRollout::dispatch(
            $tenant->id,
            $skillKey,
            $versionId,
            $resyncJob->id,
            $job->id,
        )->afterCommit();
    }
}
