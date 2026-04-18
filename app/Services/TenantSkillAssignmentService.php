<?php

namespace App\Services;

use App\Models\SkillCatalogVersion;
use App\Models\Tenant;
use App\Models\TenantSkillAssignment;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class TenantSkillAssignmentService
{
    public function __construct(
        private readonly SkillCatalogService $catalog,
        private readonly TenantSkillRegistryService $registry,
    ) {
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{assigned_skill_keys: list<string>}
     */
    public function normalizedPayload(array $input): array
    {
        $assignedSkillKeys = [];

        foreach ((array) ($input['assigned_skill_keys'] ?? []) as $skillKey) {
            if (is_string($skillKey) && trim($skillKey) !== '' && ! in_array(trim($skillKey), $assignedSkillKeys, true)) {
                $assignedSkillKeys[] = trim($skillKey);
            }
        }

        return [
            'assigned_skill_keys' => $assignedSkillKeys,
        ];
    }

    /**
     * @param  list<string>  $assignedSkillKeys
     */
    public function saveDraftAssignments(Tenant $tenant, User $actor, array $assignedSkillKeys): void
    {
        DB::transaction(function () use ($tenant, $actor, $assignedSkillKeys): void {
            $existing = $tenant->skillAssignments()->get()->keyBy('skill_key');

            foreach ($assignedSkillKeys as $skillKey) {
                $assignment = $existing->get($skillKey);
                $publishedVersion = $this->catalog->activePublishedVersion($skillKey);

                if (! $publishedVersion) {
                    throw new RuntimeException(sprintf('Skill [%s] does not have an active published version.', $skillKey));
                }

                TenantSkillAssignment::query()->updateOrCreate(
                    ['tenant_id' => $tenant->id, 'skill_key' => $skillKey],
                    [
                        'skill_catalog_version_id' => $assignment?->skill_catalog_version_id ?: $publishedVersion->id,
                        'assigned_by' => $actor->id,
                        'assigned_at' => $assignment?->assigned_at ?: now(),
                        'is_enabled' => true,
                    ],
                );
            }

            $tenant->skillAssignments()
                ->whereNotIn('skill_key', $assignedSkillKeys)
                ->update([
                    'is_enabled' => false,
                    'assigned_by' => $actor->id,
                ]);
        });
    }

    /**
     * @return Collection<int, TenantSkillAssignment>
     */
    public function enabledAssignments(Tenant $tenant): Collection
    {
        return $tenant->skillAssignments()
            ->where('is_enabled', true)
            ->with('catalogVersion.item')
            ->orderBy('skill_key')
            ->get();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function snapshot(Tenant $tenant): array
    {
        return $this->enabledAssignments($tenant)
            ->map(function (TenantSkillAssignment $assignment): array {
                $skill = $this->registry->skillDefinitionForAssignment($assignment);

                return [
                    'skill_key' => $assignment->skill_key,
                    'version' => $assignment->catalogVersion?->version,
                    'skill_catalog_version_id' => $assignment->skill_catalog_version_id,
                    'label' => $skill['label'] ?? $assignment->skill_key,
                    'openclaw_skill_ids' => $skill['openclaw_skill_ids'] ?? [],
                    'default_agent_skill_ids' => $skill['default_agent_skill_ids'] ?? [],
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @param  array<int, array<string, mixed>>  $snapshot
     */
    public function restoreFromSnapshot(Tenant $tenant, User $actor, array $snapshot): void
    {
        DB::transaction(function () use ($tenant, $actor, $snapshot): void {
            $enabledKeys = [];

            foreach ($snapshot as $entry) {
                $skillKey = is_string($entry['skill_key'] ?? null) ? trim((string) $entry['skill_key']) : '';

                if ($skillKey === '') {
                    continue;
                }

                $enabledKeys[] = $skillKey;
                $catalogVersionId = $entry['skill_catalog_version_id'] ?? null;
                $catalogVersion = $catalogVersionId
                    ? SkillCatalogVersion::query()->find($catalogVersionId)
                    : SkillCatalogVersion::query()
                        ->where('skill_key', $skillKey)
                        ->where('version', $entry['version'] ?? null)
                        ->first();

                TenantSkillAssignment::query()->updateOrCreate(
                    ['tenant_id' => $tenant->id, 'skill_key' => $skillKey],
                    [
                        'skill_catalog_version_id' => $catalogVersion?->id,
                        'assigned_by' => $actor->id,
                        'assigned_at' => now(),
                        'is_enabled' => true,
                    ],
                );
            }

            $tenant->skillAssignments()
                ->whereNotIn('skill_key', $enabledKeys)
                ->update([
                    'is_enabled' => false,
                    'assigned_by' => $actor->id,
                ]);
        });
    }

    public function markApplyResult(Tenant $tenant, string $status, ?string $error = null): void
    {
        $tenant->skillAssignments()->update([
            'last_apply_status' => $status,
            'last_apply_error' => $error,
            'last_applied_at' => $status === 'applied' ? now() : null,
        ]);
    }
}
