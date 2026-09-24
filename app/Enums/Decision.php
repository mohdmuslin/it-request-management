<?php

namespace App\Enums;

/**
 * Decision outcomes.
 *
 * WHY APPROVE, RETURN AND REJECT ARE DISTINCT
 *
 * They look like three flavours of "no" and they are not:
 *
 *  - Approve advances the request.
 *  - Return sends it back to the requestor for correction. The request is still
 *    alive and the decision is deferred. BR-002 requires a comment, because the
 *    requestor has to know what to fix.
 *  - Reject ends it. The request is not coming back, so the comment is the only
 *    record of why.
 *
 * Collapsing Return into Reject would mean every correction required a fresh
 * request, and collapsing Reject into Return would leave nowhere to record a
 * terminal decision.
 */
enum Decision: string
{
    case Approved = 'approved';
    case ApprovedWithConditions = 'approved_with_conditions';
    case Returned = 'returned';
    case Rejected = 'rejected';

    public function label(): string
    {
        return match ($this) {
            self::Approved => 'Approved',
            self::ApprovedWithConditions => 'Approved with conditions',
            self::Returned => 'Returned for amendment',
            self::Rejected => 'Not recommended',
        };
    }

    /**
     * Whether a comment is mandatory.
     *
     * BR-002. A rejection without a reason leaves the requestor with nothing to
     * act on, and a return without a reason is unactionable by definition.
     */
    public function requiresComment(): bool
    {
        return in_array($this, [self::Returned, self::Rejected], true);
    }

    /**
     * Whether conditions must be recorded.
     *
     * An approval "with conditions" and no conditions is a contradiction — the
     * condition would exist only in someone's memory.
     */
    public function requiresConditions(): bool
    {
        return $this === self::ApprovedWithConditions;
    }

    /** Whether this decision ends the request. */
    public function isTerminal(): bool
    {
        return in_array($this, [self::Approved, self::ApprovedWithConditions, self::Rejected], true);
    }

    /**
     * The status a request takes after this decision at an approval stage.
     *
     * Returns null where the next status depends on context — a decision at
     * consolidation depends on the governance route, so the caller decides.
     */
    public function resultingApprovalStatus(): ?RequestStatus
    {
        return match ($this) {
            self::Approved => RequestStatus::PendingProjectSponsor,
            self::ApprovedWithConditions => RequestStatus::ApprovedWithConditions,
            self::Returned => RequestStatus::ReturnedForAmendment,
            self::Rejected => RequestStatus::NotRecommended,
        };
    }

    /**
     * The outcome recorded when the request finally closes.
     *
     * Returns null for Returned, which does not close anything.
     */
    public function closureOutcome(): ?string
    {
        return match ($this) {
            self::Approved => 'approved',
            self::ApprovedWithConditions => 'approved_with_conditions',
            self::Rejected => 'not_recommended',
            self::Returned => null,
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
