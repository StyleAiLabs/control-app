<?php

namespace App\Http\Controllers;

use App\Enums\TenantProvisioningStatus;
use App\Enums\TrialStatus;
use App\Models\Tenant;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class TenantSetupController extends Controller
{
    public function show(Request $request): View|RedirectResponse
    {
        $tenant = $request->user()->tenant()->firstOrFail();

        if ($tenant->provisioning_status === TenantProvisioningStatus::Ready) {
            return redirect()->route('tenant.workspace-ready');
        }

        return view('tenant.setup', [
            'tenant' => $tenant,
        ]);
    }

    public function status(Request $request): JsonResponse
    {
        $tenant = $request->user()->tenant()->firstOrFail();

        return response()->json([
            'business_name' => $tenant->business_name,
            'skill_pack' => $tenant->skill_pack,
            'trial_status' => $tenant->trial_status->value,
            'provisioning_status' => $tenant->provisioning_status->value,
            'workspace_url' => $tenant->workspace_url,
            'workspace_route' => $tenant->provisioning_status === TenantProvisioningStatus::Ready
                ? $tenant->workspace_url
                : null,
            'ready_redirect' => $tenant->provisioning_status === TenantProvisioningStatus::Ready
                ? route('tenant.workspace-ready')
                : null,
            'error_message' => $tenant->provisioningJobs()->latest('id')->value('error_message'),
        ]);
    }

    public function ready(Request $request): View|RedirectResponse
    {
        $tenant = $request->user()->tenant()->firstOrFail();

        if ($tenant->provisioning_status !== TenantProvisioningStatus::Ready) {
            return redirect()->route('tenant.setup');
        }

        return view('tenant.workspace-ready', [
            'tenant' => $tenant,
            'firstName' => Str::of($request->user()->name)->before(' ')->value() ?: $request->user()->name,
            'trialLabel' => $this->trialLabel($tenant->trial_status),
            'nextSteps' => $this->nextSteps($tenant),
        ]);
    }

    public function workspace(Request $request, Tenant $tenant): View
    {
        abort_unless($tenant->user_id === $request->user()->id, 403);

        return view('workspace.show', [
            'tenant' => $tenant,
        ]);
    }

    private function trialLabel(TrialStatus $status): string
    {
        return match ($status) {
            TrialStatus::Active => 'Free trial active',
            TrialStatus::Expired => 'Trial ended',
        };
    }

    /**
     * @return list<array{title: string, description: string}>
     */
    private function nextSteps(Tenant $tenant): array
    {
        return [
            [
                'title' => 'Log in to Sync360',
                'description' => 'Open your workspace URL and make sure you can land in the right Sync360 dashboard for '.$tenant->business_name.'.',
            ],
            [
                'title' => 'Add your business context',
                'description' => 'Bring in your FAQs, service details, pricing, and tone so responses sound like your team.',
            ],
            [
                'title' => 'Connect Telegram',
                'description' => 'Finish the Telegram connection in onboarding so you can message your digital employee directly.',
            ],
            [
                'title' => 'Run a live test',
                'description' => 'Send a few sample owner-to-assistant messages and confirm the answers and behavior feel right.',
            ],
        ];
    }
}
