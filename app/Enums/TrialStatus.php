<?php

namespace App\Enums;

enum TrialStatus: string
{
    case Active = 'trial_active';
    case Expired = 'trial_expired';
}
