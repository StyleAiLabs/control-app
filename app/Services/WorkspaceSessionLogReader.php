<?php

namespace App\Services;

use App\Models\Tenant;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * Reads OpenClaw session memory files from the workspace VPS and extracts
 * AI reply text matched against Telegram/WhatsApp message IDs.
 *
 * OpenClaw writes one markdown file per conversation session to:
 *   {runtime_path}/.openclaw/workspace/memory/YYYY-MM-DD-{session-name}.md
 *
 * Each file contains alternating user/assistant blocks. The user block embeds
 * JSON metadata including the original channel message_id, which maps directly
 * to ConversationLog.external_message_id.
 */
class WorkspaceSessionLogReader
{
    public function __construct(
        private readonly TenantRuntimeService $runtime,
        private readonly SshDockerComposeRunner $ssh,
    ) {}

    /**
     * Return a map of external_message_id → reply_text for all sessions found
     * in the workspace memory directory that have a matching assistant reply.
     *
     * @return array<string, string>  e.g. ['58' => 'Sure, we can help with that...']
     */
    public function readReplies(Tenant $tenant): array
    {
        $tenant->loadMissing('server');

        if (! $tenant->server) {
            throw new RuntimeException('Tenant has no server — cannot read session logs.');
        }

        $memoryPath = $this->remoteMemoryPath($tenant);

        try {
            // List all .md files in the memory directory
            $listing = $this->runSsh($tenant, sprintf(
                'ls %s/*.md 2>/dev/null || true',
                escapeshellarg($memoryPath),
            ));

            $files = array_filter(array_map('trim', explode("\n", $listing)));

            if (empty($files)) {
                return [];
            }

            $replies = [];

            foreach ($files as $file) {
                $content = $this->runSsh($tenant, sprintf('cat %s', escapeshellarg($file)));
                $extracted = $this->parseSessionLog($content);
                $replies += $extracted; // += preserves numeric-string keys (message IDs like "46")
            }

            return $replies;
        } catch (Throwable $e) {
            Log::warning('[WorkspaceSessionLogReader] Failed to read session logs.', [
                'tenant_id' => $tenant->tenant_id,
                'error'     => $e->getMessage(),
            ]);

            return [];
        }
    }

    /**
     * Read replies for a single message ID — only reads files modified recently
     * to avoid unnecessary SSH data transfer for old sessions.
     *
     * @return string|null  The assistant reply, or null if not found yet.
     */
    public function findReply(Tenant $tenant, string $externalMessageId): ?string
    {
        $all = $this->readReplies($tenant);

        return $all[$externalMessageId] ?? null;
    }

    /**
     * Parse a single session markdown file and return message_id → reply pairs.
     *
     * Actual OpenClaw memory file format:
     *
     *   user: Conversation info (untrusted metadata):
     *   ```json
     *   {
     *     "message_id": "46",
     *     "sender_id": "...",
     *     ...
     *   }
     *   ```
     *   ... (optional sender metadata block) ...
     *
     *   The actual message text
     *   assistant: The AI reply text
     *
     * @return array<string, string>
     */
    public function parseSessionLog(string $content): array
    {
        $replies = [];

        // Split on "user:" that appears at the start of a line to get turn blocks.
        $parts = preg_split('/\nuser:\s*/u', "\n".$content);

        if (! $parts) {
            return [];
        }

        foreach ($parts as $block) {
            // Find message_id inside a ```json code fence.
            if (! preg_match('/```json\s*\{[^}]*"message_id"\s*:\s*"(\d+)"[^}]*\}\s*```/su', $block, $idMatch)) {
                continue;
            }

            $messageId = $idMatch[1];

            // Find the assistant reply that follows in this block (or next user: boundary).
            if (! preg_match('/\nassistant:\s*(.+?)(?=\nuser:|\Z)/su', "\n".$block, $replyMatch)) {
                continue;
            }

            $reply = trim($replyMatch[1]);

            if ($reply !== '') {
                $replies[$messageId] = $reply;
            }
        }

        return $replies;
    }

    /**
     * Absolute path to the OpenClaw session memory directory on the workspace VPS.
     * The Docker bind mount maps {runtime_path} → /home/node/.openclaw, so the
     * memory directory is at {runtime_path}/.openclaw/workspace/memory/.
     */
    private function remoteMemoryPath(Tenant $tenant): string
    {
        $runtimePath = $tenant->runtime_path ?: $this->runtime->remoteRuntimePath($tenant);

        return rtrim($runtimePath, '/').'/.openclaw/workspace/memory';
    }

    /**
     * Run a single command on the workspace VPS via SSH and return stdout.
     */
    private function runSsh(Tenant $tenant, string $command): string
    {
        // Use SshDockerComposeRunner's runCommand to leverage existing auth/sshpass setup.
        // We need raw output, so we capture via a temp-file trick over SSH.
        $server = $tenant->server;

        // Build the SSH command the same way SshDockerComposeRunner does:
        // sshpass -p {password} ssh {options} -p {port} {user}@{host} 'sh -lc {command}'
        $authPrefix  = $this->buildAuthPrefix($server);
        $sshOptions  = $this->buildSshOptions();
        $target      = ($server->ssh_user ?: 'root').'@'.($server->ssh_host ?: $server->host);

        $fullCommand = array_merge(
            $authPrefix,
            ['ssh'],
            $sshOptions,
            ['-p', (string) ($server->ssh_port ?: 22)],
            [$target, sprintf('sh -lc %s', $this->shellQuote($command))],
        );

        $process = new \Symfony\Component\Process\Process($fullCommand, timeout: 30);
        $process->run();

        if (! $process->isSuccessful() && trim($process->getOutput()) === '' && trim($process->getErrorOutput()) !== '') {
            throw new RuntimeException('SSH command failed: '.$process->getErrorOutput());
        }

        return $process->getOutput();
    }

    /**
     * @return list<string>
     */
    private function buildAuthPrefix(mixed $server): array
    {
        if ($server->usesPasswordAuth()) {
            $envKey   = $server->ssh_password_env_key;
            $password = $envKey ? (string) env($envKey) : '';

            return ['sshpass', '-p', $password];
        }

        return [];
    }

    /**
     * @return list<string>
     */
    private function buildSshOptions(): array
    {
        $options = ['-o', 'StrictHostKeyChecking=no', '-o', 'UserKnownHostsFile=/dev/null', '-o', 'ConnectTimeout=10'];

        foreach ((array) config('sync360.infrastructure.ssh_options', []) as $option) {
            $options[] = '-o';
            $options[] = (string) $option;
        }

        return $options;
    }

    private function shellQuote(string $value): string
    {
        return "'".str_replace("'", "'\"'\"'", $value)."'";
    }
}
