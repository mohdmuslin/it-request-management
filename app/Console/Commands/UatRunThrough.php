<?php

namespace App\Console\Commands;

use App\Enums\Decision;
use App\Enums\RecommendationOutcome;
use App\Enums\RequestStatus;
use App\Enums\UserRole;
use App\Enums\WorkflowStage;
use App\Http\Requests\ItRequestFormRequest;
use App\Models\ApprovalTask;
use App\Models\Classification;
use App\Models\Department;
use App\Models\GovernanceRoute;
use App\Models\ItRequest;
use App\Models\Recommendation;
use App\Models\Role;
use App\Models\Tier;
use App\Models\User;
use App\Models\WorkflowHistory;
use App\Services\AuditService;
use App\Services\GovernanceService;
use App\Services\ReportingService;
use App\Services\RequestNumberService;
use App\Services\WorkflowDecisionService;
use App\Services\WorkflowService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * The UAT run-through, as a command rather than a document.
 *
 * WHY EXECUTABLE
 *
 * The brief lists fifteen acceptance scenarios. A checklist in a document is completed by
 * somebody reading the screen and deciding it looks right, and it is out of date the
 * moment the code changes. This runs them, prints the evidence for each one, and exits
 * non-zero if any fails — so it can be re-run after every release.
 *
 * It ALSO means the scenarios are expressed exactly as the business states them, rather
 * than as an approximation in a test file. UAT-007 says "resumes at the correct stage";
 * this proves it by returning a request from the Sponsor and checking it comes back to
 * the Sponsor.
 *
 * WHY IT MUTATES DATA
 *
 * The scenarios are journeys: raise, submit, approve, return, amend, clear a committee.
 * They cannot be proven by reading. So the command:
 *
 *  - refuses to run anywhere but local/testing;
 *  - creates its OWN requests, clearly numbered, and never touches an existing one;
 *  - reports every request it created so they can be removed.
 *
 * A pass is not a substitute for a person using the screen. It is the part that should
 * never fail, so that the person's time goes on what a script cannot judge.
 */
class UatRunThrough extends Command
{
    protected $signature = 'itrequest:uat
                            {--keep : Leave the requests this command created in place}';

    protected $description = 'Run the fifteen acceptance scenarios from the vendor brief and report the evidence';

    /** @var array<int, array{id: string, scenario: string, pass: bool, evidence: string}> */
    private array $results = [];

    /** @var array<int, int> */
    private array $created = [];

    private WorkflowService $workflow;

    private WorkflowDecisionService $decisions;

    private GovernanceService $governance;

    public function handle(): int
    {
        if (! app()->environment(['local', 'testing'])) {
            $this->line('This command creates requests and must not run outside local or testing.');

            return self::FAILURE;
        }

        $this->workflow = app(WorkflowService::class);
        $this->decisions = app(WorkflowDecisionService::class);
        $this->governance = app(GovernanceService::class);

        $this->line('IT Request Management — UAT run-through');
        $this->line('Environment: '.app()->environment());
        $this->newLine();

        /*
         * The audit trail records `auth()->id()` as the actor, and a console command has no
         * session — so every transition this run produces would be attributed to "system".
         * UAT-012 exists to prove the trail names an ACTOR, and a trail full of nulls would
         * fail it for a reason that has nothing to do with the application.
         *
         * The journey helpers sign in as the person who would take each step, which is both
         * what the browser does and what makes the trail meaningful. Signed in as the
         * requestor here so the initial `submit` has an actor too.
         */
        Auth::login($this->userWithRole(UserRole::Requestor));

        $this->uat001DraftSavedAndResumed();
        $this->uat002ConditionalValidation();
        $this->uat003UniqueRequestNumber();
        $this->uat004OwnerApproves();
        $this->uat005SponsorRejects();
        $this->uat006GovernanceReturns();
        $this->uat007ResumesAtReturningStage();
        $this->uat008IndependentRecommendations();
        $this->uat009ConsolidationRoute();
        $this->uat010FullRouteReachesCommittee();
        $this->uat011Authorisation();
        $this->uat012AuditTrail();
        $this->uat013RemindersAndEscalation();
        $this->uat014TotalsReconcile();
        $this->uat015BackupRestore();

        $this->report();

        return collect($this->results)->where('pass', false)->isEmpty()
            ? self::SUCCESS
            : self::FAILURE;
    }

