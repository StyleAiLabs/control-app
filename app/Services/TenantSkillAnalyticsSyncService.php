<?php

namespace App\Services;

use App\Models\SkillCatalogVersion;
use App\Models\Tenant;
use App\Models\TenantSkillAnalyticsSyncState;
use App\Models\TenantSkillConversionEvent;
use App\Models\Server;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use PDO;
use RuntimeException;
use Symfony\Component\Process\Process;
use Throwable;

class TenantSkillAnalyticsSyncService
{
    public function __construct(
        private readonly TenantRuntimeService $runtime,
        private readonly TenantSkillAnalyticsRuntimeService $skillAnalyticsRuntime,
    ) {
    }

    /**
     * @return array{imported:int, skipped:int, pruned:int, tenants:int, missing_runtime_dbs:int}
     */
    public function sync(?Tenant $selectedTenant = null): array
    {
        $tenants = $selectedTenant
            ? collect([$selectedTenant->fresh(['server'])])
            : Tenant::query()
                ->with('server')
                ->whereNotNull('runtime_path')
                ->orderBy('id')
                ->get();

        $totals = [
            'imported' => 0,
            'skipped' => 0,
            'pruned' => 0,
            'tenants' => $tenants->count(),
            'missing_runtime_dbs' => 0,
        ];

        foreach ($tenants as $tenant) {
            $result = $this->syncTenant($tenant);
            $totals['imported'] += $result['imported'];
            $totals['skipped'] += $result['skipped'];
            $totals['pruned'] += $result['pruned'];
            $totals['missing_runtime_dbs'] += $result['missing_runtime_db'] ? 1 : 0;
        }

        return $totals;
    }

