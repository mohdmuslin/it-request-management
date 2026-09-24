<?php

use App\Enums\BusinessPlanStatus;
use App\Enums\RequestStatus;
use App\Enums\UserRole;
use App\Enums\WorkflowStage;
use App\Livewire\Requests\Create;
use App\Models\Classification;
use App\Models\Department;
use App\Models\Division;
use App\Models\ItRequest;
use App\Models\Tier;
use App\Models\WorkflowHistory;
use Database\Seeders\ReferenceDataSeeder;
use Livewire\Livewire;

/**
 * The request wizard.
 *
 * The step gate is the part worth testing hardest: a wizard whose "Continue" does
 * not actually validate is worse than a single long form, because it gives the
 * impression the fields were checked.
 */
beforeEach(function () {
    $this->seed(ReferenceDataSeeder::class);

    $this->requestor = asUser(UserRole::Requestor);
    $this->owner = asUser(UserRole::ProjectOwner);

    /*
     * Created here rather than taken from the reference seeder.
     *
     * The seeder deliberately seeds no departments — an organisation's structure is
     * not reference data the application can invent. Relying on it produced a null
     * department and a confusing "property id on null" failure that pointed at the
     * test helper rather than at the missing row.
     */
    $this->department = Department::create(['code' => 'ICT', 'name' => 'Information Technology']);
});

/** Values for a complete, valid request. */
function completeRequest(array $overrides = []): array
{
    return array_merge([
        'title' => 'Replacement of the ageing file server',
        'request_date' => now()->toDateString(),
        'department_id' => test()->department->id,
        'project_owner_id' => test()->owner->id,
        'business_need' => 'The current file server is out of warranty and has failed twice this year, causing two full days of downtime for the finance team.',
        'business_plan_status' => BusinessPlanStatus::Aligned->value,
        'business_plan_reference' => 'IT Roadmap 2026, line 4.2',
        'budget_amount' => '48000.00',
        'urgency' => 'medium',
        'impact_if_not_implemented' => 'A third failure would take finance offline during the month-end close, and the warranty is already void so there is no vendor support.',
    ], $overrides);
}

// ---- Access -----------------------------------------------------------------

it('refuses the wizard to an auditor', function () {
    // Read-only means read-only: an auditor who can file a request also appears as
    // a requestor in the trail they are auditing.
    $this->actingAs(asUser(UserRole::Auditor));

    Livewire::test(Create::class)->assertForbidden();
});

it('opens the wizard for a requestor', function () {
    $this->actingAs($this->requestor);

    Livewire::test(Create::class)->assertOk();
});

// ---- The step gate ----------------------------------------------------------

it('refuses to advance past step 1 when it is incomplete', function () {
    /*
     * The check that makes the wizard honest. Without it a user could click through
     * every step and only be told at the end that step 1 was empty.
     */
    $this->actingAs($this->requestor);

    Livewire::test(Create::class)
        ->set('title', '')
        ->call('next')
        ->assertHasErrors(['title'])
        ->assertSet('step', 1);
});

it('advances once the current step is valid', function () {
    $this->actingAs($this->requestor);

    Livewire::test(Create::class)
        ->set('title', 'Replacement of the ageing file server')
        ->set('request_date', now()->toDateString())
        ->set('department_id', $this->department->id)
        ->set('project_owner_id', $this->owner->id)
        ->call('next')
        ->assertHasNoErrors()
        ->assertSet('step', 2);
});

it('refuses to skip forward when a later step is clicked', function () {
    // Clicking the progress bar must not be a way round the validation.
    $this->actingAs($this->requestor);

    Livewire::test(Create::class)
        ->call('goTo', 4)
        ->assertHasErrors()
        ->assertSet('step', 1);
});

// ---- BR-003, the business plan branch ---------------------------------------

