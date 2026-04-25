<?php

namespace App\Http\Controllers;

use App\Models\Tenant;
use App\Models\TenantGoogleCredential;
use App\Services\GoogleWorkspaceOAuthService;
use App\Services\TenantAgentSyncService;
use App\Services\TenantWorkspaceDependencyHealthService;
use App\Support\GoogleWorkspaceFeature;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Throwable;

class GoogleOAuthController extends Controller
{
    public function __construct(
        private readonly GoogleWorkspaceOAuthService $oauth,
        private readonly TenantAgentSyncService $agentSync,
        private readonly TenantWorkspaceDependencyHealthService $dependencyHealth,
    ) {
    }

    public function redirect(Request $request): RedirectResponse
    {
        if (! GoogleWorkspaceFeature::isAvailable()) {
            return $this->redirectToGoogleStep('Run the latest migrations before connecting Google Workspace.');
        }

        $tenant = $this->tenantFor($request);

        return redirect()->away($this->oauth->begin($tenant));
    }

    public function callback(Request $request): RedirectResponse
    {
        if (! GoogleWorkspaceFeature::isAvailable()) {
            return $this->redirectToGoogleStep('Run the latest migrations before connecting Google Workspace.');
        }

        $credential = TenantGoogleCredential::query()
            ->with(['tenant.server', 'tenant.googleCredential'])
            ->where('oauth_state', $request->string('state')->toString())
            ->first();

        if (! $credential?->tenant) {
            return redirect()->route('login')->with('status', 'We could not match that Google Workspace callback to a tenant.');
        }

        $tenant = $credential->tenant;

        if (filled($request->string('error')->toString())) {
            $credential->forceFill([
                'status' => TenantGoogleCredential::STATUS_DISCONNECTED,
                'last_error' => 'Google returned an authorization error: '.$request->string('error')->toString(),
                'oauth_state' => null,
                'oauth_code_verifier' => null,
                'oauth_state_expires_at' => null,
            ])->save();
            $this->markOnboardingStepComplete($tenant);

            return $this->redirectToGoogleStep('Google Workspace connection was cancelled.');
        }

        try {
            $this->oauth->complete(
                $tenant,
                $request->string('state')->toString(),
                $request->string('code')->toString(),
            );
            $this->dependencyHealth->resetGoogleAlerts($tenant->fresh('googleCredential')->googleCredential);

            $syncJob = $this->agentSync->dispatchInitialGoogleWorkspaceSync(
                $tenant->fresh(['server', 'googleCredential']),
                trigger: 'google_oauth_callback',
            );

            $this->markOnboardingStepComplete($tenant);

            return $this->redirectToGoogleStep(
                $syncJob
                    ? 'Google Workspace connected. We queued the first live workspace sync and will keep it moving automatically.'
                    : 'Google Workspace connected. We will finish linking it to your live workspace as soon as setup is ready.'
            );
        } catch (Throwable $exception) {
            $tenant->googleCredential?->forceFill([
                'runtime_sync_status' => TenantGoogleCredential::RUNTIME_SYNC_FAILED,
                'last_error' => $exception->getMessage(),
            ])->save();

            return $this->redirectToGoogleStep($exception->getMessage());
        }
    }

    public function skip(Request $request): RedirectResponse
    {
        if (! GoogleWorkspaceFeature::isAvailable()) {
            return $this->redirectToGoogleStep('Run the latest migrations before connecting Google Workspace.');
        }

        $tenant = $this->tenantFor($request);
        $this->oauth->markSkipped($tenant);
        $this->markOnboardingStepComplete($tenant);

        return $this->redirectToGoogleStep('Google Workspace skipped for now.');
    }

    public function disconnect(Request $request): RedirectResponse
    {
        if (! GoogleWorkspaceFeature::isAvailable()) {
            return $this->redirectToGoogleStep('Run the latest migrations before connecting Google Workspace.');
        }

        $tenant = $this->tenantFor($request);

        try {
            $this->agentSync->disconnectGoogleWorkspace($tenant->fresh(['server', 'googleCredential']));
            $this->markOnboardingStepComplete($tenant);
        } catch (Throwable $exception) {
            return $this->redirectToGoogleStep($exception->getMessage());
        }

        return $this->redirectToGoogleStep('Google Workspace disconnected.');
    }

    private function tenantFor(Request $request): Tenant
    {
        return $request->user()->tenant()
            ->with(GoogleWorkspaceFeature::tenantRelations(['businessProfile', 'businessProfileFiles', 'server']))
            ->firstOrFail();
    }

    private function markOnboardingStepComplete(Tenant $tenant): void
    {
        $tenant->forceFill([
            'onboarding_status' => $tenant->onboarding_status === 'complete' ? 'complete' : 'in_progress',
            'onboarding_step' => max((int) $tenant->onboarding_step, 6),
        ])->save();
    }

    private function redirectToGoogleStep(string $message): RedirectResponse
    {
        return redirect()->route('onboarding.show', ['step' => 6])->with('status', $message);
    }
}
