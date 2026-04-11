<?php

namespace App\Services;

use App\Contracts\TenantProvisioner;
use App\Enums\ProvisioningJobStatus;
use App\Enums\TenantProvisioningStatus;
use App\Models\ProvisioningJob;
use App\Models\Tenant;

class LocalTenantProvisioningService implements TenantProvisioner
{
    public function __construct(private readonly TenantRuntimeService $runtime)
    {
    }

    public function provision(Tenant $tenant, ProvisioningJob $provisioningJob): void
    {
        $tenant->forceFill([
            'provisioning_status' => TenantProvisioningStatus::Provisioning,
        ])->save();

        $provisioningJob->forceFill([
            'status' => ProvisioningJobStatus::Running,
            'started_at' => now(),
            'completed_at' => null,
            'error_message' => null,
        ])->save();

        $delay = max(0, (int) config('sync360.provisioning.fake_delay_seconds', 3));

        if ($delay > 0) {
            sleep($delay);
        }

        $assignedPort = $tenant->assigned_port ?? $this->runtime->allocatePort($tenant);
        $runtimePath = $this->runtime->prepareRuntime($tenant, $provisioningJob, $assignedPort, [
            'PROVISIONING_DRIVER' => 'local',
        ], [
            'provisioning_driver' => 'local',
        ]);
        $workspaceUrl = $this->runtime->workspaceUrl($tenant, $assignedPort);

        $tenant->forceFill([
            'assigned_port' => $assignedPort,
            'runtime_path' => $runtimePath,
            'workspace_url' => $workspaceUrl,
            'provisioning_status' => TenantProvisioningStatus::Ready,
        ])->save();

        $provisioningJob->forceFill([
            'status' => ProvisioningJobStatus::Completed,
            'completed_at' => now(),
            'error_message' => null,
        ])->save();
    }
}
