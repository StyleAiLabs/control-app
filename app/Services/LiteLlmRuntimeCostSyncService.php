<?php

namespace App\Services;

use App\Enums\TenantRuntimeUsageUseCase;
use App\Models\Tenant;
use App\Models\TenantRuntimeDispatch;
use App\Models\TenantRuntimeUsageEvent;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use RuntimeException;

class LiteLlmRuntimeCostSyncService
{
    public function __construct(private readonly HttpFactory $http)
    {
    }

    /**
     * @return array{imported:int,matched:int,unmatched:int}
     */
    public function sync(): array
    {
        $totals = [
            'imported' => 0,
            'matched' => 0,
            'unmatched' => 0,
        ];

        foreach ($this->spendLogDates() as $date) {
            foreach ($this->fetchSpendLogsForDate($date) as $row) {
                $normalized = $this->normalizeRow($row);
                $callId = $normalized['litellm_call_id'];

                if ($callId !== null && TenantRuntimeUsageEvent::query()->where('litellm_call_id', $callId)->exists()) {
                    continue;
                }

                $tenant = $this->resolveTenant($normalized);
                $dispatch = $tenant ? $this->matchDispatch($tenant, $normalized) : null;
                $useCase = $dispatch?->use_case ?? TenantRuntimeUsageUseCase::UnknownRuntime;
                $triggerSource = $dispatch?->trigger_source ?? 'litellm-spend-log';

                TenantRuntimeUsageEvent::query()->create([
                    'tenant_id' => $tenant?->id,
                    'tenant_runtime_dispatch_id' => $dispatch?->id,
                    'use_case' => $useCase->value,
                    'trigger_source' => $triggerSource,
                    'effective_model' => $normalized['effective_model'],
                    'request_count' => 1,
                    'prompt_tokens' => $normalized['prompt_tokens'],
                    'completion_tokens' => $normalized['completion_tokens'],
                    'total_tokens' => $normalized['total_tokens'],
                    'cost_amount' => $normalized['cost_amount'],
                    'currency' => $normalized['currency'],
                    'occurred_at' => $normalized['occurred_at'],
                    'litellm_call_id' => $normalized['litellm_call_id'],
                    'litellm_spend_log_id' => $normalized['litellm_spend_log_id'],
                    'litellm_key_alias' => $normalized['litellm_key_alias'],
                    'raw_payload_json' => $normalized['raw_payload_json'],
                ]);

                $totals['imported']++;
                $totals[$dispatch ? 'matched' : 'unmatched']++;
            }
        }

        return $totals;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function fetchSpendLogsForDate(string $date): array
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
            ->get('/spend/logs', [
                'start_date' => $date,
                'end_date' => $date,
            ]);

        if ($response->failed()) {
            throw new RuntimeException(sprintf(
                'LiteLLM /spend/logs request failed with HTTP %d.',
                $response->status(),
            ));
        }

        $decoded = $response->json();
        $rows = is_array($decoded) && array_is_list($decoded)
            ? $decoded
            : data_get($decoded, 'data', []);

        return is_array($rows) ? array_values(array_filter($rows, 'is_array')) : [];
    }

    /**
     * @return Collection<int, string>
     */
    private function spendLogDates(): Collection
    {
        $lookbackDays = max(1, (int) config('sync360.litellm.cost_sync_lookback_days', 7));
        $today = now()->startOfDay();

        return collect(range($lookbackDays - 1, 0))
            ->map(fn (int $offset): string => $today->copy()->subDays($offset)->toDateString());
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array{tenant_external_id:?string,litellm_key_alias:?string,litellm_call_id:?string,litellm_spend_log_id:?string,effective_model:?string,prompt_tokens:int,completion_tokens:int,total_tokens:int,cost_amount:float,currency:string,occurred_at:?Carbon,raw_payload_json:array<string,mixed>}
     */
    private function normalizeRow(array $row): array
    {
        $promptTokens = (int) ($row['prompt_tokens'] ?? $row['input_tokens'] ?? 0);
        $completionTokens = (int) ($row['completion_tokens'] ?? $row['output_tokens'] ?? 0);
        $totalTokens = (int) ($row['total_tokens'] ?? ($promptTokens + $completionTokens));
        $occurredAt = $row['startTime'] ?? $row['created_at'] ?? $row['endTime'] ?? null;

        return [
            'tenant_external_id' => $this->nullableString(data_get($row, 'metadata.tenant_id')),
            'litellm_key_alias' => $this->nullableString($row['api_key_alias'] ?? $row['key_alias'] ?? $row['user_api_key_alias'] ?? null),
            'litellm_call_id' => $this->nullableString($row['request_id'] ?? $row['call_id'] ?? $row['litellm_call_id'] ?? null),
            'litellm_spend_log_id' => $this->nullableString($row['id'] ?? $row['spend_log_id'] ?? null),
            'effective_model' => $this->nullableString($row['model'] ?? $row['model_name'] ?? null),
            'prompt_tokens' => max(0, $promptTokens),
            'completion_tokens' => max(0, $completionTokens),
            'total_tokens' => max(0, $totalTokens),
            'cost_amount' => round((float) ($row['spend'] ?? $row['cost'] ?? $row['response_cost'] ?? 0), 6),
            'currency' => $this->nullableString($row['currency'] ?? null) ?? 'USD',
            'occurred_at' => $occurredAt ? Carbon::parse((string) $occurredAt) : null,
            'raw_payload_json' => $row,
        ];
    }

    /**
     * @param  array{tenant_external_id:?string,litellm_key_alias:?string}  $normalized
     */
    private function resolveTenant(array $normalized): ?Tenant
    {
        $tenantId = $normalized['tenant_external_id'];

        if ($tenantId !== null) {
            $tenant = Tenant::query()->where('tenant_id', $tenantId)->first();
            if ($tenant) {
                return $tenant;
            }
        }

        $alias = $normalized['litellm_key_alias'];

        if ($alias !== null) {
            return Tenant::query()->where('litellm_key_alias', $alias)->first();
        }

        return null;
    }

    /**
     * @param  array{effective_model:?string,occurred_at:?Carbon}  $normalized
     */
    private function matchDispatch(Tenant $tenant, array $normalized): ?TenantRuntimeDispatch
    {
        if (! $normalized['occurred_at']) {
            return null;
        }

        $query = TenantRuntimeDispatch::query()
            ->where('tenant_id', $tenant->id)
            ->where('occurred_at', '<=', $normalized['occurred_at'])
            ->where('occurred_at', '>=', $normalized['occurred_at']->copy()->subMinutes(30))
            ->whereIn('dispatch_status', ['pending', 'sent']);

        if ($normalized['effective_model'] !== null) {
            $query->where(function ($nested) use ($normalized): void {
                $nested->where('effective_model', $normalized['effective_model'])
                    ->orWhereNull('effective_model');
            });
        }

        return $query->latest('occurred_at')->first();
    }

    private function nullableString(mixed $value): ?string
    {
        $value = is_string($value) ? trim($value) : '';

        return $value !== '' ? $value : null;
    }
}
