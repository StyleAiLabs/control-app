<?php

namespace App\Http\Controllers;

use App\Models\ConversationLog;
use App\Models\Tenant;
use App\Enums\TenantProvisioningStatus;
use App\Enums\TrialStatus;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

class DashboardController extends Controller
{
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

        return view('dashboard', [
            'tenant' => $tenant,
            'businessProfile' => $tenant->businessProfile,
            'businessFiles' => $tenant->businessProfileFiles,
            'recentConversations' => $recentConversations,
            'conversationStats' => $this->conversationStats($tenant),
            'onboardingSummary' => $onboardingSummary,
            'agentContent' => $this->agentContent($tenant),
            'firstName' => Str::of($request->user()->name)->before(' ')->value() ?: $request->user()->name,
            'trialContent' => $this->trialContent($tenant->trial_status),
            'provisioningContent' => $this->provisioningContent($tenant->provisioning_status),
        ]);
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
                'primary_cta_label' => 'Open Workspace',
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
            'week' => (clone $baseQuery)->where('created_at', '>=', $weekStart)->count(),
        ];
    }
}
