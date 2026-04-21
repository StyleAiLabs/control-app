<?php

namespace App\Services;

use App\Models\Server;
use App\Models\Tenant;
use PDO;
use RuntimeException;
use Symfony\Component\Process\Process;

class TenantSkillAnalyticsRuntimeStorageService
{
    public function __construct(
        private readonly TenantRuntimeService $runtime,
    ) {
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function rowsForTenant(Tenant $tenant, int $afterRowId): array
    {
        return $this->usesLocalRuntimeDriver()
            ? $this->localRowsForTenant($tenant, $afterRowId)
            : $this->remoteRowsForTenant($tenant, $afterRowId);
    }

    public function pruneRows(Tenant $tenant, int $lastRuntimeRowId, string $cutoff): int
    {
        if ($lastRuntimeRowId <= 0) {
            return 0;
        }

        return $this->usesLocalRuntimeDriver()
            ? $this->pruneLocalRows($tenant, $lastRuntimeRowId, $cutoff)
            : $this->pruneRemoteRows($tenant, $lastRuntimeRowId, $cutoff);
    }

    public function rowCountForTenant(Tenant $tenant): ?int
    {
        return $this->usesLocalRuntimeDriver()
            ? $this->localRowCountForTenant($tenant)
            : $this->remoteRowCountForTenant($tenant);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function localRowsForTenant(Tenant $tenant, int $afterRowId): array
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

    private function localRowCountForTenant(Tenant $tenant): ?int
    {
        $path = $this->runtime->localSkillAnalyticsDbPath($tenant);

        if (! is_file($path)) {
            return null;
        }

        $pdo = new PDO('sqlite:'.$path);

        return (int) $pdo->query('SELECT COUNT(*) FROM skill_conversion_events')->fetchColumn();
    }

    private function pruneLocalRows(Tenant $tenant, int $lastRuntimeRowId, string $cutoff): int
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

    /**
     * @return list<array<string, mixed>>
     */
    private function remoteRowsForTenant(Tenant $tenant, int $afterRowId): array
    {
        $output = $this->runRemoteDatabaseCommand($tenant, 'rows', [(string) max(0, $afterRowId)], '[]');
        $rows = json_decode($output, true);

        return is_array($rows) ? array_values(array_filter($rows, 'is_array')) : [];
    }

    private function remoteRowCountForTenant(Tenant $tenant): ?int
    {
        $output = $this->runRemoteDatabaseCommand($tenant, 'count', [], '__missing__');

        if ($output === '__missing__') {
            return null;
        }

        return (int) trim($output);
    }

    private function pruneRemoteRows(Tenant $tenant, int $lastRuntimeRowId, string $cutoff): int
    {
        $output = $this->runRemoteDatabaseCommand($tenant, 'prune', [
            (string) $lastRuntimeRowId,
            $cutoff,
        ], '0');

        return (int) trim($output);
    }

    private function runRemoteDatabaseCommand(Tenant $tenant, string $action, array $args, string $missingOutput): string
    {
        $tenant->loadMissing('server');

        if (! $tenant->server) {
            throw new RuntimeException('Tenant server is missing.');
        }

        $hostDbPath = $this->runtime->remoteSkillAnalyticsDbPath($tenant);
        $containerDbPath = $this->runtime->containerSkillAnalyticsDbPath();
        $containerName = $this->runtime->containerName($tenant);
        $nodeScript = $this->remoteNodeScript();
        $commandArgs = array_merge([$action], $args);
        $quotedArgs = implode(' ', array_map(
            static fn (string $value): string => escapeshellarg($value),
            array_merge([$containerDbPath], $commandArgs),
        ));

        $command = sprintf(
            'if [ ! -f %1$s ]; then printf %2$s; else docker exec %3$s env NODE_NO_WARNINGS=1 node --input-type=module -e %4$s -- %5$s; fi',
            escapeshellarg($hostDbPath),
            escapeshellarg($missingOutput),
            escapeshellarg($containerName),
            escapeshellarg($nodeScript),
            $quotedArgs,
        );

        return $this->runRemoteShell($tenant->server, $command);
    }

    private function remoteNodeScript(): string
    {
        return <<<'JS'
import { DatabaseSync } from "node:sqlite";

const [dbPath, action, ...args] = process.argv.slice(1);

try {
  const db = new DatabaseSync(dbPath);

  if (action === "rows") {
    const afterRowId = Number.parseInt(args[0] ?? "0", 10);
    const rows = db.prepare(`
      SELECT id, event_id, skill_key, skill_version, event_type, conversion_type, conversion_id, occurred_at,
             session_id, customer_label, contact_masked, estimated_value_amount, currency, effort_override_json,
             outcome_json, created_at
      FROM skill_conversion_events
      WHERE id > ?
      ORDER BY id ASC
    `).all(Number.isNaN(afterRowId) ? 0 : afterRowId);
    process.stdout.write(JSON.stringify(rows));
  } else if (action === "count") {
    const row = db.prepare("SELECT COUNT(*) AS count FROM skill_conversion_events").get();
    process.stdout.write(String(row?.count ?? 0));
  } else if (action === "prune") {
    const lastRuntimeRowId = Number.parseInt(args[0] ?? "0", 10);
    const cutoff = args[1] ?? "";
    const result = db.prepare("DELETE FROM skill_conversion_events WHERE id <= ? AND created_at < ?")
      .run(Number.isNaN(lastRuntimeRowId) ? 0 : lastRuntimeRowId, cutoff);
    process.stdout.write(String(result?.changes ?? 0));
  } else {
    throw new Error(`Unknown action: ${action}`);
  }

  db.close();
} catch (error) {
  console.error(error instanceof Error ? error.message : String(error));
  process.exit(1);
}
JS;
    }

    private function usesLocalRuntimeDriver(): bool
    {
        return config('sync360.infrastructure.driver', 'local') === 'local';
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
