<?php

use App\Enums\UserRole;
use App\Http\Requests\ItRequestFormRequest;
use App\Livewire\Admin\TierRules;
use App\Livewire\Requests\Create;
use App\Models\Department;
use App\Models\ItRequest;
use App\Models\Tier;
use App\Models\TierFieldRule;
use App\Models\User;
use App\Services\TierFieldRules;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Validation\Rules\RequiredIf;
use Livewire\Livewire;

/**
 * BR-003 — conditional fields driven by tier.
 *
 * The compliance matrix reported this rule as met for two revisions on the strength of a
 * business-plan conditional block, which is driven by neither a tier nor a classification. These
 * tests assert the requirement as written: the TIER decides.
 *
 * The brief does not say which field for which tier — that is a business decision, and the seeder
 * holds a first draft. So the tests here set their own rules rather than asserting the seeded
 * ones, except where the seeded position is the thing under test.
 */
beforeEach(function () {
    $this->seed(ReferenceDataSeeder::class);

    Department::create(['code' => 'ICT', 'name' => 'Information Technology']);

    $this->requestor = asUser(UserRole::Requestor);
    $this->actingAs($this->requestor);

    $this->tier1 = Tier::where('code', 'tier_1')->firstOrFail();
    $this->tier2 = Tier::where('code', 'tier_2')->firstOrFail();

    // Rules are cached per request; a stale cache between assertions would hide a failure.
    app(TierFieldRules::class)->forget();
});

/** Set one rule, bypassing the screen, so a test states only what it is about. */
function setRule(?int $tierId, string $field, string $requirement): void
{
    TierFieldRule::updateOrCreate(
        ['tier_id' => $tierId, 'field' => $field],
        ['requirement' => $requirement],
    );

    app(TierFieldRules::class)->forget();
}

// ---- Resolution -------------------------------------------------------------

it('treats a field with no rule as optional', function () {
    /*
     * Absence means optional. This is what keeps the table small — the common case stores
     * nothing — and what makes "add a field to the form" need no rule rows at all.
     */
    expect(app(TierFieldRules::class)->forTier($this->tier1->id)['budget_source'])
        ->toBe(TierFieldRule::OPTIONAL);
});

it('applies the every-tier default when the tier has no rule of its own', function () {
    setRule(null, 'risk_summary', TierFieldRule::REQUIRED);

    expect(app(TierFieldRules::class)->forTier($this->tier1->id)['risk_summary'])
        ->toBe(TierFieldRule::REQUIRED);
});

it('lets a tier override the default', function () {
    // The precedence rule, in one assertion: specific beats general.
    setRule(null, 'risk_summary', TierFieldRule::REQUIRED);
    setRule($this->tier1->id, 'risk_summary', TierFieldRule::OPTIONAL);

    $rules = app(TierFieldRules::class);

    expect($rules->forTier($this->tier1->id)['risk_summary'])->toBe(TierFieldRule::OPTIONAL)
        ->and($rules->forTier($this->tier2->id)['risk_summary'])->toBe(TierFieldRule::REQUIRED);
});

it('resolves the same rules for the form, the view and the component', function () {
    /*
     * The reason this is a service rather than three implementations. If the form and the view
     * disagreed, a field would be required by validation and hidden on screen — which is the
     * worst possible combination, because it is unsubmittable and the user cannot see why.
     */
    setRule($this->tier2->id, 'budget_amount', TierFieldRule::REQUIRED);

    $rules = app(TierFieldRules::class);

    expect($rules->requiredFor($this->tier2->id))->toHaveKey('budget_amount')
        ->and($rules->forTier($this->tier2->id)['budget_amount'])->toBe(TierFieldRule::REQUIRED);
});

