<div class="sync-poc-detail-grid sync-poc-detail-grid--wide">
    <x-ui.panel title="Server & Workspace" description="Runtime placement, host identity, workspace path, and the latest health signal.">
        <x-slot:actions>
            <x-ui.badge :status="$workspaceState" technical>{{ str_replace('_', ' ', $workspaceState) }}</x-ui.badge>
        </x-slot:actions>

        <div class="sync-poc-detail-grid">
            <div class="sync-poc-field">
                <span class="sync-poc-field__label">Client VPS</span>
                <strong class="sync-poc-field__value">{{ $tenant->server?->name ?? 'Unassigned' }}</strong>
            </div>
            <div class="sync-poc-field">
                <span class="sync-poc-field__label">Host</span>
                <strong class="sync-poc-field__value sync-poc-field__value--technical">{{ $tenant->server?->host ?? '—' }}</strong>
            </div>
            <div class="sync-poc-field">
                <span class="sync-poc-field__label">Runtime Path</span>
                <strong class="sync-poc-field__value sync-poc-field__value--technical">{{ $tenant->runtime_path ?? 'Pending' }}</strong>
            </div>
            <div class="sync-poc-field">
                <span class="sync-poc-field__label">Last Health Message</span>
                <strong class="sync-poc-field__value">{{ $tenant->health_check_message ?? 'No health check run yet.' }}</strong>
            </div>
        </div>
    </x-ui.panel>

    <x-ui.panel title="Profile & Channel" description="Onboarding progress and the saved customer-facing channel configuration.">
        <x-slot:actions>
            <x-ui.badge :status="$tenant->onboarding_status ?? 'pending'" technical>
                step {{ $tenant->onboarding_step ?? 0 }}
            </x-ui.badge>
        </x-slot:actions>

        <div class="sync-poc-detail-grid">
            <div class="sync-poc-field">
                <span class="sync-poc-field__label">Onboarding Status</span>
                <strong class="sync-poc-field__value">{{ $tenant->onboarding_status ?? 'not_started' }}</strong>
            </div>
            <div class="sync-poc-field">
                <span class="sync-poc-field__label">Tone</span>
                <strong class="sync-poc-field__value">{{ $tenant->tone ?? 'Not set' }}</strong>
            </div>
            <div class="sync-poc-field">
                <span class="sync-poc-field__label">Capabilities</span>
                <strong class="sync-poc-field__value">{{ is_array($tenant->capabilities) && $tenant->capabilities !== [] ? implode(', ', $tenant->capabilities) : 'None saved yet' }}</strong>
            </div>
            <div class="sync-poc-field">
                <span class="sync-poc-field__label">Channel</span>
                <strong class="sync-poc-field__value">{{ $tenant->channel ?? 'Not connected' }}</strong>
                @if ($tenant->channel === 'telegram')
                    <div class="hint" style="margin-top: 6px;">Bot token saved: {{ filled($channelConfig['telegram_bot_token'] ?? null) ? 'Yes' : 'No' }}</div>
                @elseif ($tenant->channel === 'whatsapp')
                    <div class="hint" style="margin-top: 6px;">Phone ID saved: {{ filled($channelConfig['whatsapp_phone_number_id'] ?? null) ? 'Yes' : 'No' }}</div>
                @endif
            </div>
        </div>
    </x-ui.panel>
</div>

<x-ui.panel title="Provisioning Outcome" description="Most recent provisioning job and any recorded failure details.">
    <x-slot:actions>
        <x-ui.badge :status="$latestJob?->status?->value ?? 'neutral'" technical>
            {{ $latestJob?->status?->value ?? 'no job' }}
        </x-ui.badge>
    </x-slot:actions>

    <div class="sync-poc-detail-grid">
        <div class="sync-poc-field">
            <span class="sync-poc-field__label">Job Type</span>
            <strong class="sync-poc-field__value sync-poc-field__value--technical">{{ $latestJob?->job_type ?? 'No jobs yet' }}</strong>
        </div>
        <div class="sync-poc-field">
            <span class="sync-poc-field__label">Started</span>
            <strong class="sync-poc-field__value">{{ $latestJob?->started_at?->toDateTimeString() ?? '—' }}</strong>
        </div>
        <div class="sync-poc-field">
            <span class="sync-poc-field__label">Completed</span>
            <strong class="sync-poc-field__value">{{ $latestJob?->completed_at?->toDateTimeString() ?? '—' }}</strong>
        </div>
    </div>

    <div class="note{{ $latestJob?->error_message ? ' error' : '' }}" style="margin-top: 16px;">
        {{ $latestJob?->error_message ?? 'No recent provisioning error recorded.' }}
    </div>
</x-ui.panel>

