<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TenantSkillAnalyticsSyncState extends Model
{
    use HasFactory;

    protected $fillable = [
        'tenant_id',
        'last_runtime_row_id',
        'last_synced_at',
    ];

    protected function casts(): array
    {
        return [
            'last_runtime_row_id' => 'integer',
            'last_synced_at' => 'datetime',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