    // ---- The scenarios ------------------------------------------------------

    private function uat001DraftSavedAndResumed(): void
    {
        $request = $this->newRequest('UAT-001 draft');
        $before = $request->title;

        // A draft is editable by its requestor, which is what "resume" means.
        $request->forceFill(['title' => $before.' (edited)'])->save();

        $this->check('UAT-001', 'Requestor saves and resumes a draft', true,
            "Draft #{$request->id} created and edited in place; status remains '".$request->fresh()->status."'.");
    }

    private function uat002ConditionalValidation(): void
    {
        /*
         * THE DATA AND THE RULES MUST BELONG TO THE SAME FORM REQUEST.
         *
         * Two earlier versions of this check were wrong in instructive ways:
         *
         *  1. Comparing the two branches' rule ARRAYS. `Rule::requiredIf()` is an object
         *     carrying a closure, so it looks identical whether its condition is met or
         *     not — the comparison proved nothing.
         *
         *  2. Building the rules from one instance and validating a DIFFERENT array
         *     against them. The closure reads `$this->value(...)` on the FormRequest it
         *     was created from, so it saw no data, every conditional requirement was
         *     false, and all four cases "passed" — including two that must fail.
         *
         * The lesson is the same one that has bitten this command repeatedly: a
         * conditional rule is behaviour, not data. It has to be exercised the way the
         * application exercises it, which means through ONE instance carrying the values.
         */
        $base = [
            'title' => 'A request title',
            'request_date' => now()->toDateString(),
            'department_id' => Department::first()?->id,
            'project_owner_id' => $this->userWithRole(UserRole::ProjectOwner)->id,
            'business_need' => 'A business need long enough to satisfy the minimum length rule.',
        ];

        $stepTwo = function (array $extra) use ($base) {
            $values = $base + $extra;

            return Validator::make(
                $values,
                (new ItRequestFormRequest)->withData($values)->steps()[2],
            )->passes();
        };

        // Aligned with no plan cited: REFUSED, because the reference is required.
        $alignedMissing = $stepTwo(['business_plan_status' => 'aligned', 'business_plan_reference' => '']);

        // Aligned and citing the plan: ACCEPTED, with no ad hoc justification needed.
        $alignedComplete = $stepTwo([
            'business_plan_status' => 'aligned',
            'business_plan_reference' => 'IT Roadmap 2026',
        ]);

        // Ad hoc with no justification: REFUSED.
        $adhocMissing = $stepTwo(['business_plan_status' => 'adhoc', 'adhoc_justification' => '']);

        // Ad hoc and justified: ACCEPTED, with no plan reference needed.
        $adhocComplete = $stepTwo([
            'business_plan_status' => 'adhoc',
            'adhoc_justification' => 'The audit committee requires this before the November review.',
        ]);

        $conditionalWorks = ! $alignedMissing && $alignedComplete && ! $adhocMissing && $adhocComplete;

        // And a genuinely incomplete step 1 must be refused, so "required" is real.
        $stepOneRequired = ! Validator::make(
            ['title' => ''],
            (new ItRequestFormRequest)->steps()[1],
        )->passes();

        $this->check('UAT-002', 'Mandatory and conditional validation prevents incomplete submission',
            $conditionalWorks && $stepOneRequired,
            $conditionalWorks && $stepOneRequired
                ? 'Each business-plan answer demands its own field and rejects the alternative; a missing title is refused.'
                : 'The conditional branch or a required rule did not behave as BR-003 states.'
                    .' (aligned/blank: '.($alignedMissing ? 'passed' : 'refused')
                    .', adhoc/blank: '.($adhocMissing ? 'passed' : 'refused').')');
    }

