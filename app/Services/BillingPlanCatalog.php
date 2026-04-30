<?php

namespace App\Services;

use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use RuntimeException;

class BillingPlanCatalog
{
    /**
     * @return array<string, array<string, mixed>>
     */
    public function all(): array
    {
        $plans = (array) config('sync360.billing.plans', []);

        return collect($plans)
            ->map(fn (array $plan, string $key): array => $this->normalizePlan($key, $plan))
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    public function plan(string $key): array
    {
        $plan = Arr::get(config('sync360.billing.plans', []), $key);

        if (! is_array($plan)) {
            throw new RuntimeException(sprintf('Unknown billing plan [%s].', $key));
        }

        return $this->normalizePlan($key, $plan);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function planForStripePriceId(?string $priceId): ?array
    {
        $normalizedPriceId = is_string($priceId) ? trim($priceId) : '';

        if ($normalizedPriceId === '') {
            return null;
        }

        foreach ($this->all() as $plan) {
            if (($plan['stripe_price_id'] ?? null) === $normalizedPriceId) {
                return $plan;
            }
        }

        return null;
    }

    /**
     * @return list<string>
     */
    public function includedSkillKeysAcrossPlans(): array
    {
        return collect($this->all())
            ->flatMap(fn (array $plan): array => (array) ($plan['included_skill_keys'] ?? []))
            ->filter(fn (mixed $skillKey): bool => is_string($skillKey) && trim($skillKey) !== '')
            ->map(fn (string $skillKey): string => trim($skillKey))
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $plan
     * @return array<string, mixed>
     */
    private function normalizePlan(string $key, array $plan): array
    {
        $includedSkillKeys = array_values(array_filter(array_map(
            static fn (mixed $value): ?string => is_string($value) && trim($value) !== '' ? trim($value) : null,
            (array) ($plan['included_skill_keys'] ?? [])
        )));
        $futureEntitlementKeys = array_values(array_filter(array_map(
            static fn (mixed $value): ?string => is_string($value) && trim($value) !== '' ? trim($value) : null,
            (array) ($plan['future_entitlement_keys'] ?? [])
        )));

        return [
            'key' => $key,
            'name' => (string) ($plan['name'] ?? Str::headline($key)),
            'tagline' => (string) ($plan['tagline'] ?? ''),
            'stripe_price_id' => $plan['stripe_price_id'] ?? null,
            'monthly_price_nzd' => (int) ($plan['monthly_price_nzd'] ?? 0),
            'interaction_limit' => (int) ($plan['interaction_limit'] ?? 0),
            'litellm_plan' => (string) ($plan['litellm_plan'] ?? 'trial'),
            'litellm_budget_ceiling' => (float) ($plan['litellm_budget_ceiling'] ?? 0),
            'included_skill_keys' => $includedSkillKeys,
            'included_features' => array_map([$this, 'humanizeKey'], $includedSkillKeys),
            'future_entitlement_keys' => $futureEntitlementKeys,
            'future_entitlements' => array_map([$this, 'humanizeKey'], $futureEntitlementKeys),
        ];
    }

    private function humanizeKey(string $value): string
    {
        return Str::of($value)->replace('-', ' ')->headline()->value();
    }
}
