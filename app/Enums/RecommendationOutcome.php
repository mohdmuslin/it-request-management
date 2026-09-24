<?php

namespace App\Enums;

/**
 * A reviewing unit's position on a request.
 *
 * WHY THIS ENUM DID NOT EXIST UNTIL NOW
 *
 * The `recommendations.recommendation` column existed from the first migration but
 * was a bare string with no vocabulary defined. Any value would have been accepted,
 * so three units could have filed "recommended", "Recommended", "RECOMMEND" and
 * "yes" and the consolidation screen would have had to treat them as four different
 * positions — which is how a free-text recommendation column produces the reporting
 * failure the design was written to avoid.
 *
 * WHY THESE FOUR AND NOT "APPROVE / REJECT"
 *
 * A unit's recommendation is advisory and reaches the committee through the
 * consolidation. The three positions below map onto what the committee can actually
 * do with them, and they are deliberately not the same words as `Decision`:
 * conflating "the unit recommends approval" with "the approver approved" would make
 * the trail ambiguous about which of the two had happened.
 *
 * `NotRecommended` is not a rejection. It ends nothing — it tells the HOU and the
 * committee that a technical unit advises against, and the request continues.
 */
enum RecommendationOutcome: string
{
    case Recommended = 'recommended';
    case RecommendedWithConditions = 'recommended_with_conditions';
    case NotRecommended = 'not_recommended';
    case NoObjection = 'no_objection';

    public function label(): string
    {
        return match ($this) {
            self::Recommended => 'Recommended',
            self::RecommendedWithConditions => 'Recommended with conditions',
            self::NotRecommended => 'Not recommended',
            self::NoObjection => 'No objection',
        };
    }

    /**
     * Whether the unit has conditions to record.
     *
     * Mirrors `Decision::requiresConditions()`, and for the same reason: a
     * recommendation "with conditions" and no conditions means the conditions exist
     * only in the reviewer's memory, and the committee cannot weigh them.
     */
    public function requiresConditions(): bool
    {
        return $this === self::RecommendedWithConditions;
    }

    /**
     * Whether a reason is required.
     *
     * A unit advising against a request must say why — the HOU consolidates from
     * these, and "not recommended" with no reason gives the committee nothing to
     * balance against the requesting department's case.
     */
    public function requiresReason(): bool
    {
        return $this === self::NotRecommended;
    }

    /**
     * Whether this position is a concern the HOU should weigh.
     *
     * Used to flag a consolidation that proposes a light route while a unit has
     * advised against — not to block it, because the HOU may have context the unit
     * lacks, but to make the disagreement visible rather than silent.
     */
    public function isConcern(): bool
    {
        return in_array($this, [self::NotRecommended], true);
    }

    /** @return array<string, string> */
    public static function options(): array
    {
        return collect(self::cases())
            ->mapWithKeys(fn (self $c) => [$c->value => $c->label()])
            ->all();
    }
}
