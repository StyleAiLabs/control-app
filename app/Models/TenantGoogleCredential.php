<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TenantGoogleCredential extends Model
{
    use HasFactory;

    public const STATUS_PENDING = 'pending';
    public const STATUS_CONNECTED = 'connected';
    public const STATUS_SKIPPED = 'skipped';
    public const STATUS_DISCONNECTED = 'disconnected';

    public const RUNTIME_SYNC_PENDING = 'pending';
    public const RUNTIME_SYNC_SYNCED = 'synced';
    public const RUNTIME_SYNC_VERIFIED = 'verified';
    public const RUNTIME_SYNC_FAILED = 'failed';

    public const HEALTH_NOT_CONNECTED = 'not_connected';
    public const HEALTH_SYNCING = 'syncing';
    public const HEALTH_HEALTHY = 'healthy';
    public const HEALTH_EXPIRING_SOON = 'expiring_soon';
    public const HEALTH_DEGRADED = 'degraded';
    public const HEALTH_RECONNECT_REQUIRED = 'reconnect_required';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'tenant_id',
        'status',
        'runtime_sync_status',
        'health_status',
        'google_email',
        'access_token',
        'refresh_token',
        'expires_at',
        'scopes',
        'oauth_state',
        'oauth_code_verifier',
        'oauth_state_expires_at',
        'connected_at',
        'disconnected_at',
        'last_synced_at',
        'health_checked_at',
        'last_verified_at',
        'predicted_testing_expiry_at',
        'expiry_warning_3day_sent_at',
        'expiry_warning_1day_sent_at',
        'incident_alert_sent_at',
        'incident_alert_reason',
        'last_error',
    ];

    protected function casts(): array
    {
        return [
            'access_token' => 'encrypted',
            'refresh_token' => 'encrypted',
            'scopes' => 'array',
            'oauth_code_verifier' => 'encrypted',
            'expires_at' => 'datetime',
            'oauth_state_expires_at' => 'datetime',
            'connected_at' => 'datetime',
            'disconnected_at' => 'datetime',
            'last_synced_at' => 'datetime',
            'health_checked_at' => 'datetime',
            'last_verified_at' => 'datetime',
            'predicted_testing_expiry_at' => 'datetime',
            'expiry_warning_3day_sent_at' => 'datetime',
            'expiry_warning_1day_sent_at' => 'datetime',
            'incident_alert_sent_at' => 'datetime',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function isConnected(): bool
    {
        return $this->status === self::STATUS_CONNECTED
            && filled($this->refresh_token)
            && filled($this->google_email);
    }

    public function unblocksOnboarding(): bool
    {
        return in_array($this->status, [
            self::STATUS_CONNECTED,
            self::STATUS_SKIPPED,
            self::STATUS_DISCONNECTED,
        ], true);
    }

    public function runtimeIsVerified(): bool
    {
        return $this->runtime_sync_status === self::RUNTIME_SYNC_VERIFIED;
    }

    public function runtimeNeedsAttention(): bool
    {
        return $this->runtime_sync_status === self::RUNTIME_SYNC_FAILED;
    }

    public function healthRequiresReconnect(): bool
    {
        return $this->health_status === self::HEALTH_RECONNECT_REQUIRED;
    }
}
