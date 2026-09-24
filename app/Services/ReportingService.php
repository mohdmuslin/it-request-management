<?php

namespace App\Services;

use App\Enums\RequestStatus;
use App\Enums\WorkflowStage;
use App\Models\ApprovalTask;
use App\Models\ItRequest;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Reporting: workload, aging, turnaround and outcomes.
 *
 * WHY EVERY FIGURE COMES FROM ONE QUERY BUILDER
 *
 * UAT-014 requires the dashboard totals to RECONCILE with the transactional records.
 * That is only achievable if a total and the list it summarises are computed from the
 * same filtered set — so `query()` is the single entry point and every method here
 * takes the builder it returns.
 *
 * The failure mode this avoids is specific and common: a summary card counting the
 * whole table while the table below it is filtered, so the numbers disagree and
 * nobody can tell which is right.
 *
 * WHY "AGING" IS IN BUSINESS DAYS, NOT CALENDAR DAYS
 *
 * A request raised on Friday and read on Monday is one business day old, not three.
 * Reporting calendar days would make every weekend look like a backlog and every
 * public holiday look like a delay — and the people reading this report judge their
 * own performance against it.
 *
 * WHY NOTHING HERE WRITES
 *
 * Read-only by construction. A reporting service that can change a record is how a
 * dashboard becomes the source of truth for something it only described.
 */
class ReportingService
{
    public function __construct(
        private readonly BusinessCalendar $calendar,
    ) {}

    /**
     * The base query, scoped to what the user may see and narrowed by the filters.
     *
     * EVERY figure in this service flows through here, which is what makes the totals
     * reconcile with the rows. A method that queried `ItRequest` directly would be a
     * second definition of "the filtered set" and would drift.
     *
     * @param  array<string, mixed>  $filters
     */
    public function query(User $user, array $filters = []): Builder
    {
        $query = ItRequest::query()->visibleTo($user);

        $this->applyFilters($query, $filters);

        return $query;
    }

    /**
     * Apply the filters shared by the report and its export.
     *
     * Shared deliberately: an export that ignored a filter would produce a file whose
     * totals disagree with the screen it was downloaded from, and the recipient has no
     * way to know which one is wrong.
     *
     * @param  array<string, mixed>  $filters
     */
    public function applyFilters(Builder $query, array $filters): void
    {
        $from = $filters['from'] ?? null;
        $to = $filters['to'] ?? null;
        $status = $filters['status'] ?? null;
        $stage = $filters['stage'] ?? null;
        $tierId = $filters['tier_id'] ?? null;
        $classificationId = $filters['classification_id'] ?? null;
        $routeId = $filters['governance_route_id'] ?? null;
        $departmentId = $filters['department_id'] ?? null;
        $ownerId = $filters['project_owner_id'] ?? null;
        $search = $filters['search'] ?? null;

        /*
         * Dated on `request_date`, not `created_at`.
         *
         * `request_date` is the date the requestor states the request was raised, and it
         * is the field the business reports on. Filtering on `created_at` would move a
         * request into a different month because somebody saved a draft late in the
         * evening — which is exactly the sort of unreconcilable discrepancy the current
         * spreadsheet process produces.
         */
        if ($from) {
            $query->whereDate('request_date', '>=', $from);
        }

        if ($to) {
            $query->whereDate('request_date', '<=', $to);
        }

        if ($status) {
            $query->where('status', $status);
        }

        if ($stage) {
            $query->where('current_stage', $stage);
        }

        if ($tierId) {
            $query->where('tier_id', $tierId);
        }

        if ($classificationId) {
            $query->where('classification_id', $classificationId);
        }

        if ($routeId) {
            $query->where('governance_route_id', $routeId);
        }

        if ($departmentId) {
            $query->where('department_id', $departmentId);
        }

        if ($ownerId) {
            $query->where('project_owner_id', $ownerId);
        }

        if ($search) {
            // Escaped, so a literal % in a search term does not match everything.
            $term = '%'.str_replace(['%', '_'], ['\%', '\_'], $search).'%';

            $query->where(function ($q) use ($term) {
                $q->where('request_no', 'like', $term)
                    ->orWhere('title', 'like', $term);
            });
        }
    }

    // ---- Workload -----------------------------------------------------------