    /**
     * @return array{imported:int, skipped:int, pruned:int, missing_runtime_db:bool}
     */
    public function syncTenant(Tenant $tenant): array
    {
        $state = TenantSkillAnalyticsSyncState::query()->firstOrCreate(
            ['tenant_id' => $tenant->id],
            ['last_runtime_row_id' => 0]
        );
        $tenant->loadMissing('skillAssignments.catalogVersion');
        $missingRuntimeDb = false;

        if (
            $this->skillAnalyticsRuntime->tenantHasAnalyticsSkills($tenant)
            && ! $this->skillAnalyticsRuntime->runtimeDatabaseExists($tenant)
        ) {
            $missingRuntimeDb = true;
            Log::warning('sync360:sync-skill-conversions found analytics-enabled tenant without a runtime SQLite database.', [
                'tenant_id' => $tenant->tenant_id,
                'tenant_slug' => $tenant->slug,
                'runtime_db_path' => $this->usesLocalRuntimeDriver()
                    ? $this->runtime->localSkillAnalyticsDbPath($tenant)
                    : $this->runtime->remoteSkillAnalyticsDbPath($tenant),
            ]);
        }

        $rows = $this->runtimeRowsForTenant($tenant, (int) $state->last_runtime_row_id);
        $imported = 0;
        $skipped = 0;
        $lastProcessedRowId = (int) $state->last_runtime_row_id;

        foreach ($rows as $row) {
            $rowId = (int) ($row['id'] ?? 0);

            try {
                $payload = $this->normalizedEventPayload($row);

                TenantSkillConversionEvent::query()->updateOrCreate(
                    [
                        'tenant_id' => $tenant->id,
                        'event_id' => $payload['event_id'],
                    ],
                    array_merge($payload, ['tenant_id' => $tenant->id]),
                );

                $imported++;
            } catch (Throwable $exception) {
                $skipped++;
                Log::warning('sync360:sync-skill-conversions skipped malformed runtime row.', [
                    'tenant_id' => $tenant->tenant_id,
                    'runtime_row_id' => $rowId,
                    'error' => $exception->getMessage(),
                ]);
            }

            $lastProcessedRowId = max($lastProcessedRowId, $rowId);
        }

        if ($lastProcessedRowId !== (int) $state->last_runtime_row_id || $rows === []) {
            $state->forceFill([
                'last_runtime_row_id' => $lastProcessedRowId,
                'last_synced_at' => now(),
            ])->save();
        }

        $pruned = $this->pruneSyncedRuntimeRows($tenant, $lastProcessedRowId);

        return [
            'imported' => $imported,
            'skipped' => $skipped,
            'pruned' => $pruned,
            'missing_runtime_db' => $missingRuntimeDb,
        ];
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function normalizedEventPayload(array $row): array
    {
        $eventId = trim((string) ($row['event_id'] ?? ''));
        $skillKey = trim((string) ($row['skill_key'] ?? ''));
        $skillVersion = trim((string) ($row['skill_version'] ?? ''));
        $conversionId = trim((string) ($row['conversion_id'] ?? ''));
        $customerLabel = trim((string) ($row['customer_label'] ?? ''));
        $occurredAt = trim((string) ($row['occurred_at'] ?? ''));

        if ($eventId === '' || $skillKey === '' || $skillVersion === '' || $conversionId === '' || $customerLabel === '' || $occurredAt === '') {
            throw new RuntimeException('Required event fields are missing.');
        }

        $outcomeJson = $row['outcome_json'] ?? null;
        $outcome = is_array($outcomeJson) ? $outcomeJson : json_decode((string) $outcomeJson, true);

        if (! is_array($outcome)) {
            throw new RuntimeException('Outcome JSON is invalid.');
        }

        $effortOverrideJson = $row['effort_override_json'] ?? null;
        $effortOverride = null;

        if ($effortOverrideJson !== null && $effortOverrideJson !== '') {
            $effortOverride = is_array($effortOverrideJson) ? $effortOverrideJson : json_decode((string) $effortOverrideJson, true);

            if (! is_array($effortOverride)) {
                throw new RuntimeException('Effort override JSON is invalid.');
            }
        }

        $manifestAnalytics = $this->analyticsManifestFor($skillKey, $skillVersion);
        $manifestDefaults = is_array($manifestAnalytics['roi_defaults'] ?? null) ? $manifestAnalytics['roi_defaults'] : [];
        $humanEffortMinutes = is_numeric($effortOverride['human_effort_minutes'] ?? null)
            ? (int) $effortOverride['human_effort_minutes']
            : (is_numeric($manifestDefaults['human_effort_minutes'] ?? null) ? (int) $manifestDefaults['human_effort_minutes'] : null);
        $agentEffortMinutes = is_numeric($effortOverride['agent_effort_minutes'] ?? null)
            ? (int) $effortOverride['agent_effort_minutes']
            : (is_numeric($manifestDefaults['agent_effort_minutes'] ?? null) ? (int) $manifestDefaults['agent_effort_minutes'] : null);
        $estimatedValueAmount = is_numeric($row['estimated_value_amount'] ?? null)
            ? round((float) $row['estimated_value_amount'], 2)
            : (is_numeric($manifestDefaults['value_amount'] ?? null) ? round((float) $manifestDefaults['value_amount'], 2) : null);
        $currency = trim((string) ($row['currency'] ?? '')) !== ''
            ? trim((string) $row['currency'])
            : (is_string($manifestDefaults['currency'] ?? null) ? trim((string) $manifestDefaults['currency']) : null);

        return [
            'event_id' => $eventId,
            'skill_key' => $skillKey,
            'skill_version' => $skillVersion,
            'event_type' => trim((string) ($row['event_type'] ?? 'conversion_succeeded')),
            'conversion_type' => trim((string) ($row['conversion_type'] ?? 'conversion_succeeded')),
            'conversion_id' => $conversionId,
            'occurred_at' => Carbon::parse($occurredAt),
            'session_id' => trim((string) ($row['session_id'] ?? '')) !== '' ? trim((string) $row['session_id']) : null,
            'customer_label' => $customerLabel,
            'contact_masked' => trim((string) ($row['contact_masked'] ?? '')) !== '' ? trim((string) $row['contact_masked']) : null,
            'estimated_value_amount' => $estimatedValueAmount,
            'currency' => $estimatedValueAmount !== null ? ($currency !== '' ? $currency : null) : null,
            'human_effort_minutes' => $humanEffortMinutes,
            'agent_effort_minutes' => $agentEffortMinutes,
            'net_minutes_saved' => ($humanEffortMinutes !== null && $agentEffortMinutes !== null)
                ? max(0, $humanEffortMinutes - $agentEffortMinutes)
                : null,
            'productivity_score' => 1,
            'effort_source' => $effortOverride !== null ? 'event_override' : (($humanEffortMinutes !== null || $agentEffortMinutes !== null) ? 'manifest_default' : null),
            'effort_override_json' => $effortOverride,
            'outcome_json' => $outcome,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function analyticsManifestFor(string $skillKey, string $skillVersion): array
    {
        $manifest = SkillCatalogVersion::query()
            ->where('skill_key', $skillKey)
            ->where('version', $skillVersion)
            ->value('manifest_json');

        if (! is_array($manifest)) {
            return [];
        }

        return is_array($manifest['analytics'] ?? null) ? $manifest['analytics'] : [];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function runtimeRowsForTenant(Tenant $tenant, int $afterRowId): array
    {
        return $this->usesLocalRuntimeDriver()
            ? $this->localRuntimeRowsForTenant($tenant, $afterRowId)
            : $this->remoteRuntimeRowsForTenant($tenant, $afterRowId);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function localRuntimeRowsForTenant(Tenant $tenant, int $afterRowId): array
    {
        $path = $this->runtime->localSkillAnalyticsDbPath($tenant);

        if (! is_file($path)) {
            return [];
        }

        $pdo = new PDO('sqlite:'.$path);
        $statement = $pdo->prepare('
            SELECT id, event_id, skill_key, skill_version, event_type, conversion_type, conversion_id, occurred_at,
                   session_id, customer_label, contact_masked, estimated_value_amount, currency, effort_override_json,
                   outcome_json, created_at
            FROM skill_conversion_events
            WHERE id > :after_row_id
            ORDER BY id ASC
        ');
        $statement->execute(['after_row_id' => $afterRowId]);

        /** @var list<array<string, mixed>> $rows */
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);

        return $rows;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function remoteRuntimeRowsForTenant(Tenant $tenant, int $afterRowId): array
    {
        if (! $tenant->server) {
            return [];
        }

        $dbPath = $this->runtime->remoteSkillAnalyticsDbPath($tenant);
        $query = sprintf(
            "if [ ! -f %1\$s ]; then printf '[]'; else sqlite3 -json %1\$s %2\$s; fi",
            $this->shellQuote($dbPath),
            $this->shellQuote('SELECT id, event_id, skill_key, skill_version, event_type, conversion_type, conversion_id, occurred_at, session_id, customer_label, contact_masked, estimated_value_amount, currency, effort_override_json, outcome_json, created_at FROM skill_conversion_events WHERE id > '.max(0, $afterRowId).' ORDER BY id ASC')
        );
        $output = $this->runRemoteShell($tenant->server, $query);
        $rows = json_decode($output, true);

        return is_array($rows) ? array_values(array_filter($rows, 'is_array')) : [];
    }

    private function pruneSyncedRuntimeRows(Tenant $tenant, int $lastRuntimeRowId): int
    {
        if ($lastRuntimeRowId <= 0) {
            return 0;
        }

        $cutoff = now()->subDays(7)->toIso8601String();

        return $this->usesLocalRuntimeDriver()
            ? $this->pruneLocalRuntimeRows($tenant, $lastRuntimeRowId, $cutoff)
            : $this->pruneRemoteRuntimeRows($tenant, $lastRuntimeRowId, $cutoff);
    }

    private function usesLocalRuntimeDriver(): bool
    {
        return config('sync360.infrastructure.driver', 'local') === 'local';
    }

    private function pruneLocalRuntimeRows(Tenant $tenant, int $lastRuntimeRowId, string $cutoff): int
    {
        $path = $this->runtime->localSkillAnalyticsDbPath($tenant);

        if (! is_file($path)) {
            return 0;
        }

        $pdo = new PDO('sqlite:'.$path);
        $statement = $pdo->prepare('DELETE FROM skill_conversion_events WHERE id <= :last_runtime_row_id AND created_at < :cutoff');
        $statement->execute([
            'last_runtime_row_id' => $lastRuntimeRowId,
            'cutoff' => $cutoff,
        ]);

        return $statement->rowCount();
    }

    private function pruneRemoteRuntimeRows(Tenant $tenant, int $lastRuntimeRowId, string $cutoff): int
    {
        if (! $tenant->server) {
            return 0;
        }

        $dbPath = $this->runtime->remoteSkillAnalyticsDbPath($tenant);
        $command = sprintf(
            "if [ ! -f %1\$s ]; then printf '0'; else sqlite3 %1\$s %2\$s; fi",
            $this->shellQuote($dbPath),
            $this->shellQuote(sprintf(
                "DELETE FROM skill_conversion_events WHERE id <= %d AND created_at < '%s'; SELECT changes();",
                $lastRuntimeRowId,
                str_replace("'", "''", $cutoff)
            ))
        );

        return (int) trim($this->runRemoteShell($tenant->server, $command));
    }

    private function runRemoteShell(Server $server, string $command): string
    {
        $process = new Process($this->sshCommandParts($server, $command));
        $process->run();

        if (! $process->isSuccessful()) {
            throw new RuntimeException(trim($process->getErrorOutput()) ?: 'Remote analytics sync command failed.');
        }

        return trim($process->getOutput());
    }

    /**
     * @return list<string>
     */
    private function sshCommandParts(Server $server, string $command): array
    {
        $parts = [];
        $authMode = (string) ($server->ssh_auth_mode ?? 'key');

        if ($authMode === 'password') {
            $sshpassBin = (string) config('sync360.infrastructure.sshpass_bin', 'sshpass');
            $password = (string) env((string) $server->ssh_password_env_key, '');

            if ($password !== '') {
                $parts[] = $sshpassBin;
                $parts[] = '-p';
                $parts[] = $password;
            }
        }

        $sshBin = (string) config('sync360.infrastructure.ssh_bin', 'ssh');
        $parts[] = $sshBin;
        $parts[] = '-p';
        $parts[] = (string) ($server->ssh_port ?: 22);

        if ($authMode === 'key' && filled($server->ssh_private_key_path)) {
            $parts[] = '-i';
            $parts[] = (string) $server->ssh_private_key_path;
        }

        $parts[] = '-o';
        $parts[] = 'StrictHostKeyChecking=no';
        $parts[] = '-o';
        $parts[] = 'UserKnownHostsFile=/dev/null';
        $parts[] = sprintf('%s@%s', $server->ssh_user, $server->ssh_host ?: $server->host);
        $parts[] = sprintf('sh -lc %s', $this->shellQuote($command));

        return $parts;
    }

    private function shellQuote(string $value): string
    {
        return "'".str_replace("'", "'\"'\"'", $value)."'";
    }
}
