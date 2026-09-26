<?php

use App\Enums\UserRole;
use App\Livewire\Admin\ReferenceData;
use App\Livewire\Requests\Create;
use App\Models\Department;
use App\Models\Tier;
use App\Services\TierBands;
use App\Services\TierFieldRules;
use Database\Seeders\ReferenceDataSeeder;
use Livewire\Livewire;

/**
 * Which tier a budget amount falls into, and whether a proposed tier agrees.
 *
 * THE BOUNDARY IS THE WHOLE POINT OF THIS FILE.
 *
 * The organisation's rule is "Tier 1 up to RM50,000; anything above it, Tier 2". The interesting
 * value is RM50,000 itself, and the interesting bug is an off-by-one that makes it fall into both
 * bands — "Tier 1 or Tier 2?" having two answers is worse than either answer being wrong.
 *
 * These tests exist because the seeded SQL was verified by hand once and the boundary is the kind
 * of thing a later edit changes by one character. Everything here asserts an amount rather than a
 * configuration, so a changed threshold fails loudly.
 */
beforeEach(function () {
    $this->seed(ReferenceDataSeeder::class);

    Department::create(['code' => 'ICT', 'name' => 'Information Technology']);

    $this->requestor = asUser(UserRole::Requestor);
    $this->actingAs($this->requestor);

    $this->tier1 = Tier::where('code', 'tier_1')->firstOrFail();
    $this->tier2 = Tier::where('code', 'tier_2')->firstOrFail();
    $this->tierP = Tier::where('code', 'tier_p')->firstOrFail();

    app(TierBands::class)->forget();
});

// ---- The boundary -----------------------------------------------------------

it('puts RM50,000 and below in Tier 1', function () {
    $bands = app(TierBands::class);

    foreach (['0', '0.01', '1000', '49999.99', '50000', '50000.00'] as $amount) {
        expect($bands->forAmount($amount)?->code)->toBe('tier_1', "RM{$amount} should be Tier 1");
    }
});

it('puts anything above RM50,000 in Tier 2', function () {
    $bands = app(TierBands::class);

    // RM50,000.01 is the first amount above the line, and the one an off-by-one would miss.
    foreach (['50000.01', '50001', '100000', '2500000'] as $amount) {
        expect($bands->forAmount($amount)?->code)->toBe('tier_2', "RM{$amount} should be Tier 2");
    }
});

it('places the boundary amount in exactly one tier', function () {
    /*
     * The assertion the seeded SQL was eyeballed for. Both bounds are inclusive, so a second band
     * starting at 50,000 rather than 50,001 would put RM50,000 in two tiers — and the failure
     * would show up as a requestor being told "a budget of RM50,000 falls into more than one
     * tier", which describes a configuration fault on somebody else's form.
     */
    $matches = app(TierBands::class)->match('50000.00');

    expect($matches)->toHaveCount(1)
        ->and($matches[0]->code)->toBe('tier_1');

    // And the next cent belongs to the other one, so there is no gap either.
    expect(app(TierBands::class)->match('50000.01'))->toHaveCount(1);
});

it('reports no overlap in the seeded configuration', function () {
    expect(app(TierBands::class)->overlaps())->toBe([]);
});

it('has no amount that falls into no tier', function () {
    // A gap would refuse a legitimate request with "no tier covers this budget".
    $bands = app(TierBands::class);

    foreach (['0', '1', '25000', '50000', '50000.01', '75000', '999999'] as $amount) {
        expect($bands->forAmount($amount))->not->toBeNull("RM{$amount} falls into no tier");
    }
});

// ---- Tier P, which is not a band --------------------------------------------

it('does not match a partnership tier by budget', function () {
    /*
     * Tier P is not a cost band — a RM20,000 collaboration and a RM2,000,000 one are both Tier P.
     * If it were modelled as an open-ended band it would match every amount, and the overlap check
     * would become a permanent warning nobody reads.
     */
    expect($this->tierP->isBudgetBased())->toBeFalse()
        ->and(app(TierBands::class)->forAmount('20000')?->code)->not->toBe('tier_p')
        ->and(app(TierBands::class)->forAmount('2000000')?->code)->not->toBe('tier_p');
});

it('keeps the partnership tier out of the requestor dropdown', function () {
    // It is chosen by governance for a reason the amount cannot express.
    $selectable = app(TierBands::class)->selectable();

    expect($selectable->pluck('code')->all())->toBe(['tier_1', 'tier_2']);
});