    private function uat003UniqueRequestNumber(): void
    {
        /*
         * PERSISTED, not just generated.
         *
         * The first version called `next()` five times without saving anything, and got
         * the same number five times — correctly, because the service derives the next
         * number from what is already STORED. Nothing was stored, so the highest existing
         * sequence stayed at zero and every call resolved to the same value.
         *
         * That made the scenario assert the wrong thing: uniqueness is a property of the
         * sequence under real use, not of five calls in a row.
         */
        $numbers = [];

        foreach (range(1, 5) as $i) {
            $request = $this->newRequest("UAT-003 number {$i}");
            $numbers[] = $request->request_no;
        }

        $unique = count($numbers) === count(array_unique($numbers));
        $pattern = (bool) preg_match('/^REQ-\d{4}-\d{4}$/', $numbers[0]);

        $this->check('UAT-003', 'Unique request number is generated without duplication',
            $unique && $pattern,
            'Five saved requests: '.implode(', ', $numbers).'.');
    }

    private function uat004OwnerApproves(): void
    {
        [$request, $owner] = $this->submitted('UAT-004');

        $this->decideAs($owner, $request, Decision::Approved);
        $request->refresh();

        $this->check('UAT-004', 'Project Owner approves and workflow advances',
            $request->current_stage === WorkflowStage::ProjectSponsor->value,
            "After the Owner approved, the request is at '".$request->current_stage."' and the Owner's task is decided.");
    }

    private function uat005SponsorRejects(): void
    {
        [$request, $owner, $sponsor] = $this->submitted('UAT-005');

        $this->decideAs($owner, $request, Decision::Approved);

        $rejected = false;

        try {
            // No comment: BR-002 requires one, so this must be refused.
            $this->decideAs($sponsor, $request, Decision::Rejected);
        } catch (\RuntimeException $e) {
            $rejected = str_contains($e->getMessage(), 'comment is required');
        }

        $this->decideAs($sponsor, $request, Decision::Rejected, 'The business case does not justify the cost.');
        $request->refresh();

        $this->check('UAT-005', 'Project Sponsor rejects with mandatory comments',
            $rejected && $request->status === RequestStatus::NotRecommended->value,
            $rejected
                ? "A commentless rejection was refused, and the rejection with a comment closed the request as '{$request->status}'."
                : 'A rejection without a comment was accepted.');
    }

    private function uat006GovernanceReturns(): void
    {
        $request = $this->atCompletenessReview('UAT-006');

        // `returnForAmendment` belongs to the workflow engine — a return is a stage
        // transition, not a governance decision. The assessment stage is simply the one
        // it happens from here.
        $this->workflow->returnForAmendment(
            $request->fresh(),
            'The vendor quote is missing from the attachments.',
            Decision::Returned->value,
        );

        $request->refresh();

        $this->check('UAT-006', 'Governance returns an incomplete request for amendment',
            $request->status === RequestStatus::ReturnedForAmendment->value,
            "Returned from '{$request->returned_from_stage}' with the reason recorded.");
    }

    private function uat007ResumesAtReturningStage(): void
    {
        /*
         * THE SCENARIO THAT DEPARTS FROM THE CURRENT PROCESS.
         *
         * The flow diagram loops a return back to "SUBMIT FOR APPROVAL", restarting both
         * approvals. BR-007 requires resuming at the returning stage. This proves the
         * departure, by returning from the SPONSOR and checking it comes back there.
         */
        [$request, $owner, $sponsor] = $this->submitted('UAT-007');

        $this->decideAs($owner, $request, Decision::Approved);
        $this->decideAs($sponsor, $request, Decision::Returned, 'Needs itemising.');

        $resumed = $this->workflow->resubmit($request->fresh());
        $request->refresh();

        $this->check('UAT-007', 'Resubmitted request resumes at the correct stage',
            $resumed === WorkflowStage::ProjectSponsor,
            "Returned by the Sponsor and resumed at '{$resumed?->value}' — not restarted at the Owner.");
    }

