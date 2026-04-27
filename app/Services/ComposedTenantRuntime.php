<?php

namespace App\Services;

class ComposedTenantRuntime
{
    /**
     * @param  array<string, string>  $workspaceFiles
     * @param  array<string, string>  $skillFiles
     * @param  array<string, bool>  $baseDrifted
     * @param  array<int, string>  $workspaceFileManifest
     */
    public function __construct(
        public readonly array $workspaceFiles,
        public readonly array $skillFiles,
        public readonly string $openClawConfig,
        public readonly array $baseDrifted,
        public readonly array $workspaceFileManifest,
        public readonly string $contentHash,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function diagnosticPayload(): array
    {
        return [
            'workspace_files' => $this->diagnosticWorkspaceFiles(),
            'skill_files' => array_keys($this->skillFiles),
            'openclaw_config' => $this->openClawConfig,
            'base_drifted' => $this->baseDrifted,
            'workspace_file_manifest' => $this->workspaceFileManifest,
            'content_hash' => $this->contentHash,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function diagnosticWorkspaceFiles(): array
    {
        $payload = [];

        foreach ($this->workspaceFiles as $path => $contents) {
            if ($this->isValidUtf8($contents)) {
                $payload[$path] = $contents;

                continue;
            }

            $payload[$path] = [
                'kind' => 'binary',
                'size_bytes' => strlen($contents),
                'sha256' => hash('sha256', $contents),
            ];
        }

        return $payload;
    }

    private function isValidUtf8(string $contents): bool
    {
        return preg_match('//u', $contents) === 1;
    }
}
