<?php

namespace App\Services;

use App\Models\Tenant;
use App\Models\TenantSkillConversionEvent;

class TenantSkillAnalyticsReportService
{
    /**
     * @return array<string, mixed>
     */
    public function tenantSummary(Tenant $tenant, int $days = 30): array
    {
        $from = now()->subDays(max(1, $days));
        $query = TenantSkillConversionEvent::query()
            ->where('tenant_id', $tenant->id)
            ->where('occurred_at', '>=', $from);

        $aggregate = $query->clone()
            ->selectRaw('COUNT(*) as conversions')
            ->selectRaw('COALESCE(SUM(human_effort_minutes), 0) as human_minutes')
            ->selectRaw('COALESCE(SUM(agent_effort_minutes), 0) as agent_minutes')
            ->selectRaw('COALESCE(SUM(net_minutes_saved), 0) as net_minutes')
            ->selectRaw('SUM(estimated_value_amount) as estimated_value')
            ->first();

        $topSkills = $query->clone()
            ->select('skill_key')
            ->selectRaw('COUNT(*) as conversions')
            ->selectRaw('COALESCE(SUM(net_minutes_saved), 0) as net_minutes_saved')
            ->selectRaw('SUM(estimated_value_amount) as estimated_value')
            ->groupBy('skill_key')
            ->orderByDesc('conversions')
            ->limit(5)
            ->get();

        $recentEvents = $query->clone()
            ->latest('occurred_at')
            ->limit(10)
            ->get();

        $conversions = (int) ($aggregate?->conversions ?? 0);
        $humanMinutes = (int) ($aggregate?->human_minutes ?? 0);
        $agentMinutes = (int) ($aggregate?->agent_minutes ?? 0);
        $netMinutes = (int) ($aggregate?->net_minutes ?? 0);
        $estimatedValue = $aggregate?->estimated_value !== null ? round((float) $aggregate->estimated_value, 2) : null;

        return [
            'window_days' => $days,
            'conversions' => $conversions,
            'estimated_human_minutes' => $humanMinutes,
            'estimated_agent_minutes' => $agentMinutes,
            'estimated_net_minutes' => $netMinutes,
            'productivity_score' => $conversions,
            'estimated_value' => $estimatedValue,
            'estimated_roi_ratio' => $agentMinutes > 0 ? round($humanMinutes / max(1, $agentMinutes), 1) : null,
            'has_estimated_value' => $estimatedValue !== null,
            'top_skills' => $topSkills,
            'recent_events' => $recentEvents,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function adminSummary(int $days = 30): array
    {
        $from = now()->subDays(max(1, $days));
        $baseQuery = TenantSkillConversionEvent::query()->where('occurred_at', '>=', $from);

        $aggregate = $baseQuery->clone()
            ->selectRaw('COUNT(*) as conversions')
            ->selectRaw('COUNT(DISTINCT tenant_id) as tenants')
            ->selectRaw('COALESCE(SUM(net_minutes_saved), 0) as net_minutes')
            ->selectRaw('SUM(estimated_value_amount) as estimated_value')
            ->first();

        $perSkill = $baseQuery->clone()
            ->select('skill_key')
            ->selectRaw('COUNT(*) as conversions')
            ->selectRaw('COUNT(DISTINCT tenant_id) as tenants')
            ->selectRaw('COALESCE(SUM(net_minutes_saved), 0) as net_minutes_saved')
            ->selectRaw('SUM(estimated_value_amount) as estimated_value')
            ->groupBy('skill_key')
            ->orderByDesc('conversions')
            ->get();

        $perTenant = $baseQuery->clone()
            ->join('tenants', 'tenants.id', '=', 'tenant_skill_conversion_events.tenant_id')
            ->select('tenant_skill_conversion_events.tenant_id', 'tenants.business_name', 'tenants.slug')
            ->selectRaw('COUNT(*) as conversions')
            ->selectRaw('COALESCE(SUM(tenant_skill_conversion_events.net_minutes_saved), 0) as net_minutes_saved')
            ->selectRaw('SUM(tenant_skill_conversion_events.estimated_value_amount) as estimated_value')
            ->groupBy('tenant_skill_conversion_events.tenant_id', 'tenants.business_name', 'tenants.slug')
            ->orderByDesc('conversions')
            ->get();

        $recentEvents = $baseQuery->clone()
            ->with('tenant')
            ->latest('occurred_at')
            ->limit(20)
            ->get();

        return [
            'window_days' => $days,
            'conversions' => (int) ($aggregate?->conversions ?? 0),
            'tenants' => (int) ($aggregate?->tenants ?? 0),
            'estimated_net_minutes' => (int) ($aggregate?->net_minutes ?? 0),
            'estimated_value' => $aggregate?->estimated_value !== null ? round((float) $aggregate->estimated_value, 2) : null,
            'has_estimated_value' => $aggregate?->estimated_value !== null,
            'per_skill' => $perSkill,
            'per_tenant' => $perTenant,
            'recent_events' => $recentEvents,
        ];
    }
}
