<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureLocalEnvironment
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if ((bool) config('sync360.admin_local_only', false)) {
            abort_unless(app()->environment(['local', 'testing']), 404);
        }

        return $next($request);
    }
}
