@props([
    'status' => 'neutral',
    'label' => null,
])

@php
    $normalized = strtolower(str_replace('_', '-', (string) $status));
    $success = ['ready', 'success', 'healthy', 'live', 'running', 'completed', 'applied', 'connected', 'verified', 'synced'];
    $warning = ['pending', 'warning', 'queued', 'provisioning', 'deploying', 'stopped', 'unchecked', 'registered', 'skipped'];
    $error = ['failed', 'error', 'expired', 'unreachable', 'archived', 'missing-config', 'not-provisioned', 'disconnected'];

    $tone = match (true) {
        in_array($normalized, $success, true) => 'success',
        in_array($normalized, $warning, true) => 'warning',
        in_array($normalized, $error, true) => 'error',
        default => 'neutral',
    };

    $icon = match ($tone) {
        'success' => 'check-circle',
        'warning' => in_array($normalized, ['pending', 'queued', 'provisioning', 'deploying'], true) ? 'clock' : 'alert-triangle',
        'error' => 'x-circle',
        default => 'circle',
    };

    $accessibleLabel = $label ?: ucfirst(str_replace('-', ' ', $normalized));
@endphp

<span
    {{ $attributes->class(['sync-poc-status-icon sync-poc-status-icon--'.$tone]) }}
    role="img"
    aria-label="{{ $accessibleLabel }}"
    title="{{ $accessibleLabel }}"
>
    <x-ui.icon :name="$icon" size="sm" />
</span>
