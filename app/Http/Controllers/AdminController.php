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
use App\Services\TenantHealthCheckService;
use App\Services\TenantProfileSyncService;
use App\Services\TenantRuntimeService;
use App\Services\WorkspaceReadyEmailService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class AdminController extends Controller
{
    public function __construct(
        private readonly DockerComposeRunner $dockerCompose,
        private readonly ControlAppDeploymentService $controlAppDeployment,
        private readonly TenantHealthCheckService $tenantHealthChecks,
        private readonly TenantProfileSyncService $tenantProfileSync,
        private readonly WorkspaceReadyEmailService $workspaceReadyEmail,
        private readonly TenantRuntimeService $runtime,
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
            if (app()->environment('local')) {
                $this->localDockerCompose($tenant, 'up -d');
            } else {
                [$composeFile, $projectName] = $this->workspaceFilesFor($tenant);
                $this->dockerCompose->start($tenant->server, $composeFile, $projectName);
            }
        } catch (Throwable $exception) {
            return back()->with('status', $exception->getMessage());
        }

        return back()->with('status', 'Workspace container start requested.');
    }

    public function stopWorkspace(Tenant $tenant): RedirectResponse
    {
        try {
            if (app()->environment('local')) {
                $this->localDockerCompose($tenant, 'stop');
            } else {
                [$composeFile, $projectName] = $this->workspaceFilesFor($tenant);
                $this->dockerCompose->stop($tenant->server, $composeFile, $projectName);
            }
        } catch (Throwable $exception) {
            return back()->with('status', $exception->getMessage());
        }

        return back()->with('status', 'Workspace container stop requested.');
    }

    public function restartWorkspace(Tenant $tenant): RedirectResponse
    {
        try {
            if (app()->environment('local')) {
                $this->localDockerCompose($tenant, 'restart');
            } else {
                [$composeFile, $projectName] = $this->workspaceFilesFor($tenant);
                $this->dockerCompose->stop($tenant->server, $composeFile, $projectName);
                $this->dockerCompose->start($tenant->server, $composeFile, $projectName);
            }
        } catch (Throwable $exception) {
            return back()->with('status', $exception->getMessage());
        }

        return back()->with('status', 'Workspace container restart requested.');
    }

    public function healthCheck(Tenant $tenant): RedirectResponse
    {
        try {
            $result = $this->tenantHealthChecks->check($tenant);
        } catch (Throwable $exception) {
            return back()->with('status', $exception->getMessage());
        }

        return back()->with('status', $result['message']);
    }

    public function resyncAgent(Tenant $tenant): RedirectResponse
    {
        try {
            $this->tenantProfileSync->regenerateAndSync($tenant->fresh(['businessProfile', 'businessProfileFiles', 'server']));
        } catch (Throwable $exception) {
            return back()->with('status', $exception->getMessage());
        }

        return back()->with('status', 'Agent resync requested.');
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

    public function controlAppDeployStatus(): JsonResponse
    {
        return response()->json($this->controlAppDeployment->status());
    }

    private function workspaceStateFor(Tenant $tenant): string
    {
        if (! $tenant->runtime_path && ! app()->environment('local')) {
            return 'not_provisioned';
        }

        if (app()->environment('local')) {
            $localCompose = $this->runtime->localRuntimePath($tenant).DIRECTORY_SEPARATOR.'compose.yaml';

            if (! file_exists($localCompose)) {
                return 'missing_config';
            }

            $projectName = Str::limit('sync360-'.$tenant->slug, 63, '');
            $result = Process::run(sprintf(
                'docker compose -f %s -p %s ps --format json',
                escapeshellarg($localCompose),
                escapeshellarg($projectName),
            ));

            return str_contains($result->output(), '"running"') ? 'running' : 'stopped';
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
     * Run a docker compose command locally (dev environment only).
     */
    private function localDockerCompose(Tenant $tenant, string $action): void
    {
        $localCompose = $this->runtime->localRuntimePath($tenant).DIRECTORY_SEPARATOR.'compose.yaml';
        $projectName = Str::limit('sync360-'.$tenant->slug, 63, '');

        $command = sprintf(
            'docker compose -f %s -p %s %s',
            escapeshellarg($localCompose),
            escapeshellarg($projectName),
            $action,
        );

        Log::info('[AdminWorkspace] Local dev: '.$command);

        $result = Process::run($command);

        if (! $result->successful()) {
            throw new RuntimeException('Docker compose command failed: '.$result->errorOutput());
        }
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
