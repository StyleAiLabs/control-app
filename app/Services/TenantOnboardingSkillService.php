<?php

namespace App\Services;

use App\Models\SkillCatalogItem;
use App\Models\Tenant;
use App\Models\TenantSkillAssignment;
use Illuminate\Support\Collection;

class TenantOnboardingSkillService
{
    public const ROLE_CORE = 'core';
    public const ROLE_FEATURED = 'featured';
    public const ROLE_HIDDEN = 'hidden';

    /**
     * @return list<string>
     */
    public static function roles(): array
    {
        return [
            self::ROLE_CORE,
            self::ROLE_FEATURED,
            self::ROLE_HIDDEN,
        ];
    }

    /**
     * @return array{core: Collection<int, SkillCatalogItem>, featured: Collection<int, SkillCatalogItem>}
     */
    public function catalog(): array
    {
        $items = SkillCatalogItem::query()
            ->with('activePublishedVersion')
            ->where('is_assignable', true)
            ->where('is_orphaned', false)
            ->whereIn('onboarding_role', [self::ROLE_CORE, self::ROLE_FEATURED])
            ->orderBy('label')
            ->get();

        return [
            'core' => $items->where('onboarding_role', self::ROLE_CORE)->values(),
            'featured' => $items->where('onboarding_role', self::ROLE_FEATURED)->values(),
        ];
    }

    public function ensureCoreAssignments(Tenant $tenant, ?int $assignedBy = null): void
    {
        foreach ($this->catalog()['core'] as $item) {
            $version = $item->activePublishedVersion;

            if (! $version) {
                continue;
            }

            TenantSkillAssignment::query()->updateOrCreate(
                [
                    'tenant_id' => $tenant->id,
                    'skill_key' => $item->skill_key,
                ],
                [
                    'skill_catalog_version_id' => $version->id,
                    'assigned_by' => $assignedBy,
                    'assigned_at' => now(),
                    'is_enabled' => true,
                ],
            );
        }
    }

    /**
     * @param  array<int, string>  $selectedFeaturedSkillKeys
     */
    public function syncFeaturedAssignments(Tenant $tenant, array $selectedFeaturedSkillKeys, ?int $assignedBy = null): void
    {
        $featuredItems = $this->catalog()['featured'];
        $selected = collect($selectedFeaturedSkillKeys)
            ->filter(fn (mixed $skillKey): bool => is_string($skillKey) && trim($skillKey) !== '')
            ->map(fn (string $skillKey): string => trim($skillKey))
            ->intersect($featuredItems->pluck('skill_key'))
            ->values();

        foreach ($featuredItems as $item) {
            $version = $item->activePublishedVersion;

            if (! $version) {
                continue;
            }

            TenantSkillAssignment::query()->updateOrCreate(
                [
                    'tenant_id' => $tenant->id,
                    'skill_key' => $item->skill_key,
                ],
                [
                    'skill_catalog_version_id' => $version->id,
                    'assigned_by' => $assignedBy,
                    'assigned_at' => now(),
                    'is_enabled' => $selected->contains($item->skill_key),
                ],
            );
        }
    }

    /**
     * @return Collection<int, TenantSkillAssignment>
     */
    public function enabledAssignments(Tenant $tenant): Collection
    {
        return $tenant->skillAssignments()
            ->with('catalogVersion.item')
            ->where('is_enabled', true)
            ->orderBy('skill_key')
            ->get();
    }

    /**
     * @return list<string>
     */
    public function enabledSkillKeys(Tenant $tenant): array
    {
        return $this->enabledAssignments($tenant)
            ->pluck('skill_key')
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @return list<string>
     */
    public function selectedFeaturedSkillKeys(Tenant $tenant): array
    {
        return $this->enabledAssignments($tenant)
            ->filter(fn (TenantSkillAssignment $assignment): bool => ($assignment->catalogVersion?->item?->onboarding_role ?? null) === self::ROLE_FEATURED)
            ->pluck('skill_key')
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @return list<array{skill_key:string,label:string,description:?string,onboarding_role:string}>
     */
    public function enabledModules(Tenant $tenant): array
    {
        return $this->enabledAssignments($tenant)
            ->map(function (TenantSkillAssignment $assignment): array {
                $item = $assignment->catalogVersion?->item;

                return [
                    'skill_key' => $assignment->skill_key,
                    'label' => $item?->label ?: $assignment->skill_key,
                    'description' => $item?->description,
                    'onboarding_role' => $item?->onboarding_role ?: self::ROLE_HIDDEN,
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @return array{
     *   core:list<array{skill_key:string,label:string,description:?string}>,
     *   featured:list<array{skill_key:string,label:string,description:?string}>,
     *   selected_featured_skill_keys:list<string>,
     *   enabled_skill_keys:list<string>
     * }
     */
    public function onboardingPayload(Tenant $tenant): array
    {
        $this->ensureCoreAssignments($tenant, $tenant->user_id);
        $catalog = $this->catalog();
        $serialize = fn (Collection $items): array => $items
            ->map(fn (SkillCatalogItem $item): array => [
                'skill_key' => $item->skill_key,
                'label' => $item->label,
                'description' => $item->description,
            ])
            ->values()
            ->all();

        return [
            'core' => $serialize($catalog['core']),
            'featured' => $serialize($catalog['featured']),
            'selected_featured_skill_keys' => $this->selectedFeaturedSkillKeys($tenant),
            'enabled_skill_keys' => $this->enabledSkillKeys($tenant),
        ];
    }

    /**
     * @return array{core_count:int,featured_count:int,core_labels:list<string>,featured_labels:list<string>}
     */
    public function customerSummary(Tenant $tenant): array
    {
        $modules = collect($this->enabledModules($tenant));
        $core = $modules->where('onboarding_role', self::ROLE_CORE)->values();
        $featured = $modules->where('onboarding_role', self::ROLE_FEATURED)->values();

        return [
            'core_count' => $core->count(),
            'featured_count' => $featured->count(),
            'core_labels' => $core->pluck('label')->values()->all(),
            'featured_labels' => $featured->pluck('label')->values()->all(),
        ];
    }
}
