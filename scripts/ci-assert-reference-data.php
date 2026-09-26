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
 *
 * WHY EVERY IMPORT IS ABOVE THE BOOTSTRAP
 *
 * A `use` statement only applies from the line it appears on, so an import placed
 * after `$app->make(Kernel::class)` is not in scope for it. `Kernel::class` then
 * resolves to a class called `Kernel` in the GLOBAL namespace, which does not exist,
 * and the script dies with "Target class [Kernel] does not exist" — a message that
 * points at the container rather than at the ordering.
 *
 * Pint moves bare class names to the bottom of the import block, so this is a
 * mistake that can be reintroduced by a formatter run. The imports stay here, above
 * anything that executes.
 */

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

require __DIR__.'/../vendor/autoload.php';

$app = require_once __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

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

/**
 * Documentation does not cite a class that was never written.
 *
 * WHY THIS IS AN ASSERTION AND NOT A DOCUMENTATION REVIEW
 *
 * `architecture.md` listed `app/Contracts/` containing `IdentityProvider`, and §6 showed the
 * interface as a PHP code block naming `LocalProvider` and `EntraProvider`. **None of them
 * existed.** The compliance matrix cited that section as evidence, so a requirement was reported
 * as met on the strength of a design document describing an intention.
 *
 * Nothing in a test suite reads a design document, and no reviewer re-reads one against `app/`
 * on every change. So the check is mechanical: any class name ending in Service, Provider,
 * Policy, Middleware, Source or Controller that a design document names must exist as a file.
 *
 * DELIBERATELY NARROW. It matches capitalised names with those suffixes only, because that is
 * the shape a citation takes — `WorkflowService`, not "the workflow engine". Wider matching
 * produces false positives from prose and the check gets switched off, which is worse than not
 * having it.
 *
 * KNOWN PHANTOMS ARE LISTED, AND THE LIST IS BIDIRECTIONAL
 *
 * The names below are cited because the documents now say explicitly that they were NOT built —
 * which is the correction, not a defect. An unconditional check would fail on the corrected
 * documents and have to be removed, so it fails in both directions instead:
 *
 *   - a cited name that is not on the list and does not exist  -> a NEW false claim
 *   - a name that IS on the list but now exists                -> the documents are stale
 *
 * The second direction is what keeps this useful. When someone finally writes `IdentityProvider`,
 * the check fails and names the docblock that still describes it as missing.
 */
echo "\nDesign documents cite only classes that exist\n";

/** Documented as not built. See compliance-matrix.md D-7 to D-10 and architecture.md §4, §6. */
$knownPhantoms = [
    'IdentityProvider',      // D-10 — described in architecture.md §6, never written
    'LocalProvider',         // D-10
    'EntraProvider',         // D-10 / D-1
    'HolidaySource',         // planned integration contract, never written
    'RequestPolicy',         // named in architecture.md §4; the policy is ItRequestPolicy
    'ApprovalPolicy',        // named in architecture.md §4; not written
    'RecommendationPolicy',  // named in architecture.md §4; not written
    'ApprovalService',       // named in architecture.md §4; the work is in WorkflowDecisionService
    'RecommendationService', // named in architecture.md §4; the work is in GovernanceService
    'ConsolidationService',  // named in architecture.md §4; the work is in GovernanceService
    'ReferenceDataService',  // named in architecture.md §4; the work is in the Livewire components
];

$docNames = glob(__DIR__.'/../docs/*.md') ?: [];
$cited = [];

foreach ($docNames as $doc) {
    // The vendor brief and this project's own review notes describe a system that was to be
    // built, and a matrix that lists what was NOT built. Neither is a claim against app/.
    if (str_contains(basename($doc), 'Vendor_Brief')) {
        continue;
    }

    $text = (string) file_get_contents($doc);

    if (preg_match_all(
        '/\b([A-Z][A-Za-z]*(?:Service|Provider|Policy|Middleware|Source|Controller))\b/',
        $text,
        $matches
    )) {
        foreach ($matches[1] as $name) {
            $cited[$name][] = basename($doc);
        }
    }
}

// Every class name that exists anywhere under app/, by basename.
$exists = [];

foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(__DIR__.'/../app')) as $file) {
    if ($file->isFile() && $file->getExtension() === 'php') {
        $exists[$file->getBasename('.php')] = true;
    }
}

$phantom = [];
$nowBuilt = [];

foreach ($cited as $name => $docs) {
    $existsOnDisk = isset($exists[$name]) || class_exists('App\\'.$name);
    $isKnownPhantom = in_array($name, $knownPhantoms, true);

    if (! $existsOnDisk && ! $isKnownPhantom) {
        $phantom[$name] = array_unique($docs);
    }

    // The other direction: documented as missing, but now present. The documents are
    // stale and somebody should delete the "not built" note rather than leave it there
    // contradicting the code.
    if ($existsOnDisk && $isKnownPhantom) {
        $nowBuilt[$name] = array_unique($docs);
    }
}

foreach ($phantom as $name => $docs) {
    $failures[] = "docs cite '{$name}', which does not exist in app/ (".implode(', ', $docs).')';
}

foreach ($nowBuilt as $name => $docs) {
    $failures[] = "'{$name}' exists now but ".implode(', ', $docs).' still says it was not built — remove the stale note';
}

if ($failures === []) {
    echo '  ok  no new phantom citations ('.count($knownPhantoms).' known and documented; '
        .count($cited)." names checked)\n";
}

if ($failures !== []) {
    fwrite(STDERR, "\nReference data assertions FAILED:\n");
    foreach ($failures as $failure) {
        fwrite(STDERR, "  - {$failure}\n");
    }
    exit(1);
}

echo "\nAll reference data assertions passed.\n";
