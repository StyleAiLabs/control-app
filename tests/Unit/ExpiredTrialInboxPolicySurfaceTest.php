<?php

namespace Tests\Unit;

use App\Enums\TrialStatus;
use App\Models\Tenant;
use App\Services\ExpiredTrialInboxPolicySurface;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class ExpiredTrialInboxPolicySurfaceTest extends TestCase
{
    public function test_admin_state_distinguishes_fully_paused_monitoring_only_and_fully_allowed(): void
    {
        $service = app(ExpiredTrialInboxPolicySurface::class);

        $fullyPaused = $service->adminState($this->expiredTenant());
        $monitoringOnly = $service->adminState($this->expiredTenant([
            'allow_polling_when_trial_expired' => true,
        ]));
        $fullyAllowed = $service->adminState($this->expiredTenant([
            'allow_polling_when_trial_expired' => true,
            'allow_runtime_replies_when_trial_expired' => true,
            'allow_litellm_when_trial_expired' => true,
        ]));

        $this->assertSame('fully_paused', $fullyPaused['state']);
        $this->assertSame('Fully paused while expired', $fullyPaused['label']);

        $this->assertSame('monitoring_only', $monitoringOnly['state']);
        $this->assertSame('Monitoring only while expired', $monitoringOnly['label']);

        $this->assertSame('fully_allowed', $fullyAllowed['state']);
        $this->assertSame('Fully allowed while expired', $fullyAllowed['label']);
    }

    public function test_customer_state_stays_paused_until_all_three_overrides_are_enabled(): void
    {
        $service = app(ExpiredTrialInboxPolicySurface::class);

        $monitoringOnly = $service->customerState($this->expiredTenant([
            'allow_polling_when_trial_expired' => true,
        ]));
        $fullyAllowed = $service->customerState($this->expiredTenant([
            'allow_polling_when_trial_expired' => true,
            'allow_runtime_replies_when_trial_expired' => true,
            'allow_litellm_when_trial_expired' => true,
        ]));

        $this->assertSame('paused', $monitoringOnly['state']);
        $this->assertSame('Paused while expired', $monitoringOnly['label']);

        $this->assertSame('active', $fullyAllowed['state']);
        $this->assertSame('Active while expired', $fullyAllowed['label']);
    }

    /**
     * @param  array<string,mixed>  $overrides
     */
    private function expiredTenant(array $overrides = []): Tenant
    {
        return new Tenant(array_merge([
            'trial_status' => TrialStatus::Expired,
            'created_at' => Carbon::parse('2026-04-01 00:00:00'),
            'allow_polling_when_trial_expired' => false,
            'allow_runtime_replies_when_trial_expired' => false,
            'allow_litellm_when_trial_expired' => false,
            'billing_first_paid_at' => null,
        ], $overrides));
    }
}
