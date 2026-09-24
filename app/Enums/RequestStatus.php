<?php

namespace App\Enums;

/**
 * The fourteen workflow states.
 *
 * WHY AN ENUM AND NOT A STATUS STRING
 *
 * The brief requires controlled transitions rather than free-text status updates.
 * A string column lets anyone set any value from anywhere, and the record stops
 * meaning anything. An enum makes an invalid state unrepresentable, so the
 * question "is this status valid?" never has to be asked at runtime.
 *
 * The `value` of each case is the database value and must not change once data
 * exists — it is referenced by `it_requests.status` and `approval_tasks.stage`.
 */
enum RequestStatus: string
{
    case Draft = 'draft';
    case Submitted = 'submitted';
    case PendingProjectOwner = 'pending_project_owner';
    case PendingProjectSponsor = 'pending_project_sponsor';
    case PendingCompletenessReview = 'pending_completeness_review';
    case PendingTechnicalRecommendation = 'pending_technical_recommendation';
    case PendingConsolidation = 'pending_consolidation';
    case PendingCommitteeDecision = 'pending_committee_decision';
    case Approved = 'approved';
    case ApprovedWithConditions = 'approved_with_conditions';
    case NotRecommended = 'not_recommended';
    case ReturnedForAmendment = 'returned_for_amendment';
    case Withdrawn = 'withdrawn';
    case Closed = 'closed';

    /**
     * Human-readable label, used in the UI, in exports and in notifications.
     *
     * One source for all three. Three separate label maps drift, and then the
     * same request reads differently depending on where you look at it.
     */
    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::Submitted => 'Submitted',
            self::PendingProjectOwner => 'Pending Project Owner',
            self::PendingProjectSponsor => 'Pending Project Sponsor',
            self::PendingCompletenessReview => 'Pending Completeness Review',
            self::PendingTechnicalRecommendation => 'Pending Technical Recommendation',
            self::PendingConsolidation => 'Pending Consolidation',
            self::PendingCommitteeDecision => 'Pending Committee Decision',
            self::Approved => 'Approved',
            self::ApprovedWithConditions => 'Approved with Conditions',
            self::NotRecommended => 'Not Recommended',
            self::ReturnedForAmendment => 'Returned for Amendment',
            self::Withdrawn => 'Withdrawn',
            self::Closed => 'Closed',
        };
    }

    /**
     * Badge styling key, so status colouring is consistent everywhere.
     *
     * Returned as a semantic name rather than a Tailwind class: the UI decides
     * how "amber" looks, and a redesign does not mean editing this enum.
     */
    public function tone(): string
    {
        return match ($this) {
            self::Draft => 'neutral-outline',
            self::Submitted => 'info',
            self::PendingProjectOwner,
            self::PendingProjectSponsor => 'info',
            self::PendingCompletenessReview,
            self::PendingTechnicalRecommendation,
            self::PendingConsolidation => 'governance',
            self::PendingCommitteeDecision => 'committee',
            self::Approved => 'positive',
            self::ApprovedWithConditions => 'positive-conditional',
            self::NotRecommended => 'negative',
            self::ReturnedForAmendment => 'attention',
            self::Withdrawn => 'neutral',
            self::Closed => 'neutral-filled',
        };
    }

    /** A request in one of these states can still be changed. */
    public function isOpen(): bool
    {
        return ! in_array($this, [
            self::Closed,
            self::Withdrawn,
            self::NotRecommended,
        ], true);
    }

    /** A decision has been reached; only closure remains. */
    public function isDecided(): bool
    {
        return in_array($this, [
            self::Approved,
            self::ApprovedWithConditions,
            self::NotRecommended,
        ], true);
    }

    /** The requestor owns the next action. */
    public function awaitsRequestor(): bool
    {
        return in_array($this, [
            self::Draft,
            self::ReturnedForAmendment,
        ], true);
    }

    /**
     * The stage that owns the next action.
     *
     * Used to create the approval task when a request enters a state. Returns
     * null when no human action is pending — a decided, withdrawn or closed
     * request has nobody to assign.
     */
    public function nextStage(): ?WorkflowStage
    {
        return match ($this) {
            self::PendingProjectOwner => WorkflowStage::ProjectOwner,
            self::PendingProjectSponsor => WorkflowStage::ProjectSponsor,
            self::PendingCompletenessReview => WorkflowStage::CompletenessReview,
            self::PendingTechnicalRecommendation => WorkflowStage::TechnicalRecommendation,
            self::PendingConsolidation => WorkflowStage::Consolidation,
            self::PendingCommitteeDecision => WorkflowStage::CommitteeDecision,
            default => null,
        };
    }

    /** @return array<string, string> value => label, for select inputs. */
    public static function options(): array
    {
        return collect(self::cases())
            ->mapWithKeys(fn (self $c) => [$c->value => $c->label()])
            ->all();
    }
}
