<?php

namespace App\Services;

use App\Enums\TenantRuntimeUsageUseCase;
use App\Models\Tenant;
use App\Models\TenantRuntimeUsageEvent;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

class TenantRuntimeCostReportService
{
    /**
     * @return array<string, mixed>
     */
    public function adminSummary(string $window = '30d'): array
    {
        [$from, $resolvedWindow] = $this->windowStart($window);
        $query = TenantRuntimeUsageEvent::query()->where('occurred_at', '>=', $from);

        return [
            'window' => $resolvedWindow,
            'window_label' => strtoupper($resolvedWindow),
            'totals' => $this->aggregate($query),
            'by_use_case' => $query->clone()
                ->select('use_case')
                ->selectRaw('COALESCE(SUM(cost_amount), 0) as total_cost')
                ->selectRaw('COALESCE(SUM(request_count), 0) as total_requests')
                ->selectRaw('COALESCE(SUM(total_tokens), 0) as total_tokens')
                ->groupBy('use_case')
                ->orderByDesc('total_cost')
                ->get()
                ->map(fn ($row) => [
                    'use_case' => $this->useCaseFrom($row->use_case),
                    'total_cost' => (float) $row->total_cost,
                    'total_requests' => (int) $row->total_requests,
                    'total_tokens' => (int) $row->total_tokens,
                ]),
            'by_tenant' => $query->clone()
                ->join('tenants', 'tenants.id', '=', 'tenant_runtime_usage_events.tenant_id')
                ->select('tenant_runtime_usage_events.tenant_id', 'tenants.business_name', 'tenants.slug')
                ->selectRaw('COALESCE(SUM(tenant_runtime_usage_events.cost_amount), 0) as total_cost')
                ->selectRaw('COALESCE(SUM(tenant_runtime_usage_events.request_count), 0) as total_requests')
                ->selectRaw('COALESCE(SUM(tenant_runtime_usage_events.total_tokens), 0) as total_tokens')
                ->groupBy('tenant_runtime_usage_events.tenant_id', 'tenants.business_name', 'tenants.slug')
                ->orderByDesc('total_cost')
                ->get(),
            'by_model' => $query->clone()
                ->select('effective_model')
                ->selectRaw('COALESCE(SUM(cost_amount), 0) as total_cost')
                ->selectRaw('COALESCE(SUM(request_count), 0) as total_requests')
                ->selectRaw('COALESCE(SUM(total_tokens), 0) as total_tokens')
                ->groupBy('effective_model')
                ->orderByDesc('total_cost')
                ->get(),
            'recent_unmatched' => $query->clone()
                ->with('tenant')
                ->where('use_case', TenantRuntimeUsageUseCase::UnknownRuntime->value)
                ->latest('occurred_at')
                ->limit(20)
                ->get(),
            'recent_events' => $query->clone()
                ->with('tenant')
                ->latest('occurred_at')
                ->limit(20)
                ->get(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function tenantSummary(Tenant $tenant, string $window = '30d'): array
    {
        [$from, $resolvedWindow] = $this->windowStart($window);
        $query = TenantRuntimeUsageEvent::query()
            ->where('tenant_id', $tenant->id)
            ->where('occurred_at', '>=', $from);

        return [
            'window' => $resolvedWindow,
            'window_label' => strtoupper($resolvedWindow),
            'totals' => $this->aggregate($query),
            'by_use_case' => $query->clone()
                ->select('use_case')
                ->selectRaw('COALESCE(SUM(cost_amount), 0) as total_cost')
                ->selectRaw('COALESCE(SUM(request_count), 0) as total_requests')
                ->selectRaw('COALESCE(SUM(total_tokens), 0) as total_tokens')
                ->groupBy('use_case')
                ->orderByDesc('total_cost')
                ->get()
                ->map(fn ($row) => [
                    'use_case' => $this->useCaseFrom($row->use_case),
                    'total_cost' => (float) $row->total_cost,
                    'total_requests' => (int) $row->total_requests,
                    'total_tokens' => (int) $row->total_tokens,
                ]),
            'by_model' => $query->clone()
                ->select('effective_model')
                ->selectRaw('COALESCE(SUM(cost_amount), 0) as total_cost')
                ->selectRaw('COALESCE(SUM(request_count), 0) as total_requests')
                ->selectRaw('COALESCE(SUM(total_tokens), 0) as total_tokens')
                ->groupBy('effective_model')
                ->orderByDesc('total_cost')
                ->get(),
            'recent_events' => $query->clone()
                ->with('dispatch')
                ->latest('occurred_at')
                ->limit(20)
                ->get(),
        ];
    }

    /**
     * @return array{0:Carbon,1:string}
     */
    private function windowStart(string $window): array
    {
        $window = strtolower(trim($window));

        return match ($window) {
            '7d' => [now()->subDays(7), '7d'],
            '30d' => [now()->subDays(30), '30d'],
            '90d' => [now()->subDays(90), '90d'],
            'ytd' => [now()->startOfYear(), 'ytd'],
            default => [now()->subDays(30), '30d'],
        };
    }

    /**
     * @return array{cost_amount:float,request_count:int,total_tokens:int,tenant_count:int}
     */
    private function aggregate(Builder $query): array
    {
        $aggregate = $query->clone()
            ->selectRaw('COALESCE(SUM(cost_amount), 0) as total_cost')
            ->selectRaw('COALESCE(SUM(request_count), 0) as total_requests')
            ->selectRaw('COALESCE(SUM(total_tokens), 0) as total_tokens')
            ->selectRaw('COUNT(DISTINCT tenant_id) as tenant_count')
            ->first();

        return [
            'cost_amount' => round((float) ($aggregate?->total_cost ?? 0), 2),
            'request_count' => (int) ($aggregate?->total_requests ?? 0),
            'total_tokens' => (int) ($aggregate?->total_tokens ?? 0),
            'tenant_count' => (int) ($aggregate?->tenant_count ?? 0),
        ];
    }

    private function useCaseFrom(mixed $value): TenantRuntimeUsageUseCase
    {
        if ($value instanceof TenantRuntimeUsageUseCase) {
            return $value;
        }

        return TenantRuntimeUsageUseCase::tryFrom((string) $value) ?? TenantRuntimeUsageUseCase::UnknownRuntime;
    }
}