<x-ui.panel title="Trial & AI Usage" description="Trial expiry, cached LiteLLM spend, and notification checkpoints.">
    <x-slot:actions>
        @if ($tenant->isTrialExpired())
            <x-ui.badge status="expired" technical>trial expired</x-ui.badge>
        @else
            @php $urgency = $tenant->trialUrgency(); @endphp
            <x-ui.badge :status="$urgency === 'critical' ? 'failed' : ($urgency === 'warning' ? 'pending' : 'ready')" technical>
                {{ $tenant->trialDaysLeft() }} days left
            </x-ui.badge>
        @endif
    </x-slot:actions>

    @if (! $tenant->isTrialExpired())
        @php
            $urgencyColor = match($tenant->trialUrgency()) {
                'critical' => 'var(--color-error)',
                'warning'  => 'var(--color-warning)',
                default    => 'var(--color-success)',
            };
        @endphp
        <div class="sync-poc-subpanel">
            <div style="display:flex; justify-content:space-between; gap:12px; font-size:0.82rem; color:color-mix(in oklch, var(--color-base-content) 58%, transparent); margin-bottom:5px;">
                <span>AI Credit</span>
                <span>${{ number_format((float)($tenant->litellm_spend ?? 0), 2) }} / ${{ number_format((float)($tenant->litellm_max_budget ?? 5), 2) }} ({{ $tenant->trialBudgetPercent() }}%)</span>
            </div>
            <div style="background:color-mix(in oklch, var(--color-base-content) 8%, transparent);border-radius:999px;height:8px;overflow:hidden;">
                <div style="background:{{ $urgencyColor }};width:{{ min(100,$tenant->trialBudgetPercent()) }}%;height:100%;border-radius:999px;"></div>
            </div>
        </div>
        <div class="sync-poc-subpanel" style="margin-top: 14px;">
            <div style="display:flex; justify-content:space-between; gap:12px; font-size:0.82rem; color:color-mix(in oklch, var(--color-base-content) 58%, transparent); margin-bottom:5px;">
                <span>Time</span>
                <span>{{ min(14, (int) $tenant->created_at->diffInDays(now())) }} of 14 days elapsed — {{ $tenant->trialDaysLeft() }} remaining</span>
            </div>
            <div style="background:color-mix(in oklch, var(--color-base-content) 8%, transparent);border-radius:999px;height:8px;overflow:hidden;">
                <div style="background:{{ $urgencyColor }};width:{{ min(100,$tenant->trialTimePercent()) }}%;height:100%;border-radius:999px;"></div>
            </div>
        </div>
    @else
        <div class="note error" style="margin-bottom:14px;">
            Trial has ended. LiteLLM key has been suspended.
        </div>
    @endif

    <div class="sync-poc-detail-grid" style="margin-top: 16px;">
        <div class="sync-poc-field">
            <span class="sync-poc-field__label">Trial Status</span>
            <strong class="sync-poc-field__value">{{ $tenant->trial_status->value }}</strong>
        </div>
        <div class="sync-poc-field">
            <span class="sync-poc-field__label">Trial Ends At</span>
            <strong class="sync-poc-field__value">{{ $tenant->trial_ends_at?->toDateTimeString() ?? 'Not set (backfill pending)' }}</strong>
        </div>
        <div class="sync-poc-field">
            <span class="sync-poc-field__label">AI Spend (cached)</span>
            <strong class="sync-poc-field__value">${{ number_format((float)($tenant->litellm_spend ?? 0), 4) }}</strong>
        </div>
        <div class="sync-poc-field">
            <span class="sync-poc-field__label">Spend Cached At</span>
            <strong class="sync-poc-field__value">{{ $tenant->litellm_spend_cached_at?->toDateTimeString() ?? 'Not yet cached' }}</strong>
        </div>
        <div class="sync-poc-field">
            <span class="sync-poc-field__label">80% Budget Email</span>
            <strong class="sync-poc-field__value">{{ $tenant->trial_80pct_notified_at?->toDateTimeString() ?? '—' }}</strong>
        </div>
        <div class="sync-poc-field">
            <span class="sync-poc-field__label">3-Day Warning Email</span>
            <strong class="sync-poc-field__value">{{ $tenant->trial_3day_notified_at?->toDateTimeString() ?? '—' }}</strong>
        </div>
        <div class="sync-poc-field">
            <span class="sync-poc-field__label">Expiry Email</span>
            <strong class="sync-poc-field__value">{{ $tenant->trial_expired_notified_at?->toDateTimeString() ?? '—' }}</strong>
        </div>
        <div class="sync-poc-field">
            <span class="sync-poc-field__label">LiteLLM Plan</span>
            <strong class="sync-poc-field__value">{{ $tenant->litellm_plan_name ?? '—' }}</strong>
        </div>
    </div>
</x-ui.panel>
