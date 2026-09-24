<?php

namespace App\Livewire;

use App\Enums\RequestStatus;
use App\Enums\UserRole;
use App\Enums\WorkflowStage;
use App\Models\ApprovalTask;
use App\Models\ItRequest;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * The landing screen.
 *
 * WHY THIS IS ROLE-AWARE RATHER THAN ONE DASHBOARD FOR EVERYONE
 *
 * A dashboard that shows the same twelve numbers to everybody means nobody reads
 * it. An IT HOU opening the system wants to know what is waiting to be
 * consolidated; a requestor wants to know whether anything needs correcting.
 * Those are different questions and they get different answers here.
 *
 * The cards are also small on purpose. A dashboard whose value is a count nobody
 * acts on is decoration; each of these links to the work.
 */
#[Layout('components.layouts.app')]
class Dashboard extends Component
{
    public function render(): View
    {
        $user = auth()->user();

        return view('livewire.dashboard', [
            'cards' => $this->cardsFor($user),
            'needsAttention' => $this->needsAttention($user),
            'recentActivity' => collect(),
        ]);
    }

    /**
     * Summary cards, tailored to what this user can act on.
     *
     * @return array<int, array{label: string, value: int|string, note: string|null, route: string|null}>
     */
    private function cardsFor($user): array
    {
        $visible = ItRequest::visibleTo($user);

        /*
         * An administrator and an auditor get oversight numbers rather than a
         * personal work queue — they are not waiting on approvals or reviews.
         *
         * THE ADMINISTRATOR CASE WAS MISSING AT FIRST, and it failed in a
         * misleading way: no branch matched, so `$cards` stayed empty and the view
         * rendered its "nothing is assigned to you yet — your account has no role"
         * empty state. The most privileged user in the system was told they had no
         * role. An empty collection is not the same as an empty role, and a
         * catch-all branch is what keeps those two apart.
         */
        if ($user->isAdministrator() || $user->hasRole(UserRole::Auditor)) {
            $overdue = ApprovalTask::overdue()->whereHas('request', fn ($q) => $q->open())->count();

            return [
                $this->card('Requests total', (clone $visible)->count(), null, null),
                $this->card('Open', (clone $visible)->open()->count(), 'Not yet closed', null),
                $this->card('Awaiting a decision',
                    ApprovalTask::pending()->whereHas('request', fn ($q) => $q->open())->count(),
                    null, null),
                $this->card('Overdue tasks', $overdue,
                    $overdue > 0 ? 'Needs attention' : 'Nothing overdue', null),
            ];
        }

        $cards = [];

        if ($user->hasRole(UserRole::Requestor) || $user->requestsMade()->exists()) {
            $mine = fn () => ItRequest::where('requestor_id', $user->id);

            $cards[] = $this->card('My open requests', $mine()->open()->count(), null, route('requests.index'));
            $cards[] = $this->card('Awaiting my action', $mine()->where('status', RequestStatus::ReturnedForAmendment->value)->count(),
                'Returned for amendment', route('requests.index'));
            $cards[] = $this->card('Drafts', $mine()->where('status', RequestStatus::Draft->value)->count(),
                'Not yet submitted', route('requests.index'));
        }

        if ($user->canApprove()) {
            $awaiting = ApprovalTask::pending()->where('approver_id', $user->id)
                ->whereHas('request', fn ($q) => $q->open());

            $cards[] = $this->card('Awaiting my approval', $awaiting->count(), null, route('approvals.index'));

            $overdue = ApprovalTask::overdue()->where('approver_id', $user->id)
                ->whereHas('request', fn ($q) => $q->open())->count();

            // Surfaced as its own card rather than folded into the one above,
            // because "four waiting" and "one of them is late" prompt different
            // actions from the reader.
            $cards[] = $this->card('Overdue approvals', $overdue,
                $overdue > 0 ? 'Escalation sent' : null, route('approvals.index'));
        }

        if ($user->hasRole(UserRole::TechnicalReviewer)) {
            $cards[] = $this->card('Assigned to my unit',
                ItRequest::awaitingStage(WorkflowStage::TechnicalRecommendation)
                    ->whereHas('recommendations', fn ($q) => $q->where('reviewer_id', $user->id))->count(),
                null, route('recommendations.index'));
        }

        if ($user->hasAnyRole(UserRole::GovernanceReviewer, UserRole::Hou)) {
            $cards[] = $this->card('Awaiting completeness',
                ItRequest::awaitingStage(WorkflowStage::CompletenessReview)->count(),
                null, route('governance.index'));

            $cards[] = $this->card('Awaiting consolidation',
                ItRequest::awaitingStage(WorkflowStage::Consolidation)->count(),
                null, route('governance.index'));

            $overdue = ApprovalTask::overdue()->whereHas('request', fn ($q) => $q->open())->count();

            $cards[] = $this->card('Overdue across all stages', $overdue,
                $overdue > 0 ? 'Needs attention' : null, route('reports.index'));
        }

        if ($user->hasRole(UserRole::CommitteeSecretariat)) {
            $cards[] = $this->card('Awaiting committee',
                ItRequest::awaitingStage(WorkflowStage::CommitteeDecision)->count(),
                'Full route only', route('committee.index'));
        }

        return $cards;
    }

    private function card(string $label, int|string $value, ?string $note, ?string $route): array
    {
        return compact('label', 'value', 'note', 'route');
    }

    /**
     * Requests needing attention, oldest first.
     *
     * Ordered by due date rather than by age, because the question the reader has
     * is "what is most late", not "what was raised first".
     */
    private function needsAttention($user)
    {
        return ApprovalTask::query()
            ->pending()
            ->with(['request', 'approver'])
            ->whereHas('request', fn ($q) => $q->visibleTo($user)->open())
            ->orderByRaw('due_at IS NULL, due_at ASC')
            ->limit(8)
            ->get();
    }
}
