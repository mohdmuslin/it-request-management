<?php

namespace App\Livewire\Reports;

use App\Enums\GovernanceRoute;
use App\Enums\RequestStatus;
use App\Enums\WorkflowStage;
use App\Models\Department;
use App\Models\ItRequest;
use App\Models\Tier;
use App\Models\User;
use App\Services\ReportingService;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Reports — workload, aging, turnaround and outcomes.
 *
 * WHY EVERY FIGURE ON THIS SCREEN COMES FROM ONE QUERY
 *
 * UAT-014 requires the totals to reconcile with the transactional records. That is
 * only achievable if a total and the list it summarises come from the same filtered
 * set — so `ReportingService::query()` is called once per render and every figure is
 * derived from it.
 *
 * The failure this avoids is the common one: a summary card counting the whole table
 * while the table below it is filtered, so the numbers disagree and the reader has no
 * way to know which is right.
 *
 * WHY THIS SCREEN IS READ-ONLY FOR THE AUDITOR
 *
 * The Auditor exists to read everything and change nothing. Reports are exactly what
 * that role is for, so it is one of the few screens they can reach — and nothing here
 * writes anything, so that is safe.
 */
class Index extends Component
{
    // ---- Filters, all in the URL so a report can be linked to or bookmarked ----

    #[Url(as: 'from', history: true)]
    public string $from = '';

    #[Url(as: 'to', history: true)]
    public string $to = '';

    #[Url(as: 'status', history: true)]
    public string $status = '';

    #[Url(as: 'stage', history: true)]
    public string $stage = '';

    #[Url(as: 'tier', history: true)]
    public ?int $tier_id = null;

    #[Url(as: 'classification', history: true)]
    public ?int $classification_id = null;

    #[Url(as: 'route', history: true)]
    public ?int $governance_route_id = null;

    #[Url(as: 'department', history: true)]
    public ?int $department_id = null;

    #[Url(as: 'owner', history: true)]
    public ?int $project_owner_id = null;

    #[Url(as: 'q', history: true)]
    public string $search = '';

    public function mount(): void
    {
        $this->authorize('viewAny', ItRequest::class);

        /*
         * A default window of the current year.
         *
         * An unfiltered report over a table that grows forever gets slower every month,
         * and the first question a reader has is usually about a period they can name.
         * Prefilled rather than forced: it is editable, and cleared by "Clear filters".
         */
        if ($this->from === '' && $this->to === '') {
            $this->from = now()->startOfYear()->toDateString();
            $this->to = now()->toDateString();
        }
    }

    /** Any filter change must return to a state where the figures are consistent. */
    public function updated(): void
    {
        // Nothing to reset — there is no pagination — but the hook exists so a future
        // addition cannot silently skip it.
    }

    public function clearFilters(): void
    {
        $this->reset([
            'from', 'to', 'status', 'stage', 'tier_id', 'classification_id',
            'governance_route_id', 'department_id', 'project_owner_id', 'search',
        ]);

        $this->from = now()->startOfYear()->toDateString();
        $this->to = now()->toDateString();
    }

    public function render()
    {
        $service = app(ReportingService::class);

        $filters = [
            'from' => $this->from ?: null,
            'to' => $this->to ?: null,
            'status' => $this->status ?: null,
            'stage' => $this->stage ?: null,
            'tier_id' => $this->tier_id,
            'classification_id' => $this->classification_id,
            'governance_route_id' => $this->governance_route_id,
            'department_id' => $this->department_id,
            'project_owner_id' => $this->project_owner_id,
            'search' => $this->search ?: null,
        ];

        // ONE query, every figure derived from it.
        $query = $service->query(auth()->user(), $filters);

        return view('livewire.reports.index', [
            'outcomes' => $service->outcomes($query),
            'byStage' => $service->workloadByStage($query),
            'byStatus' => $service->workloadByStatus($query),
            'aging' => $service->aging($query),
            'turnaround' => $service->turnaround($query),
            'stageDurations' => $service->stageDurations($query),
            'overdue' => $service->overdue($query),

            // The rows behind the totals, so a reader can check one against the other.
            'requests' => $query->with(['requestor:id,name', 'department:id,name', 'tier:id,name'])
                ->orderByDesc('request_date')
                ->orderByDesc('id')
                ->limit(100)
                ->get(),

            'statuses' => RequestStatus::options(),
            'stages' => collect(WorkflowStage::cases())->mapWithKeys(fn ($s) => [$s->value => $s->label()])->all(),
            'tiers' => Tier::orderBy('sort_order')->get(['id', 'name']),
            'routes' => GovernanceRoute::options(),
            'routeModels' => \App\Models\GovernanceRoute::orderBy('sort_order')->get(['id', 'name']),
            'departments' => Department::orderBy('name')->get(['id', 'name']),
            'owners' => User::query()->active()->orderBy('name')->get(['id', 'name']),
        ])->layout('components.layouts.app', ['title' => 'Reports']);
    }
}
