<?php

namespace App\Services;

use App\Jobs\RecordQueueWorkerHeartbeat;
use App\Models\SystemHealthSignal;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

class SystemHealthService
{
    public const KEY_SCHEDULER = 'scheduler';
    public const KEY_QUEUE_WORKER = 'queue_worker';

    private const SCHEDULER_STALE_SECONDS = 120;
    private const QUEUE_WORKER_STALE_SECONDS = 180;
    private const QUEUE_PENDING_STALE_SECONDS = 600;

    /**
     * @return array<string, array{label:string, threshold_seconds:int}>
     */
    public function scheduledCommands(): array
    {
        return [
            'scheduled:tenants:health-check' => [
                'label' => 'Tenant Health Check',
                'threshold_seconds' => 420,
            ],
            'scheduled:sync360:check-trial-expiry' => [
                'label' => 'Trial Expiry Check',
                'threshold_seconds' => 2400,
            ],
            'scheduled:sync360:sync-replies' => [
                'label' => 'Conversation Sync',
                'threshold_seconds' => 900,
            ],
            'scheduled:sync360:sync-skill-conversions' => [
                'label' => 'Skill Analytics Sync',
                'threshold_seconds' => 420,
            ],
            'scheduled:sync360:poll-inbox-triage' => [
                'label' => 'Inbox Triage Polling',
                'threshold_seconds' => 420,
            ],
            'scheduled:sync360:sync-runtime-costs' => [
                'label' => 'Runtime Cost Sync',
                'threshold_seconds' => 1800,
            ],
            'scheduled:sync360:monitor-workspace-dependencies' => [
                'label' => 'Workspace Dependency Monitor',
                'threshold_seconds' => 4200,
            ],
        ];
    }

    public function recordSchedulerHeartbeat(): SystemHealthSignal
    {
        return $this->touchSignal(self::KEY_SCHEDULER, 'Scheduler', [
            'status' => SystemHealthSignal::STATUS_HEALTHY,
            'last_seen_at' => now(),
            'last_error' => null,
            'meta_json' => [
                'queue_connection' => config('queue.default'),
                'recorded_at' => now()->toIso8601String(),
            ],
        ]);
    }

    public function dispatchQueueWorkerHeartbeat(): void
    {
        if (config('queue.default') === 'sync') {
            return;
        }

        RecordQueueWorkerHeartbeat::dispatch();
    }

    public function recordQueueWorkerHeartbeat(): SystemHealthSignal
    {
        return $this->touchSignal(self::KEY_QUEUE_WORKER, 'Queue Worker', [
            'status' => SystemHealthSignal::STATUS_HEALTHY,
            'last_seen_at' => now(),
            'last_error' => null,
            'meta_json' => [
                'queue_connection' => config('queue.default'),
                'recorded_at' => now()->toIso8601String(),
            ],
        ]);
    }

    public function recordScheduledStart(string $key, string $label): SystemHealthSignal
    {
        return $this->touchSignal($key, $label, [
            'status' => SystemHealthSignal::STATUS_RUNNING,
            'last_seen_at' => now(),
            'last_started_at' => now(),
        ]);
    }

    public function recordScheduledSuccess(string $key, string $label): SystemHealthSignal
    {
        return $this->touchSignal($key, $label, [
            'status' => SystemHealthSignal::STATUS_HEALTHY,
            'last_seen_at' => now(),
            'last_success_at' => now(),
            'last_error' => null,
        ]);
    }