it('requires the plan reference when the request claims alignment', function () {
    $this->actingAs($this->requestor);

    /*
     * Step 1 is filled in first, and that is not padding.
     *
     * `goTo(3)` validates steps 1 and 2 in order and stops at the first failure, so
     * an incomplete step 1 would throw before step 2 was ever examined — and the
     * assertion below would fail with "missing error" while the rule under test was
     * working perfectly. The earlier version of this test made exactly that mistake.
     */
    Livewire::test(Create::class)
        ->set('title', 'Replacement of the ageing file server')
        ->set('request_date', now()->toDateString())
        ->set('department_id', $this->department->id)
        ->set('project_owner_id', $this->owner->id)
        ->set('business_need', str_repeat('The server fails. ', 5))
        ->set('business_plan_status', BusinessPlanStatus::Aligned->value)
        ->set('business_plan_reference', '')
        ->call('goTo', 3)
        ->assertHasErrors(['business_plan_reference'])
        ->assertSet('step', 2);
});

it('requires a justification when the request is ad hoc', function () {
    $this->actingAs($this->requestor);

    Livewire::test(Create::class)
        ->set('title', 'Replacement of the ageing file server')
        ->set('request_date', now()->toDateString())
        ->set('department_id', $this->department->id)
        ->set('project_owner_id', $this->owner->id)
        ->set('business_need', str_repeat('The server fails. ', 5))
        ->set('business_plan_status', BusinessPlanStatus::AdHoc->value)
        ->set('adhoc_justification', '')
        ->call('goTo', 3)
        ->assertHasErrors(['adhoc_justification'])
        ->assertSet('step', 2);
});

it('accepts an ad hoc request once it is justified', function () {
    $this->actingAs($this->requestor);

    Livewire::test(Create::class)
        ->set(completeRequest([
            'business_plan_status' => BusinessPlanStatus::AdHoc->value,
            'business_plan_reference' => '',
            'adhoc_justification' => 'A regulator requires this control to be in place before the next audit in November.',
        ]))
        ->call('submit')
        ->assertHasNoErrors(['adhoc_justification']);
});

// ---- Form rules that catch real mistakes ------------------------------------

it('refuses a completion date before the start date', function () {
    /*
     * Asserted through `submit`, not `saveDraft`.
     *
     * A draft deliberately does not enforce the whole rule set — only that values
     * already entered are not nonsense, which `rejectInvalidValues()` covers. The
     * date range is enforced on the way into the approval chain, and that is where
     * this asserts it.
     */
    $this->actingAs($this->requestor);

    Livewire::test(Create::class)
        ->set(completeRequest([
            'proposed_start_date' => '2026-06-01',
            'target_completion_date' => '2026-05-01',
        ]))
        ->call('submit')
        ->assertHasErrors(['target_completion_date'])
        ->assertSet('step', 3);
});

it('rejects a nonsense value in a draft', function () {
    // A draft may be incomplete, but it must not store a completion date that reads
    // backwards — the requestor would build on it and only find out at the end.
    $this->actingAs($this->requestor);

    Livewire::test(Create::class)
        ->set('proposed_start_date', '2026-06-01')
        ->set('target_completion_date', '2026-05-01')
        ->call('saveDraft')
        ->assertHasErrors(['target_completion_date']);

    expect(ItRequest::whereNotNull('proposed_start_date')->exists())->toBeFalse();
});

it('requires a reason when urgency is high', function () {
    $this->actingAs($this->requestor);

    Livewire::test(Create::class)
        ->set(completeRequest(['urgency' => 'high', 'urgency_justification' => '']))
        ->call('submit')
        ->assertHasErrors(['urgency_justification'])
        ->assertSet('step', 4);
});

it('does not require a reason when urgency is low', function () {
    // Requiring one every time trains people to type "urgent", which carries no
    // information at all.
    $this->actingAs($this->requestor);

    Livewire::test(Create::class)
        ->set(completeRequest(['urgency' => 'low', 'urgency_justification' => '']))
        ->call('submit')
        ->assertHasNoErrors(['urgency_justification']);
});

// ---- Saving and submitting --------------------------------------------------

it('saves a partial draft without requiring the whole form', function () {
    // A draft is where half-finished work lives. Demanding completeness to save one
    // means a requestor who has to stop loses what they have.
    $this->actingAs($this->requestor);

    Livewire::test(Create::class)
        ->set('title', 'Half-finished idea')
        ->call('saveDraft')
        ->assertHasNoErrors();

    expect(ItRequest::where('title', 'Half-finished idea')->exists())->toBeTrue();
});

