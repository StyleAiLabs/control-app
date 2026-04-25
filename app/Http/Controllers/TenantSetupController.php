<?php

namespace App\Http\Controllers;

use App\Enums\TenantProvisioningStatus;
use App\Enums\TrialStatus;
use App\Models\Tenant;
use App\Services\TenantAgentSyncService;
use App\Services\TenantWorkspaceDependencyHealthService;
use App\Services\TenantWorkspaceReadinessService;
use App\Support\GoogleWorkspaceFeature;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class TenantSetupController extends Controller
{
    public function __construct(
        private readonly TenantWorkspaceReadinessService $workspaceReadiness,
        private readonly TenantAgentSyncService $agentSync,
        private readonly TenantWorkspaceDependencyHealthService $dependencyHealth,
    ) {
    }

    public function show(Request $request): View|RedirectResponse
    {
        $tenant = $request->user()->tenant()
            ->with(GoogleWorkspaceFeature::tenantRelations())
            ->firstOrFail();
        $readiness = $this->readinessFor($tenant);

        if ($readiness['customer_ready']) {
            return redirect()->route('tenant.workspace-ready');
        }

        return view('tenant.setup', [
            'tenant' => $tenant,
            'dependencyHealth' => $this->dependencyHealth->evaluate($tenant),
        ]);
    }

    public function status(Request $request): JsonResponse
    {
        $tenant = $request->user()->tenant()
            ->with(GoogleWorkspaceFeature::tenantRelations())
            ->firstOrFail();
        $readiness = $this->readinessFor($tenant);

        return response()->json([
            'business_name' => $tenant->business_name,
            'skill_pack' => $tenant->skill_pack,
            'trial_status' => $tenant->trial_status->value,
            'provisioning_status' => $tenant->provisioning_status->value,
            'workspace_url' => $tenant->workspace_url,
            'workspace_route' => $readiness['customer_ready']
                ? $tenant->workspace_url
                : null,
            'ready_redirect' => $readiness['customer_ready']
                ? route('tenant.workspace-ready')
                : null,
            'runtime_ready' => $readiness['runtime_ready'],
            'customer_ready' => $readiness['customer_ready'],
            'go_live_ready' => $readiness['go_live_ready'],
            'blocking_code' => $readiness['blocking_code'],
            'blocking_message' => $readiness['blocking_message'],
            'next_action' => $readiness['next_action'],
            'dependency_health' => $this->dependencyHealth->evaluate($tenant),
            'error_message' => $tenant->provisioningJobs()->latest('id')->value('error_message'),
        ]);
    }

    public function ready(Request $request): View|RedirectResponse
    {
        $tenant = $request->user()->tenant()
            ->with(GoogleWorkspaceFeature::tenantRelations())
            ->firstOrFail();
        $readiness = $this->readinessFor($tenant);

        return view('tenant.workspace-ready', [
            'tenant' => $tenant,
            'firstName' => Str::of($request->user()->name)->before(' ')->value() ?: $request->user()->name,
            'trialLabel' => $this->trialLabel($tenant->trial_status),
            'nextSteps' => $this->nextSteps($tenant, $readiness),
            'workspaceReadiness' => $readiness,
            'dependencyHealth' => $this->dependencyHealth->evaluate($tenant),
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
    /**
     * @param  array<string, mixed>  $workspaceReadiness
     * @return list<array{title: string, description: string}>
     */
    private function nextSteps(Tenant $tenant, array $workspaceReadiness): array
    {
        if (! $workspaceReadiness['customer_ready']) {
            $nextActionLabel = (string) ($workspaceReadiness['next_action']['label'] ?? 'Continue Setup');

            return [
                [
                    'title' => $nextActionLabel,
                    'description' => $workspaceReadiness['blocking_message'] ?? 'Finish the remaining setup steps before opening the customer workspace.',
                ],
                [
                    'title' => 'Return to onboarding',
                    'description' => 'Open the setup flow and complete the Google Workspace step so the customer workspace is fully ready.',
                ],
                [
                    'title' => 'Wait for the live sync to finish',
                    'description' => 'If Google Workspace is already connected, stay on the onboarding flow and let the first live sync complete automatically.',
                ],
            ];
        }

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

    /**
     * @return array<string, mixed>
     */
    private function readinessFor(Tenant $tenant): array
    {
        $syncJob = null;

        if (GoogleWorkspaceFeature::isAvailable() && $tenant->googleCredential?->isConnected()) {
            $syncJob = $this->agentSync->latestInitialGoogleWorkspaceSyncJob($tenant);
        }

        return $this->workspaceReadiness->evaluate($tenant, GoogleWorkspaceFeature::isAvailable(), $syncJob);
    }
}
