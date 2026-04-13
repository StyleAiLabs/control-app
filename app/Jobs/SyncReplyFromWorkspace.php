<?php

namespace App\Jobs;

use App\Models\ConversationLog;
use App\Models\Tenant;
use App\Services\WorkspaceSessionLogReader;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Backfills message_out on a ConversationLog by reading OpenClaw's session
 * memory files from the workspace.
 *
 * Dispatched by ProcessIncomingMessage with a 45-second delay to give
 * OpenClaw time to process the message, generate a reply, and flush its
 * session log to disk before we try to read it.
 */
class SyncReplyFromWorkspace implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 60;

    /**
     * Delay between retry attempts (seconds).
     *
     * @var array<int, int>
     */
    public array $backoff = [30, 60];

    public function __construct(
        public readonly int $tenantId,
        public readonly string $channel,
        public readonly string $externalMessageId,
    ) {}

    public function handle(WorkspaceSessionLogReader $reader): void
    {
        $log = ConversationLog::query()
            ->where('tenant_id', $this->tenantId)
            ->where('channel', $this->channel)
            ->where('external_message_id', $this->externalMessageId)
            ->whereNull('message_out')
            ->first();

        // Already filled in (e.g. by a previous retry that succeeded) — nothing to do.
        if (! $log) {
            return;
        }

        $tenant = Tenant::query()
            ->with('server')
            ->find($this->tenantId);

        if (! $tenant || ! $tenant->server || ! $tenant->runtime_path) {
            Log::warning('[SyncReplyFromWorkspace] Tenant/server not ready — skipping.', [
                'tenant_id'          => $this->tenantId,
                'external_message_id' => $this->externalMessageId,
            ]);

            return;
        }

        try {
            $reply = $reader->findReply($tenant, $this->externalMessageId);
        } catch (Throwable $e) {
            Log::warning('[SyncReplyFromWorkspace] Could not read workspace session logs.', [
                'tenant_id'          => $this->tenantId,
                'external_message_id' => $this->externalMessageId,
                'error'              => $e->getMessage(),
            ]);

            // Re-throw so the job retries with backoff.
            throw $e;
        }

        if ($reply === null) {
            Log::info('[SyncReplyFromWorkspace] Reply not in session log yet — will retry.', [
                'tenant_id'          => $this->tenantId,
                'external_message_id' => $this->externalMessageId,
            ]);

            // Re-throw a generic exception to trigger the backoff retry.
            throw new \RuntimeException('Reply not yet flushed to workspace session log.');
        }

        $log->update([
            'message_out'  => $reply,
            'responded_at' => now(),
        ]);

        Log::info('[SyncReplyFromWorkspace] Reply synced.', [
            'tenant_id'          => $this->tenantId,
            'channel'            => $this->channel,
            'external_message_id' => $this->externalMessageId,
            'reply_preview'      => mb_substr($reply, 0, 80),
        ]);
    }
}
