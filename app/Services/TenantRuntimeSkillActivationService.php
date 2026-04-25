<?php

namespace App\Services;

use App\Contracts\DockerComposeRunner;
use App\Models\Tenant;
use App\Models\TenantAgentCustomization;
use App\Models\TenantSkillAssignment;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Collection;
use RuntimeException;
use Throwable;

class TenantRuntimeSkillActivationService
{
    public function __construct(
        private readonly Filesystem $files,
        private readonly DockerComposeRunner $dockerCompose,
        private readonly TenantRuntimeService $runtime,
        private readonly TenantRuntimeCapabilityService $runtimeCapabilities,
        private readonly TenantRuntimeSkillDiscoveryService $runtimeSkillDiscovery,
        private readonly TenantSkillRegistryService $skillRegistry,
    ) {
    }

    /**
     * @return array{changed:bool, skill_set_changed:bool, contract:array<string, mixed>}
     */
    public function syncExpectedContract(Tenant $tenant, ?TenantAgentCustomization $customization = null): array
    {
        $existing = $this->readLocalContract($tenant);
        $contract = $this->expectedContract($tenant, $customization, $existing);
        $contents = $this->encodeContract($contract);
        $path = $this->runtime->localRuntimeSkillContractPath($tenant);
        $previousContents = $this->files->exists($path) ? $this->files->get($path) : null;

        $this->files->ensureDirectoryExists(dirname($path));
        $this->files->put($path, $contents);

        return [
            'changed' => $previousContents !== $contents,
            'skill_set_changed' => (string) ($existing['skill_set_hash'] ?? '') !== $contract['skill_set_hash'],
            'contract' => $contract,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function readLocalContract(Tenant $tenant): array
    {
        $path = $this->runtime->localRuntimeSkillContractPath($tenant);

        if (! $this->files->exists($path)) {
            return [];
        }

        $decoded = json_decode($this->files->get($path), true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @return array{
     *     ready:bool,
     *     contract:array<string, mixed>,
     *     workspace_state:?string,
     *     refreshed_at:?string,
     *     skills:list<string>,
     *     raw_output:string,
     *     missing_expected_skill_ids:list<string>,
     *     missing_required_skill_ids:list<string>,
     *     error:?string
     * }
     */
    public function verifyRuntimeSkills(Tenant $tenant, array $requiredSkillIds = []): array
    {
        $sync = $this->syncExpectedContract($tenant);
        $contract = $sync['contract'];
        $requiredSkillIds = $this->normalizedSkillIds($requiredSkillIds);

        try {
            $inspection = $this->runtimeSkillDiscovery->inspect($tenant);
            $verifiedSkillIds = $this->normalizedSkillIds((array) ($inspection['skills'] ?? []));
            $missingExpected = $this->missingSkills((array) ($contract['expected_skill_ids'] ?? []), $verifiedSkillIds);
            $missingRequired = $this->missingSkills($requiredSkillIds, $verifiedSkillIds);
            $error = $this->verificationErrorMessage($missingExpected, $missingRequired);

            $updatedContract = $this->mergeVerificationState(
                $contract,
                $verifiedSkillIds,
                $missingExpected === [],
                $error,
            );
            $this->persistContract($tenant, $updatedContract);

            return [
                'ready' => $missingExpected === [] && $missingRequired === [],
                'contract' => $updatedContract,
                'workspace_state' => is_string($inspection['workspace_state'] ?? null) ? $inspection['workspace_state'] : null,
                'refreshed_at' => is_string($inspection['refreshed_at'] ?? null) ? $inspection['refreshed_at'] : null,
                'skills' => $verifiedSkillIds,
                'raw_output' => (string) ($inspection['raw_output'] ?? ''),
                'missing_expected_skill_ids' => $missingExpected,
                'missing_required_skill_ids' => $missingRequired,
                'error' => $error,
            ];
        } catch (Throwable $exception) {
            $updatedContract = $this->mergeVerificationState(
                $contract,
                [],
                false,
                $exception->getMessage(),
            );
            $this->persistContract($tenant, $updatedContract);

            return [
                'ready' => false,
                'contract' => $updatedContract,
                'workspace_state' => null,
                'refreshed_at' => null,
                'skills' => [],
                'raw_output' => '',
                'missing_expected_skill_ids' => $this->normalizedSkillIds((array) ($contract['expected_skill_ids'] ?? [])),
                'missing_required_skill_ids' => $requiredSkillIds,
                'error' => $exception->getMessage(),
            ];
        }
    }

    /**
     * @return array{
     *     ready:bool,
     *     contract:array<string, mixed>,
     *     workspace_state:?string,
     *     refreshed_at:?string,
     *     skills:list<string>,
     *     raw_output:string,
     *     missing_expected_skill_ids:list<string>,
     *     missing_required_skill_ids:list<string>,
     *     error:?string
     * }
     */
    public function activateExpectedSkills(Tenant $tenant, bool $recreate = false, bool $forceSessionRotation = false): array
    {
        $sync = $this->syncExpectedContract($tenant);

        if ($forceSessionRotation || $sync['skill_set_changed']) {
            $this->rotateAgentSessions($tenant);
        }

        $this->runtimeCapabilities->reloadRuntime($tenant, $recreate);

        $verification = $this->verifyRuntimeSkills($tenant);

        if ($verification['missing_expected_skill_ids'] !== []) {
            throw new RuntimeException($this->activationFailureMessage($verification));
        }

        return $verification;
    }

    /**
     * @return array<string, mixed>
     */
    public function ensureRequiredSkillsReady(Tenant $tenant, array $requiredSkillIds): array
    {
        $requiredSkillIds = $this->normalizedSkillIds($requiredSkillIds);
        $sync = $this->syncExpectedContract($tenant);
        $contract = $sync['contract'];

        if ($this->requiredSkillsVerified($contract, $requiredSkillIds)) {
            return $contract;
        }

        $verification = $this->verifyRuntimeSkills($tenant, $requiredSkillIds);

        if ($verification['missing_required_skill_ids'] === []) {
            return $verification['contract'];
        }

        $this->rotateAgentSessions($tenant);
        $this->runtimeCapabilities->reloadRuntime($tenant, false);

        $verification = $this->verifyRuntimeSkills($tenant, $requiredSkillIds);

        if ($verification['missing_required_skill_ids'] !== []) {
            throw new RuntimeException(sprintf(
                'Required runtime skills are not active for tenant [%s]. Missing: %s. %s',
                $tenant->slug,
                implode(', ', $verification['missing_required_skill_ids']),
                trim((string) ($verification['error'] ?? ''))
            ));
        }

        return $verification['contract'];
    }

    public function rotateAgentSessions(Tenant $tenant): void
    {
        $localPath = $this->runtime->localAgentSessionStatePath($tenant);

        if ($this->files->isDirectory($localPath)) {
            $this->files->deleteDirectory($localPath);
        }

        if (app()->environment('local')) {
            return;
        }

        $tenant->loadMissing('server');

        if ($tenant->server) {
            $this->dockerCompose->removeDirectory(
                $tenant->server,
                $this->runtime->remoteAgentSessionStatePath($tenant),
            );
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function expectedContract(Tenant $tenant, ?TenantAgentCustomization $customization = null, ?array $existing = null): array
    {
        $existing ??= [];
        $expectedSkillIds = $this->expectedSkillIds($tenant, $customization);
        $skillSetHash = $this->skillSetHash($expectedSkillIds);
        $hashUnchanged = (string) ($existing['skill_set_hash'] ?? '') === $skillSetHash;

        return [
            'expected_skill_ids' => $expectedSkillIds,
            'skill_set_hash' => $skillSetHash,
            'verified_skill_ids' => $hashUnchanged
                ? $this->normalizedSkillIds((array) ($existing['verified_skill_ids'] ?? []))
                : [],
            'verified_skill_set_hash' => $hashUnchanged && is_string($existing['verified_skill_set_hash'] ?? null)
                ? trim((string) $existing['verified_skill_set_hash'])
                : null,
            'last_verified_at' => $hashUnchanged && is_string($existing['last_verified_at'] ?? null)
                ? trim((string) $existing['last_verified_at'])
                : null,
            'last_verification_error' => $hashUnchanged && is_string($existing['last_verification_error'] ?? null)
                ? trim((string) $existing['last_verification_error'])
                : null,
        ];
    }

    /**
     * @return list<string>
     */
    public function expectedSkillIds(Tenant $tenant, ?TenantAgentCustomization $customization = null): array
    {
        $tenant->loadMissing(['agentCustomization', 'skillAssignments.catalogVersion']);
        $customization ??= $tenant->agentCustomization;
        $agentDefaults = is_array($customization?->agent_defaults_json) ? $customization->agent_defaults_json : [];
        $enabledAssignments = $tenant->skillAssignments
            ->filter(fn (TenantSkillAssignment $assignment): bool => $assignment->is_enabled)
            ->values();

        return $this->normalizedSkillIds(array_merge(
            $this->runtimeCapabilities->openClawSkills(),
            $this->skillRegistry->openClawSkillIds($enabledAssignments),
            $this->skillRegistry->defaultAgentSkillIds($enabledAssignments),
            (array) ($agentDefaults['default_skill_ids'] ?? []),
        ));
    }

    /**
     * @param  list<string>  $skillIds
     */
    public function skillSetHash(array $skillIds): string
    {
        return hash('sha256', implode("\n", $this->normalizedSkillIds($skillIds)));
    }

    /**
     * @param  array<string, mixed>  $contract
     * @return array<string, mixed>
     */
    private function mergeVerificationState(array $contract, array $verifiedSkillIds, bool $fullMatch, ?string $error): array
    {
        return array_merge($contract, [
            'verified_skill_ids' => $verifiedSkillIds,
            'verified_skill_set_hash' => $fullMatch
                ? (string) ($contract['skill_set_hash'] ?? '')
                : (is_string($contract['verified_skill_set_hash'] ?? null) ? trim((string) $contract['verified_skill_set_hash']) : null),
            'last_verified_at' => now()->toIso8601String(),
            'last_verification_error' => $error,
        ]);
    }

    /**
     * @param  array<string, mixed>  $contract
     */
    private function requiredSkillsVerified(array $contract, array $requiredSkillIds): bool
    {
        if ($requiredSkillIds === []) {
            return true;
        }

        $verifiedHash = is_string($contract['verified_skill_set_hash'] ?? null)
            ? trim((string) $contract['verified_skill_set_hash'])
            : '';
        $currentHash = is_string($contract['skill_set_hash'] ?? null)
            ? trim((string) $contract['skill_set_hash'])
            : '';

        if ($verifiedHash === '' || $verifiedHash !== $currentHash) {
            return false;
        }

        $verifiedSkillIds = $this->normalizedSkillIds((array) ($contract['verified_skill_ids'] ?? []));

        return $this->missingSkills($requiredSkillIds, $verifiedSkillIds) === [];
    }

    /**
     * @return list<string>
     */
    private function missingSkills(array $required, array $available): array
    {
        return array_values(array_diff(
            $this->normalizedSkillIds($required),
            $this->normalizedSkillIds($available),
        ));
    }

    /**
     * @param  mixed  $skills
     * @return list<string>
     */
    private function normalizedSkillIds(mixed $skills): array
    {
        $normalized = [];

        foreach (is_array($skills) ? $skills : [] as $skillId) {
            if (! is_string($skillId)) {
                continue;
            }

            $skillId = trim($skillId);

            if ($skillId !== '' && ! in_array($skillId, $normalized, true)) {
                $normalized[] = $skillId;
            }
        }

        sort($normalized);

        return $normalized;
    }

    /**
     * @param  array<string, mixed>  $contract
     */
    private function persistContract(Tenant $tenant, array $contract): void
    {
        $contents = $this->encodeContract($contract);
        $localPath = $this->runtime->localRuntimeSkillContractPath($tenant);
        $this->files->ensureDirectoryExists(dirname($localPath));
        $this->files->put($localPath, $contents);

        if (app()->environment('local')) {
            return;
        }

        $tenant->loadMissing('server');

        if ($tenant->server) {
            $this->dockerCompose->putFile(
                $tenant->server,
                $this->runtime->remoteRuntimeSkillContractPath($tenant),
                $contents,
            );
        }
    }

    /**
     * @param  array<string, mixed>  $contract
     */
    private function encodeContract(array $contract): string
    {
        return json_encode($contract, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL;
    }

    /**
     * @param  list<string>  $missingExpected
     * @param  list<string>  $missingRequired
     */
    private function verificationErrorMessage(array $missingExpected, array $missingRequired): ?string
    {
        $parts = [];

        if ($missingExpected !== []) {
            $parts[] = 'Missing expected skills: '.implode(', ', $missingExpected).'.';
        }

        if ($missingRequired !== []) {
            $parts[] = 'Missing required skills: '.implode(', ', $missingRequired).'.';
        }

        return $parts === [] ? null : implode(' ', $parts);
    }

    /**
     * @param  array{
     *      missing_expected_skill_ids:list<string>,
     *      error:?string
     *  } $verification
     */
    private function activationFailureMessage(array $verification): string
    {
        $message = 'Runtime skill activation verification failed.';

        if ($verification['missing_expected_skill_ids'] !== []) {
            $message .= ' Missing expected skills: '.implode(', ', $verification['missing_expected_skill_ids']).'.';
        }

        if (filled($verification['error'] ?? null)) {
            $message .= ' '.trim((string) $verification['error']);
        }

        return trim($message);
    }
}
