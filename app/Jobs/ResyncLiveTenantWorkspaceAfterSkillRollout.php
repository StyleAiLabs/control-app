<?php

namespace App\Jobs;

use App\Enums\ProvisioningJobStatus;
use App\Models\ProvisioningJob;
use App\Models\Tenant;
use App\Services\TenantProfileSyncService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class ResyncLiveTenantWorkspaceAfterSkillRollout implements ShouldQueue
{
    use Queueable;

    public const JOB_TYPE = 'tenant_skill_rollout_workspace_resync';

    public int $tries = 1;

    public int $timeout = 180;

    public function __construct(
        public readonly int $tenantId,
        public readonly string $skillKey,
        public readonly int $skillCatalogVersionId,
        public readonly int $provisioningJobId,
        public readonly int $sourceProvisioningJobId,
    ) {}

    public function handle(TenantProfileSyncService $profileSync): void
    {
        $tenant = Tenant::query()->findOrFail($this->tenantId);
        $job = ProvisioningJob::query()->findOrFail($this->provisioningJobId);

        $job->forceFill([
            'status' => ProvisioningJobStatus::Running,
            'started_at' => now(),
            'completed_at' => null,
            'error_message' => null,
        ])->save();

        try {
            $profileSync->regenerateAndSyncWorkspaceOnly(
                $tenant->fresh(['businessProfile', 'businessProfileFiles', 'server'])
            );

            $job->forceFill([
                'status' => ProvisioningJobStatus::Completed,
                'completed_at' => now(),
                'error_message' => null,
            ])->save();
        } catch (Throwable $exception) {
            $job->forceFill([
                'status' => ProvisioningJobStatus::Failed,
                'completed_at' => now(),
                'error_message' => $exception->getMessage(),
            ])->save();

            throw $exception;
        }
    }
}
