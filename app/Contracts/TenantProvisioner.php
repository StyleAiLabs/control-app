<?php

namespace App\Contracts;

use App\Models\ProvisioningJob;
use App\Models\Tenant;

interface TenantProvisioner
{
    public function provision(Tenant $tenant, ProvisioningJob $provisioningJob): void;
}
