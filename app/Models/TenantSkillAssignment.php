<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TenantSkillAssignment extends Model
{
    use HasFactory;

    protected $fillable = [
        'tenant_id',
        'skill_catalog_version_id',
        'skill_key',
        'assigned_by',
        'assigned_at',
        'is_enabled',
        'last_apply_status',
        'last_apply_error',
        'last_applied_at',
    ];

    protected function casts(): array
    {
        return [
            'assigned_at' => 'datetime',
            'is_enabled' => 'boolean',
            'last_applied_at' => 'datetime',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function catalogVersion(): BelongsTo
    {
        return $this->belongsTo(SkillCatalogVersion::class, 'skill_catalog_version_id');
    }

    public function assignedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_by');
    }
}