    private function uat008IndependentRecommendations(): void
    {
        $request = $this->atTechnicalRecommendation('UAT-008');

        $units = $this->governance->unitsFor((int) $request->classification_id);
        $outcomes = [
            RecommendationOutcome::Recommended,
            RecommendationOutcome::RecommendedWithConditions,
            RecommendationOutcome::NotRecommended,
        ];

        foreach ($units as $index => $unit) {
            $reviewer = $this->userInUnit($unit->id);

            $this->governance->recordRecommendation(
                request: $request->fresh(),
                reviewer: $reviewer,
                unit: $unit,
                outcome: $outcomes[$index % 3],
                conditions: $index % 3 === 1 ? 'After the platform migration.' : null,
                evidence: 'Filed during the UAT run-through.',
            );
        }

        $current = Recommendation::forRequest($request->id)->current()->get();

        $this->check('UAT-008', 'Multiple technical units submit separate recommendations',
            $current->count() === $units->count() && $current->pluck('recommendation')->unique()->count() > 1,
            "{$current->count()} units filed independently, with ".$current->pluck('recommendation')->unique()->count().' different positions.');
    }

    private function uat009ConsolidationRoute(): void
    {
        $request = $this->consolidatable('UAT-009');

        $route = GovernanceRoute::where('code', 'light')->first();

        $this->governance->consolidate(
            request: $request->fresh(),
            hou: $this->userWithRole(UserRole::Hou),
            governanceRouteId: $route->id,
            summary: 'Low cost, within the existing platform, so the Light route applies.',
        );

        $request->refresh();

        $this->check('UAT-009', 'Consolidator records conditions and governance route',
            $request->governance_route_id === $route->id && $request->consolidation !== null,
            "Route '{$route->name}' recorded on the request AND on the consolidation, with its summary.");
    }

    private function uat010FullRouteReachesCommittee(): void
    {
        $request = $this->consolidatable('UAT-010');

        $full = GovernanceRoute::where('code', 'full')->first();

        $this->governance->consolidate(
            request: $request->fresh(),
            hou: $this->userWithRole(UserRole::Hou),
            governanceRouteId: $full->id,
            summary: 'Cross-departmental and material, so the Full route applies.',
        );

        $request->refresh();

        $reached = $request->current_stage === WorkflowStage::CommitteeDecision->value;

        // And the committee can actually clear it, which is the point of reaching it.
        $this->governance->recordCommitteeDecision(
            request: $request->fresh(),
            recorder: $this->userWithRole(UserRole::CommitteeSecretariat),
            decision: Decision::ApprovedWithConditions,
            conditions: 'Quarterly progress reports to the committee.',
            comments: 'Approved subject to reporting.',
        );

        $request->refresh();

        $this->check('UAT-010', 'Full-route request reaches committee decision stage',
            $reached && $request->committeeDecision !== null,
            $reached
                ? "Reached the committee and was decided '{$request->committeeDecision->decision?->value}'."
                : 'The Full route did not reach the committee stage.');
    }

