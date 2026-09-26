<?php

namespace App\Services;

use App\Models\Tier;
use Illuminate\Support\Collection;

/**
 * Which tier a budget amount falls into, and whether a proposed tier agrees (BR-003).
 *
 * WHY THIS IS A SERVICE AND NOT A HELPER ON THE MODEL
 *
 * Three callers need the same answer and must not be able to disagree:
 *
 *   1. the form request, which refuses a contradicting pair
 *   2. the wizard, which shows the requestor what their amount implies
 *   3. the administration screen, which refuses overlapping bands
 *
 * A `firstWhere` on the collection in each of them would be three chances to differ, and the one
 * that differs is always the one nobody is looking at. Same reasoning as `TierFieldRules`.
 *
 * WHY MATCHING IS A LIST AND NOT A SINGLE RESULT
 *
 * A correct configuration has exactly one band containing any amount. An INCORRECT one can have
 * two, and the honest answer then is "two tiers claim this amount", not whichever row came back
 * first. Returning a list makes the overlap visible at the point it matters — in the form — as
 * well as on the screen that is meant to prevent it.
 *
 * The administration screen refuses overlapping bands, so this should never see one. It is
 * written to cope anyway, because "the screen prevents it" and "the data cannot contain it" are
 * different claims and only the second survives somebody using SQL.
 */
class TierBands
{
    /** @var array<int, array<string, mixed>>|null Per-request cache. */
    private ?array $rows = null;

    /**
     * Active tiers the requestor may choose.
     *
     * Excludes governance-assigned tiers, so Tier P never appears in the wizard's dropdown.
     *
     * @return Collection<int, Tier>
     */
    public function selectable(): Collection
    {
        return $this->all()
            ->filter(fn (Tier $t) => $t->is_active && $t->isRequestorAssignable())
            ->values();
    }

    /**
     * Every active tier, whatever decides it.
     *
     * @return Collection<int, Tier>
     */
    public function all(): Collection
    {
        if ($this->rows === null) {
            $this->rows = Tier::orderBy('sort_order')->orderBy('id')->get()->all();
        }

        return collect($this->rows);
    }

    /**
     * The budget-banded tiers a requestor chooses between.
     *
     * @return Collection<int, Tier>
     */
    public function banded(): Collection
    {
        return $this->all()
            ->filter(fn (Tier $t) => $t->is_active && $t->isRequestorAssignable() && $t->isBudgetBased())
            ->values();
    }

    /**
     * Every tier whose band contains this amount.
     *
     * @return array<int, Tier>
     */
    public function match(string|float|int|null $amount): array
    {
        if ($amount === null || $amount === '') {
            return [];
        }

        return $this->banded()
            ->filter(fn (Tier $t) => $t->containsAmount($amount))
            ->values()
            ->all();
    }

    /** The single tier an amount implies, or null when none does. */
    public function forAmount(string|float|int|null $amount): ?Tier
    {
        $matches = $this->match($amount);

        return count($matches) === 1 ? $matches[0] : null;
    }

    /**
     * Whether a proposed tier agrees with the amount.
     *
     * FOUR OUTCOMES, AND EACH HAS A DIFFERENT MESSAGE:
     *
     *   `ok`            — they agree, or the tier is governance-assigned and not this check's job
     *   `no_tier`       — the amount falls in no band: the bands have a gap, or are missing
     *   `overlap`       — the amount falls in more than one: the bands overlap
     *   `mismatch`      — exactly one band contains it, and the requestor chose a different tier
     *
     * Collapsing these into one boolean would produce "the tier does not match the budget" for a
     * configuration error, and send the requestor to correct a field that is already right.
     *
     * @return array{status: string, expected: Tier|null, matches: array<int, Tier>}
     */
    public function check(?int $tierId, string|float|int|null $amount): array
    {
        // No amount means nothing to check against. Whether an amount is REQUIRED is a separate
        // question, answered by the tier field rules.
        if ($amount === null || $amount === '') {
            return ['status' => 'ok', 'expected' => null, 'matches' => []];
        }

        $matches = $this->match($amount);

        if ($matches === []) {
            return ['status' => 'no_tier', 'expected' => null, 'matches' => []];
        }

        if (count($matches) > 1) {
            return ['status' => 'overlap', 'expected' => null, 'matches' => $matches];
        }

        $expected = $matches[0];

        // A governance-assigned tier is not contradicted by the budget: Tier P is chosen for a
        // reason the amount cannot express. Nothing to check when one is proposed.
        if ($tierId === null) {
            return ['status' => 'ok', 'expected' => $expected, 'matches' => $matches];
        }

        $proposed = $this->all()->firstWhere('id', $tierId);

        if ($proposed !== null && ! $proposed->isBudgetBased()) {
            return ['status' => 'ok', 'expected' => $expected, 'matches' => $matches];
        }

        return $tierId === $expected->id
            ? ['status' => 'ok', 'expected' => $expected, 'matches' => $matches]
            : ['status' => 'mismatch', 'expected' => $expected, 'matches' => $matches];
    }

