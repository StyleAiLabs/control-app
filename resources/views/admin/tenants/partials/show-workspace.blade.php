<div class="grid grid-2">
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
</div>

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

<section class="panel">
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