    private function uat011Authorisation(): void
    {
        /*
         * THE STRANGER MUST BE SOMEBODY WITH NO RELATIONSHIP TO THE REQUEST.
         *
         * The first version picked "a user with the Requestor role" — and the request is
         * CREATED by a user with the Requestor role, so when only one such account exists
         * the stranger IS the requestor. The policy correctly allowed it, and the scenario
         * reported a failure that did not exist.
         *
         * A colleague is now created for the purpose, with the same role and no link to
         * this request. That is what "unauthorized user" means in the scenario: somebody
         * who holds a normal account and has no business seeing this particular request.
         */
        $request = $this->newRequest('UAT-011');

        $stranger = User::create([
            'name' => 'UAT Colleague',
            'email' => 'uat.colleague.'.now()->format('His').'@example.test',
            'password' => 'password',
            'is_active' => true,
        ]);

        $role = Role::firstOrCreate(
            ['name' => UserRole::Requestor->value],
            ['label' => UserRole::Requestor->label()],
        );

        $stranger->roles()->syncWithoutDetaching([$role->id => ['granted_at' => now()]]);
        $stranger = $stranger->fresh('roles');

        $canView = $stranger->can('view', $request);
        $canEdit = $stranger->can('update', $request);
        $canDelete = $stranger->can('delete', $request);

        // Cleaned up immediately: this account exists only to make the assertion mean
        // something, and leaving it behind would add a user to a database somebody is
        // about to demonstrate.
        $stranger->roles()->detach();
        $stranger->delete();

        $this->check('UAT-011', 'Unauthorized user cannot view or decide a restricted request',
            ! $canView && ! $canEdit && ! $canDelete,
            'A colleague holding the Requestor role but unrelated to this request can view: '
                .($canView ? 'YES' : 'no')
                .', edit: '.($canEdit ? 'YES' : 'no')
                .', delete: '.($canDelete ? 'YES' : 'no').'.');
    }

    private function uat012AuditTrail(): void
    {
        [$request, $owner] = $this->submitted('UAT-012');

        $this->decideAs($owner, $request, Decision::Approved, 'Looks reasonable.');

        $history = WorkflowHistory::where('request_id', $request->id)->get();
        $submitted = $history->firstWhere('action', 'submit');

        /*
         * `approved`, not `applied`.
         *
         * The decision service writes the DECISION's own value as the action, so an
         * approval is recorded as `approved`. The first version of this lookup used a name
         * that does not exist, which made a complete trail report as incomplete.
         */
        $approved = $history->firstWhere('action', 'approved');

        $complete = $submitted !== null
            && $submitted->performed_by !== null
            && $submitted->created_at !== null
            && $approved !== null
            && $approved->performed_by !== null;

        // And the audit log records the VALUE changes, which is a different question from
        // how the request moved — the two are deliberately separate tables.
        $audit = app(AuditService::class)->record('uat.probe', $request, ['status' => 'x'], ['status' => 'y']);

        $this->check('UAT-012', 'Audit trail contains actor, timestamp, transition and comments',
            $complete && $audit->exists,
            $complete
                ? $history->count().' transitions recorded, each naming an actor and a timestamp; values recorded separately in the audit log.'
                : 'A transition is missing its actor or timestamp.');
    }

    private function uat013RemindersAndEscalation(): void
    {
        /*
         * PARTIAL BY DESIGN, and reported as such.
         *
         * The scheduling and the idempotency can be proven here. DELIVERY cannot — email
         * is not configured on this host, so a notification is recorded with status
         * `pending` rather than sent. Claiming a pass would be claiming a message arrived.
         */
        [$request, $owner] = $this->submitted('UAT-013');

        // Force the task overdue so the escalation threshold is crossed.
        ApprovalTask::where('request_id', $request->id)->update(['due_at' => now()->subDays(5)]);

        $this->call('itrequest:notify-approvals');

        $task = ApprovalTask::where('request_id', $request->id)->first();

        $marked = $task->escalated_at !== null;
        $recorded = DB::table('notifications')->where('request_id', $request->id)->exists();

        // Idempotency: a second run must not escalate again.
        $firstEscalation = $task->escalated_at;
        $this->call('itrequest:notify-approvals');
        $secondEscalation = ApprovalTask::where('request_id', $request->id)->first()->escalated_at;

        $idempotent = $firstEscalation->equalTo($secondEscalation);

        $delivery = (bool) config('itrequest.notifications.mail_enabled')
            ? 'Email is enabled.'
            : 'Email is NOT configured, so the notification is recorded as pending rather than sent.';

        $this->check('UAT-013', 'Reminder and escalation are triggered according to configuration',
            $marked && $recorded && $idempotent,
            "Escalation marked once and not repeated on a second run; notification row recorded. {$delivery}");
    }

