<?php

/*
 * Drive a request to a given workflow stage, for browser walks.
 *
 * A dev helper, not part of the application. Walking the real UI through five stages
 * to reach the screen you want to look at costs minutes per attempt; this gets a
 * request to the stage being examined so the SCREEN can be reviewed.
 *
 * Usage:
 *   php scripts/dev-advance-request.php {requestId} {stage}
 *
 * Stages: completeness_review | technical_recommendation | consolidation |
 *         committee_decision | closure
 */

use App\Enums\Decision;
use App\Enums\RecommendationOutcome;
use App\Enums\UserRole;
use App\Enums\WorkflowStage;
use App\Models\Classification;
use App\Models\GovernanceRoute;
use App\Models\ItRequest;
use App\Models\Tier;
use App\Models\User;
use App\Services\GovernanceService;
use App\Services\WorkflowDecisionService;
use App\Services\WorkflowService;
use Illuminate\Contracts\Console\Kernel;

/*
 * EVERY import must appear ABOVE this point.
 *
 * A `use` statement only applies from the line it appears on, so an import placed
 * after the bootstrap does nothing — and Pint's `fully_qualified_strict_types` fixer
 * actively moves a bare class name to the import block at the BOTTOM of a file, which
 * leaves the code referring to a class the file never imported. The symptom is
 * `Class "Kernel" does not exist` from deep inside the container, which points at the
 * framework rather than at the file.
 */

require __DIR__.'/../vendor/autoload.php';

$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$id = (int) ($argv[1] ?? 0);
$target = $argv[2] ?? 'completeness_review';

$request = ItRequest::find($id);

if (! $request) {
    fwrite(STDERR, "No request with id {$id}\n");
    exit(1);
}

if (! app()->environment(['local', 'testing'])) {
    fwrite(STDERR, "This script only runs in local/testing.\n");
    exit(1);
}

$workflow = app(WorkflowService::class);
$decisions = app(WorkflowDecisionService::class);
$governance = app(GovernanceService::class);

$userFor = fn (UserRole $role) => User::whereHas('roles', fn ($q) => $q->where('name', $role->value))->firstOrFail();

$owner = $userFor(UserRole::ProjectOwner);
$sponsor = $userFor(UserRole::ProjectSponsor);
$reviewer = $userFor(UserRole::GovernanceReviewer);
$hou = $userFor(UserRole::Hou);

auth()->login($reviewer);   // a real actor, so history rows are not null

/**
 * Walk a request through the two approval stages.
 *
 * EACH STEP NAMES THE STAGE IT BELONGS TO.
 *
 * The first version looped "while not yet at completeness review, decide whatever task
 * is pending". Run against a request that had ALREADY reached the committee, that loop
 * decided the committee's task — because it was pending — and left the request in a
 * state no real sequence produces: consolidated before its recommendations existed.
 *
 * Guarding on the stage rather than on "is there a task?" is the fix. A pending task is
 * not evidence that its stage is the next one due.
 */
$advanceApprovals = function () use ($request, $workflow, $decisions) {
    if ($request->fresh()->current_stage === WorkflowStage::Submission->value) {
        $workflow->submit($request);
        echo "  submitted\n";
    }

    foreach ([WorkflowStage::ProjectOwner, WorkflowStage::ProjectSponsor] as $stage) {
        $fresh = $request->fresh();

        // Already past this stage — do not re-decide it.
        if ($fresh->current_stage !== $stage->value) {
            break;
        }

        $task = $fresh->pendingApprovalTask;

        if (! $task) {
            break;
        }

        // The task names its approver, so act as that person rather than guessing.
        $actor = User::find($task->approver_id);

        if (! $actor) {
            fwrite(STDERR, "  no approver for the task at {$stage->value}; stopping.\n");

            break;
        }

        auth()->login($actor);
        $decisions->decide($fresh, $actor, Decision::Approved);

        echo "  approved at {$stage->label()} by {$actor->name}\n";
    }
};

$order = [
    'submission' => -1,
    'completeness_review' => 0,
    'technical_recommendation' => 1,
    'consolidation' => 2,
    'committee_decision' => 3,
    'closure' => 4,
];

