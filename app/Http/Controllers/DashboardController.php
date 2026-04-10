<?php

namespace App\Http\Controllers;

use App\Enums\TenantProvisioningStatus;
use App\Enums\TrialStatus;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class DashboardController extends Controller
{
    public function index(Request $request): View|RedirectResponse
    {
        if ($request->user()->is_admin && ! $request->user()->tenant) {
            return redirect()->route('admin.index');
        }

        $tenant = $request->user()->tenant()->firstOrFail();

        return view('dashboard', [
            'tenant' => $tenant,
            'firstName' => Str::of($request->user()->name)->before(' ')->value() ?: $request->user()->name,
            'trialContent' => $this->trialContent($tenant->trial_status),
            'provisioningContent' => $this->provisioningContent($tenant->provisioning_status),
        ]);
    }

    /**
     * @return array{label: string, description: string}
     */
    private function trialContent(TrialStatus $status): array
    {
        return match ($status) {
            TrialStatus::Active => [
                'label' => 'Free trial active',
                'description' => 'You can explore everything and start configuring your digital employee now.',
            ],
            TrialStatus::Expired => [
                'label' => 'Trial ended',
                'description' => 'Your workspace is still here. Reach out when you are ready to reactivate it.',
            ],
        };
    }

    /**
     * @return array{label: string, description: string}
     */
    private function provisioningContent(TenantProvisioningStatus $status): array
    {
        return match ($status) {
            TenantProvisioningStatus::Pending => [
                'label' => 'Queued for setup',
                'description' => 'We have your request and are getting your workspace lined up.',
            ],
            TenantProvisioningStatus::Provisioning => [
                'label' => 'Preparing your workspace',
                'description' => 'Your workspace is being configured and checked behind the scenes.',
            ],
            TenantProvisioningStatus::Ready => [
                'label' => 'Workspace is live',
                'description' => 'Your digital employee is online and ready for the next step.',
            ],
            TenantProvisioningStatus::Failed => [
                'label' => 'Setup needs attention',
                'description' => 'Something interrupted setup, but your workspace can be retried quickly.',
            ],
        };
    }
}
