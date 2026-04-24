@props([
    'items' => [],
    'variant' => 'vertical',
    'valueLabel' => 'Primary',
    'secondaryLabel' => null,
])

@php
    $items = collect($items)->values();
    $primaryMax = (float) $items->max('value');
    $secondaryMax = (float) $items->max('secondary');
    $max = max(1, $primaryMax, $secondaryMax);
    $hasSecondary = $secondaryLabel !== null && $items->contains(fn ($item) => (float) ($item['secondary'] ?? 0) > 0);
@endphp

@if ($variant === 'horizontal')
    <div {{ $attributes->class(['sync-dashboard-bar-chart sync-dashboard-bar-chart--horizontal']) }}>
        <div class="sync-dashboard-bar-chart__legend">
            <span><i class="sync-dashboard-bar-chart__swatch sync-dashboard-bar-chart__swatch--primary"></i>{{ $valueLabel }}</span>
        </div>

        <div class="sync-dashboard-bar-chart__rows">
            @foreach ($items as $item)
                @php
                    $value = (float) ($item['value'] ?? 0);
                    $percent = $max > 0 ? ($value / $max) * 100 : 0;
                @endphp
                <div class="sync-dashboard-bar-chart__row">
                    <div class="sync-dashboard-bar-chart__row-copy">
                        <span class="sync-dashboard-bar-chart__row-label">{{ $item['label'] }}</span>
                        @if (! empty($item['note']))
                            <span class="sync-dashboard-bar-chart__row-note">{{ $item['note'] }}</span>
                        @endif
                    </div>
                    <div class="sync-dashboard-bar-chart__row-track">
                        <span class="sync-dashboard-bar-chart__row-fill" style="width: {{ $percent }}%"></span>
                    </div>
                    <span class="sync-dashboard-bar-chart__row-value">{{ (int) $value }}</span>
                </div>
            @endforeach
        </div>
    </div>
@else
    @php
        $chartHeight = 152;
        $groupWidth = $hasSecondary ? 18 : 14;
        $groupGap = $hasSecondary ? 10 : 8;
        $width = max(220, (count($items) * ($groupWidth + $groupGap)) + 12);
        $step = $groupWidth + $groupGap;
        $barWidth = $hasSecondary ? 7 : 10;
    @endphp
    <div {{ $attributes->class(['sync-dashboard-bar-chart sync-dashboard-bar-chart--vertical']) }}>
        <div class="sync-dashboard-bar-chart__legend">
            <span><i class="sync-dashboard-bar-chart__swatch sync-dashboard-bar-chart__swatch--primary"></i>{{ $valueLabel }}</span>
            @if ($hasSecondary)
                <span><i class="sync-dashboard-bar-chart__swatch sync-dashboard-bar-chart__swatch--secondary"></i>{{ $secondaryLabel }}</span>
            @endif
        </div>

        <div class="sync-dashboard-bar-chart__canvas">
            <svg viewBox="0 0 {{ $width }} {{ $chartHeight }}" preserveAspectRatio="none" aria-hidden="true">
                <line x1="0" y1="{{ $chartHeight - 10 }}" x2="{{ $width }}" y2="{{ $chartHeight - 10 }}" class="sync-dashboard-bar-chart__axis" />
                @foreach ($items as $index => $item)
                    @php
                        $x = 6 + ($index * $step);
                        $primary = (float) ($item['value'] ?? 0);
                        $secondary = (float) ($item['secondary'] ?? 0);
                        $primaryHeight = ($primary / $max) * 118;
                        $secondaryHeight = ($secondary / $max) * 118;
                    @endphp
                    @if ($hasSecondary)
                        <rect
                            x="{{ $x }}"
                            y="{{ ($chartHeight - 12) - $primaryHeight }}"
                            width="{{ $barWidth }}"
                            height="{{ $primaryHeight }}"
                            rx="3"
                            class="sync-dashboard-bar-chart__bar sync-dashboard-bar-chart__bar--primary"
                        >
                            <title>{{ $item['label'] }}: {{ (int) $primary }} {{ strtolower($valueLabel) }}</title>
                        </rect>
                        <rect
                            x="{{ $x + $barWidth + 3 }}"
                            y="{{ ($chartHeight - 12) - $secondaryHeight }}"
                            width="{{ $barWidth }}"
                            height="{{ $secondaryHeight }}"
                            rx="3"
                            class="sync-dashboard-bar-chart__bar sync-dashboard-bar-chart__bar--secondary"
                        >
                            <title>{{ $item['label'] }}: {{ (int) $secondary }} {{ strtolower((string) $secondaryLabel) }}</title>
                        </rect>
                    @else
                        <rect
                            x="{{ $x }}"
                            y="{{ ($chartHeight - 12) - $primaryHeight }}"
                            width="{{ $barWidth }}"
                            height="{{ $primaryHeight }}"
                            rx="3"
                            class="sync-dashboard-bar-chart__bar sync-dashboard-bar-chart__bar--primary"
                        >
                            <title>{{ $item['label'] }}: {{ (int) $primary }} {{ strtolower($valueLabel) }}</title>
                        </rect>
                    @endif
                @endforeach
            </svg>
        </div>

        <div class="sync-dashboard-bar-chart__labels">
            @foreach (collect($items)->filter(fn ($item, $index) => in_array($index, [0, (int) floor((count($items) - 1) / 2), count($items) - 1], true)) as $item)
                <span>{{ $item['label'] }}</span>
            @endforeach
        </div>
    </div>
@endif
