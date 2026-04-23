@props([
    'kicker',
    'title',
    'body',
])

<section {{ $attributes->class(['landing-hero']) }}>
    <div class="hero-copy">
        <div class="hero-copy-main">
            <span class="eyebrow type-kicker animate-in animate-delay-0"><span class="eyebrow-dot"></span> {{ $kicker }}</span>
            <h1 class="type-display animate-in animate-delay-1">{{ $title }}</h1>
        </div>

        <div class="hero-support animate-in animate-delay-2">
            <p class="type-body-lg">{{ $body }}</p>
            {{ $actions ?? '' }}
            {{ $proof ?? '' }}
        </div>
    </div>

    @isset($visual)
        <div class="hero-visual animate-in animate-delay-5">
            {{ $visual }}
        </div>
    @endisset
</section>
