<?php

namespace App\Http\Controllers;

use App\Enums\TenantProvisioningStatus;
use App\Models\Tenant;
use App\Models\TenantWorkspaceContentItem;
use App\Services\TenantAgentSyncService;
use App\Services\TenantOnboardingSkillService;
use App\Services\TenantWorkspaceContentCatalogService;
use App\Services\TenantWorkspaceContentService;
use App\Services\TenantWorkspaceDependencyHealthService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Illuminate\Support\Uri;
use Throwable;

class WorkspaceContentController extends Controller
{
    public function __construct(
        private readonly TenantWorkspaceContentService $workspaceContent,
        private readonly TenantWorkspaceContentCatalogService $catalog,
        private readonly TenantWorkspaceDependencyHealthService $dependencyHealth,
        private readonly TenantOnboardingSkillService $onboardingSkills,
        private readonly TenantAgentSyncService $agentSync,
    ) {
    }

    public function show(Request $request): View|RedirectResponse
    {
        if ($request->user()->is_admin && ! $request->user()->tenant) {
            return redirect()->route('admin.index');
        }

        $tenant = $this->tenantFor($request);
        $items = $tenant->workspaceContentItems;
        $websiteItems = $this->websiteEntries($items, $tenant->businessProfile?->website_url);

        return view('workspace-content.show', [
            'tenant' => $tenant,
            'profile' => $tenant->businessProfile,
            'canSync' => $this->canSync($tenant),
            'dependencyHealth' => $this->dependencyHealth->evaluate($tenant),
            'textBlockDefinitions' => $this->catalog->textBlocks(),
            'textBlockValues' => $this->textBlockValues($items),
            'documentItems' => $items
                ->where('source_type', TenantWorkspaceContentItem::SOURCE_TYPE_DOCUMENT)
                ->whereIn('status', [TenantWorkspaceContentItem::STATUS_ACTIVE, TenantWorkspaceContentItem::STATUS_NEEDS_REVIEW])
                ->sortByDesc('updated_at')
                ->values(),
            'websiteItems' => $websiteItems,
            'maxWebsiteCount' => (int) config('sync360.workspace_content.max_websites', 5),
            'contentSummary' => $this->contentSummary($tenant, $items),
        ]);
    }

    public function updateText(Request $request): JsonResponse
    {
        $tenant = $this->tenantFor($request);
        $payload = $request->validate([
            'blocks' => ['required', 'array'],
        ]);
        $definitions = $this->catalog->textBlocksBySlug();
        $blocks = [];

        foreach ($definitions as $slug => $definition) {
            $blocks[$slug] = is_string(data_get($payload, 'blocks.'.$slug)) ? trim((string) data_get($payload, 'blocks.'.$slug)) : '';
        }

        $this->workspaceContent->saveTextBlocks($tenant, $blocks);
        [$synced, $messageSuffix] = $this->syncLiveWorkspaceIfNeeded($tenant);

        return response()->json([
            'success' => true,
            'message' => 'Workspace quick-answer notes saved'.$messageSuffix,
            'synced' => $synced,
            'blocks' => $blocks,
            'summary' => $this->contentSummary($tenant->fresh(['workspaceContentItems']), $tenant->fresh(['workspaceContentItems'])->workspaceContentItems),
        ]);
    }

