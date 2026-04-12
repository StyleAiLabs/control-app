<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $trustedProxies = env('TRUSTED_PROXIES');

        if ($trustedProxies !== null && $trustedProxies !== '') {
            $middleware->trustProxies(
                at: trim($trustedProxies) === '*'
                    ? '*'
                    : array_values(array_filter(array_map(
                        static fn (string $proxy): string => trim($proxy),
                        explode(',', $trustedProxies),
                    ))),
            );
        }

        $middleware->validateCsrfTokens(except: [
            'webhooks/*',
        ]);

        $middleware->alias([
            'admin' => \App\Http\Middleware\EnsureAdminUser::class,
            'local.only' => \App\Http\Middleware\EnsureLocalEnvironment::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
