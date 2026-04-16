<x-layouts.app title="Dashboard — Sync360">
    {{-- Trial expired banner --}}
    @if ($trialData['is_expired'])
        <div style="background: #7f1d1d; color: #fca5a5; padding: 14px 24px; display: flex; align-items: center; justify-content: space-between; gap: 12px; flex-wrap: wrap; font-size: 0.92rem;">
            <span>
                <strong style="color: #fef2f2;">Your trial has ended.</strong>
                Your digital employee has been paused. Your data is safe.
            </span>
            <a href="mailto:hello@sync360.co.nz" style="background: #fca5a5; color: #7f1d1d; padding: 7px 16px; border-radius: 6px; font-weight: 600; text-decoration: none; white-space: nowrap;">Contact Us →</a>
        </div>
    @endif

    <div class="topbar">
        <div>
            <span class="eyebrow">Dashboard</span>
            <h2>Welcome back, {{ $firstName }}.</h2>
            <p>
                @if ($tenant->onboarding_status === 'complete')
                    {{ $tenant->business_name }} is {{ strtolower($agentContent['label']) }} today.
                @else
                    {{ $tenant->business_name }} is {{ strtolower($provisioningContent['label']) }} and step {{ $onboardingSummary['resume_from_step'] }} is next.
                @endif
            </p>
        </div>
        <div style="display: flex; gap: 10px; flex-wrap: wrap;">
            <a href="{{ $agentContent['primary_cta_route'] }}" class="button button--primary" @if ($tenant->agent_status === 'live' && filled($tenant->workspace_url)) rel="noreferrer" @endif>
                {{ $agentContent['primary_cta_label'] }}
            </a>
            <a href="{{ route('profile.show') }}" class="button button--secondary">Update Business Details</a>
        </div>
    </div>

    <section class="stats" style="margin-bottom: 20px;">
        <div class="stat">
            <div class="hint">Trial</div>
            @if ($trialData['is_expired'])
                <strong style="color: #ef4444;">Trial ended</strong>
                <p>Contact us to reactivate your digital employee.</p>
            @else
                @php
                    $urgencyColor = match($trialData['urgency']) {
                        'critical' => '#ef4444',
                        'warning'  => '#f59e0b',
                        default    => '#22c55e',
                    };
                @endphp
                <strong style="color: {{ $urgencyColor }}; font-size: 0.95rem;">
                    {{ $trialData['days_left'] }} {{ $trialData['days_left'] === 1 ? 'day' : 'days' }} left
                </strong>
                <p>Free trial · ${{ number_format($trialData['spend'], 2) }} of ${{ number_format($trialData['max_budget'], 2) }} used</p>
            @endif
        </div>
        <div class="stat">
            <div class="hint">Workspace</div>
            @if ($workspaceState === 'running')
                <strong>Workspace is live</strong>
                <p>Your digital employee is online and ready for the next step.</p>
            @elseif ($workspaceState === 'stopped')
                <strong style="color: #f59e0b;">Workspace is stopped</strong>
                <p>Your workspace container is currently offline. Contact us to restart.</p>
            @elseif ($workspaceState === 'not_provisioned')
                <strong>{{ $provisioningContent['label'] }}</strong>
                <p>{{ $provisioningContent['description'] }}</p>
            @else
                <strong style="color: #f59e0b;">Status unavailable</strong>
                <p>We couldn't confirm your workspace state. Contact support if issues persist.</p>
            @endif
        </div>
        <div class="stat">
            <div class="hint">Skill Pack</div>
            <strong style="font-size: 1.05rem;">{{ $tenant->skill_pack }}</strong>
            <p>What your digital employee is configured to support.</p>
        </div>
        <div class="stat">
            <div class="hint">Industry</div>
            <strong style="font-size: 1.05rem;">{{ $tenant->industry }}</strong>
            <p>Grounded in your business sector.</p>
        </div>
    </section>

    {{-- Trial & AI Usage Panel (active trials only) --}}
    @if (! $trialData['is_expired'])
        @php
            $urgencyColor = match($trialData['urgency']) {
                'critical' => '#ef4444',
                'warning'  => '#f59e0b',
                default    => '#22c55e',
            };
            $urgencyBg = match($trialData['urgency']) {
                'critical' => 'rgba(239,68,68,0.15)',
                'warning'  => 'rgba(245,158,11,0.15)',
                default    => 'rgba(34,197,94,0.15)',
            };
        @endphp
        <section class="panel" id="trial-usage-panel" style="margin-bottom: 20px;">
            <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 12px; margin-bottom: 18px;">
                <div style="display: flex; align-items: center; gap: 10px;">
                    <span class="eyebrow">Trial &amp; AI Usage</span>
                    <button
                        id="trial-refresh-btn"
                        title="Refresh usage from LiteLLM"
                        onclick="refreshTrialUsage()"
                        style="background: none; border: none; cursor: pointer; padding: 2px; display: flex; align-items: center; color: var(--text-muted, #9ca3af); opacity: 0.7; transition: opacity 0.2s;"
                        onmouseover="this.style.opacity='1'"
                        onmouseout="this.style.opacity='0.7'"
                    >
                        <svg id="trial-refresh-icon" xmlns="http://www.w3.org/2000/svg" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M21 12a9 9 0 1 1-9-9c2.52 0 4.93 1 6.74 2.74L21 8"/>
                            <path d="M21 3v5h-5"/>
                        </svg>
                    </button>
                </div>
                <span id="trial-urgency-badge" style="background: {{ $urgencyBg }}; color: {{ $urgencyColor }}; padding: 4px 12px; border-radius: 20px; font-size: 0.82rem; font-weight: 600; {{ $trialData['urgency'] === 'ok' ? 'display:none;' : '' }}">
                    {{ $trialData['urgency'] === 'critical' ? '⚠ Approaching limit' : 'Heads up — usage climbing' }}
                </span>
            </div>

            {{-- AI Credit bar --}}
            <div style="margin-bottom: 16px;">
                <div style="display: flex; justify-content: space-between; font-size: 0.82rem; color: var(--text-muted, #9ca3af); margin-bottom: 6px;">
                    <span>AI Credit</span>
                    <span id="trial-spend-label">${{ number_format($trialData['spend'], 2) }} of ${{ number_format($trialData['max_budget'], 2) }} used ({{ $trialData['budget_percent'] }}%)</span>
                </div>
                <div style="background: rgba(255,255,255,0.07); border-radius: 6px; height: 8px; overflow: hidden;">
                    <div id="trial-budget-bar" style="background: {{ $urgencyColor }}; width: {{ min(100, $trialData['budget_percent']) }}%; height: 100%; border-radius: 6px; transition: width 0.4s ease;"></div>
                </div>
            </div>

            {{-- Trial Time bar --}}
            <div style="margin-bottom: 16px;">
                <div style="display: flex; justify-content: space-between; font-size: 0.82rem; color: var(--text-muted, #9ca3af); margin-bottom: 6px;">
                    <span>Trial Time</span>
                    <span id="trial-time-label">{{ $trialData['days_left'] }} {{ $trialData['days_left'] === 1 ? 'day' : 'days' }} remaining of 14</span>
                </div>
                <div style="background: rgba(255,255,255,0.07); border-radius: 6px; height: 8px; overflow: hidden;">
                    <div id="trial-time-bar" style="background: {{ $urgencyColor }}; width: {{ min(100, $trialData['time_percent']) }}%; height: 100%; border-radius: 6px; transition: width 0.4s ease;"></div>
                </div>
            </div>

            <div style="font-size: 0.8rem; color: var(--text-muted, #9ca3af); display: flex; justify-content: space-between; flex-wrap: wrap; gap: 8px;">
                <span>Trial ends when either limit is reached first.</span>
                <span id="trial-cached-at">
                    @if ($trialData['spend_cached_at'])
                        Usage data as of {{ $trialData['spend_cached_at'] }}
                    @else
                        Click ↻ to load live usage
                    @endif
                </span>
            </div>

            <div id="trial-refresh-error" style="display:none; margin-top:10px; font-size:0.8rem; color:#ef4444;"></div>
        </section>

        <style>
            @keyframes spin { to { transform: rotate(360deg); } }
            .trial-spin { animation: spin 0.8s linear infinite; transform-origin: center; }
        </style>

        <script>
            const _trialRefreshUrl  = @json(route('dashboard.refresh-trial-usage'));
            const _trialCsrfToken   = @json(csrf_token());

            const urgencyColors = { critical: '#ef4444', warning: '#f59e0b', ok: '#22c55e' };
            const urgencyBgs    = {
                critical: 'rgba(239,68,68,0.15)',
                warning:  'rgba(245,158,11,0.15)',
                ok:       'rgba(34,197,94,0.15)',
            };
            const urgencyLabels = {
                critical: '⚠ Approaching limit',
                warning:  'Heads up — usage climbing',
                ok:       '',
            };

            async function refreshTrialUsage() {
                const btn   = document.getElementById('trial-refresh-btn');
                const icon  = document.getElementById('trial-refresh-icon');
                const error = document.getElementById('trial-refresh-error');

                btn.disabled = true;
                icon.classList.add('trial-spin');
                error.style.display = 'none';

                try {
                    const res  = await fetch(_trialRefreshUrl, {
                        method:  'POST',
                        headers: {
                            'X-CSRF-TOKEN': _trialCsrfToken,
                            'Accept':       'application/json',
                            'Content-Type': 'application/json',
                        },
                    });
                    const data = await res.json();

                    if (!data.success) {
                        error.textContent  = data.message || 'Refresh failed.';
                        error.style.display = 'block';
                        return;
                    }

                    const td    = data.trialData;
                    const color = urgencyColors[td.urgency] || urgencyColors.ok;

                    // Update spend label & bar
                    document.getElementById('trial-spend-label').textContent =
                        '$' + Number(td.spend).toFixed(2) + ' of $' + Number(td.max_budget).toFixed(2) + ' used (' + td.budget_percent + '%)';
                    const budgetBar = document.getElementById('trial-budget-bar');
                    budgetBar.style.background = color;
                    budgetBar.style.width      = Math.min(100, td.budget_percent) + '%';

                    // Update time label & bar
                    const dayLabel = td.days_left === 1 ? 'day' : 'days';
                    document.getElementById('trial-time-label').textContent =
                        td.days_left + ' ' + dayLabel + ' remaining of 14';
                    const timeBar = document.getElementById('trial-time-bar');
                    timeBar.style.background = color;
                    timeBar.style.width      = Math.min(100, td.time_percent) + '%';

                    // Update urgency badge
                    const badge = document.getElementById('trial-urgency-badge');
                    if (td.urgency !== 'ok') {
                        badge.textContent        = urgencyLabels[td.urgency];
                        badge.style.color        = color;
                        badge.style.background   = urgencyBgs[td.urgency];
                        badge.style.display      = 'inline-block';
                    } else {
                        badge.style.display = 'none';
                    }

                    // Update cached-at footer
                    document.getElementById('trial-cached-at').textContent =
                        td.spend_cached_at ? 'Usage data as of ' + td.spend_cached_at : '';

                } catch (e) {
                    error.textContent  = 'Network error — please try again.';
                    error.style.display = 'block';
                } finally {
                    icon.classList.remove('trial-spin');
                    btn.disabled = false;
                }
            }
        </script>
    @endif

    <section class="grid grid-2">
        <div class="panel">
            <span class="eyebrow">Your Business</span>
            <div class="meta" style="margin-top: 18px;">
                <div class="meta-item">
                    <small>Business Name</small>
                    {{ $businessProfile?->business_name ?: $tenant->business_name }}
                </div>
                <div class="meta-item">
                    <small>Industry</small>
                    {{ $businessProfile?->industry ?: $tenant->industry }}
                </div>
                <div class="meta-item">
                    <small>Skill Pack</small>
                    {{ $tenant->skill_pack }}
                </div>
                <div class="meta-item">
                    <small>Contact Details</small>
                    {{ $businessProfile?->contact_email ?: 'No email yet' }}{{ $businessProfile?->contact_phone ? ' • '.$businessProfile->contact_phone : '' }}
                </div>
                <div class="meta-item">
                    <small>Trial Status</small>
                    <span class="badge {{ $tenant->trial_status->value }}">
                        {{ $trialContent['label'] }}
                    </span>
                </div>
            </div>
            <div class="note" style="margin-top: 18px;">
                Keep business details current and we’ll use them the next time the assistant is synced.
            </div>
        </div>

        <div class="panel">
            <span class="eyebrow">Digital Employee Status</span>
            <div style="margin-top: 18px; display: grid; gap: 14px;">
                <div>
                    <small class="hint">Current status</small><br>
                    <span class="badge {{ $agentContent['badge'] }}" style="margin-top: 6px; display: inline-flex;">
                        {{ $agentContent['label'] }}
                    </span>
                </div>
                <div>
                    <small class="hint">What this means</small>
                    <div style="margin-top: 6px; font-weight: 600;">{{ $agentContent['description'] }}</div>
                </div>
                <div class="meta" style="margin-top: 4px;">
                    <div class="meta-item">
                        <small>Channel</small>
                        @if ($tenant->channel === 'whatsapp')
                            <span style="color: var(--text-muted, #9ca3af);">WhatsApp (coming soon)</span>
                        @elseif ($tenant->channel === 'telegram')
                            <span style="display: inline-flex; align-items: center; gap: 6px;">
                                <svg width="18" height="18" viewBox="0 0 24 24" fill="none"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm4.64 6.8c-.15 1.58-.8 5.42-1.13 7.19-.14.75-.42 1-.68 1.03-.58.05-1.02-.38-1.58-.75-.88-.58-1.38-.94-2.23-1.5-.99-.65-.35-1.01.22-1.59.15-.15 2.71-2.48 2.76-2.69a.2.2 0 00-.05-.18c-.06-.05-.14-.03-.21-.02-.09.02-1.49.95-4.22 2.79-.4.27-.76.41-1.08.4-.36-.01-.98-.19-1.46-.35-.59-.2-1.06-.3-1.02-.64.02-.18.27-.36.74-.55 2.92-1.27 4.86-2.11 5.83-2.51 2.78-1.16 3.35-1.36 3.73-1.36.08 0 .27.02.39.12.1.08.13.19.14.27-.01.06.01.24 0 .38z" fill="#229ED9"/></svg>
                                Telegram
                            </span>
                        @else
                            <span style="color: var(--text-muted, #9ca3af);">Not connected yet</span>
                        @endif
                    </div>
                    <div class="meta-item">
                        <small>Tone</small>
                        {{ $tenant->tone ? ucfirst($tenant->tone) : 'Not set yet' }}
                    </div>
                    <div class="meta-item">
                        <small>Skills</small>
                        {{ is_array($tenant->capabilities) && $tenant->capabilities !== [] ? implode(', ', $tenant->capabilities) : 'Not selected yet' }}
                    </div>
                    <div class="meta-item">
                        <small>Last synced</small>
                        {{ $businessProfile?->last_synced_to_agent?->diffForHumans() ?: 'Not synced yet' }}
                    </div>
                </div>
                <a href="{{ $agentContent['primary_cta_route'] }}" class="button button--primary" style="align-self: start;" @if ($tenant->agent_status === 'live' && filled($tenant->workspace_url)) rel="noreferrer" @endif>
                    {{ $agentContent['primary_cta_label'] }}
                </a>
                @if ($tenant->agent_status === 'live')
                    <a href="{{ route('conversations.index') }}" class="button button--secondary" style="align-self: start;">View Messages</a>
                @else
                    <a href="{{ route('profile.show') }}" class="button button--secondary" style="align-self: start;">Edit Business Profile</a>
                @endif
            </div>
        </div>
    </section>

    <section class="grid grid-2" style="margin-top: 18px;">
        <div class="panel">
            <span class="eyebrow">Setup Progress</span>
            @if ($tenant->onboarding_status === 'complete')
                <div class="note" style="margin-top: 18px;">
                    Guided setup is complete. You can still return to the setup flow any time to change your business details, channel, or live assistant configuration.
                </div>
                <div style="margin-top: 18px; display: grid; gap: 14px;">
                    @foreach ($onboardingSummary['steps'] as $number => $step)
                        <div class="meta-item">
                            <small>Step {{ $number }}</small>
                            <div style="display: flex; justify-content: space-between; gap: 12px; align-items: center;">
                                <strong>{{ $step['label'] }}</strong>
                                <span class="badge ready">Done</span>
                            </div>
                        </div>
                    @endforeach
                </div>
            @else
                <div class="note" style="margin-top: 18px;">
                    {{ $onboardingSummary['completed_steps'] }} of {{ $onboardingSummary['total_steps'] }} setup steps are complete. Your next step is <strong>{{ $onboardingSummary['steps'][$onboardingSummary['resume_from_step']]['label'] }}</strong>.
                </div>
                <div style="margin-top: 18px; display: grid; gap: 14px;">
                    @foreach ($onboardingSummary['steps'] as $number => $step)
                        <div class="meta-item">
                            <small>Step {{ $number }}</small>
                            <div style="display: flex; justify-content: space-between; gap: 12px; align-items: center;">
                                <strong>{{ $step['label'] }}</strong>
                                @if ($step['status'] === 'complete')
                                    <span class="badge ready">Done ✓</span>
                                @elseif ($number === $onboardingSummary['resume_from_step'])
                                    <span class="badge pending" style="background: #FF6B35; color: #fff;">Next →</span>
                                @else
                                    <span class="badge" style="background: #f3f4f6; color: #6b7280;">Pending</span>
                                @endif
                            </div>
                        </div>
                    @endforeach
                </div>
                <a href="{{ route('onboarding.show') }}" class="button button--primary" style="margin-top: 18px;">Continue Setup</a>
            @endif
        </div>

        <div class="panel">
            <span class="eyebrow">Conversation Activity</span>
            <section class="stats" style="margin-top: 18px; margin-bottom: 0;">
                <div class="stat">
                    <div class="hint">Total</div>
                    <strong>{{ $conversationStats['total'] }}</strong>
                    <p>All sessions logged so far.</p>
                </div>
                <div class="stat">
                    <div class="hint">Today</div>
                    <strong>{{ $conversationStats['today'] }}</strong>
                    <p>Sessions handled since midnight.</p>
                </div>
                <div class="stat">
                    <div class="hint">This Week</div>
                    <strong>{{ $conversationStats['week'] }}</strong>
                    <p>Sessions in the current week.</p>
                </div>
                <div class="stat">
                    <div class="hint">Channel</div>
                    @if ($tenant->channel === 'telegram')
                        <strong style="display: inline-flex; align-items: center; gap: 5px; font-size: 1rem;">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm4.64 6.8c-.15 1.58-.8 5.42-1.13 7.19-.14.75-.42 1-.68 1.03-.58.05-1.02-.38-1.58-.75-.88-.58-1.38-.94-2.23-1.5-.99-.65-.35-1.01.22-1.59.15-.15 2.71-2.48 2.76-2.69a.2.2 0 00-.05-.18c-.06-.05-.14-.03-.21-.02-.09.02-1.49.95-4.22 2.79-.4.27-.76.41-1.08.4-.36-.01-.98-.19-1.46-.35-.59-.2-1.06-.3-1.02-.64.02-.18.27-.36.74-.55 2.92-1.27 4.86-2.11 5.83-2.51 2.78-1.16 3.35-1.36 3.73-1.36.08 0 .27.02.39.12.1.08.13.19.14.27-.01.06.01.24 0 .38z" fill="#229ED9"/></svg>
                            Telegram
                        </strong>
                        <p>Your connected channel.</p>
                    @elseif ($tenant->channel === 'whatsapp')
                        <strong style="color: var(--text-muted, #9ca3af); font-size: 0.9rem;">Coming Soon</strong>
                        <p>WhatsApp is not live in this onboarding phase.</p>
                    @else
                        <strong style="color: var(--text-muted, #9ca3af); font-size: 0.9rem;">Not connected</strong>
                        <p><a href="{{ route('onboarding.show') }}" style="color: var(--accent, #FF6B35);">Connect a channel →</a></p>
                    @endif
                </div>
            </section>

            <div style="margin-top: 18px; display: flex; gap: 10px; flex-wrap: wrap;">
                <a href="{{ route('conversations.index') }}" class="button button--secondary">View All Conversations</a>
            </div>

            @if ($tenant->agent_status === 'live' && $recentConversations->isNotEmpty())
                <div style="margin-top: 18px; display: grid; gap: 14px;">
                    @foreach ($recentConversations as $conversation)
                        <div class="meta-item">
                            <div style="display: flex; justify-content: space-between; gap: 12px; align-items: center;">
                                <div>
                                    <strong>{{ ucfirst($conversation->channel) }}</strong>
                                    <span class="hint"> • {{ $conversation->from_identifier }}</span>
                                </div>
                                <small class="hint">{{ $conversation->created_at?->diffForHumans() }}</small>
                            </div>
                            <div style="margin-top: 10px; display: grid; gap: 8px;">
                                <div>
                                    <small class="hint">Incoming</small>
                                    <div>{{ \Illuminate\Support\Str::limit($conversation->message_in, 140) }}</div>
                                </div>
                                <div>
                                    <small class="hint">Reply</small>
                                    <div>{{ $conversation->message_out ? \Illuminate\Support\Str::limit($conversation->message_out, 140) : 'No reply was sent.' }}</div>
                                </div>
                            </div>
                        </div>
                    @endforeach
                </div>
            @elseif ($tenant->agent_status === 'live')
                <div class="note" style="margin-top: 18px;">
                    Your digital employee is live, but no owner sessions have been logged yet. Once you start messaging it on Telegram, they’ll show up here.
                </div>
            @else
                <div class="note" style="margin-top: 18px;">
                    Conversation history will appear here after your digital employee is live and you start using Telegram.
                </div>
            @endif
        </div>
    </section>
</x-layouts.app>
