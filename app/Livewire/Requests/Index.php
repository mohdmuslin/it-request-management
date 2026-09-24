<?php

namespace App\Livewire\Requests;

use App\Enums\RequestStatus;
use App\Enums\UserRole;
use App\Models\ItRequest;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * My Requests.
 *
 * WHY THE LIST IS SCOPED IN THE QUERY, NOT FILTERED IN THE VIEW
 *
 * `visibleTo()` is applied to the query itself. Filtering after the fetch would
 * load every request in the system into memory and then hide most of it — the
 * records are still retrieved, still pass through the component, and are one
 * careless `take(20)` away from being displayed. A scoped query cannot leak what
 * it never read.
 *
 * The same scope backs the policy's visibility rule, so the list and the detail
 * screen cannot disagree about who may see what.
 */
class Index extends Component
{
    use WithPagination;

    /** The status filter, in the URL so a filtered view can be shared or bookmarked. */
    #[Url(as: 'status', history: true)]
    public string $status = '';

    #[Url(as: 'q', history: true)]
    public string $search = '';

    /**
     * Whether to show only requests awaiting the current user.
     *
     * This is the view an approver actually wants — "what is mine to do" — and it
     * defaults off so the list is predictable for a requestor.
     */
    #[Url(as: 'mine', history: true)]
    public bool $onlyMine = false;

    public function updatedSearch(): void
    {
        // Any change to the filters must return to page 1. Without this, searching
        // from page 3 shows page 3 of the new results, which is usually empty — and
        // reads as "no matches found" when there are matches on page 1.
        $this->resetPage();
    }

    public function updatedStatus(): void
    {
        $this->resetPage();
    }

    public function updatedOnlyMine(): void
    {
        $this->resetPage();
    }

    public function clearFilters(): void
    {
        $this->reset('status', 'search', 'onlyMine');
        $this->resetPage();
    }

    public function render()
    {
        $user = auth()->user();

        $query = ItRequest::query()
            ->visibleTo($user)
            ->with(['requestor:id,name', 'projectOwner:id,name', 'tier:id,name', 'classification:id,name'])
            // Newest first, with the id as a tiebreak: two requests created in the
            // same second otherwise sort arbitrarily, and a row can appear to move
            // between page loads.
            ->orderByDesc('created_at')
            ->orderByDesc('id');

        // "Mine to do" means a task assigned to me, or an unassigned task at a stage
        // my role decides — the same two paths the policy uses.
        if ($this->onlyMine) {
            $query->whereHas('approvalTasks', function ($q) use ($user) {
                $q->pending()->where(function ($inner) use ($user) {
                    $inner->where('approver_id', $user->id);

                    if ($user->hasRole(UserRole::GovernanceReviewer)) {
                        $inner->orWhere(fn ($s) => $s->whereNull('approver_id')->where('stage', 'completeness_review'));
                    }

                    if ($user->hasRole(UserRole::TechnicalReviewer)) {
                        $inner->orWhere(fn ($s) => $s->whereNull('approver_id')->where('stage', 'technical_recommendation'));
                    }

                    if ($user->hasRole(UserRole::Hou)) {
                        $inner->orWhere(fn ($s) => $s->whereNull('approver_id')->where('stage', 'consolidation'));
                    }

                    if ($user->hasRole(UserRole::CommitteeSecretariat)) {
                        $inner->orWhere(fn ($s) => $s->whereNull('approver_id')->where('stage', 'committee_decision'));
                    }
                });
            });
        }

        if ($this->status !== '') {
            $query->where('status', $this->status);
        }

        if ($this->search !== '') {
            $term = '%'.str_replace(['%', '_'], ['\%', '\_'], $this->search).'%';

            $query->where(function ($q) use ($term) {
                $q->where('request_no', 'like', $term)
                    ->orWhere('title', 'like', $term);
            });
        }

        return view('livewire.requests.index', [
            'requests' => $query->paginate(15),
            'statuses' => RequestStatus::options(),

            /*
             * The counts behind each filter, computed on the SAME scoped query.
             *
             * A count taken from the unscoped table would tell a requestor how many
             * requests exist company-wide — a small disclosure, but it is
             * information they are not entitled to and it is free to avoid.
             */
            'counts' => $this->statusCounts($user),
        ])->layout('components.layouts.app', ['title' => 'My requests']);
    }

    /** How many visible requests sit in each status. */
    private function statusCounts($user): array
    {
        return ItRequest::query()
            ->visibleTo($user)
            ->selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status')
            ->all();
    }
}
