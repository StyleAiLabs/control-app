<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BusinessProfileFiles extends Model
{
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'tenant_id',
        'identity_markdown',
        'soul_markdown',
        'user_markdown',
        'bootstrap_markdown',
        'profile_markdown',
        'heartbeat_markdown',
        'generated_at',
        'synced_at',
    ];

    protected function casts(): array
    {
        return [
            'generated_at' => 'datetime',
            'synced_at' => 'datetime',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
