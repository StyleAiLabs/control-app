<?php

namespace App\Services;

use App\Models\SkillCatalogVersion;
use App\Models\TenantSkillAssignment;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Collection;
use RuntimeException;

class TenantSkillRegistryService
{
    public function __construct(
        private readonly Filesystem $files,
    ) {
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function all(): array
    {
        return collect($this->repoSkills())
            ->mapWithKeys(fn (array $skill): array => [$skill['id'] => $skill])
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function repoSkills(): array
    {
        $skills = [];

        foreach ($this->scanRepository() as $entry) {
            if (! ($entry['manifest_valid'] ?? false)) {
                throw new RuntimeException((string) ($entry['error'] ?? 'Skill manifest is invalid.'));
            }

            $skills[] = $this->normalizedSkillDefinition(
                $entry['manifest_json'],
                (string) $entry['directory'],
            );
        }

        return $skills;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function scanRepository(?string $skillKey = null): array
    {
        $entries = [];
        $root = base_path('resources/skill-packs');

        if (! $this->files->isDirectory($root)) {
            return [];
        }

        $directories = $this->files->directories($root);
        sort($directories);

        foreach ($directories as $directory) {
            $directoryName = basename($directory);
            $requestedSkillKey = is_string($skillKey) && trim($skillKey) !== '' ? trim($skillKey) : null;

            $manifestPath = $directory.DIRECTORY_SEPARATOR.'manifest.json';

            if (! $this->files->exists($manifestPath)) {
                continue;
            }

            $manifest = json_decode($this->files->get($manifestPath), true);

            if (! is_array($manifest) || ! is_string($manifest['skill_id'] ?? null) || trim((string) $manifest['skill_id']) === '') {
                if ($requestedSkillKey !== null && $directoryName !== $requestedSkillKey) {
                    continue;
                }

                $entries[] = [
                    'skill_key' => $directoryName,
                    'directory' => $directory,
                    'manifest_path' => $manifestPath,
                    'manifest_valid' => false,
                    'error' => sprintf('Skill manifest [%s] is invalid.', $manifestPath),
                ];

                continue;
            }

            $normalized = $this->normalizedSkillDefinition($manifest, $directory);

            if ($requestedSkillKey !== null && ! in_array($requestedSkillKey, [$directoryName, $normalized['id']], true)) {
                continue;
            }

            $entries[] = array_merge($normalized, [
                'skill_key' => $normalized['id'],
                'directory' => $directory,
                'manifest_path' => $manifestPath,
                'manifest_valid' => true,
                'production_ready' => (bool) ($manifest['production_ready'] ?? true),
            ]);
        }

        return $entries;
    }

    /**
     * @return array<string, mixed>
     */
    public function find(string $skillId): array
    {
        $skill = $this->all()[$skillId] ?? null;

        if (! is_array($skill)) {
            throw new RuntimeException(sprintf('Unknown skill [%s].', $skillId));
        }

        return $skill;
    }

    /**
     * @param  Collection<int, TenantSkillAssignment>|array<int, TenantSkillAssignment>  $assignments
     * @return array<string, string>
     */
    public function renderedSkillFiles(Collection|array $assignments): array
    {
        $rendered = [];

        foreach ($assignments as $assignment) {
            if (! $assignment instanceof TenantSkillAssignment || ! $assignment->is_enabled) {
                continue;
            }

            $skill = $this->skillDefinitionForAssignment($assignment);
            $sourceRoot = (string) $skill['source_root'];
            $targetRoot = 'skills/'.$assignment->skill_key;

            foreach ($this->files->allFiles($sourceRoot) as $file) {
                $relativePath = ltrim(str_replace($sourceRoot, '', $file->getPathname()), DIRECTORY_SEPARATOR);

                if ($relativePath === 'manifest.json') {
                    continue;
                }

                $rendered[$targetRoot.'/'.$relativePath] = $this->files->get($file->getPathname());
            }
        }

        ksort($rendered);

        return $rendered;
    }

    /**
     * @param  Collection<int, TenantSkillAssignment>|array<int, TenantSkillAssignment>  $assignments
     * @return list<string>
     */
    public function openClawSkillIds(Collection|array $assignments): array
    {
        $skillIds = [];

        foreach ($assignments as $assignment) {
            if (! $assignment instanceof TenantSkillAssignment || ! $assignment->is_enabled) {
                continue;
            }

            foreach ((array) data_get($this->skillDefinitionForAssignment($assignment), 'openclaw_skill_ids', []) as $skillId) {
                if (is_string($skillId) && trim($skillId) !== '' && ! in_array(trim($skillId), $skillIds, true)) {
                    $skillIds[] = trim($skillId);
                }
            }
        }

        return $skillIds;
    }

    /**
     * @param  Collection<int, TenantSkillAssignment>|array<int, TenantSkillAssignment>  $assignments
     * @return list<string>
     */
    public function defaultAgentSkillIds(Collection|array $assignments): array
    {
        $skillIds = [];

        foreach ($assignments as $assignment) {
            if (! $assignment instanceof TenantSkillAssignment || ! $assignment->is_enabled) {
                continue;
            }

            foreach ((array) data_get($this->skillDefinitionForAssignment($assignment), 'default_agent_skill_ids', []) as $skillId) {
                if (is_string($skillId) && trim($skillId) !== '' && ! in_array(trim($skillId), $skillIds, true)) {
                    $skillIds[] = trim($skillId);
                }
            }
        }

        return $skillIds;
    }

    /**
     * @return array<string, mixed>
     */
    public function skillDefinitionForAssignment(TenantSkillAssignment $assignment): array
    {
        $version = $assignment->catalogVersion;

        if ($version instanceof SkillCatalogVersion && is_array($version->manifest_json) && $version->manifest_json !== []) {
            return array_merge($this->find($assignment->skill_key), $version->manifest_json, [
                'id' => $assignment->skill_key,
            ]);
        }

        return $this->find($assignment->skill_key);
    }

    /**
     * @param  mixed  $value
     * @return list<string>
     */
    private function normalizedStringList(mixed $value): array
    {
        $values = [];

        foreach (is_array($value) ? $value : [] as $item) {
            if (is_string($item) && trim($item) !== '' && ! in_array(trim($item), $values, true)) {
                $values[] = trim($item);
            }
        }

        return $values;
    }

    /**
     * @param  array<string, mixed>  $manifest
     * @return array<string, mixed>
     */
    private function normalizedSkillDefinition(array $manifest, string $directory): array
    {
        $skillId = trim((string) $manifest['skill_id']);

        return [
            'id' => $skillId,
            'label' => is_string($manifest['label'] ?? null) ? trim((string) $manifest['label']) : $skillId,
            'version' => is_string($manifest['version'] ?? null) ? trim((string) $manifest['version']) : '0.0.0',
            'description' => is_string($manifest['description'] ?? null) ? trim((string) $manifest['description']) : '',
            'category' => is_string($manifest['category'] ?? null) ? trim((string) $manifest['category']) : null,
            'applicable_industries' => $this->normalizedStringList($manifest['applicable_industries'] ?? []),
            'openclaw_skill_ids' => $this->normalizedStringList($manifest['openclaw_skill_ids'] ?? [$skillId]),
            'default_agent_skill_ids' => $this->normalizedStringList($manifest['default_agent_skill_ids'] ?? [$skillId]),
            'manifest_json' => $manifest,
            'source_root' => $directory,
        ];
    }
}
