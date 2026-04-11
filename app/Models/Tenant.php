<?php

namespace App\Models;

use App\Enums\TenantProvisioningStatus;
use App\Enums\TrialStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

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
        'user_id',
        'server_id',
        'trial_status',
        'provisioning_status',
        'assigned_port',
        'workspace_url',
        'runtime_path',
        'litellm_virtual_key',
        'litellm_key_alias',
        'litellm_plan_name',
        'litellm_max_budget',
        'litellm_budget_duration',
        'litellm_last_synced_at',
    ];

    protected function casts(): array
    {
        return [
            'trial_status' => TrialStatus::class,
            'provisioning_status' => TenantProvisioningStatus::class,
            'assigned_port' => 'integer',
            'litellm_virtual_key' => 'encrypted',
            'litellm_max_budget' => 'decimal:2',
            'litellm_last_synced_at' => 'datetime',
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
}
