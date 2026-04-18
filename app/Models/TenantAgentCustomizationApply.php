<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TenantAgentCustomizationApply extends Model
{
    use HasFactory;

    public const ACTION_APPLY = 'apply';
    public const ACTION_REVERT = 'revert';

    public const STATUS_RUNNING = 'running';
    public const STATUS_APPLIED = 'applied';
    public const STATUS_FAILED = 'failed';
    public const STATUS_REVERTED = 'reverted';

    public $timestamps = false;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'tenant_agent_customization_id',
        'tenant_id',
        'applied_by',
        'action',
        'draft_version_applied',
        'input_snapshot_json',
        'before_output_hash',
        'after_output_hash',
        'status',
        'error',
        'composed_output_json',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'input_snapshot_json' => 'array',
            'composed_output_json' => 'array',
            'created_at' => 'datetime',
            'draft_version_applied' => 'integer',
        ];
    }

    public function customization(): BelongsTo
    {
        return $this->belongsTo(TenantAgentCustomization::class, 'tenant_agent_customization_id');
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function appliedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'applied_by');
    }
}