    public function recordScheduledFailure(string $key, string $label, Throwable|string $error): SystemHealthSignal
    {
        $message = $error instanceof Throwable ? $error->getMessage() : $error;

        return $this->touchSignal($key, $label, [
            'status' => SystemHealthSignal::STATUS_FAILING,
            'last_seen_at' => now(),
            'last_failed_at' => now(),
            'last_error' => $message,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function adminSummary(): array
    {
        $signals = SystemHealthSignal::query()
            ->whereIn('key', array_merge(
                [self::KEY_SCHEDULER, self::KEY_QUEUE_WORKER],
                array_keys($this->scheduledCommands()),
            ))
            ->get()
            ->keyBy('key');

        $scheduler = $this->componentPayload(
            self::KEY_SCHEDULER,
            'Scheduler',
            $signals->get(self::KEY_SCHEDULER),
            self::SCHEDULER_STALE_SECONDS,
            'No scheduler heartbeat has been recorded yet.',
        );
        $queueWorker = $this->componentPayload(
            self::KEY_QUEUE_WORKER,
            'Queue Worker',
            $signals->get(self::KEY_QUEUE_WORKER),
            self::QUEUE_WORKER_STALE_SECONDS,
            'No queue worker heartbeat has been handled yet.',
        );
        $queue = $this->queuePayload();

        $scheduled = [];
        foreach ($this->scheduledCommands() as $key => $definition) {
            $scheduled[] = $this->scheduledPayload(
                $key,
                $definition['label'],
                $signals->get($key),
                $definition['threshold_seconds'],
            );
        }

        $statuses = array_merge(
            [$scheduler['status'], $queueWorker['status'], $queue['status']],
            array_column($scheduled, 'status'),
        );
        $overallStatus = $this->overallStatus($statuses);

        return [
            'generated_at' => now()->toIso8601String(),
            'overall_status' => $overallStatus,
            'overall_badge_class' => $this->badgeClass($overallStatus),
            'components' => [
                'scheduler' => $scheduler,
                'queue_worker' => $queueWorker,
            ],
            'queue' => $queue,
            'scheduled_commands' => $scheduled,
            'notes' => $this->notes($scheduler, $queueWorker, $queue),
        ];
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function touchSignal(string $key, string $label, array $attributes): SystemHealthSignal
    {
        $signal = SystemHealthSignal::query()->firstOrNew(['key' => $key]);
        $signal->forceFill(array_merge([
            'key' => $key,
            'label' => $label,
        ], $attributes))->save();

        return $signal;
    }

    /**
     * @return array<string, mixed>
     */
    private function componentPayload(string $key, string $label, ?SystemHealthSignal $signal, int $thresholdSeconds, string $missingMessage): array
    {
        if (! $signal || ! $signal->last_seen_at) {
            return $this->payload($key, $label, 'stale', $signal, $missingMessage);
        }

        if ($signal->last_seen_at->lt(now()->subSeconds($thresholdSeconds))) {
            return $this->payload($key, $label, 'stale', $signal, sprintf(
                '%s last checked in %s ago.',
                $label,
                $this->duration((int) $signal->last_seen_at->diffInSeconds(now())),
            ));
        }

        return $this->payload($key, $label, 'healthy', $signal, $signal->last_error);
    }

    /**
     * @return array<string, mixed>
     */
    private function scheduledPayload(string $key, string $label, ?SystemHealthSignal $signal, int $thresholdSeconds): array
    {
        if ($signal && $signal->last_failed_at && (! $signal->last_success_at || $signal->last_failed_at->gt($signal->last_success_at))) {
            return $this->payload($key, $label, 'failing', $signal, $signal->last_error ?: 'Latest scheduled run failed.');
        }

        if (! $signal || ! $signal->last_success_at) {
            return $this->payload($key, $label, 'stale', $signal, 'No successful scheduled run has been recorded yet.');
        }

        if ($signal->last_success_at->lt(now()->subSeconds($thresholdSeconds))) {
            return $this->payload($key, $label, 'stale', $signal, sprintf(
                'Last successful run was %s ago.',
                $this->duration((int) $signal->last_success_at->diffInSeconds(now())),
            ));
        }

        return $this->payload($key, $label, 'healthy', $signal, null);
    }

    /**
     * @return array<string, mixed>
     */
    private function queuePayload(): array
    {
        $driver = (string) config('queue.default');
        $table = (string) config("queue.connections.{$driver}.table", 'jobs');
        $failedTable = (string) config('queue.failed.table', 'failed_jobs');

        if ($driver !== 'database' || ! Schema::hasTable($table) || ! Schema::hasTable($failedTable)) {
            return [
                'key' => 'queue_backlog',
                'label' => 'Queue Backlog',
                'status' => 'unavailable',
                'badge_class' => $this->badgeClass('unavailable'),
                'message' => sprintf('Queue backlog metrics are unavailable for the [%s] driver.', $driver),
                'driver' => $driver,
                'pending_count' => null,
                'reserved_count' => null,
                'failed_count' => null,
                'oldest_pending_age_seconds' => null,
                'oldest_pending_age' => null,
            ];
        }

        $pendingCount = DB::table($table)->whereNull('reserved_at')->count();
        $reservedCount = DB::table($table)->whereNotNull('reserved_at')->count();
        $failedCount = DB::table($failedTable)->count();
        $oldestCreatedAt = DB::table($table)->whereNull('reserved_at')->min('created_at');
        $oldestAgeSeconds = is_numeric($oldestCreatedAt) ? max(0, now()->timestamp - (int) $oldestCreatedAt) : null;
        $status = match (true) {
            $failedCount > 0 => 'failing',
            $pendingCount > 0 && $oldestAgeSeconds !== null && $oldestAgeSeconds >= self::QUEUE_PENDING_STALE_SECONDS => 'stale',
            default => 'healthy',
        };

        return [
            'key' => 'queue_backlog',
            'label' => 'Queue Backlog',
            'status' => $status,
            'badge_class' => $this->badgeClass($status),
            'message' => $this->queueMessage($pendingCount, $reservedCount, $failedCount, $oldestAgeSeconds),
            'driver' => $driver,
            'pending_count' => $pendingCount,
            'reserved_count' => $reservedCount,
            'failed_count' => $failedCount,
            'oldest_pending_age_seconds' => $oldestAgeSeconds,
            'oldest_pending_age' => $oldestAgeSeconds !== null ? $this->duration($oldestAgeSeconds) : null,
        ];
    }

    private function queueMessage(int $pendingCount, int $reservedCount, int $failedCount, ?int $oldestAgeSeconds): string
    {
        if ($failedCount > 0) {
            return sprintf('%d failed queue job%s need attention.', $failedCount, $failedCount === 1 ? '' : 's');
        }

        if ($pendingCount > 0 && $oldestAgeSeconds !== null && $oldestAgeSeconds >= self::QUEUE_PENDING_STALE_SECONDS) {
            return sprintf('Oldest pending job has been waiting %s.', $this->duration($oldestAgeSeconds));
        }

        if ($pendingCount > 0 || $reservedCount > 0) {
            return sprintf('Pending: %d. Reserved: %d.', $pendingCount, $reservedCount);
        }

        return 'No queued or failed database jobs.';
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(string $key, string $label, string $status, ?SystemHealthSignal $signal, ?string $message): array
    {
        return [
            'key' => $key,
            'label' => $label,
            'status' => $status,
            'badge_class' => $this->badgeClass($status),
            'message' => $message,
            'last_seen_at' => $this->timestamp($signal?->last_seen_at),
            'last_started_at' => $this->timestamp($signal?->last_started_at),
            'last_success_at' => $this->timestamp($signal?->last_success_at),
            'last_failed_at' => $this->timestamp($signal?->last_failed_at),
            'last_error' => $signal?->last_error,
        ];
    }

    private function timestamp(?CarbonInterface $timestamp): ?string
    {
        return $timestamp?->toDateTimeString();
    }

    private function badgeClass(string $status): string
    {
        return match ($status) {
            'healthy' => 'ready',
            'failing' => 'failed',
            'stale' => 'queued',
            'unavailable' => 'pending',
            default => 'pending',
        };
    }

    /**
     * @param  list<string>  $statuses
     */
    private function overallStatus(array $statuses): string
    {
        if (in_array('failing', $statuses, true)) {
            return 'failing';
        }

        if (in_array('stale', $statuses, true)) {
            return 'stale';
        }

        if (in_array('unavailable', $statuses, true)) {
            return 'unavailable';
        }

        return 'healthy';
    }

    /**
     * @param  array<string, mixed>  $scheduler
     * @param  array<string, mixed>  $queueWorker
     * @param  array<string, mixed>  $queue
     * @return list<string>
     */
    private function notes(array $scheduler, array $queueWorker, array $queue): array
    {
        $notes = [];

        if ($scheduler['status'] === 'stale' && $queueWorker['status'] === 'stale') {
            $notes[] = 'Queue worker heartbeat depends on the scheduler dispatching a lightweight heartbeat job.';
        }

        if ($queue['status'] === 'unavailable') {
            $notes[] = (string) $queue['message'];
        }

        return $notes;
    }

    private function duration(int $seconds): string
    {
        if ($seconds < 60) {
            return $seconds.'s';
        }

        $minutes = intdiv($seconds, 60);
        if ($minutes < 60) {
            return $minutes.'m';
        }

        $hours = intdiv($minutes, 60);
        $remainingMinutes = $minutes % 60;

        return $remainingMinutes > 0 ? sprintf('%dh %dm', $hours, $remainingMinutes) : $hours.'h';
    }
}
