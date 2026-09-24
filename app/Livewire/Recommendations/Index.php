<?php

namespace App\Livewire\Recommendations;

use App\Livewire\Support\Placeholder;

/**
 * Recommendations
 *
 * Routed now so the navigation, layout and role filtering can be verified. The
 * real screen arrives with Phase F — governance module.
 */
class Index extends Placeholder
{
    protected function details(): array
    {
        return [
            'title' => 'Recommendations',
            'phase' => 'Phase F — governance module',
            'summary' => 'Requests assigned to your unit, and the recommendations you have filed. Submitting again creates a new version rather than overwriting the last.',
        ];
    }
}
