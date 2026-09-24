<?php

namespace App\Livewire\Requests;

use App\Livewire\Support\Placeholder;

/**
 * New Request
 *
 * Routed now so the navigation, layout and role filtering can be verified. The
 * real screen arrives with Phase D — request module.
 */
class Create extends Placeholder
{
    protected function details(): array
    {
        return [
            'title' => 'New Request',
            'phase' => 'Phase D — request module',
            'summary' => 'The five-step wizard carrying Section A (Document Information) and Section B (Request Details), with the conditional business-plan branch and draft autosave.',
        ];
    }
}