    public function uploadDocument(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'document' => ['required', 'file', 'mimes:pdf,docx,xlsx,csv,txt,md', 'max:10240'],
            'replace_item_id' => ['nullable', 'integer'],
        ]);

        $tenant = $this->tenantFor($request);
        $existingItem = null;

        if (filled($validated['replace_item_id'] ?? null)) {
            $existingItem = $tenant->workspaceContentItems()
                ->whereKey((int) $validated['replace_item_id'])
                ->where('source_type', TenantWorkspaceContentItem::SOURCE_TYPE_DOCUMENT)
                ->firstOrFail();
        }

        $item = $this->workspaceContent->storeDocument($tenant, $validated['document'], $existingItem);
        [$synced, $messageSuffix] = $this->syncLiveWorkspaceIfNeeded($tenant);

        return response()->json([
            'success' => true,
            'message' => sprintf('%s saved%s', $existingItem ? 'Document update' : 'Document', $messageSuffix),
            'synced' => $synced,
            'item' => $this->itemPayload($item->fresh()),
            'summary' => $this->contentSummary($tenant->fresh(['workspaceContentItems']), $tenant->fresh(['workspaceContentItems'])->workspaceContentItems),
        ]);
    }

    public function deleteDocument(Request $request, TenantWorkspaceContentItem $item): JsonResponse
    {
        $tenant = $this->tenantFor($request);
        abort_unless($item->tenant_id === $tenant->id && $item->source_type === TenantWorkspaceContentItem::SOURCE_TYPE_DOCUMENT, 404);

        $this->workspaceContent->archiveDocument($item);
        [$synced, $messageSuffix] = $this->syncLiveWorkspaceIfNeeded($tenant);

        return response()->json([
            'success' => true,
            'message' => 'Document removed'.$messageSuffix,
            'synced' => $synced,
            'item_id' => $item->id,
            'summary' => $this->contentSummary($tenant->fresh(['workspaceContentItems']), $tenant->fresh(['workspaceContentItems'])->workspaceContentItems),
        ]);
    }

    public function importWebsite(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'url' => ['required', 'url', 'max:500'],
        ]);

        $tenant = $this->tenantFor($request);
        try {
            $item = $this->workspaceContent->importWebsiteDraft($tenant, $validated['url']);
        } catch (Throwable $exception) {
            return response()->json([
                'success' => false,
                'message' => $exception->getMessage(),
            ], 422);
        }

        return response()->json([
            'success' => true,
            'message' => 'Website refresh imported. Review it below before publishing it to the assistant.',
            'synced' => false,
            'item' => $this->websiteItemPayload($item->fresh()),
            'website_items' => $this->websiteEntries(
                $tenant->fresh(['businessProfile', 'workspaceContentItems'])->workspaceContentItems,
                $tenant->fresh(['businessProfile'])->businessProfile?->website_url
            ),
            'summary' => $this->contentSummary($tenant->fresh(['workspaceContentItems']), $tenant->fresh(['workspaceContentItems'])->workspaceContentItems),
        ]);
    }

    public function publishWebsite(Request $request, TenantWorkspaceContentItem $item): JsonResponse
    {
        $tenant = $this->tenantFor($request);
        abort_unless($item->tenant_id === $tenant->id && $item->source_type === TenantWorkspaceContentItem::SOURCE_TYPE_WEBSITE_SNAPSHOT, 404);

        $publishedItem = $this->workspaceContent->publishWebsiteDraft($item);
        [$synced, $messageSuffix] = $this->syncLiveWorkspaceIfNeeded($tenant);

        return response()->json([
            'success' => true,
            'message' => 'Website content published'.$messageSuffix,
            'synced' => $synced,
            'item' => $this->websiteItemPayload($publishedItem->fresh()),
            'website_items' => $this->websiteEntries(
                $tenant->fresh(['businessProfile', 'workspaceContentItems'])->workspaceContentItems,
                $tenant->fresh(['businessProfile'])->businessProfile?->website_url
            ),
            'summary' => $this->contentSummary($tenant->fresh(['workspaceContentItems']), $tenant->fresh(['workspaceContentItems'])->workspaceContentItems),
        ]);
    }

    public function deleteWebsite(Request $request, TenantWorkspaceContentItem $item): JsonResponse
    {
        $tenant = $this->tenantFor($request);
        abort_unless($item->tenant_id === $tenant->id && $item->source_type === TenantWorkspaceContentItem::SOURCE_TYPE_WEBSITE_SNAPSHOT, 404);

        $this->workspaceContent->archiveWebsite($item);
        [$synced, $messageSuffix] = $this->syncLiveWorkspaceIfNeeded($tenant);
        $freshTenant = $tenant->fresh(['businessProfile', 'workspaceContentItems']);

        return response()->json([
            'success' => true,
            'message' => 'Website removed'.$messageSuffix,
            'synced' => $synced,
            'item_id' => $item->id,
            'website_items' => $this->websiteEntries($freshTenant->workspaceContentItems, $freshTenant->businessProfile?->website_url),
            'summary' => $this->contentSummary($freshTenant, $freshTenant->workspaceContentItems),
        ]);
    }

    public function syncAgent(Request $request): RedirectResponse
    {
        $tenant = $this->tenantFor($request);

        if (! $this->canSync($tenant)) {
            return redirect()->route('workspace-content.show')->with('status', 'Finish the guided setup first, then you can sync this content into the assistant.');
        }

        try {
            $this->agentSync->goLive($tenant->fresh(['businessProfile', 'businessProfileFiles', 'workspaceContentItems', 'server']));
        } catch (Throwable $exception) {
            return redirect()->route('workspace-content.show')->with('status', 'We saved your content, but could not sync the assistant: '.$exception->getMessage());
        }

        return redirect()->route('workspace-content.show')->with('status', 'The latest workspace content has been synced to the assistant.');
    }

    private function tenantFor(Request $request): Tenant
    {
        return $request->user()->tenant()
            ->with(['businessProfile', 'businessProfileFiles', 'workspaceContentItems', 'server'])
            ->firstOrFail();
    }

    /**
     * @param  Collection<int, TenantWorkspaceContentItem>  $items
     * @return array<string, string>
     */
    private function textBlockValues(Collection $items): array
    {
        $values = [];

        foreach ($this->catalog->textBlocks() as $definition) {
            $item = $items->first(function (TenantWorkspaceContentItem $item) use ($definition): bool {
                return $item->source_type === TenantWorkspaceContentItem::SOURCE_TYPE_TEXT_BLOCK
                    && $item->slug === $definition['slug']
                    && $item->status !== TenantWorkspaceContentItem::STATUS_ARCHIVED;
            });

            $values[$definition['slug']] = trim((string) $item?->content_markdown);

            if (str_starts_with($values[$definition['slug']], '# '.$definition['title'])) {
                $values[$definition['slug']] = trim((string) preg_replace('/^# .*?\n\n/s', '', $values[$definition['slug']]));
            }
        }

        return $values;
    }

    /**
     * @param  Collection<int, TenantWorkspaceContentItem>  $items
     * @return array{active_count:int,review_count:int,last_published:?string,assistant_status:string,assistant_note:string}
     */
    private function contentSummary(Tenant $tenant, Collection $items): array
    {
        $active = $items->where('status', TenantWorkspaceContentItem::STATUS_ACTIVE)->count();
        $review = $items->where('status', TenantWorkspaceContentItem::STATUS_NEEDS_REVIEW)->count();
        $lastPublishedAt = $items
            ->pluck('last_published_at')
            ->filter()
            ->sortDesc()
            ->first();

        return [
            'active_count' => $active,
            'review_count' => $review,
            'last_published' => $lastPublishedAt?->diffForHumans(),
            'assistant_status' => $tenant->agent_status === 'live' ? 'live' : ($this->canSync($tenant) ? 'ready' : 'pending'),
            'assistant_note' => $tenant->agent_status === 'live'
                ? 'Changes on this page will sync into the live assistant automatically.'
                : ($this->canSync($tenant)
                    ? 'Your setup is ready. Use a manual sync when you want this content pushed into the assistant.'
                    : 'Finish setup before relying on this content in customer-facing assistant replies.'),
        ];
    }

    /**
     * @return array{id:int,source_type:string,title:string,slug:string,status:string,summary:?string,workspace_path:?string,structured_data_workspace_path:?string,original_filename:?string,size_bytes:?int,last_imported_at:?string,last_published_at:?string,source_url:?string,draft_summary:?string,draft_markdown:?string,content_markdown:?string}
     */
    private function itemPayload(TenantWorkspaceContentItem $item): array
    {
        return [
            'id' => $item->id,
            'source_type' => $item->source_type,
            'title' => $item->title,
            'slug' => $item->slug,
            'status' => $item->status,
            'summary' => $item->summary,
            'workspace_path' => $item->workspace_path,
            'structured_data_workspace_path' => $item->structured_data_workspace_path,
            'original_filename' => $item->original_filename,
            'size_bytes' => $item->size_bytes,
            'last_imported_at' => $item->last_imported_at?->toIso8601String(),
            'last_published_at' => $item->last_published_at?->toIso8601String(),
            'source_url' => $item->source_url,
            'draft_summary' => $item->draft_summary,
            'draft_markdown' => $item->draft_markdown,
            'content_markdown' => $item->content_markdown,
        ];
    }

    /**
     * @param  Collection<int, TenantWorkspaceContentItem>  $items
     * @return array<int, array{id:?int,source_type:string,title:string,slug:?string,status:string,status_label:string,summary:?string,source_url:?string,draft_summary:?string,draft_markdown:?string,content_markdown:?string,last_imported_at:?string,last_published_at:?string,seeded_default:bool}>
     */
    private function websiteEntries(Collection $items, ?string $profileWebsiteUrl): array
    {
        $websiteItems = $items
            ->filter(fn (TenantWorkspaceContentItem $item): bool => $item->source_type === TenantWorkspaceContentItem::SOURCE_TYPE_WEBSITE_SNAPSHOT)
            ->whereIn('status', [
                TenantWorkspaceContentItem::STATUS_ACTIVE,
                TenantWorkspaceContentItem::STATUS_NEEDS_REVIEW,
            ])
            ->sortByDesc(fn (TenantWorkspaceContentItem $item): int => $item->updated_at?->getTimestamp() ?? 0)
            ->values();

        $payload = $websiteItems
            ->map(fn (TenantWorkspaceContentItem $item): array => $this->websiteItemPayload($item))
            ->all();

        $normalizedExistingUrls = $websiteItems
            ->pluck('source_url')
            ->filter(fn (mixed $value): bool => is_string($value) && trim($value) !== '')
            ->map(fn (string $value): string => $this->normalizeWebsiteUrl($value))
            ->values();

        $profileWebsiteUrl = is_string($profileWebsiteUrl) ? trim($profileWebsiteUrl) : '';

        if ($profileWebsiteUrl !== '') {
            $normalizedProfileUrl = $this->normalizeWebsiteUrl($profileWebsiteUrl);

            if (! $normalizedExistingUrls->contains($normalizedProfileUrl)) {
                array_unshift($payload, [
                    'id' => null,
                    'source_type' => TenantWorkspaceContentItem::SOURCE_TYPE_WEBSITE_SNAPSHOT,
                    'title' => $this->websiteTitle($normalizedProfileUrl),
                    'slug' => null,
                    'status' => 'neutral',
                    'status_label' => 'From profile',
                    'summary' => 'Saved during onboarding. Refresh it when you want this wording reviewed for the assistant.',
                    'source_url' => $normalizedProfileUrl,
                    'draft_summary' => null,
                    'draft_markdown' => null,
                    'content_markdown' => null,
                    'last_imported_at' => null,
                    'last_published_at' => null,
                    'seeded_default' => true,
                ]);
            }
        }

        return $payload;
    }

    /**
     * @return array{id:int,source_type:string,title:string,slug:string,status:string,status_label:string,summary:?string,source_url:?string,draft_summary:?string,draft_markdown:?string,content_markdown:?string,last_imported_at:?string,last_published_at:?string,seeded_default:bool}
     */
    private function websiteItemPayload(TenantWorkspaceContentItem $item): array
    {
        return [
            'id' => $item->id,
            'source_type' => $item->source_type,
            'title' => $item->title,
            'slug' => $item->slug,
            'status' => $item->status,
            'status_label' => str_replace('_', ' ', $item->status),
            'summary' => $item->summary,
            'source_url' => $item->source_url,
            'draft_summary' => $item->draft_summary,
            'draft_markdown' => $item->draft_markdown,
            'content_markdown' => $item->content_markdown,
            'last_imported_at' => $item->last_imported_at?->toIso8601String(),
            'last_published_at' => $item->last_published_at?->toIso8601String(),
            'seeded_default' => false,
        ];
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

    private function canSync(Tenant $tenant): bool
    {
        $channelConfig = is_array($tenant->channel_config) ? $tenant->channel_config : [];
        $this->onboardingSkills->ensureCoreAssignments($tenant, $tenant->user_id);
        $hasEnabledModules = $this->onboardingSkills->enabledModules($tenant) !== [];

        return $tenant->onboarding_status === 'complete'
            && $tenant->provisioning_status === TenantProvisioningStatus::Ready
            && filled($tenant->tone)
            && $hasEnabledModules
            && filled($tenant->runtime_path)
            && filled($tenant->workspace_url)
            && $tenant->channel === 'telegram'
            && filled($channelConfig['telegram_bot_token'] ?? null);
    }

    /**
     * @return array{0: bool, 1: string}
     */
    private function syncLiveWorkspaceIfNeeded(Tenant $tenant): array
    {
        if ($tenant->agent_status !== 'live') {
            return [false, '.'];
        }

        try {
            $this->agentSync->goLive($tenant->fresh(['businessProfile', 'businessProfileFiles', 'workspaceContentItems', 'server']));

            return [true, ' and the live assistant workspace has been synced.'];
        } catch (Throwable $exception) {
            return [false, ', but the live assistant sync failed: '.$exception->getMessage()];
        }
    }
}
