<?php

namespace App\Services;

use App\Enums\TenantRuntimeUsageUseCase;
use App\Enums\TenantProvisioningStatus;
use App\Models\Tenant;
use App\Models\TenantGoogleCredential;
use App\Models\TenantInboxMonitorMessage;
use App\Models\TenantInboxMonitorState;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class TenantInboxTriagePollingService
{
    public const SKILL_KEY = 'inbox-triage';
    public const CHANNEL = 'gmail_inbox_monitor';
    public const FROM = 'sync360-inbox-monitor';
    private const MAX_ATTEMPTS = 3;
    private const DISPATCH_LEASE_MINUTES = 10;

    public function __construct(
        private readonly TenantInboxGmailRuntimeService $gmail,
        private readonly TenantInboxMessageFilter $filter,
        private readonly TenantWorkspaceMessenger $messenger,
        private readonly CommercialAccessPolicy $commercialAccess,
    ) {
    }

    /**
     * @return Collection<int, Tenant>
     */
    public function eligibleTenants(): Collection
    {
        return Tenant::query()
            ->with(['server', 'googleCredential', 'inboxMonitorState', 'skillAssignments.catalogVersion'])
            ->where('agent_status', 'live')
            ->where('provisioning_status', TenantProvisioningStatus::Ready)
            ->whereNotNull('runtime_path')
            ->whereNotNull('server_id')
            ->whereHas('googleCredential', function ($query): void {
                $query->where('status', TenantGoogleCredential::STATUS_CONNECTED)
                    ->where('runtime_sync_status', TenantGoogleCredential::RUNTIME_SYNC_VERIFIED);
            })
            ->whereHas('skillAssignments', function ($query): void {
                $query->where('skill_key', self::SKILL_KEY)
                    ->where('is_enabled', true)
                    ->whereHas('catalogVersion', function ($versionQuery): void {
                        $versionQuery->where('is_active_published', true)
                            ->where('is_archived', false)
                            ->where('is_available', true);
                    });
            })
            ->orderBy('id')
            ->get()
            ->filter(fn (Tenant $tenant): bool => $this->commercialAccess->canPollInbox($tenant))
            ->filter(fn (Tenant $tenant): bool => $this->stateAllowsPolling($tenant))
            ->values();
    }

    /**
     * @return array{processed:int, delivered:int, skipped:int, failed:int}
     */
    public function pollTenant(Tenant $tenant): array
    {
        $tenant->loadMissing(['server', 'googleCredential', 'inboxMonitorState']);

        if (! $this->commercialAccess->canPollInbox($tenant)) {
            return [
                'processed' => 0,
                'delivered' => 0,
                'skipped' => 1,
                'failed' => 0,
            ];
        }

        $state = $this->stateFor($tenant);

        $state->forceFill([
            'status' => TenantInboxMonitorState::STATUS_RUNNING,
            'last_error' => null,
        ])->save();

        $totals = [
            'processed' => 0,
            'delivered' => 0,
            'skipped' => 0,
            'failed' => 0,
        ];

        try {
            $summaries = $this->gmail->searchRecentInbox($tenant);

            foreach ($summaries as $summary) {
                $totals['processed']++;
                $result = $this->processSummary($tenant, $summary);
                $totals[$result]++;
            }

            $state->forceFill([
                'status' => TenantInboxMonitorState::STATUS_IDLE,
                'last_checked_at' => now(),
                'last_failed_at' => null,
                'last_error' => null,
                'backoff_until' => null,
                'consecutive_failures' => 0,
            ])->save();

            return $totals;
        } catch (Throwable $exception) {
            $failures = (int) $state->consecutive_failures + 1;

            $state->forceFill([
                'status' => TenantInboxMonitorState::STATUS_FAILED,
                'last_failed_at' => now(),
                'last_error' => $exception->getMessage(),
                'backoff_until' => now()->addMinutes(min(60, 5 * $failures)),
                'consecutive_failures' => $failures,
            ])->save();

            Log::warning('sync360:poll-inbox-triage failed for tenant.', [
                'tenant_id' => $tenant->tenant_id,
                'tenant_slug' => $tenant->slug,
                'error' => $exception->getMessage(),
            ]);

            return [
                'processed' => $totals['processed'],
                'delivered' => $totals['delivered'],
                'skipped' => $totals['skipped'],
                'failed' => $totals['failed'] + 1,
            ];
        }
    }

    /**
     * @param  array<string, mixed>  $summary
     */
    private function processSummary(Tenant $tenant, array $summary): string
    {
        $summaryMessageId = $this->messageIdFromSummary($summary);
        $summaryLookupId = trim((string) ($summary['id'] ?? $summaryMessageId ?? ''));
        $record = null;

        if ($summaryMessageId !== null && $this->shouldSkipExisting($tenant, $summaryMessageId)) {
            return 'skipped';
        }

        try {
            $detail = $this->gmail->getMessage($tenant, $summaryLookupId);
            $metadata = $detail['metadata'];
            $messageId = trim((string) ($metadata['id'] ?? $summaryMessageId ?? ''));

            if ($messageId === '') {
                return 'skipped';
            }

            if ($this->shouldSkipExisting($tenant, $messageId)) {
                return 'skipped';
            }

            $from = (string) ($metadata['from'] ?? $summary['from'] ?? '');
            $subject = (string) ($metadata['subject'] ?? $summary['subject'] ?? '');
            $filter = $this->filter->evaluate(
                $summary,
                $metadata,
                $detail['raw'],
                $tenant->googleCredential?->google_email,
            );
            $record = $this->messageRecord($tenant, $messageId, $summary, $metadata, $from, $subject);

            if ($filter['skip']) {
                $record->forceFill([
                    'status' => TenantInboxMonitorMessage::STATUS_SKIPPED,
                    'skip_reason' => $filter['reason'],
                    'last_error' => null,
                ])->save();

                return 'skipped';
            }

            if (! $this->claimDispatch($record)) {
                return 'skipped';
            }

            $record->refresh();

            $this->messenger->sendOperational(
                $tenant,
                self::CHANNEL,
                self::FROM,
                $this->triggerMessage($tenant, $summary, $metadata, $detail['body'], $filter['hints']),
                [self::SKILL_KEY],
                [
                    'use_case' => TenantRuntimeUsageUseCase::InboxTriage,
                    'trigger_source' => 'sync360:poll-inbox-triage',
                ],
            );

            $record->forceFill([
                'status' => TenantInboxMonitorMessage::STATUS_SENT_TO_AGENT,
                'delivered_to_agent_at' => now(),
                'last_error' => null,
            ])->save();

            return 'delivered';
        } catch (Throwable $exception) {
            if ($record instanceof TenantInboxMonitorMessage) {
                $record->forceFill([
                    'status' => TenantInboxMonitorMessage::STATUS_FAILED,
                    'last_error' => $exception->getMessage(),
                ])->save();
            } elseif ($summaryMessageId !== null || $summaryLookupId !== '') {
                $failedRecord = TenantInboxMonitorMessage::query()->firstOrNew([
                    'tenant_id' => $tenant->id,
                    'gmail_message_id' => $summaryMessageId ?? $summaryLookupId,
                ]);

                $failedRecord->forceFill([
                    'status' => TenantInboxMonitorMessage::STATUS_FAILED,
                    'attempts' => (int) $failedRecord->attempts + 1,
                    'last_attempted_at' => now(),
                    'last_error' => $exception->getMessage(),
                    'detected_at' => $failedRecord->detected_at ?? now(),
                ])->save();
            }

            Log::warning('sync360:poll-inbox-triage failed for message.', [
                'tenant_id' => $tenant->tenant_id,
                'tenant_slug' => $tenant->slug,
                'gmail_message_id' => $summaryMessageId ?? $summaryLookupId ?: null,
                'error' => $exception->getMessage(),
            ]);

            return 'failed';
        }
    }

    /**
     * @param  array<string, mixed>  $summary
     * @param  array<string, mixed>  $metadata
     */
    private function messageRecord(Tenant $tenant, string $messageId, array $summary, array $metadata, string $from, string $subject): TenantInboxMonitorMessage
    {
        return TenantInboxMonitorMessage::query()->firstOrCreate(
            [
                'tenant_id' => $tenant->id,
                'gmail_message_id' => $messageId,
            ],
            [
                'gmail_thread_id' => (string) ($metadata['thread_id'] ?? $summary['thread_id'] ?? $summary['threadId'] ?? ''),
                'sender_domain' => $this->filter->senderDomain($from),
                'subject_preview' => mb_substr($subject, 0, 160),
                'subject_hash' => $subject !== '' ? hash('sha256', $subject) : null,
                'status' => TenantInboxMonitorMessage::STATUS_DETECTED,
                'detected_at' => now(),
            ],
        );
    }

    /**
     * @param  array<string, mixed>  $summary
     * @param  array<string, mixed>  $metadata
     * @param  list<string>  $mechanicalHints
     */
    private function triggerMessage(Tenant $tenant, array $summary, array $metadata, string $body, array $mechanicalHints): string
    {
        $from = (string) ($metadata['from'] ?? $summary['from'] ?? '');
        $payload = [
            'event_type' => 'business_plausible_gmail_message',
            'gmail_message_id' => (string) ($metadata['id'] ?? $summary['id'] ?? ''),
            'gmail_thread_id' => (string) ($metadata['thread_id'] ?? $summary['thread_id'] ?? $summary['threadId'] ?? ''),
            'from' => $from,
            'sender_domain' => $this->filter->senderDomain($from),
            'subject' => (string) ($metadata['subject'] ?? $summary['subject'] ?? ''),
            'date' => (string) ($metadata['date'] ?? $summary['date'] ?? ''),
            'labels' => $this->labelsForPayload($summary, $metadata),
            'mechanical_hints' => $mechanicalHints,
        ];
        $notificationContext = [
            'telegram_default_chat_id' => $this->telegramDefaultChatId($tenant),
        ];

        return implode(PHP_EOL, [
            'Internal Gmail inbox event.',
            '',
            'A business-plausible Gmail message was detected for the connected Google Workspace account.',
            'This is not a customer chat message.',
            'This is an internal operating task from Sync360.',
            '',
            'Route this event to the assigned inbox-triage skill.',
            'Read the workspace skill file at ./skills/inbox-triage/SKILL.md and follow that skill\'s instructions exactly.',
            'Make sure you follow the skill\'s Critical Runtime Contracts for Telegram, Google Drive triage logging, Google Sheets lead logging, and analytics before reporting final status.',
            'Do not look under /app/skills; Sync360 workspace skills are materialized in the current workspace.',
            'Execute the inbox-triage workflow now; do not reply with only an assessment, recommendation, or plan.',
            'When the skill requires Telegram notification, Google Drive triage logging, Google Sheets lead logging, or analytics emission, attempt those actions now and then report the exact results.',
            'Use the email metadata/body and workspace files as the primary sources for this task. Do not browse the public web or research the sender unless the operator explicitly asks for that.',
            '',
            'This trigger has not classified the email as high-value.',
            'The inbox-triage skill must decide the category, lead quality, and any next action.',
            '',
            'Available notification context:',
            json_encode($notificationContext, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?: '{}',
            '',
            'Untrusted email metadata:',
            json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?: '{}',
            '',
            'Email excerpt:',
            mb_substr(trim($body), 0, 4000),
        ]);
    }

    private function stateAllowsPolling(Tenant $tenant): bool
    {
        $state = $tenant->inboxMonitorState;

        if (! $state) {
            return true;
        }

        if (! $state->enabled) {
            return false;
        }

        return ! $state->backoff_until || $state->backoff_until->isPast();
    }

    private function stateFor(Tenant $tenant): TenantInboxMonitorState
    {
        return TenantInboxMonitorState::query()->firstOrCreate(
            ['tenant_id' => $tenant->id],
            ['enabled' => true, 'status' => TenantInboxMonitorState::STATUS_IDLE],
        );
    }

    private function messageIdFromSummary(array $summary): ?string
    {
        $id = trim((string) ($summary['message_id'] ?? $summary['messageId'] ?? ''));

        return $id !== '' ? $id : null;
    }

    private function shouldSkipExisting(Tenant $tenant, string $messageId): bool
    {
        $existing = TenantInboxMonitorMessage::query()
            ->where('tenant_id', $tenant->id)
            ->where('gmail_message_id', $messageId)
            ->first();

        if (! $existing) {
            return false;
        }

        if (in_array($existing->status, [
            TenantInboxMonitorMessage::STATUS_DISPATCHING,
            TenantInboxMonitorMessage::STATUS_SENT_TO_AGENT,
            TenantInboxMonitorMessage::STATUS_SKIPPED,
        ], true)) {
            return $existing->status !== TenantInboxMonitorMessage::STATUS_DISPATCHING
                || ! $this->dispatchLeaseExpired($existing);
        }

        return $existing->status === TenantInboxMonitorMessage::STATUS_FAILED
            && (int) $existing->attempts >= self::MAX_ATTEMPTS;
    }

    private function claimDispatch(TenantInboxMonitorMessage $record): bool
    {
        $staleCutoff = now()->subMinutes(self::DISPATCH_LEASE_MINUTES);

        $updated = TenantInboxMonitorMessage::query()
            ->whereKey($record->id)
            ->where('attempts', '<', self::MAX_ATTEMPTS)
            ->where(function ($query) use ($staleCutoff): void {
                $query->whereIn('status', [
                    TenantInboxMonitorMessage::STATUS_DETECTED,
                    TenantInboxMonitorMessage::STATUS_FAILED,
                ])->orWhere(function ($dispatchingQuery) use ($staleCutoff): void {
                    $dispatchingQuery
                        ->where('status', TenantInboxMonitorMessage::STATUS_DISPATCHING)
                        ->where(function ($leaseQuery) use ($staleCutoff): void {
                            $leaseQuery->whereNull('last_attempted_at')
                                ->orWhere('last_attempted_at', '<=', $staleCutoff);
                        });
                });
            })
            ->update([
                'status' => TenantInboxMonitorMessage::STATUS_DISPATCHING,
                'attempts' => DB::raw('attempts + 1'),
                'last_attempted_at' => now(),
                'last_error' => null,
                'updated_at' => now(),
            ]);

        return $updated === 1;
    }

    private function dispatchLeaseExpired(TenantInboxMonitorMessage $record): bool
    {
        if (! $record->last_attempted_at) {
            return true;
        }

        return $record->last_attempted_at->lte(now()->subMinutes(self::DISPATCH_LEASE_MINUTES));
    }

    /**
     * @param  array<string, mixed>  $summary
     * @param  array<string, mixed>  $metadata
     * @return list<string>
     */
    private function labelsForPayload(array $summary, array $metadata): array
    {
        $labels = $summary['labels'] ?? $summary['label_ids'] ?? $metadata['label_ids'] ?? [];

        if (is_string($labels)) {
            $labels = preg_split('/\s*,\s*/', $labels) ?: [];
        }

        return array_values(array_filter((array) $labels, 'is_string'));
    }

    private function telegramDefaultChatId(Tenant $tenant): ?string
    {
        $config = is_array($tenant->channel_config) ? $tenant->channel_config : [];
        $configured = trim((string) ($config['telegram_default_chat_id'] ?? ''));

        if ($configured !== '') {
            return $configured;
        }

        $latest = $tenant->conversationLogs()
            ->where('channel', 'telegram')
            ->whereNotNull('from_identifier')
            ->latest('id')
            ->value('from_identifier');

        return is_string($latest) && trim($latest) !== '' ? trim($latest) : null;
    }
}
