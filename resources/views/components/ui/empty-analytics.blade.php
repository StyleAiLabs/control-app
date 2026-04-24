@props([
    'title',
    'description',
])

<div {{ $attributes->class(['sync-dashboard-empty']) }}>
    <x-ui.empty-state :title="$title" :description="$description" />
</div>
