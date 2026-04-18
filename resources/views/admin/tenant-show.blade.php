<x-layouts.app title="Tenant Detail">
    @php
        $channelConfig = is_array($tenant->channel_config) ? $tenant->channel_config : [];
        $canManageWorkspace = ! in_array($workspaceState, ['not_provisioned', 'missing_config'], true);
        $googleCredential = $tenant->googleCredential;
        $googleConnected = $googleCredential?->isConnected() ?? false;
        $agentCustomization = $tenant->agentCustomization;
        $promptOverrides = is_array($agentCustomization?->prompt_overrides_json) ? $agentCustomization->prompt_overrides_json : [];
        $assignedSkillPackIds = is_array($agentCustomization?->assigned_skill_pack_ids) ? $agentCustomization->assigned_skill_pack_ids : [];
        $agentDefaults = is_array($agentCustomization?->agent_defaults_json) ? $agentCustomization->agent_defaults_json : [];
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
    @endphp

    <div class="topbar">
        <div>
            <span class="eyebrow">Tenant Detail</span>
            <h2>{{ $tenant->business_name }}</h2>
            <p>Operational details, customer context, runtime metadata, and permanent deletion live here.</p>
        </div>
        <div style="display: flex; gap: 10px; flex-wrap: wrap;">
            <a href="{{ route('admin.tenants') }}" class="button button--secondary">Back to Tenants</a>
            @if ($tenant->workspace_url)
                <a href="{{ $tenant->workspace_url }}" class="button button--secondary" target="_blank" rel="noreferrer">Open Customer Workspace URL</a>
            @endif
        </div>
    </div>

    <section class="panel">
        <div class="topbar" style="margin-bottom: 16px;">
            <div>
                <span class="eyebrow">Summary</span>
                <h2 style="font-size: 1.35rem;">Tenant Status</h2>
                <p>Quick status for provisioning, agent sync, health, and workspace runtime.</p>
            </div>
        </div>

        <div style="display: flex; gap: 10px; flex-wrap: wrap; margin-bottom: 18px;">
            <span class="badge {{ $tenant->provisioning_status->value }}">{{ $tenant->provisioning_status->value }}</span>
            <span class="badge {{ $tenant->agent_status === 'live' ? 'ready' : ($tenant->agent_status === 'failed' ? 'failed' : 'pending') }}">{{ $tenant->agent_status ?? 'offline' }}</span>
            <span class="badge {{ $tenant->last_health_check_status === 'healthy' ? 'ready' : ($tenant->last_health_check_status === 'failed' ? 'failed' : 'pending') }}">{{ $tenant->last_health_check_status ?? 'unchecked' }}</span>
            <span class="badge {{ $workspaceState === 'running' ? 'ready' : ($workspaceState === 'stopped' ? 'pending' : 'failed') }}">{{ str_replace('_', ' ', $workspaceState) }}</span>
            <span class="badge {{ $googleState['runtime_badge'] }}">{{ $googleState['runtime_label'] }}</span>
        </div>

        <div class="meta">
            <div class="meta-item">
                <small>Tenant Slug</small>
                <strong>{{ $tenant->slug }}</strong>
            </div>
            <div class="meta-item">
                <small>External Tenant ID</small>
                <strong>{{ $tenant->tenant_id }}</strong>
            </div>
            <div class="meta-item">
                <small>Workspace URL</small>
                <strong>{{ $tenant->workspace_url ?? 'Pending' }}</strong>
            </div>
            <div class="meta-item">
                <small>Assigned Port</small>
                <strong>{{ $tenant->assigned_port ?? 'Pending' }}</strong>
            </div>
        </div>
    </section>

    <div class="grid grid-2" style="margin-top: 18px;">
        <section class="panel">
            <div class="topbar" style="margin-bottom: 16px;">
                <div>
                    <span class="eyebrow">Customer</span>
                    <h2 style="font-size: 1.2rem;">Account Details</h2>
                </div>
            </div>
            <div class="meta">
                <div class="meta-item">
                    <small>Name</small>
                    <strong>{{ $tenant->user?->name ?? 'No linked user' }}</strong>
                </div>
                <div class="meta-item">
                    <small>Email</small>
                    <strong>{{ $tenant->user?->email ?? 'No linked user' }}</strong>
                </div>
                <div class="meta-item">
                    <small>Phone</small>
                    <strong>{{ $tenant->user?->phone ?? 'Not provided' }}</strong>
                </div>
                <div class="meta-item">
                    <small>Role Safety</small>
                    <strong>{{ $tenant->user?->is_admin ? 'Admin linked account' : 'Customer account' }}</strong>
                </div>
            </div>
        </section>

        <section class="panel">
            <div class="topbar" style="margin-bottom: 16px;">
                <div>
                    <span class="eyebrow">Runtime</span>
                    <h2 style="font-size: 1.2rem;">Server & Workspace</h2>
                </div>
            </div>
            <div class="meta">
                <div class="meta-item">
                    <small>Client VPS</small>
                    <strong>{{ $tenant->server?->name ?? 'Unassigned' }}</strong>
                </div>
                <div class="meta-item">
                    <small>Host</small>
                    <strong>{{ $tenant->server?->host ?? '—' }}</strong>
                </div>
                <div class="meta-item">
                    <small>Runtime Path</small>
                    <strong>{{ $tenant->runtime_path ?? 'Pending' }}</strong>
                </div>
                <div class="meta-item">
                    <small>Last Health Message</small>
                    <strong>{{ $tenant->health_check_message ?? 'No health check run yet.' }}</strong>
                </div>
            </div>
        </section>
    </div>

    <div class="grid grid-2" style="margin-top: 18px;">
        <section class="panel">
            <div class="topbar" style="margin-bottom: 16px;">
                <div>
                    <span class="eyebrow">Onboarding</span>
                    <h2 style="font-size: 1.2rem;">Profile & Channel</h2>
                </div>
            </div>
            <div class="meta">
                <div class="meta-item">
                    <small>Onboarding Status</small>
                    <strong>{{ $tenant->onboarding_status ?? 'not_started' }} (step {{ $tenant->onboarding_step ?? 0 }})</strong>
                </div>
                <div class="meta-item">
                    <small>Tone</small>
                    <strong>{{ $tenant->tone ?? 'Not set' }}</strong>
                </div>
                <div class="meta-item">
                    <small>Capabilities</small>
                    <strong>{{ is_array($tenant->capabilities) && $tenant->capabilities !== [] ? implode(', ', $tenant->capabilities) : 'None saved yet' }}</strong>
                </div>
                <div class="meta-item">
                    <small>Channel</small>
                    <strong>{{ $tenant->channel ?? 'Not connected' }}</strong>
                    @if ($tenant->channel === 'telegram')
                        <div class="hint" style="margin-top: 6px;">Bot token saved: {{ filled($channelConfig['telegram_bot_token'] ?? null) ? 'Yes' : 'No' }}</div>
                    @elseif ($tenant->channel === 'whatsapp')
                        <div class="hint" style="margin-top: 6px;">Phone ID saved: {{ filled($channelConfig['whatsapp_phone_number_id'] ?? null) ? 'Yes' : 'No' }}</div>
                    @endif
                </div>
            </div>
        </section>

        <section class="panel">
            <div class="topbar" style="margin-bottom: 16px;">
                <div>
                    <span class="eyebrow">Latest Job</span>
                    <h2 style="font-size: 1.2rem;">Provisioning Outcome</h2>
                </div>
            </div>
            <div class="meta">
                <div class="meta-item">
                    <small>Job Type</small>
                    <strong>{{ $latestJob?->job_type ?? 'No jobs yet' }}</strong>
                </div>
                <div class="meta-item">
                    <small>Status</small>
                    <strong>{{ $latestJob?->status?->value ?? '—' }}</strong>
                </div>
                <div class="meta-item">
                    <small>Started</small>
                    <strong>{{ $latestJob?->started_at?->toDateTimeString() ?? '—' }}</strong>
                </div>
                <div class="meta-item">
                    <small>Completed</small>
                    <strong>{{ $latestJob?->completed_at?->toDateTimeString() ?? '—' }}</strong>
                </div>
            </div>
            <div class="note{{ $latestJob?->error_message ? ' error' : '' }}" style="margin-top: 16px;">
                {{ $latestJob?->error_message ?? 'No recent provisioning error recorded.' }}
            </div>
        </section>
    </div>

    <section class="panel" style="margin-top: 18px;">
        <div class="topbar" style="margin-bottom: 16px;">
            <div>
                <span class="eyebrow">Google Workspace</span>
                <h2 style="font-size: 1.2rem;">Google Workspace Connection</h2>
                <p>Connection state, live sync progress, latest runtime error, and the repair actions that already exist in this control plane.</p>
            </div>
        </div>

        <div style="display: flex; gap: 10px; flex-wrap: wrap; margin-bottom: 18px;">
            <span class="badge {{ $googleState['connection_badge'] }}">{{ $googleState['connection_label'] }}</span>
            <span class="badge {{ $googleState['runtime_badge'] }}">{{ $googleState['runtime_label'] }}</span>
            @if ($googleState['sync_job_status'])
                <span class="badge {{ $googleState['sync_job_badge'] }}">{{ $googleState['sync_job_status'] }}</span>
            @endif
        </div>

        <div class="meta">
            <div class="meta-item">
                <small>Google Email</small>
                <strong>{{ $googleState['google_email'] ?? 'No Google account saved' }}</strong>
            </div>
            <div class="meta-item">
                <small>Connection Status</small>
                <strong>{{ $googleState['connection_label'] }}</strong>
            </div>
            <div class="meta-item">
                <small>Live Access</small>
                <strong>{{ $googleState['runtime_label'] }}</strong>
            </div>
            <div class="meta-item">
                <small>Connected At</small>
                <strong>{{ $googleState['connected_at'] ?? '—' }}</strong>
            </div>
            <div class="meta-item">
                <small>Disconnected At</small>
                <strong>{{ $googleState['disconnected_at'] ?? '—' }}</strong>
            </div>
            <div class="meta-item">
                <small>Last Synced At</small>
                <strong>{{ $googleState['last_synced_at'] ?? '—' }}</strong>
            </div>
            <div class="meta-item">
                <small>Initial Sync Job</small>
                <strong>{{ $googleState['sync_job_status'] ?? 'No sync job recorded' }}</strong>
            </div>
            <div class="meta-item">
                <small>Sync Job Started</small>
                <strong>{{ $googleState['sync_job_started_at'] ?? '—' }}</strong>
            </div>
            <div class="meta-item">
                <small>Sync Job Completed</small>
                <strong>{{ $googleState['sync_job_completed_at'] ?? '—' }}</strong>
            </div>
        </div>

        <div style="display: flex; gap: 10px; flex-wrap: wrap; margin-top: 18px;">
            <form method="POST" action="{{ route('admin.tenants.google.sync', $tenant) }}" class="inline">
                @csrf
                <button type="submit" {{ $googleState['can_queue_sync'] ? '' : 'disabled' }}>Queue Google Sync</button>
            </form>
            <form method="POST" action="{{ route('admin.tenants.runtime-capabilities.sync', $tenant) }}" class="inline">
                @csrf
                <button type="submit" {{ $canManageWorkspace ? '' : 'disabled' }}>Sync Runtime Capabilities</button>
            </form>
            <form method="POST" action="{{ route('admin.tenants.google.test', $tenant) }}" class="inline">
                @csrf
                <button type="submit" {{ $canManageWorkspace && $googleConnected ? '' : 'disabled' }}>Test Google Workspace</button>
            </form>
        </div>

        <div class="hint" style="margin-top: 14px;">
            Queue Google Sync reuses the existing initial Google sync job flow. Sync Runtime Capabilities repairs the `gog` runtime contract. Test Google Workspace runs the full tenant-side smoke verification.
        </div>

        @if (filled($googleState['last_error']))
            <div class="note error" style="margin-top: 16px;">
                {{ $googleState['last_error'] }}
            </div>
        @endif

        @if (filled($googleState['sync_job_error']) && $googleState['sync_job_error'] !== $googleState['last_error'])
            <div class="note error" style="margin-top: 16px;">
                Latest sync job error: {{ $googleState['sync_job_error'] }}
            </div>
        @endif
    </section>

    <section class="panel" style="margin-top: 18px;">
        <div class="topbar" style="margin-bottom: 16px;">
            <div>
                <span class="eyebrow">Runtime Customization</span>
                <h2 style="font-size: 1.2rem;">Agent Runtime Customization</h2>
                <p>Manage tenant-specific prompt overrides, Sync360 skill packs, agent defaults, preview output, and apply history.</p>
            </div>
            <div style="display:flex; gap:10px; flex-wrap:wrap;">
                <span class="badge {{ $agentCustomization?->last_apply_status === 'failed' ? 'failed' : ($agentCustomization?->applied_snapshot_hash ? 'ready' : 'pending') }}">
                    {{ $agentCustomization?->last_apply_status ?? 'draft only' }}
                </span>
                <span class="badge {{ ($agentCustomization?->draft_version ?? 0) > 0 ? 'pending' : 'pending' }}">
                    draft v{{ $agentCustomization?->draft_version ?? 0 }}
                </span>
            </div>
        </div>

        <form method="POST" action="{{ route('admin.tenants.agent-customization.update', $tenant) }}" class="field-single">
            @csrf
            @method('PATCH')

            <div class="grid grid-2">
                <section>
                    <h3 style="margin-top:0;">Skill Packs</h3>
                    <div style="display:grid; gap:12px;">
                        @forelse ($skillRegistry as $skillPack)
                            <label style="display:block; padding:12px; border:1px solid rgba(255,255,255,0.08); border-radius:12px;">
                                <div style="display:flex; gap:10px; align-items:flex-start;">
                                    <input
                                        type="checkbox"
                                        name="assigned_skill_pack_ids[]"
                                        value="{{ $skillPack['id'] }}"
                                        {{ in_array($skillPack['id'], $assignedSkillPackIds, true) ? 'checked' : '' }}
                                    >
                                    <div>
                                        <strong>{{ $skillPack['label'] }}</strong>
                                        <div class="hint">Version {{ $skillPack['version'] }}</div>
                                        <div style="margin-top:6px;">{{ $skillPack['description'] }}</div>
                                    </div>
                                </div>
                            </label>
                        @empty
                            <div class="hint">No Sync360 skill packs are registered yet.</div>
                        @endforelse
                    </div>
                </section>

                <section>
                    <h3 style="margin-top:0;">Agent Defaults</h3>
                    <label>
                        Model
                        <input type="text" name="agent_defaults[model]" value="{{ $agentDefaults['model'] ?? '' }}" placeholder="gpt-4o">
                    </label>
                    <label style="margin-top:12px;">
                        Default Skill IDs
                        <input
                            type="text"
                            name="agent_defaults[default_skill_ids][]"
                            value="{{ implode(', ', $agentDefaults['default_skill_ids'] ?? []) }}"
                            placeholder="custom-default-skill"
                            data-skill-ids-input
                        >
                    </label>
                    <div class="hint" style="margin-top:8px;">Comma-separated skill IDs to add to the default agent skill list.</div>
                </section>
            </div>

            <div class="grid grid-2" style="margin-top: 18px;">
                @foreach (['identity' => 'IDENTITY.md', 'soul' => 'SOUL.md', 'user' => 'USER.md', 'bootstrap' => 'BOOTSTRAP.md'] as $key => $label)
                    @php
                        $override = $promptOverrides[$key] ?? [];
                    @endphp
                    <label>
                        {{ $label }}
                        <div style="display:flex; gap:10px; margin:8px 0;">
                            <label class="inline"><input type="radio" name="prompt_overrides[{{ $key }}][mode]" value="append" {{ ($override['mode'] ?? 'append') === 'append' ? 'checked' : '' }}> Append</label>
                            <label class="inline"><input type="radio" name="prompt_overrides[{{ $key }}][mode]" value="replace" {{ ($override['mode'] ?? null) === 'replace' ? 'checked' : '' }}> Replace</label>
                        </div>
                        <textarea name="prompt_overrides[{{ $key }}][content]" rows="8" placeholder="No override saved">{{ $override['content'] ?? '' }}</textarea>
                    </label>
                @endforeach
            </div>

            <div style="display:flex; gap:10px; flex-wrap:wrap; margin-top:18px;">
                <button type="submit">Save Draft</button>
                <button type="button" class="button button--secondary" data-preview-customization>Preview</button>
            </div>
        </form>

        <div style="display:flex; gap:10px; flex-wrap:wrap; margin-top:18px;">
            <form method="POST" action="{{ route('admin.tenants.agent-customization.apply', $tenant) }}" class="inline">
                @csrf
                <button type="submit" {{ $canApplyAgentCustomization ? '' : 'disabled' }}>Apply</button>
            </form>
            <form method="POST" action="{{ route('admin.tenants.agent-customization.revert', $tenant) }}" class="inline">
                @csrf
                <button type="submit" class="button button--secondary" {{ $canApplyAgentCustomization && $agentCustomization?->last_applied_input_snapshot_json ? '' : 'disabled' }}>Revert</button>
            </form>
        </div>

        <div class="hint" style="margin-top: 14px;">
            Apply and revert queue a tenant-scoped background job. Live runtime changes require the extra apply permission gate.
        </div>

        <div class="grid grid-2" style="margin-top:18px;">
            <section>
                <h3 style="margin-top:0;">Preview</h3>
                <pre data-customization-preview style="white-space:pre-wrap; max-height:420px; overflow:auto; background:rgba(0,0,0,0.24); padding:14px; border-radius:12px;">Preview output will appear here.</pre>
            </section>

            <section>
                <h3 style="margin-top:0;">Apply History</h3>
                <div style="display:grid; gap:10px;">
                    @forelse ($tenant->agentCustomizationApplies->sortByDesc('id') as $entry)
                        <div style="padding:12px; border:1px solid rgba(255,255,255,0.08); border-radius:12px;">
                            <strong>{{ $entry->action }}</strong>
                            <span class="badge {{ $entry->status === 'failed' ? 'failed' : 'ready' }}">{{ $entry->status }}</span>
                            <div class="hint" style="margin-top:6px;">{{ $entry->created_at?->toDateTimeString() ?? 'Pending timestamp' }}</div>
                            <div class="hint">Before: {{ $entry->before_output_hash ?? '—' }}</div>
                            <div class="hint">After: {{ $entry->after_output_hash ?? '—' }}</div>
                            @if ($entry->error)
                                <div class="note error" style="margin-top:8px;">{{ $entry->error }}</div>
                            @endif
                        </div>
                    @empty
                        <div class="hint">No apply history yet.</div>
                    @endforelse
                </div>
            </section>
        </div>
    </section>

    {{-- Trial & AI Usage (Superadmin view) --}}
    <section class="panel" style="margin-top: 18px;">
        <div class="topbar" style="margin-bottom: 16px;">
            <div>
                <span class="eyebrow">Trial</span>
                <h2 style="font-size: 1.2rem;">Trial & AI Usage</h2>
            </div>
            <div>
                @if ($tenant->isTrialExpired())
                    <span class="badge failed">trial expired</span>
                @else
                    @php $urgency = $tenant->trialUrgency(); @endphp
                    <span class="badge {{ $urgency === 'critical' ? 'failed' : ($urgency === 'warning' ? 'pending' : 'ready') }}">
                        {{ $tenant->trialDaysLeft() }} days left
                    </span>
                @endif
            </div>
        </div>

        @if (! $tenant->isTrialExpired())
            @php
                $urgencyColor = match($tenant->trialUrgency()) {
                    'critical' => '#ef4444',
                    'warning'  => '#f59e0b',
                    default    => '#22c55e',
                };
            @endphp
            <div style="margin-bottom: 14px;">
                <div style="display:flex; justify-content:space-between; font-size:0.82rem; color:var(--text-muted,#9ca3af); margin-bottom:5px;">
                    <span>AI Credit</span>
                    <span>${{ number_format((float)($tenant->litellm_spend ?? 0), 2) }} / ${{ number_format((float)($tenant->litellm_max_budget ?? 5), 2) }} ({{ $tenant->trialBudgetPercent() }}%)</span>
                </div>
                <div style="background:rgba(255,255,255,0.07);border-radius:5px;height:7px;overflow:hidden;">
                    <div style="background:{{ $urgencyColor }};width:{{ min(100,$tenant->trialBudgetPercent()) }}%;height:100%;border-radius:5px;"></div>
                </div>
            </div>
            <div style="margin-bottom: 14px;">
                <div style="display:flex; justify-content:space-between; font-size:0.82rem; color:var(--text-muted,#9ca3af); margin-bottom:5px;">
                    <span>Time</span>
                    <span>{{ min(14, (int) $tenant->created_at->diffInDays(now())) }} of 14 days elapsed — {{ $tenant->trialDaysLeft() }} remaining</span>
                </div>
                <div style="background:rgba(255,255,255,0.07);border-radius:5px;height:7px;overflow:hidden;">
                    <div style="background:{{ $urgencyColor }};width:{{ min(100,$tenant->trialTimePercent()) }}%;height:100%;border-radius:5px;"></div>
                </div>
            </div>
        @else
            <div class="note error" style="margin-bottom:14px;">
                Trial has ended. LiteLLM key has been suspended.
            </div>
        @endif

        <div class="meta">
            <div class="meta-item">
                <small>Trial Status</small>
                <strong>{{ $tenant->trial_status->value }}</strong>
            </div>
            <div class="meta-item">
                <small>Trial Ends At</small>
                <strong>{{ $tenant->trial_ends_at?->toDateTimeString() ?? 'Not set (backfill pending)' }}</strong>
            </div>
            <div class="meta-item">
                <small>AI Spend (cached)</small>
                <strong>${{ number_format((float)($tenant->litellm_spend ?? 0), 4) }}</strong>
            </div>
            <div class="meta-item">
                <small>Spend Cached At</small>
                <strong>{{ $tenant->litellm_spend_cached_at?->toDateTimeString() ?? 'Not yet cached' }}</strong>
            </div>
            <div class="meta-item">
                <small>80% Budget Email</small>
                <strong>{{ $tenant->trial_80pct_notified_at?->toDateTimeString() ?? '—' }}</strong>
            </div>
            <div class="meta-item">
                <small>3-Day Warning Email</small>
                <strong>{{ $tenant->trial_3day_notified_at?->toDateTimeString() ?? '—' }}</strong>
            </div>
            <div class="meta-item">
                <small>Expiry Email</small>
                <strong>{{ $tenant->trial_expired_notified_at?->toDateTimeString() ?? '—' }}</strong>
            </div>
            <div class="meta-item">
                <small>LiteLLM Plan</small>
                <strong>{{ $tenant->litellm_plan_name ?? '—' }}</strong>
            </div>
        </div>
    </section>

    <section class="panel" style="margin-top: 18px;">
        <div class="topbar" style="margin-bottom: 16px;">
            <div>
                <span class="eyebrow">Support Actions</span>
                <h2 style="font-size: 1.2rem;">Operational Controls</h2>
            </div>
        </div>

        <div style="display: flex; gap: 10px; flex-wrap: wrap;">
            <form method="POST" action="{{ route('admin.retry', $tenant) }}" class="inline">
                @csrf
                <button type="submit">Retry</button>
            </form>
            <form method="POST" action="{{ route('admin.tenants.health-check', $tenant) }}" class="inline">
                @csrf
                <button type="submit" {{ $canManageWorkspace ? '' : 'disabled' }}>Health Check</button>
            </form>
            <form method="POST" action="{{ route('admin.tenants.resync-agent', $tenant) }}" class="inline">
                @csrf
                <button type="submit" {{ $canManageWorkspace ? '' : 'disabled' }}>Resync Agent</button>
            </form>
            <form method="POST" action="{{ route('admin.tenants.runtime.bootstrap', $tenant) }}" class="inline">
                @csrf
                <button type="submit" {{ $tenant->server ? '' : 'disabled' }}>Bootstrap VPS</button>
            </form>
            <form method="POST" action="{{ route('admin.workspace.start', $tenant) }}" class="inline">
                @csrf
                <button type="submit" {{ $canManageWorkspace ? '' : 'disabled' }}>Start</button>
            </form>
            <form method="POST" action="{{ route('admin.workspace.stop', $tenant) }}" class="inline">
                @csrf
                <button type="submit" {{ $canManageWorkspace ? '' : 'disabled' }}>Stop</button>
            </form>
            <form method="POST" action="{{ route('admin.workspace.restart', $tenant) }}" class="inline">
                @csrf
                <button type="submit" {{ $canManageWorkspace ? '' : 'disabled' }}>Restart</button>
            </form>
        </div>

        <div class="hint" style="margin-top: 14px;">
            Bootstrap VPS installs host-managed runtime dependencies on the assigned client VPS. Workspace controls only manage the running tenant container and do not repair Google auth/runtime state.
        </div>
    </section>

    <section class="panel danger-panel" style="margin-top: 18px;">
        <div class="topbar" style="margin-bottom: 16px;">
            <div>
                <span class="eyebrow">Danger Zone</span>
                <h2 style="font-size: 1.2rem;">Permanent Delete</h2>
                <p>This removes the control-app tenant record, linked customer account, tenant runtime, and LiteLLM resources permanently.</p>
            </div>
        </div>

        <div class="note error" style="margin-bottom: 16px;">
            This action is irreversible. Type <strong>{{ $tenant->slug }}</strong> exactly to enable permanent deletion.
        </div>

        <form method="POST" action="{{ route('admin.tenants.destroy', $tenant) }}" class="field-single">
            @csrf
            @method('DELETE')
            <label>
                Confirm Tenant Slug
                <input
                    type="text"
                    name="confirmation_slug"
                    placeholder="{{ $tenant->slug }}"
                    data-delete-confirmation
                    data-expected-slug="{{ $tenant->slug }}"
                    autocomplete="off"
                >
            </label>
            <div style="display: flex; justify-content: flex-end;">
                <button type="submit" class="button button--danger" data-delete-submit disabled>Delete Tenant Permanently</button>
            </div>
        </form>
    </section>

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
