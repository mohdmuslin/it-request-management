<?php

namespace App\Livewire\Admin;

use App\Livewire\Support\Placeholder;

/**
 * Audit log
 *
 * Routed now so the navigation, layout and role filtering can be verified. The
 * real screen arrives with Phase G — reporting and administration.
 */
class AuditLog extends Placeholder
{
    protected function details(): array
    {
        return [
            'title' => 'Audit log',
            'phase' => 'Phase G — reporting and administration',
            'summary' => 'Every material action with actor, timestamp and the before-and-after values. Read-only by design: a record that can be edited is not evidence.',
        ];
    }
}