    /**
     * How many requests sit at each stage.
     *
     * Derived from the filtered set, so this and the list below it always agree.
     *
     * @return array<string, array{label: string, count: int}>
     */
    public function workloadByStage(Builder $query): array
    {
        $rows = (clone $query)
            ->selectRaw('current_stage, count(*) as total')
            ->groupBy('current_stage')
            ->pluck('total', 'current_stage');

        $out = [];

        foreach (WorkflowStage::cases() as $stage) {
            $out[$stage->value] = [
                'label' => $stage->label(),
                'count' => (int) ($rows[$stage->value] ?? 0),
            ];
        }

        /*
         * A returned request has NO stage — `returnForAmendment()` clears it, because
         * nobody holds the request while the requestor works on it.
         *
         * Plucked explicitly by key rather than summed from '' and null, which was the
         * first attempt: Laravel's `pluck` keys a null group as an empty string on some
         * drivers and as null on others, so summing both counted the same rows twice on
         * MySQL and once on SQLite. The two test drivers would have disagreed.
         */
        $returned = (clone $query)
            ->whereNull('current_stage')
            ->count();

        $out['__no_stage'] = [
            'label' => 'Returned for amendment',
            'count' => $returned,
        ];

        return $out;
    }

    /** How many requests sit in each status. */
    public function workloadByStatus(Builder $query): array
    {
        $rows = (clone $query)
            ->selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        $out = [];

        foreach (RequestStatus::cases() as $status) {
            $count = (int) ($rows[$status->value] ?? 0);

            // Only statuses that occur. A list of fourteen rows where ten read zero is
            // a list nobody finishes reading.
            if ($count > 0) {
                $out[$status->value] = ['label' => $status->label(), 'count' => $count];
            }
        }

        return $out;
    }

    // ---- Aging --------------------------------------------------------------

    /**
     * Open requests by how long they have been waiting, in business days buckets.
     *
     * Uses the BUSINESS calendar, so a request raised on Friday does not appear
     * three days old on Monday.
     *
     * @return array<int, array{label: string, count: int, requests: Collection}>
     */
    public function aging(Builder $query): array
    {
        $open = (clone $query)
            ->open()
            ->with(['requestor:id,name', 'projectOwner:id,name', 'pendingApprovalTask'])
            ->get();

        $buckets = [
            ['label' => '0–5 business days', 'min' => 0, 'max' => 5, 'count' => 0, 'requests' => collect()],
            ['label' => '6–10 business days', 'min' => 6, 'max' => 10, 'count' => 0, 'requests' => collect()],
            ['label' => '11–20 business days', 'min' => 11, 'max' => 20, 'count' => 0, 'requests' => collect()],
            ['label' => 'Over 20 business days', 'min' => 21, 'max' => PHP_INT_MAX, 'count' => 0, 'requests' => collect()],
        ];

        foreach ($open as $request) {
            $age = $this->businessDaysSince($request);
            $request->setAttribute('business_age_days', $age);

            foreach ($buckets as $index => $bucket) {
                if ($age >= $bucket['min'] && $age <= $bucket['max']) {
                    $buckets[$index]['count']++;
                    $buckets[$index]['requests']->push($request);

                    break;
                }
            }
        }

        return $buckets;
    }

    /**
     * Business days between a request's start and now.
     *
     * Measured from `submitted_at` where it exists, falling back to `request_date`.
     * A draft has not started waiting on anybody, so it is not "aging" in the sense an
     * approver cares about — but it HAS been open since its request date, and the
     * requestor is the one holding it.
     *
     * WHY THIS WALKS CALENDAR DAYS
     *
     * Dividing elapsed hours by eight gives a wrong answer either side of a weekend:
     * Monday 09:00 back to Friday 17:00 is 64 hours, which reads as eight business
     * days and is actually one. Subtracting dates and multiplying by five-sevenths is
     * wrong again across a public holiday.
     *
     * Counting the working days that have actually elapsed cannot be wrong in either
     * case: Friday to Monday counts Saturday (no), Sunday (no), Monday (yes) — one.
     */
    public function businessDaysSince(ItRequest $request): int
    {
        $from = $request->submitted_at ?? $request->request_date?->startOfDay() ?? $request->created_at;

        if (! $from) {
            return 0;
        }

        $start = CarbonImmutable::instance($from)->startOfDay();
        $today = CarbonImmutable::now()->startOfDay();

        if ($start->greaterThanOrEqualTo($today)) {
            return 0;
        }

        $days = 0;
        $cursor = $start;

        while ($cursor->lessThan($today)) {
            $cursor = $cursor->addDay();

            if ($this->calendar->isWorkingDay($cursor)) {
                $days++;
            }
        }

        return $days;
    }

    // ---- Turnaround ---------------------------------------------------------

