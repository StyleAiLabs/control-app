<?php

namespace App\Services;

use App\Models\Tenant;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * Reads OpenClaw session memory files from the workspace VPS and extracts
 * the full conversation record — grouped by OpenClaw session — for each message.
 *
 * OpenClaw writes one markdown file per conversation session to:
 *   {runtime_path}/.openclaw/workspace/memory/YYYY-MM-DD-{session-name}.md
 *
 * A single file may contain multiple sessions separated by "# Session:" headers.
 * Each session has a UUID that groups its messages into a conversation thread.
 */
class WorkspaceSessionLogReader
{
    public function __construct(
        private readonly TenantRuntimeService $runtime,
    ) {}

    /**
     * Return all parsed conversation entries from the workspace session logs,
     * keyed by external_message_id (the Telegram message_id).
     *
     * Each entry contains:
     *   - session_id:   OpenClaw session UUID (groups messages into a thread)
     *   - sender_id:    Telegram user ID
     *   - sender_name:  Display name
     *   - message_in:   The user's message text
     *   - message_out:  The AI assistant's reply (null if not logged yet)
     *
     * @return array<string, array{session_id: string|null, sender_id: string, sender_name: string, message_in: string, message_out: string|null}>
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
                $content   = $this->runSsh($tenant, sprintf('cat %s', escapeshellarg($file)));
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
     * Parse a single session markdown file.
     *
     * A file may contain multiple sessions separated by "# Session:" headers:
     *
     *   # Session: 2026-04-12 12:05:57 UTC
     *   - **Session Key**: agent:main:main
     *   - **Session ID**: 382e48e9-d3fa-4bfd-aab0-05b3dfe63597
     *   - **Source**: telegram
     *
     *   ## Conversation Summary
     *
     *   user: Conversation info (untrusted metadata):
     *   ```json
     *   { "message_id": "34", "sender_id": "...", "sender": "...", "timestamp": "..." }
     *   ```
     *   Hello
     *   assistant: Hi! How can I help?
     *
     *   user: ... (next message)
     *   assistant: ...
     *
     *   # Session: 2026-04-13 14:39:11 UTC    ← next session starts here
     *   ...
     *
     * @return array<string, array{session_id: string|null, sender_id: string, sender_name: string, message_in: string, message_out: string|null}>
     */
    public function parseSessionLog(string $content): array
    {
        $results = [];

        // Normalize line endings.
        $content = str_replace("\r\n", "\n", $content);

        // Split the file into per-session blocks on "# Session:" at line start.
        $sessionBlocks = preg_split('/^#\s+Session:/mu', $content);

        if (! $sessionBlocks) {
            return [];
        }

        foreach ($sessionBlocks as $sessionBlock) {
            if (trim($sessionBlock) === '') {
                continue;
            }

            // ── Extract the OpenClaw session UUID ──
            $sessionId = null;
            if (preg_match('/\*\*Session\s+ID\*\*:\s*([\da-f-]{36})/ui', $sessionBlock, $sidMatch)) {
                $sessionId = $sidMatch[1];
            }

            // ── Split the session block into per-turn blocks on "user:" ──
            $parts = preg_split('/\nuser:\s*/u', "\n".$sessionBlock);

            if (! $parts) {
                continue;
            }

            foreach ($parts as $block) {
                // ── 1. Extract message_id + sender from metadata JSON fence ──
                if (! preg_match(
                    '/```json\s*(\{[^`]*?"message_id"\s*:\s*"(\d+)"[^`]*?\})\s*```/su',
                    $block,
                    $metaMatch,
                )) {
                    continue; // System or startup messages — no message_id.
                }

                $messageId  = $metaMatch[2];
                $meta       = json_decode($metaMatch[1], true) ?? [];
                $senderId   = (string) ($meta['sender_id'] ?? '');
                $senderName = (string) ($meta['sender'] ?? '');

                // ── 2. Extract the user's actual message text ──
                // Strip all code fences, remove the metadata preamble, take text before assistant:.
                $afterFences = preg_replace('/```[^`]*```/su', '', $block) ?? '';
                $afterFences = preg_replace('/\A.*?Sender\s*\(untrusted metadata\)[^\n]*\n?/su', '', trim($afterFences)) ?? '';
                $messageIn   = '';
                if (preg_match('/\A(.*?)(?=\nassistant:|\Z)/su', trim($afterFences), $msgMatch)) {
                    $messageIn = trim($msgMatch[1]);
                }

                // ── 3. Extract the assistant reply ──
                $messageOut = null;
                if (preg_match('/\nassistant:\s*(.+?)(?=\nuser:|\Z)/su', "\n".$block, $replyMatch)) {
                    $reply = trim($replyMatch[1]);
                    if ($reply !== '') {
                        $messageOut = $reply;
                    }
                }

                if ($messageId !== '' && ($messageIn !== '' || $messageOut !== null)) {
                    $results[$messageId] = [
                        'session_id'   => $sessionId,
                        'sender_id'    => $senderId,
                        'sender_name'  => $senderName,
                        'message_in'   => $messageIn,
                        'message_out'  => $messageOut,
                    ];
                }
            }
        }

        return $results;
    }

    private function remoteMemoryPath(Tenant $tenant): string
    {
        $runtimePath = $tenant->runtime_path ?: $this->runtime->remoteRuntimePath($tenant);

        return rtrim($runtimePath, '/').'/.openclaw/workspace/memory';
    }

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
