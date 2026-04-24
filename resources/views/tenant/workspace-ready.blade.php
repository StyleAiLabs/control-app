<x-layouts.app title="You're All Set — Sync360">
    <div class="topbar">
        <div>
            @if ($workspaceReadiness['customer_ready'])
                <span class="eyebrow"><span style="display:inline-block; margin-right:4px;">🎉</span> You're All Set</span>
                <h2>{{ $firstName }}, your workspace is ready.</h2>
                <p class="type-body">{{ $tenant->business_name }} is set up. Your workspace URL now takes you into the Sync360 login and dashboard experience.</p>
            @else
                <span class="eyebrow">Almost There</span>
                <h2>{{ $firstName }}, your workspace has been created.</h2>
                <p class="type-body">{{ $workspaceReadiness['blocking_message'] ?? 'There is one last setup step to finish before the workspace is customer-ready.' }}</p>
            @endif
        </div>
        <div style="display: flex; gap: 10px; flex-wrap: wrap;">
            @if ($workspaceReadiness['customer_ready'])
                <a href="{{ $tenant->workspace_url }}" class="button button--primary">
                    Open Your Sync360 Workspace &rarr;
                </a>
            @else
                <a href="{{ $workspaceReadiness['next_action']['route'] ?? route('onboarding.show') }}" class="button button--primary">
                    {{ $workspaceReadiness['next_action']['label'] ?? 'Continue Setup' }}
                </a>
                <a href="{{ route('onboarding.show') }}" class="button button--secondary">
                    Continue Setup
                </a>
            @endif
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
                    <small class="type-label">Business</small>
                    <span class="type-value">{{ $tenant->business_name }}</span>
                </div>
                <div class="meta-item">
                    <small class="type-label">Industry</small>
                    <span class="type-value">{{ $tenant->industry }}</span>
                </div>
                <div class="meta-item">
                    <small class="type-label">Modules</small>
                    <span class="type-value">Core modules included</span>
                </div>
                <div class="meta-item">
                    <small class="type-label">Trial Status</small>
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
                            <div class="type-value" style="font-size: 0.98rem;">{{ $step['title'] }}</div>
                            <div class="hint type-muted">{{ $step['description'] }}</div>
                        </div>
                    </div>
                @endforeach
                @if ($workspaceReadiness['customer_ready'])
                    <a href="{{ $tenant->workspace_url }}" class="button button--primary" style="margin-top: 4px;">
                        Open Your Sync360 Workspace &rarr;
                    </a>
                @else
                    <a href="{{ $workspaceReadiness['next_action']['route'] ?? route('onboarding.show') }}" class="button button--primary" style="margin-top: 4px;">
                        {{ $workspaceReadiness['next_action']['label'] ?? 'Continue Setup' }}
                    </a>
                @endif
            </div>
        </div>
    </section>
</x-layouts.app>
