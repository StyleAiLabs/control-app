@php
    $tenantAnalytics = is_array($tenantAnalytics ?? null) ? $tenantAnalytics : [
        'conversions' => 0,
        'estimated_human_minutes' => 0,
        'estimated_agent_minutes' => 0,
        'estimated_net_minutes' => 0,
        'productivity_score' => 0,
        'estimated_value' => null,
        'has_estimated_value' => false,
        'estimated_roi_ratio' => null,
        'top_skills' => collect(),
        'recent_events' => collect(),
    ];
@endphp

<section class="panel">
    <span class="eyebrow">Estimated Skill Impact</span>
    <h3 style="margin: 10px 0 0;">Last {{ $tenantAnalytics['window_days'] ?? 30 }} Days</h3>
    <div class="stats" style="margin-top: 18px; margin-bottom: 0;">
        <div class="stat">
            <div class="hint">Successful Conversions</div>
            <strong>{{ $tenantAnalytics['conversions'] }}</strong>
            <p>Estimated productivity score matches this count in v1.</p>
        </div>
        <div class="stat">
            <div class="hint">Estimated Time Saved</div>
            <strong>{{ $tenantAnalytics['estimated_net_minutes'] }} min</strong>
            <p>{{ $tenantAnalytics['estimated_human_minutes'] }} human vs {{ $tenantAnalytics['estimated_agent_minutes'] }} agent minutes.</p>
        </div>
        <div class="stat">
            <div class="hint">Estimated ROI</div>
            <strong>{{ $tenantAnalytics['estimated_roi_ratio'] ? number_format($tenantAnalytics['estimated_roi_ratio'], 1).'x' : 'n/a' }}</strong>
            <p>Operational time-return ratio based on Sync360 skill defaults.</p>
        </div>
        @if ($tenantAnalytics['has_estimated_value'])
            <div class="stat">
                <div class="hint">Estimated Value Created</div>
                <strong>${{ number_format((float) $tenantAnalytics['estimated_value'], 2) }}</strong>
                <p>Shown only when the skill contract provides a value estimate.</p>
            </div>
        @endif
    </div>
</section>

<section class="panel">
    <span class="eyebrow">Recent Outcomes</span>
    @if (collect($tenantAnalytics['recent_events'])->isEmpty())
        <div class="hint" style="margin-top: 14px;">No conversion events have been synced for this tenant yet.</div>
    @else
        <div style="margin-top: 18px; display: grid; gap: 14px;">
            @foreach ($tenantAnalytics['recent_events'] as $event)
                <div class="meta-item">
                    <div style="display:flex; justify-content:space-between; gap:12px; align-items:center;">
                        <div>
                            <strong>{{ $event->skill_key }}</strong>
                            <span class="hint"> • {{ $event->conversion_id }}</span>
                        </div>
                        <small class="hint">{{ $event->occurred_at?->diffForHumans() }}</small>
                    </div>
                    <div class="hint" style="margin-top: 8px;">{{ $event->customer_label }} @if($event->session_id) • {{ $event->session_id }} @endif</div>
                </div>
            @endforeach
        </div>
    @endif
</section>
