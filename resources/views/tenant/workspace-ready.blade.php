<x-layouts.app title="You're All Set — Sync360">
    <div class="topbar">
        <div>
            <span class="eyebrow"><span style="display:inline-block; margin-right:4px;">🎉</span> You're All Set</span>
            <h2>Your digital employee is ready to work.</h2>
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
                        {{ $tenant->trial_status->value === 'trial_active' ? 'Free Trial Active' : 'Trial Expired' }}
                    </span>
                </div>
            </div>
        </div>

        <div class="panel">
            <span class="eyebrow">What's Next</span>
            <div style="margin-top: 18px; display: grid; gap: 16px;">
                <div style="display: flex; gap: 14px; align-items: flex-start;">
                    <div style="width: 28px; height: 28px; border-radius: 50%; background: rgba(255,107,53,0.12); color: var(--accent-dark); display: flex; align-items: center; justify-content: center; font-weight: 700; font-size: 0.85rem; flex-shrink: 0;">1</div>
                    <div>
                        <div style="font-weight: 600;">Connect your channels</div>
                        <div class="hint">Link your WhatsApp or email so your digital employee can start receiving messages.</div>
                    </div>
                </div>
                <div style="display: flex; gap: 14px; align-items: flex-start;">
                    <div style="width: 28px; height: 28px; border-radius: 50%; background: rgba(255,107,53,0.12); color: var(--accent-dark); display: flex; align-items: center; justify-content: center; font-weight: 700; font-size: 0.85rem; flex-shrink: 0;">2</div>
                    <div>
                        <div style="font-weight: 600;">Customise your responses</div>
                        <div class="hint">Set your tone, add your pricing, and teach it how your business works.</div>
                    </div>
                </div>
                <div style="display: flex; gap: 14px; align-items: flex-start;">
                    <div style="width: 28px; height: 28px; border-radius: 50%; background: rgba(255,107,53,0.12); color: var(--accent-dark); display: flex; align-items: center; justify-content: center; font-weight: 700; font-size: 0.85rem; flex-shrink: 0;">3</div>
                    <div>
                        <div style="font-weight: 600;">Share your booking link</div>
                        <div class="hint">Put it on your website, social profiles, and email signature.</div>
                    </div>
                </div>
                <div style="display: flex; gap: 14px; align-items: flex-start;">
                    <div style="width: 28px; height: 28px; border-radius: 50%; background: rgba(255,107,53,0.12); color: var(--accent-dark); display: flex; align-items: center; justify-content: center; font-weight: 700; font-size: 0.85rem; flex-shrink: 0;">4</div>
                    <div>
                        <div style="font-weight: 600;">Sit back and let it work</div>
                        <div class="hint">Your digital employee handles the back-and-forth while you focus on the job.</div>
                    </div>
                </div>
                <a href="{{ $tenant->workspace_url }}" class="button button--primary" target="_blank" rel="noreferrer" style="margin-top: 4px;">
                    Open Your Dashboard &rarr;
                </a>
            </div>
        </div>
    </section>
</x-layouts.app>
