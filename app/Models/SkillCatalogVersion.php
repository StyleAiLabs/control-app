<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SkillCatalogVersion extends Model
{
    use HasFactory;

    protected $fillable = [
        'skill_catalog_item_id',
        'skill_key',
        'version',
        'manifest_json',
        'is_active_published',
        'is_archived',
        'is_available',
        'discovered_at',
        'last_imported_at',
    ];

    protected function casts(): array
    {
        return [
            'manifest_json' => 'array',
            'is_active_published' => 'boolean',
            'is_archived' => 'boolean',
            'is_available' => 'boolean',
            'discovered_at' => 'datetime',
            'last_imported_at' => 'datetime',
        ];
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(SkillCatalogItem::class, 'skill_catalog_item_id');
    }

    public function tenantAssignments(): HasMany
    {
        return $this->hasMany(TenantSkillAssignment::class);
    }
}
