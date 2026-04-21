<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SystemHealthSignal extends Model
{
    public const STATUS_HEALTHY = 'healthy';
    public const STATUS_RUNNING = 'running';
    public const STATUS_FAILING = 'failing';
    public const STATUS_UNKNOWN = 'unknown';

    protected $fillable = [
        'key',
        'label',
        'status',
        'last_seen_at',
        'last_started_at',
        'last_success_at',
        'last_failed_at',
        'last_error',
        'meta_json',
    ];

    protected function casts(): array
    {
        return [
            'last_seen_at' => 'datetime',
            'last_started_at' => 'datetime',
            'last_success_at' => 'datetime',
            'last_failed_at' => 'datetime',
            'meta_json' => 'array',
        ];
    }
}
