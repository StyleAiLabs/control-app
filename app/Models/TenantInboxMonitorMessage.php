<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TenantInboxMonitorMessage extends Model
{
    public const STATUS_DETECTED = 'detected';
    public const STATUS_DISPATCHING = 'dispatching';
    public const STATUS_SENT_TO_AGENT = 'sent_to_agent';
    public const STATUS_SKIPPED = 'skipped';
    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'tenant_id',
        'gmail_message_id',
        'gmail_thread_id',
        'sender_domain',
        'subject_preview',
        'subject_hash',
        'status',
        'skip_reason',
        'attempts',
        'detected_at',
        'delivered_to_agent_at',
        'last_attempted_at',
        'last_error',
    ];

    protected function casts(): array
    {
        return [
            'attempts' => 'integer',
            'detected_at' => 'datetime',
            'delivered_to_agent_at' => 'datetime',
            'last_attempted_at' => 'datetime',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
