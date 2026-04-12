<?php

namespace App\Services;

use App\Models\Tenant;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Response;
use RuntimeException;

class LiteLlmTenantKeyService
{
    public function __construct(private readonly HttpFactory $http)
    {
    }

    /**
     * @return array{key:string,alias:string,plan_name:string,max_budget:float,budget_duration:string,base_url:string}
     */
    public function ensureTenantKey(Tenant $tenant): array
    {
        if ($tenant->litellm_virtual_key) {
            return $this->detailsFromTenant($tenant->fresh());
        }

        return $this->generateTenantKey($tenant);
    }

    /**
     * @return array{key:string,alias:string,plan_name:string,max_budget:float,budget_duration:string,base_url:string}
     */
    public function generateTenantKey(Tenant $tenant): array
    {
        $planName = $tenant->litellm_plan_name ?: (string) config('sync360.litellm.default_plan_name', 'trial');
        $budget = $this->budgetForPlan($planName);
        $budgetDuration = (string) config('sync360.litellm.default_budget_duration', 'monthly');
        $alias = sprintf('openclaw-%s', $tenant->tenant_id);

        $response = $this->request('post', '/key/generate', array_filter([
            'key_alias' => $alias,
            'max_budget' => $budget,
            'budget_duration' => $budgetDuration,
            'team_id' => config('sync360.litellm.team_id'),
            'models' => config('sync360.litellm.default_models', []) ?: null,
            'metadata' => [
                'tenant_id' => $tenant->tenant_id,
                'plan' => $planName,
            ],
        ]));

        $key = (string) data_get($response->json(), 'key', '');

        if ($key === '') {
            throw new RuntimeException(sprintf(
                'LiteLLM key generation succeeded for tenant [%s] but no key was returned.',
                $tenant->tenant_id,
            ));
        }

        $tenant->forceFill([
            'litellm_virtual_key' => $key,
            'litellm_key_alias' => $alias,
            'litellm_plan_name' => $planName,
            'litellm_max_budget' => $budget,
            'litellm_budget_duration' => $budgetDuration,
            'litellm_last_synced_at' => now(),
        ])->save();

        return $this->detailsFromTenant($tenant->fresh());
    }

    public function deleteTenantKey(Tenant $tenant): void
    {
        if (! $tenant->litellm_virtual_key) {
            return;
        }

        $this->request('post', '/key/delete', [
            'keys' => [$tenant->litellm_virtual_key],
        ]);

        $tenant->forceFill([
            'litellm_virtual_key' => null,
            'litellm_key_alias' => null,
            'litellm_plan_name' => null,
            'litellm_max_budget' => null,
            'litellm_budget_duration' => null,
            'litellm_last_synced_at' => now(),
        ])->save();
    }

    public function updateTenantBudget(Tenant $tenant, string $planName, float $budget, ?string $budgetDuration = 'monthly'): void
    {
        if (! $tenant->litellm_virtual_key) {
            throw new RuntimeException(sprintf('Tenant [%s] does not have a LiteLLM key to update.', $tenant->tenant_id));
        }

        $payload = [
            'key' => $tenant->litellm_virtual_key,
            'max_budget' => $budget,
        ];

        if ($budgetDuration !== null) {
            $payload['budget_duration'] = $budgetDuration;
        }

        $this->request('post', '/key/update', $payload);

        $tenant->forceFill([
            'litellm_plan_name' => $planName,
            'litellm_max_budget' => $budget,
            'litellm_budget_duration' => $budgetDuration,
            'litellm_last_synced_at' => now(),
        ])->save();
    }

    public function suspendTenant(Tenant $tenant): void
    {
        if (! $tenant->litellm_virtual_key) {
            throw new RuntimeException(sprintf('Tenant [%s] does not have a LiteLLM key to suspend.', $tenant->tenant_id));
        }

        $this->request('post', '/key/update', [
            'key' => $tenant->litellm_virtual_key,
            'max_budget' => 0,
            'budget_duration' => null,
        ]);

        $tenant->forceFill([
            'litellm_max_budget' => 0,
            'litellm_budget_duration' => null,
            'litellm_last_synced_at' => now(),
        ])->save();
    }

    public function budgetForPlan(string $planName): float
    {
        $planBudgets = (array) config('sync360.litellm.plan_budgets', []);

        if (array_key_exists($planName, $planBudgets)) {
            return (float) $planBudgets[$planName];
        }

        return (float) config('sync360.litellm.default_budget', 25);
    }

    /**
     * @return array{key:string,alias:string,plan_name:string,max_budget:float,budget_duration:string,base_url:string}
     */
    private function detailsFromTenant(Tenant $tenant): array
    {
        return [
            'key' => (string) $tenant->litellm_virtual_key,
            'alias' => (string) $tenant->litellm_key_alias,
            'plan_name' => (string) ($tenant->litellm_plan_name ?: config('sync360.litellm.default_plan_name', 'trial')),
            'max_budget' => (float) ($tenant->litellm_max_budget ?? $this->budgetForPlan((string) ($tenant->litellm_plan_name ?: config('sync360.litellm.default_plan_name', 'trial')))),
            'budget_duration' => (string) ($tenant->litellm_budget_duration ?: config('sync360.litellm.default_budget_duration', 'monthly')),
            'base_url' => rtrim((string) config('services.litellm.base_url', 'https://litellm.stylesoftware.co.nz'), '/'),
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function request(string $method, string $path, array $payload): Response
    {
        $baseUrl = rtrim((string) config('services.litellm.base_url', ''), '/');
        $masterKey = (string) config('services.litellm.master_key', '');

        if ($baseUrl === '') {
            throw new RuntimeException('LiteLLM base URL is not configured.');
        }

        if ($masterKey === '') {
            throw new RuntimeException('LiteLLM master key is not configured.');
        }

        $response = $this->http
            ->baseUrl($baseUrl)
            ->acceptJson()
            ->withToken($masterKey)
            ->send($method, ltrim($path, '/'), [
                'json' => $payload,
            ]);

        if ($response->failed()) {
            throw new RuntimeException(sprintf(
                'LiteLLM request to [%s] failed with HTTP %d. %s',
                $path,
                $response->status(),
                $this->responseSummary($response),
            ));
        }

        return $response;
    }

    private function responseSummary(Response $response): string
    {
        $body = trim($response->body());

        if ($body === '') {
            return 'No response body returned.';
        }

        return mb_strimwidth($body, 0, 240, '...');
    }
}
