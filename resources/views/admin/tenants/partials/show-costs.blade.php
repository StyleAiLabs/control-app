<section class="panel">
    <div style="display:flex; justify-content:space-between; gap:12px; align-items:flex-start; flex-wrap:wrap;">
        <div>
            <span class="eyebrow">Runtime Cost Observability</span>
            <h3 style="margin-top:6px;">{{ $tenant->business_name }}</h3>
            <p class="hint">Actual imported LiteLLM spend for this tenant over the last {{ $tenantCostSummary['window_label'] }}.</p>
        </div>
        <a href="{{ route('admin.analytics.costs', ['window' => $tenantCostSummary['window']]) }}" class="button button--secondary">Open Fleet Dashboard</a>
    </div>

    <section class="stats" style="margin-top: 20px;">
        <div class="stat">
            <div class="hint">Runtime Cost</div>
            <strong>${{ number_format((float) $tenantCostSummary['totals']['cost_amount'], 2) }}</strong>
        </div>
        <div class="stat">
            <div class="hint">Requests</div>
            <strong>{{ $tenantCostSummary['totals']['request_count'] }}</strong>
        </div>
        <div class="stat">
            <div class="hint">Tokens</div>
            <strong>{{ number_format((int) $tenantCostSummary['totals']['total_tokens']) }}</strong>
        </div>
    </section>
</section>

<section class="grid grid-2" style="margin-top:20px;">
    <div class="panel">
        <span class="eyebrow">By Use Case</span>
        <div style="margin-top:16px; display:grid; gap:12px;">
            @forelse ($tenantCostSummary['by_use_case'] as $row)
                <div class="meta-item">
                    <div style="display:flex; justify-content:space-between; gap:12px; align-items:center;">
                        <strong>{{ $row['use_case']->label() }}</strong>
                        <span class="badge ready">${{ number_format((float) $row['total_cost'], 2) }}</span>
                    </div>
                    <div class="hint" style="margin-top:8px;">{{ $row['total_requests'] }} requests • {{ number_format((int) $row['total_tokens']) }} tokens</div>
                </div>
            @empty
                <div class="hint">No runtime usage has been imported for this tenant yet.</div>
            @endforelse
        </div>
    </div>

    <div class="panel">
        <span class="eyebrow">By Model</span>
        <div style="margin-top:16px; display:grid; gap:12px;">
            @forelse ($tenantCostSummary['by_model'] as $row)
                <div class="meta-item">
                    <div style="display:flex; justify-content:space-between; gap:12px; align-items:center;">
                        <strong>{{ $row->effective_model ?: 'Unknown model' }}</strong>
                        <span class="badge ready">${{ number_format((float) $row->total_cost, 2) }}</span>
                    </div>
                    <div class="hint" style="margin-top:8px;">{{ (int) $row->total_requests }} requests • {{ number_format((int) $row->total_tokens) }} tokens</div>
                </div>
            @empty
                <div class="hint">No model usage has been imported for this tenant yet.</div>
            @endforelse
        </div>
    </div>
</section>

<section class="panel" style="margin-top:20px;">
    <span class="eyebrow">Recent Runtime Usage Events</span>
    <div style="margin-top:16px; display:grid; gap:12px;">
        @forelse ($tenantCostSummary['recent_events'] as $event)
            <div class="meta-item">
                <div style="display:flex; justify-content:space-between; gap:12px; align-items:center;">
                    <strong>{{ $event->use_case->label() }}</strong>
                    <span class="badge ready">${{ number_format((float) $event->cost_amount, 2) }}</span>
                </div>
                <div class="hint" style="margin-top:8px;">{{ $event->effective_model ?: 'Unknown model' }} • {{ number_format((int) $event->total_tokens) }} tokens • {{ $event->occurred_at?->diffForHumans() }}</div>
            </div>
        @empty
            <div class="hint">No runtime usage events have been imported for this tenant yet.</div>
        @endforelse
    </div>
</section>
