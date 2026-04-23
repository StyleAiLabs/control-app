@props([
    'stats' => [],
])

<div {{ $attributes->class(['dui-stats trust-bar']) }}>
    @foreach ($stats as $index => $stat)
        <div class="dui-stat trust-stat">
            <div class="dui-stat-value type-value">{{ $stat['value'] }}</div>
            <div class="dui-stat-desc type-label">{{ $stat['label'] }}</div>
        </div>
        @if ($index < count($stats) - 1)
            <div class="trust-divider" aria-hidden="true"></div>
        @endif
    @endforeach
</div>
