<?php

namespace App\Services;

class ComposedTenantRuntime
{
    /**
     * @param  array<string, string>  $workspaceFiles
     * @param  array<string, bool>  $baseDrifted
     * @param  array<int, string>  $workspaceFileManifest
     */
    public function __construct(
        public readonly array $workspaceFiles,
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
            'openclaw_config' => $this->openClawConfig,
            'base_drifted' => $this->baseDrifted,
            'workspace_file_manifest' => $this->workspaceFileManifest,
            'content_hash' => $this->contentHash,
        ];
    }
}
