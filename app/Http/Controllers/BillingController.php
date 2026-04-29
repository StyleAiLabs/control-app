<?php

namespace App\Http\Controllers;

use App\Enums\BillingStatus;
use App\Models\Tenant;
use App\Services\BillingPlanCatalog;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use RuntimeException;
use Stripe\StripeClient;

class BillingController extends Controller
{
    public function __construct(
        private readonly BillingPlanCatalog $plans,
    ) {
    }

    public function show(Request $request): View
    {
        $tenant = $request->user()->tenant()->firstOrFail();
        $plans = $this->plans->all();
        $currentPlan = $this->currentPlan($tenant, $plans);
        $usage = $tenant->currentInteractionUsage();
        $limit = $tenant->currentInteractionLimit();
        $billingStatus = $tenant->billing_status ?? BillingStatus::Trialing;

        return view('billing.show', [
            'tenant' => $tenant,
            'billingPayload' => [
                'billing_enabled' => (bool) config('sync360.billing.enabled', false),
                'billing_status' => $billingStatus,
                'billing_plan' => $tenant->billing_plan,
                'trial_status' => $tenant->trial_status,
                'trial_days_left' => $tenant->trialDaysLeft(),
                'current_cycle_end' => $tenant->billing_cycle_ends_at,
                'grace_ends_at' => $tenant->billing_grace_ends_at,
                'interaction_usage' => $usage,
                'interaction_limit' => $limit,
                'usage_paused' => $tenant->hasReachedInteractionLimit(),
                'available_plans' => $plans,
                'current_plan_features' => $currentPlan['included_features'] ?? [],
                'future_entitlements' => $currentPlan['future_entitlements'] ?? [],
                'cta' => [
                    'can_manage_portal' => $tenant->hasPaidActivation(),
                    'show_upgrade' => $tenant->billing_plan === 'standard' && isset($plans['flex']),
                ],
            ],
        ]);
    }

    public function checkout(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'plan' => ['required', 'string'],
        ]);

        $plan = $this->plans->plan($validated['plan']);
        $priceId = trim((string) ($plan['stripe_price_id'] ?? ''));

        if ($priceId === '') {
            return redirect()->route('billing.show')
                ->with('status', 'Billing checkout is not configured for that plan yet.');
        }

        $tenant = $request->user()->tenant()->firstOrFail();
        $stripe = $this->stripe();
        $session = $stripe->checkout->sessions->create([
            'mode' => 'subscription',
            'success_url' => route('billing.success', absolute: true).'?session_id={CHECKOUT_SESSION_ID}',
            'cancel_url' => route('billing.show', absolute: true),
            'customer_email' => $request->user()->email,
            'client_reference_id' => (string) $tenant->tenant_id,
            'line_items' => [
                ['price' => $priceId, 'quantity' => 1],
            ],
            'metadata' => [
                'tenant_id' => (string) $tenant->id,
                'tenant_ulid' => (string) $tenant->tenant_id,
                'selected_plan' => (string) $plan['key'],
                'user_id' => (string) $request->user()->id,
            ],
            'subscription_data' => [
                'metadata' => [
                    'tenant_id' => (string) $tenant->id,
                    'tenant_ulid' => (string) $tenant->tenant_id,
                    'selected_plan' => (string) $plan['key'],
                    'user_id' => (string) $request->user()->id,
                ],
            ],
        ]);

        return redirect()->away((string) $session->url);
    }

    public function success(Request $request): View
    {
        return view('billing.success', [
            'sessionId' => $request->query('session_id'),
        ]);
    }

    public function portal(Request $request): RedirectResponse
    {
        $user = $request->user();

        if (! filled($user->stripe_id)) {
            return redirect()->route('billing.show')
                ->with('status', 'A Stripe customer record is required before you can manage billing.');
        }

        $session = $this->stripe()->billingPortal->sessions->create([
            'customer' => (string) $user->stripe_id,
            'return_url' => route('billing.show', absolute: true),
        ]);

        return redirect()->away((string) $session->url);
    }

    private function stripe(): StripeClient
    {
        $secret = trim((string) config('services.stripe.secret', ''));

        if ($secret === '') {
            throw new RuntimeException('Stripe secret key is not configured.');
        }

        return new StripeClient($secret);
    }

    /**
     * @param  array<string, array<string, mixed>>  $plans
     * @return array<string, mixed>|null
     */
    private function currentPlan(Tenant $tenant, array $plans): ?array
    {
        $planKey = is_string($tenant->billing_plan) ? trim($tenant->billing_plan) : '';

        return $planKey !== '' ? ($plans[$planKey] ?? null) : null;
    }
}
