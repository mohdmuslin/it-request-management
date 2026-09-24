<?php

namespace App\Livewire\Requests;

use App\Livewire\Support\Placeholder;
use App\Models\ItRequest;

/**
 * Request detail — the screen the project succeeds or fails on.
 *
 * Status timeline, approval trail, per-unit recommendations, documents, comments
 * and the audit history. If this screen is right, the rest follows.
 *
 * THE AUTHORISATION GAP THIS COMPONENT HAS
 *
 * The route is behind `auth` only, so any signed-in user can currently open any
 * request by id. That is not acceptable for real request data and must not reach
 * production: a policy check belongs in `mount()`, and it arrives with the request
 * module. Noted here so it is not overlooked — an unfilled authorisation check is
 * the easiest thing in the world to forget once the screen works.
 */
class Show extends Placeholder
{
    public ?ItRequest $request = null;

    public function mount(ItRequest $request): void
    {
        // Route-model bound, so the request is already resolved. The policy check
        // belongs here and arrives with the request module.
        $this->request = $request;
    }

    protected function details(): array
    {
        return [
            'title' => $this->request?->title ?: 'Request',
            'phase' => 'Phase D — request module',
            'summary' => 'The status timeline, approval trail, recommendations, documents, '
                .'comments and audit history for one request.',
        ];
    }
}
