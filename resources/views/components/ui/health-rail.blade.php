@props([
    'items' => [],
])

<section {{ $attributes->class(['sync-dashboard-health-rail']) }}>
    @foreach ($items as $item)
        <x-ui.metric-card
            :label="$item['label']"
            :status="$item['status'] ?? 'neutral'"
            :value="$item['value']"
            :note="$item['note'] ?? null"
            :dom-id="$item['dom_id'] ?? null"
        />
    @endforeach
</section>
