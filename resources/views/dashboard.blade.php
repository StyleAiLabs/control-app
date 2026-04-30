<x-layouts.app title="Dashboard — Sync360">
    @if ($trialData['is_expired'])
        <div style="background: #7f1d1d; color: #fca5a5; padding: 14px 24px; display: flex; align-items: center; justify-content: space-between; gap: 12px; flex-wrap: wrap; font-size: 0.92rem;">
            <span>
                <strong style="color: #fef2f2;">Your trial has ended.</strong>
                {{ $expiredTrialCustomerState['note'] ?? 'Your digital employee has been paused. Your data is safe.' }}
            </span>
            <a href="mailto:hello@sync360.co.nz" style="background: #fca5a5; color: #7f1d1d; padding: 7px 16px; border-radius: 6px; font-weight: 600; text-decoration: none; white-space: nowrap;">Contact Us →</a>
        </div>
    @endif

    <div class="customer-dashboard-shell">
        <header class="customer-dashboard-hero">
            <div class="customer-dashboard-hero__copy">
                <span class="eyebrow">Dashboard</span>
                <h2>Welcome back, {{ $firstName }}.</h2>
                <p class="customer-dashboard-summary-line">{{ $dashboardSummary }}</p>
            </div>

            <div class="customer-dashboard-hero__actions">
                @if ($tenant->agent_status === 'live' && filled($tenant->workspace_url))
                    <x-ui.button :href="$agentContent['primary_cta_route']" icon="external-link" icon-position="after" rel="noreferrer">
                        {{ $agentContent['primary_cta_label'] }}
                    </x-ui.button>
                @else
                    <x-ui.button :href="$agentContent['primary_cta_route']" icon="chevron-right" icon-position="after">
                        {{ $agentContent['primary_cta_label'] }}
                    </x-ui.button>
                @endif
                <x-ui.button :href="route('profile.show')" variant="secondary" icon="external-link" icon-position="after">
                    Open Profile
                </x-ui.button>
            </div>
        </header>

        <x-ui.health-rail :items="$healthRail" />

        <div class="sync-dashboard-inline-actions sync-dashboard-window-switcher" aria-label="Dashboard analytics window">
            @foreach ($analyticsWindowOptions as $option)
                <x-ui.button
                    :href="route('dashboard', ['window' => $option['key']])"
                    :variant="$analyticsWindow['key'] === $option['key'] ? 'primary' : 'secondary'"
                    size="sm"
                >
                    {{ $option['label'] }}
                </x-ui.button>
            @endforeach
        </div>

        <section class="customer-dashboard-grid customer-dashboard-grid--top-analytics">
            <x-ui.chart-panel title="Performance Overview" :description="'Reviewed work and successful outcomes in '.$analyticsWindow['label'].'.'">
                <div class="sync-dashboard-kpi-row">
                    <div class="sync-dashboard-kpi">
                        <span class="sync-dashboard-kpi__label">Reviewed</span>
                        <span class="sync-dashboard-kpi__value">{{ $performanceSeries['reviewed_total'] }}</span>
                        <span class="sync-dashboard-kpi__note">Inbox items reviewed in {{ strtolower($analyticsWindow['label']) }}.</span>
                    </div>
                    <div class="sync-dashboard-kpi">
                        <span class="sync-dashboard-kpi__label">Successful Outcomes</span>
                        <span class="sync-dashboard-kpi__value">{{ $impactSummary['conversions'] }}</span>
                        <span class="sync-dashboard-kpi__note">Tracked conversions recorded in {{ strtolower($analyticsWindow['label']) }}.</span>
                    </div>
                    <div class="sync-dashboard-kpi">
                        <span class="sync-dashboard-kpi__label">Estimated Time Saved</span>
                        <span class="sync-dashboard-kpi__value">{{ $impactSummary['estimated_net_minutes'] >= 60 ? sprintf('%dh %dm', intdiv($impactSummary['estimated_net_minutes'], 60), $impactSummary['estimated_net_minutes'] % 60) : $impactSummary['estimated_net_minutes'].' min' }}</span>
                        <span class="sync-dashboard-kpi__note">Based on estimated human vs assistant effort.</span>
                    </div>
                    <div class="sync-dashboard-kpi">
                        <span class="sync-dashboard-kpi__label">Estimated ROI</span>
                        <span class="sync-dashboard-kpi__value">{{ $impactSummary['estimated_roi_ratio'] !== null ? number_format((float) $impactSummary['estimated_roi_ratio'], 1).'x' : '—' }}</span>
                        <span class="sync-dashboard-kpi__note">Efficiency ratio from estimated human and assistant effort.</span>
                    </div>
                </div>
                @if ($performanceSeries['has_data'])
                    <div class="customer-dashboard-panel-stack">
                        <p class="customer-dashboard-summary-line">
                            {{ $performanceSeries['reviewed_total'] }} reviewed items and {{ $performanceSeries['outcomes_total'] }} successful outcomes in {{ strtolower($analyticsWindow['label']) }}.
                        </p>
                        <x-ui.bar-chart
                            :items="$performanceSeries['items']"
                            value-label="Reviewed items"
                            secondary-label="Successful outcomes"
                        />
                    </div>
                @else
                    <x-ui.empty-analytics
                        title="Performance will appear here soon."
                        description="Once inbox work is reviewed or skills record successful outcomes, this chart will start showing the trend."
                    />
                @endif
            </x-ui.chart-panel>

            <x-ui.chart-panel title="Trial Runway" description="A compact view of AI credit and time remaining before the trial ends." class="customer-dashboard-panel customer-dashboard-panel--runway">
                <x-ui.runway-meter :summary="$runwaySummary" />
            </x-ui.chart-panel>
        </section>

        <section class="customer-dashboard-grid customer-dashboard-grid--balanced">
            <x-ui.chart-panel title="Top Skills" :description="'The skills creating the most saved time and successful outcomes in '.$analyticsWindow['label'].'.'">
                <div id="skill-outcomes">
                    @if ($topSkillsSeries['has_data'])
                        <x-ui.bar-chart
                            :items="$topSkillsSeries['items']"
                            variant="horizontal"
                            value-label="Conversions"
                        />
                    @else
                        <x-ui.empty-analytics
                            title="Top skills will show up here."
                            description="As your enabled skills start recording successful outcomes, this panel will rank the strongest contributors."
                        />
                    @endif
                </div>
            </x-ui.chart-panel>

            <x-ui.chart-panel title="Inbox Performance" :description="'How inbound work is being triaged and surfaced in '.$analyticsWindow['label'].'.'">
                @if ($inboxPerformance['enabled'])
                    <div class="customer-dashboard-panel-stack">
                        <div class="sync-dashboard-kpi-row">
                            <div class="sync-dashboard-kpi">
                                <span class="sync-dashboard-kpi__label">State</span>
                                <span class="sync-dashboard-kpi__value">{{ $inboxPerformance['status_label'] }}</span>
                                <span class="sync-dashboard-kpi__note">{{ $inboxPerformance['status_note'] }}</span>
                            </div>
                            <div class="sync-dashboard-kpi">
                                <span class="sync-dashboard-kpi__label">Reviewed</span>
                                <span class="sync-dashboard-kpi__value">{{ $inboxPerformance['reviewed_total'] }}</span>
                                <span class="sync-dashboard-kpi__note">Inbox items reviewed in {{ strtolower($inboxPerformance['window_label']) }}.</span>
                            </div>
                            <div class="sync-dashboard-kpi">
                                <span class="sync-dashboard-kpi__label">Outcomes</span>
                                <span class="sync-dashboard-kpi__value">{{ $inboxPerformance['outcomes_total'] }}</span>
                                <span class="sync-dashboard-kpi__note">{{ $inboxPerformance['value_line'] }}</span>
                            </div>
                        </div>

                        @if ($inboxPerformance['secondary_note'])
                            <p class="customer-dashboard-summary-line">{{ $inboxPerformance['secondary_note'] }}</p>
                        @endif

                        @if ($inboxPerformance['cta'])
                            <div class="sync-dashboard-inline-actions">
                                <x-ui.button :href="$inboxPerformance['cta']['route']" variant="secondary" icon="chevron-right" icon-position="after">
                                    {{ $inboxPerformance['cta']['label'] }}
                                </x-ui.button>
                            </div>
                        @endif
                    </div>
                @else
                    <div class="customer-dashboard-panel-stack">
                        <x-ui.empty-analytics
                            title="Inbox analytics will show up here."
                            description="Enable inbox monitoring during setup and this panel will track reviewed work and qualified leads."
                        />
                        <div class="sync-dashboard-inline-actions">
                            <x-ui.button :href="$inboxPerformance['cta']['route']" variant="secondary" icon="chevron-right" icon-position="after">
                                {{ $inboxPerformance['cta']['label'] }}
                            </x-ui.button>
                        </div>
                    </div>
                @endif
            </x-ui.chart-panel>
        </section>

        @if ($setupWizard)
            <x-ui.chart-panel title="Finish setup" :description="$setupWizard['summary']">
                <div class="customer-dashboard-panel-stack">
                    <x-ui.step-wizard :steps="$setupWizard['steps']" :current-step="$setupWizard['current_step']" />

                    <div class="sync-dashboard-inline-actions">
                        <x-ui.button :href="route('onboarding.show')" icon="chevron-right" icon-position="after">
                            Continue Setup
                        </x-ui.button>
                        <x-ui.button :href="route('profile.show')" variant="secondary">
                            Open Profile
                        </x-ui.button>
                    </div>
                </div>
            </x-ui.chart-panel>
        @endif
    </div>

    @if (! $trialData['is_expired'])
        <style>
            @keyframes spin { to { transform: rotate(360deg); } }
            .trial-spin { animation: spin 0.8s linear infinite; transform-origin: center; }
        </style>

        <script>
            const _trialRefreshUrl = @json(route('dashboard.refresh-trial-usage'));
            const _trialCsrfToken = @json(csrf_token());

            const urgencyColors = { critical: 'error', warning: 'warning', ok: 'success' };
            const urgencyLabels = {
                critical: 'Action soon',
                warning: 'Usage climbing',
                ok: '',
            };

            async function refreshTrialUsage() {
                const btn = document.getElementById('trial-refresh-btn');
                const icon = btn?.querySelector('svg');
                const error = document.getElementById('trial-refresh-error');

                if (!btn || !error) {
                    return;
                }

                btn.disabled = true;
                error.hidden = true;
                error.textContent = '';
                icon?.classList.add('trial-spin');

                try {
                    const res = await fetch(_trialRefreshUrl, {
                        method: 'POST',
                        headers: {
                            'X-CSRF-TOKEN': _trialCsrfToken,
                            'Accept': 'application/json',
                            'Content-Type': 'application/json',
                        },
                    });
                    const data = await res.json();

                    if (!data.success) {
                        error.textContent = data.message || 'Refresh failed.';
                        error.hidden = false;
                        return;
                    }

                    const td = data.trialData;
                    const tone = urgencyColors[td.urgency] || urgencyColors.ok;
                    const spendText = '$' + Number(td.spend).toFixed(2) + ' of $' + Number(td.max_budget).toFixed(2) + ' used (' + td.budget_percent + '%)';
                    const timeText = td.days_left + ' ' + (td.days_left === 1 ? 'day' : 'days') + ' remaining';

                    document.getElementById('trial-spend-label').textContent = spendText;
                    document.getElementById('trial-time-label').textContent = timeText;
                    document.getElementById('trial-cached-at').textContent = td.spend_cached_at ? 'Usage data as of ' + td.spend_cached_at : 'Click refresh to load live usage.';
                    document.getElementById('trial-budget-bar').style.width = Math.min(100, td.budget_percent) + '%';
                    document.getElementById('trial-time-bar').style.width = Math.min(100, td.time_percent) + '%';
                    document.getElementById('trial-budget-bar').className = 'sync-dashboard-runway__fill sync-dashboard-runway__fill--' + tone;
                    document.getElementById('trial-time-bar').className = 'sync-dashboard-runway__fill sync-dashboard-runway__fill--' + tone;

                    const badge = document.getElementById('trial-urgency-badge');
                    if (badge) {
                        if (td.urgency === 'ok') {
                            badge.remove();
                        } else {
                            badge.textContent = urgencyLabels[td.urgency];
                            badge.className = 'badge dui-badge dui-badge-sm whitespace-nowrap font-bold uppercase tracking-[0.035em] ' + (td.urgency === 'critical' ? 'dui-badge-error' : 'dui-badge-warning');
                        }
                    }

                    const trialValue = document.getElementById('dashboard-trial-health-value');
                    const trialNote = document.getElementById('dashboard-trial-health-note');
                    if (trialValue) {
                        trialValue.textContent = td.days_left + ' ' + (td.days_left === 1 ? 'day' : 'days') + ' left';
                    }
                    if (trialNote) {
                        trialNote.textContent = '$' + Number(td.spend).toFixed(2) + ' of $' + Number(td.max_budget).toFixed(2) + ' AI credit used';
                    }
                } catch (e) {
                    error.textContent = 'Network error — please try again.';
                    error.hidden = false;
                } finally {
                    btn.disabled = false;
                    icon?.classList.remove('trial-spin');
                }
            }
        </script>
    @endif
</x-layouts.app>