    /**
     * Turnaround: how long decided requests took, end to end.
     *
     * Only CLOSED requests, because a turnaround figure that includes work still in
     * progress understates the true time and makes the average move every time
     * somebody opens the report.
     */
    public function turnaround(Builder $query): array
    {
        $closed = (clone $query)
            ->whereNotNull('closed_at')
            ->whereNotNull('submitted_at')
            ->get(['id', 'request_no', 'title', 'submitted_at', 'closed_at']);

        $durations = $closed
            ->map(fn (ItRequest $r) => $this->calendar
                ->workingHoursBetween($r->submitted_at, $r->closed_at))
            ->filter(fn ($h) => $h >= 0)
            ->values();

        if ($durations->isEmpty()) {
            return [
                'count' => 0,
                'median_hours' => null,
                'average_hours' => null,
                'fastest_hours' => null,
                'slowest_hours' => null,
            ];
        }

        $sorted = $durations->sort()->values();

        return [
            'count' => $durations->count(),

            /*
             * Median as well as average, and the median is the one worth reading.
             *
             * A single request returned three times and left open for a month drags the
             * average away from every other request, so the average describes a
             * turnaround no individual request experienced. The median is a real request.
             */
            'median_hours' => $this->median($sorted),
            'average_hours' => round($durations->sum() / $durations->count(), 1),
            'fastest_hours' => round($sorted->first(), 1),
            'slowest_hours' => round($sorted->last(), 1),
        ];
    }

    private function median(Collection $sorted): float
    {
        $count = $sorted->count();

        if ($count === 0) {
            return 0.0;
        }

        $middle = intdiv($count, 2);

        return $count % 2 === 1
            ? round((float) $sorted[$middle], 1)
            : round(((float) $sorted[$middle - 1] + (float) $sorted[$middle]) / 2, 1);
    }

    // ---- Outcomes -----------------------------------------------------------

    /**
     * What happened to the requests in scope.
     *
     * Includes the still-open ones, because "we approved 4" means nothing without
     * "out of how many" — and a report that shows only decided requests makes an
     * approval rate look like 100%.
     */
    public function outcomes(Builder $query): array
    {
        $rows = (clone $query)
            ->selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        $closed = (clone $query)->whereNotNull('closed_at')->count();

        $outcomeRows = (clone $query)
            ->whereNotNull('outcome')
            ->selectRaw('outcome, count(*) as total')
            ->groupBy('outcome')
            ->pluck('total', 'outcome');

        return [
            'total' => (int) $rows->sum(),
            'open' => (int) $rows
                ->only(collect(RequestStatus::cases())->filter(fn ($s) => $s->isOpen())->pluck('value')->all())
                ->sum(),
            'closed' => $closed,
            'by_outcome' => $outcomeRows->all(),
        ];
    }

    // ---- Overdue ------------------------------------------------------------

    /**
     * Tasks past their target, with how late.
     *
     * The figure the report exists for: the current process has no due dates at all,
     * so "are we meeting our targets?" is unanswerable today.
     */
    public function overdue(Builder $query): Collection
    {
        /*
         * Selected through the REQUEST query rather than from `approval_tasks`
         * directly, so the report's filters apply. A separate query would ignore the
         * date range and the department filter, and the overdue list would not match
         * the totals above it.
         */
        $ids = (clone $query)->open()->pluck('id');

        if ($ids->isEmpty()) {
            return collect();
        }

        return ApprovalTask::query()
            ->overdue()
            ->whereIn('request_id', $ids)
            ->with(['request:id,request_no,title,current_stage', 'approver:id,name'])
            ->orderBy('due_at')
            ->get()
            ->map(function (ApprovalTask $task) {
                $task->setAttribute(
                    'business_days_late',
                    $this->calendar->workingHoursBetween($task->due_at, now()) / max(1, $this->calendar->hoursPerDay()),
                );

                return $task;
            });
    }

    /**
     * Median and average time-to-decision per stage.
     *
     * Answers "where does it actually get stuck?" — which a workload count cannot,
     * because a stage holding two requests for a month is a bigger problem than one
     * holding eight for a day.
     */
    public function stageDurations(Builder $query): array
    {
        $requestIds = (clone $query)->pluck('id');

        if ($requestIds->isEmpty()) {
            return [];
        }

        $tasks = ApprovalTask::query()
            ->whereIn('request_id', $requestIds)
            ->whereNotNull('decided_at')
            ->get(['stage', 'due_at', 'created_at', 'decided_at']);

        $byStage = [];

        foreach ($tasks as $task) {
            $hours = $this->calendar->workingHoursBetween($task->created_at, $task->decided_at);

            $byStage[$task->stage] ??= ['label' => '', 'durations' => collect(), 'overdue' => 0];

            $byStage[$task->stage]['durations']->push($hours);

            // Counted against the due date the task actually had, not a recomputed one.
            if ($task->due_at && $task->decided_at->gt($task->due_at)) {
                $byStage[$task->stage]['overdue']++;
            }
        }

        foreach ($byStage as $stage => $data) {
            $sorted = $data['durations']->sort()->values();

            $byStage[$stage] = [
                'label' => WorkflowStage::tryFrom($stage)?->label() ?? $stage,
                'count' => $sorted->count(),
                'median_hours' => $this->median($sorted),
                'average_hours' => round($sorted->sum() / max(1, $sorted->count()), 1),
                'overdue' => $data['overdue'],
            ];
        }

        return $byStage;
    }
}
