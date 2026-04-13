<?php

namespace App\Http\Controllers;

use App\Contracts\DockerComposeRunner;
use App\Models\ConversationLog;
use App\Models\Tenant;
use App\Enums\TenantProvisioningStatus;
use App\Enums\TrialStatus;
use App\Services\LiteLlmTenantKeyService;
use App\Services\TenantRuntimeService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
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
    ) {}

    public function index(Request $request): View|RedirectResponse
    {
        if ($request->user()->is_admin && ! $request->user()->tenant) {
            return redirect()->route('admin.index');
        }

        $tenant = $request->user()->tenant()
            ->with(['businessProfile', 'businessProfileFiles'])
            ->firstOrFail();
        $recentConversations = $tenant->conversationLogs()
            ->latest('created_at')
            ->limit(10)
            ->get();
        $onboardingSummary = $this->onboardingSummary($tenant);
        $workspaceState    = $this->workspaceState($tenant);

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

        return view('dashboard', [
            'tenant'              => $tenant,
            'businessProfile'     => $tenant->businessProfile,
            'businessFiles'       => $tenant->businessProfileFiles,
            'recentConversations' => $recentConversations,
            'conversationStats'   => $this->conversationStats($tenant),
            'onboardingSummary'   => $onboardingSummary,
            'agentContent'        => $this->agentContent($tenant),
            'firstName'           => Str::of($request->user()->name)->before(' ')->value() ?: $request->user()->name,
            'trialContent'        => $this->trialContent($tenant->trial_status),
            'provisioningContent' => $this->provisioningContent($tenant->provisioning_status),
            'trialData'           => $this->trialData($tenant),
            'workspaceState'      => $workspaceState,
        ]);
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
                'description' => 'Finish the guided setup to bring your digital employee live for customers.',
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
                'description' => 'The workspace exists, but the customer-facing assistant has not been brought live yet.',
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
        $profile = $tenant->businessProfile;
        $files = $tenant->businessProfileFiles;
        $services = collect(is_array($profile?->services) ? $profile->services : [])
            ->filter(fn (mixed $value): bool => is_string($value) && trim($value) !== '')
            ->values()
            ->all();
        $capabilities = collect(is_array($tenant->capabilities) ? $tenant->capabilities : [])
            ->filter(fn (mixed $value): bool => is_string($value) && trim($value) !== '')
            ->values()
            ->all();
        $channelConfig = is_array($tenant->channel_config) ? $tenant->channel_config : [];

        $steps = [
            1 => [
                'label' => 'Business Website',
                'status' => filled($profile?->website_url) || ((int) $tenant->onboarding_step >= 2 && filled($profile?->business_name) && filled($profile?->description) && $services !== []) ? 'complete' : 'incomplete',
            ],
            2 => [
                'label' => 'Business Info',
                'status' => ((int) $tenant->onboarding_step >= 2 && filled($profile?->business_name) && filled($profile?->description) && $services !== []) ? 'complete' : 'incomplete',
            ],
            3 => [
                'label' => 'Personality',
                'status' => ((int) $tenant->onboarding_step >= 3 && filled($tenant->tone)) ? 'complete' : 'incomplete',
            ],
            4 => [
                'label' => 'Capabilities',
                'status' => ((int) $tenant->onboarding_step >= 4 && $capabilities !== [] && $files?->generated_at !== null) ? 'complete' : 'incomplete',
            ],
            5 => [
                'label' => 'Channel',
                'status' => ((int) $tenant->onboarding_step >= 5 && match ($tenant->channel) {
                    'whatsapp' => filled($channelConfig['whatsapp_phone_number_id'] ?? null)
                        && filled($channelConfig['whatsapp_access_token'] ?? null)
                        && filled($channelConfig['whatsapp_verify_token'] ?? null),
                    'telegram' => filled($channelConfig['telegram_bot_token'] ?? null),
                    default => false,
                }) ? 'complete' : 'incomplete',
            ],
            6 => [
                'label' => 'Go Live',
                'status' => $tenant->agent_status === 'live' ? 'complete' : 'incomplete',
            ],
        ];

        $resumeFromStep = 6;

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
     * @return array{total:int,today:int,week:int}
     */
    private function conversationStats(Tenant $tenant): array
    {
        $baseQuery = ConversationLog::query()->where('tenant_id', $tenant->id);
        $todayStart = Carbon::now()->startOfDay();
        $weekStart = Carbon::now()->startOfWeek();

        return [
            'total' => (clone $baseQuery)->count(),
            'today' => (clone $baseQuery)->where('created_at', '>=', $todayStart)->count(),
            'week'  => (clone $baseQuery)->where('created_at', '>=', $weekStart)->count(),
        ];
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
                'docker compose -f %s -p %s ps --format json',
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
