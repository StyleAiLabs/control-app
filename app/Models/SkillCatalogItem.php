<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class SkillCatalogItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'skill_key',
        'label',
        'description',
        'category',
        'onboarding_role',
        'is_assignable',
        'is_orphaned',
        'orphaned_warning',
        'last_imported_at',
    ];

    protected function casts(): array
    {
        return [
            'is_assignable' => 'boolean',
            'is_orphaned' => 'boolean',
            'last_imported_at' => 'datetime',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'skill_key';
    }

    public function versions(): HasMany
    {
        return $this->hasMany(SkillCatalogVersion::class);
    }

    public function activePublishedVersion(): HasOne
    {
        return $this->hasOne(SkillCatalogVersion::class)->where('is_active_published', true);
    }
}
