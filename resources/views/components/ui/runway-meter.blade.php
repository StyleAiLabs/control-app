@props([
    'summary',
])

@php
    $urgency = $summary['urgency'] ?? 'ok';
    $tone = match ($urgency) {
        'critical' => 'error',
        'warning' => 'warning',
        default => 'success',
    };
    $badgeLabel = match ($urgency) {
        'critical' => 'Action soon',
        'warning' => 'Usage climbing',
        default => null,
    };
@endphp

<div {{ $attributes->class(['sync-dashboard-runway']) }}>
    <div class="sync-dashboard-runway__header">
        <div class="sync-dashboard-runway__copy">
            <p class="sync-dashboard-runway__summary">{{ $summary['summary'] }}</p>
            @if (! empty($summary['spend_cached_at']) && ! $summary['is_expired'])
                <p class="sync-dashboard-runway__meta" id="trial-cached-at">Usage data as of {{ $summary['spend_cached_at'] }}</p>
            @elseif (! $summary['is_expired'])
                <p class="sync-dashboard-runway__meta" id="trial-cached-at">Click refresh to load live usage.</p>
            @endif
        </div>

        @if (! empty($badgeLabel) && ! $summary['is_expired'])
            <x-ui.badge :status="$tone" id="trial-urgency-badge">{{ $badgeLabel }}</x-ui.badge>
        @endif
    </div>

    @if ($summary['is_expired'])
        <div class="sync-dashboard-runway__expired">
            <x-ui.empty-state title="Trial ended" description="Your workspace is still here, but the live assistant is paused until the account is reactivated." />
        </div>
    @else
        <div class="sync-dashboard-runway__meters">
            <div class="sync-dashboard-runway__meter">
                <div class="sync-dashboard-runway__meter-row">
                    <span>AI credit</span>
                    <span id="trial-spend-label">${{ number_format($summary['spend'], 2) }} of ${{ number_format($summary['max_budget'], 2) }} used ({{ round($summary['budget_percent']) }}%)</span>
                </div>
                <div class="sync-dashboard-runway__track">
                    <span id="trial-budget-bar" class="sync-dashboard-runway__fill sync-dashboard-runway__fill--{{ $tone }}" style="width: {{ min(100, $summary['budget_percent']) }}%"></span>
                </div>
            </div>

            <div class="sync-dashboard-runway__meter">
                <div class="sync-dashboard-runway__meter-row">
                    <span>Trial time</span>
                    <span id="trial-time-label">{{ $summary['days_left'] }} {{ $summary['days_left'] === 1 ? 'day' : 'days' }} remaining</span>
                </div>
                <div class="sync-dashboard-runway__track">
                    <span id="trial-time-bar" class="sync-dashboard-runway__fill sync-dashboard-runway__fill--{{ $tone }}" style="width: {{ min(100, $summary['time_percent']) }}%"></span>
                </div>
            </div>
        </div>

        <div class="sync-dashboard-runway__actions">
            <x-ui.button id="trial-refresh-btn" variant="ghost" icon="refresh-cw" onclick="refreshTrialUsage()">
                Refresh usage
            </x-ui.button>
            <div id="trial-refresh-error" class="sync-dashboard-runway__error" hidden></div>
        </div>
    @endif
</div>
