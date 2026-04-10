<x-layouts.app title="You're All Set — Sync360">
    <div class="topbar">
        <div>
            <span class="eyebrow"><span style="display:inline-block; margin-right:4px;">🎉</span> You're All Set</span>
            <h2>{{ $firstName }}, your workspace is ready.</h2>
            <p>{{ $tenant->business_name }} is set up and ready to start handling messages, bookings, and quotes for you.</p>
        </div>
        <div style="display: flex; gap: 10px; flex-wrap: wrap;">
            <a href="{{ $tenant->workspace_url }}" class="button button--primary" target="_blank" rel="noreferrer">
                Open Your Dashboard &rarr;
            </a>
            @if (auth()->user()?->is_admin)
                <a href="{{ route('admin.tenants') }}" class="button button--secondary">Inspect Tenant</a>
            @endif
        </div>
    </div>

    <section class="grid grid-2">
        <div class="panel">
            <span class="eyebrow">Your Plan</span>
            <div class="meta" style="margin-top: 18px;">
                <div class="meta-item">
                    <small>Business</small>
                    {{ $tenant->business_name }}
                </div>
                <div class="meta-item">
                    <small>Industry</small>
                    {{ $tenant->industry }}
                </div>
                <div class="meta-item">
                    <small>Skill Pack</small>
                    {{ $tenant->skill_pack }}
                </div>
                <div class="meta-item">
                    <small>Trial Status</small>
                    <span class="badge {{ $tenant->trial_status->value }}">
                        {{ $trialLabel }}
                    </span>
                </div>
            </div>
        </div>

        <div class="panel">
            <span class="eyebrow">What's Next</span>
            <div style="margin-top: 18px; display: grid; gap: 16px;">
                @foreach ($nextSteps as $index => $step)
                    <div style="display: flex; gap: 14px; align-items: flex-start;">
                        <div style="width: 28px; height: 28px; border-radius: 50%; background: rgba(255,107,53,0.12); color: var(--accent-dark); display: flex; align-items: center; justify-content: center; font-weight: 700; font-size: 0.85rem; flex-shrink: 0;">{{ $index + 1 }}</div>
                        <div>
                            <div style="font-weight: 600;">{{ $step['title'] }}</div>
                            <div class="hint">{{ $step['description'] }}</div>
                        </div>
                    </div>
                @endforeach
                <a href="{{ $tenant->workspace_url }}" class="button button--primary" target="_blank" rel="noreferrer" style="margin-top: 4px;">
                    Open Your Dashboard &rarr;
                </a>
            </div>
        </div>
    </section>
</x-layouts.app>
