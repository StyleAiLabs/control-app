<?php

namespace App\Providers;

use App\Contracts\DockerComposeRunner;
use App\Contracts\TenantProvisioner;
use App\Services\LocalDockerComposeRunner;
use App\Services\LocalTenantProvisioningService;
use App\Services\OpenClawProvisioner;
use App\Services\SshDockerComposeRunner;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;
use InvalidArgumentException;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(DockerComposeRunner::class, function ($app) {
            return match (config('sync360.infrastructure.driver', 'local')) {
                'local' => $app->make(LocalDockerComposeRunner::class),
                'ssh' => $app->make(SshDockerComposeRunner::class),
                default => throw new InvalidArgumentException('Unsupported infrastructure driver configured.'),
            };
        });

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
        // Inject sidebar alerts into the app layout on every authenticated page.
        // Alerts are derived from cached tenant DB state — no live Docker call here.
        // The full live workspace state is checked separately on the Dashboard page load.
        View::composer('components.layouts.app', function (\Illuminate\View\View $view): void {
            $user = auth()->user();

            if (! $user || ! $user->tenant) {
                $view->with('sidebarAlerts', []);
                return;
            }

            $tenant = $user->tenant;
            $alerts = [];

            // Trial expiry / urgency alert
            if ($tenant->isTrialExpired()) {
                $alerts[] = [
                    'type'    => 'error',
                    'icon'    => '🔴',
                    'title'   => 'Trial expired',
                    'message' => 'Your digital employee has been paused.',
                    'cta'     => ['text' => 'Contact us', 'href' => 'mailto:hello@sync360.co.nz'],
                ];
            } elseif ($tenant->trialUrgency() === 'critical') {
                $alerts[] = [
                    'type'    => 'warning',
                    'icon'    => '🟡',
                    'title'   => 'Trial ending soon',
                    'message' => $tenant->trialDaysLeft() . ' days left · $' . number_format((float)($tenant->litellm_spend ?? 0), 2) . ' of $' . number_format((float)($tenant->litellm_max_budget ?? 5), 2) . ' used.',
                    'cta'     => ['text' => 'Contact us to upgrade', 'href' => 'mailto:hello@sync360.co.nz'],
                ];
            }

            // Workspace stopped — derived from agent status + provisioning status mismatch
            // (Avoids live Docker SSH call on every page. Dashboard does the live check.)
            if (
                $tenant->provisioning_status?->value === 'ready'
                && $tenant->agent_status === 'live'
                && $tenant->last_health_check_status === 'failed'
            ) {
                $alerts[] = [
                    'type'    => 'warning',
                    'icon'    => '⚠️',
                    'title'   => 'Workspace health check failed',
                    'message' => $tenant->health_check_message ?? 'Your workspace may not be responding.',
                    'cta'     => ['text' => 'Contact support', 'href' => 'mailto:hello@sync360.co.nz'],
                ];
            }

            $view->with('sidebarAlerts', array_merge($alerts, request()->attributes->get('_workspaceAlert', [])));
        });
    }
}
