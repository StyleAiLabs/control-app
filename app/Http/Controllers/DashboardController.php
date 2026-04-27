<?php

namespace App\Http\Controllers;

use App\Contracts\DockerComposeRunner;
use App\Enums\TenantProvisioningStatus;
use App\Enums\TrialStatus;
use App\Models\Tenant;
use App\Models\TenantGoogleCredential;
use App\Models\TenantInboxMonitorMessage;
use App\Models\TenantInboxMonitorState;
use App\Models\TenantSkillConversionEvent;
use App\Services\LiteLlmTenantKeyService;
use App\Services\TenantAgentSyncService;
use App\Services\TenantInboxTriagePollingService;
use App\Services\TenantOnboardingSkillService;
use App\Services\TenantSkillAnalyticsReportService;
use App\Services\TenantRuntimeService;
use App\Services\TenantWorkspaceDependencyHealthService;
use App\Services\TenantWorkspaceReadinessService;
use App\Support\GoogleWorkspaceFeature;
use App\Support\OnboardingStepCatalog;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class DashboardController extends Controller
{
    public function __construct(
        private readonly DockerComposeRunner $dockerCompose,
        private readonly TenantRuntimeService $runtime,
        private readonly TenantAgentSyncService $agentSync,
        private readonly TenantOnboardingSkillService $onboardingSkills,
        private readonly TenantWorkspaceReadinessService $workspaceReadiness,
        private readonly TenantSkillAnalyticsReportService $skillAnalytics,
        private readonly TenantWorkspaceDependencyHealthService $dependencyHealth,
    ) {}

    public function index(Request $request): Response|RedirectResponse
    {
        if ($request->user()->is_admin && ! $request->user()->tenant) {
            return redirect()->route('admin.index');
        }

        $tenant = $request->user()->tenant()
            ->with(GoogleWorkspaceFeature::tenantRelations([
                'businessProfile',
                'businessProfileFiles',
                'inboxMonitorState',
                'skillAssignments.catalogVersion.item',
            ]))
            ->firstOrFail();
        $onboardingSummary = $this->onboardingSummary($tenant);
        $workspaceState    = $this->workspaceState($tenant);
        $agentContent      = $this->agentContent($tenant);
        $trialData         = $this->trialData($tenant);
        $analyticsWindow   = $this->analyticsWindow($request);
        $impactSummary     = $this->skillAnalytics->tenantSummaryForPeriod(
            $tenant,
            $analyticsWindow['start'],
            $analyticsWindow['days'],
        );
        $dependencyHealth  = $this->dependencyHealth->evaluate($tenant);
        $inboxOverview     = $this->inboxOverview($tenant, $dependencyHealth, $analyticsWindow);
        $performanceSeries = $this->performanceSeries($tenant, $analyticsWindow);

        // Inject workspace alert into the sidebar bell via request attributes
        // (the View composer in AppServiceProvider merges this in)
        // Also persist the result to the DB so all other pages show the alert too.
        if ($workspaceState === 'running') {
            // Clear any stale health-check failure so the bell goes away on other pages.
            if ($tenant->last_health_check_status !== null) {
                $tenant->forceFill([
                    'last_health_check_status' => null,
                    'health_check_message'     => null,
                ])->save();
            }
        } elseif ($workspaceState !== 'not_provisioned') {
            $wsAlertMsg = match ($workspaceState) {
                'stopped'        => 'Your workspace container is offline. Your digital employee cannot respond right now.',
                'missing_config' => 'Workspace configuration is missing. Contact support.',
                default          => 'We could not confirm your workspace is running. Contact support.',
            };

            // Persist so that all pages (Conversations, Profile, Setup…) show the bell alert
            // without needing their own live Docker call.
            $tenant->forceFill([
                'last_health_check_status' => 'failed',
                'health_check_message'     => $wsAlertMsg,
            ])->save();

            $request->attributes->set('_workspaceAlert', [[
                'type'    => 'warning',
                'icon'    => '⚠️',
                'title'   => 'Workspace offline',
                'message' => $wsAlertMsg,
                'cta'     => ['text' => 'Contact support', 'href' => 'mailto:hello@sync360.co.nz'],
            ]]);
        }

        return response()->view('dashboard', [
            'tenant'              => $tenant,
            'businessProfile'     => $tenant->businessProfile,
            'onboardingSummary'   => $onboardingSummary,
            'agentContent'        => $agentContent,
            'firstName'           => Str::of($request->user()->name)->before(' ')->value() ?: $request->user()->name,
            'trialContent'        => $this->trialContent($tenant->trial_status),
            'provisioningContent' => $this->provisioningContent($tenant->provisioning_status),
            'trialData'           => $trialData,
            'analyticsWindow'     => $analyticsWindow,
            'analyticsWindowOptions' => $this->analyticsWindowOptions(),
            'workspaceState'      => $workspaceState,
            'impactSummary'       => $impactSummary,
            'inboxOverview'       => $inboxOverview,
            'healthRail'          => $this->healthRail($tenant, $agentContent, $workspaceState, $trialData, $inboxOverview),
            'performanceSeries'   => $performanceSeries,
            'runwaySummary'       => $this->runwaySummary($trialData),
            'topSkillsSeries'     => $this->topSkillsSeries($impactSummary),
            'inboxPerformance'    => $this->inboxPerformance($tenant, $inboxOverview, $performanceSeries),
            'setupWizard'         => $this->setupWizard($onboardingSummary),
        ])->header('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0, private')
            ->header('Pragma', 'no-cache')
            ->header('Expires', 'Fri, 01 Jan 1990 00:00:00 GMT');
    }

    public function refreshTrialUsage(Request $request): JsonResponse
    {
        $tenant = $request->user()->tenant()->firstOrFail();

        try {
            $info = app(LiteLlmTenantKeyService::class)->getKeyInfo($tenant);

            $tenant->forceFill([
                'litellm_spend'           => $info['spend'],
                'litellm_spend_cached_at' => now(),
            ])->save();

            $tenant->refresh();

            return response()->json([
                'success'   => true,
                'trialData' => $this->trialData($tenant),
            ]);
        } catch (Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Could not refresh usage right now. Please try again shortly.',
            ], 503);
        }
    }

    /**
     * @return array{label: string, description: string}
     */
    private function trialContent(TrialStatus $status): array
    {
        return match ($status) {
            TrialStatus::Active => [
                'label' => 'Free trial active',
                'description' => 'You can explore everything and start configuring your digital employee now.',
            ],
            TrialStatus::Expired => [
                'label' => 'Trial ended',
                'description' => 'Your workspace is still here. Reach out when you are ready to reactivate it.',
            ],
        };
    }

    /**
     * @return array{
     *   is_expired: bool,
     *   max_budget: float,
     *   spend: float,
     *   days_left: int,
     *   budget_percent: float,
     *   time_percent: float,
     *   urgency: string,
     *   spend_cached_at: ?string
     * }
     */
    private function trialData(Tenant $tenant): array
    {
        return [
            'is_expired'      => $tenant->isTrialExpired(),
            'max_budget'      => (float) ($tenant->litellm_max_budget ?? 5.0),
            'spend'           => (float) ($tenant->litellm_spend ?? 0.0),
            'days_left'       => $tenant->trialDaysLeft(),
            'budget_percent'  => $tenant->trialBudgetPercent(),
            'time_percent'    => $tenant->trialTimePercent(),
            'urgency'         => $tenant->trialUrgency(),
            'spend_cached_at' => $tenant->litellm_spend_cached_at?->diffForHumans(),
        ];
    }

    /**
     * @return array{label: string, description: string}
     */
    private function provisioningContent(TenantProvisioningStatus $status): array
    {
        return match ($status) {
            TenantProvisioningStatus::Pending => [
                'label' => 'Queued for setup',
                'description' => 'We have your request and are getting your workspace lined up.',
            ],
            TenantProvisioningStatus::Provisioning => [
                'label' => 'Preparing your workspace',
                'description' => 'Your workspace is being configured and checked behind the scenes.',
            ],
            TenantProvisioningStatus::Ready => [
                'label' => 'Workspace is live',
                'description' => 'Your digital employee is online and ready for the next step.',
            ],
            TenantProvisioningStatus::Failed => [
                'label' => 'Setup needs attention',
                'description' => 'Something interrupted setup, but your workspace can be retried quickly.',
            ],
        };
    }

    /**
     * @return array{label:string,description:string,badge:string,primary_cta_label:string,primary_cta_route:string}
     */
    private function agentContent(Tenant $tenant): array
    {
        if ($tenant->onboarding_status !== 'complete') {
            return [
                'label' => 'Setup in progress',
                'description' => 'Finish the guided setup to bring your digital employee live.',
                'badge' => 'pending',
                'primary_cta_label' => 'Continue Setup',
                'primary_cta_route' => route('onboarding.show'),
            ];
        }

        return match ($tenant->agent_status) {
            'live' => [
                'label' => 'Live',
                'description' => 'Your digital employee is live and ready to respond on the connected channel.',
                'badge' => 'ready',
                'primary_cta_label' => 'Open Sync360 Workspace',
                'primary_cta_route' => $tenant->workspace_url ?: route('tenant.workspace-ready'),
            ],
            'deploying' => [
                'label' => 'Deploying',
                'description' => 'We are syncing the latest business profile into the live workspace now.',
                'badge' => 'pending',
                'primary_cta_label' => 'View Setup Progress',
                'primary_cta_route' => route('onboarding.show'),
            ],
            'failed' => [
                'label' => 'Needs attention',
                'description' => 'The live sync hit a problem. Review the setup details and try again.',
                'badge' => 'failed',
                'primary_cta_label' => 'Review Setup',
                'primary_cta_route' => route('onboarding.show'),
            ],
            default => [
                'label' => 'Offline',
                'description' => 'The workspace exists, but your digital employee has not been brought live yet.',
                'badge' => 'pending',
                'primary_cta_label' => 'Continue Setup',
                'primary_cta_route' => route('onboarding.show'),
            ],
        };
    }

    /**
     * @return array{
     *   resume_from_step:int,
     *   completed_steps:int,
     *   total_steps:int,
     *   steps:array<int, array{label:string,status:string}>
     * }
     */
    private function onboardingSummary(Tenant $tenant): array
    {
        $this->onboardingSkills->ensureCoreAssignments($tenant, $tenant->user_id);
        $profile = $tenant->businessProfile;
        $files = $tenant->businessProfileFiles;
        $services = collect(is_array($profile?->services) ? $profile->services : [])
            ->filter(fn (mixed $value): bool => is_string($value) && trim($value) !== '')
            ->values()
            ->all();
        $enabledModules = $this->onboardingSkills->enabledModules($tenant);
        $channelConfig = is_array($tenant->channel_config) ? $tenant->channel_config : [];
        $googleCredential = GoogleWorkspaceFeature::isAvailable() ? $tenant->googleCredential : null;
        $googleSyncJob = null;

        if (GoogleWorkspaceFeature::isAvailable() && $tenant->googleCredential?->isConnected()) {
            $googleSyncJob = $this->agentSync->latestInitialGoogleWorkspaceSyncJob($tenant);
        }

        $workspaceReadiness = $this->workspaceReadiness->evaluate($tenant, GoogleWorkspaceFeature::isAvailable(), $googleSyncJob);
        $stepLabels = OnboardingStepCatalog::labels();

        $steps = [
            1 => [
                'label' => $stepLabels[1],
                'status' => filled($profile?->website_url) || ((int) $tenant->onboarding_step >= 2 && filled($profile?->business_name) && filled($profile?->description) && $services !== []) ? 'complete' : 'incomplete',
            ],
            2 => [
                'label' => $stepLabels[2],
                'status' => ((int) $tenant->onboarding_step >= 2 && filled($profile?->business_name) && filled($profile?->description) && $services !== []) ? 'complete' : 'incomplete',
            ],
            3 => [
                'label' => $stepLabels[3],
                'status' => ((int) $tenant->onboarding_step >= 3 && filled($tenant->tone)) ? 'complete' : 'incomplete',
            ],
            4 => [
                'label' => $stepLabels[4],
                'status' => ((int) $tenant->onboarding_step >= 4 && $enabledModules !== [] && $files?->generated_at !== null) ? 'complete' : 'incomplete',
            ],
            5 => [
                'label' => $stepLabels[5],
                'status' => ((int) $tenant->onboarding_step >= 5
                    && $tenant->channel === 'telegram'
                    && filled($channelConfig['telegram_bot_token'] ?? null)) ? 'complete' : 'incomplete',
            ],
            6 => [
                'label' => $stepLabels[6],
                'status' => $workspaceReadiness['customer_ready'] ? 'complete' : 'incomplete',
            ],
            7 => [
                'label' => $stepLabels[7],
                'status' => $tenant->agent_status === 'live' ? 'complete' : 'incomplete',
            ],
        ];

        $resumeFromStep = 7;

        foreach ($steps as $stepNumber => $step) {
            if ($step['status'] === 'incomplete') {
                $resumeFromStep = $stepNumber;
                break;
            }
        }

        return [
            'resume_from_step' => $resumeFromStep,
            'completed_steps' => collect($steps)->where('status', 'complete')->count(),
            'total_steps' => count($steps),
            'steps' => $steps,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function inboxOverview(Tenant $tenant, array $dependencyHealth, array $analyticsWindow): ?array
    {
        $assignment = $tenant->skillAssignments
            ->first(fn ($item) => $item->skill_key === TenantInboxTriagePollingService::SKILL_KEY && $item->is_enabled);

        if (! $assignment) {
            return null;
        }

        $googleHealth = is_array($dependencyHealth['google_workspace'] ?? null) ? $dependencyHealth['google_workspace'] : [];
        $inboxHealth = is_array($dependencyHealth['inbox_monitor'] ?? null) ? $dependencyHealth['inbox_monitor'] : [];
        $cta = isset($dependencyHealth['primary_cta']) && is_array($dependencyHealth['primary_cta'])
            ? [
                'label' => $dependencyHealth['primary_cta']['text'],
                'route' => $dependencyHealth['primary_cta']['href'],
            ]
            : null;
        $googleSetupIncomplete = in_array($googleHealth['health_status'] ?? null, [
            TenantGoogleCredential::HEALTH_NOT_CONNECTED,
            TenantGoogleCredential::HEALTH_SYNCING,
        ], true);

        if (($googleHealth['requires_reconnect'] ?? false) || $googleSetupIncomplete || ($inboxHealth['health_status'] ?? null) === TenantInboxMonitorState::HEALTH_NOT_ENABLED) {
            $statusLabel = 'Setup incomplete';
            $statusNote = 'Reconnect Google Workspace to resume inbox monitoring';
        } elseif (($inboxHealth['health_status'] ?? null) === TenantInboxMonitorState::HEALTH_HEALTHY) {
            $statusLabel = 'Watching your inbox';
            $statusNote = $inboxHealth['health_note'] ?? 'Last checked recently';
        } else {
            $statusLabel = 'Needs attention';
            $statusNote = 'We’re having trouble checking your inbox right now.';
        }

        $secondaryNote = null;
        if (($googleHealth['health_status'] ?? null) === TenantGoogleCredential::HEALTH_EXPIRING_SOON) {
            $secondaryNote = $googleHealth['health_note'] ?? null;
        } elseif ($statusLabel === 'Watching your inbox' && $this->telegramDefaultChatId($tenant) === null) {
            $secondaryNote = 'Urgent Telegram alerts are not set up yet.';
        }

        return [
            'status_label' => $statusLabel,
            'status_note' => $statusNote,
            'secondary_note' => $secondaryNote,
            'value_line' => $this->inboxValueLine($tenant, $analyticsWindow),
            'cta' => $cta,
        ];
    }

    /**
     * @param  array{label:string,description:string,badge:string,primary_cta_label:string,primary_cta_route:string}  $agentContent
     * @param  array{
     *   is_expired: bool,
     *   max_budget: float,
     *   spend: float,
     *   days_left: int,
     *   budget_percent: float,
     *   time_percent: float,
     *   urgency: string,
     *   spend_cached_at: ?string
     * }  $trialData
     * @param  array<string,mixed>|null  $inboxOverview
     * @return array<int, array<string, mixed>>
     */
    private function healthRail(Tenant $tenant, array $agentContent, string $workspaceState, array $trialData, ?array $inboxOverview): array
    {
        $workspace = match ($workspaceState) {
            'running' => [
                'status' => 'running',
                'value' => 'Workspace is live',
                'note' => 'Your workspace is online and ready to receive work.',
            ],
            'stopped' => [
                'status' => 'stopped',
                'value' => 'Workspace stopped',
                'note' => 'The workspace needs attention before the assistant can respond.',
            ],
            'not_provisioned' => [
                'status' => 'pending',
                'value' => 'Provisioning in progress',
                'note' => 'We are still lining up the workspace for first launch.',
            ],
            'missing_config' => [
                'status' => 'failed',
                'value' => 'Configuration missing',
                'note' => 'Support needs to restore workspace configuration.',
            ],
            default => [
                'status' => 'warning',
                'value' => 'Status unavailable',
                'note' => 'We could not confirm the live workspace state just now.',
            ],
        };

        $trial = $trialData['is_expired']
            ? [
                'status' => 'expired',
                'value' => 'Expired',
                'note' => null,
            ]
            : [
                'status' => $trialData['urgency'] === 'critical' ? 'error' : ($trialData['urgency'] === 'warning' ? 'warning' : 'success'),
                'value' => $trialData['days_left'].' '.($trialData['days_left'] === 1 ? 'day' : 'days').' left',
                'note' => '$'.number_format($trialData['spend'], 2).' of $'.number_format($trialData['max_budget'], 2).' AI credit used',
            ];

        $assistant = $trialData['is_expired']
            ? [
                'status' => 'expired',
                'value' => 'Paused',
                'note' => 'Reactivation is needed before customer replies can resume.',
            ]
            : [
                'status' => $agentContent['badge'],
                'value' => $agentContent['label'],
                'note' => $agentContent['description'],
            ];

        $inbox = $inboxOverview
            ? [
                'status' => match ($inboxOverview['status_label']) {
                    'Watching your inbox' => 'healthy',
                    'Needs attention' => 'warning',
                    'Setup incomplete' => 'pending',
                    default => 'neutral',
                },
                'value' => $inboxOverview['status_label'],
                'note' => $inboxOverview['value_line'],
            ]
            : [
                'status' => 'neutral',
                'value' => 'Not enabled',
                'note' => 'Inbox monitoring will appear here once the workflow is turned on.',
            ];

        return [
            [
                'label' => 'Assistant',
                'status' => $assistant['status'],
                'value' => $assistant['value'],
                'note' => $assistant['note'],
            ],
            [
                'label' => 'Workspace',
                'status' => $workspace['status'],
                'value' => $workspace['value'],
                'note' => $workspace['note'],
            ],
            [
                'label' => 'Trial',
                'status' => $trial['status'],
                'value' => $trial['value'],
                'note' => $trial['note'],
                'dom_id' => 'dashboard-trial-health',
            ],
            [
                'label' => 'Inbox',
                'status' => $inbox['status'],
                'value' => $inbox['value'],
                'note' => $inbox['note'],
            ],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function performanceSeries(Tenant $tenant, array $analyticsWindow): array
    {
        $start = $analyticsWindow['start']->copy()->startOfDay();
        $days = max(1, $start->diffInDays(now()->startOfDay()) + 1);
        $dates = collect(range(0, $days - 1))
            ->map(fn (int $offset): Carbon => $start->copy()->addDays($offset));

        $conversionRows = TenantSkillConversionEvent::query()
            ->where('tenant_id', $tenant->id)
            ->where('occurred_at', '>=', $start)
            ->get(['occurred_at']);

        $reviewRows = TenantInboxMonitorMessage::query()
            ->where('tenant_id', $tenant->id)
            ->whereIn('status', [
                TenantInboxMonitorMessage::STATUS_SENT_TO_AGENT,
                TenantInboxMonitorMessage::STATUS_SKIPPED,
            ])
            ->where('detected_at', '>=', $start)
            ->get(['detected_at']);

        $conversionCounts = $conversionRows
            ->groupBy(fn (TenantSkillConversionEvent $event): string => optional($event->occurred_at)->toDateString() ?? '')
            ->map(fn (Collection $events): int => $events->count());

        $reviewCounts = $reviewRows
            ->groupBy(fn (TenantInboxMonitorMessage $message): string => optional($message->detected_at)->toDateString() ?? '')
            ->map(fn (Collection $messages): int => $messages->count());

        $items = $dates->map(function (Carbon $date) use ($conversionCounts, $reviewCounts): array {
            $key = $date->toDateString();

            return [
                'label' => $date->format('j M'),
                'short_label' => $date->format('j'),
                'value' => (int) ($reviewCounts[$key] ?? 0),
                'secondary' => (int) ($conversionCounts[$key] ?? 0),
            ];
        });

        $reviewed = (int) $items->sum('value');
        $outcomes = (int) $items->sum('secondary');

        return [
            'items' => $items->all(),
            'reviewed_total' => $reviewed,
            'outcomes_total' => $outcomes,
            'window_days' => $days,
            'window_label' => $analyticsWindow['label'],
            'has_data' => $reviewed > 0 || $outcomes > 0,
        ];
    }

    /**
     * @param  array{
     *   is_expired: bool,
     *   max_budget: float,
     *   spend: float,
     *   days_left: int,
     *   budget_percent: float,
     *   time_percent: float,
     *   urgency: string,
     *   spend_cached_at: ?string
     * }  $trialData
     * @return array<string,mixed>
     */
    private function runwaySummary(array $trialData): array
    {
        return [
            'is_expired' => $trialData['is_expired'],
            'budget_percent' => (float) $trialData['budget_percent'],
            'time_percent' => (float) $trialData['time_percent'],
            'days_left' => (int) $trialData['days_left'],
            'spend' => (float) $trialData['spend'],
            'max_budget' => (float) $trialData['max_budget'],
            'urgency' => (string) $trialData['urgency'],
            'spend_cached_at' => $trialData['spend_cached_at'],
            'summary' => $trialData['is_expired']
                ? 'Next step: reactivate service to resume customer replies.'
                : '$'.number_format($trialData['spend'], 2).' of $'.number_format($trialData['max_budget'], 2).' used with '.$trialData['days_left'].' '.($trialData['days_left'] === 1 ? 'day' : 'days').' remaining.',
        ];
    }

    /**
     * @param  array<string,mixed>  $impactSummary
     * @return array<string,mixed>
     */
    private function topSkillsSeries(array $impactSummary): array
    {
        $items = collect($impactSummary['top_skills'] ?? [])
            ->map(fn ($skill): array => [
                'label' => Str::headline((string) $skill->skill_key),
                'value' => (int) ($skill->conversions ?? 0),
                'note' => $this->formatMinutes((int) ($skill->net_minutes_saved ?? 0)).' saved',
            ])
            ->all();

        return [
            'items' => $items,
            'has_data' => count($items) > 0,
        ];
    }

    /**
     * @param  array<string,mixed>|null  $inboxOverview
     * @param  array<string,mixed>  $performanceSeries
     * @return array<string,mixed>
     */
    private function inboxPerformance(Tenant $tenant, ?array $inboxOverview, array $performanceSeries): array
    {
        if (! $inboxOverview) {
            return [
                'enabled' => false,
                'status_label' => 'Not enabled',
                'status_note' => 'Turn on inbox workflows to see reviewed work and lead signals here.',
                'value_line' => null,
                'secondary_note' => null,
                'cta' => [
                    'label' => 'Open setup',
                    'route' => route('onboarding.show'),
                ],
                'reviewed_total' => 0,
                'outcomes_total' => 0,
            ];
        }

        return [
            'enabled' => true,
            'status_label' => $inboxOverview['status_label'],
            'status_note' => $inboxOverview['status_note'],
            'value_line' => $inboxOverview['value_line'],
            'secondary_note' => $inboxOverview['secondary_note'] ?? null,
            'cta' => $inboxOverview['cta'] ?? null,
            'reviewed_total' => $performanceSeries['reviewed_total'],
            'outcomes_total' => $performanceSeries['outcomes_total'],
            'window_label' => $performanceSeries['window_label'],
        ];
    }

    /**
     * @param  array{
     *   resume_from_step:int,
     *   completed_steps:int,
     *   total_steps:int,
     *   steps:array<int, array{label:string,status:string}>
     * }  $onboardingSummary
     * @return array<string,mixed>|null
     */
    private function setupWizard(array $onboardingSummary): ?array
    {
        if ($onboardingSummary['completed_steps'] >= $onboardingSummary['total_steps']) {
            return null;
        }

        return [
            'current_step' => $onboardingSummary['resume_from_step'],
            'completed_steps' => $onboardingSummary['completed_steps'],
            'total_steps' => $onboardingSummary['total_steps'],
            'steps' => $onboardingSummary['steps'],
            'summary' => $onboardingSummary['completed_steps'].' of '.$onboardingSummary['total_steps'].' setup steps are complete, and step '.$onboardingSummary['resume_from_step'].' is next.',
        ];
    }

    private function inboxValueLine(Tenant $tenant, array $analyticsWindow): string
    {
        $from = $analyticsWindow['start'];
        $windowLabel = $analyticsWindow['label'];

        $qualifiedLeadCount = TenantSkillConversionEvent::query()
            ->where('tenant_id', $tenant->id)
            ->where('skill_key', TenantInboxTriagePollingService::SKILL_KEY)
            ->where('occurred_at', '>=', $from)
            ->count();

        if ($qualifiedLeadCount > 0) {
            return sprintf(
                '%d qualified lead%s in %s',
                $qualifiedLeadCount,
                $qualifiedLeadCount === 1 ? '' : 's',
                strtolower($windowLabel),
            );
        }

        $reviewedCount = TenantInboxMonitorMessage::query()
            ->where('tenant_id', $tenant->id)
            ->whereIn('status', [
                TenantInboxMonitorMessage::STATUS_SENT_TO_AGENT,
                TenantInboxMonitorMessage::STATUS_SKIPPED,
            ])
            ->where('detected_at', '>=', $from)
            ->count();

        if ($reviewedCount > 0) {
            return sprintf(
                '%d inbox item%s reviewed in %s',
                $reviewedCount,
                $reviewedCount === 1 ? '' : 's',
                strtolower($windowLabel),
            );
        }

        return 'Your inbox overview will appear here as new enquiries are reviewed.';
    }

    /**
     * @return array{key:string,label:string,start:Carbon,days:?int}
     */
    private function analyticsWindow(Request $request): array
    {
        $selected = $request->string('window')->trim()->toString();

        return $this->analyticsWindowOptions()[$selected] ?? $this->analyticsWindowOptions()['30d'];
    }

    /**
     * @return array<string, array{key:string,label:string,start:Carbon,days:?int}>
     */
    private function analyticsWindowOptions(): array
    {
        $today = now()->startOfDay();

        return [
            '7d' => [
                'key' => '7d',
                'label' => 'Last 7 days',
                'start' => $today->copy()->subDays(6),
                'days' => 7,
            ],
            '30d' => [
                'key' => '30d',
                'label' => 'Last 30 days',
                'start' => $today->copy()->subDays(29),
                'days' => 30,
            ],
            '90d' => [
                'key' => '90d',
                'label' => 'Last 90 days',
                'start' => $today->copy()->subDays(89),
                'days' => 90,
            ],
            'ytd' => [
                'key' => 'ytd',
                'label' => 'Year to date',
                'start' => $today->copy()->startOfYear(),
                'days' => null,
            ],
        ];
    }

    private function formatMinutes(int $minutes): string
    {
        if ($minutes >= 60) {
            $hours = intdiv($minutes, 60);
            $remainder = $minutes % 60;

            if ($remainder === 0) {
                return sprintf('%dh', $hours);
            }

            return sprintf('%dh %dm', $hours, $remainder);
        }

        return sprintf('%d min', $minutes);
    }

    private function telegramDefaultChatId(Tenant $tenant): ?string
    {
        $config = is_array($tenant->channel_config) ? $tenant->channel_config : [];
        $configured = trim((string) ($config['telegram_default_chat_id'] ?? ''));

        if ($configured !== '') {
            return $configured;
        }

        $latest = $tenant->conversationLogs()
            ->where('channel', 'telegram')
            ->whereNotNull('from_identifier')
            ->latest('id')
            ->value('from_identifier');

        return is_string($latest) && trim($latest) !== '' ? trim($latest) : null;
    }

    /**
     * Check the live Docker container state for this tenant's workspace.
     * Mirrors AdminController::workspaceStateFor() so the client dashboard
     * always reflects reality rather than the cached DB columns.
     *
     * Returns: 'running' | 'stopped' | 'not_provisioned' | 'missing_config'
     */
    private function workspaceState(Tenant $tenant): string
    {
        if (! $tenant->runtime_path && ! app()->environment('local')) {
            return 'not_provisioned';
        }

        if (app()->environment('local')) {
            $localCompose = $this->runtime->localRuntimePath($tenant) . DIRECTORY_SEPARATOR . 'compose.yaml';

            if (! file_exists($localCompose)) {
                return 'missing_config';
            }

            $projectName = Str::limit('sync360-' . $tenant->slug, 63, '');
            $result = Process::run(sprintf(
                '%s -f %s -p %s ps --format json',
                $this->runtime->localDockerComposeShellPrefix(),
                escapeshellarg($localCompose),
                escapeshellarg($projectName),
            ));

            return str_contains($result->output(), '"running"') ? 'running' : 'stopped';
        }

        try {
            if (! $tenant->runtime_path || ! $tenant->server) {
                return 'missing_config';
            }

            $composeFile = rtrim($tenant->runtime_path, DIRECTORY_SEPARATOR)
                . DIRECTORY_SEPARATOR
                . (string) config('sync360.openclaw.compose_filename', 'compose.yaml');

            $projectName = Str::limit('sync360-' . $tenant->slug, 63, '');

            return $this->dockerCompose->isRunning($tenant->server, $composeFile, $projectName)
                ? 'running'
                : 'stopped';
        } catch (RuntimeException $e) {
            Log::warning('[Dashboard] Could not check workspace state.', [
                'tenant_id' => $tenant->tenant_id,
                'error'     => $e->getMessage(),
            ]);

            return 'unknown';
        }
    }
}
