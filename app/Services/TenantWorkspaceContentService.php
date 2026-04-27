<?php

namespace App\Services;

use App\Models\Tenant;
use App\Models\TenantWorkspaceContentItem;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Support\Uri;
use RuntimeException;

class TenantWorkspaceContentService
{
    public function __construct(
        private readonly TenantWorkspaceContentCatalogService $catalog,
        private readonly TenantWorkspaceContentDocumentParser $documentParser,
        private readonly WebScraperService $scraper,
    ) {
    }

    /**
     * @param  array<string, string|null>  $blocks
     * @return Collection<int, TenantWorkspaceContentItem>
     */
    public function saveTextBlocks(Tenant $tenant, array $blocks): Collection
    {
        $saved = collect();
        $definitions = $this->catalog->textBlocksBySlug();

        foreach ($definitions as $slug => $definition) {
            $body = trim((string) ($blocks[$slug] ?? ''));
            $item = $tenant->workspaceContentItems()
                ->where('source_type', TenantWorkspaceContentItem::SOURCE_TYPE_TEXT_BLOCK)
                ->where('slug', $slug)
                ->first();

            if ($body === '') {
                if ($item) {
                    $item->forceFill([
                        'status' => TenantWorkspaceContentItem::STATUS_ARCHIVED,
                        'content_markdown' => null,
                        'summary' => null,
                        'content_json' => null,
                        'workspace_path' => 'knowledge/text/'.$slug.'.md',
                        'structured_data_workspace_path' => null,
                        'source_hash' => null,
                    ])->save();
                }

                continue;
            }

            $markdown = '# '.$definition['title']."\n\n".$body."\n";
            $summary = mb_substr(trim(preg_replace('/\s+/', ' ', $body) ?? $body), 0, 180);

            $item ??= new TenantWorkspaceContentItem([
                'tenant_id' => $tenant->id,
                'source_type' => TenantWorkspaceContentItem::SOURCE_TYPE_TEXT_BLOCK,
                'slug' => $slug,
                'title' => $definition['title'],
            ]);

            $item->forceFill([
                'status' => TenantWorkspaceContentItem::STATUS_ACTIVE,
                'summary' => $summary,
                'content_markdown' => $markdown,
                'content_json' => null,
                'workspace_path' => 'knowledge/text/'.$slug.'.md',
                'structured_data_workspace_path' => null,
                'source_hash' => hash('sha256', $markdown),
                'last_imported_at' => now(),
                'last_published_at' => now(),
                'last_error' => null,
            ])->save();

            $saved->push($item);
        }

        return $saved;
    }

    public function storeDocument(Tenant $tenant, UploadedFile $file, ?TenantWorkspaceContentItem $existingItem = null): TenantWorkspaceContentItem
    {
        $extension = strtolower($file->getClientOriginalExtension() ?: $file->extension() ?: 'txt');
        $title = $existingItem?->title ?: $this->documentTitle($file->getClientOriginalName());
        $slug = $existingItem?->slug ?: $this->uniqueDocumentSlug($tenant, Str::slug($title) ?: 'document');
        $storagePath = sprintf(
            'tenant-workspace-content/%s/documents/%s.%s',
            $tenant->tenant_id,
            $slug,
            $extension
        );

        Storage::disk('local')->put($storagePath, $file->get());

        if ($existingItem && is_string($existingItem->source_storage_path) && trim($existingItem->source_storage_path) !== '' && trim($existingItem->source_storage_path) !== $storagePath) {
            Storage::disk('local')->delete(trim($existingItem->source_storage_path));
        }

        $parsed = $this->documentParser->parse(Storage::disk('local')->path($storagePath), $file->getClientOriginalName(), (string) $file->getMimeType());
        $workspacePath = 'knowledge/documents/'.$slug.'.md';
        $structuredDataPath = is_array($parsed['content_json']) ? 'knowledge/data/'.$slug.'.json' : null;

        $item = $existingItem ?? new TenantWorkspaceContentItem([
            'tenant_id' => $tenant->id,
            'source_type' => TenantWorkspaceContentItem::SOURCE_TYPE_DOCUMENT,
            'slug' => $slug,
            'title' => $title,
        ]);

        $item->forceFill([
            'status' => TenantWorkspaceContentItem::STATUS_ACTIVE,
            'source_storage_path' => $storagePath,
            'original_filename' => $file->getClientOriginalName(),
            'mime_type' => $file->getMimeType(),
            'size_bytes' => $file->getSize(),
            'summary' => $parsed['summary'],
            'content_markdown' => $parsed['markdown'],
            'content_json' => $parsed['content_json'],
            'workspace_path' => $workspacePath,
            'structured_data_workspace_path' => $structuredDataPath,
            'source_hash' => hash('sha256', $parsed['markdown'].json_encode($parsed['content_json'] ?? [])),
            'last_imported_at' => now(),
            'last_published_at' => now(),
            'last_error' => null,
        ])->save();

        return $item;
    }

