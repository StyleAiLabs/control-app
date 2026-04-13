<?php

namespace App\Models;

use App\Enums\TenantProvisioningStatus;
use App\Enums\TrialStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use RuntimeException;

class Tenant extends Model
{
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'tenant_id',
        'slug',
        'business_name',
        'industry',
        'skill_pack',
        'tone',
        'capabilities',
        'channel',
        'channel_config',
        'agent_status',
        'agent_last_synced_at',
        'webhook_secret',
        'last_health_check_at',
        'last_health_check_status',
        'health_check_message',
        'user_id',
        'server_id',
        'trial_status',
        'provisioning_status',
        'onboarding_status',
        'onboarding_step',
        'assigned_port',
        'workspace_url',
        'runtime_path',
        'litellm_virtual_key',
        'litellm_key_alias',
        'litellm_plan_name',
        'litellm_max_budget',
        'litellm_budget_duration',
        'litellm_last_synced_at',
        'trial_ends_at',
        'litellm_spend',
        'litellm_spend_cached_at',
        'trial_80pct_notified_at',
        'trial_3day_notified_at',
        'trial_expired_notified_at',
    ];

    protected function casts(): array
    {
        return [
            'trial_status' => TrialStatus::class,
            'provisioning_status' => TenantProvisioningStatus::class,
            'capabilities' => 'array',
            'channel_config' => 'encrypted:array',
            'assigned_port' => 'integer',
            'onboarding_step' => 'integer',
            'agent_last_synced_at' => 'datetime',
            'last_health_check_at' => 'datetime',
            'litellm_virtual_key' => 'encrypted',
            'litellm_max_budget' => 'decimal:2',
            'litellm_last_synced_at' => 'datetime',
            'trial_ends_at' => 'datetime',
            'litellm_spend' => 'decimal:6',
            'litellm_spend_cached_at' => 'datetime',
            'trial_80pct_notified_at' => 'datetime',
            'trial_3day_notified_at' => 'datetime',
            'trial_expired_notified_at' => 'datetime',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }

    public function provisioningJobs(): HasMany
    {
        return $this->hasMany(ProvisioningJob::class);
    }

    public function conversationLogs(): HasMany
    {
        return $this->hasMany(ConversationLog::class);
    }

    public function businessProfile(): HasOne
    {
        return $this->hasOne(BusinessProfile::class);
    }

    public function businessProfileFiles(): HasOne
    {
        return $this->hasOne(BusinessProfileFiles::class);
    }

    public function workspaceHost(): string
    {
        $this->loadMissing('server');

        $baseDomain = trim((string) $this->server?->workspace_base_domain, '.');

        if ($baseDomain === '') {
            throw new RuntimeException('Tenant workspace base domain is not configured.');
        }

        return sprintf('%s.%s', $this->slug, $baseDomain);
    }

    // -------------------------------------------------------------------------
    // Trial accessors
    // -------------------------------------------------------------------------

    public function isTrialExpired(): bool
    {
        return $this->trial_status === TrialStatus::Expired;
    }

    public function trialDaysLeft(): int
    {
        if (! $this->trial_ends_at || $this->isTrialExpired()) {
            return 0;
        }

        return max(0, (int) now()->diffInDays($this->trial_ends_at, absolute: false));
    }

    public function trialBudgetPercent(): float
    {
        $max = max(0.01, (float) ($this->litellm_max_budget ?? 5.0));

        return min(100.0, round((float) ($this->litellm_spend ?? 0.0) / $max * 100, 1));
    }

    public function trialTimePercent(): float
    {
        $elapsed = (int) $this->created_at->diffInDays(now());

        return min(100.0, round($elapsed / 14 * 100, 1));
    }

    public function trialUrgency(): string
    {
        $pct = max($this->trialBudgetPercent(), $this->trialTimePercent());

        return match (true) {
            $pct >= 85 => 'critical',
            $pct >= 60 => 'warning',
            default    => 'ok',
        };
    }
}
