<?php

namespace App\Livewire\Requests;

use App\Models\ItRequest;
use App\Services\WorkflowService;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Request detail — the screen the project succeeds or fails on.
 *
 * Status timeline, approval trail, recommendations, documents, comments and audit
 * history for one request. If this screen is right, the rest follows.
 *
 * THE AUTHORISATION GAP THIS COMPONENT USED TO HAVE
 *
 * The route is behind `auth` only, so any signed-in user could open any request by
 * changing the id in the URL — and the request body holds budgets, vendor
 * arrangements and internal justifications. `mount()` now authorises through the
 * policy, deriving access from the record rather than from knowing the id.
 *
 * WHY 403 RATHER THAN 404 FOR A REQUEST THE USER MAY NOT SEE
 *
 * A 404 would be the stronger choice: a 403 confirms the record exists, so somebody
 * walking ids learns which ones are real. If this ever faces anyone outside the
 * organisation, that difference matters and the check should return 404 instead.
 *
 * Inside a single organisation the trade-off goes the other way. The users are
 * colleagues, a request is often shared by link, and "you do not have access to this
 * request" tells a legitimate person to ask for access — whereas 404 tells them the
 * link is broken and they go looking for a bug that is not there. Whichever is
 * chosen, it should be chosen deliberately rather than by leaving `authorize()` in
 * and describing it as something else.
 */
class Show extends Component
{
    /**
     * The request on screen.
     *
     * LOCKED: the client must not be able to reassign it. Without the lock, an
     * action could be run against a request other than the one that was authorised
     * in `mount()` — the authorisation would still have passed, for a different row.
     */
    #[Locked]
    public ?ItRequest $request = null;

    /** One line of feedback after an action. */
    public string $flash = '';

    public function mount(ItRequest $request): void
    {
        $this->request = $request;

        $this->authorize('view', $request);
    }

    /**
     * Submit the request into the approval chain.
     *
     * Re-authorised at the point of action, not only on load. A page left open can
     * outlive the permission that opened it — the request may have moved, or a role
     * may have been withdrawn.
     */
    public function submit(): void
    {
        $this->authorize('submit', $this->request);

        app(WorkflowService::class)->submit($this->request);

        $this->request->refresh();

        $this->flash = "Submitted to {$this->request->projectOwner?->name} for approval.";
    }

    /** Withdraw the request. */
    public function withdraw(): void
    {
        $this->authorize('withdraw', $this->request);

        app(WorkflowService::class)->close($this->request, 'withdrawn');

        $this->request->refresh();

        $this->flash = 'Request withdrawn.';
    }

    /**
     * Resubmit a request that was returned for amendment (BR-007).
     *
     * Re-enters the stage that returned it rather than restarting the chain, so the
     * requestor is not sent back through approvals that already passed and the
     * approvers do not re-read decisions they have already made.
     */
    public function resubmit(): void
    {
        $this->authorize('resubmit', $this->request);

        $resumed = app(WorkflowService::class)->resubmit($this->request);

        $this->request->refresh();

        /*
         * The message names the stage, because "resubmitted" alone leaves the
         * requestor unsure whether it went back to the beginning. Naming it answers
         * the question they are actually asking: did my earlier approval survive?
         */
        $this->flash = $resumed
            ? "Resubmitted. It is back with the {$resumed->label()}."
            : 'Resubmitted.';
    }

    public function render()
    {
        /*
         * Everything the screen shows, eager-loaded.
         *
         * The timeline, trail, comments and audit tab each touch a relation, and this
         * host has no worker process — so an N+1 here is paid on every page view by
         * the person waiting for it.
         */
        $this->request->load([
            'requestor:id,name',
            'projectOwner:id,name',
            'projectSponsor:id,name',
            'department:id,name',
            'division:id,name',
            'proposedTier:id,name',
            'proposedClassification:id,name',
            'tier:id,name',
            'classification:id,name',
            'governanceRoute:id,name',
            'histories' => fn ($q) => $q->with('performedBy:id,name')->orderBy('created_at'),
            'approvalTasks' => fn ($q) => $q->with('approver:id,name')->orderBy('created_at'),
            'comments' => fn ($q) => $q->with('user:id,name')->orderBy('created_at'),
            'attachments',
        ]);

        return view('livewire.requests.show', [
            'canDecide' => auth()->user()->can('decide', $this->request),
            'canEdit' => auth()->user()->can('update', $this->request),
            'canSubmit' => auth()->user()->can('submit', $this->request),
            'canResubmit' => auth()->user()->can('resubmit', $this->request),
            'canWithdraw' => auth()->user()->can('withdraw', $this->request),
            'pendingTask' => $this->request->pendingApprovalTask,
        ])->layout('components.layouts.app', ['title' => $this->request->title]);
    }
}
