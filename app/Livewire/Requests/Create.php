<?php

namespace App\Livewire\Requests;

use App\Enums\BusinessPlanStatus;
use App\Enums\RequestStatus;
use App\Enums\WorkflowStage;
use App\Http\Requests\ItRequestFormRequest;
use App\Models\Classification;
use App\Models\Department;
use App\Models\Division;
use App\Models\ItRequest;
use App\Models\Tier;
use App\Models\User;
use App\Services\AuditService;
use App\Services\RequestNumberService;
use App\Services\WorkflowService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * The five-step request wizard.
 *
 * WHY A WIZARD AND NOT ONE LONG FORM
 *
 * The request has around forty fields across four distinct concerns. As a single
 * page it is a wall of inputs, and the fields most often missed — the business
 * plan branch, the impact statement — are the ones furthest down the page. Split
 * into steps, each step is short enough that a missing field is visible, and the
 * validation message can name the step to return to.
 *
 * WHY THE STEPS ARE VALIDATED FROM ONE RULE SET
 *
 * `ItRequestFormRequest::steps()` partitions the same rules the final check uses.
 * Two copies would eventually disagree, and the failure mode is a request that
 * passes every step and is then rejected at the end with nothing indicating which
 * step was wrong.
 *
 * DRAFTS ARE SAVED EXPLICITLY, NOT AUTOMATICALLY
 *
 * The request row is created when the requestor asks for it, not while they type.
 * A row created on the first keystroke and then abandoned is indistinguishable
 * from a real draft, so the list fills with empty requests — which is precisely
 * the complaint about the current SharePoint list.
 */
class Create extends Component
{
    /** Which step is on screen. */
    public int $step = 1;

    /** The last step, so the view can render the progress bar without hardcoding it. */
    public int $lastStep = 4;

    /**
     * The request being edited, once it exists.
     *
     * LOCKED so the client cannot change it. Without this, a crafted payload could
     * point the component at another user's draft and overwrite it — the component
     * would then save over the wrong row using the current user's data.
     */
    #[Locked]
    public ?int $requestId = null;

    /** A one-line confirmation shown after any save or step move. */
    public string $flash = '';

    // ---- Step 1: request information ---------------------------------------

    public string $title = '';

    public ?string $request_date = null;

    public ?int $department_id = null;

    public ?int $division_id = null;

    public ?int $project_owner_id = null;

    public ?int $project_sponsor_id = null;

    public ?int $proposed_tier_id = null;

    public ?int $proposed_classification_id = null;

    // ---- Step 2: business justification ------------------------------------

    public string $business_need = '';

    public string $business_plan_status = '';

    public string $business_plan_reference = '';

    public string $adhoc_justification = '';

    public string $value_proposition = '';

    // ---- Step 3: budget and timeline ---------------------------------------

    public ?string $budget_amount = null;

    public string $budget_source = '';

    public string $budget_code = '';

    public string $funding_type = '';

    public ?string $proposed_start_date = null;

    public ?string $target_completion_date = null;

    public string $forecast_resources = '';

    // ---- Step 4: risk and scope --------------------------------------------

    public string $urgency = '';

    public string $urgency_justification = '';

    public string $risk_summary = '';

    public string $mitigation_plan = '';

    public string $dependencies_constraints = '';

    public string $impact_if_not_implemented = '';

    public string $in_scope = '';

    public string $out_of_scope = '';

    public function mount(): void
    {
        $this->authorize('create', ItRequest::class);

        // Defaulted, not forced. The date is editable, but it is almost always
        // today and requiring a click to confirm that is friction for no benefit.
        $this->request_date = now()->toDateString();

        $this->department_id = auth()->user()->department_id;
        $this->division_id = auth()->user()->division_id;
    }

