<?php

namespace App\Enums;

/**
 * The nine roles.
 *
 * WHY NINE AND NOT THE USUAL THREE
 *
 * This is IT investment governance, not a helpdesk. The process flow names eight
 * distinct actors, and each has a different relationship to a request:
 *
 *  - A *Requestor* raises it.
 *  - A *Project Owner* and *Project Sponsor* are named on it, and approve in that
 *    order. They are per-request roles, not org-chart lookups — a named approver
 *    is accountable, whereas a role queue lets a request sit unowned.
 *  - An *IT Governance Reviewer* assesses it and assigns tier and classification.
 *  - A *Technical Reviewer* files a unit recommendation.
 *  - The *IT HOU* consolidates and determines the governance route.
 *  - A *Committee Secretariat* records the committee decision.
 *  - An *Administrator* manages the system.
 *  - An *Auditor* sees everything and changes nothing.
 *
 * WHY THIS IS NOT THE SAME AS review_units
 *
 * A role is what a person may do. A review unit is where they sit — IT
 * Operations, IT Platforms, IT Delivery & Governance. A Technical Reviewer holds
 * the role AND belongs to a unit, because the same role behaves differently
 * depending on the unit. Conflating them would make "which units must review
 * this?" unanswerable.
 */
enum UserRole: string
{
    case Requestor = 'requestor';
    case ProjectOwner = 'project_owner';
    case ProjectSponsor = 'project_sponsor';
    case GovernanceReviewer = 'governance_reviewer';
    case TechnicalReviewer = 'technical_reviewer';
    case Hou = 'hou';
    case CommitteeSecretariat = 'committee_secretariat';
    case Administrator = 'administrator';
    case Auditor = 'auditor';

    public function label(): string
    {
        return match ($this) {
            self::Requestor => 'Requestor',
            self::ProjectOwner => 'Project Owner',
            self::ProjectSponsor => 'Project Sponsor',
            self::GovernanceReviewer => 'IT Governance Reviewer',
            self::TechnicalReviewer => 'Technical Reviewer',
            self::Hou => 'IT HOU / Consolidator',
            self::CommitteeSecretariat => 'Committee Secretariat',
            self::Administrator => 'Administrator',
            self::Auditor => 'Auditor (read-only)',
        };
    }

    /**
     * The landing screen for this role.
     *
     * Each role starts where its work is, rather than everyone landing on a
     * dashboard of things they cannot act on.
     */
    public function landingRoute(): string
    {
        return match ($this) {
            self::Requestor => 'dashboard',
            self::ProjectOwner,
            self::ProjectSponsor => 'approvals',
            self::GovernanceReviewer => 'governance',
            self::TechnicalReviewer => 'recommendations',
            self::Hou => 'governance',
            self::CommitteeSecretariat => 'committee',
            self::Administrator => 'dashboard',
            self::Auditor => 'audit',
        };
    }

    /**
     * An auditor may read everything and change nothing.
     *
     * Checked by the policies, not by the navigation. Hiding a menu item is
     * presentation; refusing the write is the control.
     */
    public function isReadOnly(): bool
    {
        return $this === self::Auditor;
    }

    /**
     * Whether this role exists on the org chart as a manager of others.
     *
     * Used only for reporting and escalation targets — never for routing. Routing
     * uses the Owner and Sponsor named on the request.
     */
    public function isManagerial(): bool
    {
        return in_array($this, [
            self::ProjectOwner,
            self::ProjectSponsor,
            self::Hou,
            self::Administrator,
        ], true);
    }

    /** @return array<string, string> */
    public static function options(): array
    {
        return collect(self::cases())
            ->mapWithKeys(fn (self $c) => [$c->value => $c->label()])
            ->all();
    }
}
