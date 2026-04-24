<x-layouts.app title="Tenant Detail">
    @php
        $channelConfig = is_array($tenant->channel_config) ? $tenant->channel_config : [];
        $canManageWorkspace = ! in_array($workspaceState, ['not_provisioned', 'missing_config'], true);
        $googleCredential = $tenant->googleCredential;
        $googleConnected = $googleCredential?->isConnected() ?? false;
        $agentSummaryStatus = match ($tenant->agent_status) {
            'live' => 'live',
            'failed' => 'failed',
            default => 'offline',
        };
        $healthSummaryStatus = match ($tenant->last_health_check_status) {
            'healthy' => 'healthy',
            'failed' => 'failed',
            default => 'unchecked',
        };
        $workspaceSummaryLabel = ucfirst(str_replace('_', ' ', $workspaceState));
        $trialSummaryStatus = $tenant->isTrialExpired()
            ? 'expired'
            : match ($tenant->trialUrgency()) {
                'critical' => 'failed',
                'warning' => 'warning',
                default => 'ready',
            };
        $trialSummaryLabel = $tenant->isTrialExpired()
            ? 'Expired'
            : $tenant->trialDaysLeft().' days left';
        $agentCustomization = $tenant->agentCustomization;
        $agentCustomizationAvailable = $agentCustomizationAvailable ?? true;
        $tenantSkillsAvailable = $tenantSkillsAvailable ?? false;
        $runtimeCustomizationAvailable = $runtimeCustomizationAvailable ?? false;
        $promptOverrides = is_array($agentCustomization?->prompt_overrides_json) ? $agentCustomization->prompt_overrides_json : [];
        $assignedSkillKeys = $tenantSkillsAvailable
            ? $tenant->skillAssignments
                ->where('is_enabled', true)
                ->pluck('skill_key')
                ->values()
                ->all()
            : [];
        $agentDefaults = is_array($agentCustomization?->agent_defaults_json) ? $agentCustomization->agent_defaults_json : [];
        $currentCustomizationPreview = is_array($currentCustomizationPreview ?? null) ? $currentCustomizationPreview : [];
        $activeTenantTab = $activeTenantTab ?? 'overview';
        $tenantSkillsStatus = is_array($tenantSkillsStatus ?? null) ? $tenantSkillsStatus : [
            'label' => 'setup needed',
            'class' => 'failed',
            'summary_label' => null,
            'summary_class' => null,
        ];
        $googleState = $googleState ?? [
            'connection_label' => 'Pending',
            'connection_badge' => 'pending',
            'runtime_label' => 'Pending',
            'runtime_badge' => 'pending',
            'google_email' => null,
            'connected_at' => null,
            'disconnected_at' => null,
            'last_synced_at' => null,
            'sync_job_status' => null,
            'sync_job_badge' => 'pending',
            'sync_job_started_at' => null,
            'sync_job_completed_at' => null,
            'sync_job_error' => null,
            'last_error' => null,
            'can_queue_sync' => false,
            'has_active_sync_job' => false,
        ];

        $tabs = [
            'overview' => [
                'label' => 'Overview',
                'badge_label' => null,
                'badge_class' => null,
            ],
            'workspace' => [
                'label' => 'Workspace',
                'badge_label' => null,
                'badge_class' => null,
            ],
            'google' => [
                'label' => 'Google',
                'badge_label' => $googleState['connection_label'],
                'badge_class' => $googleState['connection_badge'],
            ],
            'skills' => [
                'label' => 'Skills',
                'badge_label' => $tenantSkillsStatus['label'],
                'badge_class' => $tenantSkillsStatus['class'],
            ],
            'analytics' => [
                'label' => 'Analytics',
                'badge_label' => null,
                'badge_class' => null,
            ],
            'agent-runtime' => [
                'label' => 'Agent Runtime',
                'badge_label' => null,
                'badge_class' => null,
            ],
            'support' => [
                'label' => 'Support',
                'badge_label' => null,
                'badge_class' => null,
            ],
        ];
        $tenantStatusCards = [
            [
                'label' => 'Provisioning',
                'status' => $tenant->provisioning_status->value,
                'value' => str_replace('_', ' ', $tenant->provisioning_status->value),
            ],
            [
                'label' => 'Agent',
                'status' => $agentSummaryStatus,
                'value' => $tenant->agent_status ?? 'offline',
            ],
            [
                'label' => 'Health',
                'status' => $healthSummaryStatus,
                'value' => $tenant->last_health_check_status ?? 'unchecked',
            ],
            [
                'label' => 'Workspace',
                'status' => $workspaceState,
                'value' => str_replace('_', ' ', $workspaceState),
            ],
            [
                'label' => 'Google',
                'status' => $googleState['runtime_badge'],
                'value' => $googleConnected ? ($googleState['runtime_label'] ?? 'Pending') : ($googleState['connection_label'] ?? 'Pending'),
                'note' => $googleState['google_email'] ?? 'No Google account saved',
            ],
            [
                'label' => 'Trial',
                'status' => $trialSummaryStatus,
                'value' => $trialSummaryLabel,
            ],
        ];
    @endphp

    <div class="tenant-admin-shell" data-active-tab="{{ $activeTenantTab }}">
        <div class="topbar">
            <div>
                <span class="eyebrow">Tenant Detail</span>
                <h2>{{ $tenant->business_name }}</h2>
                <p class="type-body">Overview holds the shared tenant summary. Use the other tabs for workspace placement, Google repair, skills, runtime behavior, and support actions.</p>
            </div>
            <div class="sync-poc-panel-actions">
                <x-ui.button :href="route('admin.tenants')" variant="secondary" size="sm" icon="arrow-left">Back to Tenants</x-ui.button>
                @if ($tenant->workspace_url)
                    <x-ui.button :href="$tenant->workspace_url" variant="secondary" size="sm" icon="external-link" icon-position="after" target="_blank" rel="noreferrer">Open Customer Workspace URL</x-ui.button>
                @endif
            </div>
        </div>

        <div class="sync-poc-status-strip tenant-admin-status-strip" aria-label="Tenant state summary">
            @foreach ($tenantStatusCards as $card)
                <section class="sync-poc-status-card sync-poc-status-card--compact">
                    <div class="sync-poc-status-card__header">
                        <x-ui.status-icon :status="$card['status']" :label="$card['label'].' '.$card['value']" />
                        <div class="sync-poc-status-card__body">
                            <span class="sync-poc-status-card__label">{{ $card['label'] }}</span>
                            <span class="sync-poc-status-card__value">{{ $card['value'] }}</span>
                        </div>
                    </div>
                    @if (! empty($card['note']))
                        <span class="sync-poc-status-card__note sync-poc-truncate" title="{{ $card['note'] }}">{{ $card['note'] }}</span>
                    @endif
                </section>
            @endforeach
        </div>

        <div class="tenant-admin-layout">
            <aside class="tenant-admin-sidebar">
                @foreach ($tabs as $tabKey => $tab)
                    <a
                        href="{{ route('admin.tenants.show', ['tenant' => $tenant, 'tab' => $tabKey]) }}"
                        class="tenant-admin-tab {{ $activeTenantTab === $tabKey ? 'active' : '' }}"
                        aria-current="{{ $activeTenantTab === $tabKey ? 'page' : 'false' }}"
                    >
                        <div class="tenant-admin-tab__row">
                            <span class="tenant-admin-tab__label">{{ $tab['label'] }}</span>
                            @if ($tab['badge_label'])
                                <x-ui.status-icon :status="$tab['badge_class']" :label="$tab['label'].' status: '.$tab['badge_class']" />
                            @endif
                        </div>
                    </a>
                @endforeach
            </aside>

            <div class="tenant-admin-panel-stack">
                @switch($activeTenantTab)
                    @case('workspace')
                        @include('admin.tenants.partials.show-workspace')
                        @break

                    @case('google')
                        @include('admin.tenants.partials.show-google')
                        @break

                    @case('skills')
                        @include('admin.tenants.partials.show-skills')
                        @break

                    @case('analytics')
                        @include('admin.tenants.partials.show-analytics')
                        @break

                    @case('agent-runtime')
                        @include('admin.tenants.partials.show-agent-runtime')
                        @break

                    @case('support')
                        @include('admin.tenants.partials.show-support')
                        @break

                    @default
                        @include('admin.tenants.partials.show-overview')
                @endswitch
            </div>
        </div>
    </div>

    <script>
        (() => {
            const input = document.querySelector('[data-delete-confirmation]');
            const button = document.querySelector('[data-delete-submit]');
            if (!input || !button) return;

            const expected = input.dataset.expectedSlug ?? '';
            const sync = () => {
                button.disabled = input.value.trim() !== expected;
            };

            input.addEventListener('input', sync);
            sync();
        })();

        (() => {
            const button = document.querySelector('[data-preview-customization]');
            const preview = document.querySelector('[data-customization-preview]');
            const form = button?.closest('form');
            const skillIdsInput = document.querySelector('[data-skill-ids-input]');

            if (!button || !preview || !form) return;

            const normaliseSkillIds = () => {
                if (!skillIdsInput) return;
                const raw = skillIdsInput.value
                    .split(',')
                    .map((value) => value.trim())
                    .filter(Boolean);
                skillIdsInput.value = raw.join(', ');
            };

            button.addEventListener('click', async () => {
                normaliseSkillIds();
                preview.textContent = 'Loading preview...';

                const formData = new FormData(form);
                const response = await fetch(@json(route('admin.tenants.agent-customization.preview', $tenant)), {
                    method: 'POST',
                    headers: {
                        'X-CSRF-TOKEN': @json(csrf_token()),
                        'Accept': 'application/json',
                    },
                    body: formData,
                });

                const payload = await response.json();

                if (!response.ok) {
                    preview.textContent = JSON.stringify(payload, null, 2);
                    return;
                }

                preview.textContent = JSON.stringify(payload, null, 2);
            });
        })();
    </script>
</x-layouts.app>
