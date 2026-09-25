<?php

namespace App\Livewire\Approvals;

use App\Enums\Decision;
use App\Models\ApprovalTask;
use App\Models\Delegation;
use App\Models\User;
use App\Services\WorkflowDecisionService;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * My Approvals — the queue.
 *
 * WHY THIS QUEUE IS SCOPED BY THE DECISION SERVICE, NOT BY A WHERE CLAUSE
 *
 * "What can I decide?" has three answers, not one: tasks assigned to me, tasks
 * assigned to somebody who has delegated to me, and unassigned tasks at a stage my
 * role owns. A `where('approver_id', $user->id)` covers the first and silently drops
 * the other two — so a delegate sees an empty queue while a request waits on them,
 * which is exactly the problem delegation was added to solve.
 *
 * The rule lives in `WorkflowDecisionService::authorisedDecider()`, and this screen
 * and the policy both ask it. One definition, three callers.
 */
class Index extends Component
{
    use WithPagination;

    #[Url(as: 'overdue', history: true)]
    public bool $overdueOnly = false;

    /** The task being decided, if the decision panel is open. */
    public ?int $decidingTaskId = null;

    public string $decision = '';

    public string $comments = '';

    public string $conditions = '';

    /** Shown after a decision, so the queue change is explained rather than sudden. */
    public string $flash = '';

    public function startDecision(int $taskId): void
    {
        $task = ApprovalTask::with('request')->findOrFail($taskId);

        // Authorised through the same rule the queue uses. Fetching by id and
        // trusting the queue to have filtered it would let a crafted call decide
        // somebody else's task.
        $this->authorizeDecision($task);

        $this->decidingTaskId = $taskId;
        $this->decision = '';
        $this->comments = '';
        $this->conditions = '';
        $this->flash = '';
        $this->resetErrorBag();
    }

    public function cancelDecision(): void
    {
        $this->decidingTaskId = null;
        $this->reset('decision', 'comments', 'conditions');
        $this->resetErrorBag();
    }

    /**
     * Record the decision.
     *
     * Validation happens here AND in the service. This copy exists to give a useful
     * message in the panel; the service's copy is the rule, and holds even if this
     * screen is bypassed.
     */
    public function recordDecision(): void
    {
        /*
         * No panel open means there is nothing to decide.
         *
         * Checked BEFORE the lookup, because `findOrFail(null)` raises a 404 — the
         * same status as "no such task", which is the wrong answer. The two failure
         * modes need different handling: a missing id is a malformed call, a task
         * that is not yours is an authority problem, and a task already decided is a
         * stale page. Collapsing them into one status tells the user nothing.
         */
        if ($this->decidingTaskId === null) {
            $this->addError('decision', 'Open a task before recording a decision.');

            return;
        }

        $task = ApprovalTask::with('request')->find($this->decidingTaskId);

        if (! $task) {
            $this->addError('decision', 'That task no longer exists. Reload the page.');

            return;
        }

        $this->authorizeDecision($task);

        $this->validate([
            'decision' => ['required', 'string'],
            'comments' => [
                // BR-002, mirrored here so the user sees which field is missing.
                Rule::requiredIf(fn () => in_array($this->decision, ['returned', 'rejected'], true)),
                'nullable', 'string', 'max:5000',
            ],
            'conditions' => [
                Rule::requiredIf(fn () => $this->decision === 'approved_with_conditions'),
                'nullable', 'string', 'max:2000',
            ],
        ], [
            'comments.required' => 'A comment is required when returning or rejecting. The requestor cannot act on a decision they cannot read.',
            'conditions.required' => 'Approving with conditions requires the conditions to be recorded.',
        ]);

        $chosen = Decision::from($this->decision);

        try {
            app(WorkflowDecisionService::class)->decide(
                request: $task->request,
                actor: auth()->user(),
                decision: $chosen,
                comments: $this->comments ?: null,
                conditions: $this->conditions ?: null,
            );
        } catch (\RuntimeException $e) {
            /*
             * The service refuses a decision that is no longer valid — usually
             * because somebody else decided it while this page was open.
             *
             * Surfaced as a message rather than a 500: the correct next step is to
             * reload, and saying so is more useful than an error page.
             */
            $this->addError('decision', $e->getMessage());

            return;
        }

        $this->flash = "Decision recorded for {$task->request->request_no}.";
        $this->cancelDecision();
        $this->dispatch('$refresh');
    }

    /** Refuse a decision this user is not authorised to make. */
    private function authorizeDecision(ApprovalTask $task): void
    {
        $decider = app(WorkflowDecisionService::class)->authorisedDecider($task);

        abort_unless(
            $decider !== null && $decider === auth()->id(),
            403,
            'You are not authorised to decide this task.',
        );
    }

    public function render()
    {
        $service = app(WorkflowDecisionService::class);
        $user = auth()->user();

        /*
         * Fetch the candidate tasks, then filter in PHP by the same rule the service
         * uses.
         *
         * A SQL implementation of "tasks I can decide" would be a SECOND definition
         * of the rule, and the two would drift — producing a task that appears in a
         * queue and then refuses the decision. The candidate set is small: pending
         * tasks, which is bounded by the number of requests in flight.
         */
        $candidates = ApprovalTask::query()
            ->pending()
            ->with([
                'request' => fn ($q) => $q->with('requestor:id,name', 'tier:id,name', 'department:id,name'),
                'approver:id,name',
                'delegatedFrom:id,name',
            ])
            ->when($this->overdueOnly, fn ($q) => $q->overdue())
            ->orderByRaw('due_at is null, due_at asc')   // dated first, then undated
            ->orderBy('id')
            ->get()
            ->filter(fn (ApprovalTask $task) => $service->authorisedDecider($task) === $user->id);

        $deciding = $this->decidingTaskId
            ? $candidates->firstWhere('id', $this->decidingTaskId)
            : null;

        // The task may have left the queue while the panel was open.
        if ($this->decidingTaskId && ! $deciding) {
            $this->flash = 'That task is no longer awaiting your decision — it may have been decided or reassigned.';
            $this->decidingTaskId = null;
        }

        /*
         * Which tasks this user is deciding on somebody else's behalf.
         *
         * Computed from the ACTIVE DELEGATION, not from `delegated_from_id`.
         *
         * `delegated_from_id` is written after the fact — it records that a decision
         * WAS made under a delegation. Reading it to label the queue meant a delegate
         * saw no "acting for" marker on the very tasks they were about to decide,
         * which is exactly when they need it: the marker appeared only afterwards,
         * when it no longer helps anybody.
         */
        $actingFor = [];
        foreach ($candidates as $task) {
            if ($task->approver_id === null) {
                continue;
            }

            $delegation = Delegation::for((int) $task->approver_id);

            if ($delegation && $delegation->delegate_id === $user->id) {
                $actingFor[$task->id] = $delegation->approver?->name ?? 'another approver';
            }
        }

        return view('livewire.approvals.index', [
            'tasks' => $candidates->values(),
            'deciding' => $deciding,
            'decisions' => Decision::options(),
            'actingFor' => $actingFor,
            'myDelegations' => Delegation::query()
                ->active()
                ->where('delegate_id', $user->id)
                ->with('approver:id,name')
                ->get(),
            'overdueCount' => $candidates->filter(fn (ApprovalTask $t) => $t->isOverdue())->count(),
        ])->layout('components.layouts.app', ['title' => 'My approvals']);
    }
}
