<?php

use App\Enums\Decision;
use App\Enums\RequestStatus;
use App\Enums\UserRole;
use App\Enums\WorkflowStage;
use App\Models\ApprovalTask;
use App\Models\Department;
use App\Models\GovernanceRoute;
use App\Models\Holiday;
use App\Models\ItRequest;
use App\Models\Tier;
use App\Services\BusinessCalendar;
use App\Services\ReportingService;
use App\Services\RequestNumberService;
use App\Services\WorkflowService;
use Carbon\CarbonImmutable;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Support\Carbon;

/**
 * Reporting.
 *
 * UAT-014 is "dashboard totals reconcile with transactional records", so the tests
 * that matter most here assert that a total EQUALS the list it summarises. A
 * reporting bug is invisible in the usual way: a wrong number looks like a number.
 */
beforeEach(function () {
    $this->seed(ReferenceDataSeeder::class);

    $this->reporting = app(ReportingService::class);
    $this->workflow = app(WorkflowService::class);

    $this->admin = asUser(UserRole::Administrator);
    $this->requestor = asUser(UserRole::Requestor);
    $this->owner = asUser(UserRole::ProjectOwner);

    $this->department = Department::create(['code' => 'ICT', 'name' => 'Information Technology']);
    $this->tier = Tier::orderBy('sort_order')->first();
});

/** A request in a chosen state. */
function makeRequest(array $overrides = []): ItRequest
{
    return ItRequest::create(array_merge([
        'request_no' => app(RequestNumberService::class)->next(),
        'title' => 'Test request',
        'request_date' => now()->toDateString(),
        'requestor_id' => test()->requestor->id,
        'department_id' => test()->department->id,
        'project_owner_id' => test()->owner->id,
        'tier_id' => test()->tier->id,
        'status' => RequestStatus::Draft->value,
        'current_stage' => WorkflowStage::Submission->value,
        'business_need' => 'Testing.',
    ], $overrides));
}

// ---- Reconciliation (UAT-014) -----------------------------------------------

it('reports totals that reconcile with the transactional records', function () {
    /*
     * THE UAT-014 ASSERTION.
     *
     * Every figure is derived from the same filtered query, so the totals and the rows
     * cannot disagree. This asserts that directly rather than trusting the design.
     */
    foreach (range(1, 5) as $i) {
        makeRequest(['title' => "Request {$i}", 'status' => RequestStatus::Draft->value]);
    }

    $query = $this->reporting->query($this->admin);

    $outcomes = $this->reporting->outcomes($query);
    $statuses = $this->reporting->workloadByStatus($query);

    // The total equals the row count...
    expect($outcomes['total'])->toBe(5)
        ->and($outcomes['total'])->toBe(ItRequest::count());

    // ...and equals the sum of the status breakdown.
    expect(array_sum(array_column($statuses, 'count')))->toBe($outcomes['total']);
});

it('reconciles the stage breakdown with the total, including returned requests', function () {
    /*
     * A returned request has NO stage — `returnForAmendment()` clears it. If the stage
     * breakdown did not account for those rows, the figures would not add up to the
     * request count and the missing requests would be invisible.
     */
    makeRequest(['title' => 'Draft']);
    makeRequest(['title' => 'Submitted', 'status' => RequestStatus::Submitted->value,
        'current_stage' => WorkflowStage::ProjectOwner->value]);
    makeRequest(['title' => 'Returned', 'status' => RequestStatus::ReturnedForAmendment->value,
        'current_stage' => null]);

    $query = $this->reporting->query($this->admin);
    $stages = $this->reporting->workloadByStage($query);

    expect(array_sum(array_column($stages, 'count')))->toBe(3)
        ->and($stages['__no_stage']['count'])->toBe(1);
});

