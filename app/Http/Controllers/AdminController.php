<?php

namespace App\Http\Controllers;

use App\Contracts\DockerComposeRunner;
use App\Enums\ProvisioningJobStatus;
use App\Enums\TenantProvisioningStatus;
use App\Jobs\ApplyTenantAgentCustomization;
use App\Jobs\ProcessInitialGoogleWorkspaceSync;
use App\Jobs\ProcessTenantProvisioning;
use App\Models\ProvisioningJob;
use App\Models\SkillCatalogItem;
use App\Models\SkillCatalogVersion;
use App\Models\Tenant;
use App\Models\TenantAgentCustomizationApply;
use App\Models\TenantGoogleCredential;
use App\Models\TenantSkillAssignment;
use App\Models\User;
use App\Services\ControlAppDeploymentService;
use App\Services\SkillCatalogService;
use App\Services\TenantAgentCustomizationService;
use App\Services\TenantAgentSyncService;
use App\Services\TenantDeletionService;
use App\Services\TenantHealthCheckService;
use App\Services\TenantProfileSyncService;
use App\Services\TenantRuntimeCustomizationComposer;
use App\Services\TenantRuntimeSkillDiscoveryService;
use App\Services\TenantSkillAssignmentService;
use App\Services\TenantSkillRegistryService;
use App\Services\TenantRuntimeService;
use App\Services\WorkspaceReadyEmailService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class AdminController extends Controller
{
    private const TENANT_SHOW_TABS = [
        'overview',
        'workspace',
        'google',
        'skills',
        'agent-runtime',
        'support',
    ];

    public function __construct(
        private readonly DockerComposeRunner $dockerCompose,
        private readonly ControlAppDeploymentService $controlAppDeployment,
        private readonly TenantHealthCheckService $tenantHealthChecks,
        private readonly TenantProfileSyncService $tenantProfileSync,
        private readonly WorkspaceReadyEmailService $workspaceReadyEmail,
        private readonly TenantRuntimeService $runtime,
        private readonly TenantDeletionService $tenantDeletion,
        private readonly TenantAgentCustomizationService $tenantCustomizations,
        private readonly TenantRuntimeCustomizationComposer $tenantRuntimeComposer,
        private readonly TenantSkillRegistryService $skillRegistry,
        private readonly SkillCatalogService $skillCatalog,
        private readonly TenantSkillAssignmentService $tenantSkillAssignments,
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

    public function showTenant(Request $request, Tenant $tenant): View
    {
        $relations = [
            'user',
            'server',
            'googleCredential',
            'businessProfile',
            'businessProfileFiles',
            'provisioningJobs' => fn ($query) => $query->latest('id'),
        ];
        $agentCustomizationAvailable = $this->agentCustomizationTablesAvailable();
        $tenantSkillsAvailable = $this->tenantSkillTablesAvailable();
        $runtimeCustomizationAvailable = $agentCustomizationAvailable && $tenantSkillsAvailable;

        if ($tenantSkillsAvailable) {
            $relations[] = 'skillAssignments.catalogVersion.item';
        }

        if ($agentCustomizationAvailable) {
            $relations[] = 'agentCustomization';
            $relations[] = 'agentCustomizationApplies';
        }

        $tenant->load($relations);

        if (! $agentCustomizationAvailable) {
            $tenant->setRelation('agentCustomization', null);
            $tenant->setRelation('agentCustomizationApplies', collect());
        }

        if (! $tenantSkillsAvailable) {
            $tenant->setRelation('skillAssignments', collect());
        }

        $googleSyncJob = $this->latestGoogleWorkspaceSyncJob($tenant);
        $currentCustomizationPreview = [];
        $skillCatalog = $tenantSkillsAvailable ? $this->skillCatalog->catalog() : collect();

        if ($runtimeCustomizationAvailable && $tenant->businessProfile && $tenant->businessProfileFiles) {
            try {
                $currentCustomizationPreview = $this->tenantRuntimeComposer
                    ->compose($tenant)
                    ->workspaceFiles;
            } catch (Throwable $exception) {
                Log::warning('[AdminTenantShow] Unable to render current customization preview.', [
                    'tenant_id' => $tenant->tenant_id,
                    'error' => $exception->getMessage(),
                ]);
            }
        }

        $activeTenantTab = $this->resolveTenantTab($request->query('tab'));

        return view('admin.tenant-show', [
            'tenant' => $tenant,
            'workspaceState' => $this->workspaceStateFor($tenant),
            'latestJob' => $tenant->provisioningJobs->first(),
            'googleSyncJob' => $googleSyncJob,
            'googleState' => $this->googleStateFor($tenant, $googleSyncJob),
            'skillRegistry' => array_values($this->skillRegistry->all()),
            'skillCatalog' => $skillCatalog,
            'tenantSkillsStatus' => $this->tenantSkillsStatus($tenant, $skillCatalog, $tenantSkillsAvailable),
            'runtimeSkillInspection' => $this->runtimeSkillInspectionFor($request, $tenant),
            'canApplyAgentCustomization' => Gate::allows('admin.tenants.agent-customization.apply'),
            'currentCustomizationPreview' => $currentCustomizationPreview,
            'activeTenantTab' => $activeTenantTab,
            'agentCustomizationAvailable' => $agentCustomizationAvailable,
            'tenantSkillsAvailable' => $tenantSkillsAvailable,
            'runtimeCustomizationAvailable' => $runtimeCustomizationAvailable,
            'skillChangeHistory' => $runtimeCustomizationAvailable ? $this->skillChangeHistoryFor($tenant) : [],
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

    public function retry(Request $request, Tenant $tenant): RedirectResponse
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

        return $this->redirectToTenantShow($request, $tenant, 'support', 'Provisioning retry queued.');
    }

    public function startWorkspace(Request $request, Tenant $tenant): RedirectResponse
    {
        try {
            if (app()->environment('local')) {
                $this->localDockerCompose($tenant, 'up -d');
            } else {
                [$composeFile, $projectName] = $this->workspaceFilesFor($tenant);
                $this->dockerCompose->start($tenant->server, $composeFile, $projectName);
            }
        } catch (Throwable $exception) {
            return $this->redirectToTenantShow($request, $tenant, 'support', $exception->getMessage());
        }

        return $this->redirectToTenantShow($request, $tenant, 'support', 'Workspace container start requested.');
    }

    public function stopWorkspace(Request $request, Tenant $tenant): RedirectResponse
    {
        try {
            if (app()->environment('local')) {
                $this->localDockerCompose($tenant, 'stop');
            } else {
                [$composeFile, $projectName] = $this->workspaceFilesFor($tenant);
                $this->dockerCompose->stop($tenant->server, $composeFile, $projectName);
            }
        } catch (Throwable $exception) {
            return $this->redirectToTenantShow($request, $tenant, 'support', $exception->getMessage());
        }

        return $this->redirectToTenantShow($request, $tenant, 'support', 'Workspace container stop requested.');
    }

    public function restartWorkspace(Request $request, Tenant $tenant): RedirectResponse
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
            return $this->redirectToTenantShow($request, $tenant, 'support', $exception->getMessage());
        }

        return $this->redirectToTenantShow($request, $tenant, 'support', 'Workspace container restart requested.');
    }

    public function healthCheck(Request $request, Tenant $tenant): RedirectResponse
    {
        try {
            $result = $this->tenantHealthChecks->check($tenant);
        } catch (Throwable $exception) {
            return $this->redirectToTenantShow($request, $tenant, 'support', $exception->getMessage());
        }

        return $this->redirectToTenantShow($request, $tenant, 'support', $result['message']);
    }

    public function resyncAgent(Request $request, Tenant $tenant): RedirectResponse
    {
        try {
            $this->tenantProfileSync->regenerateAndSync($tenant->fresh(['businessProfile', 'businessProfileFiles', 'server']));
        } catch (Throwable $exception) {
            return $this->redirectToTenantShow($request, $tenant, 'support', $exception->getMessage());
        }

        return $this->redirectToTenantShow($request, $tenant, 'support', 'Agent resync requested.');
    }

    public function bootstrapRuntimeHost(Request $request, Tenant $tenant): RedirectResponse
    {
        try {
            if (! $tenant->server) {
                throw new RuntimeException('This tenant does not have an assigned client VPS.');
            }

            $result = $this->runArtisanCommand('sync360:bootstrap-client-vps', [
                'serverSelector' => (string) $tenant->server->id,
            ]);
        } catch (Throwable $exception) {
            return $this->redirectToTenantShow($request, $tenant, 'support', $exception->getMessage());
        }

        return $this->redirectToTenantShow($request, $tenant, 'support', $this->formatArtisanStatus(
            sprintf('Client VPS bootstrap finished for %s.', $tenant->server->name),
            $result['output'],
        ));
    }

    public function syncRuntimeCapabilities(Request $request, Tenant $tenant): RedirectResponse
    {
        try {
            $result = $this->runArtisanCommand('sync360:sync-runtime-capabilities', [
                'tenantSelector' => $tenant->slug,
            ]);
        } catch (Throwable $exception) {
            return $this->redirectToTenantShow($request, $tenant, 'google', $exception->getMessage());
        }

        return $this->redirectToTenantShow($request, $tenant, 'google', $this->formatArtisanStatus(
            sprintf('Runtime capability sync finished for %s.', $tenant->slug),
            $result['output'],
        ));
    }

    public function retryGoogleWorkspaceSync(Request $request, Tenant $tenant, TenantAgentSyncService $tenantAgentSync): RedirectResponse
    {
        $tenant->loadMissing(['server', 'googleCredential']);

        if (! $tenant->googleCredential?->isConnected()) {
            return $this->redirectToTenantShow($request, $tenant, 'google', 'Google Workspace is not connected for this tenant.');
        }

        $activeJob = $this->latestGoogleWorkspaceSyncJob($tenant, [
            ProvisioningJobStatus::Queued,
            ProvisioningJobStatus::Running,
        ]);

        if ($activeJob) {
            return $this->redirectToTenantShow($request, $tenant, 'google', sprintf(
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
            return $this->redirectToTenantShow($request, $tenant, 'google', 'Workspace must be ready before Google Workspace sync can be queued.');
        }

        return $this->redirectToTenantShow($request, $tenant, 'google', sprintf('Google Workspace sync queued for %s.', $tenant->slug));
    }

    public function testGoogleWorkspace(Request $request, Tenant $tenant): RedirectResponse
    {
        try {
            $result = $this->runArtisanCommand('sync360:test-google-workspace', [
                'tenantSelector' => $tenant->slug,
            ]);
        } catch (Throwable $exception) {
            return $this->redirectToTenantShow($request, $tenant, 'google', $exception->getMessage());
        }

        return $this->redirectToTenantShow($request, $tenant, 'google', $this->formatArtisanStatus(
            sprintf('Google Workspace smoke test passed for %s.', $tenant->slug),
            $result['output'],
        ));
    }

    public function refreshRuntimeAvailableSkills(
        Request $request,
        Tenant $tenant,
        TenantRuntimeSkillDiscoveryService $runtimeSkillDiscovery,
    ): RedirectResponse {
        try {
            $inspection = $runtimeSkillDiscovery->inspect($tenant);
        } catch (Throwable $exception) {
            return $this->redirectToTenantShow($request, $tenant, 'skills', $exception->getMessage());
        }

        return redirect()
            ->route('admin.tenants.show', [
                'tenant' => $tenant,
                'tab' => $this->resolveTenantTab($request->input('return_tab', 'skills')),
            ])
            ->with('status', 'Runtime skills refreshed.')
            ->with('tenantRuntimeSkillInspection', array_merge($inspection, [
                'tenant_id' => $tenant->id,
            ]));
    }

    public function updateAgentCustomization(Request $request, Tenant $tenant): RedirectResponse
    {
        if (! $this->runtimeCustomizationTablesAvailable()) {
            return $this->redirectToTenantShow($request, $tenant, 'skills', 'Tenant runtime customization is unavailable until the required tenant customization migrations are applied locally.');
        }

        $payload = $this->validatedCustomizationPayload($request, $tenant);
        $this->tenantCustomizations->saveDraft($tenant->fresh(['businessProfileFiles', 'agentCustomization']), $request->user(), $payload);

        return $this->redirectToTenantShow($request, $tenant, $this->customizationTabFallback($request), 'Tenant customization draft saved.');
    }

    public function previewAgentCustomization(Request $request, Tenant $tenant): JsonResponse
    {
        if (! $this->runtimeCustomizationTablesAvailable()) {
            return response()->json([
                'message' => 'Agent runtime customization is unavailable until the required tenant customization migrations are applied locally.',
            ], 409);
        }

        $tenant->loadMissing(['businessProfile', 'businessProfileFiles', 'googleCredential', 'agentCustomization']);

        if ($request->all() !== []) {
            $payload = $this->validatedCustomizationPayload($request, $tenant);
            $customization = $tenant->agentCustomization ?: $tenant->agentCustomization()->make();
            $customization->forceFill([
                'prompt_overrides_json' => $payload['prompt_overrides'],
                'agent_defaults_json' => $payload['agent_defaults'],
            ]);
        } else {
            $customization = $tenant->agentCustomization;
        }

        $composed = $this->tenantRuntimeComposer->compose($tenant, $customization);

        return response()->json($composed->diagnosticPayload());
    }

    public function applyAgentCustomization(Request $request, Tenant $tenant): RedirectResponse
    {
        Gate::authorize('admin.tenants.agent-customization.apply');

        if (! $this->runtimeCustomizationTablesAvailable()) {
            return $this->redirectToTenantShow($request, $tenant, $this->customizationTabFallback($request), 'Tenant runtime customization is unavailable until the required tenant customization migrations are applied locally.');
        }

        $customization = $tenant->agentCustomization;

        if (! $customization) {
            return $this->redirectToTenantShow($request, $tenant, $this->customizationTabFallback($request), 'Save a tenant customization draft before applying it.');
        }

        $job = ProvisioningJob::query()->create([
            'tenant_id' => $tenant->id,
            'job_type' => ApplyTenantAgentCustomization::JOB_TYPE,
            'status' => ProvisioningJobStatus::Queued,
            'payload_json' => [
                'action' => TenantAgentCustomizationApply::ACTION_APPLY,
            ],
        ]);

        ApplyTenantAgentCustomization::dispatch(
            $tenant->id,
            $job->id,
            TenantAgentCustomizationApply::ACTION_APPLY,
        )->afterCommit();

        return $this->redirectToTenantShow($request, $tenant, $this->customizationTabFallback($request), 'Tenant customization apply queued.');
    }

    public function revertAgentCustomization(Request $request, Tenant $tenant): RedirectResponse
    {
        Gate::authorize('admin.tenants.agent-customization.apply');

        if (! $this->runtimeCustomizationTablesAvailable()) {
            return $this->redirectToTenantShow($request, $tenant, $this->customizationTabFallback($request), 'Tenant runtime customization is unavailable until the required tenant customization migrations are applied locally.');
        }

        $customization = $tenant->agentCustomization;

        if (! $customization?->last_applied_input_snapshot_json) {
            return $this->redirectToTenantShow($request, $tenant, $this->customizationTabFallback($request), 'There is no previously applied customization snapshot to restore.');
        }

        $job = ProvisioningJob::query()->create([
            'tenant_id' => $tenant->id,
            'job_type' => ApplyTenantAgentCustomization::JOB_TYPE,
            'status' => ProvisioningJobStatus::Queued,
            'payload_json' => [
                'action' => TenantAgentCustomizationApply::ACTION_REVERT,
            ],
        ]);

        ApplyTenantAgentCustomization::dispatch(
            $tenant->id,
            $job->id,
            TenantAgentCustomizationApply::ACTION_REVERT,
        )->afterCommit();

        return $this->redirectToTenantShow($request, $tenant, $this->customizationTabFallback($request), 'Tenant customization revert queued.');
    }

    public function agentCustomizationApplyLog(Tenant $tenant): JsonResponse
    {
        if (! $this->runtimeCustomizationTablesAvailable()) {
            return response()->json([
                'data' => [],
                'message' => 'Agent runtime customization is unavailable until the required tenant customization migrations are applied locally.',
            ], 409);
        }

        $entries = $tenant->agentCustomizationApplies()
            ->latest('id')
            ->get()
            ->map(fn (TenantAgentCustomizationApply $entry): array => [
                'id' => $entry->id,
                'action' => $entry->action,
                'status' => $entry->status,
                'draft_version_applied' => $entry->draft_version_applied,
                'before_output_hash' => $entry->before_output_hash,
                'after_output_hash' => $entry->after_output_hash,
                'error' => $entry->error,
                'created_at' => $entry->created_at?->toDateTimeString(),
            ])
            ->values();

        return response()->json([
            'data' => $entries,
        ]);
    }

    public function skillsCatalog(): View
    {
        $this->ensureSkillCatalogEnabled();

        return view('admin.skills.index', [
            'skills' => $this->skillCatalog->catalog(),
            'skillScan' => session('skillCatalogScan', ['rows' => [], 'missing' => []]),
            'skillCatalogFlags' => [
                'scan_enabled' => $this->skillCatalogFeatureEnabled('scan_enabled'),
                'import_enabled' => $this->skillCatalogFeatureEnabled('import_enabled'),
                'rollout_enabled' => $this->skillCatalogFeatureEnabled('rollout_enabled'),
            ],
        ]);
    }

    public function scanSkillCatalog(Request $request): RedirectResponse
    {
        $this->ensureSkillCatalogScanEnabled();

        $validated = $request->validate([
            'skill_key' => ['nullable', 'string'],
        ]);

        $skillKey = is_string($validated['skill_key'] ?? null) && trim((string) $validated['skill_key']) !== ''
            ? trim((string) $validated['skill_key'])
            : null;
        $scan = $this->skillCatalog->scanRepository($skillKey);
        $status = $scan['missing'] !== []
            ? sprintf('No repo skill matched [%s].', implode(', ', $scan['missing']))
            : sprintf('Scanned %d repo skill%s.', count($scan['rows']), count($scan['rows']) === 1 ? '' : 's');

        return redirect()
            ->route('admin.skills.index')
            ->with('status', $status)
            ->with('skillCatalogScan', $scan);
    }

    public function importSkillCatalog(Request $request): RedirectResponse
    {
        $this->ensureSkillCatalogImportEnabled();

        $validated = $request->validate([
            'skill_keys' => ['nullable', 'array'],
            'skill_keys.*' => ['string'],
        ]);

        $skillKeys = collect((array) ($validated['skill_keys'] ?? []))
            ->filter(fn ($skillKey): bool => is_string($skillKey) && trim($skillKey) !== '')
            ->map(fn (string $skillKey): string => trim($skillKey))
            ->unique()
            ->values()
            ->all();
        $result = $this->skillCatalog->importFromRepository($skillKeys !== [] ? $skillKeys : null);
        $messages = [];

        if ($result['imported'] !== []) {
            $messages[] = sprintf('Imported %s.', implode(', ', $result['imported']));
        }

        if ($result['skipped'] !== []) {
            $messages[] = sprintf('Skipped %s.', implode(', ', $result['skipped']));
        }

        if ($result['missing'] !== []) {
            $messages[] = sprintf('Missing %s.', implode(', ', $result['missing']));
        }

        if ($result['orphaned'] !== []) {
            $messages[] = sprintf('Orphaned warnings: %s.', implode(', ', $result['orphaned']));
        }

        if ($messages === []) {
            $messages[] = 'No eligible repo skills were imported.';
        }

        return redirect()
            ->route('admin.skills.index')
            ->with('status', implode(' ', $messages));
    }

    public function showSkillCatalog(SkillCatalogItem $skill): View
    {
        $this->ensureSkillCatalogEnabled();

        $skill->load(['versions' => fn ($query) => $query->latest('id')]);

        return view('admin.skills.show', [
            'skill' => $skill,
            'tenantAssignmentCounts' => TenantSkillAssignment::query()
                ->selectRaw('skill_catalog_version_id, count(*) as aggregate')
                ->where('skill_key', $skill->skill_key)
                ->where('is_enabled', true)
                ->groupBy('skill_catalog_version_id')
                ->pluck('aggregate', 'skill_catalog_version_id')
                ->all(),
        ]);
    }

    public function publishSkillCatalogVersion(SkillCatalogItem $skill, SkillCatalogVersion $version): RedirectResponse
    {
        $this->ensureSkillCatalogEnabled();

        $this->skillCatalog->publishVersion($version);

        return redirect()
            ->route('admin.skills.show', $skill)
            ->with('status', sprintf('Published %s version %s.', $skill->label, $version->version));
    }

    public function archiveSkillCatalogVersion(SkillCatalogItem $skill, SkillCatalogVersion $version): RedirectResponse
    {
        $this->ensureSkillCatalogEnabled();

        $this->skillCatalog->archiveVersion($version);

        return redirect()
            ->route('admin.skills.show', $skill)
            ->with('status', sprintf('Archived %s version %s.', $skill->label, $version->version));
    }

    public function rolloutSkillCatalogVersion(Request $request, SkillCatalogItem $skill, SkillCatalogVersion $version): RedirectResponse
    {
        $this->ensureSkillCatalogRolloutEnabled();

        Gate::authorize('admin.tenants.agent-customization.apply');

        $validated = $request->validate([
            'tenant_ids' => ['required', 'array'],
            'tenant_ids.*' => ['integer', 'exists:tenants,id'],
        ]);

        $tenantIds = array_values(array_unique(array_map('intval', $validated['tenant_ids'])));

        $assignments = TenantSkillAssignment::query()
            ->where('skill_key', $skill->skill_key)
            ->whereIn('tenant_id', $tenantIds)
            ->where('is_enabled', true)
            ->get()
            ->keyBy('tenant_id');

        foreach ($tenantIds as $tenantId) {
            $assignment = $assignments->get($tenantId);

            if (! $assignment) {
                continue;
            }

            $assignment->forceFill([
                'skill_catalog_version_id' => $version->id,
            ])->save();

            $job = ProvisioningJob::query()->create([
                'tenant_id' => $tenantId,
                'job_type' => ApplyTenantAgentCustomization::JOB_TYPE,
                'status' => ProvisioningJobStatus::Queued,
                'payload_json' => [
                    'action' => TenantAgentCustomizationApply::ACTION_APPLY,
                    'source' => 'skill_rollout',
                    'skill_key' => $skill->skill_key,
                    'skill_catalog_version_id' => $version->id,
                ],
            ]);

            ApplyTenantAgentCustomization::dispatch(
                $tenantId,
                $job->id,
                TenantAgentCustomizationApply::ACTION_APPLY,
            )->afterCommit();
        }

        return redirect()
            ->route('admin.skills.show', $skill)
            ->with('status', sprintf('Queued rollout of %s version %s.', $skill->label, $version->version));
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
     *     prompt_overrides: array<string, array{mode:string, content:string, base_snapshot:string}>,
     *     assigned_skill_keys: array<int, string>,
     *     agent_defaults: array<string, mixed>
     * }
     */
    private function validatedCustomizationPayload(Request $request, Tenant $tenant): array
    {
        $payload = $request->all();
        $defaultSkillIds = data_get($payload, 'agent_defaults.default_skill_ids');

        if (is_string($defaultSkillIds)) {
            data_set($payload, 'agent_defaults.default_skill_ids', [$defaultSkillIds]);
        }

        $validated = Validator::make($payload, [
            'prompt_overrides' => ['nullable', 'array'],
            'prompt_overrides.*.mode' => ['nullable', 'string', 'in:append,replace'],
            'prompt_overrides.*.content' => ['nullable', 'string'],
            'assigned_skill_keys' => ['nullable', 'array'],
            'assigned_skill_keys.*' => ['string'],
            'agent_defaults' => ['nullable', 'array'],
            'agent_defaults.model' => ['nullable', 'string'],
            'agent_defaults.default_skill_ids' => ['nullable', 'array'],
            'agent_defaults.default_skill_ids.*' => ['string'],
        ])->validate();

        $normalized = $this->tenantCustomizations->normalizedPayload($tenant->fresh(['businessProfileFiles']), $validated);
        $scope = $this->resolveCustomizationScope($request);
        $existingCustomization = $tenant->agentCustomization;
        $existingPromptOverrides = is_array($existingCustomization?->prompt_overrides_json) ? $existingCustomization->prompt_overrides_json : [];
        $existingAssignedSkillKeys = $tenant->skillAssignments
            ->where('is_enabled', true)
            ->pluck('skill_key')
            ->values()
            ->all();
        $existingAgentDefaults = is_array($existingCustomization?->agent_defaults_json) ? $existingCustomization->agent_defaults_json : [];

        if ($scope === 'skills') {
            $agentDefaults = $existingAgentDefaults;
            unset($agentDefaults['default_skill_ids']);

            if (array_key_exists('default_skill_ids', $normalized['agent_defaults'])) {
                $agentDefaults['default_skill_ids'] = $normalized['agent_defaults']['default_skill_ids'];
            }

            return [
                'prompt_overrides' => $existingPromptOverrides,
                'assigned_skill_keys' => $normalized['assigned_skill_keys'],
                'agent_defaults' => $agentDefaults,
            ];
        }

        if ($scope === 'agent-runtime') {
            $agentDefaults = $existingAgentDefaults;
            unset($agentDefaults['model']);

            if (array_key_exists('model', $normalized['agent_defaults'])) {
                $agentDefaults['model'] = $normalized['agent_defaults']['model'];
            }

            return [
                'prompt_overrides' => $normalized['prompt_overrides'],
                'assigned_skill_keys' => $existingAssignedSkillKeys,
                'agent_defaults' => $agentDefaults,
            ];
        }

        return $normalized;
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

    private function resolveTenantTab(null|string|array $tab): string
    {
        if (! is_string($tab)) {
            return 'overview';
        }

        return in_array($tab, self::TENANT_SHOW_TABS, true) ? $tab : 'overview';
    }

    private function redirectToTenantShow(Request $request, Tenant $tenant, string $fallbackTab, string $status): RedirectResponse
    {
        return redirect()
            ->route('admin.tenants.show', [
                'tenant' => $tenant,
                'tab' => $this->resolveTenantTab($request->input('return_tab', $fallbackTab)),
            ])
            ->with('status', $status);
    }

    private function customizationTabFallback(Request $request): string
    {
        return $this->resolveCustomizationScope($request) === 'agent-runtime'
            ? 'agent-runtime'
            : 'skills';
    }

    private function resolveCustomizationScope(Request $request): string
    {
        $scope = $request->input('customization_scope');

        if (is_string($scope) && in_array($scope, ['skills', 'agent-runtime'], true)) {
            return $scope;
        }

        $returnTab = $request->input('return_tab');

        if (is_string($returnTab) && in_array($returnTab, ['skills', 'agent-runtime'], true)) {
            return $returnTab;
        }

        return 'all';
    }

    private function agentCustomizationTablesAvailable(): bool
    {
        return Schema::hasTable('tenant_agent_customizations')
            && Schema::hasTable('tenant_agent_customization_applies');
    }

    private function tenantSkillTablesAvailable(): bool
    {
        return Schema::hasTable('skill_catalog_items')
            && Schema::hasTable('skill_catalog_versions')
            && Schema::hasTable('tenant_skill_assignments');
    }

    private function runtimeCustomizationTablesAvailable(): bool
    {
        return $this->agentCustomizationTablesAvailable()
            && $this->tenantSkillTablesAvailable();
    }

    /**
     * @return array{
     *     tenant_id:int,
     *     workspace_state:string,
     *     refreshed_at:string,
     *     skills:array<int, string>,
     *     raw_output:string
     * }|null
     */
    private function runtimeSkillInspectionFor(Request $request, Tenant $tenant): ?array
    {
        $inspection = $request->session()->get('tenantRuntimeSkillInspection');

        if (! is_array($inspection)) {
            return null;
        }

        if (($inspection['tenant_id'] ?? null) !== $tenant->id) {
            return null;
        }

        return [
            'tenant_id' => $tenant->id,
            'workspace_state' => (string) ($inspection['workspace_state'] ?? 'unknown'),
            'refreshed_at' => (string) ($inspection['refreshed_at'] ?? ''),
            'skills' => array_values(array_filter((array) ($inspection['skills'] ?? []), 'is_string')),
            'raw_output' => (string) ($inspection['raw_output'] ?? ''),
        ];
    }

    private function skillCatalogFeatureEnabled(string $key = 'enabled'): bool
    {
        return (bool) config('sync360.skill_catalog.'.$key, false);
    }

    private function ensureSkillCatalogEnabled(): void
    {
        abort_unless($this->skillCatalogFeatureEnabled('enabled'), 404);
    }

    private function ensureSkillCatalogScanEnabled(): void
    {
        abort_unless(
            $this->skillCatalogFeatureEnabled('enabled') && $this->skillCatalogFeatureEnabled('scan_enabled'),
            404,
        );
    }

    private function ensureSkillCatalogImportEnabled(): void
    {
        abort_unless(
            $this->skillCatalogFeatureEnabled('enabled') && $this->skillCatalogFeatureEnabled('import_enabled'),
            404,
        );
    }

    private function ensureSkillCatalogRolloutEnabled(): void
    {
        abort_unless(
            $this->skillCatalogFeatureEnabled('enabled') && $this->skillCatalogFeatureEnabled('rollout_enabled'),
            404,
        );
    }

    /**
     * @param  \Illuminate\Support\Collection<int, \App\Models\SkillCatalogItem>  $skillCatalog
     * @return array{
     *     label:string,
     *     class:string,
     *     summary_label:?string,
     *     summary_class:?string
     * }
     */
    private function tenantSkillsStatus(Tenant $tenant, $skillCatalog, bool $tenantSkillsAvailable): array
    {
        if (! $tenantSkillsAvailable) {
            return [
                'label' => 'setup needed',
                'class' => 'failed',
                'summary_label' => null,
                'summary_class' => null,
            ];
        }

        $enabledAssignments = $tenant->skillAssignments
            ->where('is_enabled', true)
            ->values();
        $assignedSkillKeys = $enabledAssignments
            ->pluck('skill_key')
            ->filter(fn (mixed $skillKey): bool => is_string($skillKey) && trim($skillKey) !== '')
            ->map(fn (string $skillKey): string => trim($skillKey))
            ->unique()
            ->values()
            ->all();
        sort($assignedSkillKeys);

        $defaultSkillIds = array_values(array_filter(
            (array) data_get($tenant->agentCustomization?->agent_defaults_json, 'default_skill_ids', []),
            fn (mixed $skillId): bool => is_string($skillId) && trim($skillId) !== '',
        ));
        $defaultSkillIds = array_map('trim', $defaultSkillIds);
        $defaultSkillIds = array_values(array_unique($defaultSkillIds));
        sort($defaultSkillIds);

        $appliedSnapshot = is_array($tenant->agentCustomization?->last_applied_input_snapshot_json)
            ? $tenant->agentCustomization->last_applied_input_snapshot_json
            : [];
        $appliedAssignedSkillKeys = collect((array) ($appliedSnapshot['assigned_skills'] ?? []))
            ->pluck('skill_key')
            ->filter(fn (mixed $skillKey): bool => is_string($skillKey) && trim($skillKey) !== '')
            ->map(fn (string $skillKey): string => trim($skillKey))
            ->unique()
            ->values()
            ->all();
        sort($appliedAssignedSkillKeys);

        $appliedDefaultSkillIds = array_values(array_filter(
            (array) data_get($appliedSnapshot, 'agent_defaults.default_skill_ids', []),
            fn (mixed $skillId): bool => is_string($skillId) && trim($skillId) !== '',
        ));
        $appliedDefaultSkillIds = array_map('trim', $appliedDefaultSkillIds);
        $appliedDefaultSkillIds = array_values(array_unique($appliedDefaultSkillIds));
        sort($appliedDefaultSkillIds);

        $hasPendingChanges = $assignedSkillKeys !== $appliedAssignedSkillKeys
            || $defaultSkillIds !== $appliedDefaultSkillIds;
        $hasAssignmentFailure = $enabledAssignments->contains(
            fn (TenantSkillAssignment $assignment): bool => $assignment->last_apply_status === 'failed'
        );
        $catalogCount = $skillCatalog->count();
        $assignmentCount = count($assignedSkillKeys);
        $defaultSkillCount = count($defaultSkillIds);
        $hasAnySkillState = $assignmentCount > 0 || $defaultSkillCount > 0;

        if ($hasAssignmentFailure) {
            $label = 'apply failed';
            $class = 'failed';
        } elseif ($hasPendingChanges) {
            $label = 'changes pending';
            $class = 'pending';
        } elseif (! $hasAnySkillState && $catalogCount === 0) {
            $label = 'catalog empty';
            $class = 'pending';
        } elseif (! $hasAnySkillState) {
            $label = 'no skills assigned';
            $class = 'pending';
        } else {
            $label = 'applied';
            $class = 'ready';
        }

        if ($assignmentCount > 0) {
            $summaryLabel = sprintf('%d assigned', $assignmentCount);
        } elseif ($defaultSkillCount > 0) {
            $summaryLabel = sprintf('%d default IDs', $defaultSkillCount);
        } elseif ($catalogCount === 0) {
            $summaryLabel = 'catalog empty';
        } else {
            $summaryLabel = 'none assigned';
        }

        return [
            'label' => $label,
            'class' => $class,
            'summary_label' => $summaryLabel,
            'summary_class' => $class === 'failed' ? 'failed' : 'pending',
        ];
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

    /**
     * @return array<int, array{entry: TenantAgentCustomizationApply, changes: array<int, string>}>
     */
    private function skillChangeHistoryFor(Tenant $tenant): array
    {
        $labelsById = collect($this->skillRegistry->all())
            ->mapWithKeys(fn (array $pack): array => [(string) $pack['id'] => (string) $pack['label']])
            ->all();

        $history = [];
        $previousSnapshot = [
            'assigned_skills' => [],
            'agent_defaults' => [
                'default_skill_ids' => [],
            ],
        ];

        foreach ($tenant->agentCustomizationApplies->sortBy('created_at') as $entry) {
            if (! in_array($entry->status, [
                TenantAgentCustomizationApply::STATUS_APPLIED,
                TenantAgentCustomizationApply::STATUS_REVERTED,
            ], true)) {
                continue;
            }

            $snapshot = is_array($entry->input_snapshot_json) ? $entry->input_snapshot_json : [];
            $currentPackIds = collect((array) ($snapshot['assigned_skills'] ?? []))
                ->pluck('skill_key')
                ->filter(fn (mixed $skillKey): bool => is_string($skillKey))
                ->values()
                ->all();
            $previousPackIds = collect((array) ($previousSnapshot['assigned_skills'] ?? []))
                ->pluck('skill_key')
                ->filter(fn (mixed $skillKey): bool => is_string($skillKey))
                ->values()
                ->all();
            $enabledPackIds = array_values(array_diff($currentPackIds, $previousPackIds));
            $disabledPackIds = array_values(array_diff($previousPackIds, $currentPackIds));
            $currentDefaultSkillIds = array_values(array_filter((array) data_get($snapshot, 'agent_defaults.default_skill_ids', []), 'is_string'));
            $previousDefaultSkillIds = array_values(array_filter((array) data_get($previousSnapshot, 'agent_defaults.default_skill_ids', []), 'is_string'));
            $addedDefaultSkillIds = array_values(array_diff($currentDefaultSkillIds, $previousDefaultSkillIds));
            $removedDefaultSkillIds = array_values(array_diff($previousDefaultSkillIds, $currentDefaultSkillIds));
            $changes = [];

            foreach ($enabledPackIds as $packId) {
                $changes[] = 'Enabled skill pack: '.($labelsById[$packId] ?? $packId);
            }

            foreach ($disabledPackIds as $packId) {
                $changes[] = 'Disabled skill pack: '.($labelsById[$packId] ?? $packId);
            }

            foreach ($addedDefaultSkillIds as $skillId) {
                $changes[] = 'Added default skill ID: '.$skillId;
            }

            foreach ($removedDefaultSkillIds as $skillId) {
                $changes[] = 'Removed default skill ID: '.$skillId;
            }

            if ($changes !== []) {
                $history[] = [
                    'entry' => $entry,
                    'changes' => $changes,
                ];
            }

            $previousSnapshot = $snapshot;
        }

        return array_reverse($history);
    }
}
