<?php

namespace App\Services;

use App\Models\Tenant;

class ExpiredTrialInboxPolicySurface
{
    /**
     * @return array{
     *   applies:bool,
     *   state:string,
     *   polling_allowed:bool,
     *   runtime_replies_allowed:bool,
     *   litellm_allowed:bool,
     *   label:?string,
     *   note:?string,
     *   status:?string
     * }
     */
    public function customerState(Tenant $tenant): array
    {
        $flags = $this->flags($tenant);

        if (! $flags['applies']) {
            return [
                ...$flags,
                'state' => 'normal',
                'label' => null,
                'note' => null,
                'status' => null,
            ];
        }

        if ($this->isFullyAllowed($flags)) {
            return [
                ...$flags,
                'state' => 'active',
                'label' => 'Active while expired',
                'note' => 'Expired-trial access is still enabled, so inbox monitoring and customer replies stay live for now.',
                'status' => 'ready',
            ];
        }

        return [
            ...$flags,
            'state' => 'paused',
            'label' => 'Paused while expired',
            'note' => 'Inbox monitoring, customer replies, and live assistant work stay paused until expired-trial access is fully restored.',
            'status' => 'warning',
        ];
    }

    /**
     * @return array{
     *   applies:bool,
     *   state:string,
     *   polling_allowed:bool,
     *   runtime_replies_allowed:bool,
     *   litellm_allowed:bool,
     *   label:?string,
     *   note:?string,
     *   status:?string
     * }
     */
    public function adminState(Tenant $tenant): array
    {
        $flags = $this->flags($tenant);

        if (! $flags['applies']) {
            return [
                ...$flags,
                'state' => 'not_applicable',
                'label' => null,
                'note' => null,
                'status' => null,
            ];
        }

        if ($this->isFullyAllowed($flags)) {
            return [
                ...$flags,
                'state' => 'fully_allowed',
                'label' => 'Fully allowed while expired',
                'note' => 'Inbox polling, customer-facing replies, and LiteLLM stay enabled while the trial is expired.',
                'status' => 'ready',
            ];
        }

        if ($this->isMonitoringOnly($flags)) {
            return [
                ...$flags,
                'state' => 'monitoring_only',
                'label' => 'Monitoring only while expired',
                'note' => 'Inbox polling can continue, but customer-facing replies remain paused while the trial is expired.',
                'status' => 'warning',
            ];
        }

        if ($this->isFullyPaused($flags)) {
            return [
                ...$flags,
                'state' => 'fully_paused',
                'label' => 'Fully paused while expired',
                'note' => 'Inbox polling, customer-facing replies, and LiteLLM are all paused while the trial is expired.',
                'status' => 'warning',
            ];
        }

        return [
            ...$flags,
            'state' => 'custom_mix',
            'label' => 'Custom expired-trial access mix',
            'note' => $this->customMixNote($flags),
            'status' => 'warning',
        ];
    }

    /**
     * @return array{applies:bool,polling_allowed:bool,runtime_replies_allowed:bool,litellm_allowed:bool}
     */
    private function flags(Tenant $tenant): array
    {
        $applies = ! $tenant->hasPaidActivation() && $tenant->isTrialExpired();

        return [
            'applies' => $applies,
            'polling_allowed' => $applies && (bool) $tenant->allow_polling_when_trial_expired,
            'runtime_replies_allowed' => $applies && (bool) $tenant->allow_runtime_replies_when_trial_expired,
            'litellm_allowed' => $applies && (bool) $tenant->allow_litellm_when_trial_expired,
        ];
    }

    /**
     * @param  array{applies:bool,polling_allowed:bool,runtime_replies_allowed:bool,litellm_allowed:bool}  $flags
     */
    private function isFullyAllowed(array $flags): bool
    {
        return $flags['applies']
            && $flags['polling_allowed']
            && $flags['runtime_replies_allowed']
            && $flags['litellm_allowed'];
    }

    /**
     * @param  array{applies:bool,polling_allowed:bool,runtime_replies_allowed:bool,litellm_allowed:bool}  $flags
     */
    private function isMonitoringOnly(array $flags): bool
    {
        return $flags['applies']
            && $flags['polling_allowed']
            && ! $flags['runtime_replies_allowed'];
    }

    /**
     * @param  array{applies:bool,polling_allowed:bool,runtime_replies_allowed:bool,litellm_allowed:bool}  $flags
     */
    private function isFullyPaused(array $flags): bool
    {
        return $flags['applies']
            && ! $flags['polling_allowed']
            && ! $flags['runtime_replies_allowed']
            && ! $flags['litellm_allowed'];
    }

    /**
     * @param  array{polling_allowed:bool,runtime_replies_allowed:bool,litellm_allowed:bool}  $flags
     */
    private function customMixNote(array $flags): string
    {
        $states = [
            'Inbox polling '.($flags['polling_allowed'] ? 'is allowed' : 'is paused'),
            'customer-facing replies '.($flags['runtime_replies_allowed'] ? 'are allowed' : 'are paused'),
            'LiteLLM '.($flags['litellm_allowed'] ? 'stays active' : 'is suspended'),
        ];

        return implode(', ', $states).'.';
    }
}
