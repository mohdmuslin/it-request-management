<?php

namespace App\Enums;

/**
 * Whether a request cites an approved business plan.
 *
 * This drives the one genuinely conditional field group in the form, and is the
 * concrete instance of BR-003 — "conditional documents and fields shall be driven
 * by tier and classification".
 *
 * The rule it implements:
 *   aligned to a plan  -> a business plan REFERENCE is required
 *   not aligned        -> an AD-HOC JUSTIFICATION is required
 *
 * WHY THE AD-HOC BRANCH EXISTS AT ALL
 *
 * If only the aligned case were allowed, genuine urgent requests would be forced
 * to cite a plan that does not cover them — which is how a form teaches people to
 * enter something untrue. The ad-hoc branch captures the real justification and
 * makes it visible to the approver.
 */
enum BusinessPlanStatus: string
{
    case Aligned = 'aligned';
    case AdHoc = 'adhoc';

    public function label(): string
    {
        return match ($this) {
            self::Aligned => 'Aligned to an approved business plan',
            self::AdHoc => 'Ad-hoc — no plan covers this',
        };
    }

    /** The field that becomes mandatory when this option is chosen. */
    public function requiredField(): string
    {
        return match ($this) {
            self::Aligned => 'business_plan_reference',
            self::AdHoc => 'adhoc_justification',
        };
    }

    /** @return array<string, string> */
    public static function options(): array
    {
        return collect(self::cases())
            ->mapWithKeys(fn (self $c) => [$c->value => $c->label()])
            ->all();
    }
}
