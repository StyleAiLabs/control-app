<?php

namespace App\Http\Controllers;

use App\Enums\ProvisioningJobStatus;
use App\Exceptions\ExtractionFailedException;
use App\Models\BusinessProfile;
use App\Models\BusinessProfileFiles;
use App\Models\Tenant;
use App\Models\TenantGoogleCredential;
use App\Services\BusinessExtractionService;
use App\Services\GoogleWorkspaceOAuthService;
use App\Services\TenantAgentSyncService;
use App\Services\TenantWorkspaceReadinessService;
use App\Support\GoogleWorkspaceFeature;
use App\Support\OnboardingStepCatalog;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Throwable;

class OnboardingController extends Controller
{
    public function __construct(
        private readonly BusinessExtractionService $businessExtraction,
        private readonly TenantAgentSyncService $agentSync,
        private readonly GoogleWorkspaceOAuthService $googleOAuth,
        private readonly TenantWorkspaceReadinessService $workspaceReadiness,
    ) {
    }

    public function show(Request $request): View|RedirectResponse
    {
        if ($request->user()->is_admin && ! $request->user()->tenant) {
            return redirect()->route('admin.index');
        }

        $tenant = $this->tenantFor($request);

        return view('onboarding.show', [
            'tenant' => $tenant,
            'state' => $this->statePayload($tenant),
        ]);
    }

    public function state(Request $request): JsonResponse
    {
        return response()->json(
            $this->statePayload($this->tenantFor($request))
        );
    }