    /**
     * Bands that overlap each other, as pairs. Empty when the configuration is sound.
     *
     * Used by the administration screen to refuse a save, and by `itrequest:deploy-check` to
     * report a configuration that arrived by another route.
     *
     * TWO BANDS OVERLAP WHEN EACH CONTAINS AN AMOUNT THE OTHER DOES — which is not the same as
     * their edges touching. [null, 50000] and [50001, null] do not overlap; [null, 50000] and
     * [50000, null] do, because RM50,000 satisfies both. That single shared value is exactly the
     * mistake a hand-typed threshold makes.
     *
     * @return array<int, array{0: Tier, 1: Tier}>
     */
    public function overlaps(): array
    {
        $tiers = $this->banded()->values();
        $found = [];

        for ($i = 0; $i < $tiers->count(); $i++) {
            for ($j = $i + 1; $j < $tiers->count(); $j++) {
                if (self::bandsIntersect($tiers[$i], $tiers[$j])) {
                    $found[] = [$tiers[$i], $tiers[$j]];
                }
            }
        }

        return $found;
    }

    /**
     * Which saved tiers a proposed band would collide with.
     *
     * WHY THIS IS STATIC AND TAKES A TIER THAT IS NOT SAVED
     *
     * The administration screen has to check a band BEFORE writing it, because refusing a save is
     * the only moment the person who made the mistake is looking at it. Checking afterwards would
     * mean an invalid band is briefly live, an audit row records it, and the refusal has to be a
     * second write that undoes the first.
     *
     * `$exceptId` is the row being edited, so a tier does not report a collision with itself.
     *
     * Exposed as a static so the screen and `overlaps()` cannot hold two versions of the same
     * comparison. They did, briefly, while this was being written — and two copies of an overlap
     * rule is precisely the drift this service exists to prevent.
     *
     * @return array<int, Tier>
     */
    public static function collisionsFor(Tier $candidate, ?int $exceptId = null): array
    {
        // A tier that is not budget-based, or that the requestor cannot choose, is not in the
        // set the amount is matched against — so it cannot collide with anything.
        if (! $candidate->isBudgetBased() || ! $candidate->isRequestorAssignable()) {
            return [];
        }

        return Tier::query()
            ->when($exceptId !== null, fn ($q) => $q->where('id', '!=', $exceptId))
            ->get()
            ->filter(fn (Tier $other) => $other->isBudgetBased()
                && $other->isRequestorAssignable()
                && self::bandsIntersect($candidate, $other))
            ->values()
            ->all();
    }

    /** Whether two bands share at least one amount. */
    public static function bandsIntersect(Tier $a, Tier $b): bool
    {
        /*
         * Each bound is compared only where both bands HAVE one.
         *
         * `null` means unbounded, so a null edge cannot exclude anything — treating it as 0 or as
         * infinity would be inventing a bound the business did not state, and `[null, null]` is
         * excluded earlier as not budget-based.
         *
         * The comparison is `bccomp` on two-decimal strings, so RM50,000 and RM50,000.00 are the
         * same number here as they are in `containsAmount`. A float comparison would make them
         * equal too, but `0.1 + 0.2` would not be 0.3, and this is a governance boundary.
         */
        $aMax = $a->budget_max === null ? null : (string) $a->budget_max;
        $aMin = $a->budget_min === null ? null : (string) $a->budget_min;
        $bMax = $b->budget_max === null ? null : (string) $b->budget_max;
        $bMin = $b->budget_min === null ? null : (string) $b->budget_min;

        // a starts above b's ceiling.
        if ($aMin !== null && $bMax !== null && bccomp($aMin, $bMax, 2) > 0) {
            return false;
        }

        // b starts above a's ceiling.
        if ($bMin !== null && $aMax !== null && bccomp($bMin, $aMax, 2) > 0) {
            return false;
        }

        return true;
    }

    /** Forget the per-request cache. Called after a save, and between tests. */
    public function forget(): void
    {
        $this->rows = null;
    }
}
