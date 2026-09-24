<?php

namespace App\Livewire\Committee;

use App\Enums\WorkflowStage;
use App\Models\ItRequest;
use Livewire\Component;

/**
 * Committee workspace — the Full-route queue.
 *
 * WHY THIS IS SEPARATE FROM THE GOVERNANCE WORKSPACE
 *
 * The governance workspace is the IT department's view of a request's progress. This
 * is the committee's agenda, and the committee is a different body: its members do not
 * assess completeness, file recommendations or consolidate, and they should not have
 * to look at three tabs of work that is not theirs.
 *
 * WHAT THE DECISION IS RECORDED ON
 *
 * The request detail screen, not here. The decision needs the full request, the
 * recommendations and the consolidation in front of it — the committee votes on a
 * case, not on a row in a list. This screen is the agenda that gets them there.
 */
class Index extends Component
{
    public function render()
    {
        /*
         * Only requests on a route that REQUIRES a committee.
         *
         * Filtered on `requires_committee` rather than on the stage alone. A stage
         * check would be sufficient today, because only the Full route sets that stage
         * — but the routing rule lives in the database precisely so an administrator
         * can change it, and a hardcoded stage check would then show this body
         * requests it has no authority over.
         */
        $pending = ItRequest::query()
            ->where('current_stage', WorkflowStage::CommitteeDecision->value)
            ->whereHas('governanceRoute', fn ($q) => $q->where('requires_committee', true))
            ->with([
                'requestor:id,name',
                'department:id,name',
                'tier:id,name',
                'classification:id,name',
                'projectOwner:id,name',
                'pendingApprovalTask',
                'recommendations' => fn ($q) => $q->current()->with('reviewUnit:id,name'),
            ])
            ->orderBy('submitted_at')
            ->get();

        return view('livewire.committee.index', [
            'requests' => $pending,
        ])->layout('components.layouts.app', ['title' => 'Committee workspace']);
    }
}
