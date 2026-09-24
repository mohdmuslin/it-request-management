<?php

namespace App\Livewire\Governance;

use App\Livewire\Support\Placeholder;

/**
 * Governance Workspace
 *
 * Routed now so the navigation, layout and role filtering can be verified. The
 * real screen arrives with Phase F — governance module.
 */
class Index extends Placeholder
{
    protected function details(): array
    {
        return [
            'title' => 'Governance Workspace',
            'phase' => 'Phase F — governance module',
            'summary' => 'Completeness review, tier and classification assignment, and consolidation — where the governance route is determined.',
        ];
    }
}