it('applies the same filters to a total as to the rows', function () {
    // The specific failure this prevents: a summary card counting the whole table
    // while the list below it is filtered, so the numbers disagree.
    makeRequest(['title' => 'IT request', 'department_id' => $this->department->id]);

    $otherDepartment = Department::create(['code' => 'OPS', 'name' => 'Operations']);
    makeRequest(['title' => 'Ops request', 'department_id' => $otherDepartment->id]);

    $query = $this->reporting->query($this->admin, ['department_id' => $this->department->id]);

    expect($query->count())->toBe(1)
        ->and($this->reporting->outcomes($query)['total'])->toBe(1);
});

it('counts only what the user may see', function () {
    /*
     * A report is a list, and a list is where a leak is easiest: scoping it wrongly
     * shows a row rather than throwing an error. The query is scoped, not filtered
     * afterwards, so the rows are never retrieved.
     */
    makeRequest(['title' => 'Someone else\'s request', 'requestor_id' => $this->owner->id]);

    $query = $this->reporting->query($this->requestor);

    expect($query->count())->toBe(0);
});

// ---- Aging ------------------------------------------------------------------

it('measures age in business days, not calendar days', function () {
    /*
     * A request raised on Friday and read on Monday is ONE business day old, not
     * three. Reporting calendar days makes every weekend look like a backlog — and the
     * people reading this report judge their own performance against it.
     */
    $friday = CarbonImmutable::parse('2026-09-18 09:00:00');   // Friday
    Carbon::setTestNow(CarbonImmutable::parse('2026-09-21 09:00:00'));   // Monday

    $request = makeRequest([
        'request_date' => $friday->toDateString(),
        'submitted_at' => $friday,
    ]);

    // Saturday and Sunday are not working days, so Friday → Monday is one business day.
    expect($this->reporting->businessDaysSince($request->fresh()))->toBe(1);

    Carbon::setTestNow();
});

it('does not count a public holiday as a business day', function () {
    // A holiday makes a request look younger, which is correct: nobody could act.
    Holiday::create([
        'date' => '2026-09-21',
        'name' => 'Public holiday',
        'source' => 'manual',
    ]);

    Carbon::setTestNow(CarbonImmutable::parse('2026-09-22 09:00:00'));

    $request = makeRequest([
        'request_date' => '2026-09-18',
        'submitted_at' => CarbonImmutable::parse('2026-09-18 09:00:00'),
    ]);

    app(BusinessCalendar::class)->forgetHolidays();

    // Friday → Tuesday, with Monday a holiday: only Tuesday counts.
    expect(app(ReportingService::class)->businessDaysSince($request->fresh()))->toBe(1);

    Carbon::setTestNow();
});

it('returns zero for a request raised today', function () {
    $request = makeRequest(['request_date' => now()->toDateString()]);

    expect($this->reporting->businessDaysSince($request))->toBe(0);
});

it('buckets every open request into exactly one aging band', function () {
    foreach ([1, 8, 15, 40] as $days) {
        makeRequest([
            'title' => "{$days} days old",
            'status' => RequestStatus::PendingProjectOwner->value,
            'current_stage' => WorkflowStage::ProjectOwner->value,
            'request_date' => now()->subDays($days)->toDateString(),
            'submitted_at' => now()->subDays($days),
        ]);
    }

    $buckets = $this->reporting->aging($this->reporting->query($this->admin));

    $total = array_sum(array_column($buckets, 'count'));

    // Every open request lands in exactly one bucket — no double counting, no gaps.
    expect($total)->toBe(4);
});

it('excludes closed requests from aging', function () {
    // Aging is about work still waiting. A closed request is not waiting on anybody.
    makeRequest([
        'status' => RequestStatus::Closed->value,
        'closed_at' => now(),
        'request_date' => now()->subDays(60)->toDateString(),
    ]);

    $buckets = $this->reporting->aging($this->reporting->query($this->admin));

    expect(array_sum(array_column($buckets, 'count')))->toBe(0);
});

// ---- Turnaround -------------------------------------------------------------

