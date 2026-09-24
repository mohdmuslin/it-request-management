<?php

namespace App\Livewire\Reports;

use App\Livewire\Support\Placeholder;

/**
 * Reports
 *
 * Routed now so the navigation, layout and role filtering can be verified. The
 * real screen arrives with Phase G — reporting and administration.
 */
class Index extends Placeholder
{
    protected function details(): array
    {
        return [
            'title' => 'Reports',
            'phase' => 'Phase G — reporting and administration',
            'summary' => 'Workload, aging, turnaround and outcomes, with filters and exports. UAT-014 requires these totals to reconcile with the underlying records.',
        ];
    }
}
