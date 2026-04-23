@props([
    'status' => 'neutral',
    'technical' => false,
])

@php
    $normalized = strtolower(str_replace('_', '-', (string) $status));
    $statusClasses = [
        'ready' => 'dui-badge-success',
        'success' => 'dui-badge-success',
        'healthy' => 'dui-badge-success',
        'live' => 'dui-badge-success',
        'running' => 'dui-badge-success',
        'completed' => 'dui-badge-success',
        'applied' => 'dui-badge-success',
        'pending' => 'dui-badge-warning',
        'warning' => 'dui-badge-warning',
        'queued' => 'dui-badge-warning',
        'provisioning' => 'dui-badge-warning',
        'deploying' => 'dui-badge-warning',
        'stopped' => 'dui-badge-warning',
        'unchecked' => 'dui-badge-warning',
        'registered' => 'dui-badge-warning',
        'failed' => 'dui-badge-error',
        'error' => 'dui-badge-error',
        'expired' => 'dui-badge-error',
        'unreachable' => 'dui-badge-error',
        'missing-config' => 'dui-badge-error',
        'not-provisioned' => 'dui-badge-error',
        'archived' => 'dui-badge-error',
        'info' => 'dui-badge-info',
        'offline' => 'dui-badge-neutral',
        'neutral' => 'dui-badge-neutral',
        'technical' => 'dui-badge-neutral',
    ];

    $classes = trim(implode(' ', array_filter([
        'badge',
        $technical ? 'badge--technical' : '',
        'dui-badge dui-badge-sm whitespace-nowrap font-bold uppercase tracking-[0.035em]',
        $statusClasses[$normalized] ?? $statusClasses['neutral'],
    ])));
@endphp

<span {{ $attributes->class([$classes]) }}>
    {{ $slot }}
</span>