it('reports which step a governed field lives in', function () {
    // Used to fold tier rules into the right wizard step, so a failure names the screen with the
    // problem rather than sending the user to the last one.
    expect(TierFieldRules::stepFor('budget_amount'))->toBe(3)
        ->and(TierFieldRules::stepFor('risk_summary'))->toBe(4)
        ->and(TierFieldRules::stepFor('value_proposition'))->toBe(2)
        ->and(TierFieldRules::stepFor('not_a_field'))->toBeNull();
});

// ---- Validation -------------------------------------------------------------

it('requires a field the tier marks required', function () {
    setRule($this->tier2->id, 'budget_amount', TierFieldRule::REQUIRED);

    $request = (new ItRequestFormRequest)->withData([
        'proposed_tier_id' => $this->tier2->id,
        'budget_amount' => null,
    ]);

    expect($request->rules()['budget_amount'])->toContain('required');
});

it('does not require the same field for a tier that does not mark it', function () {
    /*
     * BOTH TIERS ARE SET EXPLICITLY.
     *
     * The seeder marks `budget_amount` required for every tier, because the tier bands decide the
     * tier and so an amount is not optional detail — see `seedTierFieldRules()`. Relying on the
     * seeded position would therefore make this test pass or fail for a reason unrelated to what
     * it is about, which is that a rule on one tier does not leak to another.
     */
    setRule($this->tier2->id, 'budget_amount', TierFieldRule::REQUIRED);
    setRule($this->tier1->id, 'budget_amount', TierFieldRule::OPTIONAL);

    $request = (new ItRequestFormRequest)->withData([
        'proposed_tier_id' => $this->tier1->id,
        'budget_amount' => null,
    ]);

    expect($request->rules()['budget_amount'])->not->toContain('required');
});

it('folds a tier rule into the step the field belongs to', function () {
    /*
     * The bug this prevents: a tier-required field in step 3 passing step 3's check and failing
     * only at the end, leaving the requestor on the last screen with no indication of which one
     * to go back to.
     */
    setRule($this->tier2->id, 'budget_source', TierFieldRule::REQUIRED);

    $request = (new ItRequestFormRequest)->withData([
        'proposed_tier_id' => $this->tier2->id,
    ]);

    $steps = $request->steps();

    expect($steps[3]['budget_source'] ?? [])->toContain('required');
});

it('prohibits a value in a field the tier hides', function () {
    // Optional would let a stale budget through — a number in a column that means nothing for
    // that tier.
    setRule($this->tier1->id, 'budget_amount', TierFieldRule::HIDDEN);

    $request = (new ItRequestFormRequest)->withData([
        'proposed_tier_id' => $this->tier1->id,
    ]);

    expect($request->rules()['budget_amount'])->toContain('prohibited');
});

it('adds no tier rules when no tier is selected', function () {
    /*
     * Tier is proposed, not required, and a draft may not have one yet. Treating "no tier" as
     * "the strictest tier" would refuse a first step somebody has not finished.
     */
    setRule($this->tier2->id, 'budget_amount', TierFieldRule::REQUIRED);

    $request = (new ItRequestFormRequest)->withData([
        'proposed_tier_id' => null,
    ]);

    expect($request->rules()['budget_amount'])->not->toContain('required');
});

it('never relaxes the business-plan branch, whatever the tier says', function () {
    /*
     * `business_plan_reference` and `adhoc_justification` are conditionally required by the
     * branch, not by tier. A tier making one optional would let a request be submitted claiming
     * plan alignment with no plan cited and no reason given — which is the specific failure
     * BR-003's conditional block exists to prevent.
     */
    expect(app(TierFieldRules::class)->mayRelax('business_plan_reference'))->toBeFalse()
        ->and(app(TierFieldRules::class)->mayRelax('adhoc_justification'))->toBeFalse()
        ->and(app(TierFieldRules::class)->mayRelax('budget_amount'))->toBeTrue();
});

