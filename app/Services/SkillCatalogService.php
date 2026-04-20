<?php

namespace App\Services;

use App\Models\SkillCatalogItem;
use App\Models\SkillCatalogVersion;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class SkillCatalogService
{
    public function __construct(
        private readonly TenantSkillRegistryService $registry,
    ) {
    }

    /**
     * @return array{
     *     rows: list<array<string, mixed>>,
     *     missing: list<string>
     * }
     */
    public function scanRepository(?string $skillKey = null): array
    {
        $requestedSkillKey = is_string($skillKey) && trim($skillKey) !== '' ? trim($skillKey) : null;
        $catalogItems = SkillCatalogItem::query()
            ->with('versions')
            ->get()
            ->keyBy('skill_key');
        $rows = [];

        foreach ($this->registry->scanRepository($requestedSkillKey) as $entry) {
            $catalogItem = $catalogItems->get($entry['skill_key']);
            $manifestValid = (bool) ($entry['manifest_valid'] ?? false);
            $productionReady = $manifestValid ? (bool) ($entry['production_ready'] ?? true) : false;
            $importable = $manifestValid && ($this->isLocalEnvironment() || $productionReady);

            $rows[] = [
                'skill_key' => $entry['skill_key'],
                'label' => $entry['label'] ?? $entry['skill_key'],
                'version' => $entry['version'] ?? null,
                'manifest_valid' => $manifestValid,
                'production_ready' => $productionReady,
                'catalog_state' => $this->catalogStateFor($catalogItem, $entry),
                'status' => $this->scanStatusFor($catalogItem, $entry, $importable),
                'importable' => $importable,
                'environment_label' => $this->environmentLabelFor($manifestValid, $productionReady),
                'error' => $entry['error'] ?? null,
                'description' => $entry['description'] ?? null,
                'category' => $entry['category'] ?? null,
                'manifest_json' => $entry['manifest_json'] ?? null,
            ];
        }

        return [
            'rows' => $rows,
            'missing' => $requestedSkillKey && $rows === [] ? [$requestedSkillKey] : [],
        ];
    }

    /**
     * @param  null|list<string>  $skillKeys
     * @return array{
     *     imported: list<string>,
     *     skipped: list<string>,
     *     missing: list<string>,
     *     orphaned: list<string>
     * }
     */
    public function importFromRepository(?array $skillKeys = null): array
    {
        $requestedSkillKeys = collect($skillKeys ?? [])
            ->filter(fn ($skillKey): bool => is_string($skillKey) && trim($skillKey) !== '')
            ->map(fn (string $skillKey): string => trim($skillKey))
            ->unique()
            ->values();
        $selectedMode = $requestedSkillKeys->isNotEmpty();
        $scan = $selectedMode
            ? ['rows' => [], 'missing' => []]
            : $this->scanRepository();

        if ($selectedMode) {
            foreach ($requestedSkillKeys as $requestedSkillKey) {
                $result = $this->scanRepository($requestedSkillKey);
                $scan['rows'] = [...$scan['rows'], ...$result['rows']];
                $scan['missing'] = [...$scan['missing'], ...$result['missing']];
            }
        }

        $rows = collect($scan['rows'])->keyBy('skill_key');
        $imported = [];
        $skipped = [];

        DB::transaction(function () use ($rows, $selectedMode, &$imported, &$skipped): void {
            foreach ($rows as $skillKey => $row) {
                if (! ($row['manifest_valid'] ?? false) || ! ($row['importable'] ?? false)) {
                    $skipped[] = (string) $skillKey;

                    continue;
                }

                $versionString = (string) ($row['version'] ?? '0.0.0');
                $item = SkillCatalogItem::query()->updateOrCreate(
                    ['skill_key' => $skillKey],
                    [
                        'label' => $row['label'] ?? $skillKey,
                        'description' => $row['description'] ?? null,
                        'category' => $row['category'] ?? null,
                        'is_assignable' => false,
                        'is_orphaned' => false,
                        'orphaned_warning' => null,
                        'last_imported_at' => now(),
                    ],
                );

                $version = SkillCatalogVersion::query()->firstOrNew([
                    'skill_catalog_item_id' => $item->id,
                    'version' => $versionString,
                ]);

                $version->forceFill([
                    'skill_key' => $skillKey,
                    'manifest_json' => is_array($row['manifest_json'] ?? null) ? $row['manifest_json'] : [],
                    'is_available' => true,
                    'discovered_at' => $version->exists ? $version->discovered_at : now(),
                    'last_imported_at' => now(),
                ])->save();

                SkillCatalogVersion::query()
                    ->where('skill_catalog_item_id', $item->id)
                    ->whereKeyNot($version->id)
                    ->whereNotIn('version', [$versionString])
                    ->update(['is_available' => false]);

                $this->syncItemAssignability($item);

                $imported[] = sprintf('%s@%s', $skillKey, $versionString);
            }

            if (! $selectedMode) {
                $this->markOrphanedCatalogItems($rows->keys()->all());
            }
        });

        return [
            'imported' => $imported,
            'skipped' => array_values(array_unique($skipped)),
            'missing' => array_values(array_unique($scan['missing'])),
            'orphaned' => SkillCatalogItem::query()
                ->where('is_orphaned', true)
                ->orderBy('skill_key')
                ->pluck('skill_key')
                ->all(),
        ];
    }

    public function publishVersion(SkillCatalogVersion $version): void
    {
        DB::transaction(function () use ($version): void {
            SkillCatalogVersion::query()
                ->where('skill_catalog_item_id', $version->skill_catalog_item_id)
                ->update(['is_active_published' => false]);

            $version->forceFill([
                'is_active_published' => true,
                'is_archived' => false,
                'is_available' => true,
            ])->save();

            $this->syncItemAssignability($version->item);
        });
    }

    public function archiveVersion(SkillCatalogVersion $version): void
    {
        DB::transaction(function () use ($version): void {
            $version->forceFill([
                'is_active_published' => false,
                'is_archived' => true,
            ])->save();

            $this->syncItemAssignability($version->item);
        });
    }

    public function activePublishedVersion(string $skillKey): ?SkillCatalogVersion
    {
        return SkillCatalogVersion::query()
            ->where('skill_key', $skillKey)
            ->where('is_active_published', true)
            ->where('is_archived', false)
            ->first();
    }

    /**
     * @return Collection<int, SkillCatalogItem>
     */
    public function catalog(): Collection
    {
        return SkillCatalogItem::query()
            ->with(['activePublishedVersion', 'versions' => fn ($query) => $query->latest('id')])
            ->orderBy('label')
            ->get();
    }

    /**
     * @param  array<string, mixed>  $entry
     */
    private function catalogStateFor(?SkillCatalogItem $catalogItem, array $entry): string
    {
        if (! $catalogItem) {
            return 'not imported';
        }

        if ($catalogItem->is_orphaned) {
            return 'orphaned in db';
        }

        $version = (string) ($entry['version'] ?? '');
        $hasVersion = $catalogItem->versions->contains(fn (SkillCatalogVersion $candidate): bool => $candidate->version === $version);

        return $hasVersion ? 'imported' : 'imported, newer version available';
    }

    /**
     * @param  array<string, mixed>  $entry
     */
    private function scanStatusFor(?SkillCatalogItem $catalogItem, array $entry, bool $importable): string
    {
        if (! ($entry['manifest_valid'] ?? false)) {
            return 'invalid manifest';
        }

        if (! $importable) {
            return 'local only';
        }

        if (! $catalogItem) {
            return 'new';
        }

        if ($catalogItem->is_orphaned) {
            return 'orphaned in db';
        }

        $version = (string) ($entry['version'] ?? '');
        $hasVersion = $catalogItem->versions->contains(fn (SkillCatalogVersion $candidate): bool => $candidate->version === $version);

        return $hasVersion ? 'already imported' : 'version update available';
    }

    private function environmentLabelFor(bool $manifestValid, bool $productionReady): string
    {
        if (! $manifestValid) {
            return 'not importable';
        }

        if (! $this->isLocalEnvironment() && ! $productionReady) {
            return 'not importable on live';
        }

        return 'eligible';
    }

    /**
     * @param  list<string>  $presentSkillKeys
     */
    private function markOrphanedCatalogItems(array $presentSkillKeys): void
    {
        $existingItems = SkillCatalogItem::query()->get()->keyBy('skill_key');

        foreach ($existingItems as $skillKey => $item) {
            if (in_array($skillKey, $presentSkillKeys, true)) {
                continue;
            }

            $item->forceFill([
                'is_assignable' => false,
                'is_orphaned' => true,
                'orphaned_warning' => sprintf('Skill [%s] is missing from resources/skill-packs and is now orphaned.', $skillKey),
                'last_imported_at' => now(),
            ])->save();

            $item->versions()->update(['is_available' => false]);
        }
    }

    private function syncItemAssignability(SkillCatalogItem $item): void
    {
        $hasActivePublishedVersion = SkillCatalogVersion::query()
            ->where('skill_catalog_item_id', $item->id)
            ->where('is_active_published', true)
            ->where('is_archived', false)
            ->exists();

        $item->forceFill([
            'is_assignable' => ! $item->is_orphaned && $hasActivePublishedVersion,
        ])->save();
    }

    private function isLocalEnvironment(): bool
    {
        return app()->environment(['local', 'testing']);
    }
}
