<?php

namespace App\Jobs;

use App\Models\Tenant;
use App\Services\TenantInboxTriagePollingService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ProcessTenantInboxTriage implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 120;

    public function __construct(
        public readonly int $tenantId,
    ) {}

    public function handle(TenantInboxTriagePollingService $polling): void
    {
        $tenant = Tenant::query()
            ->with(['server', 'googleCredential', 'inboxMonitorState'])
            ->findOrFail($this->tenantId);

        $polling->pollTenant($tenant);
    }
}
