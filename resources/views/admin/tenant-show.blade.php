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
        .tenant-admin-status .badge {
            padding-inline: 13px;
        }

        .tenant-admin-layout {
            display: grid;
            grid-template-columns: minmax(160px, 200px) minmax(0, 1fr);
            gap: 18px;
            align-items: start;
        }

        .tenant-admin-sidebar {
            display: grid;
            gap: 10px;
            position: sticky;
            top: 18px;
        }

        .tenant-admin-tab {
            display: block;
            padding: 12px 14px;
            border-radius: 14px;
            border: 1px solid rgba(255, 255, 255, 0.08);
            background: rgba(255, 255, 255, 0.03);
            text-decoration: none;
            color: inherit;
            transition: border-color 160ms ease, background 160ms ease, box-shadow 160ms ease, transform 160ms ease;
        }

        .tenant-admin-tab.active {
            border-color: rgba(232, 108, 52, 0.4);
            background: linear-gradient(135deg, rgba(232, 108, 52, 0.14), rgba(255, 255, 255, 0.08));
            box-shadow: inset 4px 0 0 #e86c34, 0 10px 24px rgba(232, 108, 52, 0.12);
            transform: translateX(2px);
        }

        .tenant-admin-tab.active .tenant-admin-tab__label {
            color: #b44b1a;
        }

        .tenant-admin-tab.active .badge {
            border-color: rgba(232, 108, 52, 0.28);
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
                grid-auto-columns: minmax(160px, 1fr);
                overflow-x: auto;
                padding-bottom: 4px;
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
            <div style="display: flex; gap: 10px; flex-wrap: wrap;">
                <a href="{{ route('admin.tenants') }}" class="button button--secondary">Back to Tenants</a>
                @if ($tenant->workspace_url)
                    <a href="{{ $tenant->workspace_url }}" class="button button--secondary" target="_blank" rel="noreferrer">Open Customer Workspace URL</a>
                @endif
            </div>
        </div>

        <div class="tenant-admin-status">
            <span class="badge badge--technical {{ $tenant->provisioning_status->value }}">Provisioning: {{ $tenant->provisioning_status->value }}</span>
            <span class="badge badge--technical {{ $tenant->agent_status === 'live' ? 'ready' : ($tenant->agent_status === 'failed' ? 'failed' : 'pending') }}">Agent: {{ $tenant->agent_status ?? 'offline' }}</span>
            <span class="badge badge--technical {{ $tenant->last_health_check_status === 'healthy' ? 'ready' : ($tenant->last_health_check_status === 'failed' ? 'failed' : 'pending') }}">Health: {{ $tenant->last_health_check_status ?? 'unchecked' }}</span>
            <span class="badge badge--technical {{ $workspaceState === 'running' ? 'ready' : ($workspaceState === 'stopped' ? 'pending' : 'failed') }}">Workspace: {{ str_replace('_', ' ', $workspaceState) }}</span>
            <span class="badge badge--technical {{ $googleState['runtime_badge'] }}">Google: {{ $googleState['runtime_label'] }}</span>
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
                                <span class="badge {{ $tab['badge_class'] }}">{{ $tab['badge_label'] }}</span>
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