    /**
     * Move forward one step, validating the current one on the way out.
     *
     * The check on the way out is what makes the wizard honest. Without it, someone
     * could click through all four steps and only discover at the end that step 2
     * was incomplete — with no indication of which step.
     */
    public function next(): void
    {
        $this->validateStep($this->step);

        if ($this->step < $this->lastStep) {
            $this->step++;
            $this->flash = '';
        }
    }

    public function previous(): void
    {
        if ($this->step > 1) {
            $this->step--;
            $this->flash = '';
        }
    }

    /**
     * Jump to a step from the progress bar.
     *
     * Backwards is free. Forward runs every intervening step's rules, because a
     * click on the bar would otherwise be a way to skip validation entirely — which
     * would make the "Review" step the first time a problem is ever reported.
     */
    public function goTo(int $step): void
    {
        if ($step < 1 || $step > $this->lastStep) {
            return;
        }

        if ($step > $this->step) {
            foreach (range($this->step, $step - 1) as $from) {
                try {
                    $this->validateStep($from);
                } catch (ValidationException $e) {
                    /*
                     * Land on the step that failed, not the one we started from.
                     *
                     * Clicking step 4 and failing on step 2 must show step 2. Staying
                     * on step 1 displays a message about a field that is not on screen,
                     * which reads as the form being broken rather than incomplete.
                     */
                    $this->step = $from;

                    throw $e;
                }
            }
        }

        $this->step = $step;
        $this->flash = '';
    }

    /**
     * Save as a draft and stay put.
     *
     * Deliberately does not require the whole form to be valid. A draft is where
     * half-finished work lives, and demanding completeness to save one would mean a
     * requestor who has to stop loses what they have.
     *
     * It DOES validate every field that is actually filled in. Otherwise a draft
     * could hold a completion date before its start date, or a budget that is not
     * a number — values the requestor would only discover at the end, after
     * building on top of them. This is done without `validate()`, because that
     * fails on the empty required fields a draft is allowed to have.
     */
    public function saveDraft(): void
    {
        $this->authorize('create', ItRequest::class);

        $this->rejectInvalidValues();

        $this->persist(submit: false);

        $this->flash = 'Draft saved. You can leave this page and come back to it.';
    }

    /**
     * Refuse values that are wrong regardless of what else is empty.
     *
     * These are checks a draft cannot legitimately fail: a date stored as text, an
     * amount that is not money, and a range that reads backwards. An empty field is
     * fine — these are about fields the requestor has already filled in.
     */
    private function rejectInvalidValues(): void
    {
        $messages = [];

        foreach (['request_date', 'proposed_start_date', 'target_completion_date'] as $field) {
            if ($this->{$field} !== null && $this->{$field} !== '' && ! $this->isDate($this->{$field})) {
                $messages[$field] = 'Enter a valid date.';
            }
        }

        if ($this->proposed_start_date && $this->target_completion_date
            && $this->isDate($this->proposed_start_date) && $this->isDate($this->target_completion_date)
            && strtotime((string) $this->target_completion_date) < strtotime((string) $this->proposed_start_date)) {
            $messages['target_completion_date'] = 'The target completion date cannot be before the start date.';
        }

        if ($this->budget_amount !== null && $this->budget_amount !== '' && ! is_numeric($this->budget_amount)) {
            $messages['budget_amount'] = 'Enter the budget as a number, without commas or a currency symbol.';
        }

        if ($messages) {
            throw ValidationException::withMessages($messages);
        }
    }

    private function isDate(?string $value): bool
    {
        return $value !== null && $value !== '' && strtotime($value) !== false;
    }

    /** Validate everything, submit into the approval chain, and show the request. */
    public function submit(): void
    {
        $this->authorize('create', ItRequest::class);

        $this->validateAll();

        $request = $this->persist(submit: true);

        session()->flash(
            'status',
            "Request {$request->request_no} has been submitted to {$request->projectOwner->name} for approval.",
        );

        $this->redirectRoute('requests.show', ['request' => $request], navigate: true);
    }

