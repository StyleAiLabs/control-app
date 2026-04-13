<?php

namespace App\Console\Commands;

use App\Models\ConversationLog;
use App\Models\Tenant;
use App\Services\ConversationSummaryService;
use App\Services\WorkspaceSessionLogReader;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Throwable;

class SyncConversationReplies extends Command
{
    /**
     * @var string
     */
    protected $signature = 'sync360:sync-replies
                            {tenant? : Tenant ID (ULID) to sync — omit to process all live tenants}';

    /**
     * @var string
     */
    protected $description = 'Upsert ConversationLog records from OpenClaw session logs, grouped by session with AI summaries';

    public function handle(
        WorkspaceSessionLogReader $reader,
        ConversationSummaryService $summariser,
    ): int {
        $tenantArg = $this->argument('tenant');

        $query = Tenant::query()
            ->with('server')
            ->where('agent_status', 'live')
            ->whereNotNull('runtime_path')
            ->whereNotNull('server_id');

        if ($tenantArg) {
            $query->where('tenant_id', $tenantArg);
        }

        $tenants = $query->get();

        if ($tenants->isEmpty()) {
            $this->components->info('No live tenants found for reply sync.');

            return self::SUCCESS;
        }

        $created  = 0;
        $updated  = 0;
        $skipped  = 0;
        $errors   = 0;

        foreach ($tenants as $tenant) {
            try {
                $conversations = $reader->readConversations($tenant);

                if (empty($conversations)) {
                    $this->components->twoColumnDetail(
                        sprintf('%s (%s)', $tenant->business_name, $tenant->tenant_id),
                        'No session log entries found',
                    );
                    continue;
                }

                $tenantCreated = 0;
                $tenantUpdated = 0;
                $tenantSkipped = 0;

                // ── 1. Upsert individual ConversationLog records ──
                foreach ($conversations as $messageId => $entry) {
                    $messageId = (string) $messageId;

                    /** @var ConversationLog|null $existing */
                    $existing = ConversationLog::query()
                        ->where('tenant_id', $tenant->id)
                        ->where('channel', $tenant->channel ?? 'telegram')
                        ->where('external_message_id', $messageId)
                        ->first();

                    if ($existing) {
                        $changes = [];

                        if ($existing->message_out === null && $entry['message_out'] !== null) {
                            $changes['message_out']  = $entry['message_out'];
                            $changes['responded_at'] = now();
                        }

                        if ($existing->session_id === null && $entry['session_id'] !== null) {
                            $changes['session_id'] = $entry['session_id'];
                        }

                        if (! empty($changes)) {
                            $existing->update($changes);
                            $updated++;
                            $tenantUpdated++;
                        } else {
                            $skipped++;
                            $tenantSkipped++;
                        }

                        continue;
                    }

                    // New record — create from session log data.
                    ConversationLog::query()->create([
                        'tenant_id'           => $tenant->id,
                        'channel'             => $tenant->channel ?? 'telegram',
                        'external_message_id' => $messageId,
                        'session_id'          => $entry['session_id'],
                        'from_identifier'     => $entry['sender_id'],
                        'message_in'          => $entry['message_in'],
                        'message_out'         => $entry['message_out'],
                        'meta_json'           => ['sender_name' => $entry['sender_name'], 'source' => 'session_log_sync'],
                        'responded_at'        => $entry['message_out'] ? now() : null,
                    ]);

                    $created++;
                    $tenantCreated++;

                    Log::info('[SyncConversationReplies] Created from session log.', [
                        'tenant_id'  => $tenant->tenant_id,
                        'message_id' => $messageId,
                        'session_id' => $entry['session_id'],
                    ]);
                }

                // ── 2. Generate AI summary per session ──
                // Group messages by session_id and summarise any session that
                // doesn't have a summary yet.
                $this->generateSessionSummaries($tenant, $conversations, $summariser);

                $this->components->twoColumnDetail(
                    sprintf('%s (%s)', $tenant->business_name, $tenant->tenant_id),
                    sprintf('Created: %d  Updated: %d  Skipped: %d', $tenantCreated, $tenantUpdated, $tenantSkipped),
                );
            } catch (Throwable $e) {
                $errors++;
                Log::warning('[SyncConversationReplies] Failed for tenant.', [
                    'tenant_id' => $tenant->tenant_id,
                    'error'     => $e->getMessage(),
                ]);
                $this->components->twoColumnDetail(
                    sprintf('%s (%s)', $tenant->business_name, $tenant->tenant_id),
                    'Error: '.$e->getMessage(),
                );
            }
        }

        $this->components->info(sprintf(
            'Sync complete. Created: %d. Updated: %d. Skipped: %d. Errors: %d.',
            $created, $updated, $skipped, $errors,
        ));

        return self::SUCCESS;
    }

    /**
     * For each distinct session_id, generate an AI summary if one is missing
     * and write it to all ConversationLog records in that session.
     *
     * @param  array<string, array{session_id: string|null, sender_id: string, sender_name: string, message_in: string, message_out: string|null}>  $conversations
     */
    private function generateSessionSummaries(
        Tenant $tenant,
        array $conversations,
        ConversationSummaryService $summariser,
    ): void {
        // Group the raw conversation entries by session_id.
        /** @var Collection<string, Collection> $bySession */
        $bySession = collect($conversations)->groupBy('session_id');

        foreach ($bySession as $sessionId => $entries) {
            if (! $sessionId) {
                continue; // Skip messages that have no session association.
            }

            // Check if any record in this session already has a summary.
            // When SYNC360_REFRESH_SUMMARIES=true the existing summary is cleared
            // so all sessions get regenerated with the latest prompt (one-time use).
            $hasSummary = ConversationLog::query()
                ->where('tenant_id', $tenant->id)
                ->where('session_id', $sessionId)
                ->whereNotNull('ai_summary')
                ->exists();

            if ($hasSummary) {
                if (! config('sync360.refresh_summaries', false)) {
                    continue;
                }
                // Clear the stale summary so it regenerates below.
                ConversationLog::query()
                    ->where('tenant_id', $tenant->id)
                    ->where('session_id', $sessionId)
                    ->update(['ai_summary' => null]);
            }

            // Build the ordered message list for the summariser.
            $messages   = $entries->values()->map(fn ($e) => [
                'message_in'  => $e['message_in'],
                'message_out' => $e['message_out'],
            ])->all();

            $senderName = $entries->first()['sender_name'] ?? null;
            $summary    = $summariser->summarise($messages, $senderName ?: null);

            if ($summary === null) {
                continue;
            }

            // Write the summary to every record in this session.
            ConversationLog::query()
                ->where('tenant_id', $tenant->id)
                ->where('session_id', $sessionId)
                ->update(['ai_summary' => $summary]);

            $this->components->twoColumnDetail(
                sprintf('  Session %s', substr($sessionId, 0, 8).'…'),
                \Illuminate\Support\Str::limit($summary, 70),
            );

            Log::info('[SyncConversationReplies] Session summary generated.', [
                'tenant_id'  => $tenant->tenant_id,
                'session_id' => $sessionId,
            ]);
        }
    }
}
