<?php

namespace App\Enums;

/**
 * The stages a request passes through.
 *
 * WHY THIS IS SEPARATE FROM RequestStatus
 *
 * They look similar and are not the same thing. A *stage* is a step in the
 * process; a *status* is where the request currently is. Two statuses can share a
 * stage — `ReturnedForAmendment` belongs to whichever stage returned it, and
 * `Approved` and `ApprovedWithConditions` are both terminal decisions reached at
 * different stages.
 *
 * Keeping them apart is what makes BR-007 possible: a returned request resumes at
 * the stage that returned it, which requires knowing the stage independently of
 * the status.
 */
enum WorkflowStage: string
{
    case Submission = 'submission';
    case ProjectOwner = 'project_owner';
    case ProjectSponsor = 'project_sponsor';
    case CompletenessReview = 'completeness_review';
    case TechnicalRecommendation = 'technical_recommendation';
    case Consolidation = 'consolidation';
    case CommitteeDecision = 'committee_decision';
    case Closure = 'closure';

    public function label(): string
    {
        return match ($this) {
            self::Submission => 'Submission',
            self::ProjectOwner => 'Project Owner',
            self::ProjectSponsor => 'Project Sponsor',
            self::CompletenessReview => 'Completeness Review',
            self::TechnicalRecommendation => 'Technical Recommendation',
            self::Consolidation => 'Consolidation',
            self::CommitteeDecision => 'Committee Decision',
            self::Closure => 'Closure',
        };
    }

    /**
     * Ordering for the status timeline.
     *
     * The timeline is a business view, so the order is fixed here rather than
     * derived from the database sort_order. A reordered seed should not silently
     * rearrange what a user sees.
     */
    public function order(): int
    {
        return match ($this) {
            self::Submission => 1,
            self::ProjectOwner => 2,
            self::ProjectSponsor => 3,
            self::CompletenessReview => 4,
            self::TechnicalRecommendation => 5,
            self::Consolidation => 6,
            self::CommitteeDecision => 7,
            self::Closure => 8,
        };
    }

    /**
     * Stages every tier passes through.
     *
     * Committee is absent deliberately: only the Full governance route reaches
     * it, which is the single routing rule that distinguishes the three routes.
     */
    public function isConditional(): bool
    {
        return $this === self::CommitteeDecision;
    }

    /** @return array<string, string> */
    public static function options(): array
    {
        return collect(self::cases())
            ->mapWithKeys(fn (self $c) => [$c->value => $c->label()])
            ->all();
    }
}
