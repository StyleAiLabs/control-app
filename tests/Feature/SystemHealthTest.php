<?php

namespace Tests\Feature;

use App\Jobs\RecordQueueWorkerHeartbeat;
use App\Models\SystemHealthSignal;
use App\Models\User;
use App\Services\SystemHealthService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use RuntimeException;
use Tests\TestCase;

class SystemHealthTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_overview_renders_system_health_panel(): void
    {
        $admin = $this->adminUser();

        $this->actingAs($admin)
            ->get(route('admin.index'))
            ->assertOk()
            ->assertSee('System Health')
            ->assertSee('Scheduler And Queue')
            ->assertSee('Queue Backlog')
            ->assertSee('Inbox Triage Polling');
    }

    public function test_non_admin_users_cannot_access_system_health_json(): void
    {
        $user = User::query()->create([
            'name' => 'Standard User',
            'email' => 'standard-health@example.com',
            'password' => 'secret',
            'is_admin' => false,
        ]);

        $this->actingAs($user)
            ->get(route('admin.system-health.status'))
            ->assertForbidden();
    }

    public function test_json_endpoint_reports_healthy_and_stale_heartbeats(): void
    {
        Config::set('queue.default', 'database');

        $admin = $this->adminUser();

        SystemHealthSignal::query()->create([
            'key' => SystemHealthService::KEY_SCHEDULER,
            'label' => 'Scheduler',
            'status' => SystemHealthSignal::STATUS_HEALTHY,
            'last_seen_at' => now(),
        ]);
        SystemHealthSignal::query()->create([
            'key' => SystemHealthService::KEY_QUEUE_WORKER,
            'label' => 'Queue Worker',
            'status' => SystemHealthSignal::STATUS_HEALTHY,
            'last_seen_at' => now()->subMinutes(5),
        ]);

        $this->actingAs($admin)
            ->getJson(route('admin.system-health.status'))
            ->assertOk()
            ->assertJsonPath('components.scheduler.status', 'healthy')
            ->assertJsonPath('components.queue_worker.status', 'stale')
            ->assertJsonPath('queue.status', 'healthy');
    }

    public function test_database_queue_metrics_report_backlog_and_failed_jobs(): void
    {
        Config::set('queue.default', 'database');

        DB::table('jobs')->insert([
            'queue' => 'default',
            'payload' => '{}',
            'attempts' => 0,
            'reserved_at' => null,
            'available_at' => now()->subMinutes(12)->timestamp,
            'created_at' => now()->subMinutes(12)->timestamp,
        ]);
        DB::table('jobs')->insert([
            'queue' => 'default',
            'payload' => '{}',
            'attempts' => 1,
            'reserved_at' => now()->timestamp,
            'available_at' => now()->timestamp,
            'created_at' => now()->timestamp,
        ]);

        $summary = app(SystemHealthService::class)->adminSummary();

        $this->assertSame('stale', $summary['queue']['status']);
        $this->assertSame(1, $summary['queue']['pending_count']);
        $this->assertSame(1, $summary['queue']['reserved_count']);
        $this->assertSame(0, $summary['queue']['failed_count']);

        DB::table('failed_jobs')->insert([
            'uuid' => (string) str()->uuid(),
            'connection' => 'database',
            'queue' => 'default',
            'payload' => '{}',
            'exception' => 'Boom',
            'failed_at' => now(),
        ]);

        $summary = app(SystemHealthService::class)->adminSummary();

        $this->assertSame('failing', $summary['queue']['status']);
        $this->assertSame(1, $summary['queue']['failed_count']);
    }

    public function test_non_database_queue_metrics_are_unavailable(): void
    {
        Config::set('queue.default', 'redis');

        $summary = app(SystemHealthService::class)->adminSummary();

        $this->assertSame('unavailable', $summary['queue']['status']);
        $this->assertSame('redis', $summary['queue']['driver']);
    }

    public function test_system_health_heartbeat_command_updates_scheduler_and_dispatches_worker_probe(): void
    {
        Config::set('queue.default', 'database');
        Queue::fake();

        $this->artisan('sync360:system-health-heartbeat')
            ->assertExitCode(0)
            ->expectsOutputToContain('System health heartbeat recorded.');

        $this->assertDatabaseHas('system_health_signals', [
            'key' => SystemHealthService::KEY_SCHEDULER,
            'status' => SystemHealthSignal::STATUS_HEALTHY,
        ]);
        Queue::assertPushed(RecordQueueWorkerHeartbeat::class);
    }

    public function test_queue_worker_heartbeat_job_marks_worker_seen(): void
    {
        app(RecordQueueWorkerHeartbeat::class)->handle(app(SystemHealthService::class));

        $this->assertDatabaseHas('system_health_signals', [
            'key' => SystemHealthService::KEY_QUEUE_WORKER,
            'status' => SystemHealthSignal::STATUS_HEALTHY,
        ]);
    }

    public function test_scheduled_command_recording_stores_success_and_failure_state(): void
    {
        $service = app(SystemHealthService::class);
        $service->recordScheduledStart('scheduled:test-command', 'Test Command');
        $service->recordScheduledSuccess('scheduled:test-command', 'Test Command');

        $this->assertDatabaseHas('system_health_signals', [
            'key' => 'scheduled:test-command',
            'status' => SystemHealthSignal::STATUS_HEALTHY,
            'last_error' => null,
        ]);

        $service->recordScheduledFailure('scheduled:test-command', 'Test Command', new RuntimeException('runtime failed'));

        $this->assertDatabaseHas('system_health_signals', [
            'key' => 'scheduled:test-command',
            'status' => SystemHealthSignal::STATUS_FAILING,
            'last_error' => 'runtime failed',
        ]);
    }

    private function adminUser(): User
    {
        return User::query()->create([
            'name' => 'Health Admin',
            'email' => 'health-admin@example.com',
            'password' => 'secret',
            'is_admin' => true,
        ]);
    }
}
