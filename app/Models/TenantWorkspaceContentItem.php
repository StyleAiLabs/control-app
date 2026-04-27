<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TenantWorkspaceContentItem extends Model
{
    use HasFactory;

    public const SOURCE_TYPE_TEXT_BLOCK = 'text_block';
    public const SOURCE_TYPE_DOCUMENT = 'document';
    public const SOURCE_TYPE_WEBSITE_SNAPSHOT = 'website_snapshot';

    public const STATUS_ACTIVE = 'active';
    public const STATUS_NEEDS_REVIEW = 'needs_review';
    public const STATUS_FAILED = 'failed';
    public const STATUS_ARCHIVED = 'archived';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'tenant_id',
        'source_type',
        'slug',
        'title',
        'status',
        'source_url',
        'source_storage_path',
        'original_filename',
        'mime_type',
        'size_bytes',
        'summary',
        'content_markdown',
        'content_json',
        'draft_markdown',
        'draft_summary',
        'draft_json',
        'source_hash',
        'draft_hash',
        'workspace_path',
        'structured_data_workspace_path',
        'last_imported_at',
        'last_published_at',
        'last_error',
    ];

    protected function casts(): array
    {
        return [
            'content_json' => 'array',
            'draft_json' => 'array',
            'size_bytes' => 'integer',
            'last_imported_at' => 'datetime',
            'last_published_at' => 'datetime',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    public function needsReview(): bool
    {
        return $this->status === self::STATUS_NEEDS_REVIEW;
    }
}
