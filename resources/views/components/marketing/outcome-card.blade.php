@props([
    'eyebrow',
    'title',
])

<article {{ $attributes->class(['dui-card sync-poc-marketing-card outcome-card']) }}>
    <div class="dui-card-body">
        <span class="type-secondary">{{ $eyebrow }}</span>
        <h3 class="dui-card-title type-h3">{{ $title }}</h3>
        <p class="type-body">{{ $slot }}</p>
    </div>
</article>