it('reports turnaround from closed requests only', function () {
    /*
     * A figure that included work still in progress would understate the true time and
     * would move every time somebody opened the report.
     */
    $submitted = now()->subDays(10);
    $closed = now()->subDays(2);

    makeRequest([
        'status' => RequestStatus::Closed->value,
        'submitted_at' => $submitted,
        'closed_at' => $closed,
    ]);

    // An open request, which must not affect the figure.
    makeRequest([
        'status' => RequestStatus::PendingProjectOwner->value,
        'current_stage' => WorkflowStage::ProjectOwner->value,
        'submitted_at' => now()->subDays(30),
    ]);

    $turnaround = $this->reporting->turnaround($this->reporting->query($this->admin));

    expect($turnaround['count'])->toBe(1)
        ->and($turnaround['median_hours'])->toBeGreaterThan(0);
});

it('reports no turnaround rather than zero when nothing is closed', function () {
    /*
     * Null, not 0. A turnaround of "0 hours" reads as "we are instant", which is the
     * opposite of "we have not finished anything" — and it is the kind of number that
     * gets quoted in a management meeting.
     */
    makeRequest();

    $turnaround = $this->reporting->turnaround($this->reporting->query($this->admin));

    expect($turnaround['count'])->toBe(0)
        ->and($turnaround['median_hours'])->toBeNull()
        ->and($turnaround['average_hours'])->toBeNull();
});

it('reports a median that is not dragged by a single slow request', function () {
    /*
     * The median is the figure worth reading. One request returned three times and left
     * open for two months drags the average away from every other request, so the
     * average describes a turnaround nobody experienced.
     */
    foreach ([2, 3, 4, 5] as $days) {
        makeRequest([
            'status' => RequestStatus::Closed->value,
            'submitted_at' => now()->subDays($days + 1),
            'closed_at' => now()->subDays(1),
        ]);
    }

    // One outlier: open for two months.
    makeRequest([
        'status' => RequestStatus::Closed->value,
        'submitted_at' => now()->subDays(60),
        'closed_at' => now(),
    ]);

    $turnaround = $this->reporting->turnaround($this->reporting->query($this->admin));

    expect($turnaround['count'])->toBe(5)
        // The median sits with the cluster, not with the outlier.
        ->and($turnaround['median_hours'])->toBeLessThan($turnaround['average_hours']);
});

// ---- Overdue ----------------------------------------------------------------

it('reports overdue tasks with the request they belong to', function () {
    $request = makeRequest([
        'status' => RequestStatus::PendingProjectOwner->value,
        'current_stage' => WorkflowStage::ProjectOwner->value,
        'submitted_at' => now(),
    ]);

    ApprovalTask::create([
        'request_id' => $request->id,
        'stage' => WorkflowStage::ProjectOwner->value,
        'sequence' => 1,
        'approver_id' => $this->owner->id,
        'due_at' => now()->subDays(4),
    ]);

    $overdue = $this->reporting->overdue($this->reporting->query($this->admin));

    expect($overdue)->toHaveCount(1)
        ->and($overdue->first()->request->id)->toBe($request->id)
        ->and($overdue->first()->business_days_late)->toBeGreaterThan(0);
});

it('excludes overdue tasks on closed requests', function () {
    // An undecided task on a closed request is a data problem, not a workload one, and
    // counting it here would inflate the number the report exists to state.
    $request = makeRequest([
        'status' => RequestStatus::Closed->value,
        'closed_at' => now(),
    ]);

    ApprovalTask::create([
        'request_id' => $request->id,
        'stage' => WorkflowStage::ProjectOwner->value,
        'sequence' => 1,
        'approver_id' => $this->owner->id,
        'due_at' => now()->subDays(4),
    ]);

    expect($this->reporting->overdue($this->reporting->query($this->admin)))->toHaveCount(0);
});

