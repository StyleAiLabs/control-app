<?php

namespace App\Services;

use App\Contracts\DockerComposeRunner;
use App\Enums\ProvisioningJobStatus;
use App\Models\ProvisioningJob;
use App\Models\Tenant;
use App\Models\TenantAgentCustomization;
use App\Models\TenantAgentCustomizationApply;
use App\Models\User;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

class TenantAgentCustomizationService
{
    public function __construct(
        private readonly Filesystem $files,
        private readonly DockerComposeRunner $dockerCompose,
        private readonly TenantRuntimeService $runtime,
        private readonly TenantSkillAssignmentService $skillAssignments,
    ) {
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function saveDraft(Tenant $tenant, User $actor, array $payload): TenantAgentCustomization
    {
        $customization = $tenant->agentCustomization ?: new TenantAgentCustomization([
            'tenant_id' => $tenant->id,
        ]);

        DB::transaction(function () use ($tenant, $actor, $payload, $customization): void {
            $customization->forceFill([
                'prompt_overrides_json' => $payload['prompt_overrides'],
                'agent_defaults_json' => $payload['agent_defaults'],
                'draft_version' => ((int) $customization->draft_version) + 1,
                'draft_updated_by' => $actor->id,
                'draft_updated_at' => now(),
            ])->save();

            if (array_key_exists('assigned_skill_keys', $payload)) {
                $this->skillAssignments->saveDraftAssignments($tenant, $actor, $payload['assigned_skill_keys']);
            }
        });

        return $customization->fresh();
    }

    /**
     * @return array<string, mixed>
     */
    public function normalizedPayload(Tenant $tenant, array $input): array
    {
        $tenant->loadMissing('businessProfileFiles');

        $promptOverrides = [];

        foreach (['identity', 'soul', 'user', 'bootstrap'] as $key) {
            $override = $input['prompt_overrides'][$key] ?? null;

            if (! is_array($override)) {
                continue;
            }

            $mode = is_string($override['mode'] ?? null) ? trim((string) $override['mode']) : '';
            $content = is_string($override['content'] ?? null) ? trim((string) $override['content']) : '';

            if (! in_array($mode, ['append', 'replace'], true) || $content === '') {
                continue;
            }

            $promptOverrides[$key] = [
                'mode' => $mode,
                'content' => $content,
                'base_snapshot' => trim($this->basePromptContent($tenant, $key)),
            ];
        }

        $agentDefaults = [];

        if (is_string($input['agent_defaults']['model'] ?? null) && trim((string) $input['agent_defaults']['model']) !== '') {
            $agentDefaults['model'] = trim((string) $input['agent_defaults']['model']);
        }

        $defaultSkillIds = [];

        foreach ((array) ($input['agent_defaults']['default_skill_ids'] ?? []) as $skillId) {
            if (! is_string($skillId) || trim($skillId) === '') {
                continue;
            }

            foreach (explode(',', $skillId) as $token) {
                $normalized = trim($token);

                if ($normalized !== '' && ! in_array($normalized, $defaultSkillIds, true)) {
                    $defaultSkillIds[] = $normalized;
                }
            }
        }

        if ($defaultSkillIds !== []) {
            $agentDefaults['default_skill_ids'] = $defaultSkillIds;
        }

        return array_merge([
            'prompt_overrides' => $promptOverrides,
            'agent_defaults' => $agentDefaults,
        ], $this->skillAssignments->normalizedPayload($input));
    }

    /**
     * @return array<string, mixed>
     */
    public function draftInputSnapshot(Tenant $tenant, TenantAgentCustomization $customization): array
    {
        return [
            'prompt_overrides' => is_array($customization->prompt_overrides_json) ? $customization->prompt_overrides_json : [],
            'assigned_skills' => $this->skillAssignments->snapshot($tenant),
            'agent_defaults' => is_array($customization->agent_defaults_json) ? $customization->agent_defaults_json : [],
        ];
    }

    public function apply(
        Tenant $tenant,
        ProvisioningJob $provisioningJob,
        TenantRuntimeCustomizationComposer $composer,
        string $action = TenantAgentCustomizationApply::ACTION_APPLY,
    ): void {
        $tenant->loadMissing(['server', 'agentCustomization', 'skillAssignments.catalogVersion']);
        $customization = $tenant->agentCustomization;

        if (! $customization) {
            throw new RuntimeException('No tenant runtime customization draft exists yet.');
        }

        DB::transaction(function () use ($tenant, $provisioningJob, $composer, $action, $customization): void {
            $lockedCustomization = TenantAgentCustomization::query()
                ->whereKey($customization->id)
                ->lockForUpdate()
                ->firstOrFail();
            $freshTenant = $tenant->fresh(['businessProfile', 'businessProfileFiles', 'googleCredential', 'agentCustomization', 'skillAssignments.catalogVersion']);

            $snapshot = $action === TenantAgentCustomizationApply::ACTION_REVERT
                ? $this->restoreLastAppliedSnapshot($freshTenant, $lockedCustomization)
                : $this->draftInputSnapshot($freshTenant, $lockedCustomization);

            $provisioningJob->forceFill([
                'status' => ProvisioningJobStatus::Running,
                'started_at' => now(),
                'completed_at' => null,
                'error_message' => null,
            ])->save();

            $beforeHash = $lockedCustomization->applied_snapshot_hash;

            try {
                $freshTenant = $tenant->fresh(['businessProfile', 'businessProfileFiles', 'googleCredential', 'agentCustomization', 'skillAssignments.catalogVersion']);
                $composed = $composer->compose($freshTenant, $freshTenant->agentCustomization);

                if ($composed->contentHash !== $beforeHash) {
                    $this->materializeWorkspaceFiles($tenant, $composed);
                    $configChanged = $this->writeLocalConfig($tenant, $composed->openClawConfig);
                    $this->syncRemoteArtifacts($tenant, $composed->openClawConfig, $configChanged);
                }

                $status = $action === TenantAgentCustomizationApply::ACTION_REVERT
                    ? TenantAgentCustomizationApply::STATUS_REVERTED
                    : TenantAgentCustomizationApply::STATUS_APPLIED;

                $lockedCustomization->forceFill([
                    'last_applied_input_snapshot_json' => $snapshot,
                    'applied_snapshot_hash' => $composed->contentHash,
                    'last_applied_at' => now(),
                    'last_apply_status' => 'applied',
                    'last_apply_error' => null,
                ])->save();

                $this->skillAssignments->markApplyResult($tenant, 'applied');

                TenantAgentCustomizationApply::query()->create([
                    'tenant_agent_customization_id' => $lockedCustomization->id,
                    'tenant_id' => $tenant->id,
                    'applied_by' => $lockedCustomization->draft_updated_by,
                    'action' => $action,
                    'draft_version_applied' => (int) $lockedCustomization->draft_version,
                    'input_snapshot_json' => $snapshot,
                    'before_output_hash' => $beforeHash,
                    'after_output_hash' => $composed->contentHash,
                    'status' => $status,
                    'composed_output_json' => $composed->diagnosticPayload(),
                    'created_at' => now(),
                ]);

                $provisioningJob->forceFill([
                    'status' => ProvisioningJobStatus::Completed,
                    'completed_at' => now(),
                    'error_message' => null,
                ])->save();
            } catch (Throwable $exception) {
                $lockedCustomization->forceFill([
                    'last_apply_status' => 'failed',
                    'last_apply_error' => $exception->getMessage(),
                ])->save();
                $this->skillAssignments->markApplyResult($tenant, 'failed', $exception->getMessage());

                TenantAgentCustomizationApply::query()->create([
                    'tenant_agent_customization_id' => $lockedCustomization->id,
                    'tenant_id' => $tenant->id,
                    'applied_by' => $lockedCustomization->draft_updated_by,
                    'action' => $action,
                    'draft_version_applied' => (int) $lockedCustomization->draft_version,
                    'input_snapshot_json' => $snapshot ?? $this->draftInputSnapshot($tenant->fresh(['skillAssignments.catalogVersion']), $lockedCustomization),
                    'before_output_hash' => $beforeHash,
                    'after_output_hash' => null,
                    'status' => TenantAgentCustomizationApply::STATUS_FAILED,
                    'error' => $exception->getMessage(),
                    'created_at' => now(),
                ]);

                $provisioningJob->forceFill([
                    'status' => ProvisioningJobStatus::Failed,
                    'completed_at' => now(),
                    'error_message' => $exception->getMessage(),
                ])->save();

                throw $exception;
            }
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function restoreLastAppliedSnapshot(Tenant $tenant, TenantAgentCustomization $customization): array
    {
        $snapshot = is_array($customization->last_applied_input_snapshot_json)
            ? $customization->last_applied_input_snapshot_json
            : null;

        if (! $snapshot) {
            throw new RuntimeException('There is no previously applied customization snapshot to restore.');
        }

        $customization->forceFill([
            'prompt_overrides_json' => $snapshot['prompt_overrides'] ?? [],
            'agent_defaults_json' => $snapshot['agent_defaults'] ?? [],
            'draft_version' => ((int) $customization->draft_version) + 1,
            'draft_updated_at' => now(),
        ])->save();

        $actor = User::query()->find($customization->draft_updated_by) ?? User::query()->find($tenant->user_id);

        if ($actor) {
            $this->skillAssignments->restoreFromSnapshot($tenant, $actor, (array) ($snapshot['assigned_skills'] ?? []));
        }

        return $snapshot;
    }

    private function materializeWorkspaceFiles(Tenant $tenant, ComposedTenantRuntime $composed): void
    {
        $workspacePath = $this->runtime->localWorkspacePath($tenant);
        $this->files->ensureDirectoryExists($workspacePath);
        $this->files->deleteDirectory($workspacePath.DIRECTORY_SEPARATOR.'skill-packs');
        $this->files->deleteDirectory($workspacePath.DIRECTORY_SEPARATOR.'skills');
        $this->files->ensureDirectoryExists($workspacePath.DIRECTORY_SEPARATOR.'skills');

        foreach ($composed->workspaceFiles as $filename => $contents) {
            $this->files->put($workspacePath.DIRECTORY_SEPARATOR.$filename, $contents);
        }

        foreach ($composed->skillFiles as $relativePath => $contents) {
            $targetPath = $workspacePath.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
            $this->files->ensureDirectoryExists(dirname($targetPath));
            $this->files->put($targetPath, $contents);
        }
    }

    private function writeLocalConfig(Tenant $tenant, string $contents): bool
    {
        $path = $this->runtime->localOpenClawConfigPath($tenant);
        $existing = $this->files->exists($path) ? $this->files->get($path) : null;
        $this->files->ensureDirectoryExists(dirname($path));
        $this->files->put($path, $contents);

        return $existing !== $contents;
    }

    private function syncRemoteArtifacts(Tenant $tenant, string $configContents, bool $configChanged): void
    {
        if (app()->environment('local')) {
            return;
        }

        $tenant->loadMissing('server');

        if (! $tenant->server) {
            throw new RuntimeException('Tenant server is missing.');
        }

        $remoteWorkspacePath = $this->runtime->remoteWorkspacePath($tenant);

        // Clear live skill folders before syncing so removed tenant skills do not
        // remain discoverable on the remote runtime after an apply.
        $this->dockerCompose->removeDirectory(
            $tenant->server,
            $remoteWorkspacePath.DIRECTORY_SEPARATOR.'skills',
        );
        $this->dockerCompose->removeDirectory(
            $tenant->server,
            $remoteWorkspacePath.DIRECTORY_SEPARATOR.'skill-packs',
        );

        $this->dockerCompose->syncWorkspaceFiles(
            $tenant->server,
            $this->runtime->localWorkspacePath($tenant),
            $remoteWorkspacePath,
        );

        $this->dockerCompose->putFile(
            $tenant->server,
            $this->runtime->remoteOpenClawConfigPath($tenant),
            $configContents,
        );

        if (! $configChanged) {
            return;
        }

        $this->dockerCompose->runCommand(
            $tenant->server,
            sprintf(
                'docker compose -f %s -p %s restart',
                escapeshellarg($this->runtime->remoteComposePath($tenant)),
                escapeshellarg($this->runtime->projectName($tenant)),
            ),
        );
    }

    private function basePromptContent(Tenant $tenant, string $key): string
    {
        $files = $tenant->businessProfileFiles;

        if (! $files) {
            return '';
        }

        return match ($key) {
            'identity' => (string) $files->identity_markdown,
            'soul' => (string) $files->soul_markdown,
            'user' => (string) $files->user_markdown,
            'bootstrap' => (string) $files->bootstrap_markdown,
            default => '',
        };
    }
}