it('does not contradict a partnership tier with the amount', function () {
    /*
     * A requestor cannot propose Tier P, but governance does — and a RM30,000 partnership must
     * not be refused for disagreeing with the Tier 1 band.
     */
    $check = app(TierBands::class)->check($this->tierP->id, '30000');

    expect($check['status'])->toBe('ok');
});

// ---- The four outcomes ------------------------------------------------------

it('accepts a tier that agrees with the amount', function () {
    $check = app(TierBands::class)->check($this->tier2->id, '75000');

    expect($check['status'])->toBe('ok')
        ->and($check['expected']?->code)->toBe('tier_2');
});

it('reports a mismatch when the tier disagrees with the amount', function () {
    // The bug this exists for: Tier 2 chosen for RM20,000, which is a Tier 1 amount.
    $check = app(TierBands::class)->check($this->tier2->id, '20000');

    expect($check['status'])->toBe('mismatch')
        ->and($check['expected']?->code)->toBe('tier_1');
});

it('says nothing when no amount is given', function () {
    // Whether an amount is REQUIRED is a separate question, answered by the tier field rules.
    expect(app(TierBands::class)->check($this->tier1->id, null)['status'])->toBe('ok')
        ->and(app(TierBands::class)->check($this->tier1->id, '')['status'])->toBe('ok');
});

it('distinguishes a gap from a mismatch', function () {
    /*
     * Collapsing these would produce "the tier does not match the budget" for a configuration
     * fault, sending the requestor to correct a field that is already right.
     *
     * A gap is manufactured here by widening Tier 1's ceiling past Tier 2's floor while leaving
     * Tier 2 alone — which is what a mistyped threshold looks like in practice.
     */
    $this->tier1->forceFill(['budget_max' => '60000.00'])->save();
    $this->tier2->forceFill(['budget_min' => '70000.00'])->save();
    app(TierBands::class)->forget();

    expect(app(TierBands::class)->check($this->tier1->id, '65000')['status'])->toBe('no_tier');
});

it('distinguishes an overlap from a mismatch', function () {
    // Both bands claiming RM50,000, which is the off-by-one this whole area is about.
    $this->tier2->forceFill(['budget_min' => '50000.00'])->save();
    app(TierBands::class)->forget();

    $check = app(TierBands::class)->check($this->tier1->id, '50000');

    expect($check['status'])->toBe('overlap')
        ->and($check['matches'])->toHaveCount(2);
});

// ---- Overlap detection ------------------------------------------------------

it('detects two bands that share an amount', function () {
    $this->tier2->forceFill(['budget_min' => '50000.00'])->save();
    app(TierBands::class)->forget();

    $overlaps = app(TierBands::class)->overlaps();

    expect($overlaps)->toHaveCount(1)
        ->and($overlaps[0][0]->code)->toBe('tier_1')
        ->and($overlaps[0][1]->code)->toBe('tier_2');
});

it('does not call touching bands an overlap when they do not share a value', function () {
    // [0, 50000] and [50001, null] touch without sharing an amount. Without this distinction the
    // seeded configuration would report an overlap, and a check that always fires is not a check.
    expect(TierBands::bandsIntersect($this->tier1, $this->tier2))->toBeFalse();
});

it('treats an unbounded top band as reaching every amount above its floor', function () {
    // Tier 2 has no ceiling. Treating null as 0 would make it cover nothing.
    expect($this->tier2->budget_max)->toBeNull()
        ->and($this->tier2->containsAmount('999999999'))->toBeTrue();
});

// ---- The administration screen ----------------------------------------------

it('refuses to save a band that overlaps another tier', function () {
    /*
     * Refused rather than warned. A warning would let an invalid band go live, and the first
     * symptom would be a message on somebody else's request form.
     */
    $this->actingAs(asUser(UserRole::Administrator));

    Livewire::test(ReferenceData::class)
        ->set("edits.tier:{$this->tier2->id}.budget_min", '50000.00')
        ->call('save', 'tier', $this->tier2->id)
        ->assertHasErrors("edits.tier:{$this->tier2->id}.budget_min");

    expect($this->tier2->fresh()->budget_min)->toBe('50000.01');
});

it('refuses a band whose ceiling is below its floor', function () {
    $this->actingAs(asUser(UserRole::Administrator));

    Livewire::test(ReferenceData::class)
        ->set("edits.tier:{$this->tier1->id}.budget_min", '60000')
        ->set("edits.tier:{$this->tier1->id}.budget_max", '10000')
        ->call('save', 'tier', $this->tier1->id)
        ->assertHasErrors("edits.tier:{$this->tier1->id}.budget_max");
});

