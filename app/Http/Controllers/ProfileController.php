<?php

namespace App\Http\Controllers;

use App\Models\BusinessProfile;
use App\Models\Tenant;
use App\Services\TenantProfileSyncService;
use App\Services\TenantWorkspaceDependencyHealthService;
use App\Enums\TenantProvisioningStatus;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Throwable;

class ProfileController extends Controller
{
    public function __construct(
        private readonly TenantProfileSyncService $profileSync,
        private readonly \App\Services\TenantOnboardingSkillService $onboardingSkills,
        private readonly TenantWorkspaceDependencyHealthService $dependencyHealth,
    ) {
    }

    public function show(Request $request): View|RedirectResponse
    {
        if ($request->user()->is_admin && ! $request->user()->tenant) {
            return redirect()->route('admin.index');
        }

        $tenant = $this->tenantFor($request);

        return view('profile.show', [
            'tenant' => $tenant,
            'profile' => $tenant->businessProfile,
            'canSync' => $this->canSync($tenant),
            'dependencyHealth' => $this->dependencyHealth->evaluate($tenant),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $tenant = $this->tenantFor($request);
        $profile = $tenant->businessProfile ?: BusinessProfile::query()->create(['tenant_id' => $tenant->id]);
        $validated = $this->validatedPayload($request);
        $services = $this->lineValues($validated['services_text']);
        $businessHours = $this->lineValues($validated['business_hours_text'] ?? null);
        $faqs = $this->lineValues($validated['faqs_text'] ?? null);

        $profile->forceFill([
            'business_name' => $validated['business_name'],
            'trading_name' => $validated['trading_name'] ?? null,
            'website_url' => $validated['website_url'] ?? null,
            'industry' => $validated['industry'],
            'description' => $validated['description'],
            'tagline' => $validated['tagline'] ?? null,
            'contact_email' => $validated['contact_email'],
            'contact_phone' => $validated['contact_phone'] ?? null,
            'contact_mobile' => $validated['contact_mobile'] ?? null,
            'physical_address' => $validated['physical_address'] ?? null,
            'postal_address' => $validated['postal_address'] ?? null,
            'city' => $validated['city'] ?? null,
            'country' => $validated['country'] ?? null,
            'tax_number' => $validated['tax_number'] ?? null,
            'company_reg_number' => $validated['company_reg_number'] ?? null,
            'owner_name' => $validated['owner_name'] ?? null,
            'owner_email' => $validated['owner_email'] ?? null,
            'owner_phone' => $validated['owner_phone'] ?? null,
            'business_hours' => $businessHours,
            'after_hours_policy' => $validated['after_hours_policy'] ?? null,
            'primary_language' => $validated['primary_language'] ?? null,
            'services' => $services,
            'faqs' => $faqs,
            'target_customers' => $validated['target_customers'] ?? null,
            'pricing_notes' => $validated['pricing_notes'] ?? null,
            'profile_completeness' => $this->profileCompleteness($validated, $services, $businessHours),
        ])->save();

        $tenant->forceFill([
            'business_name' => $validated['business_name'],
            'industry' => $validated['industry'],
        ])->save();

        if ($tenant->agent_status === 'live') {
            try {
                $this->profileSync->regenerateAndSync($tenant->fresh(['businessProfile', 'businessProfileFiles', 'server']));

                return redirect()->route('profile.show')->with('status', 'Business profile saved and the live assistant has been synced.');
            } catch (Throwable $exception) {
                return redirect()->route('profile.show')->with('status', 'Business profile saved, but the live assistant sync failed: '.$exception->getMessage());
            }
        }

        return redirect()->route('profile.show')->with('status', 'Business profile saved.');
    }

    public function syncAgent(Request $request): RedirectResponse
    {
        $tenant = $this->tenantFor($request);

        if (! $this->canSync($tenant)) {
            return redirect()->route('profile.show')->with('status', 'Finish the guided setup first, then you can sync the latest business changes.');
        }

        try {
            $this->profileSync->regenerateAndSync($tenant->fresh(['businessProfile', 'businessProfileFiles', 'server']));
        } catch (Throwable $exception) {
            return redirect()->route('profile.show')->with('status', 'We saved your profile, but could not sync the assistant: '.$exception->getMessage());
        }

        return redirect()->route('profile.show')->with('status', 'The latest business profile has been synced to the assistant.');
    }

    private function tenantFor(Request $request): Tenant
    {
        return $request->user()->tenant()
            ->with(['businessProfile', 'businessProfileFiles', 'server'])
            ->firstOrFail();
    }

    /**
     * @return array<string, mixed>
     */
    private function validatedPayload(Request $request): array
    {
        return $request->validate([
            'business_name' => ['required', 'string', 'max:255'],
            'trading_name' => ['nullable', 'string', 'max:255'],
            'website_url' => ['nullable', 'url', 'max:500'],
            'industry' => ['required', 'string', 'max:255'],
            'description' => ['required', 'string', 'max:3000'],
            'tagline' => ['nullable', 'string', 'max:500'],
            'contact_email' => ['required', 'email:rfc', 'max:255'],
            'contact_phone' => ['nullable', 'string', 'max:50'],
            'contact_mobile' => ['nullable', 'string', 'max:50'],
            'physical_address' => ['nullable', 'string', 'max:1000'],
            'postal_address' => ['nullable', 'string', 'max:1000'],
            'city' => ['nullable', 'string', 'max:100'],
            'country' => ['nullable', 'string', 'max:100'],
            'tax_number' => ['nullable', 'string', 'max:100'],
            'company_reg_number' => ['nullable', 'string', 'max:100'],
            'owner_name' => ['nullable', 'string', 'max:255'],
            'owner_email' => ['nullable', 'email:rfc', 'max:255'],
            'owner_phone' => ['nullable', 'string', 'max:50'],
            'services_text' => ['required', 'string', 'max:4000'],
            'business_hours_text' => ['nullable', 'string', 'max:3000'],
            'after_hours_policy' => ['nullable', 'string', 'max:500'],
            'primary_language' => ['nullable', 'string', 'max:50'],
            'faqs_text' => ['nullable', 'string', 'max:4000'],
            'target_customers' => ['nullable', 'string', 'max:2000'],
            'pricing_notes' => ['nullable', 'string', 'max:2000'],
        ]);
    }

    /**
     * @return array<int, string>
     */
    private function lineValues(?string $input): array
    {
        if (! is_string($input)) {
            return [];
        }

        return array_values(array_filter(
            array_map(
                static fn (string $value): ?string => trim($value) !== '' ? trim($value) : null,
                preg_split('/\r?\n|,/', $input) ?: [],
            )
        ));
    }

    /**
     * @param  array<string, mixed>  $validated
     * @param  array<int, string>  $services
     * @param  array<int, string>  $businessHours
     */
    private function profileCompleteness(array $validated, array $services, array $businessHours): int
    {
        $checks = [
            filled($validated['business_name'] ?? null),
            filled($validated['industry'] ?? null),
            filled($validated['description'] ?? null),
            $services !== [],
            filled($validated['contact_email'] ?? null),
            filled($validated['contact_phone'] ?? null) || filled($validated['contact_mobile'] ?? null),
            filled($validated['website_url'] ?? null),
            $businessHours !== [],
            filled($validated['physical_address'] ?? null),
            filled($validated['tax_number'] ?? null),
        ];

        return (int) round((collect($checks)->filter()->count() / count($checks)) * 100);
    }

    private function canSync(Tenant $tenant): bool
    {
        $channelConfig = is_array($tenant->channel_config) ? $tenant->channel_config : [];
        $this->onboardingSkills->ensureCoreAssignments($tenant, $tenant->user_id);
        $hasEnabledModules = $this->onboardingSkills->enabledModules($tenant) !== [];

        return $tenant->onboarding_status === 'complete'
            && $tenant->provisioning_status === TenantProvisioningStatus::Ready
            && filled($tenant->tone)
            && $hasEnabledModules
            && filled($tenant->runtime_path)
            && filled($tenant->workspace_url)
            && $tenant->channel === 'telegram'
            && filled($channelConfig['telegram_bot_token'] ?? null);
    }
}
