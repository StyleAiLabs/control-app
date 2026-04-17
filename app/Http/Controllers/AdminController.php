<?php

namespace App\Http\Controllers;

use App\Contracts\DockerComposeRunner;
use App\Enums\ProvisioningJobStatus;
use App\Enums\TenantProvisioningStatus;
use App\Jobs\ProcessInitialGoogleWorkspaceSync;
use App\Jobs\ProcessTenantProvisioning;
use App\Models\ProvisioningJob;
use App\Models\Tenant;
use App\Models\TenantGoogleCredential;
use App\Models\User;
use App\Services\ControlAppDeploymentService;
use App\Services\TenantAgentSyncService;
use App\Services\TenantDeletionService;
use App\Services\TenantHealthCheckService;
use App\Services\TenantProfileSyncService;
use App\Services\TenantRuntimeService;
use App\Services\WorkspaceReadyEmailService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Artisan;
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
        private readonly TenantDeletionService $tenantDeletion,
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
            ->with(['user', 'server', 'googleCredential', 'provisioningJobs' => fn ($query) => $query->latest('id')])
            ->orderByDesc('id')
            ->get();

        $workspaceStates = [];
        $googleSyncJobs = ProvisioningJob::query()
            ->whereIn('tenant_id', $tenants->pluck('id'))
            ->where('job_type', ProcessInitialGoogleWorkspaceSync::JOB_TYPE)
            ->latest('id')
            ->get()
            ->unique('tenant_id')
            ->keyBy('tenant_id');
        $googleStates = [];

        foreach ($tenants as $tenant) {
            $workspaceStates[$tenant->id] = $this->workspaceStateFor($tenant);
            $googleStates[$tenant->id] = $this->googleStateFor($tenant, $googleSyncJobs->get($tenant->id));
        }

        return view('admin.tenants', [
            'tenants' => $tenants,
            'workspaceStates' => $workspaceStates,
            'googleStates' => $googleStates,
        ]);
    }

    public function showTenant(Tenant $tenant): View
    {
        $tenant->load([
            'user',
            'server',
            'googleCredential',
            'businessProfile',
            'businessProfileFiles',
            'provisioningJobs' => fn ($query) => $query->latest('id'),
        ]);

        $googleSyncJob = $this->latestGoogleWorkspaceSyncJob($tenant);

        return view('admin.tenant-show', [
            'tenant' => $tenant,
            'workspaceState' => $this->workspaceStateFor($tenant),
            'latestJob' => $tenant->provisioningJobs->first(),
            'googleSyncJob' => $googleSyncJob,
            'googleState' => $this->googleStateFor($tenant, $googleSyncJob),
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

    public function bootstrapRuntimeHost(Tenant $tenant): RedirectResponse
    {
        try {
            if (! $tenant->server) {
                throw new RuntimeException('This tenant does not have an assigned client VPS.');
            }

            $result = $this->runArtisanCommand('sync360:bootstrap-client-vps', [
                'serverSelector' => (string) $tenant->server->id,
            ]);
        } catch (Throwable $exception) {
            return back()->with('status', $exception->getMessage());
        }

        return back()->with('status', $this->formatArtisanStatus(
            sprintf('Client VPS bootstrap finished for %s.', $tenant->server->name),
            $result['output'],
        ));
    }

    public function syncRuntimeCapabilities(Tenant $tenant): RedirectResponse
    {
        try {
            $result = $this->runArtisanCommand('sync360:sync-runtime-capabilities', [
                'tenantSelector' => $tenant->slug,
            ]);
        } catch (Throwable $exception) {
            return back()->with('status', $exception->getMessage());
        }

        return back()->with('status', $this->formatArtisanStatus(
            sprintf('Runtime capability sync finished for %s.', $tenant->slug),
            $result['output'],
        ));
    }

    public function retryGoogleWorkspaceSync(Tenant $tenant, TenantAgentSyncService $tenantAgentSync): RedirectResponse
    {
        $tenant->loadMissing(['server', 'googleCredential']);

        if (! $tenant->googleCredential?->isConnected()) {
            return back()->with('status', 'Google Workspace is not connected for this tenant.');
        }

        $activeJob = $this->latestGoogleWorkspaceSyncJob($tenant, [
            ProvisioningJobStatus::Queued,
            ProvisioningJobStatus::Running,
        ]);

        if ($activeJob) {
            return back()->with('status', sprintf(
                'Google Workspace sync is already %s for %s.',
                $activeJob->status->value,
                $tenant->slug,
            ));
        }

        $job = $tenantAgentSync->dispatchInitialGoogleWorkspaceSync(
            $tenant->fresh(['server', 'googleCredential']),
            trigger: 'admin_retry',
        );

        if (! $job) {
            return back()->with('status', 'Workspace must be ready before Google Workspace sync can be queued.');
        }

        return back()->with('status', sprintf('Google Workspace sync queued for %s.', $tenant->slug));
    }

    public function testGoogleWorkspace(Tenant $tenant): RedirectResponse
    {
        try {
            $result = $this->runArtisanCommand('sync360:test-google-workspace', [
                'tenantSelector' => $tenant->slug,
            ]);
        } catch (Throwable $exception) {
            return back()->with('status', $exception->getMessage());
        }

        return back()->with('status', $this->formatArtisanStatus(
            sprintf('Google Workspace smoke test passed for %s.', $tenant->slug),
            $result['output'],
        ));
    }

    public function destroyTenant(Tenant $tenant): RedirectResponse
    {
        $validated = request()->validate([
            'confirmation_slug' => ['required', 'string', 'in:'.$tenant->slug],
        ], [
            'confirmation_slug.in' => 'Type the tenant slug exactly to confirm permanent deletion.',
        ]);

        try {
            $this->tenantDeletion->deletePermanently($tenant);
        } catch (Throwable $exception) {
            return back()->with('status', $exception->getMessage());
        }

        return redirect()->route('admin.tenants')->with('status', sprintf(
            'Tenant %s and its linked customer account were permanently deleted.',
            $validated['confirmation_slug'],
        ));
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
                '%s -f %s -p %s ps --format json',
                $this->runtime->localDockerComposeShellPrefix(),
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
     * @return array{
     *     available: bool,
     *     connection_status: string,
     *     connection_label: string,
     *     connection_badge: string,
     *     runtime_sync_status: string,
     *     runtime_label: string,
     *     runtime_badge: string,
     *     google_email: ?string,
     *     last_error: ?string,
     *     last_timestamp_label: ?string,
     *     last_timestamp: ?string,
     *     sync_job_status: ?string,
     *     sync_job_badge: string,
     *     sync_job_started_at: ?string,
     *     sync_job_completed_at: ?string,
     *     sync_job_error: ?string,
     *     connected_at: ?string,
     *     disconnected_at: ?string,
     *     last_synced_at: ?string,
     *     can_queue_sync: bool,
     *     has_active_sync_job: bool
     * }
     */
    private function googleStateFor(Tenant $tenant, ?ProvisioningJob $syncJob = null): array
    {
        $credential = $tenant->googleCredential;
        $connectionStatus = $credential?->status ?? TenantGoogleCredential::STATUS_PENDING;
        $runtimeSyncStatus = $credential?->runtime_sync_status ?? TenantGoogleCredential::RUNTIME_SYNC_PENDING;
        $syncJobStatus = $syncJob?->status?->value;
        $workspaceReady = $tenant->provisioning_status === TenantProvisioningStatus::Ready && filled($tenant->runtime_path);
        $hasActiveSyncJob = in_array($syncJobStatus, [
            ProvisioningJobStatus::Queued->value,
            ProvisioningJobStatus::Running->value,
        ], true);

        [$lastTimestampLabel, $lastTimestamp] = match (true) {
            filled($credential?->last_error) => ['Last error', $credential?->updated_at?->toDateTimeString()],
            filled($credential?->last_synced_at) => ['Last synced', $credential?->last_synced_at?->toDateTimeString()],
            filled($syncJob?->completed_at) => ['Last sync job', $syncJob?->completed_at?->toDateTimeString()],
            filled($syncJob?->started_at) => ['Sync started', $syncJob?->started_at?->toDateTimeString()],
            filled($credential?->connected_at) => ['Connected at', $credential?->connected_at?->toDateTimeString()],
            filled($credential?->disconnected_at) => ['Disconnected at', $credential?->disconnected_at?->toDateTimeString()],
            default => [null, null],
        };

        return [
            'available' => $credential !== null,
            'connection_status' => $connectionStatus,
            'connection_label' => match ($connectionStatus) {
                TenantGoogleCredential::STATUS_CONNECTED => 'Connected',
                TenantGoogleCredential::STATUS_DISCONNECTED => 'Disconnected',
                TenantGoogleCredential::STATUS_SKIPPED => 'Skipped',
                default => 'Pending',
            },
            'connection_badge' => match ($connectionStatus) {
                TenantGoogleCredential::STATUS_CONNECTED => 'ready',
                TenantGoogleCredential::STATUS_DISCONNECTED => 'failed',
                default => 'pending',
            },
            'runtime_sync_status' => $runtimeSyncStatus,
            'runtime_label' => $this->googleWorkspaceRuntimeSyncLabel(
                $connectionStatus,
                $runtimeSyncStatus,
                $workspaceReady,
                $syncJobStatus,
            ),
            'runtime_badge' => match (true) {
                $runtimeSyncStatus === TenantGoogleCredential::RUNTIME_SYNC_FAILED => 'failed',
                $runtimeSyncStatus === TenantGoogleCredential::RUNTIME_SYNC_VERIFIED => 'ready',
                $syncJobStatus === ProvisioningJobStatus::Running->value => 'running',
                $syncJobStatus === ProvisioningJobStatus::Queued->value => 'queued',
                $runtimeSyncStatus === TenantGoogleCredential::RUNTIME_SYNC_SYNCED => 'pending',
                default => 'pending',
            },
            'google_email' => $credential?->google_email,
            'last_error' => $credential?->last_error ?: $syncJob?->error_message,
            'last_timestamp_label' => $lastTimestampLabel,
            'last_timestamp' => $lastTimestamp,
            'sync_job_status' => $syncJobStatus,
            'sync_job_badge' => match ($syncJobStatus) {
                ProvisioningJobStatus::Completed->value => 'completed',
                ProvisioningJobStatus::Failed->value => 'failed',
                ProvisioningJobStatus::Running->value => 'running',
                ProvisioningJobStatus::Queued->value => 'queued',
                default => 'pending',
            },
            'sync_job_started_at' => $syncJob?->started_at?->toDateTimeString(),
            'sync_job_completed_at' => $syncJob?->completed_at?->toDateTimeString(),
            'sync_job_error' => $syncJob?->error_message,
            'connected_at' => $credential?->connected_at?->toDateTimeString(),
            'disconnected_at' => $credential?->disconnected_at?->toDateTimeString(),
            'last_synced_at' => $credential?->last_synced_at?->toDateTimeString(),
            'can_queue_sync' => ($credential?->isConnected() ?? false) && $workspaceReady && ! $hasActiveSyncJob,
            'has_active_sync_job' => $hasActiveSyncJob,
        ];
    }

    private function googleWorkspaceRuntimeSyncLabel(
        string $connectionStatus,
        string $runtimeSyncStatus,
        bool $workspaceReady,
        ?string $syncJobStatus,
    ): string {
        if ($connectionStatus !== TenantGoogleCredential::STATUS_CONNECTED) {
            return ucfirst($runtimeSyncStatus);
        }

        return match (true) {
            $runtimeSyncStatus === TenantGoogleCredential::RUNTIME_SYNC_FAILED => 'Needs attention',
            $runtimeSyncStatus === TenantGoogleCredential::RUNTIME_SYNC_VERIFIED => 'Ready',
            $runtimeSyncStatus === TenantGoogleCredential::RUNTIME_SYNC_SYNCED => 'Checking',
            $syncJobStatus === ProvisioningJobStatus::Running->value => 'Syncing',
            $syncJobStatus === ProvisioningJobStatus::Queued->value => 'Queued',
            $runtimeSyncStatus === TenantGoogleCredential::RUNTIME_SYNC_PENDING && ! $workspaceReady => 'Waiting for workspace',
            default => ucfirst($runtimeSyncStatus),
        };
    }

    /**
     * Run a docker compose command locally (dev environment only).
     */
    private function localDockerCompose(Tenant $tenant, string $action): void
    {
        $localCompose = $this->runtime->localRuntimePath($tenant).DIRECTORY_SEPARATOR.'compose.yaml';
        $projectName = Str::limit('sync360-'.$tenant->slug, 63, '');

        $command = sprintf(
            '%s -f %s -p %s %s',
            $this->runtime->localDockerComposeShellPrefix(),
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

    /**
     * @return array{exit_code:int, output:string}
     */
    private function runArtisanCommand(string $command, array $arguments = []): array
    {
        $exitCode = Artisan::call($command, $arguments);
        $output = trim(Artisan::output());

        if ($exitCode !== 0) {
            throw new RuntimeException($output !== ''
                ? $this->formatArtisanOutput($output)
                : sprintf('The command [%s] failed with exit code %d.', $command, $exitCode));
        }

        return [
            'exit_code' => $exitCode,
            'output' => $output,
        ];
    }

    private function formatArtisanStatus(string $prefix, string $output): string
    {
        $formattedOutput = $this->formatArtisanOutput($output);

        if ($formattedOutput === '') {
            return $prefix;
        }

        return $prefix.' '.$formattedOutput;
    }

    private function formatArtisanOutput(string $output): string
    {
        return Str::limit(preg_replace('/\s+/', ' ', trim($output)) ?? '', 500);
    }

    private function latestGoogleWorkspaceSyncJob(Tenant $tenant, ?array $statuses = null): ?ProvisioningJob
    {
        $query = ProvisioningJob::query()
            ->where('tenant_id', $tenant->id)
            ->where('job_type', ProcessInitialGoogleWorkspaceSync::JOB_TYPE)
            ->latest('id');

        if (is_array($statuses) && $statuses !== []) {
            $query->whereIn('status', array_map(
                static fn (ProvisioningJobStatus|string $status): string => $status instanceof ProvisioningJobStatus ? $status->value : $status,
                $statuses,
            ));
        }

        return $query->first();
    }
}
