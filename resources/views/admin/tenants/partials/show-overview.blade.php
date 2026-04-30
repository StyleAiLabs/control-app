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

@if ($tenant->hasPaidActivation())
    <x-ui.panel title="Billing" description="Paid plan state, billing window, and interaction usage for converted tenants.">
        <x-slot:actions>
            <x-ui.badge :status="$tenant->isBillingActive() ? 'ready' : ($tenant->isBillingPastDue() ? 'warning' : 'failed')">
                {{ $tenant->billing_status?->label() ?? 'Unknown' }}
            </x-ui.badge>
            @if ($tenant->billing_plan)
                <x-ui.badge status="technical" technical>{{ $tenant->billing_plan }}</x-ui.badge>
            @endif
        </x-slot:actions>

        <div class="sync-poc-detail-grid">
            <div class="sync-poc-field">
                <span class="sync-poc-field__label">Plan</span>
                <strong class="sync-poc-field__value">{{ $tenant->billing_plan ? \Illuminate\Support\Str::headline((string) $tenant->billing_plan) : 'Not set' }}</strong>
            </div>
            <div class="sync-poc-field">
                <span class="sync-poc-field__label">Billing Status</span>
                <strong class="sync-poc-field__value">{{ $tenant->billing_status?->label() ?? 'Unknown' }}</strong>
            </div>
            <div class="sync-poc-field">
                <span class="sync-poc-field__label">Cycle Anchor</span>
                <strong class="sync-poc-field__value">{{ $tenant->billing_cycle_anchor_at?->toDateTimeString() ?? 'Pending' }}</strong>
            </div>
            <div class="sync-poc-field">
                <span class="sync-poc-field__label">Cycle End</span>
                <strong class="sync-poc-field__value">{{ $tenant->billing_cycle_ends_at?->toDateTimeString() ?? 'Pending' }}</strong>
            </div>
            <div class="sync-poc-field">
                <span class="sync-poc-field__label">Interactions This Cycle</span>
                <strong class="sync-poc-field__value">
                    @if ($tenant->currentInteractionLimit())
                        {{ $tenant->currentInteractionUsage() }} / {{ $tenant->currentInteractionLimit() }}
                    @else
                        Not configured
                    @endif
                </strong>
            </div>
            <div class="sync-poc-field">
                <span class="sync-poc-field__label">Grace Ends</span>
                <strong class="sync-poc-field__value">{{ $tenant->billing_grace_ends_at?->toDateTimeString() ?? '—' }}</strong>
            </div>
            <div class="sync-poc-field">
                <span class="sync-poc-field__label">First Paid At</span>
                <strong class="sync-poc-field__value">{{ $tenant->billing_first_paid_at?->toDateTimeString() ?? '—' }}</strong>
            </div>
            <div class="sync-poc-field">
                <span class="sync-poc-field__label">Stripe Customer</span>
                <strong class="sync-poc-field__value type-value--technical">{{ $tenant->user?->stripe_id ?? 'Not linked yet' }}</strong>
            </div>
        </div>
    </x-ui.panel>
@endif

<x-ui.panel title="Trial & AI Usage" description="Trial expiry, cached LiteLLM spend, and notification checkpoints.">
    <x-slot:actions>
        @if (! $tenant->hasPaidActivation())
            <form method="POST" action="{{ route('admin.tenants.trial.extend', $tenant) }}" class="inline">
                @csrf
                <input type="hidden" name="return_tab" value="overview">
                <x-ui.button type="submit" size="sm" icon="calendar-plus" variant="secondary">Add 7 Days</x-ui.button>
            </form>
        @endif
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
            $trialEndsAt = $tenant->trialEndsAt();
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

@if (! $tenant->hasPaidActivation())
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
            @if (($expiredTrialPolicySummary['applies'] ?? false) && filled($expiredTrialPolicySummary['label'] ?? null))
                <div class="sync-poc-field">
                    <span class="sync-poc-field__label">Current Policy</span>
                    <strong class="sync-poc-field__value">{{ $expiredTrialPolicySummary['label'] }}</strong>
                    @if (filled($expiredTrialPolicySummary['note'] ?? null))
                        <div class="hint" style="margin-top:6px;">{{ $expiredTrialPolicySummary['note'] }}</div>
                    @endif
                </div>
            @endif
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
@endif
