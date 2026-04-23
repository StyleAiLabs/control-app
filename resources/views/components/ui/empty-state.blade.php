@props([
    'title' => 'Nothing here yet.',
    'description' => null,
])

<div {{ $attributes->class(['dui-card sync-poc-card bg-base-100 text-center']) }}>
    <div class="dui-card-body items-center">
        <h3 class="dui-card-title">{{ $title }}</h3>
        @if ($description)
            <p class="hint max-w-xl">{{ $description }}</p>
        @endif
        @if (trim($slot) !== '')
            <div class="dui-card-actions justify-center">
                {{ $slot }}
            </div>
        @endif
    </div>
</div>
