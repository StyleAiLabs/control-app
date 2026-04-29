<x-layouts.app title="Billing — Sync360">
    @php
        $billingStatus = $billingPayload['billing_status'];
        $statusLabel = $billingStatus instanceof \App\Enums\BillingStatus
            ? $billingStatus->label()
            : \Illuminate\Support\Str::headline((string) $billingStatus);
        $trialing = ! $tenant->hasPaidActivation();
        $usage = (int) ($billingPayload['interaction_usage'] ?? 0);
        $limit = $billingPayload['interaction_limit'];
        $currentPlan = null;

        foreach (($billingPayload['available_plans'] ?? []) as $plan) {
            if (($plan['key'] ?? null) === $tenant->billing_plan) {
                $currentPlan = $plan;
                break;
            }
        }
    @endphp

    <div class="customer-profile-shell">
        <header class="customer-profile-hero">
            <div class="customer-profile-hero__copy">
                <span class="eyebrow">Billing</span>
                <h2>Manage your Sync360 subscription in one place.</h2>
                <p>Review your plan, track monthly interaction usage, and manage payment details without leaving the client portal.</p>
                @if ($trialing)
                    <p class="customer-dashboard-summary-line">{{ $billingPayload['trial_days_left'] }} days left in your free trial.</p>
                @endif
            </div>

            <div class="customer-profile-hero__actions">
                <x-ui.button :href="route('dashboard')" variant="secondary" icon="arrow-left">
                    Back to Dashboard
                </x-ui.button>

                @if (($billingPayload['cta']['can_manage_portal'] ?? false) === true)
                    <x-ui.button :href="route('billing.portal')" icon="external-link" icon-position="after">
                        Manage Subscription
                    </x-ui.button>
                @endif
            </div>
        </header>

        <x-ui.health-rail :items="[
            [
                'label' => 'Billing status',
                'status' => $tenant->hasReachedInteractionLimit() ? 'warning' : ($tenant->isBillingActive() ? 'success' : ($tenant->isBillingPastDue() ? 'warning' : 'neutral')),
                'value' => $statusLabel,
                'note' => $tenant->hasReachedInteractionLimit()
                    ? 'Customer-facing runtime is paused until the next billing cycle.'
                    : ($tenant->isBillingPastDue()
                        ? 'Service remains active during the grace period.'
                        : 'Plan state is synced from Stripe billing events.'),
            ],
            [
                'label' => 'Plan',
                'status' => $tenant->billing_plan ? 'success' : 'neutral',
                'value' => $currentPlan['name'] ?? 'Trial',
                'note' => $currentPlan['tagline'] ?? 'Choose the plan that matches your automation needs.',
            ],
            [
                'label' => 'Usage this cycle',
                'status' => $tenant->hasReachedInteractionLimit() ? 'warning' : 'neutral',
                'value' => $limit ? sprintf('%d of %d used this month', $usage, $limit) : 'Not started',
                'note' => $limit ? 'Counts customer-facing runtime dispatches in the current billing window.' : 'Usage tracking starts after first paid activation.',
            ],
            [
                'label' => 'Next cycle date',
                'status' => $billingPayload['current_cycle_end'] ? 'neutral' : 'pending',
                'value' => $billingPayload['current_cycle_end'] ? $billingPayload['current_cycle_end']->toFormattedDayDateString() : 'Trial in progress',
                'note' => $billingPayload['grace_ends_at']
                    ? 'Grace period ends '.$billingPayload['grace_ends_at']->toFormattedDayDateString().'.'
                    : ($trialing ? $billingPayload['trial_days_left'].' days left' : 'Stripe will define this after checkout completes.'),
            ],
        ]" class="customer-profile-rail" />

        @if ($trialing)
            <section class="customer-profile-layout">
                <x-ui.panel title="Upgrade from trial" description="Choose the plan that fits your monthly interaction needs. Payment starts after checkout.">
                    <div class="grid grid-2">
                        @foreach (($billingPayload['available_plans'] ?? []) as $plan)
                            <article class="panel" style="padding: 24px;">
                                <div class="customer-profile-panel-stack">
                                    <div style="display:flex; align-items:center; justify-content:space-between; gap:12px;">
                                        <div>
                                            <span class="eyebrow">{{ $plan['name'] }}</span>
                                            <h3 class="type-section-title" style="margin-top: 14px;">NZ${{ number_format((int) $plan['monthly_price_nzd']) }}/month</h3>
                                        </div>
                                        <span class="badge ready">{{ (int) $plan['interaction_limit'] }} interactions</span>
                                    </div>

                                    <p class="type-muted" style="margin-top: 12px;">{{ $plan['tagline'] }}</p>

                                    <div class="sync-poc-stack" style="gap: 10px; margin-top: 20px;">
                                        @foreach (($plan['included_features'] ?? []) as $feature)
                                            <div class="sync-poc-subpanel">{{ $feature }}</div>
                                        @endforeach
                                    </div>

                                    @if (($plan['future_entitlements'] ?? []) !== [])
                                        <div style="margin-top: 18px;">
                                            <span class="type-label">Included soon</span>
                                            <div class="sync-poc-stack" style="gap: 10px;">
                                                @foreach (($plan['future_entitlements'] ?? []) as $feature)
                                                    <div class="sync-poc-subpanel">{{ $feature }}</div>
                                                @endforeach
                                            </div>
                                        </div>
                                    @endif

                                    <form method="POST" action="{{ route('billing.checkout') }}" style="margin-top: 22px;">
                                        @csrf
                                        <input type="hidden" name="plan" value="{{ $plan['key'] }}">
                                        <x-ui.button type="submit" class="sidebar-logout-btn">
                                            {{ $plan['key'] === 'standard' ? 'Choose Standard' : 'Choose Flex' }}
                                        </x-ui.button>
                                    </form>
                                </div>
                            </article>
                        @endforeach
                    </div>
                </x-ui.panel>
            </section>
        @else
            <section class="customer-profile-layout">
                <x-ui.panel title="Current subscription" description="This view reflects the plan and usage state currently applied to your tenant runtime.">
                    <div class="stats">
                        <div class="stat">
                            <span class="type-label">Status</span>
                            <strong>{{ $statusLabel }}</strong>
                        </div>
                        <div class="stat">
                            <span class="type-label">Plan</span>
                            <strong>{{ $currentPlan['name'] ?? \Illuminate\Support\Str::headline((string) $tenant->billing_plan) }}</strong>
                        </div>
                        <div class="stat">
                            <span class="type-label">Interactions</span>
                            <strong>{{ $limit ? sprintf('%d of %d used this month', $usage, $limit) : 'Not available' }}</strong>
                        </div>
                        <div class="stat">
                            <span class="type-label">Cycle ends</span>
                            <strong>{{ $billingPayload['current_cycle_end'] ? $billingPayload['current_cycle_end']->toDateString() : 'Pending' }}</strong>
                        </div>
                    </div>

                    <div class="grid grid-2" style="margin-top: 24px;">
                        <div class="panel" style="padding: 24px;">
                            <span class="type-label">Included live modules</span>
                            <div class="sync-poc-stack" style="gap: 10px;">
                                @foreach (($billingPayload['current_plan_features'] ?? []) as $feature)
                                    <div class="sync-poc-subpanel">{{ $feature }}</div>
                                @endforeach
                            </div>
                        </div>

                        <div class="panel" style="padding: 24px;">
                            <span class="type-label">Future entitlements</span>
                            <div class="sync-poc-stack" style="gap: 10px;">
                                @forelse (($billingPayload['future_entitlements'] ?? []) as $feature)
                                    <div class="sync-poc-subpanel">{{ $feature }}</div>
                                @empty
                                    <div class="sync-poc-subpanel">No additional future entitlements on this plan right now.</div>
                                @endforelse
                            </div>
                        </div>
                    </div>

                    <div class="sync-dashboard-inline-actions" style="margin-top: 24px;">
                        <x-ui.button :href="route('billing.portal')" icon="external-link" icon-position="after">
                            {{ $tenant->isBillingPastDue() ? 'Update Payment Method' : 'Manage Subscription' }}
                        </x-ui.button>

                        @if (($billingPayload['cta']['show_upgrade'] ?? false) === true)
                            <form method="POST" action="{{ route('billing.checkout') }}">
                                @csrf
                                <input type="hidden" name="plan" value="flex">
                                <x-ui.button type="submit" variant="secondary">Upgrade to Flex</x-ui.button>
                            </form>
                        @endif
                    </div>
                </x-ui.panel>
            </section>
        @endif
    </div>
</x-layouts.app>
