<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TenantInboxMonitorState extends Model
{
    public const STATUS_IDLE = 'idle';
    public const STATUS_RUNNING = 'running';
    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'tenant_id',
        'enabled',
        'status',
        'last_checked_at',
        'last_failed_at',
        'last_error',
        'backoff_until',
        'consecutive_failures',
    ];

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'last_checked_at' => 'datetime',
            'last_failed_at' => 'datetime',
            'backoff_until' => 'datetime',
            'consecutive_failures' => 'integer',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
