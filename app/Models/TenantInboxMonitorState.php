<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TenantInboxMonitorState extends Model
{
    public const STATUS_IDLE = 'idle';
    public const STATUS_RUNNING = 'running';
    public const STATUS_FAILED = 'failed';

    public const HEALTH_NOT_ENABLED = 'not_enabled';
    public const HEALTH_HEALTHY = 'healthy';
    public const HEALTH_DEGRADED = 'degraded';
    public const HEALTH_DOWN = 'down';

    protected $fillable = [
        'tenant_id',
        'enabled',
        'status',
        'health_status',
        'last_checked_at',
        'health_checked_at',
        'last_failed_at',
        'last_error',
        'backoff_until',
        'consecutive_failures',
        'incident_alert_sent_at',
        'incident_alert_reason',
    ];

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'last_checked_at' => 'datetime',
            'health_checked_at' => 'datetime',
            'last_failed_at' => 'datetime',
            'backoff_until' => 'datetime',
            'consecutive_failures' => 'integer',
            'incident_alert_sent_at' => 'datetime',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
