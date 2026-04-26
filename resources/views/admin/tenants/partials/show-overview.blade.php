<x-ui.panel
    title="Tenant Summary"
    description="Canonical identifiers, customer context, trial posture, and the latest provisioning evidence."
>
    <x-slot:actions>
        <x-ui.badge :status="$tenant->provisioning_status->value">
            {{ $tenant->provisioning_status->value }}
        </x-ui.badge>
        <x-ui.badge status="technical" technical>
            port {{ $tenant->assigned_port ?? 'pending' }}
        </x-ui.badge>
    </x-slot:actions>

    <div class="sync-poc-detail-grid">
        <div class="sync-poc-field">
            <small class="type-label">Tenant Slug</small>
            <strong class="type-value type-value--technical">{{ $tenant->slug }}</strong>
        </div>
        <div class="sync-poc-field">
            <small class="type-label">External Tenant ID</small>
            <strong class="type-value type-value--technical">{{ $tenant->tenant_id }}</strong>
        </div>
        <div class="sync-poc-field">
            <small class="type-label">Workspace URL</small>
            <strong class="type-value type-value--technical">{{ $tenant->workspace_url ?? 'Pending' }}</strong>
        </div>
        <div class="sync-poc-field">
            <small class="type-label">Assigned Port</small>
            <strong class="type-value type-value--technical">{{ $tenant->assigned_port ?? 'Pending' }}</strong>
        </div>
    </div>
</x-ui.panel>

<div class="sync-poc-detail-grid sync-poc-detail-grid--wide">
    <x-ui.panel title="Account Details">
        <x-slot:actions>
            <x-ui.badge :status="$tenant->user?->is_admin ? 'warning' : 'neutral'">
                {{ $tenant->user?->is_admin ? 'admin linked' : 'customer' }}
            </x-ui.badge>
        </x-slot:actions>

        <div class="sync-poc-detail-grid">
            <div class="sync-poc-field">
                <small class="type-label">Name</small>
                <strong class="type-value">{{ $tenant->user?->name ?? 'No linked user' }}</strong>
            </div>
            <div class="sync-poc-field">
                <small class="type-label">Email</small>
                <strong class="type-value">{{ $tenant->user?->email ?? 'No linked user' }}</strong>
            </div>
            <div class="sync-poc-field">
                <small class="type-label">Phone</small>
                <strong class="type-value">{{ $tenant->user?->phone ?? 'Not provided' }}</strong>
            </div>
            <div class="sync-poc-field">
                <small class="type-label">Role Safety</small>
                <strong class="type-value">{{ $tenant->user?->is_admin ? 'Admin linked account' : 'Customer account' }}</strong>
            </div>
        </div>
    </x-ui.panel>

    <x-ui.panel title="Provisioning Outcome">
        <x-slot:actions>
            <x-ui.badge :status="$latestJob?->status?->value ?? 'neutral'">
                {{ $latestJob?->status?->value ?? 'no jobs' }}
            </x-ui.badge>
        </x-slot:actions>

        <div class="sync-poc-detail-grid">
            <div class="sync-poc-field">
                <small class="type-label">Job Type</small>
                <strong class="type-value type-value--technical">{{ $latestJob?->job_type ?? 'No jobs yet' }}</strong>
            </div>
            <div class="sync-poc-field">
                <small class="type-label">Status</small>
                <strong class="type-value">{{ $latestJob?->status?->value ?? '—' }}</strong>
            </div>
            <div class="sync-poc-field">
                <small class="type-label">Started</small>
                <strong class="type-value">{{ $latestJob?->started_at?->toDateTimeString() ?? '—' }}</strong>
            </div>
            <div class="sync-poc-field">
                <small class="type-label">Completed</small>
                <strong class="type-value">{{ $latestJob?->completed_at?->toDateTimeString() ?? '—' }}</strong>
            </div>
        </div>
        <div class="note{{ $latestJob?->error_message ? ' error' : '' }}" style="margin-top: 16px;">
            {{ $latestJob?->error_message ?? 'No recent provisioning error recorded.' }}
        </div>
    </x-ui.panel>
</div>

