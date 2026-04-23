@props([
    'href' => null,
    'variant' => 'primary',
    'size' => 'md',
    'type' => 'button',
    'disabled' => false,
    'icon' => null,
    'iconPosition' => 'before',
])

@php
    $variantClasses = [
        'primary' => 'dui-btn-primary',
        'secondary' => 'dui-btn-outline',
        'ghost' => 'dui-btn-ghost',
        'danger' => 'dui-btn-error',
        'link' => 'dui-btn-link',
    ];

    $sizeClasses = [
        'sm' => 'dui-btn-sm',
        'md' => '',
        'lg' => 'dui-btn-lg',
    ];

    $classes = trim(implode(' ', array_filter([
        'dui-btn gap-2 whitespace-nowrap normal-case font-bold tracking-normal',
        $variantClasses[$variant] ?? $variantClasses['primary'],
        $sizeClasses[$size] ?? '',
        $disabled ? 'dui-btn-disabled' : '',
    ])));
@endphp

@if ($href)
    <a href="{{ $href }}" {{ $attributes->class([$classes]) }} @if ($disabled) aria-disabled="true" tabindex="-1" @endif>
        @if ($icon && $iconPosition === 'before')
            <x-ui.icon :name="$icon" />
        @endif
        {{ $slot }}
        @if ($icon && $iconPosition === 'after')
            <x-ui.icon :name="$icon" />
        @endif
    </a>
@else
    <button type="{{ $type }}" {{ $attributes->class([$classes]) }} @disabled($disabled)>
        @if ($icon && $iconPosition === 'before')
            <x-ui.icon :name="$icon" />
        @endif
        {{ $slot }}
        @if ($icon && $iconPosition === 'after')
            <x-ui.icon :name="$icon" />
        @endif
    </button>
@endif
