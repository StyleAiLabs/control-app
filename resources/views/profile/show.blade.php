<x-layouts.app title="Business Profile — Sync360">
    @php
        $assistantStatus = strtolower((string) ($tenant->agent_status ?? 'offline'));
        $onboardingComplete = $tenant->onboarding_status === 'complete';
        $profileCompleteness = (int) ($profile?->profile_completeness ?? 0);
        $lastSynced = $profile?->last_synced_to_agent?->diffForHumans() ?: 'Not synced yet';
        $lastGenerated = $tenant->businessProfileFiles?->generated_at?->diffForHumans() ?: 'Not generated yet';
        $lastLiveSync = $tenant->businessProfileFiles?->synced_at?->diffForHumans() ?: 'Not pushed live yet';
        $googleHealth = $dependencyHealth['google_workspace'] ?? [];
        $inboxHealth = $dependencyHealth['inbox_monitor'] ?? [];
        $dependencyAlert = $dependencyHealth['customer_alerts'][0] ?? null;
        $dependencyCta = $dependencyHealth['primary_cta'] ?? null;
        $logoPreviewUrl = $logo['preview_url'] ?? null;
        $logoOriginalFilename = $logo['original_filename'] ?? null;
        $logoUploadedAt = $logo['uploaded_at'] ?? null ? \Illuminate\Support\Carbon::parse($logo['uploaded_at'])->diffForHumans() : null;
        $googleBadgeStatus = match (true) {
            ($googleHealth['requires_reconnect'] ?? false) => 'error',
            in_array($googleHealth['health_status'] ?? null, ['expiring_soon', 'degraded'], true) => 'warning',
            ($googleHealth['connected'] ?? false) => 'success',
            default => 'neutral',
        };
        $inboxBadgeStatus = match ($inboxHealth['health_status'] ?? null) {
            'healthy' => 'success',
            'degraded' => 'warning',
            'down' => 'error',
            default => 'neutral',
        };
        $workspaceToolsStatus = match (true) {
            ($googleHealth['requires_reconnect'] ?? false) => 'error',
            in_array($googleHealth['health_status'] ?? null, ['expiring_soon', 'degraded'], true),
            in_array($inboxHealth['health_status'] ?? null, ['degraded', 'down'], true) => 'warning',
            ($googleHealth['connected'] ?? false) || ($inboxHealth['enabled'] ?? false) => 'success',
            default => 'neutral',
        };
        $workspaceToolsValue = $dependencyAlert['title']
            ?? (($googleHealth['connected'] ?? false) ? ($googleHealth['health_label'] ?? 'Working') : 'Not connected');
        $workspaceToolsNote = $dependencyAlert['message']
            ?? (($googleHealth['connected'] ?? false)
                ? (($googleHealth['health_note'] ?? 'Google Workspace is connected and available.'))
                : 'Connect Google Workspace when you want inbox monitoring and live tools.');

        $syncSummary = match (true) {
            $tenant->agent_status === 'live' => [
                'title' => 'Saving updates will sync the live assistant automatically.',
                'note' => 'Keep services, hours, contact details, and after-hours guidance current so customer replies stay accurate.',
                'tone' => 'live',
            ],
            $canSync => [
                'title' => 'Your setup is complete. Sync when you are ready.',
                'note' => 'Use a manual sync when you want to push profile changes into the assistant without editing the full form.',
                'tone' => 'ready',
            ],
            default => [
                'title' => 'Finish guided setup before you sync the assistant.',
                'note' => 'Tone, modules, channel setup, and workspace readiness still need to be completed first.',
                'tone' => 'pending',
            ],
        };

        $healthRail = [
            [
                'label' => 'Assistant',
                'status' => $assistantStatus,
                'value' => ucfirst($tenant->agent_status ?? 'offline'),
                'note' => $tenant->agent_status === 'live'
                    ? 'Customer-facing replies are active.'
                    : 'The assistant is not live yet.',
            ],
            [
                'label' => 'Onboarding',
                'status' => $onboardingComplete ? 'success' : 'pending',
                'value' => $onboardingComplete ? 'Complete' : 'In progress',
                'note' => $onboardingComplete
                    ? 'Guided setup is complete.'
                    : 'Finish setup before relying on live sync.',
            ],
            [
                'label' => 'Profile',
                'status' => $profileCompleteness >= 80 ? 'success' : ($profileCompleteness >= 50 ? 'warning' : 'neutral'),
                'value' => $profileCompleteness.'% complete',
                'note' => 'Services, hours, and contact details matter most.',
            ],
            [
                'label' => 'Workspace tools',
                'status' => $workspaceToolsStatus,
                'value' => $workspaceToolsValue,
                'note' => $workspaceToolsNote,
            ],
        ];
    @endphp

    <div class="customer-profile-shell">
        <header class="customer-profile-hero">
            <div class="customer-profile-hero__copy">
                <span class="eyebrow">Business Profile</span>
                <h2>Keep your assistant aligned with your business.</h2>
                <p>Update the details your digital employee depends on most, then sync them into the live workspace when needed.</p>
            </div>

            <div class="customer-profile-hero__actions">
                <x-ui.button :href="route('dashboard')" variant="secondary" icon="arrow-left">
                    Back to Dashboard
                </x-ui.button>

                @if (filled($tenant->workspace_url))
                    <x-ui.button :href="$tenant->workspace_url" variant="secondary" icon="external-link" icon-position="after" rel="noreferrer">
                        Open Sync360 Workspace
                    </x-ui.button>
                @endif

                @if ($canSync)
                    <form method="POST" action="{{ route('profile.sync-agent') }}" id="manual-sync-form">
                        @csrf
                        <x-ui.button type="submit" id="manual-sync-button" icon="refresh-cw">
                            Sync Assistant Now
                        </x-ui.button>
                    </form>
                @endif
            </div>
        </header>

        <x-ui.health-rail :items="$healthRail" class="customer-profile-rail" />

        <section class="customer-profile-layout">
            <x-ui.panel
                variant="subtle"
                class="customer-profile-main"
                title="Business Details"
                description="These details shape how your assistant introduces the business, answers questions, and handles customer expectations."
            >
                <form method="POST" action="{{ route('profile.update') }}" id="business-profile-form" class="customer-profile-form">
                    @csrf
                    @method('PATCH')

                    <section class="customer-profile-section">
                        <div class="customer-profile-section__header">
                            <h3 class="customer-profile-section__title">Identity and positioning</h3>
                            <p class="customer-profile-section__note">Keep the business basics clear so the assistant introduces the company correctly every time.</p>
                        </div>

                        <div class="field-single">
                            <div class="note" id="profile-logo-card" style="padding:14px 16px;">
                                <div style="display:flex; flex-wrap:wrap; gap:14px; align-items:center;">
                                    <div style="flex:0 0 84px;">
                                        <div style="width:84px; height:84px; border:1px solid rgba(28, 30, 38, 0.12); border-radius:16px; background:#fff; display:flex; align-items:center; justify-content:center; overflow:hidden;">
                                            <img
                                                id="profile-logo-preview"
                                                @if ($logoPreviewUrl) src="{{ $logoPreviewUrl }}" @endif
                                                alt="Business logo preview"
                                                style="max-width:100%; max-height:100%; object-fit:contain; display:{{ $logo['present'] ? 'block' : 'none' }};"
                                            >
                                            <span
                                                id="profile-logo-placeholder"
                                                class="hint"
                                                style="padding:12px; text-align:center; font-size:0.88rem; line-height:1.35; display:{{ $logo['present'] ? 'none' : 'block' }};"
                                            >
                                                No logo yet
                                            </span>
                                        </div>
                                    </div>

                                    <div style="flex:1 1 320px; min-width:240px;">
                                        <div style="display:flex; flex-wrap:wrap; gap:10px 14px; align-items:center; justify-content:space-between;">
                                            <div>
                                                <strong>Business logo</strong>
                                                <p class="hint" id="profile-logo-meta" style="margin-top:4px;">
                                                    @if ($logo['present'])
                                                        {{ $logoOriginalFilename }}@if ($logoUploadedAt), uploaded {{ $logoUploadedAt }}@endif
                                                    @else
                                                        Optional. Used in customer-facing documents and workspace modules.
                                                    @endif
                                                </p>
                                            </div>

                                            <div style="display:flex; flex-wrap:wrap; gap:8px;">
                                                <input type="file" id="profile-logo-input" accept=".png,.jpg,.jpeg,.webp,image/png,image/jpeg,image/webp" style="display:none;">
                                                <x-ui.button type="button" id="profile-logo-upload-button" variant="secondary">
                                                    {{ $logo['present'] ? 'Replace Logo' : 'Upload Logo' }}
                                                </x-ui.button>
                                                <x-ui.button type="button" id="profile-logo-remove-button" variant="secondary" style="{{ $logo['present'] ? '' : 'display:none;' }}">
                                                    Remove
                                                </x-ui.button>
                                            </div>
                                        </div>

                                        <div class="hint" style="margin-top:8px;">
                                            PNG, JPG, JPEG, WEBP. Maximum size: 2 MB.
                                        </div>
                                    </div>
                                </div>

                                <div class="note" id="profile-logo-status" aria-live="polite" style="margin-top:12px;">
                                    @if ($logo['present'])
                                        The current logo is ready for workspace sync and custom skill use.
                                    @else
                                        Upload a logo any time. Your text profile fields still save separately below.
                                    @endif
                                </div>
                            </div>
                        </div>

                        <div class="field-grid">
                            <label>
                                Business Name
                                <input type="text" name="business_name" value="{{ old('business_name', $profile?->business_name ?: $tenant->business_name) }}">
                            </label>
                            <label>
                                Trading Name <span class="hint">(optional)</span>
                                <input type="text" name="trading_name" value="{{ old('trading_name', $profile?->trading_name) }}">
                            </label>
                        </div>

                        <div class="field-grid">
                            <label>
                                Industry
                                <input type="text" name="industry" value="{{ old('industry', $profile?->industry ?: $tenant->industry) }}">
                            </label>
                            <label>
                                Website <span class="hint">(optional)</span>
                                <input type="url" name="website_url" value="{{ old('website_url', $profile?->website_url) }}">
                            </label>
                        </div>

                        <div class="field-single">
                            <label>
                                What your business does
                                <textarea name="description" placeholder="Describe the business, who it serves, and the types of jobs or requests it handles.">{{ old('description', $profile?->description) }}</textarea>
                            </label>
                        </div>

                        <div class="field-grid">
                            <label>
                                Tagline <span class="hint">(optional)</span>
                                <input type="text" name="tagline" value="{{ old('tagline', $profile?->tagline) }}">
                            </label>
                            <label>
                                Primary Language <span class="hint">(optional)</span>
                                <input type="text" name="primary_language" value="{{ old('primary_language', $profile?->primary_language) }}">
                            </label>
                        </div>
                    </section>

                    <section class="customer-profile-section">
                        <div class="customer-profile-section__header">
                            <h3 class="customer-profile-section__title">Services and operating hours</h3>
                            <p class="customer-profile-section__note">These fields drive the day-to-day accuracy of customer replies more than anything else on this page.</p>
                        </div>

                        <div class="field-single">
                            <label>
                                Services
                                <textarea name="services_text" placeholder="Add one service per line">{{ old('services_text', is_array($profile?->services) ? implode("\n", $profile->services) : '') }}</textarea>
                            </label>
                        </div>

                        <div class="field-single">
                            <label>
                                Business Hours <span class="hint">(one line per day or rule)</span>
                                <textarea name="business_hours_text" placeholder="Mon-Fri: 8am - 5pm&#10;Sat: 9am - 1pm&#10;Sun: Closed">{{ old('business_hours_text', is_array($profile?->business_hours) ? implode("\n", $profile->business_hours) : '') }}</textarea>
                            </label>
                        </div>

                        <div class="field-single">
                            <label>
                                After-Hours Policy <span class="hint">(optional)</span>
                                <textarea name="after_hours_policy" placeholder="Explain what the assistant should say after hours.">{{ old('after_hours_policy', $profile?->after_hours_policy) }}</textarea>
                            </label>
                        </div>

                        <div class="field-single">
                            <label>
                                FAQs <span class="hint">(optional, one per line)</span>
                                <textarea name="faqs_text" placeholder="Do you offer emergency callouts?&#10;What areas do you service?">{{ old('faqs_text', is_array($profile?->faqs) ? implode("\n", $profile->faqs) : '') }}</textarea>
                            </label>
                        </div>
                    </section>

                    <section class="customer-profile-section">
                        <div class="customer-profile-section__header">
                            <h3 class="customer-profile-section__title">Contact and location</h3>
                            <p class="customer-profile-section__note">Give the assistant the details customers actually need when they are deciding whether to contact you.</p>
                        </div>

                        <div class="field-grid">
                            <label>
                                Contact Email
                                <input type="email" name="contact_email" value="{{ old('contact_email', $profile?->contact_email) }}">
                            </label>
                            <label>
                                Contact Phone <span class="hint">(optional)</span>
                                <input type="text" name="contact_phone" value="{{ old('contact_phone', $profile?->contact_phone) }}">
                            </label>
                        </div>

                        <div class="field-grid">
                            <label>
                                Mobile <span class="hint">(optional)</span>
                                <input type="text" name="contact_mobile" value="{{ old('contact_mobile', $profile?->contact_mobile) }}">
                            </label>
                            <label>
                                Physical Address <span class="hint">(optional)</span>
                                <input type="text" name="physical_address" value="{{ old('physical_address', $profile?->physical_address) }}">
                            </label>
                        </div>

                        <div class="field-grid">
                            <label>
                                Postal Address <span class="hint">(optional)</span>
                                <input type="text" name="postal_address" value="{{ old('postal_address', $profile?->postal_address) }}">
                            </label>
                            <label>
                                City <span class="hint">(optional)</span>
                                <input type="text" name="city" value="{{ old('city', $profile?->city) }}">
                            </label>
                        </div>

                        <div class="field-grid">
                            <label>
                                Country <span class="hint">(optional)</span>
                                <input type="text" name="country" value="{{ old('country', $profile?->country) }}">
                            </label>
                            <label>
                                Target Customers <span class="hint">(optional)</span>
                                <input type="text" name="target_customers" value="{{ old('target_customers', $profile?->target_customers) }}">
                            </label>
                        </div>
                    </section>

                    <section class="customer-profile-section">
                        <div class="customer-profile-section__header">
                            <h3 class="customer-profile-section__title">Ownership and commercial notes</h3>
                            <p class="customer-profile-section__note">Use these for owner context, tax references, and any pricing guardrails the assistant should respect.</p>
                        </div>

                        <div class="field-grid">
                            <label>
                                Owner Name <span class="hint">(optional)</span>
                                <input type="text" name="owner_name" value="{{ old('owner_name', $profile?->owner_name) }}">
                            </label>
                            <label>
                                Owner Email <span class="hint">(optional)</span>
                                <input type="email" name="owner_email" value="{{ old('owner_email', $profile?->owner_email) }}">
                            </label>
                        </div>

                        <div class="field-grid">
                            <label>
                                Owner Phone <span class="hint">(optional)</span>
                                <input type="text" name="owner_phone" value="{{ old('owner_phone', $profile?->owner_phone) }}">
                            </label>
                            <label>
                                GST / Tax Number <span class="hint">(optional)</span>
                                <input type="text" name="tax_number" value="{{ old('tax_number', $profile?->tax_number) }}">
                            </label>
                        </div>

                        <div class="field-grid">
                            <label>
                                Company Registration <span class="hint">(optional)</span>
                                <input type="text" name="company_reg_number" value="{{ old('company_reg_number', $profile?->company_reg_number) }}">
                            </label>
                            <label>
                                Pricing Notes <span class="hint">(optional)</span>
                                <input type="text" name="pricing_notes" value="{{ old('pricing_notes', $profile?->pricing_notes) }}">
                            </label>
                        </div>
                    </section>

                    <div class="customer-profile-form-footer">
                        <div class="customer-profile-form-footer__copy">
                            <strong>Save Business Profile</strong>
                            <span>
                                @if ($tenant->agent_status === 'live')
                                    Saving this form will also sync the live assistant automatically.
                                @else
                                    Save your details here first, then sync the assistant when the rest of setup is complete.
                                @endif
                            </span>
                        </div>
                        <x-ui.button type="submit" id="save-profile-button" icon="save">
                            Save Business Profile
                        </x-ui.button>
                    </div>
                </form>
            </x-ui.panel>

            <aside class="customer-profile-sidebar">
                @if ($dependencyAlert)
                    <x-ui.panel
                        variant="subtle"
                        class="customer-profile-alert"
                        title="{{ $dependencyAlert['title'] }}"
                        description="{{ $dependencyAlert['message'] }}"
                    >
                        @if (filled($googleHealth['last_error'] ?? null))
                            <div class="note error">
                                {{ \Illuminate\Support\Str::limit((string) $googleHealth['last_error'], 180) }}
                            </div>
                        @endif

                        @if ($dependencyCta)
                            <x-ui.button :href="$dependencyCta['href']" variant="secondary">
                                {{ $dependencyCta['text'] }}
                            </x-ui.button>
                        @endif
                    </x-ui.panel>
                @endif

                <x-ui.panel
                    variant="subtle"
                    title="Sync Status"
                    description="This tells you how ready the profile is for customer-facing assistant responses."
                >
                    <div class="customer-profile-status-list">
                        <div class="customer-profile-status-row">
                            <span class="customer-profile-status-row__label">Assistant</span>
                            <x-ui.badge :status="$assistantStatus">{{ ucfirst($tenant->agent_status ?? 'offline') }}</x-ui.badge>
                        </div>
                        <div class="customer-profile-status-row">
                            <span class="customer-profile-status-row__label">Onboarding</span>
                            <x-ui.badge :status="$onboardingComplete ? 'success' : 'pending'">
                                {{ $onboardingComplete ? 'Complete' : 'In progress' }}
                            </x-ui.badge>
                        </div>
                        <div class="customer-profile-status-row">
                            <span class="customer-profile-status-row__label">Profile completeness</span>
                            <span class="customer-profile-status-row__value">{{ $profileCompleteness }}%</span>
                        </div>
                        <div class="customer-profile-status-row">
                            <span class="customer-profile-status-row__label">Last assistant sync</span>
                            <span class="customer-profile-status-row__value">{{ $lastSynced }}</span>
                        </div>
                        <div class="customer-profile-status-row">
                            <span class="customer-profile-status-row__label">Last file generation</span>
                            <span class="customer-profile-status-row__value">{{ $lastGenerated }}</span>
                        </div>
                        <div class="customer-profile-status-row">
                            <span class="customer-profile-status-row__label">Latest live sync</span>
                            <span class="customer-profile-status-row__value">{{ $lastLiveSync }}</span>
                        </div>
                        <div class="customer-profile-status-row">
                            <span class="customer-profile-status-row__label">Google Workspace</span>
                            <x-ui.badge :status="$googleBadgeStatus">
                                {{ $googleHealth['health_label'] ?? 'Not connected' }}
                            </x-ui.badge>
                        </div>
                        <div class="customer-profile-status-row">
                            <span class="customer-profile-status-row__label">Inbox monitoring</span>
                            <x-ui.badge :status="$inboxBadgeStatus">
                                {{ $inboxHealth['health_label'] ?? 'Not enabled' }}
                            </x-ui.badge>
                        </div>
                    </div>

                    <div class="customer-profile-sync-summary">
                        <div class="customer-profile-sync-summary__title">
                            <x-ui.status-icon :status="$syncSummary['tone']" :label="$syncSummary['title']" />
                            <strong>{{ $syncSummary['title'] }}</strong>
                        </div>
                        <p class="customer-profile-sync-summary__note">{{ $syncSummary['note'] }}</p>
                    </div>

                    <div class="note" id="sync-progress-idle">
                        We’ll show assistant sync progress here while a live sync is running.
                    </div>

                    @if (session('status'))
                        <div class="note" id="sync-complete-note">
                            {{ session('status') }}
                        </div>
                    @endif

                    <div class="note" style="display: none;" id="sync-progress-note" aria-live="polite">
                        Preparing the latest assistant files…
                    </div>
                </x-ui.panel>

                <x-ui.panel
                    variant="subtle"
                    title="Keep This Fresh"
                    description="These are the details customers feel first when the assistant is answering on your behalf."
                >
                    <ul class="customer-profile-helper-list">
                        <li>Services and what kinds of jobs you actually take on</li>
                        <li>Business hours and after-hours guidance</li>
                        <li>Primary contact details and the best callback path</li>
                        <li>Tax, pricing, and ownership notes that affect customer expectations</li>
                    </ul>

                    <div class="customer-profile-sidebar__actions">
                        <x-ui.button :href="route('onboarding.show')" variant="secondary">
                            Back to Guided Setup
                        </x-ui.button>
                        @if (filled($tenant->workspace_url))
                            <x-ui.button :href="$tenant->workspace_url" variant="secondary" icon="external-link" icon-position="after" rel="noreferrer">
                                Open Workspace
                            </x-ui.button>
                        @endif
                    </div>
                </x-ui.panel>
            </aside>
        </section>
    </div>

    <script>
        const manualSyncForm = document.getElementById('manual-sync-form');
        const manualSyncButton = document.getElementById('manual-sync-button');
        const profileForm = document.getElementById('business-profile-form');
        const saveProfileButton = document.getElementById('save-profile-button');
        const syncProgressIdle = document.getElementById('sync-progress-idle');
        const syncCompleteNote = document.getElementById('sync-complete-note');
        const syncProgressNote = document.getElementById('sync-progress-note');
        const profileLogoInput = document.getElementById('profile-logo-input');
        const profileLogoUploadButton = document.getElementById('profile-logo-upload-button');
        const profileLogoRemoveButton = document.getElementById('profile-logo-remove-button');
        const profileLogoPreview = document.getElementById('profile-logo-preview');
        const profileLogoPlaceholder = document.getElementById('profile-logo-placeholder');
        const profileLogoStatus = document.getElementById('profile-logo-status');
        const profileLogoMeta = document.getElementById('profile-logo-meta');
        const profileLogoEndpoint = @json(route('profile.logo.upload'));
        const profileLogoDeleteEndpoint = @json(route('profile.logo.delete'));
        const csrfToken = @json(csrf_token());
        let profileLogoState = @json($logo);

        function beginSyncFeedback(button, labels, note) {
            if (!button || !syncProgressNote) {
                return;
            }

            button.disabled = true;
            button.dataset.originalLabel = button.dataset.originalLabel || button.textContent;
            button.textContent = labels[0];

            if (syncProgressIdle) {
                syncProgressIdle.style.display = 'none';
            }

            if (syncCompleteNote) {
                syncCompleteNote.style.display = 'none';
            }

            syncProgressNote.style.display = 'block';
            syncProgressNote.textContent = note;

            let labelIndex = 0;
            window.setInterval(() => {
                labelIndex = Math.min(labelIndex + 1, labels.length - 1);
                button.textContent = labels[labelIndex];
            }, 1400);
        }

        manualSyncForm?.addEventListener('submit', () => {
            beginSyncFeedback(
                manualSyncButton,
                ['Syncing Assistant…', 'Pushing Workspace Files…', 'Verifying Workspace Access…'],
                'Sync in progress. We are regenerating the latest assistant instructions, syncing the live workspace files, and verifying any connected Google Workspace access.'
            );
        });

        profileForm?.addEventListener('submit', () => {
            const liveAgent = @json($tenant->agent_status === 'live');

            if (liveAgent) {
                beginSyncFeedback(
                    saveProfileButton,
                    ['Saving Profile…', 'Regenerating Assistant Files…', 'Syncing Live Assistant…', 'Verifying Workspace Access…'],
                    'Saving your business profile and syncing the live assistant. We will notify you here after the sync completes.'
                );

                return;
            }

            if (!saveProfileButton) {
                return;
            }

            saveProfileButton.disabled = true;
            saveProfileButton.dataset.originalLabel = saveProfileButton.dataset.originalLabel || saveProfileButton.textContent;
            saveProfileButton.textContent = 'Saving Profile…';
        });

        function setLogoBusy(isBusy, uploadLabel = null) {
            if (profileLogoUploadButton) {
                profileLogoUploadButton.disabled = isBusy;
                if (uploadLabel) {
                    profileLogoUploadButton.textContent = uploadLabel;
                } else {
                    profileLogoUploadButton.textContent = profileLogoState.present ? 'Replace Logo' : 'Upload Logo';
                }
            }

            if (profileLogoRemoveButton) {
                profileLogoRemoveButton.disabled = isBusy;
            }
        }

        function renderLogoState(logo, statusMessage = null) {
            profileLogoState = logo || { present: false };

            if (profileLogoPreview) {
                if (profileLogoState.present && profileLogoState.preview_url) {
                    profileLogoPreview.src = profileLogoState.preview_url;
                    profileLogoPreview.style.display = 'block';
                } else {
                    profileLogoPreview.removeAttribute('src');
                    profileLogoPreview.style.display = 'none';
                }
            }

            if (profileLogoPlaceholder) {
                profileLogoPlaceholder.style.display = profileLogoState.present ? 'none' : 'block';
            }

            if (profileLogoRemoveButton) {
                profileLogoRemoveButton.style.display = profileLogoState.present ? '' : 'none';
            }

            if (profileLogoMeta) {
                if (profileLogoState.present && profileLogoState.original_filename) {
                    const uploadedAt = profileLogoState.uploaded_at
                        ? new Date(profileLogoState.uploaded_at).toLocaleString()
                        : null;
                    profileLogoMeta.textContent = uploadedAt
                        ? `${profileLogoState.original_filename}, uploaded ${uploadedAt}`
                        : profileLogoState.original_filename;
                } else {
                    profileLogoMeta.textContent = 'Optional. Used in customer-facing documents and workspace modules.';
                }
            }

            if (profileLogoStatus && statusMessage) {
                profileLogoStatus.textContent = statusMessage;
            }

            setLogoBusy(false);
        }

        async function uploadProfileLogo(file) {
            if (!file) {
                return;
            }

            setLogoBusy(true, 'Uploading Logo…');

            if (profileLogoStatus) {
                profileLogoStatus.textContent = 'Uploading your logo and preparing the shared business profile contract…';
            }

            const formData = new FormData();
            formData.append('logo', file);

            try {
                const response = await fetch(profileLogoEndpoint, {
                    method: 'POST',
                    headers: {
                        'Accept': 'application/json',
                        'X-CSRF-TOKEN': csrfToken,
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                    body: formData,
                });

                const payload = await response.json();

                if (!response.ok) {
                    const validationMessage = payload?.errors?.logo?.[0] || payload?.message || 'Logo upload failed.';
                    throw new Error(validationMessage);
                }

                renderLogoState(payload.logo, payload.message);
            } catch (error) {
                setLogoBusy(false);
                if (profileLogoStatus) {
                    profileLogoStatus.textContent = error?.message || 'Logo upload failed.';
                }
            } finally {
                if (profileLogoInput) {
                    profileLogoInput.value = '';
                }
            }
        }

        async function removeProfileLogo() {
            setLogoBusy(true, 'Updating Logo…');

            if (profileLogoStatus) {
                profileLogoStatus.textContent = 'Removing your logo and refreshing the shared business profile contract…';
            }

            try {
                const response = await fetch(profileLogoDeleteEndpoint, {
                    method: 'DELETE',
                    headers: {
                        'Accept': 'application/json',
                        'X-CSRF-TOKEN': csrfToken,
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                });

                const payload = await response.json();

                if (!response.ok) {
                    throw new Error(payload?.message || 'Logo removal failed.');
                }

                renderLogoState(payload.logo, payload.message);
            } catch (error) {
                setLogoBusy(false);
                if (profileLogoStatus) {
                    profileLogoStatus.textContent = error?.message || 'Logo removal failed.';
                }
            }
        }

        profileLogoUploadButton?.addEventListener('click', () => {
            profileLogoInput?.click();
        });

        profileLogoInput?.addEventListener('change', (event) => {
            const file = event.target?.files?.[0];
            uploadProfileLogo(file);
        });

        profileLogoRemoveButton?.addEventListener('click', () => {
            removeProfileLogo();
        });
    </script>
</x-layouts.app>
