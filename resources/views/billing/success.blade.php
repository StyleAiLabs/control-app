<x-layouts.app title="Billing Confirmation — Sync360">
    <div class="customer-profile-shell">
        <header class="customer-profile-hero">
            <div class="customer-profile-hero__copy">
                <span class="eyebrow">Billing</span>
                <h2>Checkout completed.</h2>
                <p>Your subscription activation may take a moment while Stripe webhook processing updates the tenant runtime state.</p>
            </div>

            <div class="customer-profile-hero__actions">
                <x-ui.button :href="route('billing.show')">
                    Back to Billing
                </x-ui.button>
                <x-ui.button :href="route('dashboard')" variant="secondary">
                    Go to Dashboard
                </x-ui.button>
            </div>
        </header>

        <x-ui.panel title="What happens next" description="We finish activation through the billing webhook so the plan, LiteLLM budget, and customer-facing runtime all stay in sync.">
            <div class="sync-poc-stack" style="gap: 14px;">
                <div class="sync-poc-subpanel">Stripe confirms the subscription and customer record.</div>
                <div class="sync-poc-subpanel">Sync360 updates the tenant billing state and plan entitlements.</div>
                <div class="sync-poc-subpanel">The billing page becomes your ongoing subscription management surface.</div>
            </div>

            @if ($sessionId)
                <p class="type-muted" style="margin-top: 20px;">Checkout session: <span class="type-tech">{{ $sessionId }}</span></p>
            @endif
        </x-ui.panel>
    </div>
</x-layouts.app>
