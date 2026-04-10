<?php

namespace App\Providers;

use App\Contracts\DockerComposeRunner;
use App\Contracts\TenantProvisioner;
use App\Services\LocalDockerComposeRunner;
use App\Services\LocalTenantProvisioningService;
use App\Services\OpenClawProvisioner;
use Illuminate\Support\ServiceProvider;
use InvalidArgumentException;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(DockerComposeRunner::class, LocalDockerComposeRunner::class);

        $this->app->bind(TenantProvisioner::class, function ($app) {
            return match (config('sync360.provisioning.driver', 'openclaw')) {
                'local' => $app->make(LocalTenantProvisioningService::class),
                'openclaw' => $app->make(OpenClawProvisioner::class),
                default => throw new InvalidArgumentException('Unsupported tenant provisioning driver configured.'),
            };
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
