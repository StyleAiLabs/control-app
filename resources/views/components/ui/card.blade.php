@props([
    'title' => null,
    'description' => null,
    'variant' => 'default',
])

<x-ui.panel :title="$title" :description="$description" :variant="$variant" {{ $attributes }}>
    {{ $slot }}
</x-ui.panel>