if (! array_key_exists($target, $order)) {
    fwrite(STDERR, "Unknown stage '{$target}'. Valid: ".implode(', ', array_keys($order))."\n");
    exit(1);
}

$want = $order[$target];

/*
 * Refuse to walk BACKWARDS.
 *
 * Re-running this script against a request that has already moved on would decide
 * whatever task happens to be pending and leave the request in a state no real
 * sequence produces. Comparing the request's current position against the requested
 * one catches that before anything is written.
 */
$currentOrder = $order[$request->current_stage ?? ''] ?? null;

if ($currentOrder !== null && $currentOrder > $want) {
    fwrite(STDERR, "{$request->request_no} is already at '{$request->current_stage}', "
        ."which is past '{$target}'. Refusing to walk it backwards.\n");
    exit(1);
}

echo "Advancing {$request->request_no} to {$target}\n";

// 1. Through both approvals.
$advanceApprovals();

$at = fn () => $request->fresh()->current_stage;

if ($want >= 1 && $at() === WorkflowStage::CompletenessReview->value) {
    $tier = Tier::orderBy('sort_order')->first();
    $classification = Classification::orderBy('name')->first();

    auth()->login($reviewer);

    // A reason is required when the values differ from the proposal, so the
    // classification is deliberately changed here to exercise that path.
    $governance->assess(
        request: $request->fresh(),
        assessor: $reviewer,
        tierId: $tier->id,
        classificationId: $classification->id,
        reclassificationReason: 'Reclassified during completeness review: the request is a new system rather than an enhancement.',
        notes: 'Documents complete. Vendor quote attached.',
    );

    echo "  assessed (tier {$tier->id}, classification {$classification->id})\n";
}

if ($want >= 2 && $at() === WorkflowStage::TechnicalRecommendation->value) {
    $fresh = $request->fresh();

    foreach ($governance->unitsFor((int) $fresh->classification_id) as $index => $unit) {
        $u = User::whereHas('reviewUnits', fn ($q) => $q->where('review_units.id', $unit->id))->first();

        if (! $u) {
            // Assign a technical reviewer to the unit so the walk can proceed.
            $u = User::whereHas('roles', fn ($q) => $q->where('name', UserRole::TechnicalReviewer->value))->firstOrFail();
            $u->reviewUnits()->syncWithoutDetaching([$unit->id]);
        }

        auth()->login($u);

        // Deliberately mixed: one unit raises a concern, so the consolidation screen
        // has something real to weigh.
        [$outcome, $conditions, $evidence] = match ($index) {
            0 => [RecommendationOutcome::Recommended, null, 'No operational objection. Existing platform capacity is sufficient.'],
            1 => [RecommendationOutcome::RecommendedWithConditions, 'Implementation must be scheduled after the platform migration completes in December.', 'The current platform cannot absorb this load before the migration.'],
            default => [RecommendationOutcome::NotRecommended, null, 'The stock reconciliation benefit is real, but the estimated integration effort has been underestimated by roughly half.'],
        };

        $governance->recordRecommendation(
            request: $request->fresh(),
            reviewer: $u,
            unit: $unit,
            outcome: $outcome,
            conditions: $conditions,
            evidence: $evidence,
        );

        echo "  recommendation from {$unit->name}: {$outcome->value}\n";
    }
}

if ($want >= 3 && $at() === WorkflowStage::Consolidation->value) {
    auth()->login($hou);

    $route = GovernanceRoute::where('code', $want >= 3 ? 'full' : 'light')->first();

    $governance->consolidate(
        request: $request->fresh(),
        hou: $hou,
        governanceRouteId: $route->id,
        summary: 'Cross-departmental impact and material spend. One unit advises against on effort grounds; the majority view is that the control weakness makes the work necessary. Full route recommended.',
    );

    echo "  consolidated to route {$route->code}\n";
}

$final = $request->fresh();

echo "\nDone. {$final->request_no}: stage={$final->current_stage} status={$final->status}\n";