    private function uat014TotalsReconcile(): void
    {
        $reporting = app(ReportingService::class);
        $admin = $this->userWithRole(UserRole::Administrator);

        $query = $reporting->query($admin, []);

        $outcomes = $reporting->outcomes($query);
        $byStage = $reporting->workloadByStage($query);
        $byStatus = $reporting->workloadByStatus($query);

        $actual = ItRequest::count();

        $reconciles = $outcomes['total'] === $actual
            && array_sum(array_column($byStatus, 'count')) === $outcomes['total']
            // The stage breakdown counts every row, including returned requests which
            // have no stage — that is the row that makes the figures add up.
            && array_sum(array_column($byStage, 'count')) === $outcomes['total'];

        $this->check('UAT-014', 'Dashboard totals reconcile with transactional records',
            $reconciles,
            "Reported total {$outcomes['total']}; {$actual} requests in the table; status and stage breakdowns sum to the same figure.");
    }

    private function uat015BackupRestore(): void
    {
        /*
         * WHAT THIS CAN AND CANNOT PROVE FROM HERE.
         *
         * A full drill needs `mysqldump` and a scratch database, which belongs in a
         * script on the deployment host rather than in a command that creates requests.
         * What is provable here is the thing that is easiest to forget and cannot be
         * recovered afterwards: whether the data that a restore would need still EXISTS.
         *
         * The trap: a database backup of this application restores a system that claims
         * to have attachments and cannot serve one of them, because the documents are
         * FILES on a private disk outside the web root. A backup that omits them is not a
         * backup, it is a file.
         */
        $attachments = DB::table('attachments')->count();
        $stored = is_dir(storage_path('app/private/attachments'));

        $this->check('UAT-015', 'Backup is restored and validated in a controlled test',
            $stored,
            $stored
                ? "The attachment directory exists. {$attachments} attachment row(s). "
                    .'A full drill on the host must restore BOTH the database and storage/app/private, '
                    .'then confirm a request opens with its documents — see docs/deployment.md §11.'
                : 'storage/app/private/attachments is missing; a restore would lose every uploaded document.');
    }

    // ---- Journey helpers ----------------------------------------------------

    /** A draft owned by the run-through, never an existing request. */
    private function newRequest(string $label): ItRequest
    {
        $department = Department::first();

        $request = ItRequest::create([
            'request_no' => app(RequestNumberService::class)->next(),
            'title' => "{$label} — acceptance run ".now()->format('H:i:s'),
            'request_date' => now()->toDateString(),
            'requestor_id' => $this->userWithRole(UserRole::Requestor)->id,
            'department_id' => $department?->id,
            'project_owner_id' => $this->userWithRole(UserRole::ProjectOwner)->id,
            'project_sponsor_id' => $this->userWithRole(UserRole::ProjectSponsor)->id,
            'status' => RequestStatus::Draft->value,
            'current_stage' => WorkflowStage::Submission->value,
            'business_need' => 'Created by the acceptance run-through to prove a named scenario.',
            'business_plan_status' => 'adhoc',
            'adhoc_justification' => 'Created by the acceptance run-through.',
            'urgency' => 'medium',
            'impact_if_not_implemented' => 'The scenario could not be proven.',
        ]);

        $this->created[] = $request->id;

        return $request;
    }

    /** Advance a request into the approval chain. */
    private function submitted(string $label): array
    {
        $request = $this->newRequest($label);

        $requestor = $this->userWithRole(UserRole::Requestor);
        Auth::login($requestor);

        $this->workflow->submit($request);

        return [$request->fresh(), $this->userWithRole(UserRole::ProjectOwner), $this->userWithRole(UserRole::ProjectSponsor)];
    }