    public function archiveDocument(TenantWorkspaceContentItem $item): void
    {
        if (is_string($item->source_storage_path) && trim($item->source_storage_path) !== '') {
            Storage::disk('local')->delete(trim($item->source_storage_path));
        }

        $item->forceFill([
            'status' => TenantWorkspaceContentItem::STATUS_ARCHIVED,
            'source_storage_path' => null,
            'content_markdown' => null,
            'content_json' => null,
            'structured_data_workspace_path' => null,
            'source_hash' => null,
        ])->save();
    }

    public function importWebsiteDraft(Tenant $tenant, string $url): TenantWorkspaceContentItem
    {
        $normalizedUrl = $this->normalizeWebsiteUrl($url);
        $this->enforceWebsiteLimit($tenant, $normalizedUrl);

        $content = trim($this->scraper->scrape($normalizedUrl));

        if ($content === '') {
            throw new RuntimeException('We could not pull any readable website content from that URL.');
        }

        $title = $this->websiteTitle($normalizedUrl);
        $slug = $this->websiteSlug($tenant, $normalizedUrl);

        $draftMarkdown = implode("\n", [
            '# Website Snapshot: '.$title,
            '',
            '- Source URL: '.$normalizedUrl,
            '- Imported At: '.now()->toIso8601String(),
            '',
            $content,
            '',
        ]);

        $item = $tenant->workspaceContentItems()
            ->where('source_type', TenantWorkspaceContentItem::SOURCE_TYPE_WEBSITE_SNAPSHOT)
            ->where('source_url', $normalizedUrl)
            ->first()
            ?? new TenantWorkspaceContentItem([
                'tenant_id' => $tenant->id,
                'source_type' => TenantWorkspaceContentItem::SOURCE_TYPE_WEBSITE_SNAPSHOT,
                'slug' => $slug,
                'title' => $title,
            ]);

        $item->forceFill([
            'status' => TenantWorkspaceContentItem::STATUS_NEEDS_REVIEW,
            'source_url' => $normalizedUrl,
            'slug' => $slug,
            'title' => $title,
            'draft_markdown' => $draftMarkdown,
            'draft_summary' => $this->websiteSummary($content),
            'draft_json' => [
                'url' => $normalizedUrl,
                'excerpt' => mb_substr($content, 0, 1200),
            ],
            'draft_hash' => hash('sha256', $draftMarkdown),
            'workspace_path' => 'knowledge/website/'.$slug.'.md',
            'last_imported_at' => now(),
            'last_error' => null,
        ])->save();

        return $item;
    }

    public function archiveWebsite(TenantWorkspaceContentItem $item): void
    {
        if ($item->source_type !== TenantWorkspaceContentItem::SOURCE_TYPE_WEBSITE_SNAPSHOT) {
            throw new RuntimeException('Only website snapshots can be removed from this section.');
        }

        $item->forceFill([
            'status' => TenantWorkspaceContentItem::STATUS_ARCHIVED,
            'content_markdown' => null,
            'content_json' => null,
            'draft_markdown' => null,
            'draft_summary' => null,
            'draft_json' => null,
            'source_hash' => null,
            'draft_hash' => null,
            'structured_data_workspace_path' => null,
            'last_error' => null,
        ])->save();
    }

    public function publishWebsiteDraft(TenantWorkspaceContentItem $item): TenantWorkspaceContentItem
    {
        if ($item->source_type !== TenantWorkspaceContentItem::SOURCE_TYPE_WEBSITE_SNAPSHOT) {
            throw new RuntimeException('Only website snapshot drafts can be published.');
        }

        if (! is_string($item->draft_markdown) || trim($item->draft_markdown) === '') {
            throw new RuntimeException('There is no website refresh waiting to be published.');
        }

        $item->forceFill([
            'status' => TenantWorkspaceContentItem::STATUS_ACTIVE,
            'content_markdown' => $item->draft_markdown,
            'summary' => $item->draft_summary,
            'content_json' => $item->draft_json,
            'source_hash' => $item->draft_hash,
            'draft_markdown' => null,
            'draft_summary' => null,
            'draft_json' => null,
            'draft_hash' => null,
            'last_published_at' => now(),
            'last_error' => null,
        ])->save();

        return $item;
    }

