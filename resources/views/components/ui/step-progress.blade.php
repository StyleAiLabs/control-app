@props([
    'steps' => [],
    'currentStep' => 1,
    'currentLabel' => 'Setup',
    'progressPercent' => 0,
    'progressNote' => null,
    'completedSteps' => 0,
    'totalSteps' => 7,
])

<section class="sync-onboarding-progress-shell">
    <x-ui.panel variant="subtle" class="wizard-status-card wizard-status-card--progress sync-onboarding-progress-panel">
        <div class="sync-onboarding-progress-top">
            <div class="sync-onboarding-progress-copy">
                <span class="eyebrow">Setup Progress</span>
                <strong id="wizard-step-counter" class="sync-onboarding-progress-title">Step {{ $currentStep }} of {{ $totalSteps }}</strong>
            </div>
            <span class="sync-onboarding-progress-badge">{{ $completedSteps }} / {{ $totalSteps }} done</span>
        </div>

        <div class="sync-onboarding-progress-meta">
            <div class="sync-onboarding-progress-meta__copy">
                <span id="wizard-step-name" class="sync-onboarding-progress-step">{{ $currentLabel }}</span>
                <p class="wizard-auto-note" id="wizard-progress-note">
                    {{ $progressNote }}
                </p>
            </div>
            <strong id="wizard-progress-percent" class="sync-onboarding-progress-value">{{ $progressPercent }}%</strong>
        </div>

        <div class="wizard-status-track" aria-hidden="true">
            <span id="wizard-progress-fill" style="width: {{ $progressPercent }}%"></span>
        </div>

        <ul class="wizard-steps-bar" id="wizard-steps-bar">
            @foreach ($steps as $number => $step)
                <li data-step="{{ $number }}" class="{{ ($step['status'] ?? null) === 'complete' ? 'done' : '' }}">
                    <span class="wizard-step-index" aria-hidden="true">{{ $number }}</span>
                    <span class="wizard-step-label">{{ $step['label'] ?? 'Step' }}</span>
                </li>
            @endforeach
        </ul>

        <div class="wizard-operation-note" id="wizard-operation-note" role="status" aria-live="polite"></div>
    </x-ui.panel>
</section>
