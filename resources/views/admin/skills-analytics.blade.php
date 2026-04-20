<x-layouts.app title="Skill Analytics">
    <div class="topbar">
        <div>
            <span class="eyebrow">Analytics</span>
            <h2>Skill Analytics</h2>
            <p>Cross-tenant visibility into estimated custom skill outcomes over the last {{ $analyticsSummary['window_days'] }} days.</p>
        </div>
        <div style="display:flex; gap:10px; flex-wrap:wrap;">
            <a href="{{ route('admin.index') }}" class="button button--secondary">Back to Admin</a>
            <a href="{{ route('admin.skills.index') }}" class="button button--secondary">Skill Catalog</a>
        </div>
    </div>

    <section class="stats" style="margin-bottom: 20px;">
        <div class="stat">
            <div class="hint">Successful Conversions</div>
            <strong>{{ $analyticsSummary['conversions'] }}</strong>
            <p>Tenant-scoped `conversion_succeeded` events.</p>
        </div>
        <div class="stat">
            <div class="hint">Active Tenants</div>
            <strong>{{ $analyticsSummary['tenants'] }}</strong>
            <p>Tenants with at least one synced skill outcome.</p>
        </div>
        <div class="stat">
            <div class="hint">Estimated Time Saved</div>
            <strong>{{ $analyticsSummary['estimated_net_minutes'] }} min</strong>
            <p>Estimated net human minutes saved.</p>
        </div>
        @if ($analyticsSummary['has_estimated_value'])
            <div class="stat">
                <div class="hint">Estimated Value Created</div>
                <strong>${{ number_format((float) $analyticsSummary['estimated_value'], 2) }}</strong>
                <p>Shown only when value defaults or overrides exist.</p>
            </div>
        @endif
    </section>

    <section class="grid grid-2">
        <div class="panel">
            <span class="eyebrow">By Skill</span>
            <div style="margin-top: 16px; display:grid; gap:12px;">
                @forelse ($analyticsSummary['per_skill'] as $row)
                    <div class="meta-item">
                        <div style="display:flex; justify-content:space-between; gap:12px; align-items:center;">
                            <strong>{{ \Illuminate\Support\Str::headline($row->skill_key) }}</strong>
                            <span class="badge ready">{{ $row->conversions }} conversions</span>
                        </div>
                        <div class="hint" style="margin-top:8px;">{{ $row->tenants }} tenants • {{ $row->net_minutes_saved }} estimated minutes saved</div>
                    </div>
                @empty
                    <div class="hint">No skill analytics have been synced yet.</div>
                @endforelse
            </div>
        </div>

        <div class="panel">
            <span class="eyebrow">By Tenant</span>
            <div style="margin-top: 16px; display:grid; gap:12px;">
                @forelse ($analyticsSummary['per_tenant'] as $row)
                    <div class="meta-item">
                        <div style="display:flex; justify-content:space-between; gap:12px; align-items:center;">
                            <strong>{{ $row->business_name }}</strong>
                            <a href="{{ route('admin.tenants.show', ['tenant' => $row->slug, 'tab' => 'analytics']) }}" class="button button--secondary">Open</a>
                        </div>
                        <div class="hint" style="margin-top:8px;">{{ $row->conversions }} conversions • {{ $row->net_minutes_saved }} estimated minutes saved</div>
                    </div>
                @empty
                    <div class="hint">No tenant analytics have been synced yet.</div>
                @endforelse
            </div>
        </div>
    </section>

    <section class="panel" style="margin-top:20px;">
        <span class="eyebrow">Recent Conversion Stream</span>
        <div style="margin-top: 16px; display:grid; gap:12px;">
            @forelse ($analyticsSummary['recent_events'] as $event)
                <div class="meta-item">
                    <div style="display:flex; justify-content:space-between; gap:12px; align-items:center;">
                        <div>
                            <strong>{{ $event->tenant?->business_name ?? 'Unknown tenant' }}</strong>
                            <span class="hint"> • {{ $event->conversion_id }}</span>
                        </div>
                        <small class="hint">{{ $event->occurred_at?->diffForHumans() }}</small>
                    </div>
                    <div class="hint" style="margin-top:8px;">{{ \Illuminate\Support\Str::headline($event->skill_key) }} • {{ $event->customer_label }}</div>
                </div>
            @empty
                <div class="hint">No conversion events have been synced yet.</div>
            @endforelse
        </div>
    </section>
</x-layouts.app>
