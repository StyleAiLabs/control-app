<?php

namespace App\Services;

use App\Models\Server;
use App\Models\Tenant;
use RuntimeException;
use Symfony\Component\Process\Process;

class TenantInboxGmailRuntimeService
{
    public function __construct(
        private readonly TenantRuntimeService $runtime,
    ) {
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function searchRecentInbox(Tenant $tenant, string $query = 'in:inbox newer_than:2d', int $max = 20): array
    {
        $output = $this->runTenantCommand(
            $tenant,
            sprintf(
                'gog --json gmail search %s --max %d',
                escapeshellarg($query),
                max(1, $max),
            ),
        );

        $decoded = json_decode($output, true);

        if (! is_array($decoded)) {
            throw new RuntimeException('Gmail search returned invalid JSON.');
        }

        $rows = $decoded['threads'] ?? $decoded['messages'] ?? $decoded['items'] ?? [];

        if (! is_array($rows)) {
            return [];
        }

        return array_values(array_filter($rows, 'is_array'));
    }

    /**
     * @return array{raw:string, metadata:array<string, mixed>, body:string}
     */
    public function getMessage(Tenant $tenant, string $messageId): array
    {
        $messageId = trim($messageId);

        if ($messageId === '') {
            throw new RuntimeException('Gmail message id is required.');
        }

        $raw = $this->runTenantCommand(
            $tenant,
            sprintf('gog gmail get %s', escapeshellarg($messageId)),
        );

        return $this->parsePlainMessage($raw);
    }

    private function runTenantCommand(Tenant $tenant, string $innerCommand): string
    {
        $tenant->loadMissing('server');

        if (! $tenant->server) {
            throw new RuntimeException('Tenant server is missing.');
        }

        $workspacePath = $this->runtime->containerWorkspacePath();
        $command = sprintf(
            'docker exec %s sh -lc %s',
            escapeshellarg($this->runtime->containerName($tenant)),
            escapeshellarg(sprintf('cd %s && %s', escapeshellarg($workspacePath), $innerCommand)),
        );

        $process = $this->usesLocalRuntimeDriver()
            ? new Process(['sh', '-lc', $command])
            : new Process($this->sshCommandParts($tenant->server, $command));

        $process->setTimeout(60);
        $process->run();

        if (! $process->isSuccessful()) {
            $output = trim($process->getErrorOutput()) ?: trim($process->getOutput());

            throw new RuntimeException($output !== '' ? $output : 'Tenant Gmail runtime command failed.');
        }

        return trim($process->getOutput());
    }

    /**
     * @return array{raw:string, metadata:array<string, mixed>, body:string}
     */
    private function parsePlainMessage(string $raw): array
    {
        $metadata = [];
        $bodyLines = [];
        $inBody = false;

        foreach (preg_split('/\R/', $raw) ?: [] as $line) {
            if (! $inBody && trim($line) === '') {
                $inBody = true;
                continue;
            }

            if (! $inBody && str_contains($line, "\t")) {
                [$key, $value] = explode("\t", $line, 2);
                $metadata[strtolower(trim($key))] = trim($value);
                continue;
            }

            $inBody = true;
            $bodyLines[] = $line;
        }

        return [
            'raw' => $raw,
            'metadata' => $metadata,
            'body' => trim(implode(PHP_EOL, $bodyLines)),
        ];
    }

    private function usesLocalRuntimeDriver(): bool
    {
        return config('sync360.infrastructure.driver', 'local') === 'local';
    }

    /**
     * @return list<string>
     */
    private function sshCommandParts(Server $server, string $command): array
    {
        $parts = [];
        $authMode = (string) ($server->ssh_auth_mode ?? 'key');

        if ($authMode === 'password') {
            $password = (string) env((string) $server->ssh_password_env_key, '');

            if ($password !== '') {
                $parts[] = (string) config('sync360.infrastructure.sshpass_bin', 'sshpass');
                $parts[] = '-p';
                $parts[] = $password;
            }
        }

        $parts[] = (string) config('sync360.infrastructure.ssh_bin', 'ssh');
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
        $parts[] = sprintf('sh -lc %s', escapeshellarg($command));

        return $parts;
    }
}
