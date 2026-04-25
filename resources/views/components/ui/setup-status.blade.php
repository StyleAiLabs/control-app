@props([
    'status' => '',
    'note' => '',
    'tone' => 'warning',
    'visible' => true,
    'statusId' => null,
    'noteId' => null,
])

<div {{ $attributes->class(['sync-onboarding-inline-status', 'is-hidden' => ! $visible]) }}>
    <div class="sync-onboarding-inline-status__content">
        <span @if($statusId) id="{{ $statusId }}" @endif class="wizard-status-pill wizard-status-pill--{{ $tone }}">
            {{ $status }}
        </span>
        <p @if($noteId) id="{{ $noteId }}" @endif class="sync-onboarding-status-note">
            {{ $note }}
        </p>
    </div>
</div>
