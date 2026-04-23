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

<x-ui.panel title="Estimated Skill Impact" description="Last {{ $tenantAnalytics['window_days'] ?? 30 }} days, based on synced conversion events and Sync360 skill defaults.">
    <x-slot:actions>
        <x-ui.badge status="info" technical>analytics</x-ui.badge>
    </x-slot:actions>

    <div class="stats" style="margin-top: 0; margin-bottom: 0;">
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
</x-ui.panel>

<x-ui.panel title="Recent Outcomes" description="Latest synced conversion events for this tenant.">
    @if (collect($tenantAnalytics['recent_events'])->isEmpty())
        <x-ui.empty-state title="No conversion events yet" description="No conversion events have been synced for this tenant." />
    @else
        <x-ui.table fit>
            <colgroup>
                <col style="width: 28%;">
                <col style="width: 26%;">
                <col style="width: 26%;">
                <col style="width: 20%;">
            </colgroup>
            <thead>
                <tr>
                    <th>Skill</th>
                    <th>Conversion</th>
                    <th>Customer / Session</th>
                    <th>Occurred</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($tenantAnalytics['recent_events'] as $event)
                    <tr>
                        <td>
                            <span class="sync-poc-truncate" title="{{ $event->skill_key }}">{{ $event->skill_key }}</span>
                        </td>
                        <td>
                            <span class="sync-poc-truncate type-tech" title="{{ $event->conversion_id }}">{{ $event->conversion_id }}</span>
                        </td>
                        <td>
                            <div class="sync-poc-table-cell-stack">
                                <span class="sync-poc-truncate" title="{{ $event->customer_label }}">{{ $event->customer_label }}</span>
                                @if ($event->session_id)
                                    <span class="hint sync-poc-truncate" title="{{ $event->session_id }}">{{ $event->session_id }}</span>
                                @endif
                            </div>
                        </td>
                        <td>{{ $event->occurred_at?->diffForHumans() }}</td>
                    </tr>
                @endforeach
            </tbody>
        </x-ui.table>
    @endif
</x-ui.panel>