    /** Clear the division when the department changes, so a stale pair cannot be saved. */
    public function updatedDepartmentId(): void
    {
        $this->division_id = null;
    }

    /** Validate one step against its slice of the shared rule set. */
    private function validateStep(int $step): void
    {
        $this->validateForStep($this->formRequest()->steps()[$step] ?? []);
    }

    /**
     * Validate every step, reporting the earliest that fails.
     *
     * The failing step is put back on screen before the exception leaves, so the
     * message points at a field the user can actually see. Showing "business plan
     * reference is required" while step 4 is displayed is a dead end.
     */
    private function validateAll(): void
    {
        foreach ($this->formRequest()->steps() as $number => $rules) {
            try {
                $this->validateForStep($rules);
            } catch (ValidationException $e) {
                $this->step = $number;

                throw $e;
            }
        }
    }

    /**
     * Run one step's rules.
     *
     * WHY THIS IS A SEPARATE METHOD RATHER THAN ONE `$this->validate()` CALL
     *
     * The rules must come from a directly-constructed form request, not one resolved
     * from the container. Laravel's `afterResolving` hook makes
     * `app(ItRequestFormRequest::class)` validate the current HTTP request instead —
     * which in a Livewire action is empty, so every required field fails at once.
     *
     * That produced a failure with no clue as to its cause: on step 1 the user filled
     * in the title, date, department and owner, and Continue reported all four as
     * required. The message was accurate about the empty request and useless to the
     * person reading it, and the obvious explanation — that the component had lost
     * its data — was wrong.
     *
     * Livewire validates the component's public properties against these rules.
     * Fields outside the step have no rules here, so they are not considered.
     */
    private function validateForStep(array $rules): void
    {
        $form = $this->formRequest();

        $this->validate($rules, $form->messages(), $form->attributes());
    }

    /**
     * The shared rules, constructed directly and given this component's values.
     *
     * NOT resolved from the container: Laravel's `afterResolving` hook makes
     * `app(ItRequestFormRequest::class)` validate the current HTTP request instead,
     * which in a Livewire action is empty — so every required field fails at once
     * with a message that looks like the user's data was lost.
     */
    private function formRequest(): ItRequestFormRequest
    {
        return (new ItRequestFormRequest)->withData([
            'business_plan_status' => $this->business_plan_status,
            'urgency' => $this->urgency,
        ]);
    }

