<x-layouts.app title="Dashboard — Sync360">
    <div class="topbar">
        <div>
            <span class="eyebrow">Dashboard</span>
            <h2>Welcome back, {{ $firstName }}.</h2>
            <p>{{ $tenant->business_name }} is {{ strtolower($provisioningContent['label']) }} today.</p>
        </div>
        <div style="display: flex; gap: 10px; flex-wrap: wrap;">
            @if ($tenant->provisioning_status->value === 'ready')
                <a href="{{ route('tenant.workspace-ready') }}" class="button button--primary">Open Your Dashboard &rarr;</a>
            @else
                <a href="{{ route('tenant.setup') }}" class="button button--primary">View Setup Progress</a>
            @endif
        </div>
    </div>

    <section class="stats" style="margin-bottom: 20px;">
        <div class="stat">
            <div class="hint">Trial</div>
            <strong>{{ $trialContent['label'] }}</strong>
            <p>{{ $trialContent['description'] }}</p>
        </div>
        <div class="stat">
            <div class="hint">Workspace</div>
            <strong>{{ $provisioningContent['label'] }}</strong>
            <p>{{ $provisioningContent['description'] }}</p>
        </div>
        <div class="stat">
            <div class="hint">Skill Pack</div>
            <strong style="font-size: 1.05rem;">{{ $tenant->skill_pack }}</strong>
            <p>What your digital employee handles.</p>
        </div>
        <div class="stat">
            <div class="hint">Industry</div>
            <strong style="font-size: 1.05rem;">{{ $tenant->industry }}</strong>
            <p>Trained for your sector.</p>
        </div>
    </section>

    <section class="grid grid-2">
        <div class="panel">
            <span class="eyebrow">Your Business</span>
            <div class="meta" style="margin-top: 18px;">
                <div class="meta-item">
                    <small>Business Name</small>
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
                        {{ $trialContent['label'] }}
                    </span>
                </div>
            </div>
        </div>

        <div class="panel">
            <span class="eyebrow">Digital Employee Status</span>
            <div style="margin-top: 18px; display: grid; gap: 14px;">
                <div>
                    <small class="hint">Current status</small><br>
                    <span class="badge {{ $tenant->provisioning_status->value }}" style="margin-top: 6px; display: inline-flex;">
                        {{ $provisioningContent['label'] }}
                    </span>
                </div>
                <div>
                    <small class="hint">What this means</small>
                    <div style="margin-top: 6px; font-weight: 600;">{{ $provisioningContent['description'] }}</div>
                </div>
                @if ($tenant->provisioning_status->value === 'ready')
                    <div>
                        <small class="hint">Recommended next step</small>
                        <div style="margin-top: 6px; font-weight: 600;">Open the workspace and start tailoring it to how {{ $tenant->business_name }} works.</div>
                    </div>
                    <a href="{{ route('tenant.workspace-ready') }}" class="button button--primary" style="align-self: start;">
                        Open Your Dashboard &rarr;
                    </a>
                @elseif ($tenant->provisioning_status->value === 'failed')
                    <div class="note error">
                        Setup hit a snag. <a href="{{ route('tenant.setup') }}" style="font-weight: 700;">Check the details &rarr;</a>
                    </div>
                @else
                    <div class="note">
                        We're still getting everything ready for you. This usually takes under a minute, and you do not need to refresh manually.
                        <a href="{{ route('tenant.setup') }}" style="font-weight: 700; color: var(--accent-dark);">Watch setup progress &rarr;</a>
                    </div>
                @endif
            </div>
        </div>
    </section>
</x-layouts.app>
