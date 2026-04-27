<?php

namespace App\Http\Controllers;

use App\Models\BusinessProfile;
use App\Models\BusinessProfileFiles;
use App\Models\Tenant;
use App\Services\TenantAgentSyncService;
use App\Services\TenantProfileSyncService;
use App\Services\TenantWorkspaceDependencyHealthService;
use App\Enums\TenantProvisioningStatus;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

class ProfileController extends Controller
{
    public function __construct(
        private readonly TenantProfileSyncService $profileSync,
        private readonly \App\Services\TenantOnboardingSkillService $onboardingSkills,
        private readonly TenantWorkspaceDependencyHealthService $dependencyHealth,
        private readonly TenantAgentSyncService $agentSync,
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
            'logo' => $this->logoPayload($tenant->businessProfileFiles),
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

    public function showLogo(Request $request)
    {
        $tenant = $this->tenantFor($request);
        $files = $tenant->businessProfileFiles;

        abort_unless($files instanceof BusinessProfileFiles, 404);

        $storagePath = is_string($files->logo_storage_path) ? trim($files->logo_storage_path) : '';

        if ($storagePath === '' || ! Storage::disk('local')->exists($storagePath)) {
            abort(404);
        }

        $filename = $files->logo_original_filename ?: basename($storagePath);

        return response(Storage::disk('local')->get($storagePath), 200, [
            'Content-Type' => $files->logo_mime_type ?: Storage::disk('local')->mimeType($storagePath) ?: 'application/octet-stream',
            'Content-Disposition' => 'inline; filename="'.$filename.'"',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    public function uploadLogo(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'logo' => ['required', 'file', 'image', 'mimes:png,jpg,jpeg,webp', 'max:2048'],
        ]);

        $tenant = $this->tenantFor($request);
        $files = $tenant->businessProfileFiles ?: BusinessProfileFiles::query()->create(['tenant_id' => $tenant->id]);
        /** @var UploadedFile $uploadedLogo */
        $uploadedLogo = $validated['logo'];
        $extension = strtolower($uploadedLogo->getClientOriginalExtension() ?: $uploadedLogo->extension() ?: 'png');
        $storagePath = $this->logoStoragePath($tenant, $extension);
        $previousPath = is_string($files->logo_storage_path) ? trim($files->logo_storage_path) : '';

        Storage::disk('local')->put($storagePath, $uploadedLogo->get());

        if ($previousPath !== '' && $previousPath !== $storagePath && Storage::disk('local')->exists($previousPath)) {
            Storage::disk('local')->delete($previousPath);
        }

        $files->forceFill([
            'logo_storage_path' => $storagePath,
            'logo_original_filename' => $uploadedLogo->getClientOriginalName(),
            'logo_mime_type' => $uploadedLogo->getMimeType(),
            'logo_size_bytes' => $uploadedLogo->getSize(),
            'logo_uploaded_at' => now(),
        ])->save();

        $tenant->setRelation('businessProfileFiles', $files->fresh());
        [$synced, $messageSuffix] = $this->syncLiveWorkspaceIfNeeded($tenant);

        return response()->json([
            'success' => true,
            'message' => 'Business logo uploaded'.$messageSuffix,
            'business_profile_updated' => true,
            'synced' => $synced,
            'logo' => $this->logoPayload($tenant->fresh(['businessProfileFiles'])->businessProfileFiles),
        ]);
    }

    public function deleteLogo(Request $request): JsonResponse
    {
        $tenant = $this->tenantFor($request);
        $files = $tenant->businessProfileFiles;

        if (! $files instanceof BusinessProfileFiles) {
            return response()->json([
                'success' => true,
                'message' => 'Business logo removed.',
                'business_profile_updated' => true,
                'synced' => false,
                'logo' => $this->logoPayload(null),
            ]);
        }

        $storagePath = is_string($files->logo_storage_path) ? trim($files->logo_storage_path) : '';

        if ($storagePath !== '' && Storage::disk('local')->exists($storagePath)) {
            Storage::disk('local')->delete($storagePath);
        }

        $files->forceFill([
            'logo_storage_path' => null,
            'logo_original_filename' => null,
            'logo_mime_type' => null,
            'logo_size_bytes' => null,
            'logo_uploaded_at' => null,
        ])->save();

        $tenant->setRelation('businessProfileFiles', $files->fresh());
        [$synced, $messageSuffix] = $this->syncLiveWorkspaceIfNeeded($tenant);

        return response()->json([
            'success' => true,
            'message' => 'Business logo removed'.$messageSuffix,
            'business_profile_updated' => true,
            'synced' => $synced,
            'logo' => $this->logoPayload($tenant->fresh(['businessProfileFiles'])->businessProfileFiles),
        ]);
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

    private function logoStoragePath(Tenant $tenant, string $extension): string
    {
        return sprintf(
            'tenant-business-profile-assets/%s/logo.%s',
            $tenant->tenant_id,
            Str::lower($extension)
        );
    }

    /**
     * @return array{0: bool, 1: string}
     */
    private function syncLiveWorkspaceIfNeeded(Tenant $tenant): array
    {
        if ($tenant->agent_status !== 'live') {
            return [false, '.'];
        }

        try {
            $this->agentSync->goLive($tenant->fresh(['businessProfile', 'businessProfileFiles', 'server']));

            return [true, ' and the live assistant workspace has been synced.'];
        } catch (Throwable $exception) {
            return [false, ', but the live assistant sync failed: '.$exception->getMessage()];
        }
    }

    /**
     * @return array{present:bool, preview_url:?string, workspace_path:?string, mime_type:?string, original_filename:?string, size_bytes:?int, uploaded_at:?string}
     */
    private function logoPayload(?BusinessProfileFiles $files): array
    {
        $storagePath = is_string($files?->logo_storage_path) ? trim($files->logo_storage_path) : '';
        $mimeType = is_string($files?->logo_mime_type) ? trim($files->logo_mime_type) : '';
        $originalFilename = is_string($files?->logo_original_filename) ? trim($files->logo_original_filename) : '';
        $uploadedAt = $files?->logo_uploaded_at?->toIso8601String();
        $workspacePath = $storagePath !== '' ? 'business-assets/logo.'.$this->logoExtensionFromPath($storagePath) : null;
        $present = $storagePath !== '' && Storage::disk('local')->exists($storagePath);
        $previewUrl = $present
            ? route('profile.logo.show').'?v='.urlencode((string) ($files?->logo_uploaded_at?->timestamp ?? time()))
            : null;

        return [
            'present' => $present,
            'preview_url' => $previewUrl,
            'workspace_path' => $present ? $workspacePath : null,
            'mime_type' => $present && $mimeType !== '' ? $mimeType : null,
            'original_filename' => $present && $originalFilename !== '' ? $originalFilename : null,
            'size_bytes' => $present ? $files?->logo_size_bytes : null,
            'uploaded_at' => $present ? $uploadedAt : null,
        ];
    }

    private function logoExtensionFromPath(string $storagePath): string
    {
        $extension = strtolower(pathinfo($storagePath, PATHINFO_EXTENSION));

        return $extension !== '' ? $extension : 'png';
    }
}
