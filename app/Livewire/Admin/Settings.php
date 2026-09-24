<?php

namespace App\Livewire\Admin;

use App\Livewire\Support\Placeholder;

/**
 * Due dates and calendar
 *
 * Routed now so the navigation, layout and role filtering can be verified. The
 * real screen arrives with Phase G — reporting and administration.
 */
class Settings extends Placeholder
{
    protected function details(): array
    {
        return [
            'title' => 'Due dates and calendar',
            'phase' => 'Phase G — reporting and administration',
            'summary' => 'Per-stage targets in business days, and the holiday calendar. Malaysia has no daylight saving, so the only calendar trap here is a holiday nobody entered.',
        ];
    }
}
