<?php

namespace App\Services;

use App\Enums\BillingStatus;
use App\Models\Tenant;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Stripe\Event;

class SubscriptionLifecycleService
{
    public function __construct(
        private readonly BillingPlanCatalog $plans,
        private readonly LiteLlmTenantKeyService $liteLlm,
        private readonly TenantAgentSyncService $agentSync,
        private readonly TenantSkillAssignmentService $skillAssignments,
        private readonly SkillCatalogService $skillCatalog,
    ) {
    }

    /**
     * @param  array<string, mixed>  $stripeContext
     */
    public function activateSubscription(Tenant $tenant, string $planKey, ?User $actor = null, array $stripeContext = []): void
    {
        $plan = $this->plans->plan($planKey);
        $actor ??= $tenant->user;
        $cycleAnchor = $this->timestampFromStripe($stripeContext['current_period_start'] ?? null) ?? now();
        $cycleEnd = $this->timestampFromStripe($stripeContext['current_period_end'] ?? null) ?? now()->addMonth();

        $tenant->forceFill([
            'billing_status' => BillingStatus::Active,
            'billing_plan' => $planKey,
            'billing_started_at' => $tenant->billing_started_at ?? now(),
            'billing_grace_ends_at' => null,
            'billing_cycle_anchor_at' => $cycleAnchor,
            'billing_cycle_ends_at' => $cycleEnd,
            'billing_first_paid_at' => $tenant->billing_first_paid_at ?? now(),
        ])->save();

        if ($tenant->litellm_virtual_key) {
            $this->liteLlm->updateTenantBudget(
                $tenant,
                (string) $plan['litellm_plan'],
                (float) $plan['litellm_budget_ceiling'],
                (string) config('sync360.litellm.default_budget_duration', 'monthly'),
            );
        }

        $this->syncPlanEntitlements($tenant, $planKey, $actor);
        $this->agentSync->syncSavedChannelIfReady($tenant->fresh());
    }

    public function markPastDue(Tenant $tenant, ?\DateTimeInterface $graceEndsAt = null): void
    {
        $tenant->forceFill([
            'billing_status' => BillingStatus::PastDue,
            'billing_grace_ends_at' => $graceEndsAt ?? now()->addDays((int) config('sync360.billing.grace_period_days', 3)),
        ])->save();
    }

    public function suspendForBilling(Tenant $tenant, string $reason = 'billing'): void
    {
        $tenant->forceFill([
            'billing_status' => BillingStatus::Suspended,
            'billing_grace_ends_at' => null,
        ])->save();

        if ($tenant->litellm_virtual_key) {
            $this->liteLlm->suspendTenant($tenant);
        }

        $this->agentSync->syncSavedChannelIfReady($tenant->fresh());
    }

    public function cancelSubscription(Tenant $tenant): void
    {
        $tenant->forceFill([
            'billing_status' => BillingStatus::Cancelled,
            'billing_grace_ends_at' => null,
        ])->save();

        if ($tenant->litellm_virtual_key) {
            $this->liteLlm->suspendTenant($tenant);
        }

        $this->agentSync->syncSavedChannelIfReady($tenant->fresh());
    }

    /**
     * @param  array<string, mixed>  $stripeContext
     */
    public function renewBillingCycle(Tenant $tenant, array $stripeContext = []): void
    {
        $tenant->forceFill([
            'billing_status' => BillingStatus::Active,
            'billing_grace_ends_at' => null,
            'billing_cycle_anchor_at' => $this->timestampFromStripe($stripeContext['current_period_start'] ?? null) ?? now(),
            'billing_cycle_ends_at' => $this->timestampFromStripe($stripeContext['current_period_end'] ?? null) ?? now()->addMonth(),
        ])->save();
    }

    public function handleStripeEvent(Event $event): void
    {
        $type = (string) $event->type;
        $object = is_object($event->data->object ?? null) ? $event->data->object : null;

        if ($object === null) {
            return;
        }

        match ($type) {
            'checkout.session.completed' => $this->handleCheckoutCompleted($object),
            'customer.subscription.updated' => $this->handleSubscriptionUpdated($object),
            'customer.subscription.deleted' => $this->handleSubscriptionDeleted($object),
            'invoice.payment_failed' => $this->handleInvoicePaymentFailed($object),
            'invoice.payment_succeeded' => $this->handleInvoicePaymentSucceeded($object),
            default => null,
        };
    }