it('refuses a rule for a field that is not governed', function () {
    // The administration screen cannot produce this; the service refuses it so a row naming a
    // non-existent field cannot be written and then silently govern nothing.
    expect(TierFieldRules::isGoverned('budget_amount'))->toBeTrue()
        ->and(TierFieldRules::isGoverned('title'))->toBeFalse()
        ->and(TierFieldRules::isGoverned('status'))->toBeFalse();
});

it('does not offer a rule for a field that must always be required', function () {
    // A rule that could make `title` optional would allow a configuration that breaks the
    // workflow, so those fields are not in the governed list at all.
    $governed = TierFieldRules::fieldNames();

    expect($governed)->not->toContain('title')
        ->and($governed)->not->toContain('department_id')
        ->and($governed)->not->toContain('project_owner_id')
        ->and($governed)->not->toContain('impact_if_not_implemented');
});

it('does not offer a rule for the business-plan branch', function () {
    /*
     * These two were ORIGINALLY in the governed list, and that was a defect.
     *
     * Tier rules are merged OVER the step rules, so a tier marking `business_plan_reference`
     * optional emitted `nullable` and REPLACED the `requiredIf` the business-plan branch depends
     * on. The result: a request could be submitted claiming alignment with an approved plan, with
     * no plan cited and no reason given — the specific failure the branch exists to prevent, and
     * the opposite of what BR-003 asks for.
     *
     * `mayRelax()` described the rule and was never called, so nothing stopped it. Both halves are
     * asserted here.
     */
    $governed = TierFieldRules::fieldNames();

    expect($governed)->not->toContain('business_plan_reference')
        ->and($governed)->not->toContain('adhoc_justification');
});

it('ignores a business-plan rule even if one is written directly', function () {
    /*
     * The second guard. A rule for a non-relaxable field can still be written — by a direct
     * insert, or by a future edit to the governed list — and it must not reach the rule set.
     *
     * ASSERTED ON `tierRules()`, NOT ON `rules()`.
     *
     * `rules()` is the step rules merged with the tier rules, and the step rule for this field is
     * `requiredIf(...)` PLUS `nullable` — because the field is conditionally required, so a blank
     * is legitimate when the branch does not apply. Asserting `not->toContain('nullable')` against
     * the merged set fails against CORRECT behaviour, and would have to be "fixed" by weakening
     * the assertion — which is how a test stops testing the thing it was written for.
     *
     * The question worth asking is narrower: did the tier rule reach the set at all? This asserts
     * that, and then that the branch survived.
     */
    setRule($this->tier1->id, 'business_plan_reference', TierFieldRule::OPTIONAL);

    $request = (new ItRequestFormRequest)->withData([
        'proposed_tier_id' => $this->tier1->id,
        'business_plan_status' => 'aligned',
    ]);

    $tierRules = (new ReflectionMethod($request, 'tierRules'))->invoke($request);

    expect($tierRules)->not->toHaveKey('business_plan_reference');

    // And the branch itself is intact.
    $rules = $request->rules()['business_plan_reference'];

    expect(collect($rules)->contains(fn ($r) => $r instanceof RequiredIf))->toBeTrue();
});

it('still requires the plan reference when a tier hides it, rather than skipping the check', function () {
    /*
     * The end-to-end consequence: a Tier 1 request claiming plan alignment with no reference must
     * be refused, whatever a tier rule says about that field.
     */
    setRule($this->tier1->id, 'business_plan_reference', TierFieldRule::HIDDEN);
    setRule($this->tier1->id, 'adhoc_justification', TierFieldRule::HIDDEN);

    Livewire::test(Create::class)
        ->set('proposed_tier_id', $this->tier1->id)
        ->set('title', 'Claims a plan with no reference')
        ->set('request_date', now()->toDateString())
        ->set('department_id', Department::first()->id)
        ->set('project_owner_id', User::factory()->create()->id)
        ->set('business_need', str_repeat('Need. ', 10))
        ->set('business_plan_status', 'aligned')
        ->set('business_plan_reference', null)
        ->set('urgency', 'low')
        ->set('impact_if_not_implemented', str_repeat('Impact. ', 6))
        ->call('submit')
        ->assertHasErrors(['business_plan_reference']);
});

