<?php

namespace App\Models;

use App\Enums\BillingStatus;
use App\Enums\TenantProvisioningStatus;
use App\Enums\TenantRuntimeUsageUseCase;
use App\Enums\TrialStatus;
use Illuminate\Support\Carbon;
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
        'litellm_default_max_budget',
        'litellm_budget_duration',
        'litellm_default_budget_duration',
        'litellm_last_synced_at',
        'trial_ends_at',
        'litellm_spend',
        'litellm_spend_cached_at',
        'trial_80pct_notified_at',
        'trial_3day_notified_at',
        'trial_expired_notified_at',
        'allow_polling_when_trial_expired',
        'allow_runtime_replies_when_trial_expired',
        'allow_litellm_when_trial_expired',
        'billing_status',
        'billing_plan',
        'billing_started_at',
        'billing_grace_ends_at',
        'billing_cycle_anchor_at',
        'billing_cycle_ends_at',
        'billing_first_paid_at',
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
            'litellm_default_max_budget' => 'decimal:2',
            'litellm_last_synced_at' => 'datetime',
            'trial_ends_at' => 'datetime',
            'litellm_spend' => 'decimal:6',
            'litellm_spend_cached_at' => 'datetime',
            'trial_80pct_notified_at' => 'datetime',
            'trial_3day_notified_at' => 'datetime',
            'trial_expired_notified_at' => 'datetime',
            'allow_polling_when_trial_expired' => 'boolean',
            'allow_runtime_replies_when_trial_expired' => 'boolean',
            'allow_litellm_when_trial_expired' => 'boolean',
            'billing_status' => BillingStatus::class,
            'billing_started_at' => 'datetime',
            'billing_grace_ends_at' => 'datetime',
            'billing_cycle_anchor_at' => 'datetime',
            'billing_cycle_ends_at' => 'datetime',
            'billing_first_paid_at' => 'datetime',
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

    public function googleCredential(): HasOne
    {
        return $this->hasOne(TenantGoogleCredential::class);
    }

    public function agentCustomization(): HasOne
    {
        return $this->hasOne(TenantAgentCustomization::class);
    }

    public function agentCustomizationApplies(): HasMany
    {
        return $this->hasMany(TenantAgentCustomizationApply::class);
    }

    public function skillAssignments(): HasMany
    {
        return $this->hasMany(TenantSkillAssignment::class);
    }

    public function skillConversionEvents(): HasMany
    {
        return $this->hasMany(TenantSkillConversionEvent::class);
    }

    public function skillAnalyticsSyncState(): HasOne
    {
        return $this->hasOne(TenantSkillAnalyticsSyncState::class);
    }

    public function inboxMonitorState(): HasOne
    {
        return $this->hasOne(TenantInboxMonitorState::class);
    }

    public function inboxMonitorMessages(): HasMany
    {
        return $this->hasMany(TenantInboxMonitorMessage::class);
    }

    public function workspaceContentItems(): HasMany
    {
        return $this->hasMany(TenantWorkspaceContentItem::class);
    }

    public function runtimeDispatches(): HasMany
    {
        return $this->hasMany(TenantRuntimeDispatch::class);
    }

    public function runtimeUsageEvents(): HasMany
    {
        return $this->hasMany(TenantRuntimeUsageEvent::class);
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
        if ($this->isTrialExpired()) {
            return 0;
        }

        $endsAt = $this->trialEndsAt();

        return max(0, (int) now()->diffInDays($endsAt, absolute: false));
    }

    public function trialBudgetPercent(): float
    {
        $max = max(0.01, (float) ($this->litellm_max_budget ?? 5.0));

        return min(100.0, round((float) ($this->litellm_spend ?? 0.0) / $max * 100, 1));
    }

    public function trialTimePercent(): float
    {
        $endsAt = $this->trialEndsAt();
        $durationSeconds = max(1, $endsAt->diffInSeconds($this->created_at, absolute: true));
        $elapsedSeconds = min($durationSeconds, max(0, $this->created_at->diffInSeconds(now(), absolute: false)));

        return min(100.0, round($elapsedSeconds / $durationSeconds * 100, 1));
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

    public function extendTrialByDays(int $days = 7): void
    {
        $baseEndsAt = $this->trialEndsAt();
        $newEndsAt = $baseEndsAt->isFuture()
            ? $baseEndsAt->copy()->addDays($days)
            : now()->addDays($days);

        $this->forceFill([
            'trial_status' => TrialStatus::Active,
            'trial_ends_at' => $newEndsAt,
            'trial_3day_notified_at' => null,
            'trial_expired_notified_at' => null,
        ])->save();
    }

    public function hasExhaustedTrialBudget(): bool
    {
        $spend = max(0.0, (float) ($this->litellm_spend ?? 0.0));
        $maxBudget = max(0.0, (float) ($this->litellm_max_budget ?? 0.0));

        if ($this->litellm_virtual_key === null) {
            return false;
        }

        if ($maxBudget > 0.0) {
            return $spend >= $maxBudget;
        }

        return $spend > 0.0;
    }

    public function extendedTrialBudgetTarget(float $topUpAmount = 5.0): float
    {
        $spend = max(0.0, (float) ($this->litellm_spend ?? 0.0));
        $maxBudget = max(0.0, (float) ($this->litellm_max_budget ?? 0.0));

        return round(max($spend, $maxBudget) + $topUpAmount, 2);
    }

    public function savedLiteLlmBudgetTarget(): float
    {
        $saved = (float) ($this->litellm_default_max_budget ?? 0);

        if ($saved > 0.0) {
            return $saved;
        }

        return max(0.0, (float) ($this->litellm_max_budget ?? 0));
    }

    public function savedLiteLlmBudgetDuration(): ?string
    {
        $saved = is_string($this->litellm_default_budget_duration)
            ? trim($this->litellm_default_budget_duration)
            : '';

        if ($saved !== '') {
            return $saved;
        }

        $current = is_string($this->litellm_budget_duration)
            ? trim($this->litellm_budget_duration)
            : '';

        return $current !== '' ? $current : null;
    }

    public function trialEndsAt(): Carbon
    {
        return $this->trial_ends_at ?? $this->created_at->copy()->addDays((int) config('sync360.billing.trial_days', 7));
    }

    public function hasPaidActivation(): bool
    {
        return $this->billing_first_paid_at !== null;
    }

    public function isBillingActive(): bool
    {
        return $this->billing_status === BillingStatus::Active;
    }

    public function isBillingPastDue(): bool
    {
        return $this->billing_status === BillingStatus::PastDue;
    }

    public function isBillingSuspended(): bool
    {
        return $this->billing_status === BillingStatus::Suspended;
    }

    public function isBillingCancelled(): bool
    {
        return $this->billing_status === BillingStatus::Cancelled;
    }

    public function currentInteractionLimit(): ?int
    {
        $plan = is_string($this->billing_plan) ? trim($this->billing_plan) : '';

        if ($plan === '') {
            return null;
        }

        $limit = data_get(config('sync360.billing.plans', []), $plan.'.interaction_limit');

        return is_numeric($limit) && (int) $limit > 0 ? (int) $limit : null;
    }

    public function currentInteractionUsage(): int
    {
        if (! $this->hasPaidActivation()) {
            return 0;
        }

        if (! $this->billing_cycle_anchor_at || ! $this->billing_cycle_ends_at) {
            return 0;
        }

        $qualifyingUseCases = array_values(array_filter(array_map(
            static fn (mixed $value): ?string => is_string($value) && trim($value) !== '' ? trim($value) : null,
            (array) config('sync360.billing.qualifying_use_cases', array_map(
                static fn (TenantRuntimeUsageUseCase $useCase): string => $useCase->value,
                [
                    TenantRuntimeUsageUseCase::TelegramChat,
                    TenantRuntimeUsageUseCase::InboxTriage,
                    TenantRuntimeUsageUseCase::OwnerFollowup,
                    TenantRuntimeUsageUseCase::ManualRuntimeHook,
                ]
            ))
        )));

        if ($qualifyingUseCases === []) {
            return 0;
        }

        return $this->runtimeDispatches()
            ->where('dispatch_status', 'sent')
            ->whereIn('use_case', $qualifyingUseCases)
            ->whereBetween('occurred_at', [$this->billing_cycle_anchor_at, $this->billing_cycle_ends_at])
            ->count();
    }

    public function hasReachedInteractionLimit(): bool
    {
        $limit = $this->currentInteractionLimit();

        if ($limit === null || $limit <= 0) {
            return false;
        }

        return $this->currentInteractionUsage() >= $limit;
    }
}
