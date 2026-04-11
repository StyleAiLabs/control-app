<?php

namespace App\Http\Controllers;

use App\Contracts\DockerComposeRunner;
use App\Enums\ProvisioningJobStatus;
use App\Enums\TenantProvisioningStatus;
use App\Jobs\ProcessTenantProvisioning;
use App\Models\ProvisioningJob;
use App\Models\Tenant;
use App\Models\User;
use App\Services\ControlAppDeploymentService;
use App\Services\WorkspaceReadyEmailService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class AdminController extends Controller
{
    public function __construct(
        private readonly DockerComposeRunner $dockerCompose,
        private readonly ControlAppDeploymentService $controlAppDeployment,
        private readonly WorkspaceReadyEmailService $workspaceReadyEmail,
    ) {}

    public function index(): View
    {
        return view('admin.index', [
            'userCount' => User::query()->count(),
            'tenantCount' => Tenant::query()->count(),
            'jobCounts' => [
                'queued' => ProvisioningJob::query()->where('status', ProvisioningJobStatus::Queued)->count(),
                'running' => ProvisioningJob::query()->where('status', ProvisioningJobStatus::Running)->count(),
                'completed' => ProvisioningJob::query()->where('status', ProvisioningJobStatus::Completed)->count(),
                'failed' => ProvisioningJob::query()->where('status', ProvisioningJobStatus::Failed)->count(),
            ],
            'tenantCounts' => [
                'pending' => Tenant::query()->where('provisioning_status', 'pending')->count(),
                'ready' => Tenant::query()->where('provisioning_status', 'ready')->count(),
                'failed' => Tenant::query()->where('provisioning_status', 'failed')->count(),
            ],
            'controlAppDeployStatus' => $this->controlAppDeployment->status(),
        ]);
    }

    public function users(): View
    {
        return view('admin.users', [
            'users' => User::query()
                ->with('tenant')
                ->orderByDesc('is_admin')
                ->orderBy('name')
                ->get(),
        ]);
    }

    public function tenants(): View
    {
        $tenants = Tenant::query()
            ->with(['user', 'server', 'provisioningJobs' => fn ($query) => $query->latest('id')])
            ->orderByDesc('id')
            ->get();

        $workspaceStates = [];

        foreach ($tenants as $tenant) {
            $workspaceStates[$tenant->id] = $this->workspaceStateFor($tenant);
        }

        return view('admin.tenants', [
            'tenants' => $tenants,
            'workspaceStates' => $workspaceStates,
        ]);
    }

    public function jobs(): View
    {
        return view('admin.jobs', [
            'jobs' => ProvisioningJob::query()
                ->with('tenant.user')
                ->latest('id')
                ->get(),
        ]);
    }

    public function retry(Tenant $tenant): RedirectResponse
    {
        $previousJob = $tenant->provisioningJobs()->latest('id')->first();

        $tenant->forceFill([
            'provisioning_status' => TenantProvisioningStatus::Pending,
            'assigned_port' => null,
            'workspace_url' => null,
            'runtime_path' => null,
        ])->save();

        $job = ProvisioningJob::query()->create([
            'tenant_id' => $tenant->id,
            'job_type' => 'provision_tenant',
            'status' => ProvisioningJobStatus::Queued,
            'payload_json' => $this->workspaceReadyEmail->carryForwardCredentials([
                'retried_from_admin' => true,
                'requested_at' => now()->toIso8601String(),
            ], $previousJob, $tenant->user?->email),
        ]);

        ProcessTenantProvisioning::dispatch($tenant->id, $job->id)->afterCommit();

        return back()->with('status', 'Provisioning retry queued.');
    }

    public function startWorkspace(Tenant $tenant): RedirectResponse
    {
        try {
            [$composeFile, $projectName] = $this->workspaceFilesFor($tenant);
            $this->dockerCompose->start($tenant->server, $composeFile, $projectName);
        } catch (Throwable $exception) {
            return back()->with('status', $exception->getMessage());
        }

        return back()->with('status', 'Workspace container start requested.');
    }

    public function stopWorkspace(Tenant $tenant): RedirectResponse
    {
        try {
            [$composeFile, $projectName] = $this->workspaceFilesFor($tenant);
            $this->dockerCompose->stop($tenant->server, $composeFile, $projectName);
        } catch (Throwable $exception) {
            return back()->with('status', $exception->getMessage());
        }

        return back()->with('status', 'Workspace container stop requested.');
    }

    public function triggerControlAppDeploy(): RedirectResponse
    {
        try {
            $pid = $this->controlAppDeployment->trigger();
        } catch (Throwable $exception) {
            return back()->with('status', $exception->getMessage());
        }

        return back()->with('status', sprintf('Control app deploy started. Remote process id: %s', $pid));
    }

    private function workspaceStateFor(Tenant $tenant): string
    {
        if (! $tenant->runtime_path) {
            return 'not_provisioned';
        }

        try {
            [$composeFile, $projectName] = $this->workspaceFilesFor($tenant);
        } catch (RuntimeException) {
            return 'missing_config';
        }

        return $this->dockerCompose->isRunning($tenant->server, $composeFile, $projectName)
            ? 'running'
            : 'stopped';
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function workspaceFilesFor(Tenant $tenant): array
    {
        if (! $tenant->runtime_path) {
            throw new RuntimeException('This tenant does not have a provisioned runtime yet.');
        }

        if (! $tenant->server) {
            throw new RuntimeException('This tenant does not have an assigned client VPS.');
        }

        $composeFile = rtrim($tenant->runtime_path, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.(string) config('sync360.openclaw.compose_filename', 'compose.yaml');

        $projectName = Str::limit('sync360-'.$tenant->slug, 63, '');

        return [$composeFile, $projectName];
    }
}