it('allocates a request number on save', function () {
    $this->actingAs($this->requestor);

    Livewire::test(Create::class)
        ->set('title', 'Numbered request')
        ->call('saveDraft');

    $request = ItRequest::where('title', 'Numbered request')->first();

    expect($request->request_no)->toMatch('/^REQ-\d{4}-\d{4}$/');
});

it('records the requestor as the signed-in user, not from the form', function () {
    /*
     * `requestor_id` is deliberately not a form field. Accepting it would let one
     * user file a request in another's name — and since the number, the approval
     * chain and the whole trail are built on the requestor, that is not a spoofed
     * field but a spoofed request.
     */
    $this->actingAs($this->requestor);

    Livewire::test(Create::class)
        ->set('title', 'Ownership test')
        ->call('saveDraft');

    expect(ItRequest::where('title', 'Ownership test')->first()->requestor_id)
        ->toBe($this->requestor->id);
});

it('submits a complete request into the approval chain', function () {
    $this->actingAs($this->requestor);

    Livewire::test(Create::class)
        ->set(completeRequest())
        ->call('submit')
        ->assertHasNoErrors();

    $request = ItRequest::where('title', 'Replacement of the ageing file server')->first();

    expect($request)->not->toBeNull()
        ->and($request->status)->toBe(RequestStatus::Submitted->value)
        ->and($request->current_stage)->toBe(WorkflowStage::ProjectOwner->value)
        ->and($request->submitted_at)->not->toBeNull()
        ->and(WorkflowHistory::where('request_id', $request->id)->where('action', 'submit')->exists())->toBeTrue();
});

it('shows which step failed rather than a message for a field off screen', function () {
    // Sending someone to step 4 with "business need is required" is a dead end —
    // that field is on step 2.
    $this->actingAs($this->requestor);

    Livewire::test(Create::class)
        ->set(completeRequest(['business_need' => '']))
        ->call('submit')
        ->assertHasErrors(['business_need'])
        ->assertSet('step', 2);
});

it('clears the division when the department changes', function () {
    // Otherwise a request can be saved against a division from a different
    // department, and the pair on the record never existed.
    $this->actingAs($this->requestor);

    Livewire::test(Create::class)
        ->set('division_id', 1)
        ->set('department_id', $this->department->id + 1)
        ->assertSet('division_id', null);
});

// ---- The dropdowns actually have labels in them -----------------------------

it('renders the tier and classification options by name', function () {
    /*
     * A test for the TEXT of each option, not for the presence of the select.
     *
     * The tier and classification dropdowns rendered as blank lines, because the
     * query asked for a `label` column on tables whose column is `name`. Every other
     * assertion in this file passed throughout — the select existed, the component
     * bound to it, the value saved — and the screen was still unusable, because
     * nothing checked that an option said anything.
     *
     * A test that asserts a list is populated and a test that asserts its items are
     * labelled are different tests, and only the second one catches this.
     */
    $this->actingAs($this->requestor);

    $tier = Tier::orderBy('sort_order')->first();
    $classification = Classification::orderBy('name')->first();

    expect($tier)->not->toBeNull()
        ->and($classification)->not->toBeNull();

    Livewire::test(Create::class)
        ->assertSee($tier->name)
        ->assertSee($classification->name);
});

it('stores the business plan branch it did not take as null', function () {
    /*
     * If both fields were kept, a reader could not tell which claim the request
     * actually rests on — and the trail would show a justification for a position
     * the requestor had abandoned.
     */
    $this->actingAs($this->requestor);

    Livewire::test(Create::class)
        ->set('title', 'Branch test')
        ->set('business_plan_status', BusinessPlanStatus::Aligned->value)
        ->set('business_plan_reference', 'IT Roadmap 2026, line 4.2')
        ->set('adhoc_justification', 'This should be discarded because the aligned branch was chosen.')
        ->call('saveDraft')
        ->assertHasNoErrors();

    $request = ItRequest::where('title', 'Branch test')->first();

    expect($request->business_plan_reference)->toBe('IT Roadmap 2026, line 4.2')
        ->and($request->adhoc_justification)->toBeNull();
});