// ---- The wizard -------------------------------------------------------------

it('clears a hidden field when the tier changes, and says so', function () {
    /*
     * A hidden field that kept its value would be refused by validation with an error pointing
     * at a field the user can no longer see — invisible AND blocking, which is the worst of
     * both. So the value is cleared and the flash names what went.
     */
    setRule($this->tier1->id, 'budget_amount', TierFieldRule::HIDDEN);

    Livewire::test(Create::class)
        ->set('proposed_tier_id', $this->tier2->id)
        ->set('budget_amount', '15000')
        ->assertSet('budget_amount', '15000')
        ->set('proposed_tier_id', $this->tier1->id)
        ->assertSet('budget_amount', null)
        ->assertSee('Budget amount');
});

it('leaves a visible field alone when the tier changes', function () {
    // Clearing everything on a tier change would discard work for no reason.
    setRule($this->tier1->id, 'budget_amount', TierFieldRule::OPTIONAL);

    Livewire::test(Create::class)
        ->set('proposed_tier_id', $this->tier2->id)
        ->set('budget_amount', '15000')
        ->set('proposed_tier_id', $this->tier1->id)
        ->assertSet('budget_amount', '15000');
});

it('hides a field the tier does not use, and shows the others', function () {
    setRule($this->tier1->id, 'forecast_resources', TierFieldRule::HIDDEN);

    $component = Livewire::test(Create::class)
        ->set('proposed_tier_id', $this->tier1->id)
        ->set('step', 3);

    /*
     * Asserted on the CONTROL, not the label.
     *
     * The explanatory note above the grid NAMES the hidden fields — "Not applicable to Tier 1:
     * Resources required." — which is the whole point of the note. Asserting `assertDontSee` on
     * the label would fail against a correctly hidden field, so the assertion is that no input
     * is rendered for it.
     */
    $component->assertDontSeeHtml('wire:model="forecast_resources"');

    // Named rather than silently absent, so an empty gap is not read as a broken form.
    $component->assertSee('Not applicable to');

    // And a field that is NOT hidden still renders.
    $component->assertSeeHtml('wire:model="budget_amount"');
});

it('hides a step 4 field the tier does not use', function () {
    /*
     * Step 4 was MISSED the first time this feature was written.
     *
     * The step 3 fields were wired to `$isHidden()` and the step 4 fields were not, so a rule
     * hiding `dependencies_constraints` was saved, resolved correctly by the service, honoured by
     * validation — and the field was still displayed. The form then refused the request over a
     * field the requestor could plainly see, which reads as the validation being wrong rather
     * than the view.
     *
     * A test per step is the cheap guard: the failure is invisible from the service's side,
     * because the service was right.
     */
    setRule($this->tier1->id, 'dependencies_constraints', TierFieldRule::HIDDEN);

    $component = Livewire::test(Create::class)
        ->set('proposed_tier_id', $this->tier1->id)
        ->set('step', 4);

    $component->assertDontSeeHtml('wire:model="dependencies_constraints"');
    $component->assertSee('Not applicable to');

    // A step 4 field that is not hidden still renders, so the assertion is not vacuous.
    $component->assertSeeHtml('wire:model="risk_summary"');
});

it('marks a step 4 field required when the tier says so', function () {
    setRule($this->tier2->id, 'risk_summary', TierFieldRule::REQUIRED);

    Livewire::test(Create::class)
        ->set('proposed_tier_id', $this->tier2->id)
        ->set('step', 4)
        ->assertSeeHtml('wire:model="risk_summary"');

    $request = (new ItRequestFormRequest)->withData([
        'proposed_tier_id' => $this->tier2->id,
    ]);

    expect($request->steps()[4]['risk_summary'] ?? [])->toContain('required');
});

