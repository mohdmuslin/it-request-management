<?php

namespace App\Livewire\Approvals;

use App\Livewire\Support\Placeholder;

/**
 * My Approvals
 *
 * Routed now so the navigation, layout and role filtering can be verified. The
 * real screen arrives with Phase E — workflow module.
 */
class Index extends Placeholder
{
    protected function details(): array
    {
        return [
            'title' => 'My Approvals',
            'phase' => 'Phase E — workflow module',
            'summary' => 'Requests awaiting your decision, with due dates, and the delegation control for when you will be away.',
        ];
    }
}
