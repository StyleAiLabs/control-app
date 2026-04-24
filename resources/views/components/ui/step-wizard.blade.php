@props([
    'steps' => [],
    'currentStep' => 1,
])

<ol {{ $attributes->class(['sync-dashboard-step-wizard']) }}>
    @foreach ($steps as $number => $step)
        @php
            $state = $step['status'] === 'complete' ? 'complete' : ($number === $currentStep ? 'current' : 'pending');
        @endphp
        <li class="sync-dashboard-step-wizard__item sync-dashboard-step-wizard__item--{{ $state }}">
            <span class="sync-dashboard-step-wizard__marker">
                @if ($state === 'complete')
                    <x-ui.icon name="check-circle" size="sm" />
                @else
                    {{ $number }}
                @endif
            </span>
            <div class="sync-dashboard-step-wizard__copy">
                <span class="sync-dashboard-step-wizard__label">{{ $step['label'] }}</span>
                <span class="sync-dashboard-step-wizard__status">
                    @if ($state === 'complete')
                        Done
                    @elseif ($state === 'current')
                        Next
                    @else
                        Pending
                    @endif
                </span>
            </div>
        </li>
    @endforeach
</ol>
