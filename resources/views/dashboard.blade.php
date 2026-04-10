<x-layouts.app title="Dashboard — Sync360">
    <div class="topbar">
        <div>
            <span class="eyebrow">Dashboard</span>
            <h2>{{ $tenant->business_name }}</h2>
            <p>Here's what's happening with your digital employee today.</p>
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
            <strong>
                @switch($tenant->trial_status->value)
                    @case('trial_active')   Active @break
                    @case('trial_expired')  Expired @break
                    @default                {{ ucfirst(str_replace('_', ' ', $tenant->trial_status->value)) }}
                @endswitch
            </strong>
            <p>{{ $tenant->trial_status->value === 'trial_active' ? 'Your free trial is running.' : 'Your trial has ended.' }}</p>
        </div>
        <div class="stat">
            <div class="hint">Status</div>
            <strong>
                @switch($tenant->provisioning_status->value)
                    @case('pending')        Setting Up @break
                    @case('provisioning')   Getting Ready @break
                    @case('ready')          Live @break
                    @case('failed')         Needs Attention @break
                    @default                {{ ucfirst($tenant->provisioning_status->value) }}
                @endswitch
            </strong>
            <p>
                @switch($tenant->provisioning_status->value)
                    @case('pending')        In the setup queue. @break
                    @case('provisioning')   Being configured now. @break
                    @case('ready')          Your digital employee is live. @break
                    @case('failed')         Something needs fixing. @break
                    @default &nbsp; @endswitch
            </p>
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
                        {{ $tenant->trial_status->value === 'trial_active' ? 'Active' : 'Expired' }}
                    </span>
                </div>
            </div>
        </div>

        <div class="panel">
            <span class="eyebrow">Digital Employee Status</span>
            <div style="margin-top: 18px; display: grid; gap: 14px;">
                <div>
                    <small class="hint">Setup progress</small><br>
                    <span class="badge {{ $tenant->provisioning_status->value }}" style="margin-top: 6px; display: inline-flex;">
                        @switch($tenant->provisioning_status->value)
                            @case('pending')      Setting Up @break
                            @case('provisioning') Getting Ready @break
                            @case('ready')        Live @break
                            @case('failed')       Needs Attention @break
                            @default              {{ ucfirst($tenant->provisioning_status->value) }}
                        @endswitch
                    </span>
                </div>
                @if ($tenant->provisioning_status->value === 'ready')
                    <div>
                        <small class="hint">Your digital employee</small>
                        <div style="margin-top: 6px; font-weight: 600;">Ready to handle messages, bookings, and quotes.</div>
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
                        We're still getting everything ready for you. This usually takes under a minute.
                        <a href="{{ route('tenant.setup') }}" style="font-weight: 700; color: var(--accent-dark);">Watch setup progress &rarr;</a>
                    </div>
                @endif
            </div>
        </div>
    </section>
</x-layouts.app>