    /**
     * Write the request.
     *
     * One transaction covering the row, its number and the submission. A request
     * submitted without its history row — or holding a number that a rollback
     * released — would be an unauditable record, and this is the only place a
     * request is ever created.
     */
    private function persist(bool $submit): ItRequest
    {
        return DB::transaction(function () use ($submit) {
            $request = $this->requestId
                ? ItRequest::findOrFail($this->requestId)
                : new ItRequest;

            if ($request->exists) {
                // Re-checked rather than assumed: the request may have been
                // submitted or returned by somebody else since the page was opened.
                $this->authorize('update', $request);
            }

            $attributes = [
                'title' => $this->title,
                'request_date' => $this->request_date,
                'department_id' => $this->department_id,
                'division_id' => $this->division_id,
                'project_owner_id' => $this->project_owner_id,
                'project_sponsor_id' => $this->project_sponsor_id ?: null,
                'proposed_tier_id' => $this->proposed_tier_id,
                'proposed_classification_id' => $this->proposed_classification_id,
                'business_need' => $this->business_need,
                'value_proposition' => $this->value_proposition ?: null,
                'budget_source' => $this->budget_source ?: null,
                'budget_code' => $this->budget_code ?: null,
                'funding_type' => $this->funding_type ?: null,
                'proposed_start_date' => $this->proposed_start_date ?: null,
                'target_completion_date' => $this->target_completion_date ?: null,
                'forecast_resources' => $this->forecast_resources ?: null,
                'urgency' => $this->urgency ?: null,
                'urgency_justification' => $this->urgency_justification ?: null,
                'risk_summary' => $this->risk_summary ?: null,
                'mitigation_plan' => $this->mitigation_plan ?: null,
                'dependencies_constraints' => $this->dependencies_constraints ?: null,
                'impact_if_not_implemented' => $this->impact_if_not_implemented ?: null,
                'in_scope' => $this->in_scope ?: null,
                'out_of_scope' => $this->out_of_scope ?: null,
            ];

            /*
             * The business-plan branch clears the side that does not apply.
             *
             * Without this, a requestor who switches to "In Business Plan" after
             * typing an ad hoc justification leaves BOTH on the record. A reader
             * then cannot tell which claim the request actually rests on — and the
             * trail would show a justification for a position that was withdrawn.
             */
            if ($this->business_plan_status !== '') {
                $status = BusinessPlanStatus::tryFrom($this->business_plan_status);

                $attributes['business_plan_status'] = $this->business_plan_status;
                $attributes['business_plan_reference'] = $status === BusinessPlanStatus::Aligned
                    ? ($this->business_plan_reference ?: null)
                    : null;
                $attributes['adhoc_justification'] = $status === BusinessPlanStatus::AdHoc
                    ? ($this->adhoc_justification ?: null)
                    : null;
            }

            /*
             * Money as a string, never a float.
             *
             * The column is DECIMAL(12,2) and the brief's §5.2 forbids float storage
             * for financial values. Passing a PHP float through would reintroduce the
             * exact representation error the column type exists to avoid.
             */
            if ($this->budget_amount !== null && $this->budget_amount !== '') {
                $attributes['budget_amount'] = (string) $this->budget_amount;
            }

            if (! $request->exists) {
                $attributes['request_no'] = app(RequestNumberService::class)->next();
                $attributes['requestor_id'] = auth()->id();
                $attributes['status'] = RequestStatus::Draft->value;
                $attributes['current_stage'] = WorkflowStage::Submission->value;
            }

            // Captured before the fill, so the audit row can say what changed
            // rather than only what the values now are.
            $before = $request->exists ? $request->getAttributes() : [];

            $request->fill($attributes)->save();

            if ($submit) {
                app(WorkflowService::class)->submit($request);

                // Reloaded so the redirect carries the post-submission state rather
                // than the draft state that was in memory a moment ago.
                $request->refresh();
            } elseif ($before) {
                // `recordChanges` rather than `record`: it diffs the two arrays and
                // returns null when nothing actually moved, so a save that changed
                // nothing leaves no audit row. Auditing an empty save would fill the
                // trail with entries that say only "this page was reloaded".
                app(AuditService::class)->recordChanges('updated', $request, $before, $request->id);
            } else {
                app(AuditService::class)->record('created', $request, null, $request->getAttributes(), $request->id);
            }

            $this->requestId = $request->id;

            return $request;
        });
    }

    public function render()
    {
        return view('livewire.requests.create', [
            'departments' => Department::orderBy('name')->get(['id', 'name']),

            // Filtered by department so the list is short. Every division in the
            // group is a scroll, not a choice.
            'divisions' => $this->department_id
                ? Division::where('department_id', $this->department_id)->orderBy('name')->get(['id', 'name'])
                : collect(),

            'people' => User::query()->active()->orderBy('name')->get(['id', 'name']),

            /*
             * `name`, not `label`.
             *
             * These reference tables have a `name` column. Selecting `label`
             * returned rows with a null name for every option, so all three dropdowns
             * rendered as blank lines — which reads as "the data is missing" rather
             * than "the query is wrong", and is the sort of thing that survives a
             * test suite because nothing asserts the option TEXT.
             */
            'tiers' => Tier::orderBy('sort_order')->get(['id', 'name']),
            'classifications' => Classification::orderBy('name')->get(['id', 'name']),
            'planStatuses' => BusinessPlanStatus::options(),
        ])->layout('components.layouts.app', ['title' => $this->title ?: 'New request']);
    }
}
