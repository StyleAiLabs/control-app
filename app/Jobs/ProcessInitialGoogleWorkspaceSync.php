<?php

namespace App\Jobs;

use App\Enums\ProvisioningJobStatus;
use App\Models\ProvisioningJob;
use App\Models\Tenant;
use App\Services\TenantAgentSyncService;
use App\Support\GoogleWorkspaceFeature;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class ProcessInitialGoogleWorkspaceSync implements ShouldQueue
{
    use Queueable;

    public const JOB_TYPE = 'initial_google_workspace_sync';

    public int $tries = 1;

    public int $timeout = 120;

    public function __construct(
        public readonly int $tenantId,
        public readonly int $provisioningJobId,
    ) {}

    public function handle(TenantAgentSyncService $agentSync): void
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
            $agentSync->syncConnectedGoogleWorkspaceOrFail(
                $tenant->fresh(GoogleWorkspaceFeature::tenantRelations(['server']))
            );

            $job->forceFill([
                'status' => ProvisioningJobStatus::Completed,
                'completed_at' => now(),
                'error_message' => null,
            ])->save();
        } catch (Throwable $exception) {
            $this->markAsFailed($job, $exception);

            throw $exception;
        }
    }

    public function failed(?Throwable $exception): void
    {
        if (! $exception) {
            return;
        }

        $job = ProvisioningJob::query()->find($this->provisioningJobId);

        if ($job) {
            $this->markAsFailed($job, $exception);
        }
    }

    private function markAsFailed(ProvisioningJob $job, Throwable $exception): void
    {
        $job->forceFill([
            'status' => ProvisioningJobStatus::Failed,
            'completed_at' => now(),
            'error_message' => $exception->getMessage(),
        ])->save();
    }
}
