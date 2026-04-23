@props([
    'quote',
    'name',
    'role',
    'initials',
    'metricLabel',
    'metricValue',
    'countTo' => null,
    'prefix' => null,
    'suffix' => null,
])

<article {{ $attributes->class(['dui-card sync-poc-marketing-card testimonial-card']) }}>
    <div class="dui-card-body">
        <div class="testimonial-content">
            <p class="testimonial-quote">{{ $quote }}</p>
        </div>
        <div class="testimonial-author">
            <div class="author-avatar">{{ $initials }}</div>
            <div class="author-info">
                <strong>{{ $name }}</strong>
                <span>{{ $role }}</span>
            </div>
        </div>
        <div class="testimonial-metric">
            <span class="metric-label">{{ $metricLabel }}</span>
            <strong
                class="metric-value"
                @if ($countTo !== null) data-count-to="{{ $countTo }}" @endif
                @if ($prefix !== null) data-prefix="{{ $prefix }}" @endif
                @if ($suffix !== null) data-suffix="{{ $suffix }}" @endif
            >{{ $metricValue }}</strong>
        </div>
    </div>
</article>
