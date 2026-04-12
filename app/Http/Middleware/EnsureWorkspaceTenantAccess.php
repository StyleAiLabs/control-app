<?php

namespace App\Http\Middleware;

use App\Services\WorkspaceHostResolver;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureWorkspaceTenantAccess
{
    public function __construct(
        private readonly WorkspaceHostResolver $workspaceHosts,
    ) {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $workspaceTenant = $this->workspaceHosts->resolveTenantForHost($request->getHost());

        if (! $workspaceTenant) {
            return $next($request);
        }

        $user = $request->user();
        $userTenant = $user?->tenant;

        if ($userTenant && $userTenant->is($workspaceTenant)) {
            return $next($request);
        }

        return response()->view('errors.workspace-access', [
            'workspaceTenant' => $workspaceTenant,
            'destinationUrl' => $userTenant?->workspace_url ?: config('app.url'),
            'destinationLabel' => $userTenant?->workspace_url ? 'Open Your Workspace' : 'Go to Sync360',
        ], 403);
    }
}
