<?php

namespace App\Livewire\Admin;

use App\Livewire\Support\Placeholder;

/**
 * Users and roles
 *
 * Routed now so the navigation, layout and role filtering can be verified. The
 * real screen arrives with Phase G — reporting and administration.
 */
class Users extends Placeholder
{
    protected function details(): array
    {
        return [
            'title' => 'Users and roles',
            'phase' => 'Phase G — reporting and administration',
            'summary' => 'Accounts, role assignment and review-unit membership. Accounts are deactivated rather than deleted, because history references them.',
        ];
    }
}
