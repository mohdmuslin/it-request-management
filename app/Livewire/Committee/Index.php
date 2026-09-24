<?php

namespace App\Livewire\Committee;

use App\Livewire\Support\Placeholder;

/**
 * Committee Workspace
 *
 * Routed now so the navigation, layout and role filtering can be verified. The
 * real screen arrives with Phase F — governance module.
 */
class Index extends Placeholder
{
    protected function details(): array
    {
        return [
            'title' => 'Committee Workspace',
            'phase' => 'Phase F — governance module',
            'summary' => 'Full-route requests awaiting an IT Investment Committee decision, and the recording of that decision with any conditions.',
        ];
    }
}