    public function extractBusiness(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'url' => ['required', 'url', 'max:500'],
        ]);

        $tenant = $this->tenantFor($request);
        $profile = $tenant->businessProfile
            ?? BusinessProfile::query()->firstOrCreate(
                ['tenant_id' => $tenant->id],
                ['business_name' => $tenant->business_name, 'industry' => $tenant->industry]
            );

        try {
            $result = $this->businessExtraction->extractFromUrl($validated['url']);
        } catch (ExtractionFailedException $exception) {
            return response()->json([
                'success' => false,
                'error' => 'extraction_failed',
                'message' => $exception->getMessage(),
            ], 422);
        }

        $profile?->forceFill([
            'website_url' => $result['website_url'],
            'business_name' => $result['business_name'] ?? $profile->business_name,
            'trading_name' => $result['trading_name'] ?? null,
            'tagline' => $result['tagline'] ?? null,
            'description' => $result['description'] ?? null,
            'industry' => $result['industry'] ?? $profile->industry,
            'services' => $result['services'] ?? [],
            'target_customers' => $result['target_customers'] ?? null,
            'tone_hint' => $result['tone_hint'] ?? null,
            'contact_email' => $result['contact_email'] ?? $profile->contact_email,
            'contact_phone' => $result['contact_phone'] ?? $profile->contact_phone,
            'contact_mobile' => $result['contact_mobile'] ?? null,
            'physical_address' => $result['physical_address'] ?? null,
            'city' => $result['city'] ?? null,
            'country' => $result['country'] ?? $profile->country,
            'pricing_notes' => $result['pricing_notes'] ?? null,
            'website_extracted_at' => now(),
            'website_extraction_raw' => $result['website_extraction_raw'] ?? null,
        ])->save();

        $tenant->forceFill([
            'onboarding_status' => $tenant->onboarding_status === 'complete' ? 'complete' : 'in_progress',
            'onboarding_step' => max((int) $tenant->onboarding_step, 1),
        ])->save();

        return response()->json([
            'success' => true,
            'message' => 'We’ve pulled in what we can from your website. Please review and adjust anything that needs fixing.',
            'state' => $this->statePayload($tenant->fresh(GoogleWorkspaceFeature::tenantRelations(['businessProfile', 'businessProfileFiles']))),
        ]);
    }

    public function saveBusinessInfo(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'business_name' => ['required', 'string', 'max:255'],
            'trading_name' => ['nullable', 'string', 'max:255'],
            'description' => ['required', 'string', 'max:2000'],
            'industry' => ['required', 'string', 'max:255'],
            'services' => ['required', 'array', 'min:1'],
            'services.*' => ['required', 'string', 'max:255'],
            'contact_email' => ['required', 'email:rfc', 'max:255'],
            'contact_phone' => ['nullable', 'string', 'max:50'],
            'contact_mobile' => ['nullable', 'string', 'max:50'],
            'physical_address' => ['nullable', 'string', 'max:500'],
            'city' => ['nullable', 'string', 'max:100'],
            'country' => ['nullable', 'string', 'max:100'],
            'tagline' => ['nullable', 'string', 'max:500'],
            'website_url' => ['nullable', 'url', 'max:500'],
            'owner_name' => ['nullable', 'string', 'max:255'],
            'tone_hint' => ['nullable', Rule::in(['friendly', 'professional', 'formal', 'casual'])],
        ]);

        $tenant = $this->tenantFor($request);
        $profile = $tenant->businessProfile
            ?? BusinessProfile::query()->firstOrCreate(
                ['tenant_id' => $tenant->id],
                ['business_name' => $tenant->business_name, 'industry' => $tenant->industry]
            );

        $services = array_values(array_filter(
            array_map(
                static fn (mixed $service): ?string => is_string($service) && trim($service) !== '' ? trim($service) : null,
                $validated['services']
            )
        ));

        $profile?->forceFill([
            'business_name' => $validated['business_name'],
            'trading_name' => $validated['trading_name'] ?? null,
            'website_url' => $validated['website_url'] ?? $profile->website_url,
            'industry' => $validated['industry'],
            'description' => $validated['description'],
            'tagline' => $validated['tagline'] ?? null,
            'contact_email' => $validated['contact_email'],
            'contact_phone' => $validated['contact_phone'] ?? null,
            'contact_mobile' => $validated['contact_mobile'] ?? null,
            'physical_address' => $validated['physical_address'] ?? null,
            'city' => $validated['city'] ?? null,
            'country' => $validated['country'] ?? $profile->country,
            'owner_name' => $validated['owner_name'] ?? $profile->owner_name,
            'services' => $services,
            'tone_hint' => $validated['tone_hint'] ?? $profile->tone_hint,
        ])->save();

        $tenant->forceFill([
            'business_name' => $validated['business_name'],
            'industry' => $validated['industry'],
            'onboarding_status' => $tenant->onboarding_status === 'complete' ? 'complete' : 'in_progress',
            'onboarding_step' => max((int) $tenant->onboarding_step, 2),
        ])->save();

        return response()->json([
            'success' => true,
            'message' => 'Your business details are saved.',
            'state' => $this->statePayload($tenant->fresh(GoogleWorkspaceFeature::tenantRelations(['businessProfile', 'businessProfileFiles']))),
        ]);
    }

    public function savePersonality(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'tone' => ['required', Rule::in(['friendly', 'professional', 'formal', 'casual'])],
        ]);

        $tenant = $this->tenantFor($request);
        $profile = $tenant->businessProfile
            ?? BusinessProfile::query()->firstOrCreate(
                ['tenant_id' => $tenant->id],
                ['business_name' => $tenant->business_name, 'industry' => $tenant->industry]
            );

        $tenant->forceFill([
            'tone' => $validated['tone'],
            'onboarding_status' => $tenant->onboarding_status === 'complete' ? 'complete' : 'in_progress',
            'onboarding_step' => max((int) $tenant->onboarding_step, 3),
        ])->save();

        $profile->forceFill([
            'tone_hint' => $validated['tone'],
        ])->save();

        return response()->json([
            'success' => true,
            'message' => 'We’ve saved how your digital employee should sound.',
            'state' => $this->statePayload($tenant->fresh(GoogleWorkspaceFeature::tenantRelations(['businessProfile', 'businessProfileFiles']))),
        ]);
    }

    public function saveCapabilities(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'capabilities' => ['required', 'array', 'min:1'],
            'capabilities.*' => ['required', Rule::in(['faqs', 'messages', 'complaints', 'after_hours', 'appointments', 'pricing'])],
        ]);

        $tenant = $this->tenantFor($request);
        $profile = $tenant->businessProfile
            ?? BusinessProfile::query()->firstOrCreate(
                ['tenant_id' => $tenant->id],
                ['business_name' => $tenant->business_name, 'industry' => $tenant->industry]
            );
        $files = $tenant->businessProfileFiles
            ?? BusinessProfileFiles::query()->firstOrCreate(
                ['tenant_id' => $tenant->id]
            );

        if (! filled($tenant->tone)) {
            return response()->json([
                'success' => false,
                'message' => 'Choose the communication style first, then we can save the capabilities.',
            ], 422);
        }

        $capabilities = array_values(array_unique(array_filter(
            array_map(
                static fn (mixed $value): ?string => is_string($value) && trim($value) !== '' ? trim($value) : null,
                $validated['capabilities']
            )
        )));

        $generated = $this->businessExtraction->generateAgentFiles($profile, $tenant->tone, $capabilities, $tenant->skill_pack);

        $tenant->forceFill([
            'capabilities' => $capabilities,
            'onboarding_status' => $tenant->onboarding_status === 'complete' ? 'complete' : 'in_progress',
            'onboarding_step' => max((int) $tenant->onboarding_step, 4),
        ])->save();

        $files->forceFill([
            'identity_markdown' => $generated['identity'],
            'soul_markdown' => $generated['soul'],
            'user_markdown' => $generated['user'],
            'bootstrap_markdown' => $generated['bootstrap'],
            'generated_at' => now(),
        ])->save();

        return response()->json([
            'success' => true,
            'message' => 'Your digital employee capabilities are saved and the internal setup files are ready.',
            'state' => $this->statePayload($tenant->fresh(GoogleWorkspaceFeature::tenantRelations(['businessProfile', 'businessProfileFiles']))),
        ]);
    }

    public function saveChannel(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'channel' => ['required', Rule::in(['telegram'])],
            'telegram_bot_token' => ['nullable', 'string', 'max:2000'],
        ]);

        $tenant = $this->tenantFor($request);

        if ((int) $tenant->onboarding_step < 4) {
            return response()->json([
                'success' => false,
                'message' => 'Finish choosing the communication style and capabilities first, then connect the customer channel.',
            ], 422);
        }

        $channel = $validated['channel'];
        $channelConfig = $this->telegramChannelConfig($request);

        $tenant->forceFill([
            'channel' => $channel,
            'channel_config' => $channelConfig,
            'onboarding_status' => $tenant->onboarding_status === 'complete' ? 'complete' : 'in_progress',
            'onboarding_step' => max((int) $tenant->onboarding_step, 5),
        ])->save();

        /* Write channel config to the tenant's OpenClaw instance and restart
           the gateway so it starts listening immediately. */
        $channelConfigured = false;

        if ($channel === 'telegram') {
            try {
                $this->agentSync->configureChannel($tenant);
                $channelConfigured = true;
            } catch (Throwable $exception) {
                report($exception);
            }
        }

        $message = match (true) {
            $channel === 'telegram' && $channelConfigured => 'Telegram is connected — your assistant is ready to receive messages.',
            $channel === 'telegram' => 'Telegram details saved. We could not configure the assistant automatically right now — please try again shortly.',
            default => 'Channel details saved.',
        };

        return response()->json([
            'success' => true,
            'message' => $message,
            'state' => $this->statePayload($tenant->fresh(GoogleWorkspaceFeature::tenantRelations(['businessProfile', 'businessProfileFiles']))),
        ]);
    }

    public function disconnectChannel(Request $request): JsonResponse
    {
        $tenant = $this->tenantFor($request);

        $tenant->forceFill([
            'channel' => null,
            'channel_config' => null,
        ])->save();

        /* Remove channel config from openclaw.json and restart the gateway. */
        try {
            $this->agentSync->removeChannelConfig($tenant);
        } catch (Throwable $exception) {
            report($exception);
        }

        return response()->json([
            'success' => true,
            'message' => 'Channel disconnected.',
            'state' => $this->statePayload($tenant->fresh(GoogleWorkspaceFeature::tenantRelations(['businessProfile', 'businessProfileFiles']))),
        ]);
    }

    public function goLive(Request $request): JsonResponse
    {
        $tenant = $this->tenantFor($request);
        $googleSyncJob = $this->latestInitialGoogleWorkspaceSyncJob($tenant);
        $readiness = $this->workspaceReadiness->evaluate($tenant, GoogleWorkspaceFeature::isAvailable(), $googleSyncJob);
        $isResync = $tenant->agent_status === 'live';

        if (! $isResync && ! $readiness['go_live_ready']) {
            return response()->json([
                'success' => false,
                'code' => $readiness['blocking_code'],
                'message' => $readiness['blocking_message'] ?? 'Your workspace is not ready to go live yet.',
            ], 409);
        }

        try {
            $this->agentSync->goLive($tenant);
        } catch (Throwable $exception) {
            return response()->json([
                'success' => false,
                'message' => $exception->getMessage(),
            ], 422);
        }

        return response()->json([
            'success' => true,
            'message' => $isResync
                ? 'Your digital employee has been resynced with the latest setup details.'
                : 'Your digital employee is now live and ready to start helping customers.',
            'state' => $this->statePayload($tenant->fresh(GoogleWorkspaceFeature::tenantRelations(['businessProfile', 'businessProfileFiles']))),
        ]);
    }

    private function tenantFor(Request $request): Tenant
    {
        return $request->user()->tenant()
            ->with(GoogleWorkspaceFeature::tenantRelations(['businessProfile', 'businessProfileFiles', 'server']))
            ->firstOrFail();
    }

    /**
     * @return array<string, mixed>
     */
    private function statePayload(Tenant $tenant): array
    {
        $profile = $tenant->businessProfile;
        $files = $tenant->businessProfileFiles;
        $services = is_array($profile?->services) ? array_values(array_filter($profile->services, fn (mixed $value): bool => is_string($value) && trim($value) !== '')) : [];
        $capabilities = is_array($tenant->capabilities) ? array_values(array_filter($tenant->capabilities, fn (mixed $value): bool => is_string($value) && trim($value) !== '')) : [];
        $channelConfig = is_array($tenant->channel_config) ? $tenant->channel_config : [];
        $googleCredential = GoogleWorkspaceFeature::isAvailable() ? $tenant->googleCredential : null;
        $googleSyncJob = $this->latestInitialGoogleWorkspaceSyncJob($tenant);
        $workspaceReadiness = $this->workspaceReadiness->evaluate($tenant, GoogleWorkspaceFeature::isAvailable(), $googleSyncJob);
        $stepLabels = OnboardingStepCatalog::labels();

        $steps = [
            1 => [
                'label' => $stepLabels[1],
                'status' => $this->stepOneComplete($tenant, $profile, $services) ? 'complete' : 'incomplete',
            ],
            2 => [
                'label' => $stepLabels[2],
                'status' => $this->stepTwoComplete($tenant, $profile, $services) ? 'complete' : 'incomplete',
            ],
            3 => [
                'label' => $stepLabels[3],
                'status' => ((int) $tenant->onboarding_step >= 3 && filled($tenant->tone)) ? 'complete' : 'incomplete',
            ],
            4 => [
                'label' => $stepLabels[4],
                'status' => ((int) $tenant->onboarding_step >= 4 && $capabilities !== [] && $files?->generated_at !== null) ? 'complete' : 'incomplete',
            ],
            5 => [
                'label' => $stepLabels[5],
                'status' => $this->stepFiveComplete($tenant, $channelConfig) ? 'complete' : 'incomplete',
            ],
            6 => [
                'label' => $stepLabels[6],
                'status' => $this->stepSixComplete($workspaceReadiness) ? 'complete' : 'incomplete',
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
            'onboarding_status' => $tenant->onboarding_status,
            'onboarding_step' => $tenant->onboarding_step,
            'provisioning_status' => $tenant->provisioning_status->value,
            'agent_status' => $tenant->agent_status,
            'resume_from_step' => $resumeFromStep,
            'steps' => $steps,
            'tenant' => [
                'business_name' => $tenant->business_name,
                'industry' => $tenant->industry,
                'skill_pack' => $tenant->skill_pack,
            ],
            'business' => [
                'business_name' => $profile?->business_name,
                'trading_name' => $profile?->trading_name,
                'website_url' => $profile?->website_url,
                'description' => $profile?->description,
                'industry' => $profile?->industry,
                'services' => $services,
                'contact_email' => $profile?->contact_email,
                'contact_phone' => $profile?->contact_phone,
                'contact_mobile' => $profile?->contact_mobile,
                'physical_address' => $profile?->physical_address,
                'city' => $profile?->city,
                'country' => $profile?->country,
                'tagline' => $profile?->tagline,
                'owner_name' => $profile?->owner_name,
                'tone_hint' => $profile?->tone_hint,
            ],
            'tone' => $tenant->tone,
            'capabilities' => $capabilities,
            'channel' => $tenant->channel === 'telegram' ? 'telegram' : null,
            'channel_setup' => $this->channelSetupPayload($tenant, $channelConfig),
            'google_workspace' => $this->googleWorkspacePayload($tenant, $googleCredential, $workspaceReadiness, $googleSyncJob),
            'workspace' => [
                'url' => $tenant->workspace_url,
                'ready' => $workspaceReadiness['ready'],
                'runtime_ready' => $workspaceReadiness['runtime_ready'],
                'customer_ready' => $workspaceReadiness['customer_ready'],
                'go_live_ready' => $workspaceReadiness['go_live_ready'],
                'blocking_code' => $workspaceReadiness['blocking_code'],
                'blocking_message' => $workspaceReadiness['blocking_message'],
                'next_action' => $workspaceReadiness['next_action'],
            ],
            'files' => [
                'generated_at' => $files?->generated_at?->toIso8601String(),
                'synced_at' => $files?->synced_at?->toIso8601String(),
            ],
        ];
    }

    /**
     * @param  array<int, string>  $services
     */
    private function stepOneComplete(Tenant $tenant, mixed $profile, array $services): bool
    {
        return filled($profile?->website_url)
            || $this->stepTwoComplete($tenant, $profile, $services);
    }

    /**
     * @param  array<int, string>  $services
     */
    private function stepTwoComplete(Tenant $tenant, mixed $profile, array $services): bool
    {
        return (int) $tenant->onboarding_step >= 2
            && filled($profile?->business_name)
            && filled($profile?->description)
            && $services !== [];
    }

    /**
     * @param  array<string, mixed>  $channelConfig
     * @return array<string, mixed>
     */
    private function channelSetupPayload(Tenant $tenant, array $channelConfig): array
    {
        $botTokenSaved = filled($channelConfig['telegram_bot_token'] ?? null);
        $runtimeConfigured = $this->agentSync->isSavedChannelRuntimeConfigured($tenant);
        $status = match (true) {
            $botTokenSaved && $runtimeConfigured => 'connected',
            $botTokenSaved => 'saved',
            default => 'pending',
        };

        return [
            'selected_channel' => $tenant->channel === 'telegram' ? 'telegram' : null,
            'status' => $status,
            'telegram' => [
                'bot_token_saved' => $botTokenSaved,
                'runtime_configured' => $runtimeConfigured,
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $channelConfig
     */
    private function stepFiveComplete(Tenant $tenant, array $channelConfig): bool
    {
        $channel = $tenant->channel;

        if (! filled($channel)) {
            return false;
        }

        return (int) $tenant->onboarding_step >= 5
            && $channel === 'telegram'
            && filled($channelConfig['telegram_bot_token'] ?? null);
    }

    /**
     * @param  array<string, mixed>  $workspaceReadiness
     */
    private function stepSixComplete(array $workspaceReadiness): bool
    {
        if (! GoogleWorkspaceFeature::isAvailable()) {
            return true;
        }

        return $workspaceReadiness['customer_ready'] === true
            || $workspaceReadiness['go_live_ready'] === true;
    }

    /**
     * @return array<string, mixed>
     */
    private function googleWorkspacePayload(
        Tenant $tenant,
        ?TenantGoogleCredential $credential,
        array $workspaceReadiness,
        ?\App\Models\ProvisioningJob $syncJob,
    ): array
    {
        if (! GoogleWorkspaceFeature::isAvailable()) {
            return [
                'status' => 'unavailable',
                'runtime_sync_status' => 'unavailable',
                'connected_email' => null,
                'scopes' => $this->googleOAuth->scopes(),
                'can_connect' => false,
                'can_reconnect' => false,
                'connected' => false,
                'pending_sync' => false,
                'sync_queued' => false,
                'sync_in_progress' => false,
                'sync_job_status' => null,
                'runtime_sync_label' => 'Unavailable',
                'requires_action' => false,
                'workspace_ready' => $tenant->provisioning_status->value === 'ready',
                'available' => false,
            ];
        }

        $configured = filled(config('services.google.client_id'))
            && filled(config('services.google.client_secret'))
            && filled(config('services.google.redirect_uri'));
        $status = $credential?->status ?? TenantGoogleCredential::STATUS_PENDING;
        $workspaceReady = $workspaceReadiness['runtime_ready'];
        $runtimeSyncStatus = $credential?->runtime_sync_status ?? TenantGoogleCredential::RUNTIME_SYNC_PENDING;
        $syncJob = $status === TenantGoogleCredential::STATUS_CONNECTED ? $syncJob : null;
        $syncJobStatus = $syncJob?->status?->value;
        $syncQueued = $status === TenantGoogleCredential::STATUS_CONNECTED
            && $syncJob?->status === ProvisioningJobStatus::Queued;
        $syncInProgress = $status === TenantGoogleCredential::STATUS_CONNECTED
            && $syncJob?->status === ProvisioningJobStatus::Running
            && $runtimeSyncStatus === TenantGoogleCredential::RUNTIME_SYNC_PENDING;
        $pendingVerification = $status === TenantGoogleCredential::STATUS_CONNECTED
            && $runtimeSyncStatus === TenantGoogleCredential::RUNTIME_SYNC_SYNCED;
        $verified = $status === TenantGoogleCredential::STATUS_CONNECTED
            && $runtimeSyncStatus === TenantGoogleCredential::RUNTIME_SYNC_VERIFIED;
        $needsAttention = $status === TenantGoogleCredential::STATUS_CONNECTED
            && $runtimeSyncStatus === TenantGoogleCredential::RUNTIME_SYNC_FAILED;

        return [
            'status' => $status,
            'runtime_sync_status' => $runtimeSyncStatus,
            'runtime_sync_label' => (string) $workspaceReadiness['google_runtime_sync_label'],
            'connected_email' => $credential?->google_email,
            'scopes' => is_array($credential?->scopes) ? $credential->scopes : $this->googleOAuth->scopes(),
            'can_connect' => $configured,
            'can_reconnect' => $status === TenantGoogleCredential::STATUS_DISCONNECTED,
            'connected' => $status === TenantGoogleCredential::STATUS_CONNECTED,
            'pending_sync' => $status === TenantGoogleCredential::STATUS_CONNECTED
                && $runtimeSyncStatus === TenantGoogleCredential::RUNTIME_SYNC_PENDING
                && ! $workspaceReady
                && ! $syncQueued
                && ! $syncInProgress,
            'sync_queued' => $syncQueued,
            'sync_in_progress' => $syncInProgress,
            'sync_job_status' => $syncJobStatus,
            'sync_job_started_at' => $syncJob?->started_at?->toIso8601String(),
            'sync_job_completed_at' => $syncJob?->completed_at?->toIso8601String(),
            'pending_verification' => $pendingVerification,
            'verified' => $verified,
            'needs_attention' => $needsAttention,
            'requires_action' => $status === TenantGoogleCredential::STATUS_PENDING,
            'workspace_ready' => $workspaceReady,
            'available' => true,
            'last_error' => $credential?->last_error,
            'last_synced_at' => $credential?->last_synced_at?->toIso8601String(),
            'customer_ready' => $workspaceReadiness['customer_ready'],
            'go_live_ready' => $workspaceReadiness['go_live_ready'],
        ];
    }

    private function latestInitialGoogleWorkspaceSyncJob(Tenant $tenant): ?\App\Models\ProvisioningJob
    {
        if (! GoogleWorkspaceFeature::isAvailable() || ! $tenant->googleCredential?->isConnected()) {
            return null;
        }

        return $this->agentSync->latestInitialGoogleWorkspaceSyncJob($tenant);
    }

    /**
     * @return array<string, string>
     */
    private function telegramChannelConfig(Request $request): array
    {
        return $request->validate([
            'telegram_bot_token' => ['required', 'string', 'max:2000'],
        ]);
    }
}
