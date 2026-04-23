@props([
    'minWidth' => null,
    'fit' => false,
])

@php
    $tableClasses = trim(implode(' ', array_filter([
        'sync-poc-table',
        $fit ? 'sync-poc-table--fit' : '',
    ])));
@endphp

<div {{ $attributes->class(['sync-poc-table-wrap']) }}>
    <table class="{{ $tableClasses }}" @if ($minWidth) style="min-width: {{ $minWidth }};" @endif>
        {{ $slot }}
    </table>
</div>