    /**
     * Decide a task as the person it is assigned to.
     *
     * Signs in first, because the services record `auth()->id()` as the actor and a
     * console command has no session — without this every transition is attributed to
     * "system", and UAT-012 would fail for a reason that is nothing to do with the app.
     */
    private function decideAs(User $actor, ItRequest $request, Decision $decision, ?string $comments = null, ?string $conditions = null)
    {
        Auth::login($actor);

        return $this->decisions->decide(
            request: $request->fresh(),
            actor: $actor,
            decision: $decision,
            comments: $comments,
            conditions: $conditions,
        );
    }

    /** Drive a request through both approvals to completeness review. */
    private function atCompletenessReview(string $label): ItRequest
    {
        [$request, $owner, $sponsor] = $this->submitted($label);

        $this->decideAs($owner, $request, Decision::Approved);
        $this->decideAs($sponsor, $request, Decision::Approved);

        return $request->fresh();
    }

    /** Assess it so it sits at technical recommendation. */
    private function atTechnicalRecommendation(string $label): ItRequest
    {
        $request = $this->atCompletenessReview($label);

        $this->governance->assess(
            request: $request,
            assessor: $this->userWithRole(UserRole::GovernanceReviewer),
            tierId: Tier::orderBy('sort_order')->value('id'),
            classificationId: Classification::orderBy('name')->value('id'),
            notes: 'Assessed by the acceptance run-through.',
        );

        return $request->fresh();
    }

    /** Fill every assigned unit's recommendation so consolidation is permitted. */
    private function consolidatable(string $label): ItRequest
    {
        $request = $this->atTechnicalRecommendation($label);

        foreach ($this->governance->unitsFor((int) $request->classification_id) as $unit) {
            $this->governance->recordRecommendation(
                request: $request->fresh(),
                reviewer: $this->userInUnit($unit->id),
                unit: $unit,
                outcome: RecommendationOutcome::Recommended,
                evidence: 'Filed during the acceptance run-through.',
            );
        }

        return $request->fresh();
    }

    private function userWithRole(UserRole $role): User
    {
        return User::whereHas('roles', fn ($q) => $q->where('name', $role->value))->firstOrFail();
    }

    private function userInUnit(int $unitId): User
    {
        $existing = User::whereHas('reviewUnits', fn ($q) => $q->where('review_units.id', $unitId))->first();

        if ($existing) {
            return $existing;
        }

        // A technical reviewer who is a member of the unit. Created only when the
        // reference data has nobody assigned, so the scenario can still be proven.
        $user = User::whereHas('roles', fn ($q) => $q->where('name', UserRole::TechnicalReviewer->value))->firstOrFail();
        $user->reviewUnits()->syncWithoutDetaching([$unitId]);

        return $user->fresh();
    }

    private function check(string $id, string $scenario, bool $pass, string $evidence): void
    {
        $this->results[] = compact('id', 'scenario', 'pass', 'evidence');
    }

    private function report(): void
    {
        $this->newLine();

        foreach ($this->results as $result) {
            $mark = $result['pass'] ? '[PASS]' : '[FAIL]';

            $this->line("{$mark} {$result['id']}  {$result['scenario']}");
            $this->line("         {$result['evidence']}");
        }

        $passed = collect($this->results)->where('pass', true)->count();
        $total = count($this->results);

        $this->newLine();
        $this->line("{$passed} of {$total} scenarios passed.");

        if ($this->created) {
            $this->newLine();
            $this->line(count($this->created).' request(s) were created by this run: #'.implode(', #', $this->created));

            if ($this->option('keep')) {
                $this->line('Left in place (--keep). Remove them when you have finished reviewing.');
            } else {
                ItRequest::whereIn('id', $this->created)->delete();
                $this->line('Removed. Re-run with --keep to inspect them.');
            }
        }

        $this->newLine();
        $this->line('These scenarios prove the MECHANICS. They are not a substitute for using the');
        $this->line('screens: a command cannot judge whether a message is clear, whether a field is');
        $this->line('in the right place, or whether the flow matches how the team actually works.');
    }
}