it('blocks submission when a tier-required field is empty', function () {
    /*
     * THE TIER RULES REACH THE WIZARD THROUGH `Create::formRequest()`.
     *
     * That method passes the component's values into a fresh `ItRequestFormRequest`, because
     * the container-resolved one would validate the empty HTTP request instead. It listed
     * `business_plan_status` and `urgency` but NOT `proposed_tier_id` — so `value()` fell back
     * to `input()`, found nothing, and every tier rule silently evaluated as "no tier".
     *
     * The feature was written, the service resolved correctly, and no rule ever applied. This
     * test is the one that catches that: it walks the wizard to the last step and asserts the
     * tier's own requirement stops the submission.
     */
    setRule($this->tier2->id, 'budget_amount', TierFieldRule::REQUIRED);

    Livewire::test(Create::class)
        ->set('proposed_tier_id', $this->tier2->id)
        ->set('title', 'A request with a required budget')
        ->set('request_date', now()->toDateString())
        ->set('department_id', Department::first()->id)
        ->set('project_owner_id', User::factory()->create()->id)
        ->set('business_need', str_repeat('Need. ', 10))
        ->set('business_plan_status', 'adhoc')
        ->set('adhoc_justification', str_repeat('Because. ', 5))
        ->set('urgency', 'low')
        ->set('impact_if_not_implemented', str_repeat('Impact. ', 6))
        ->set('budget_amount', null)
        ->call('submit')
        ->assertHasErrors(['budget_amount']);
});

it('accepts the same submission once the tier requirements are met', function () {
    /*
     * The other half: the rules must block the empty case WITHOUT blocking a correct one, or
     * they are not requirements, they are a wall.
     *
     * THE AMOUNT MUST FALL IN TIER 2'S BAND. This used to submit RM15,000 against Tier 2 and
     * passed, because tiers had no bands. Now the band check refuses that pair — correctly, and
     * with a message naming Tier 1 — so the test has to use an amount the tier actually covers.
     * RM75,000 is inside "RM50,000.01 and above".
     */
    setRule($this->tier2->id, 'budget_amount', TierFieldRule::REQUIRED);

    Livewire::test(Create::class)
        ->set('proposed_tier_id', $this->tier2->id)
        ->set('title', 'A request with a supplied budget')
        ->set('request_date', now()->toDateString())
        ->set('department_id', Department::first()->id)
        ->set('project_owner_id', User::factory()->create()->id)
        ->set('business_need', str_repeat('Need. ', 10))
        ->set('business_plan_status', 'adhoc')
        ->set('adhoc_justification', str_repeat('Because. ', 5))
        ->set('urgency', 'low')
        ->set('impact_if_not_implemented', str_repeat('Impact. ', 6))
        ->set('budget_amount', '75000')
        ->set('budget_source', 'IT operating budget')
        ->set('forecast_resources', str_repeat('Two analysts. ', 3))
        ->call('submit')
        ->assertHasNoErrors();

    expect(ItRequest::where('title', 'A request with a supplied budget')->exists())->toBeTrue();
});

// ---- The administration screen ----------------------------------------------
//
// These act as an administrator: `mount()` refuses anybody else, which is correct, and a test
// that forgot would fail with `Livewire snapshot structure` rather than a readable 403.

it('shows a grid of every governed field for every tier', function () {
    $this->actingAs(asUser(UserRole::Administrator));

    Livewire::test(TierRules::class)
        ->assertOk()
        ->assertSee('budget_amount')
        ->assertSee('Every tier')
        ->assertSee($this->tier1->name);
});

it('saves a rule and applies it immediately', function () {
    $this->actingAs(asUser(UserRole::Administrator));

    Livewire::test(TierRules::class)
        ->set('edits.'.$this->tier2->id.':budget_amount', TierFieldRule::REQUIRED)
        ->call('save')
        ->assertHasNoErrors();

    expect(TierFieldRule::where('tier_id', $this->tier2->id)
        ->where('field', 'budget_amount')
        ->value('requirement'))->toBe(TierFieldRule::REQUIRED);
});

