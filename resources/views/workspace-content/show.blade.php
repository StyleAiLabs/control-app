<x-layouts.app title="Workspace Content — Sync360">
    @php
        $dependencyAlert = $dependencyHealth['customer_alerts'][0] ?? null;
        $dependencyCta = $dependencyHealth['primary_cta'] ?? null;
        $websiteUrl = old('website_url', $profile?->website_url ?: '');
        $filledTextBlockCount = collect($textBlockValues)->filter(fn ($value) => filled(trim((string) $value)))->count();
        $websiteCount = count($websiteItems);
        $websiteSlotsRemaining = max($maxWebsiteCount - $websiteCount, 0);
        $statusMessage = (string) session('status', '');
        $statusTone = str_contains(strtolower($statusMessage), 'could not')
            ? 'error'
            : ($contentSummary['assistant_status'] === 'pending' ? 'warning' : 'success');
        $statusLabel = match (true) {
            $statusTone === 'error' => 'Needs attention',
            $contentSummary['assistant_status'] === 'live' => 'Live assistant',
            $contentSummary['assistant_status'] === 'ready' => 'Ready to sync',
            default => 'Finish setup',
        };
        $statusNote = $statusMessage !== ''
            ? $statusMessage
            : 'Add the answers, documents, and website wording customers ask about most often so the assistant can respond with confidence.';
        $contentRail = [
            [
                'label' => 'Ready to use',
                'status' => $contentSummary['active_count'] > 0 ? 'success' : 'neutral',
                'value' => $contentSummary['active_count'].' item'.($contentSummary['active_count'] === 1 ? '' : 's'),
                'note' => 'Approved notes the assistant can answer from today.',
                'dom_id' => 'workspace-content-metric-active',
            ],
            [
                'label' => 'Needs review',
                'status' => $contentSummary['review_count'] > 0 ? 'warning' : 'neutral',
                'value' => $contentSummary['review_count'].' item'.($contentSummary['review_count'] === 1 ? '' : 's'),
                'note' => 'Usually website refreshes waiting for your approval.',
                'dom_id' => 'workspace-content-metric-review',
            ],
            [
                'label' => 'Last approved',
                'status' => $contentSummary['last_published'] ? 'success' : 'neutral',
                'value' => $contentSummary['last_published'] ?: 'Not yet',
                'note' => 'Your most recent approved content change.',
                'dom_id' => 'workspace-content-metric-published',
            ],
            [
                'label' => 'Assistant sync',
                'status' => $contentSummary['assistant_status'],
                'value' => match ($contentSummary['assistant_status']) {
                    'live' => 'Live',
                    'ready' => 'Ready',
                    default => 'Setup first',
                },
                'note' => $contentSummary['assistant_note'],
                'dom_id' => 'workspace-content-metric-assistant',
            ],
        ];
    @endphp

    <div class="customer-workspace-shell">
        <header class="customer-workspace-hero">
            <div class="customer-workspace-hero__copy">
                <span class="eyebrow">Workspace Content</span>
                <h2>Give your assistant the business context it should trust.</h2>
                <p>Keep repeat answers, reference files, and website wording in one calm place so customer emails can be handled faster and with fewer guessy replies.</p>
            </div>

            <div class="customer-workspace-hero__actions">
                <x-ui.button :href="route('profile.show')" variant="secondary" icon="arrow-left">
                    Back to Profile
                </x-ui.button>

                @if (filled($tenant->workspace_url))
                    <x-ui.button :href="$tenant->workspace_url" variant="secondary" icon="external-link" icon-position="after" rel="noreferrer">
                        Open Sync360 Workspace
                    </x-ui.button>
                @endif

                @if ($canSync)
                    <form method="POST" action="{{ route('workspace-content.sync-agent') }}" id="workspace-content-sync-form">
                        @csrf
                        <x-ui.button type="submit" id="workspace-content-sync-button" icon="refresh-cw">
                            Sync Assistant
                        </x-ui.button>
                    </form>
                @endif
            </div>
        </header>

        <x-ui.health-rail :items="$contentRail" class="customer-workspace-rail" />

        <x-ui.setup-status
            class="customer-workspace-status"
            :status="$statusLabel"
            :note="$statusNote"
            :tone="$statusTone"
            :visible="true"
            status-id="workspace-content-status-pill"
            note-id="workspace-content-status-note"
        />

        <section class="customer-workspace-layout">
            <div class="customer-workspace-main">
                <x-ui.panel
                    variant="subtle"
                    title="Business facts"
                    description="Your profile still owns the business basics. This page is for the supporting details the assistant needs when answering customers."
                >
                    <x-slot:actions>
                        <x-ui.button :href="route('profile.show')" variant="secondary">
                            Edit Profile
                        </x-ui.button>
                    </x-slot:actions>

                    <div class="customer-workspace-facts-grid">
                        <div class="customer-workspace-fact">
                            <span class="type-label">Business</span>
                            <strong>{{ $profile?->business_name ?: $tenant->business_name }}</strong>
                        </div>
                        <div class="customer-workspace-fact">
                            <span class="type-label">Trading as</span>
                            <strong>{{ $profile?->trading_name ?: 'Not provided yet' }}</strong>
                        </div>
                        <div class="customer-workspace-fact">
                            <span class="type-label">Contact</span>
                            <strong>{{ $profile?->contact_email ?: $tenant->user?->email ?: 'Not provided yet' }}</strong>
                        </div>
                        <div class="customer-workspace-fact">
                            <span class="type-label">Website</span>
                            <strong>{{ $profile?->website_url ?: 'Not provided yet' }}</strong>
                        </div>
                    </div>
                </x-ui.panel>

                    <x-ui.panel
                        variant="subtle"
                        title="Quick answers"
                        description="Write down the short answers and guardrails you repeat most often so the assistant can stay quick and consistent."
                    >
                        <x-slot:actions>
                            <span class="customer-workspace-panel-meta">{{ $filledTextBlockCount }} of {{ count($textBlockDefinitions) }} filled in</span>
                        </x-slot:actions>

                        <form id="workspace-content-text-form" class="customer-workspace-text-form">
                            @csrf

                            <div class="customer-workspace-text-grid">
                                @foreach ($textBlockDefinitions as $block)
                                    <label class="customer-workspace-text-card">
                                        <span class="customer-workspace-text-card__title">{{ $block['title'] }}</span>
                                        <small class="hint">{{ $block['summary'] }}</small>
                                        <textarea
                                            name="blocks[{{ $block['slug'] }}]"
                                            rows="5"
                                            placeholder="{{ $block['placeholder'] }}"
                                        >{{ $textBlockValues[$block['slug']] ?? '' }}</textarea>
                                    </label>
                                @endforeach
                            </div>

                            <div class="customer-workspace-panel-footer">
                                <div class="customer-workspace-panel-footer__copy">
                                    <strong>Keep this practical.</strong>
                                    <span>Short grounded notes work better than long internal documents pasted into every field.</span>
                                </div>

                                <x-ui.button type="submit" id="workspace-content-text-save-button" icon="save">
                                    Save Quick Answers
                                </x-ui.button>
                            </div>
                        </form>
                    </x-ui.panel>

            </div>

            <aside class="customer-workspace-sidebar">
                @if ($dependencyAlert)
                    <x-ui.panel
                        variant="subtle"
                        title="{{ $dependencyAlert['title'] }}"
                        description="{{ $dependencyAlert['message'] }}"
                    >
                        @if ($dependencyCta)
                            <x-ui.button :href="$dependencyCta['href']" variant="secondary">
                                {{ $dependencyCta['text'] }}
                            </x-ui.button>
                        @endif
                    </x-ui.panel>
                @endif

                <x-ui.panel
                    variant="subtle"
                    title="What to add first"
                    description="A few strong inputs go further than a giant dump of files."
                >
                    <ol class="customer-workspace-steps">
                        <li><strong>Write the repeat answers.</strong> Start with pricing, service boundaries, and escalation rules.</li>
                        <li><strong>Upload one trusted file.</strong> Pick the sheet or PDF your team reaches for most often.</li>
                        <li><strong>Refresh the website only when needed.</strong> Review the draft, then publish when it looks right.</li>
                    </ol>
                </x-ui.panel>

                    <x-ui.panel
                        variant="subtle"
                        title="Documents"
                        description="Pin the files your team actually relies on, like a pricing sheet, policy PDF, scope checklist, or spreadsheet."
                    >
                        <div class="customer-workspace-toolbar">
                            <div class="customer-workspace-toolbar__copy">
                                <strong>Best first upload</strong>
                                <p class="hint">Start with the one document you reference most often when replying to customers.</p>
                            </div>

                            <div class="customer-workspace-toolbar__actions">
                                <span class="customer-workspace-toolbar__spec">PDF, DOCX, XLSX, CSV, TXT, or MD up to 10 MB</span>
                                <input type="file" id="workspace-content-document-input" accept=".pdf,.docx,.xlsx,.csv,.txt,.md" hidden>
                                <x-ui.button type="button" id="workspace-content-document-upload-button" icon="upload">
                                    Upload Document
                                </x-ui.button>
                            </div>
                        </div>

                        <div class="customer-workspace-item-list" id="workspace-content-document-list">
                            @forelse ($documentItems as $item)
                                <details class="customer-workspace-item" data-item-id="{{ $item->id }}">
                                    <summary class="customer-workspace-item__summary">
                                        <div class="customer-workspace-item__identity">
                                            <strong>{{ $item->title }}</strong>
                                            <p class="hint">{{ $item->summary ?: 'Imported reference document.' }}</p>
                                        </div>

                                        <div class="customer-workspace-item__summary-meta">
                                            <x-ui.badge :status="$item->status">{{ str_replace('_', ' ', $item->status) }}</x-ui.badge>
                                            <span class="hint">{{ $item->last_published_at?->diffForHumans() ?: 'Not approved yet' }}</span>
                                        </div>
                                    </summary>

                                    <div class="customer-workspace-item__body">
                                        <dl class="customer-workspace-item__facts">
                                            <div>
                                                <dt>File</dt>
                                                <dd>{{ $item->original_filename ?: 'Document' }}</dd>
                                            </div>
                                            <div>
                                                <dt>Imported</dt>
                                                <dd>{{ $item->last_imported_at?->diffForHumans() ?: 'Just now' }}</dd>
                                            </div>
                                            <div>
                                                <dt>Last approved</dt>
                                                <dd>{{ $item->last_published_at?->diffForHumans() ?: 'Not approved yet' }}</dd>
                                            </div>
                                            <div>
                                                <dt>Sheet data</dt>
                                                <dd>{{ $item->structured_data_workspace_path ? 'Included for lookups' : 'Not needed' }}</dd>
                                            </div>
                                        </dl>

                                        <div class="customer-workspace-item__actions">
                                            <x-ui.button type="button" variant="secondary" class="workspace-content-replace-document" data-item-id="{{ $item->id }}">
                                                Replace
                                            </x-ui.button>
                                            <x-ui.button type="button" variant="secondary" class="workspace-content-remove-document" data-item-id="{{ $item->id }}">
                                                Remove
                                            </x-ui.button>
                                        </div>
                                    </div>
                                </details>
                            @empty
                                <div class="customer-workspace-empty" id="workspace-content-document-empty">
                                    <strong>No documents uploaded yet.</strong>
                                    <p class="hint">Add the pricing sheet, policy PDF, or service checklist you trust most.</p>
                                </div>
                            @endforelse
                        </div>
                    </x-ui.panel>

                <x-ui.panel
                    variant="subtle"
                    title="What the assistant uses"
                    description="Keep the role of each page clear so updates stay easy to manage."
                >
                    <div class="customer-workspace-reference-list">
                        <div class="customer-workspace-reference">
                            <span class="type-label">Profile</span>
                            <strong>Business basics, contact details, hours, tone, and logo.</strong>
                        </div>
                        <div class="customer-workspace-reference">
                            <span class="type-label">Workspace Content</span>
                            <strong>Repeat answers, reference documents, and reviewed website wording.</strong>
                        </div>
                    </div>
                </x-ui.panel>
            </aside>
        </section>

        <x-ui.panel
            variant="subtle"
            title="Website content"
            description="Pull in public website wording only when you want it reviewed. Each site stays separate so the assistant can trust the right wording."
            class="customer-workspace-fullwidth"
        >
            <x-slot:actions>
                <span class="customer-workspace-panel-meta" id="workspace-content-website-count">
                    {{ $websiteCount }} of {{ $maxWebsiteCount }} website{{ $maxWebsiteCount === 1 ? '' : 's' }}
                </span>
            </x-slot:actions>

            <div class="customer-workspace-toolbar customer-workspace-toolbar--websites">
                <div class="customer-workspace-toolbar__copy">
                    <strong>Review before trust</strong>
                    <p class="hint">The onboarding website is shown here too. Refresh any saved site when you want its latest customer-facing wording reviewed for the assistant.</p>
                </div>

                <div class="customer-workspace-toolbar__actions">
                    <span class="customer-workspace-toolbar__spec" id="workspace-content-website-limit-note">
                        Up to {{ $maxWebsiteCount }} websites. Each refresh stays in review until you publish it.
                    </span>
                    <x-ui.button
                        type="button"
                        variant="secondary"
                        id="workspace-content-website-toggle-button"
                        :hidden="$websiteSlotsRemaining === 0"
                    >
                        + Add Website
                    </x-ui.button>
                </div>
            </div>

            <form
                id="workspace-content-website-form"
                class="customer-workspace-website-composer"
                {{ $websiteCount > 0 ? 'hidden' : '' }}
            >
                @csrf

                <label class="customer-workspace-text-card customer-workspace-text-card--wide">
                    <span class="customer-workspace-text-card__title">Website URL</span>
                    <small class="hint">Use the public page or domain you want reviewed again. Adding a site does not publish it to the assistant automatically.</small>
                    <input type="url" name="url" value="{{ $websiteUrl }}" placeholder="https://example.com">
                </label>

                <div class="customer-workspace-panel-footer">
                    <div class="customer-workspace-panel-footer__copy">
                        <strong>Keep it customer-facing.</strong>
                        <span>Use public pages with pricing, service details, FAQs, or policies your team wants the assistant to reference.</span>
                    </div>

                    <div class="customer-workspace-item__actions">
                        <x-ui.button type="button" variant="secondary" id="workspace-content-website-cancel-button">
                            Cancel
                        </x-ui.button>
                        <x-ui.button type="submit" id="workspace-content-website-import-button" icon="refresh-cw">
                            Add Website
                        </x-ui.button>
                    </div>
                </div>
            </form>

            <div class="customer-workspace-website-grid" id="workspace-content-website-list">
                @forelse ($websiteItems as $item)
                    @php
                        $isSeededDefault = (bool) ($item['seeded_default'] ?? false);
                        $hasDraft = filled($item['draft_markdown'] ?? null);
                        $hasApproved = filled($item['content_markdown'] ?? null);
                        $status = (string) ($item['status'] ?? 'neutral');
                        $statusLabel = (string) ($item['status_label'] ?? str_replace('_', ' ', $status));
                    @endphp

                    <article
                        class="customer-workspace-website-card"
                        @if (filled($item['id'] ?? null)) data-item-id="{{ $item['id'] }}" @endif
                        data-source-url="{{ $item['source_url'] }}"
                    >
                        <div class="customer-workspace-website-card__header">
                            <div class="customer-workspace-website-card__identity">
                                <strong>{{ $item['title'] }}</strong>
                                <p class="hint">{{ $item['source_url'] }}</p>
                            </div>

                            <x-ui.badge :status="$isSeededDefault ? 'neutral' : $status">
                                {{ $statusLabel }}
                            </x-ui.badge>
                        </div>

                        <p class="customer-workspace-website-card__summary">
                            {{ $item['draft_summary'] ?: $item['summary'] ?: 'Saved during onboarding. Refresh it when you want website wording reviewed for the assistant.' }}
                        </p>

                        <dl class="customer-workspace-website-card__facts">
                            <div>
                                <dt>Assistant view</dt>
                                <dd>{{ $hasApproved ? 'Approved snapshot available' : 'Not published yet' }}</dd>
                            </div>
                            <div>
                                <dt>Last review</dt>
                                <dd>{{ $item['last_imported_at'] ? \Illuminate\Support\Carbon::parse($item['last_imported_at'])->diffForHumans() : 'Not reviewed yet' }}</dd>
                            </div>
                            <div>
                                <dt>Last approved</dt>
                                <dd>{{ $item['last_published_at'] ? \Illuminate\Support\Carbon::parse($item['last_published_at'])->diffForHumans() : 'Not approved yet' }}</dd>
                            </div>
                        </dl>

                        <div class="customer-workspace-item__actions">
                            <x-ui.button
                                type="button"
                                variant="secondary"
                                class="workspace-content-refresh-website"
                                data-url="{{ $item['source_url'] }}"
                            >
                                {{ $isSeededDefault ? 'Review This Website' : 'Refresh Review' }}
                            </x-ui.button>

                            @if ($hasDraft && filled($item['id'] ?? null))
                                <x-ui.button
                                    type="button"
                                    class="workspace-content-publish-website"
                                    data-item-id="{{ $item['id'] }}"
                                >
                                    Publish Review
                                </x-ui.button>
                            @endif

                            @if (filled($item['id'] ?? null))
                                <x-ui.button
                                    type="button"
                                    variant="secondary"
                                    class="workspace-content-remove-website"
                                    data-item-id="{{ $item['id'] }}"
                                >
                                    Remove
                                </x-ui.button>
                            @endif
                        </div>

                        @if ($hasDraft || $hasApproved)
                            <details class="customer-workspace-preview-details">
                                <summary>{{ $hasDraft ? 'Preview latest review' : 'Preview approved excerpt' }}</summary>
                                <pre>{{ \Illuminate\Support\Str::limit($hasDraft ? (string) $item['draft_markdown'] : (string) $item['content_markdown'], 900) }}</pre>
                            </details>
                        @endif
                    </article>
                @empty
                    <div class="customer-workspace-empty customer-workspace-empty--wide" id="workspace-content-website-empty">
                        <strong>No websites added yet.</strong>
                        <p class="hint">Add up to {{ $maxWebsiteCount }} customer-facing sites or pages when you want the assistant to answer from their wording.</p>
                    </div>
                @endforelse
            </div>
        </x-ui.panel>
    </div>

    <script>
        const csrfToken = @json(csrf_token());
        const textForm = document.getElementById('workspace-content-text-form');
        const textSaveButton = document.getElementById('workspace-content-text-save-button');
        const documentInput = document.getElementById('workspace-content-document-input');
        const documentUploadButton = document.getElementById('workspace-content-document-upload-button');
        const websiteForm = document.getElementById('workspace-content-website-form');
        const websiteImportButton = document.getElementById('workspace-content-website-import-button');
        const websiteToggleButton = document.getElementById('workspace-content-website-toggle-button');
        const websiteCancelButton = document.getElementById('workspace-content-website-cancel-button');
        const websiteUrlInput = websiteForm?.querySelector('input[name="url"]');
        const websiteList = document.getElementById('workspace-content-website-list');
        const websiteCount = document.getElementById('workspace-content-website-count');
        const websiteLimitNote = document.getElementById('workspace-content-website-limit-note');
        const statusPill = document.getElementById('workspace-content-status-pill');
        const statusNote = document.getElementById('workspace-content-status-note');
        const documentList = document.getElementById('workspace-content-document-list');
        const maxWebsiteCount = @json($maxWebsiteCount);
        let replacingDocumentId = null;

        const endpoints = {
            textUpdate: @json(route('workspace-content.text.update')),
            documentUpload: @json(route('workspace-content.documents.upload')),
            websiteImport: @json(route('workspace-content.website.import')),
        };

        function escapeHtml(value) {
            return String(value ?? '')
                .replaceAll('&', '&amp;')
                .replaceAll('<', '&lt;')
                .replaceAll('>', '&gt;')
                .replaceAll('"', '&quot;')
                .replaceAll("'", '&#039;');
        }

        function bytesLabel(size) {
            if (!size) {
                return '';
            }

            if (size < 1024) {
                return `${size} B`;
            }

            if (size < 1024 * 1024) {
                return `${(size / 1024).toFixed(1)} KB`;
            }

            return `${(size / (1024 * 1024)).toFixed(1)} MB`;
        }

        function statusPillTone(tone) {
            if (tone === 'error') {
                return 'error';
            }

            if (tone === 'working') {
                return 'warning';
            }

            return 'success';
        }

        function statusPillLabel(tone) {
            if (tone === 'error') {
                return 'Needs attention';
            }

            if (tone === 'working') {
                return 'Working';
            }

            return 'Updated';
        }

        function setStatus(message, tone = 'default') {
            if (statusNote) {
                statusNote.textContent = message;
            }

            if (statusPill) {
                const pillTone = statusPillTone(tone);
                statusPill.textContent = statusPillLabel(tone);
                statusPill.className = `wizard-status-pill wizard-status-pill--${pillTone}`;
            }
        }

        function setMetric(metricId, value, note) {
            const valueNode = document.getElementById(`${metricId}-value`);
            const noteNode = document.getElementById(`${metricId}-note`);

            if (valueNode && value !== undefined) {
                valueNode.textContent = value;
            }

            if (noteNode && note !== undefined) {
                noteNode.textContent = note;
            }
        }

        function updateSummary(summary) {
            if (!summary) {
                return;
            }

            setMetric(
                'workspace-content-metric-active',
                `${summary.active_count} item${summary.active_count === 1 ? '' : 's'}`,
                'Approved notes the assistant can answer from today.'
            );
            setMetric(
                'workspace-content-metric-review',
                `${summary.review_count} item${summary.review_count === 1 ? '' : 's'}`,
                'Usually website refreshes waiting for your approval.'
            );
            setMetric(
                'workspace-content-metric-published',
                summary.last_published || 'Not yet',
                'Your most recent approved content change.'
            );

            const assistantValue = summary.assistant_status === 'live'
                ? 'Live'
                : (summary.assistant_status === 'ready' ? 'Ready' : 'Setup first');

            setMetric('workspace-content-metric-assistant', assistantValue, summary.assistant_note);
        }

        function renderErrors(result, fallback) {
            if (result?.errors && typeof result.errors === 'object') {
                const messages = Object.values(result.errors).flat().filter(Boolean);

                if (messages.length > 0) {
                    return messages.join(' ');
                }
            }

            return result?.message || fallback;
        }

        function documentCardMarkup(item) {
            const importedLabel = item.last_imported_at
                ? new Date(item.last_imported_at).toLocaleString()
                : 'Just now';
            const approvedLabel = item.last_published_at
                ? new Date(item.last_published_at).toLocaleString()
                : 'Not approved yet';

            return `
                <details class="customer-workspace-item" data-item-id="${item.id}">
                    <summary class="customer-workspace-item__summary">
                        <div class="customer-workspace-item__identity">
                            <strong>${escapeHtml(item.title)}</strong>
                            <p class="hint">${escapeHtml(item.summary || 'Imported reference document.')}</p>
                        </div>
                        <div class="customer-workspace-item__summary-meta">
                            <span class="badge dui-badge dui-badge-sm uppercase tracking-[0.035em] ${item.status === 'needs_review' ? 'dui-badge-warning' : 'dui-badge-success'}">${escapeHtml(item.status.replaceAll('_', ' '))}</span>
                            <span class="hint">${escapeHtml(item.last_published_at ? new Date(item.last_published_at).toLocaleString() : 'Not approved yet')}</span>
                        </div>
                    </summary>
                    <div class="customer-workspace-item__body">
                        <dl class="customer-workspace-item__facts">
                            <div>
                                <dt>File</dt>
                                <dd>${escapeHtml(item.original_filename || 'Document')}${item.size_bytes ? ` (${escapeHtml(bytesLabel(item.size_bytes))})` : ''}</dd>
                            </div>
                            <div>
                                <dt>Imported</dt>
                                <dd>${escapeHtml(importedLabel)}</dd>
                            </div>
                            <div>
                                <dt>Last approved</dt>
                                <dd>${escapeHtml(approvedLabel)}</dd>
                            </div>
                            <div>
                                <dt>Sheet data</dt>
                                <dd>${item.structured_data_workspace_path ? 'Included for lookups' : 'Not needed'}</dd>
                            </div>
                        </dl>
                        <div class="customer-workspace-item__actions">
                            <button type="button" class="button button--secondary workspace-content-replace-document" data-item-id="${item.id}">Replace</button>
                            <button type="button" class="button button--secondary workspace-content-remove-document" data-item-id="${item.id}">Remove</button>
                        </div>
                    </div>
                </details>
            `;
        }

        function upsertDocumentItem(item) {
            if (!documentList || !item) {
                return;
            }

            const emptyState = documentList.querySelector('#workspace-content-document-empty');

            if (emptyState) {
                emptyState.remove();
            }

            const existing = documentList.querySelector(`[data-item-id="${item.id}"]`);

            if (existing) {
                existing.outerHTML = documentCardMarkup(item);
                return;
            }

            documentList.insertAdjacentHTML('afterbegin', documentCardMarkup(item));
        }

        function removeDocumentItem(itemId) {
            if (!documentList) {
                return;
            }

            const existing = documentList.querySelector(`[data-item-id="${itemId}"]`);

            if (existing) {
                existing.remove();
            }

            if (!documentList.querySelector('.customer-workspace-item')) {
                documentList.innerHTML = `
                    <div class="customer-workspace-empty" id="workspace-content-document-empty">
                        <strong>No documents uploaded yet.</strong>
                        <p class="hint">Add the pricing sheet, policy PDF, or service checklist you trust most.</p>
                    </div>
                `;
            }
        }

        function websiteBadgeClass(item) {
            if (item.seeded_default) {
                return 'dui-badge-neutral';
            }

            if (item.status === 'needs_review') {
                return 'dui-badge-warning';
            }

            return 'dui-badge-success';
        }

        function relativeTimeLabel(value, fallback) {
            if (!value) {
                return fallback;
            }

            const date = new Date(value);

            if (Number.isNaN(date.getTime())) {
                return fallback;
            }

            return date.toLocaleString();
        }

        function websiteCardMarkup(item) {
            const hasDraft = Boolean(item.draft_markdown);
            const hasApproved = Boolean(item.content_markdown);
            const summary = item.draft_summary || item.summary || 'Saved during onboarding. Refresh it when you want website wording reviewed for the assistant.';
            const previewLabel = hasDraft ? 'Preview latest review' : 'Preview approved excerpt';
            const previewBody = hasDraft ? item.draft_markdown : item.content_markdown;
            const actions = [
                `<button type="button" class="button button--secondary workspace-content-refresh-website" data-url="${escapeHtml(item.source_url || '')}">${item.seeded_default ? 'Review This Website' : 'Refresh Review'}</button>`,
            ];

            if (hasDraft && item.id) {
                actions.push(`<button type="button" class="button button--primary workspace-content-publish-website" data-item-id="${item.id}">Publish Review</button>`);
            }

            if (item.id) {
                actions.push(`<button type="button" class="button button--secondary workspace-content-remove-website" data-item-id="${item.id}">Remove</button>`);
            }

            return `
                <article class="customer-workspace-website-card" ${item.id ? `data-item-id="${item.id}"` : ''} data-source-url="${escapeHtml(item.source_url || '')}">
                    <div class="customer-workspace-website-card__header">
                        <div class="customer-workspace-website-card__identity">
                            <strong>${escapeHtml(item.title)}</strong>
                            <p class="hint">${escapeHtml(item.source_url || '')}</p>
                        </div>
                        <span class="badge dui-badge dui-badge-sm uppercase tracking-[0.035em] ${websiteBadgeClass(item)}">${escapeHtml(item.status_label || item.status || 'saved')}</span>
                    </div>
                    <p class="customer-workspace-website-card__summary">${escapeHtml(summary)}</p>
                    <dl class="customer-workspace-website-card__facts">
                        <div>
                            <dt>Assistant view</dt>
                            <dd>${hasApproved ? 'Approved snapshot available' : 'Not published yet'}</dd>
                        </div>
                        <div>
                            <dt>Last review</dt>
                            <dd>${escapeHtml(relativeTimeLabel(item.last_imported_at, 'Not reviewed yet'))}</dd>
                        </div>
                        <div>
                            <dt>Last approved</dt>
                            <dd>${escapeHtml(relativeTimeLabel(item.last_published_at, 'Not approved yet'))}</dd>
                        </div>
                    </dl>
                    <div class="customer-workspace-item__actions">
                        ${actions.join('')}
                    </div>
                    ${previewBody ? `
                        <details class="customer-workspace-preview-details">
                            <summary>${previewLabel}</summary>
                            <pre>${escapeHtml(String(previewBody).slice(0, 900))}</pre>
                        </details>
                    ` : ''}
                </article>
            `;
        }

        function setWebsiteComposerOpen(open, nextUrl = '') {
            if (!websiteForm) {
                return;
            }

            websiteForm.hidden = !open;

            if (websiteToggleButton) {
                websiteToggleButton.hidden = open;
            }

            if (open && websiteUrlInput) {
                websiteUrlInput.value = nextUrl || websiteUrlInput.value || '';
                websiteUrlInput.focus();
                websiteUrlInput.select();
            }
        }

        function updateWebsiteCapacity(items) {
            const count = Array.isArray(items) ? items.length : 0;
            const remaining = Math.max(maxWebsiteCount - count, 0);

            if (websiteCount) {
                websiteCount.textContent = `${count} of ${maxWebsiteCount} website${maxWebsiteCount === 1 ? '' : 's'}`;
            }

            if (websiteLimitNote) {
                websiteLimitNote.textContent = remaining > 0
                    ? `Up to ${maxWebsiteCount} websites. ${remaining} slot${remaining === 1 ? '' : 's'} left.`
                    : `You’ve reached the ${maxWebsiteCount}-website limit. Remove one before adding another.`;
            }

            if (websiteToggleButton) {
                websiteToggleButton.hidden = remaining === 0 || websiteForm?.hidden === false;
            }
        }

        function renderWebsiteList(items) {
            if (!websiteList || !Array.isArray(items)) {
                return;
            }

            if (items.length === 0) {
                websiteList.innerHTML = `
                    <div class="customer-workspace-empty customer-workspace-empty--wide" id="workspace-content-website-empty">
                        <strong>No websites added yet.</strong>
                        <p class="hint">Add up to ${maxWebsiteCount} customer-facing sites or pages when you want the assistant to answer from their wording.</p>
                    </div>
                `;
            } else {
                websiteList.innerHTML = items.map(websiteCardMarkup).join('');
            }

            updateWebsiteCapacity(items);
        }

        async function parseJsonResponse(response) {
            try {
                return await response.json();
            } catch (error) {
                return null;
            }
        }

        async function sendJson(url, method, payload) {
            const response = await fetch(url, {
                method,
                headers: {
                    'Accept': 'application/json',
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': csrfToken,
                    'X-Requested-With': 'XMLHttpRequest',
                },
                body: JSON.stringify(payload),
            });

            const result = await parseJsonResponse(response);

            if (!response.ok) {
                throw new Error(renderErrors(result, 'Request failed.'));
            }

            return result;
        }

        async function importWebsiteUrl(url) {
            websiteImportButton.disabled = true;
            websiteImportButton.textContent = 'Refreshing…';
            setStatus('Refreshing your website content for review…', 'working');

            try {
                const result = await sendJson(endpoints.websiteImport, 'POST', { url });
                renderWebsiteList(result.website_items || (result.item ? [result.item] : []));
                updateSummary(result.summary);
                setStatus(result.message);
                setWebsiteComposerOpen(false);
            } catch (error) {
                setStatus(error?.message || 'Could not refresh website content.', 'error');
            } finally {
                websiteImportButton.disabled = false;
                websiteImportButton.textContent = 'Add Website';
            }
        }

        textForm?.addEventListener('submit', async (event) => {
            event.preventDefault();
            textSaveButton.disabled = true;
            textSaveButton.textContent = 'Saving…';
            setStatus('Saving your quick answers…', 'working');

            try {
                const formData = new FormData(textForm);
                const blocks = {};

                for (const [key, value] of formData.entries()) {
                    const match = key.match(/^blocks\[(.+)\]$/);

                    if (match) {
                        blocks[match[1]] = value;
                    }
                }

                const result = await sendJson(endpoints.textUpdate, 'PATCH', { blocks });
                setStatus(result.message);
                updateSummary(result.summary);
            } catch (error) {
                setStatus(error?.message || 'Could not save quick answers.', 'error');
            } finally {
                textSaveButton.disabled = false;
                textSaveButton.textContent = 'Save Quick Answers';
            }
        });

        documentUploadButton?.addEventListener('click', () => {
            documentInput?.click();
        });

        documentInput?.addEventListener('change', async () => {
            const file = documentInput.files?.[0];

            if (!file) {
                return;
            }

            documentUploadButton.disabled = true;
            documentUploadButton.textContent = replacingDocumentId ? 'Replacing…' : 'Uploading…';
            setStatus(
                replacingDocumentId
                    ? 'Replacing your document and updating the assistant reference set…'
                    : 'Uploading your document and preparing it for the assistant…',
                'working'
            );

            const formData = new FormData();
            formData.append('document', file);

            if (replacingDocumentId) {
                formData.append('replace_item_id', String(replacingDocumentId));
            }

            try {
                const response = await fetch(endpoints.documentUpload, {
                    method: 'POST',
                    headers: {
                        'Accept': 'application/json',
                        'X-CSRF-TOKEN': csrfToken,
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                    body: formData,
                });

                const result = await parseJsonResponse(response);

                if (!response.ok) {
                    throw new Error(renderErrors(result, 'Document upload failed.'));
                }

                upsertDocumentItem(result.item);
                updateSummary(result.summary);
                setStatus(result.message);
            } catch (error) {
                setStatus(error?.message || 'Document upload failed.', 'error');
            } finally {
                documentUploadButton.disabled = false;
                documentUploadButton.textContent = 'Upload Document';
                replacingDocumentId = null;
                documentInput.value = '';
            }
        });

        documentList?.addEventListener('click', async (event) => {
            const target = event.target;

            if (!(target instanceof HTMLElement)) {
                return;
            }

            if (target.classList.contains('workspace-content-replace-document')) {
                replacingDocumentId = target.dataset.itemId;
                documentInput?.click();
                return;
            }

            if (!target.classList.contains('workspace-content-remove-document')) {
                return;
            }

            const itemId = target.dataset.itemId;

            if (!itemId) {
                return;
            }

            target.setAttribute('disabled', 'disabled');
            setStatus('Removing the document from your approved reference set…', 'working');

            try {
                const result = await sendJson(
                    @json(url('/workspace-content/documents')).replace(/\/$/, '') + '/' + itemId,
                    'DELETE',
                    {}
                );

                removeDocumentItem(result.item_id);
                updateSummary(result.summary);
                setStatus(result.message);
            } catch (error) {
                setStatus(error?.message || 'Could not remove the document.', 'error');
                target.removeAttribute('disabled');
            }
        });

        websiteToggleButton?.addEventListener('click', () => {
            setWebsiteComposerOpen(true);
        });

        websiteCancelButton?.addEventListener('click', () => {
            setWebsiteComposerOpen(false);
        });

        websiteForm?.addEventListener('submit', async (event) => {
            event.preventDefault();
            const formData = new FormData(websiteForm);
            const url = String(formData.get('url') || '').trim();

            if (url === '') {
                setStatus('Add a website URL first.', 'error');
                return;
            }

            await importWebsiteUrl(url);
        });

        websiteList?.addEventListener('click', async (event) => {
            const target = event.target;

            if (!(target instanceof HTMLElement)) {
                return;
            }

            if (target.classList.contains('workspace-content-refresh-website')) {
                const url = String(target.dataset.url || '').trim();

                if (url === '') {
                    return;
                }

                if (websiteUrlInput) {
                    websiteUrlInput.value = url;
                }

                void importWebsiteUrl(url);
                return;
            }

            if (target.classList.contains('workspace-content-publish-website')) {
                const itemId = target.dataset.itemId;

                if (!itemId) {
                    return;
                }

                target.setAttribute('disabled', 'disabled');
                target.textContent = 'Publishing…';
                setStatus('Publishing the reviewed website snapshot…', 'working');

                try {
                    const result = await sendJson(
                        @json(url('/workspace-content/website')).replace(/\/$/, '') + '/' + itemId + '/publish',
                        'POST',
                        {}
                    );
                    renderWebsiteList(result.website_items || []);
                    updateSummary(result.summary);
                    setStatus(result.message);
                } catch (error) {
                    setStatus(error?.message || 'Could not publish website content.', 'error');
                    target.removeAttribute('disabled');
                    target.textContent = 'Publish Review';
                }

                return;
            }

            if (!target.classList.contains('workspace-content-remove-website')) {
                return;
            }

            const itemId = target.dataset.itemId;

            if (!itemId) {
                return;
            }

            target.setAttribute('disabled', 'disabled');
            setStatus('Removing this website from the assistant reference set…', 'working');

            try {
                const result = await sendJson(
                    @json(url('/workspace-content/website')).replace(/\/$/, '') + '/' + itemId,
                    'DELETE',
                    {}
                );
                renderWebsiteList(result.website_items || []);
                updateSummary(result.summary);
                setStatus(result.message);
            } catch (error) {
                setStatus(error?.message || 'Could not remove this website.', 'error');
                target.removeAttribute('disabled');
            }
        });

        updateWebsiteCapacity(@json($websiteItems));
    </script>
</x-layouts.app>