    private function uniqueDocumentSlug(Tenant $tenant, string $baseSlug): string
    {
        $slug = $baseSlug;
        $suffix = 2;

        while ($tenant->workspaceContentItems()
            ->where('source_type', TenantWorkspaceContentItem::SOURCE_TYPE_DOCUMENT)
            ->where('slug', $slug)
            ->exists()) {
            $slug = $baseSlug.'-'.$suffix;
            $suffix++;
        }

        return $slug;
    }

    private function documentTitle(string $filename): string
    {
        return trim(pathinfo($filename, PATHINFO_FILENAME)) ?: 'Document';
    }

    private function normalizeWebsiteUrl(string $url): string
    {
        $uri = Uri::of(trim($url));
        $normalizedPath = '/'.ltrim((string) $uri->path(), '/');

        if ($normalizedPath === '/') {
            $normalizedPath = '';
        }

        $normalized = strtolower((string) $uri->scheme()).'://'.strtolower((string) $uri->host());

        if ($uri->port()) {
            $normalized .= ':'.$uri->port();
        }

        $normalized .= $normalizedPath;

        if ((string) $uri->query() !== '') {
            $normalized .= '?'.$uri->query();
        }

        return $normalized;
    }

    private function websiteTitle(string $url): string
    {
        $uri = Uri::of($url);
        $host = strtolower((string) $uri->host());
        $path = trim((string) $uri->path(), '/');

        if ($path === '') {
            return $host;
        }

        return $host.' / '.Str::headline(str_replace(['/', '-'], ' ', $path));
    }

    private function websiteSlug(Tenant $tenant, string $url): string
    {
        $uri = Uri::of($url);
        $baseSlug = Str::slug(trim(implode(' ', array_filter([
            $uri->host(),
            trim((string) $uri->path(), '/'),
        ])))) ?: 'website';

        $existing = $tenant->workspaceContentItems()
            ->where('source_type', TenantWorkspaceContentItem::SOURCE_TYPE_WEBSITE_SNAPSHOT)
            ->where('source_url', $url)
            ->first();

        if ($existing && is_string($existing->slug) && trim($existing->slug) !== '') {
            return trim($existing->slug);
        }

        $slug = 'website-'.$baseSlug;
        $suffix = 2;

        while ($tenant->workspaceContentItems()
            ->where('source_type', TenantWorkspaceContentItem::SOURCE_TYPE_WEBSITE_SNAPSHOT)
            ->where('slug', $slug)
            ->where('source_url', '!=', $url)
            ->exists()) {
            $slug = 'website-'.$baseSlug.'-'.$suffix;
            $suffix++;
        }

        return $slug;
    }

    private function enforceWebsiteLimit(Tenant $tenant, string $normalizedUrl): void
    {
        $tenant->loadMissing('businessProfile');

        $knownUrls = $tenant->workspaceContentItems()
            ->where('source_type', TenantWorkspaceContentItem::SOURCE_TYPE_WEBSITE_SNAPSHOT)
            ->where('status', '!=', TenantWorkspaceContentItem::STATUS_ARCHIVED)
            ->pluck('source_url')
            ->filter(fn (mixed $value): bool => is_string($value) && trim($value) !== '')
            ->map(fn (string $value): string => $this->normalizeWebsiteUrl($value))
            ->values();

        $profileUrl = is_string($tenant->businessProfile?->website_url) ? trim($tenant->businessProfile->website_url) : '';

        if ($profileUrl !== '') {
            $knownUrls->push($this->normalizeWebsiteUrl($profileUrl));
        }

        $uniqueUrls = $knownUrls->unique()->values();
        $maxWebsites = (int) config('sync360.workspace_content.max_websites', 5);

        if ($uniqueUrls->contains($normalizedUrl) || $uniqueUrls->count() < $maxWebsites) {
            return;
        }

        throw new RuntimeException(sprintf(
            'You can keep up to %d websites here at once. Remove one before adding another.',
            $maxWebsites
        ));
    }

    private function websiteSummary(string $content): string
    {
        $normalized = trim(preg_replace('/\s+/', ' ', $content) ?? $content);

        return mb_substr($normalized, 0, 180).(mb_strlen($normalized) > 180 ? '…' : '');
    }
}
