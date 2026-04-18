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
            'workspace_files' => $this->workspaceFiles,
            'skill_files' => array_keys($this->skillFiles),
            'openclaw_config' => $this->openClawConfig,
            'base_drifted' => $this->baseDrifted,
            'workspace_file_manifest' => $this->workspaceFileManifest,
            'content_hash' => $this->contentHash,
        ];
    }
}
