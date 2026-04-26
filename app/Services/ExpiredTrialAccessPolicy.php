<?php

namespace App\Services;

use App\Models\Tenant;

class ExpiredTrialAccessPolicy
{
    public function canPollInbox(Tenant $tenant): bool
    {
        return ! $tenant->isTrialExpired() || $tenant->allow_polling_when_trial_expired;
    }

    public function canSendCustomerFacingRuntimeWork(Tenant $tenant): bool
    {
        return ! $tenant->isTrialExpired() || $tenant->allow_runtime_replies_when_trial_expired;
    }

    public function shouldEnableDirectCustomerChannels(Tenant $tenant): bool
    {
        return $this->canSendCustomerFacingRuntimeWork($tenant);
    }

    public function shouldSuspendLiteLlmOnExpiry(Tenant $tenant): bool
    {
        return ! $tenant->allow_litellm_when_trial_expired;
    }

    public function canSendOperationalAlerts(Tenant $tenant): bool
    {
        $config = is_array($tenant->channel_config) ? $tenant->channel_config : [];

        return trim((string) ($config['telegram_bot_token'] ?? '')) !== ''
            && trim((string) ($config['telegram_default_chat_id'] ?? '')) !== '';
    }
}
