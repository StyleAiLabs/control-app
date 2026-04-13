@if ($paginator->hasPages())
    <nav role="navigation" aria-label="Pagination" style="display: flex; align-items: center; gap: 8px; flex-wrap: wrap;">

        {{-- Previous --}}
        @if ($paginator->onFirstPage())
            <span class="button button--secondary" style="opacity: 0.4; cursor: not-allowed; padding: 8px 16px; font-size: 0.85rem;">
                <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="15 18 9 12 15 6"/></svg>
                Previous
            </span>
        @else
            <a href="{{ $paginator->previousPageUrl() }}" rel="prev" class="button button--secondary" style="padding: 8px 16px; font-size: 0.85rem;">
                <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="15 18 9 12 15 6"/></svg>
                Previous
            </a>
        @endif

        {{-- Page numbers --}}
        @foreach ($elements as $element)
            @if (is_string($element))
                <span style="font-size: 0.85rem; color: var(--muted); padding: 0 4px;">{{ $element }}</span>
            @endif

            @if (is_array($element))
                @foreach ($element as $page => $url)
                    @if ($page == $paginator->currentPage())
                        <span style="display: inline-flex; align-items: center; justify-content: center; width: 34px; height: 34px; border-radius: 999px; background: var(--accent); color: white; font-size: 0.85rem; font-weight: 700; line-height: 1;">
                            {{ $page }}
                        </span>
                    @else
                        <a href="{{ $url }}" style="display: inline-flex; align-items: center; justify-content: center; width: 34px; height: 34px; border-radius: 999px; background: white; border: 1.5px solid var(--stroke); color: var(--ink); font-size: 0.85rem; font-weight: 600; text-decoration: none; transition: border-color 0.15s;">
                            {{ $page }}
                        </a>
                    @endif
                @endforeach
            @endif
        @endforeach

        {{-- Next --}}
        @if ($paginator->hasMorePages())
            <a href="{{ $paginator->nextPageUrl() }}" rel="next" class="button button--secondary" style="padding: 8px 16px; font-size: 0.85rem;">
                Next
                <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="9 18 15 12 9 6"/></svg>
            </a>
        @else
            <span class="button button--secondary" style="opacity: 0.4; cursor: not-allowed; padding: 8px 16px; font-size: 0.85rem;">
                Next
                <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="9 18 15 12 9 6"/></svg>
            </span>
        @endif

    </nav>
@endif
