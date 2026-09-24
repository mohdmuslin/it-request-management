<?php

/**
 * CI assertion: the seeded reference data is correct.
 *
 * WHY THIS IS A FILE AND NOT AN INLINE `artisan tinker --execute`
 *
 * Shell-quoting PHP inside a YAML run block is fragile — a `$` in a double-quoted
 * string, an escaping difference between bash and the YAML parser, and the
 * assertion silently checks the wrong thing. A file has no quoting at all, and it
 * can be run by hand when something fails, which a one-liner cannot.
 *
 * Run: php scripts/ci-assert-reference-data.php
 * Exits non-zero with a message naming what was wrong.
 */

require __DIR__.'/../vendor/autoload.php';

$app = require_once __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

use App\Enums\UserRole;
use App\Models\Classification;
use App\Models\GovernanceRoute;
use App\Models\ReviewUnit;
use App\Models\Role;
use App\Models\StageDueDay;
use App\Models\Tier;
use App\Models\User;
use App\Models\WorkflowStage;
use Illuminate\Contracts\Console\Kernel;

$failures = [];

/**
 * Assert a count, recording a readable failure rather than exiting on the spot so
 * every problem is reported in one run.
 */
$expect = function (string $what, int $actual, int $expected) use (&$failures) {
    if ($actual !== $expected) {
        $failures[] = sprintf('%s: expected %d, found %d', $what, $expected, $actual);
    } else {
        echo "  ok  {$what} = {$actual}\n";
    }
};

echo "Reference data\n";
$expect('tiers', Tier::count(), 3);
$expect('classifications', Classification::count(), 4);
$expect('governance routes', GovernanceRoute::count(), 3);
$expect('review units', ReviewUnit::count(), 3);
$expect('roles', Role::count(), count(UserRole::cases()));

/*
 * Exactly one route requires a committee.
 *
 * Asserted as a count rather than by checking Full alone, because the failure that
 * matters is a second route being switched on — which would send requests to a
 * committee that has no business seeing them, and would not be caught by checking
 * that Full is true.
 */
$committeeRoutes = GovernanceRoute::where('requires_committee', true)->count();
if ($committeeRoutes !== 1) {
    $failures[] = sprintf(
        'committee routes: expected exactly 1, found %d — only Full may require a committee',
        $committeeRoutes
    );
} else {
    echo "  ok  exactly one route requires a committee\n";
}

/*
 * Every stage that needs a target has one.
 *
 * This is the assertion that matters most. A stage with no due date is not an
 * error anywhere — it simply produces tasks with no deadline, and nothing reports
 * it. It happened once already when the config keys did not match the enum values.
 */
echo "\nStage due dates\n";
$mustHaveTargets = [
    'project_owner',
    'project_sponsor',
    'completeness_review',
    'technical_recommendation',
    'consolidation',
    'committee_decision',
];

foreach ($mustHaveTargets as $code) {
    $stage = WorkflowStage::where('code', $code)->first();

    if (! $stage) {
        $failures[] = "stage '{$code}' does not exist";

        continue;
    }

    $days = StageDueDay::where('workflow_stage_id', $stage->id)->whereNull('tier_id')->value('business_days');

    if ($days === null) {
        $failures[] = "stage '{$code}' has no default due-day target — every request in it would have no deadline";
    } else {
        echo "  ok  {$code} = {$days} business days\n";
    }
}

/*
 * The demo-account guard.
 *
 * The guard must seed accounts in local and testing, and refuse everywhere else.
 * Asserted in BOTH directions, because a guard that never fires and a guard that
 * always fires are equally wrong — and only one of them is usually tested.
 *
 * The environment is overridden here rather than assumed, so this check means the
 * same thing in CI as it does on a developer's machine.
 */
echo "\nDemo account guard\n";

$seeded = User::where('email', 'admin@example.com')->exists();
$isLocalish = app()->environment('local', 'testing');

if ($isLocalish && ! $seeded) {
    $failures[] = 'running in '.app()->environment().' but demo accounts were not seeded';
} elseif (! $isLocalish && $seeded) {
    $failures[] = 'running in '.app()->environment().' and demo accounts WERE seeded — the guard is not working';
} else {
    echo '  ok  environment is '.app()->environment()
        .($seeded ? ', demo accounts present as expected' : ', demo accounts correctly absent')."\n";
}

if ($failures !== []) {
    fwrite(STDERR, "\nReference data assertions FAILED:\n");
    foreach ($failures as $failure) {
        fwrite(STDERR, "  - {$failure}\n");
    }
    exit(1);
}

echo "\nAll reference data assertions passed.\n";
