@props([
    'label',
    'value',
    'note' => null,
    'status' => 'neutral',
    'domId' => null,
])

<article @if($domId) id="{{ $domId }}" @endif {{ $attributes->class(['sync-dashboard-metric-card']) }}>
    <div class="sync-dashboard-metric-card__header">
        <x-ui.status-icon :status="$status" :label="$label.' status'" />
        <div class="sync-dashboard-metric-card__body">
            <span class="sync-dashboard-metric-card__label">{{ $label }}</span>
            <span @if($domId) id="{{ $domId }}-value" @endif class="sync-dashboard-metric-card__value">{{ $value }}</span>
        </div>
    </div>

    @if ($note)
        <p @if($domId) id="{{ $domId }}-note" @endif class="sync-dashboard-metric-card__note">{{ $note }}</p>
    @endif
</article>