<x-ui.panel title="Trial & AI Usage" description="Trial expiry, cached LiteLLM spend, and notification checkpoints.">
    <x-slot:actions>
        <form method="POST" action="{{ route('admin.tenants.trial.extend', $tenant) }}" class="inline">
            @csrf
            <input type="hidden" name="return_tab" value="overview">
            <x-ui.button type="submit" size="sm" icon="calendar-plus" variant="secondary">Add 7 Days</x-ui.button>
        </form>
        @if ($tenant->isTrialExpired())
            <x-ui.badge status="expired">trial expired</x-ui.badge>
        @else
            @php $urgency = $tenant->trialUrgency(); @endphp
            <x-ui.badge :status="$urgency === 'critical' ? 'failed' : ($urgency === 'warning' ? 'pending' : 'ready')">
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
            $trialEndsAt = $tenant->trial_ends_at ?? $tenant->created_at->copy()->addDays(14);
            $trialDurationDays = max(1, (int) ceil($tenant->created_at->diffInSeconds($trialEndsAt, absolute: true) / 86400));
            $trialElapsedDays = min($trialDurationDays, max(0, (int) floor($tenant->created_at->diffInSeconds(now(), absolute: false) / 86400)));
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
                <span>{{ $trialElapsedDays }} of {{ $trialDurationDays }} days elapsed — {{ $tenant->trialDaysLeft() }} remaining</span>
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

<x-ui.panel title="Expired-trial Runtime Overrides" description="Admin controls for keeping selected runtime paths active after a trial expires.">
    <div class="note" style="margin-bottom:14px;">
        Customer-facing AI work pauses by default when a trial expires. Operational Telegram health alerts stay enabled when Telegram is configured.
    </div>

    <form method="POST" action="{{ route('admin.tenants.trial-overrides.update', $tenant) }}" class="sync-poc-stack" style="gap: 16px;">
        @csrf
        @method('PATCH')
        <input type="hidden" name="return_tab" value="overview">

        <label class="sync-poc-subpanel" style="display:flex; gap:12px; align-items:flex-start;">
            <input type="checkbox" name="allow_polling_when_trial_expired" value="1" @checked($tenant->allow_polling_when_trial_expired) style="margin-top: 4px;">
            <span>
                <strong style="display:block;">Allow inbox polling</strong>
                <span class="type-note">Keep Gmail inbox triage polling eligible even while the trial is expired.</span>
            </span>
        </label>

        <label class="sync-poc-subpanel" style="display:flex; gap:12px; align-items:flex-start;">
            <input type="checkbox" name="allow_runtime_replies_when_trial_expired" value="1" @checked($tenant->allow_runtime_replies_when_trial_expired) style="margin-top: 4px;">
            <span>
                <strong style="display:block;">Allow runtime replies</strong>
                <span class="type-note">Permit customer-facing runtime work and assistant replies while expired.</span>
            </span>
        </label>

        <label class="sync-poc-subpanel" style="display:flex; gap:12px; align-items:flex-start;">
            <input type="checkbox" name="allow_litellm_when_trial_expired" value="1" @checked($tenant->allow_litellm_when_trial_expired) style="margin-top: 4px;">
            <span>
                <strong style="display:block;">Keep LiteLLM key active</strong>
                <span class="type-note">When enabled for an expired tenant, Sync360 restores the tenant LiteLLM virtual key immediately.</span>
            </span>
        </label>

        <div class="sync-poc-detail-grid">
            <div class="sync-poc-field">
                <span class="sync-poc-field__label">Inbox Polling</span>
                <strong class="sync-poc-field__value">{{ $tenant->allow_polling_when_trial_expired ? 'allowed while expired' : 'paused on expiry' }}</strong>
            </div>
            <div class="sync-poc-field">
                <span class="sync-poc-field__label">Runtime Replies</span>
                <strong class="sync-poc-field__value">{{ $tenant->allow_runtime_replies_when_trial_expired ? 'allowed while expired' : 'paused on expiry' }}</strong>
            </div>
            <div class="sync-poc-field">
                <span class="sync-poc-field__label">LiteLLM Key</span>
                <strong class="sync-poc-field__value">{{ $tenant->allow_litellm_when_trial_expired ? 'kept active' : 'suspended on expiry' }}</strong>
            </div>
            <div class="sync-poc-field">
                <span class="sync-poc-field__label">Ops Alerts</span>
                <strong class="sync-poc-field__value">still allowed when Telegram is configured</strong>
            </div>
        </div>

        <div>
            <x-ui.button type="submit" size="sm" variant="secondary">Save Expired-trial Overrides</x-ui.button>
        </div>
    </form>
</x-ui.panel>