    private function handleCheckoutCompleted(object $session): void
    {
        $tenant = $this->tenantFromStripeObject($session);
        $user = $tenant?->user;

        if (! $tenant || ! $user) {
            return;
        }

        if (filled($session->customer ?? null)) {
            $user->forceFill(['stripe_id' => (string) $session->customer])->save();
        }

        $planKey = (string) ($session->metadata->selected_plan ?? '');

        if ($planKey === '') {
            return;
        }

        $subscriptionContext = [];

        if (is_object($session->subscription ?? null)) {
            $subscriptionContext = [
                'current_period_start' => $session->subscription->current_period_start ?? null,
                'current_period_end' => $session->subscription->current_period_end ?? null,
            ];
        }

        $this->activateSubscription($tenant, $planKey, $user, $subscriptionContext);
    }

    private function handleSubscriptionUpdated(object $subscription): void
    {
        $tenant = $this->tenantFromStripeObject($subscription);

        if (! $tenant) {
            return;
        }

        $status = (string) ($subscription->status ?? '');
        $planKey = (string) ($subscription->metadata->selected_plan ?? $tenant->billing_plan ?? '');

        if ($planKey !== '' && in_array($status, ['active', 'trialing'], true)) {
            $this->activateSubscription($tenant, $planKey, $tenant->user, [
                'current_period_start' => $subscription->current_period_start ?? null,
                'current_period_end' => $subscription->current_period_end ?? null,
            ]);
            return;
        }

        if ($status === 'past_due') {
            $this->markPastDue($tenant);
            return;
        }

        if (in_array($status, ['unpaid', 'paused'], true)) {
            $this->suspendForBilling($tenant, $status);
        }
    }

    private function handleSubscriptionDeleted(object $subscription): void
    {
        $tenant = $this->tenantFromStripeObject($subscription);

        if ($tenant) {
            $this->cancelSubscription($tenant);
        }
    }

    private function handleInvoicePaymentFailed(object $invoice): void
    {
        $tenant = $this->tenantFromStripeObject($invoice);

        if ($tenant) {
            $this->markPastDue($tenant);
        }
    }

    private function handleInvoicePaymentSucceeded(object $invoice): void
    {
        $tenant = $this->tenantFromStripeObject($invoice);

        if (! $tenant) {
            return;
        }

        if ($tenant->billing_plan !== null) {
            $this->renewBillingCycle($tenant, [
                'current_period_start' => $invoice->period_start ?? null,
                'current_period_end' => $invoice->period_end ?? null,
            ]);
        }
    }

    private function syncPlanEntitlements(Tenant $tenant, string $planKey, ?User $actor): void
    {
        if (! $actor) {
            return;
        }

        $plan = $this->plans->plan($planKey);
        $assignableSkillKeys = collect((array) ($plan['included_skill_keys'] ?? []))
            ->filter(fn (mixed $skillKey): bool => is_string($skillKey) && $this->skillCatalog->activePublishedVersion($skillKey) !== null)
            ->values()
            ->all();

        if ($assignableSkillKeys !== []) {
            $this->skillAssignments->saveDraftAssignments($tenant, $actor, $assignableSkillKeys);
        }
    }

    private function tenantFromStripeObject(object $object): ?Tenant
    {
        $tenantId = (string) ($object->metadata->tenant_id ?? '');
        $tenantUlid = (string) ($object->metadata->tenant_ulid ?? $object->client_reference_id ?? '');
        $customerId = (string) ($object->customer ?? '');

        if ($tenantId === '' && $tenantUlid === '' && $customerId === '') {
            return null;
        }

        return Tenant::query()
            ->where(function ($query) use ($tenantId, $tenantUlid, $customerId): void {
                if ($tenantId !== '') {
                    $query->orWhere('id', (int) $tenantId);
                }

                if ($tenantUlid !== '') {
                    $query->orWhere('tenant_id', $tenantUlid);
                }

                if ($customerId !== '') {
                    $query->orWhereHas('user', fn ($userQuery) => $userQuery->where('stripe_id', $customerId));
                }
            })
            ->with('user')
            ->first();
    }

    private function timestampFromStripe(mixed $value): ?CarbonImmutable
    {
        if (is_numeric($value)) {
            return CarbonImmutable::createFromTimestamp((int) $value);
        }

        return null;
    }
}
