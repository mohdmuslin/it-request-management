<?php

use App\Enums\UserRole;
use App\Http\Requests\ItRequestFormRequest;
use App\Models\Department;
use App\Models\ItRequest;
use App\Models\WorkflowHistory;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Validator;

/**
 * The UAT run-through.
 *
 * The command asserts fifteen acceptance scenarios, so a bug in the command is a false
 * report about the application — in either direction, and this session produced both:
 *
 *  - Four scenarios reported failures that did not exist, because the CHECK was wrong.
 *  - One scenario reported a pass that was meaningless, because the check built the rules
 *    from one object and validated a different one — so every conditional requirement read
 *    as satisfied and two cases that MUST fail passed.
 *
 * A false pass is the dangerous direction: it is the one nobody follows up.
 */
beforeEach(function () {
    /*
     * The command walks real journeys, so it needs a real cast: a requestor, an owner, a
     * sponsor, a governance reviewer, an HOU and a secretariat. Those are the demo accounts.
     *
     * `DemoUserSeeder` refuses outside local/testing — which the test environment is — so
     * this is the same seeder a developer gets locally, and the application's own guard
     * still stops it ever running on a live site.
     */
    $this->seed(ReferenceDataSeeder::class);
    $this->seed(DemoUserSeeder::class);

    Department::create(['code' => 'ICT', 'name' => 'Information Technology']);
    asUser(UserRole::Administrator);
});

it('passes every acceptance scenario', function () {
    // The headline assertion. If this fails, the output names the scenario and its evidence.
    $this->artisan('itrequest:uat')->assertSuccessful();
});

it('reports all fifteen scenarios rather than stopping at the first failure', function () {
    /*
     * One fault per run would mean fifteen runs to find fifteen problems. The command
     * collects the results and reports them together.
     */
    Artisan::call('itrequest:uat');

    $output = Artisan::output();

    foreach (range(1, 15) as $n) {
        expect($output)->toContain(sprintf('UAT-%03d', $n));
    }
});

it('cleans up the requests it created', function () {
    /*
     * The command creates real requests to prove real journeys. Leaving them behind would
     * add noise to a database somebody is about to demonstrate, and the next run would
     * start from a state the first run produced.
     */
    $before = ItRequest::count();

    Artisan::call('itrequest:uat');

    expect(ItRequest::count())->toBe($before);
});

it('leaves the requests in place when asked to', function () {
    // `--keep` exists so the evidence can be inspected in the screens afterwards.
    $before = ItRequest::count();

    Artisan::call('itrequest:uat', ['--keep' => true]);
    $during = ItRequest::count();

    expect($during)->toBeGreaterThan($before);

    // Tidy up, so the test does not change what the next one sees.
    ItRequest::where('title', 'like', 'UAT-%')->delete();
});

it('refuses to run outside local or testing', function () {
    /*
     * It creates requests and deletes them. On a live site that is a data-loss command
     * wearing an acceptance test's name.
     */
    $this->app->detectEnvironment(fn () => 'production');

    $this->artisan('itrequest:uat')->assertFailed();
});

it('exercises the conditional validation branch against real submissions', function () {
    /*
     * The specific scenario that was reported as a false pass.
     *
     * `Rule::requiredIf()` reads its condition from the FormRequest INSTANCE, not from the
     * array handed to `Validator::make()`. Building the rules from a fresh instance and
     * validating a different array made every conditional requirement false, so "aligned
     * with no plan cited" — which BR-003 says must be refused — passed.
     *
     * Asserted here directly, so a future change to the check cannot silently stop testing
     * the thing it claims to test.
     */
    $base = [
        'title' => 'A request title',
        'request_date' => now()->toDateString(),
        'department_id' => Department::first()->id,
        'project_owner_id' => asUser(UserRole::ProjectOwner)->id,
        'business_need' => 'A business need long enough to satisfy the minimum length rule.',
    ];

    $passes = function (array $extra) use ($base) {
        $values = $base + $extra;

        return Validator::make(
            $values,
            (new ItRequestFormRequest)->withData($values)->steps()[2],
        )->passes();
    };

    // An aligned request MUST cite the plan.
    expect($passes(['business_plan_status' => 'aligned', 'business_plan_reference' => '']))->toBeFalse()
        ->and($passes([
            'business_plan_status' => 'aligned',
            'business_plan_reference' => 'IT Roadmap 2026',
        ]))->toBeTrue();

    // An ad hoc request MUST justify itself.
    expect($passes(['business_plan_status' => 'adhoc', 'adhoc_justification' => '']))->toBeFalse()
        ->and($passes([
            'business_plan_status' => 'adhoc',
            'adhoc_justification' => 'The audit committee requires this before the November review.',
        ]))->toBeTrue();
});

it('records a named actor on the transitions it produces', function () {
    /*
     * The services record `auth()->id()` as the actor, and a console command has no
     * session — so without signing in, every transition this run produces is attributed to
     * "system". UAT-012 exists to prove the trail names an actor, and would otherwise fail
     * on a trail that is complete, just anonymous.
     */
    Artisan::call('itrequest:uat', ['--keep' => true]);

    $request = ItRequest::where('title', 'like', 'UAT-012%')->first();

    expect($request)->not->toBeNull();

    $transitions = WorkflowHistory::where('request_id', $request->id)->get();

    expect($transitions)->not->toBeEmpty()
        ->and($transitions->whereNotNull('performed_by'))->not->toBeEmpty();

    ItRequest::where('title', 'like', 'UAT-%')->delete();
});
