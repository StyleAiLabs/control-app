<?php

namespace App\Jobs;

use App\Contracts\TenantProvisioner;
use App\Enums\ProvisioningJobStatus;
use App\Enums\TenantProvisioningStatus;
use App\Models\ProvisioningJob;
use App\Models\Tenant;
use App\Services\WorkspaceReadyEmailService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class ProcessTenantProvisioning implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 120;

    /**
     * Create a new job instance.
     */
    public function __construct(
        public readonly int $tenantId,
        public readonly int $provisioningJobId,
    ) {}

    /**
     * Execute the job.
     */
    public function handle(TenantProvisioner $provisioner, WorkspaceReadyEmailService $workspaceReadyEmail): void
    {
        $tenant = Tenant::query()->findOrFail($this->tenantId);
        $provisioningJob = ProvisioningJob::query()->findOrFail($this->provisioningJobId);

        try {
            $provisioner->provision($tenant, $provisioningJob);
            $workspaceReadyEmail->sendWorkspaceReadyEmail($tenant->fresh('user'), $provisioningJob->fresh());
        } catch (Throwable $exception) {
            $this->markAsFailed($tenant, $provisioningJob, $exception);

            throw $exception;
        }
    }

    public function failed(?Throwable $exception): void
    {
        if (! $exception) {
            return;
        }

        $tenant = Tenant::query()->find($this->tenantId);
        $provisioningJob = ProvisioningJob::query()->find($this->provisioningJobId);

        if ($tenant && $provisioningJob) {
            $this->markAsFailed($tenant, $provisioningJob, $exception);
        }
    }

    private function markAsFailed(Tenant $tenant, ProvisioningJob $provisioningJob, Throwable $exception): void
    {
        $tenant->forceFill([
            'provisioning_status' => TenantProvisioningStatus::Failed,
        ])->save();

        $provisioningJob->forceFill([
            'status' => ProvisioningJobStatus::Failed,
            'completed_at' => now(),
            'error_message' => $exception->getMessage(),
        ])->save();
    }
}
