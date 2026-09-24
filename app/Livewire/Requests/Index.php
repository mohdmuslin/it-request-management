<?php

namespace App\Livewire\Requests;

use App\Livewire\Support\Placeholder;

/**
 * My Requests
 *
 * Routed now so the navigation, layout and role filtering can be verified. The
 * real screen arrives with Phase D — request module.
 */
class Index extends Placeholder
{
    protected function details(): array
    {
        return [
            'title' => 'My Requests',
            'phase' => 'Phase D — request module',
            'summary' => 'Drafts, submissions and anything returned for amendment, with scoping so each user sees only what they are entitled to.',
        ];
    }
}
