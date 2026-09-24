<?php

namespace App\Livewire\Governance;

use App\Enums\RequestStatus;
use App\Enums\WorkflowStage;
use App\Models\ItRequest;
use App\Services\GovernanceService;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * The governance workspace.
 *
 * WHY THIS IS ONE SCREEN WITH TABS RATHER THAN FOUR
 *
 * Completeness review, technical recommendations, consolidation and closure are four
 * stages, but they are worked by overlapping people — the Governance Reviewer
 * assesses and later closes; the HOU consolidates and also closes. Four separate
 * menus would mean the same person navigating between them to finish one request.
 *
 * The tabs are the STAGES with a count each, so the person opening this knows where
 * the work is before clicking anything.
 */
class Index extends Component
{
    /** Which stage tab is open. In the URL so a queue can be linked to directly. */
    #[Url(as: 'stage', history: true)]
    public string $stage = 'completeness_review';

    public string $flash = '';

    /** The stages this workspace covers, in process order. */
    public const STAGES = [
        'completeness_review' => 'Completeness review',
        'technical_recommendation' => 'Technical recommendations',
        'consolidation' => 'Consolidation',
        'committee_decision' => 'With the committee',
        'closure' => 'Awaiting closure',
    ];

    public function showStage(string $stage): void
    {
        if (! array_key_exists($stage, self::STAGES)) {
            return;
        }

        $this->stage = $stage;
        $this->flash = '';
    }

    public function render()
    {
        $service = app(GovernanceService::class);

        /*
         * Every request at every governance stage, in one query.
         *
         * The counts per tab and the rows for the open tab come from the SAME set, so
         * a count can never disagree with what the list shows — which is the defect
         * where a tab says "3" and shows nothing.
         */
        $all = ItRequest::query()
            ->where(function ($q) {
                $q->whereIn('current_stage', [
                    WorkflowStage::CompletenessReview->value,
                    WorkflowStage::TechnicalRecommendation->value,
                    WorkflowStage::Consolidation->value,
                    /*
                     * Included so the workspace does not report "0 requests in the
                     * governance process" while a Full-route request is sitting with
                     * the committee. That is exactly what it said before this tab was
                     * added — the request had not finished, and the screen a governance
                     * reviewer opens claimed nothing was in progress.
                     */
                    WorkflowStage::CommitteeDecision->value,
                ])
                    /*
                     * Closure is a stage a CLOSED request also sits at — it is where
                     * `close()` leaves `current_stage`. Without the status filter the
                     * "awaiting closure" tab would fill up with everything ever closed,
                     * which is the opposite of a work queue.
                     */
                    ->orWhere(function ($inner) {
                        $inner->where('current_stage', WorkflowStage::Closure->value)
                            ->where('status', '!=', RequestStatus::Closed->value);
                    });
            })
            ->with([
                'requestor:id,name',
                'department:id,name',
                'tier:id,name',
                'classification:id,name',
                'governanceRoute:id,name,requires_committee',
                'projectOwner:id,name',
            ])
            // Oldest first: this is a work queue, and the requests that have waited
            // longest are the ones somebody should be looking at.
            ->orderBy('submitted_at')
            ->orderBy('id')
            ->get();

        $counts = [];
        foreach (array_keys(self::STAGES) as $stage) {
            $counts[$stage] = $all->where('current_stage', $stage)->count();
        }

        $requests = $all->where('current_stage', $this->stage)->values();

        /*
         * Which units are outstanding, computed only for the technical tab.
         *
         * It is only DISPLAYED there, and it is what an HOU means by "where is this
         * stuck?" — a request awaiting two of three units looks identical to one
         * awaiting all three without this.
         */
        $outstanding = [];
        if ($this->stage === WorkflowStage::TechnicalRecommendation->value) {
            foreach ($requests as $request) {
                $outstanding[$request->id] = $service->outstandingUnits($request)->pluck('name')->all();
            }
        }

        return view('livewire.governance.index', [
            'requests' => $requests,
            'counts' => $counts,
            'stages' => self::STAGES,
            'outstanding' => $outstanding,
            'total' => $all->count(),
        ])->layout('components.layouts.app', ['title' => 'Governance workspace']);
    }
}
