<x-layouts.app title="Tenant Detail">
    @php
        $channelConfig = is_array($tenant->channel_config) ? $tenant->channel_config : [];
        $canManageWorkspace = ! in_array($workspaceState, ['not_provisioned', 'missing_config'], true);
        $googleCredential = $tenant->googleCredential;
        $googleConnected = $googleCredential?->isConnected() ?? false;
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
    @endphp

    <style>
        .tenant-admin-shell {
            display: grid;
            gap: 18px;
        }

        .tenant-admin-status {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
        }

        .tenant-admin-layout {
            display: grid;
            grid-template-columns: minmax(190px, 220px) minmax(0, 1fr);
            gap: 18px;
            align-items: start;
        }

        .tenant-admin-sidebar {
            border: 1px solid color-mix(in oklch, var(--color-base-content) 10%, transparent);
            border-radius: 18px;
            background: color-mix(in oklch, var(--color-base-100) 92%, white);
            box-shadow: 0 14px 34px color-mix(in oklch, var(--color-base-content) 6%, transparent);
            display: grid;
            gap: 6px;
            padding: 8px;
            position: sticky;
            top: 18px;
        }

        .tenant-admin-tab {
            display: block;
            padding: 11px 12px;
            border-radius: 12px;
            border: 1px solid transparent;
            background: transparent;
            text-decoration: none;
            color: color-mix(in oklch, var(--color-base-content) 78%, transparent);
            transition: border-color 160ms ease, background 160ms ease, box-shadow 160ms ease, transform 160ms ease;
        }

        .tenant-admin-tab:hover,
        .tenant-admin-tab:focus {
            border-color: color-mix(in oklch, var(--color-base-content) 12%, transparent);
            background: color-mix(in oklch, var(--color-base-200) 54%, transparent);
            outline: none;
        }

        .tenant-admin-tab.active {
            border-color: color-mix(in oklch, var(--color-primary) 34%, transparent);
            background: linear-gradient(135deg, color-mix(in oklch, var(--color-primary) 14%, transparent), color-mix(in oklch, var(--color-base-100) 72%, white));
            box-shadow: 0 8px 22px color-mix(in oklch, var(--color-primary) 8%, transparent);
            transform: translateX(2px);
        }

        .tenant-admin-tab.active .tenant-admin-tab__label {
            color: color-mix(in oklch, var(--color-primary) 72%, var(--color-base-content));
        }

        .tenant-admin-tab__row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 10px;
        }

        .tenant-admin-tab__label {
            font-weight: 700;
            line-height: 1.3;
        }

        .tenant-admin-panel-stack {
            display: grid;
            gap: 18px;
        }

        @media (max-width: 920px) {
            .tenant-admin-layout {
                grid-template-columns: 1fr;
            }

            .tenant-admin-sidebar {
                position: static;
                grid-auto-flow: column;
                grid-auto-columns: max-content;
                overflow-x: auto;
                padding-bottom: 4px;
            }

            .tenant-admin-tab {
                min-width: 148px;
            }
        }
    </style>

    <div class="tenant-admin-shell" data-active-tab="{{ $activeTenantTab }}">
        <div class="topbar">
            <div>
                <span class="eyebrow">Tenant Detail</span>
                <h2>{{ $tenant->business_name }}</h2>
                <p class="type-body">Use the sidebar to move between tenant summary, workspace details, Google state, tenant skills, agent runtime behavior, and support actions.</p>
            </div>
            <div class="sync-poc-panel-actions">
                <x-ui.button :href="route('admin.tenants')" variant="secondary" size="sm" icon="arrow-left">Back to Tenants</x-ui.button>
                @if ($tenant->workspace_url)
                    <x-ui.button :href="$tenant->workspace_url" variant="secondary" size="sm" icon="external-link" icon-position="after" target="_blank" rel="noreferrer">Open Customer Workspace URL</x-ui.button>
                @endif
            </div>
        </div>

        <div class="tenant-admin-status">
            <x-ui.badge :status="$tenant->provisioning_status->value" technical>Provisioning: {{ $tenant->provisioning_status->value }}</x-ui.badge>
            <x-ui.badge :status="$tenant->agent_status ?? 'offline'" technical>Agent: {{ $tenant->agent_status ?? 'offline' }}</x-ui.badge>
            <x-ui.badge :status="$tenant->last_health_check_status ?? 'unchecked'" technical>Health: {{ $tenant->last_health_check_status ?? 'unchecked' }}</x-ui.badge>
            <x-ui.badge :status="$workspaceState" technical>Workspace: {{ str_replace('_', ' ', $workspaceState) }}</x-ui.badge>
            <x-ui.badge :status="$googleState['runtime_badge']" technical>Google: {{ $googleState['runtime_label'] }}</x-ui.badge>
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
