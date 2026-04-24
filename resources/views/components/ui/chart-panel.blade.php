@props([
    'title',
    'description' => null,
])

<x-ui.panel :title="$title" :description="$description" variant="subtle" {{ $attributes }}>
    {{ $slot }}
</x-ui.panel>