it('saves a valid threshold change and applies it immediately', function () {
    /*
     * The reason the thresholds are columns rather than code: governance policy changes at short
     * notice, and it should be a screen rather than a release.
     *
     * MOVING A BOUNDARY TAKES TWO SAVES IN A PARTICULAR ORDER, and the order is not a preference.
     * Widening Tier 1 first would make it overlap Tier 2's floor, so the save is refused. The
     * upper tier is narrowed FIRST — its floor moves above the new boundary — and the lower tier
     * is widened second. The overlap check names this in its message, because the screen
     * otherwise refuses a correct intention with no indication of how to carry it out.
     */
    $this->actingAs(asUser(UserRole::Administrator));

    // 1. Narrow the upper tier: its floor moves to the new boundary plus one cent.
    Livewire::test(ReferenceData::class)
        ->set("edits.tier:{$this->tier2->id}.budget_min", '100000.01')
        ->call('save', 'tier', $this->tier2->id)
        ->assertHasNoErrors();

    // 2. Widen the lower tier up to the new boundary.
    Livewire::test(ReferenceData::class)
        ->set("edits.tier:{$this->tier1->id}.budget_max", '100000.00')
        ->call('save', 'tier', $this->tier1->id)
        ->assertHasNoErrors();

    expect(app(TierBands::class)->forAmount('80000')?->code)->toBe('tier_1')
        ->and(app(TierBands::class)->forAmount('100000')?->code)->toBe('tier_1')
        ->and(app(TierBands::class)->forAmount('120000')?->code)->toBe('tier_2');
});

it('refuses the first half of a boundary move, and says which order works', function () {
    /*
     * The trap this check creates, and the reason the message has to carry a procedure.
     *
     * Widening Tier 1's ceiling without narrowing Tier 2's floor first makes both bands contain
     * the new boundary. The refusal is CORRECT — there is no single save that avoids the overlap —
     * but "this band overlaps Tier 2" leaves the administrator stuck, because the edit they are
     * trying to make is the right one.
     *
     * So the message is asserted here: it names the shared amount and the order to save in. A
     * refusal that does not say how to proceed is indistinguishable from the screen being broken.
     */
    $this->actingAs(asUser(UserRole::Administrator));

    $component = Livewire::test(ReferenceData::class)
        ->set("edits.tier:{$this->tier1->id}.budget_max", '100000.00')
        ->call('save', 'tier', $this->tier1->id)
        ->assertHasErrors("edits.tier:{$this->tier1->id}.budget_min");

    $message = $component->errors()->first("edits.tier:{$this->tier1->id}.budget_min");

    expect($message)
        ->toContain('RM50,000.01')          // the amount that would be in both
        ->toContain('Tier 2')               // the tier it collides with
        ->toContain('save the HIGHER tier first')
        ->toContain('RM50,000 and RM50,001');

    // And nothing was written.
    expect($this->tier1->fresh()->budget_max)->toBe('50000.00');
});

it('treats a cleared bound as unbounded rather than zero', function () {
    /*
     * "RM50,001 and above" has no ceiling. Recording one as 0 would describe a band covering
     * nothing, and saving it back must not invent a limit.
     *
     * Clearing the ceiling makes this tier cover everything above its floor, which overlaps the
     * next tier — so this asserts the UNBOUNDED behaviour through a tier that has no follower,
     * and the overlap refusal is asserted separately above.
     */
    $this->actingAs(asUser(UserRole::Administrator));

    Livewire::test(ReferenceData::class)
        ->set("edits.tier:{$this->tier2->id}.budget_max", '')
        ->call('save', 'tier', $this->tier2->id)
        ->assertHasNoErrors();

    expect($this->tier2->fresh()->budget_max)->toBeNull();
});

it('records a threshold change in the audit trail', function () {
    // Who moved the boundary, and when, is a governance question — the same reason the routing
    // rule is audited. The upper tier is narrowed, which needs no second save.
    $this->actingAs(asUser(UserRole::Administrator));

    Livewire::test(ReferenceData::class)
        ->set("edits.tier:{$this->tier2->id}.budget_min", '120000.01')
        ->call('save', 'tier', $this->tier2->id)
        ->assertHasNoErrors();

    $this->assertDatabaseHas('audit_logs', ['event' => 'reference_data.updated']);
});

