<?php

namespace App\Services;

use App\Models\Tenant;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * Reads OpenClaw session memory files from the workspace VPS and extracts
 * the full conversation record for each message — both the user's incoming
 * message and the AI's reply.
 *
 * OpenClaw writes one markdown file per conversation session to:
 *   {runtime_path}/.openclaw/workspace/memory/YYYY-MM-DD-{session-name}.md
 *
 * These files are the authoritative record of all conversations. OpenClaw
 * operates in polling mode, pulling messages directly from Telegram and
 * writing session logs autonomously. The control app reads these logs to
 * populate ConversationLog records.
 */
class WorkspaceSessionLogReader
{
    public function __construct(
        private readonly TenantRuntimeService $runtime,
    ) {}

    /**
     * Return all parsed conversation entries from the workspace session logs.
     *
     * Each entry keyed by external_message_id (the Telegram message_id) contains:
     *   - sender_id:   Telegram user ID of the message sender
     *   - sender_name: Display name of the sender
     *   - message_in:  The user's message text
     *   - message_out: The AI assistant's reply text (null if no reply logged yet)
     *
     * @return array<string, array{sender_id: string, sender_name: string, message_in: string, message_out: string|null}>
     */
    public function readConversations(Tenant $tenant): array
    {
        $tenant->loadMissing('server');

        if (! $tenant->server) {
            throw new RuntimeException('Tenant has no server — cannot read session logs.');
        }

        $memoryPath = $this->remoteMemoryPath($tenant);

        try {
            $listing = $this->runSsh($tenant, sprintf(
                'ls %s/*.md 2>/dev/null || true',
                escapeshellarg($memoryPath),
            ));

            $files = array_filter(array_map('trim', explode("\n", $listing)));

            if (empty($files)) {
                return [];
            }

            $conversations = [];

            foreach ($files as $file) {
                $content  = $this->runSsh($tenant, sprintf('cat %s', escapeshellarg($file)));
                $extracted = $this->parseSessionLog($content);
                // += preserves numeric-string keys (Telegram message IDs like "46")
                $conversations += $extracted;
            }

            return $conversations;
        } catch (Throwable $e) {
            Log::warning('[WorkspaceSessionLogReader] Failed to read session logs.', [
                'tenant_id' => $tenant->tenant_id,
                'error'     => $e->getMessage(),
            ]);

            return [];
        }
    }

    /**
     * Parse a single session markdown file and return message_id → conversation entries.
     *
     * Actual OpenClaw memory file format (each user turn):
     *
     *   user: Conversation info (untrusted metadata):
     *   ```json
     *   {
     *     "message_id": "46",
     *     "sender_id":  "8699995227",
     *     "sender":     "Gayan Hewage",
     *     "timestamp":  "..."
     *   }
     *   ```
     *   Sender (untrusted metadata):
     *   ```json
     *   { "label": "...", "id": "...", "name": "..." }
     *   ```
     *
     *   The user's actual message text here
     *   assistant: The AI reply text
     *
     * @return array<string, array{sender_id: string, sender_name: string, message_in: string, message_out: string|null}>
     */
    public function parseSessionLog(string $content): array
    {
        $results = [];

        // Normalize line endings (SSH output can carry \r\n on some systems).
        $content = str_replace("\r\n", "\n", $content);

        // Split the whole document on "user:" at line start to get per-turn blocks.
        $parts = preg_split('/\nuser:\s*/u', "\n".$content);

        if (! $parts) {
            return [];
        }

        foreach ($parts as $block) {
            // ── 1. Extract message metadata from the first ```json code fence ──
            if (! preg_match(
                '/```json\s*(\{[^`]*?"message_id"\s*:\s*"(\d+)"[^`]*?\})\s*```/su',
                $block,
                $metaMatch,
            )) {
                continue; // No metadata block — system prompt or startup message, skip.
            }

            $messageId  = $metaMatch[2];
            $metaJson   = $metaMatch[1];

            // Decode metadata to get sender fields.
            $meta       = json_decode($metaJson, true) ?? [];
            $senderId   = (string) ($meta['sender_id'] ?? '');
            $senderName = (string) ($meta['sender'] ?? '');

            // ── 2. Extract the user's actual message text ──
            // It appears after the last ```…``` code fence in the user block,
            // before "assistant:" (or end of block).
            $messageIn = '';
            // Find the position after the last code fence in the block.
            if (preg_match('/```\s*\n(.*?)(?=\nassistant:|\Z)/su', $block, $msgMatch, 0, (int) strpos($block, $metaMatch[0]))) {
                // The capture may start with the Sender fence content — we want
                // the plain text after the final ```.
                // Strip everything inside code fences, leaving only plain text.
                $afterFences = preg_replace('/```[^`]*```/su', '', $block);
                $afterFences = (string) $afterFences;
                // Strip the metadata preamble ("Conversation info..." lines).
                $afterFences = preg_replace('/^.*?Sender\s*\(untrusted metadata\):.*?(?=\S)/su', '', $afterFences);
                // Take only what's before assistant:.
                if (preg_match('/^(.*?)(?=\nassistant:|\Z)/su', trim($afterFences), $msgOnlyMatch)) {
                    $messageIn = trim($msgOnlyMatch[1]);
                }
            }

            // ── 3. Extract the assistant reply ──
            $messageOut = null;
            if (preg_match('/\nassistant:\s*(.+?)(?=\nuser:|\Z)/su', "\n".$block, $replyMatch)) {
                $reply = trim($replyMatch[1]);
                if ($reply !== '') {
                    $messageOut = $reply;
                }
            }

            // Only store entries where we have at least a sender and something to log.
            if ($messageId !== '' && ($messageIn !== '' || $messageOut !== null)) {
                $results[$messageId] = [
                    'sender_id'   => $senderId,
                    'sender_name' => $senderName,
                    'message_in'  => $messageIn,
                    'message_out' => $messageOut,
                ];
            }
        }

        return $results;
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
     * Uses the same auth mechanism as SshDockerComposeRunner.
     */
    private function runSsh(Tenant $tenant, string $command): string
    {
        $server     = $tenant->server;
        $authPrefix = $this->buildAuthPrefix($server);
        $sshOptions = $this->buildSshOptions();
        $target     = ($server->ssh_user ?: 'root').'@'.($server->ssh_host ?: $server->host);

        $fullCommand = array_merge(
            $authPrefix,
            ['ssh'],
            $sshOptions,
            ['-p', (string) ($server->ssh_port ?: 22)],
            [$target, sprintf('sh -lc %s', $this->shellQuote($command))],
        );

        $process = new Process($fullCommand, timeout: 30);
        $process->run();

        if (! $process->isSuccessful()
            && trim($process->getOutput()) === ''
            && trim($process->getErrorOutput()) !== ''
        ) {
            throw new RuntimeException('SSH command failed: '.$process->getErrorOutput());
        }

        return $process->getOutput();
    }

    /** @return list<string> */
    private function buildAuthPrefix(mixed $server): array
    {
        if ($server->usesPasswordAuth()) {
            $envKey   = $server->ssh_password_env_key;
            $password = $envKey ? (string) env($envKey) : '';

            return ['sshpass', '-p', $password];
        }

        return [];
    }

    /** @return list<string> */
    private function buildSshOptions(): array
    {
        $options = [
            '-o', 'StrictHostKeyChecking=no',
            '-o', 'UserKnownHostsFile=/dev/null',
            '-o', 'ConnectTimeout=10',
        ];

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
