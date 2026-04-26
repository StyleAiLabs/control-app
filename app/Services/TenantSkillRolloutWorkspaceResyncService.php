<?php

namespace App\Services;

use App\Enums\ProvisioningJobStatus;
use App\Jobs\ApplyTenantAgentCustomization;
use App\Jobs\ResyncLiveTenantWorkspaceAfterSkillRollout;
use App\Models\ProvisioningJob;
use App\Models\SkillCatalogVersion;
use App\Models\Tenant;
use Illuminate\Support\Collection;

class TenantSkillRolloutWorkspaceResyncService
{
    public function queueFollowUpForCompletedApplyJob(Tenant $tenant, ProvisioningJob $applyJob): ?ProvisioningJob
    {
        $context = $this->eligibleContext($tenant, $applyJob);

        if (! $context) {
            return null;
        }

        $existing = $this->existingFollowUpJob($tenant->id, $applyJob->id);

        if ($existing) {
            return $existing;
        }

        $resyncJob = ProvisioningJob::query()->create([
            'tenant_id' => $tenant->id,
            'job_type' => ResyncLiveTenantWorkspaceAfterSkillRollout::JOB_TYPE,
            'status' => ProvisioningJobStatus::Queued,
            'payload_json' => [
                'source' => 'skill_rollout_auto_resync',
                'skill_key' => $context['skill_key'],
                'skill_catalog_version_id' => $context['skill_catalog_version_id'],
                'source_provisioning_job_id' => $applyJob->id,
            ],
        ]);

        ResyncLiveTenantWorkspaceAfterSkillRollout::dispatch(
            $tenant->id,
            $context['skill_key'],
            $context['skill_catalog_version_id'],
            $resyncJob->id,
            $applyJob->id,
        )->afterCommit();

        return $resyncJob;
    }

    public function recoverMissingFollowUps(int $limit = 100): int
    {
        $recovered = 0;

        $this->candidateApplyJobs($limit)->each(function (ProvisioningJob $applyJob) use (&$recovered): void {
            $tenant = $applyJob->tenant;

            if (! $tenant instanceof Tenant) {
                return;
            }

            if ($this->existingFollowUpJob($tenant->id, $applyJob->id)) {
                return;
            }

            if ($this->queueFollowUpForCompletedApplyJob($tenant, $applyJob)) {
                $recovered++;
            }
        });

        return $recovered;
    }

    /**
     * @return Collection<int, ProvisioningJob>
     */
    private function candidateApplyJobs(int $limit): Collection
    {
        return ProvisioningJob::query()
            ->with('tenant')
            ->where('job_type', ApplyTenantAgentCustomization::JOB_TYPE)
            ->where('status', ProvisioningJobStatus::Completed)
            ->where('payload_json->source', 'skill_rollout')
            ->orderByDesc('id')
            ->limit(max(1, $limit))
            ->get();
    }

    private function existingFollowUpJob(int $tenantId, int $sourceProvisioningJobId): ?ProvisioningJob
    {
        return ProvisioningJob::query()
            ->where('tenant_id', $tenantId)
            ->where('job_type', ResyncLiveTenantWorkspaceAfterSkillRollout::JOB_TYPE)
            ->where('payload_json->source', 'skill_rollout_auto_resync')
            ->where('payload_json->source_provisioning_job_id', $sourceProvisioningJobId)
            ->latest('id')
            ->first();
    }

    /**
     * @return array{skill_key:string, skill_catalog_version_id:int}|null
     */
    private function eligibleContext(Tenant $tenant, ProvisioningJob $applyJob): ?array
    {
        $payload = is_array($applyJob->payload_json) ? $applyJob->payload_json : [];

        if (($payload['source'] ?? null) !== 'skill_rollout') {
            return null;
        }

        if ($tenant->agent_status !== 'live') {
            return null;
        }

        $versionId = (int) ($payload['skill_catalog_version_id'] ?? 0);
        $skillKey = is_string($payload['skill_key'] ?? null) ? trim((string) $payload['skill_key']) : '';

        if ($versionId <= 0 || $skillKey === '') {
            return null;
        }

        $version = SkillCatalogVersion::query()->find($versionId);
        $runtimeType = is_array($version?->manifest_json) ? ($version->manifest_json['runtime_type'] ?? null) : null;

        if ($runtimeType !== 'sync360_workspace') {
            return null;
        }

        return [
            'skill_key' => $skillKey,
            'skill_catalog_version_id' => $versionId,
        ];
    }
}
