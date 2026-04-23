@props([
    'primaryHref',
    'primaryLabel' => 'Start Free Trial',
    'secondaryHref' => null,
    'secondaryLabel' => null,
])

<div {{ $attributes->class(['sync-poc-cta-row']) }}>
    <x-ui.button :href="$primaryHref" variant="primary">
        {!! $primaryLabel !!}
    </x-ui.button>

    @if ($secondaryHref && $secondaryLabel)
        <x-ui.button :href="$secondaryHref" variant="secondary">
            {!! $secondaryLabel !!}
        </x-ui.button>
    @endif
</div>