// ---- The wizard -------------------------------------------------------------

it('shows only the tiers a requestor may choose', function () {
    Livewire::test(Create::class)
        ->assertSeeHtml('Tier 1')
        ->assertSeeHtml('Tier 2')
        ->assertDontSeeHtml('Tier P (Partnership)');
});

it('refuses a submission whose tier contradicts the amount', function () {
    /*
     * The end-to-end consequence. Tier 1 chosen with RM80,000 must not be accepted — otherwise
     * the band is decoration and the tier is whatever somebody typed.
     */
    Livewire::test(Create::class)
        ->set('proposed_tier_id', $this->tier1->id)
        ->set('title', 'A request claiming the wrong tier')
        ->set('request_date', now()->toDateString())
        ->set('department_id', Department::first()->id)
        ->set('project_owner_id', asUser(UserRole::ProjectOwner)->id)
        ->set('business_need', str_repeat('Need. ', 10))
        ->set('business_plan_status', 'adhoc')
        ->set('adhoc_justification', str_repeat('Because. ', 5))
        ->set('urgency', 'low')
        ->set('impact_if_not_implemented', str_repeat('Impact. ', 6))
        ->set('budget_amount', '80000')
        ->call('submit')
        ->assertHasErrors(['budget_amount']);
});

it('names the amount the requestor actually typed', function () {
    /*
     * THE MESSAGE IS PART OF THE BEHAVIOUR HERE, which is why it is asserted rather than the
     * error key alone.
     *
     * The rule reads the amount from the closure's own `$value`. An earlier version re-read it
     * through `value('budget_amount')` — which is not in the `withData()` array the wizard
     * builds — so it fell back to `input()`, found nothing, and formatted an empty string.
     *
     * The result was a refusal reading "A budget of RM0.00 is Tier 2 (RM50,000.01 and above)"
     * beside a field plainly showing 80000. A message that contradicts the screen reads as the
     * application being broken, and sends the requestor to re-type a number that was never the
     * problem. Asserting the key alone would have passed.
     */
    $component = Livewire::test(Create::class)
        ->set('proposed_tier_id', $this->tier1->id)
        ->set('title', 'A request claiming the wrong tier')
        ->set('request_date', now()->toDateString())
        ->set('department_id', Department::first()->id)
        ->set('project_owner_id', asUser(UserRole::ProjectOwner)->id)
        ->set('business_need', str_repeat('Need. ', 10))
        ->set('business_plan_status', 'adhoc')
        ->set('adhoc_justification', str_repeat('Because. ', 5))
        ->set('urgency', 'low')
        ->set('impact_if_not_implemented', str_repeat('Impact. ', 6))
        ->set('budget_amount', '80000')
        ->call('submit');

    $message = $component->errors()->first('budget_amount');

    expect($message)
        ->toContain('RM80,000.00')          // the amount typed, not RM0.00
        ->toContain('Tier 2')               // and the tier it actually belongs to
        ->not->toContain('RM0.00');
});

it('accepts a submission whose tier agrees with the amount', function () {
    // The other half: the check must not block a correct request.
    Livewire::test(Create::class)
        ->set('proposed_tier_id', $this->tier1->id)
        ->set('title', 'A request claiming the right tier')
        ->set('request_date', now()->toDateString())
        ->set('department_id', Department::first()->id)
        ->set('project_owner_id', asUser(UserRole::ProjectOwner)->id)
        ->set('business_need', str_repeat('Need. ', 10))
        ->set('business_plan_status', 'adhoc')
        ->set('adhoc_justification', str_repeat('Because. ', 5))
        ->set('urgency', 'low')
        ->set('impact_if_not_implemented', str_repeat('Impact. ', 6))
        ->set('budget_amount', '45000')
        ->call('submit')
        ->assertHasNoErrors();
});

it('requires a budget, because the band cannot be checked without one', function () {
    /*
     * This reverses the earlier position, and the reversal is the interesting part.
     *
     * Tier 1 used to accept no amount, on the grounds that a small request should not need a cost
     * code to be filed. Once the amount DECIDES the tier, an absent amount leaves no valid tier —
     * so allowing it would produce a form that cannot be completed, which is worse than one that
     * plainly asks for a number.
     */
    expect(app(TierFieldRules::class)->requiredFor($this->tier1->id))
        ->toHaveKey('budget_amount');
});
