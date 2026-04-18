<?php

namespace App\Services;

use Illuminate\Filesystem\Filesystem;
use RuntimeException;

class TenantSkillRegistryService
{
    public function __construct(
        private readonly Filesystem $files,
    ) {}

    /**
     * @return array<string, array<string, mixed>>
     */
    public function all(): array
    {
        $registry = config('sync360.skill_registry', []);

        return is_array($registry) ? $registry : [];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function resolve(array $packIds): array
    {
        $packs = [];

        foreach ($packIds as $packId) {
            if (! is_string($packId) || trim($packId) === '') {
                continue;
            }

            $packs[] = $this->find(trim($packId));
        }

        return $packs;
    }

    /**
     * @return array<string, mixed>
     */
    public function find(string $packId): array
    {
        $pack = $this->all()[$packId] ?? null;

        if (! is_array($pack)) {
            throw new RuntimeException(sprintf('Unknown skill pack [%s].', $packId));
        }

        return $pack;
    }

    /**
     * @param  list<array<string, mixed>>  $packs
     * @return list<string>
     */
    public function openClawSkillIds(array $packs): array
    {
        return $this->uniqueStrings($packs, 'openclaw_skill_ids');
    }

    /**
     * @param  list<array<string, mixed>>  $packs
     * @return list<string>
     */
    public function defaultAgentSkillIds(array $packs): array
    {
        return $this->uniqueStrings($packs, 'default_agent_skill_ids');
    }

    public function materializeAssignedPacks(string $workspacePath, array $packIds): void
    {
        $root = rtrim($workspacePath, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.'skill-packs';
        $this->files->deleteDirectory($root);
        $this->files->ensureDirectoryExists($root);

        foreach ($this->resolve($packIds) as $pack) {
            $packId = (string) $pack['id'];
            $sourceRoot = base_path('resources/skill-packs/'.$packId);
            $targetRoot = $root.DIRECTORY_SEPARATOR.$packId;

            foreach ((array) ($pack['workspace_files'] ?? []) as $relativePath) {
                if (! is_string($relativePath) || trim($relativePath) === '') {
                    continue;
                }

                $relativePath = trim($relativePath, '/');
                $sourcePath = $sourceRoot.DIRECTORY_SEPARATOR.$relativePath;
                $targetPath = $targetRoot.DIRECTORY_SEPARATOR.$relativePath;

                if ($this->files->isDirectory($sourcePath)) {
                    $this->files->copyDirectory($sourcePath, $targetPath);
                    continue;
                }

                if (! $this->files->exists($sourcePath)) {
                    throw new RuntimeException(sprintf('Skill pack asset [%s] is missing for [%s].', $relativePath, $packId));
                }

                $this->files->ensureDirectoryExists(dirname($targetPath));
                $this->files->copy($sourcePath, $targetPath);
            }
        }
    }

    /**
     * @param  list<array<string, mixed>>  $packs
     * @return list<string>
     */
    private function uniqueStrings(array $packs, string $field): array
    {
        $values = [];

        foreach ($packs as $pack) {
            foreach ((array) ($pack[$field] ?? []) as $value) {
                if (is_string($value) && trim($value) !== '' && ! in_array(trim($value), $values, true)) {
                    $values[] = trim($value);
                }
            }
        }

        return $values;
    }
}