it('applies the report filters to the overdue list', function () {
    /*
     * Selected through the REQUEST query rather than from `approval_tasks` directly, so
     * the filters apply. A separate query would ignore the date range and the
     * department filter, and the overdue list would not match the totals above it.
     */
    $request = makeRequest([
        'status' => RequestStatus::PendingProjectOwner->value,
        'current_stage' => WorkflowStage::ProjectOwner->value,
        'request_date' => '2020-01-01',
        'submitted_at' => '2020-01-01',
    ]);

    ApprovalTask::create([
        'request_id' => $request->id,
        'stage' => WorkflowStage::ProjectOwner->value,
        'sequence' => 1,
        'approver_id' => $this->owner->id,
        'due_at' => now()->subDays(4),
    ]);

    // A date filter that excludes the request must exclude its overdue task too.
    $query = $this->reporting->query($this->admin, [
        'from' => now()->subDays(7)->toDateString(),
        'to' => now()->toDateString(),
    ]);

    expect($this->reporting->overdue($query))->toHaveCount(0);
});

// ---- Stage durations --------------------------------------------------------

it('reports stage durations so a bottleneck is visible', function () {
    /*
     * A workload count cannot answer "where does it get stuck?" — a stage holding two
     * requests for a month is a bigger problem than one holding eight for a day.
     */
    $request = makeRequest([
        'status' => RequestStatus::PendingProjectSponsor->value,
        'current_stage' => WorkflowStage::ProjectSponsor->value,
    ]);

    ApprovalTask::create([
        'request_id' => $request->id,
        'stage' => WorkflowStage::ProjectOwner->value,
        'sequence' => 1,
        'approver_id' => $this->owner->id,
        'due_at' => now()->subDays(5),
        'decided_at' => now(),
        'decision' => Decision::Approved->value,
        'created_at' => now()->subDays(8),
    ]);

    $durations = $this->reporting->stageDurations($this->reporting->query($this->admin));

    expect($durations)->toHaveKey(WorkflowStage::ProjectOwner->value)
        ->and($durations[WorkflowStage::ProjectOwner->value]['count'])->toBe(1)
        ->and($durations[WorkflowStage::ProjectOwner->value]['overdue'])->toBe(1);
});

it('matches the request number pattern in a search', function () {
    $request = makeRequest(['title' => 'Unique searchable title']);

    $query = $this->reporting->query($this->admin, ['search' => $request->request_no]);

    expect($query->count())->toBe(1);
});

it('treats a search term with a percent sign literally', function () {
    // An unescaped % matches everything, so a search for "50%" would return the whole
    // table and look like it worked.
    makeRequest(['title' => 'Normal request']);

    $query = $this->reporting->query($this->admin, ['search' => '100%']);

    expect($query->count())->toBe(0);
});

// ---- The governance route filter --------------------------------------------

it('filters by governance route', function () {
    $full = GovernanceRoute::where('code', 'full')->first();
    $light = GovernanceRoute::where('code', 'light')->first();

    makeRequest(['title' => 'Full route', 'governance_route_id' => $full->id]);
    makeRequest(['title' => 'Light route', 'governance_route_id' => $light->id]);

    $query = $this->reporting->query($this->admin, ['governance_route_id' => $full->id]);

    expect($query->count())->toBe(1)
        ->and($query->first()->title)->toBe('Full route');
});

// ---- Read-only --------------------------------------------------------------

it('does not change any record', function () {
    // A reporting service that can write is how a dashboard becomes the source of
    // truth for something it only described.
    foreach (range(1, 6) as $i) {
        makeRequest(['title' => "Request {$i}"]);
    }

    $before = ItRequest::orderBy('id')->get(['id', 'status', 'current_stage', 'updated_at'])->toArray();

    $query = $this->reporting->query($this->admin);

    $this->reporting->workloadByStage($query);
    $this->reporting->workloadByStatus($query);
    $this->reporting->aging($query);
    $this->reporting->turnaround($query);
    $this->reporting->outcomes($query);
    $this->reporting->overdue($query);
    $this->reporting->stageDurations($query);

    $after = ItRequest::orderBy('id')->get(['id', 'status', 'current_stage', 'updated_at'])->toArray();

    expect($after)->toBe($before);
});
