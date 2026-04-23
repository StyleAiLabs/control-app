@props([
    'title' => null,
    'description' => null,
    'variant' => 'default',
])

@php
    $classes = trim(implode(' ', array_filter([
        'dui-card sync-poc-card text-base-content',
        $variant === 'subtle' ? 'sync-poc-card--subtle' : 'bg-base-100',
        $variant === 'technical' ? 'bg-base-200' : '',
    ])));
@endphp

<section {{ $attributes->class([$classes]) }}>
    <div class="dui-card-body gap-4">
        @if ($title || $description || isset($actions))
            <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                <div>
                    @if ($title)
                        <h2 class="dui-card-title type-section-title">{{ $title }}</h2>
                    @endif
                    @if ($description)
                        <p class="hint mt-2">{{ $description }}</p>
                    @endif
                </div>
                @isset($actions)
                    <div class="flex shrink-0 flex-wrap gap-2">
                        {{ $actions }}
                    </div>
                @endisset
            </div>
        @endif

        {{ $slot }}
    </div>
</section>
