<?php

namespace App\Services;

use App\Enums\BillingStatus;
use App\Models\Tenant;

class CommercialAccessPolicy
{
    public function __construct(
        private readonly ExpiredTrialAccessPolicy $expiredTrialAccess,
    ) {
    }

    public function canPollInbox(Tenant $tenant): bool
    {
        if (! $tenant->hasPaidActivation()) {
            return $this->expiredTrialAccess->canPollInbox($tenant);
        }

        return $this->hasCommercialRuntimeAccess($tenant);
    }

    public function canSendCustomerFacingRuntimeWork(Tenant $tenant): bool
    {
        if (! $tenant->hasPaidActivation()) {
            return $this->expiredTrialAccess->canSendCustomerFacingRuntimeWork($tenant);
        }

        return $this->hasCommercialRuntimeAccess($tenant);
    }

    public function canDispatchAiRuntimeWork(Tenant $tenant): bool
    {
        if (! $tenant->hasPaidActivation()) {
            return $this->expiredTrialAccess->canDispatchAiRuntimeWork($tenant);
        }

        return $this->hasCommercialRuntimeAccess($tenant) && ! $this->shouldSuspendLiteLlm($tenant);
    }

    public function shouldEnableDirectCustomerChannels(Tenant $tenant): bool
    {
        if (! $tenant->hasPaidActivation()) {
            return $this->expiredTrialAccess->shouldEnableDirectCustomerChannels($tenant);
        }

        return $this->hasCommercialRuntimeAccess($tenant);
    }

    public function shouldSuspendLiteLlm(Tenant $tenant): bool
    {
        if (! $tenant->hasPaidActivation()) {
            return $this->expiredTrialAccess->shouldSuspendLiteLlmOnExpiry($tenant) && $tenant->isTrialExpired();
        }

        return ! in_array($tenant->billing_status, [
            BillingStatus::Active,
            BillingStatus::PastDue,
        ], true) || $tenant->hasReachedInteractionLimit();
    }

    public function canSendOperationalAlerts(Tenant $tenant): bool
    {
        return $this->expiredTrialAccess->canSendOperationalAlerts($tenant);
    }

    public function runtimePauseReason(Tenant $tenant): ?string
    {
        if (! $tenant->hasPaidActivation()) {
            return $tenant->isTrialExpired() ? 'trial_expired' : null;
        }

        if ($tenant->hasReachedInteractionLimit()) {
            return 'interaction_limit_reached';
        }

        return match ($tenant->billing_status) {
            BillingStatus::Suspended => 'subscription_suspended',
            BillingStatus::Cancelled => 'subscription_cancelled',
            default => null,
        };
    }

    private function hasCommercialRuntimeAccess(Tenant $tenant): bool
    {
        if ($tenant->hasReachedInteractionLimit()) {
            return false;
        }

        return in_array($tenant->billing_status, [
            BillingStatus::Active,
            BillingStatus::PastDue,
        ], true);
    }
}
