<?php

namespace App\Http\Controllers;

use App\Services\WorkspaceHostResolver;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Response;
use Illuminate\Http\Request;

class LandingController extends Controller
{
    public function __construct(
        private readonly WorkspaceHostResolver $workspaceHosts,
    ) {
    }

    public function show(Request $request): View|RedirectResponse|Response
    {
        $workspaceTenant = $this->workspaceHosts->resolveTenantForHost($request->getHost());

        if (! $workspaceTenant) {
            return view('landing');
        }

        $user = $request->user();

        if (! $user) {
            return redirect()->route('login');
        }

        $userTenant = $user->tenant;

        if ($userTenant && $userTenant->is($workspaceTenant)) {
            return redirect()->route('dashboard');
        }

        return response()->view('errors.workspace-access', [
            'workspaceTenant' => $workspaceTenant,
            'destinationUrl' => $userTenant?->workspace_url ?: config('app.url'),
            'destinationLabel' => $userTenant?->workspace_url ? 'Open Your Workspace' : 'Go to Sync360',
        ], 403);
    }
}
