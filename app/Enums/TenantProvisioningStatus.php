<?php

namespace App\Enums;

enum TenantProvisioningStatus: string
{
    case Pending = 'pending';
    case Provisioning = 'provisioning';
    case Ready = 'ready';
    case Failed = 'failed';
}
