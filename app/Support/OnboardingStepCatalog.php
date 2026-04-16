<?php

namespace App\Support;

final class OnboardingStepCatalog
{
    /**
     * @return array<int, string>
     */
    public static function labels(): array
    {
        return [
            1 => 'Website',
            2 => 'Business Info',
            3 => 'Tone',
            4 => 'Skills',
            5 => 'Channel',
            6 => 'Go Live',
        ];
    }
}
