<x-layouts.app title="Cost Observability">
    <div class="topbar">
        <div>
            <span class="eyebrow">Analytics</span>
            <h2>Cost Observability</h2>
            <p>Actual LiteLLM runtime usage across the fleet for the last {{ $costSummary['window_label'] }}.</p>
        </div>
        <div style="display:flex; gap:10px; flex-wrap:wrap;">
            <a href="{{ route('admin.index') }}" class="button button--secondary">Back to Admin</a>
            <a href="{{ route('admin.analytics.skills') }}" class="button button--secondary">Skill Analytics</a>
        </div>
    </div>

    <section class="stats" style="margin-bottom: 20px;">
        <div class="stat">
            <div class="hint">Runtime Cost</div>
            <strong>${{ number_format((float) $costSummary['totals']['cost_amount'], 2) }}</strong>
            <p>Actual imported LiteLLM spend.</p>
        </div>
        <div class="stat">
            <div class="hint">Requests</div>
            <strong>{{ $costSummary['totals']['request_count'] }}</strong>
            <p>Imported runtime requests.</p>
        </div>
        <div class="stat">
            <div class="hint">Tokens</div>
            <strong>{{ number_format((int) $costSummary['totals']['total_tokens']) }}</strong>
            <p>Prompt plus completion tokens.</p>
        </div>
        <div class="stat">
            <div class="hint">Active Tenants</div>
            <strong>{{ $costSummary['totals']['tenant_count'] }}</strong>
            <p>Tenants with imported runtime usage.</p>
        </div>
    </section>

    <section class="grid grid-2">
        <div class="panel">
            <span class="eyebrow">By Use Case</span>
            <div style="margin-top:16px; display:grid; gap:12px;">
                @forelse ($costSummary['by_use_case'] as $row)
                    <div class="meta-item">
                        <div style="display:flex; justify-content:space-between; gap:12px; align-items:center;">
                            <strong>{{ $row['use_case']->label() }}</strong>
                            <span class="badge ready">${{ number_format((float) $row['total_cost'], 2) }}</span>
                        </div>
                        <div class="hint" style="margin-top:8px;">{{ $row['total_requests'] }} requests • {{ number_format((int) $row['total_tokens']) }} tokens</div>
                    </div>
                @empty
                    <div class="hint">No runtime usage has been imported yet.</div>
                @endforelse
            </div>
        </div>

        <div class="panel">
            <span class="eyebrow">By Model</span>
            <div style="margin-top:16px; display:grid; gap:12px;">
                @forelse ($costSummary['by_model'] as $row)
                    <div class="meta-item">
                        <div style="display:flex; justify-content:space-between; gap:12px; align-items:center;">
                            <strong>{{ $row->effective_model ?: 'Unknown model' }}</strong>
                            <span class="badge ready">${{ number_format((float) $row->total_cost, 2) }}</span>
                        </div>
                        <div class="hint" style="margin-top:8px;">{{ (int) $row->total_requests }} requests • {{ number_format((int) $row->total_tokens) }} tokens</div>
                    </div>
                @empty
                    <div class="hint">No model usage has been imported yet.</div>
                @endforelse
            </div>
        </div>
    </section>

    <section class="grid grid-2" style="margin-top:20px;">
        <div class="panel">
            <span class="eyebrow">By Tenant</span>
            <div style="margin-top:16px; display:grid; gap:12px;">
                @forelse ($costSummary['by_tenant'] as $row)
                    <div class="meta-item">
                        <div style="display:flex; justify-content:space-between; gap:12px; align-items:center;">
                            <strong>{{ $row->business_name }}</strong>
                            <a href="{{ route('admin.tenants.show', ['tenant' => $row->slug, 'tab' => 'costs']) }}" class="button button--secondary">Open</a>
                        </div>
                        <div class="hint" style="margin-top:8px;">${{ number_format((float) $row->total_cost, 2) }} • {{ (int) $row->total_requests }} requests • {{ number_format((int) $row->total_tokens) }} tokens</div>
                    </div>
                @empty
                    <div class="hint">No tenant usage has been imported yet.</div>
                @endforelse
            </div>
        </div>

        <div class="panel">
            <span class="eyebrow">Recent Unmatched Usage</span>
            <div style="margin-top:16px; display:grid; gap:12px;">
                @forelse ($costSummary['recent_unmatched'] as $event)
                    <div class="meta-item">
                        <div style="display:flex; justify-content:space-between; gap:12px; align-items:center;">
                            <strong>{{ $event->tenant?->business_name ?? 'Unknown tenant' }}</strong>
                            <span class="badge warning">{{ $event->effective_model ?: 'Unknown model' }}</span>
                        </div>
                        <div class="hint" style="margin-top:8px;">{{ $event->use_case->label() }} • ${{ number_format((float) $event->cost_amount, 2) }} • {{ $event->occurred_at?->diffForHumans() }}</div>
                    </div>
                @empty
                    <div class="hint">No unmatched runtime spend rows in this window.</div>
                @endforelse
            </div>
        </div>
    </section>
</x-layouts.app>