it('removes the row when a rule is set back to optional', function () {
    // Optional is the default, so storing it would leave the grid full of rows saying nothing.
    $this->actingAs(asUser(UserRole::Administrator));

    setRule($this->tier2->id, 'budget_amount', TierFieldRule::REQUIRED);

    Livewire::test(TierRules::class)
        ->set('edits.'.$this->tier2->id.':budget_amount', TierFieldRule::OPTIONAL)
        ->call('save')
        ->assertHasNoErrors();

    expect(TierFieldRule::where('tier_id', $this->tier2->id)
        ->where('field', 'budget_amount')
        ->exists())->toBeFalse();
});

it('refuses an invented requirement value', function () {
    /*
     * The three states are the whole vocabulary; anything else is a payload nobody should send.
     *
     * The error is keyed by the FULL property path — `edits.2:budget_amount` — not by the
     * wildcard `edits.*`, because Livewire expands the wildcard to the actual key. Asserting
     * `edits.` would pass only if Livewire happened to echo the wildcard, which is not what it
     * does.
     */
    $this->actingAs(asUser(UserRole::Administrator));

    Livewire::test(TierRules::class)
        ->set('edits.'.$this->tier2->id.':budget_amount', 'mandatory-ish')
        ->call('save')
        ->assertHasErrors(['edits.'.$this->tier2->id.':budget_amount']);

    // And nothing was written.
    expect(TierFieldRule::where('tier_id', $this->tier2->id)
        ->where('field', 'budget_amount')
        ->where('requirement', 'mandatory-ish')
        ->exists())->toBeFalse();
});

it('records a rule change in the audit trail', function () {
    /*
     * Who decided Tier 2 needs a budget, and when, is a governance question.
     *
     * The change is from HIDDEN to REQUIRED rather than to REQUIRED from the seeded state — the
     * component only writes an audit row when something actually changed, and re-saving a rule
     * that is already required is a no-op. Setting the same value and expecting a row would pass
     * only while the seeded position happened to differ.
     */
    $this->actingAs(asUser(UserRole::Administrator));

    Livewire::test(TierRules::class)
        ->set('edits.'.$this->tier2->id.':budget_amount', TierFieldRule::HIDDEN)
        ->call('save')
        ->assertHasNoErrors();

    $this->assertDatabaseHas('audit_logs', ['event' => 'settings.tier_field_rules_updated']);
});

it('refuses tier rule changes to a non-administrator', function () {
    $this->actingAs(asUser(UserRole::Requestor));

    Livewire::test(TierRules::class)->assertForbidden();
});

// ---- The seeder --------------------------------------------------------------

it('seeds a starting position that is visible rather than neutral', function () {
    /*
     * The brief does not say which field for which tier, so the seeder holds a defensible first
     * draft. Asserted so the draft is a decision somebody can review rather than an accident —
     * and so a change to it is deliberate.
     */
    $rules = app(TierFieldRules::class);

    expect($rules->requiredFor($this->tier2->id))->toHaveKey('budget_amount')
        ->and($rules->hiddenFor(Tier::where('code', 'tier_p')->value('id')))
        ->toContain('dependencies_constraints');
});

it('does not overwrite a rule an administrator changed', function () {
    /*
     * The seeder runs on every deploy. If it updated, it would silently revert an administrator's
     * change — and the change would reappear days later with nobody connecting it to a release.
     */
    setRule($this->tier2->id, 'budget_amount', TierFieldRule::OPTIONAL);

    $this->seed(ReferenceDataSeeder::class);

    expect(TierFieldRule::where('tier_id', $this->tier2->id)
        ->where('field', 'budget_amount')
        ->value('requirement'))->toBe(TierFieldRule::OPTIONAL);
});
