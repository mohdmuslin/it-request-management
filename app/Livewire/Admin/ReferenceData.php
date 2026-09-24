<?php

namespace App\Livewire\Admin;

use App\Livewire\Support\Placeholder;

/**
 * Reference data
 *
 * Routed now so the navigation, layout and role filtering can be verified. The
 * real screen arrives with Phase G — reporting and administration.
 */
class ReferenceData extends Placeholder
{
    protected function details(): array
    {
        return [
            'title' => 'Reference data',
            'phase' => 'Phase G — reporting and administration',
            'summary' => 'Tiers, classifications, governance routes and review units. This list is enforced rather than free-typed, so routing and reporting stay reliable.',
        ];
    }
}
