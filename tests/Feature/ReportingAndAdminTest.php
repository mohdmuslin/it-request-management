<?php

use App\Enums\RequestStatus;
use App\Enums\UserRole;
use App\Enums\WorkflowStage;
use App\Livewire\Admin\ReferenceData;
use App\Livewire\Admin\Settings;
use App\Livewire\Admin\Users;
use App\Livewire\Reports\Index as ReportsIndex;
use App\Models\Department;
use App\Models\GovernanceRoute;
use App\Models\Holiday;
use App\Models\ItRequest;
use App\Models\Role;
use App\Models\StageDueDay;
use App\Models\Tier;
use App\Models\User;
use App\Models\WorkflowStage as WorkflowStageModel;
use App\Services\BusinessCalendar;
use App\Services\ReportingService;
use App\Services\RequestNumberService;
use Carbon\CarbonImmutable;
use Database\Seeders\ReferenceDataSeeder;
use Livewire\Livewire;

/**
 * Phase G — reporting and administration.
 *
 * The reporting tests assert RECONCILIATION (UAT-014): a total must equal the rows it
 * summarises, and the export must contain the same set the screen showed. A reporting
 * bug is invisible in the usual way — a wrong number looks like a number.
 */
beforeEach(function () {
    $this->seed(ReferenceDataSeeder::class);

    $this->admin = asUser(UserRole::Administrator);
    $this->requestor = asUser(UserRole::Requestor);
    $this->owner = asUser(UserRole::ProjectOwner);

    $this->department = Department::create(['code' => 'ICT', 'name' => 'Information Technology']);
});

function seedRequests(int $count, array $overrides = []): void
{
    foreach (range(1, $count) as $i) {
        ItRequest::create(array_merge([
            'request_no' => app(RequestNumberService::class)->next(),
            'title' => "Request {$i}",
            'request_date' => now()->toDateString(),
            'requestor_id' => test()->requestor->id,
            'department_id' => test()->department->id,
            'project_owner_id' => test()->owner->id,
            'status' => RequestStatus::Draft->value,
            'current_stage' => WorkflowStage::Submission->value,
            'business_need' => 'Testing.',
        ], $overrides));
    }
}

// ---- Reports screen ---------------------------------------------------------

it('opens the report for an administrator', function () {
    $this->actingAs($this->admin);

    Livewire::test(ReportsIndex::class)
        ->assertOk()
        ->assertSee('Turnaround for closed requests');
});

it('opens the report for an auditor, who reads everything', function () {
    // One of the few screens an auditor can reach, and it writes nothing.
    $this->actingAs(asUser(UserRole::Auditor));

    Livewire::test(ReportsIndex::class)->assertOk();
});

it('defaults to the current year rather than everything', function () {
    // An unfiltered report over a table that grows forever gets slower every month.
    $this->actingAs($this->admin);

    $component = Livewire::test(ReportsIndex::class);

    expect($component->get('from'))->toBe(now()->startOfYear()->toDateString())
        ->and($component->get('to'))->toBe(now()->toDateString());
});

it('shows a total that matches the rows it summarises', function () {
    /*
     * UAT-014, asserted on the SCREEN rather than only in the service.
     *
     * Every figure comes from one query, so the totals and the table cannot disagree.
     */
    seedRequests(4);
    $this->actingAs($this->admin);

    $component = Livewire::test(ReportsIndex::class);

    expect($component->viewData('outcomes')['total'])->toBe(4)
        ->and($component->viewData('requests'))->toHaveCount(4)
        ->and(array_sum(array_column($component->viewData('byStage'), 'count')))->toBe(4);
});

it('narrows the totals when a filter is applied', function () {
    seedRequests(3);

    $other = Department::create(['code' => 'OPS', 'name' => 'Operations']);
    seedRequests(2, ['department_id' => $other->id]);

    $this->actingAs($this->admin);

    $component = Livewire::test(ReportsIndex::class)
        ->set('department_id', $this->department->id);

    expect($component->viewData('outcomes')['total'])->toBe(3)
        ->and($component->viewData('requests'))->toHaveCount(3);
});

it('clears the filters back to the default window', function () {
    $this->actingAs($this->admin);

    Livewire::test(ReportsIndex::class)
        ->set('status', RequestStatus::Draft->value)
        ->set('search', 'anything')
        ->call('clearFilters')
        ->assertSet('status', '')
        ->assertSet('search', '')
        ->assertSet('from', now()->startOfYear()->toDateString());
});

it('shows no turnaround rather than zero when nothing is closed', function () {
    // "0 hours" reads as "we are instant", which is the opposite of "nothing is done".
    seedRequests(2);
    $this->actingAs($this->admin);

    Livewire::test(ReportsIndex::class)
        ->assertSee('No requests have been closed within these filters');
});

it('counts only the requests the signed-in user can see', function () {
    // A report is a list, and a list is where a leak is easiest.
    seedRequests(3, ['requestor_id' => $this->owner->id]);

    $this->actingAs($this->requestor);

    expect(Livewire::test(ReportsIndex::class)->viewData('outcomes')['total'])->toBe(0);
});

// ---- The export -------------------------------------------------------------

it('exports the filtered set, not everything', function () {
    /*
     * THE RECONCILIATION REQUIREMENT IN PRACTICE.
     *
     * The export takes the same parameters the report puts in its URL and applies them
     * through the same filter method — so the file contains the set the screen showed.
     * An export with its own filtering would produce totals that disagree with the
     * screen, and the recipient has no way to know which is wrong.
     */
    seedRequests(2);

    $other = Department::create(['code' => 'OPS', 'name' => 'Operations']);
    seedRequests(3, ['department_id' => $other->id]);

    $this->actingAs($this->admin);

    $response = $this->get(route('reports.export', ['department' => $this->department->id]));

    $response->assertOk();

    // `streamedContent()` is on the RESPONSE, not the test case.
    $csv = $response->streamedContent();

    // A header row plus exactly the two requests in the filtered department.
    $lines = array_filter(explode("\n", trim($csv)));

    expect($lines)->toHaveCount(3);

    $service = app(ReportingService::class);
    $query = $service->query($this->admin, ['department_id' => $this->department->id]);

    // And the file's row count matches the figure the screen would show.
    expect(count($lines) - 1)->toBe($query->count())
        ->and($service->outcomes($query)['total'])->toBe(2);
});

it('refuses an export to a user with no access', function () {
    seedRequests(1, ['requestor_id' => $this->owner->id]);

    // A requestor can reach the report, but only for their own requests — so the
    // export cannot contain somebody else's.
    $this->actingAs($this->requestor);

    $csv = $this->get(route('reports.export'))->streamedContent();

    expect($csv)->not->toContain('Request 1');
});

it('writes a UTF-8 BOM and human labels into the export', function () {
    seedRequests(1);
    $this->actingAs($this->admin);

    $csv = $this->get(route('reports.export'))->streamedContent();

    // The BOM stops Excel mangling accented names on Windows.
    expect(substr($csv, 0, 3))->toBe("\xEF\xBB\xBF")
        // Labels, not stored codes — a code in a spreadsheet is a code somebody looks up.
        ->and($csv)->toContain('Draft')
        ->and($csv)->toContain('Submission')
        ->and($csv)->toContain('Request number');
});

it('exports an unpriced request with a blank budget, not a zero', function () {
    // A zero in a budget column is a figure somebody will report.
    seedRequests(1);
    $this->actingAs($this->admin);

    $csv = $this->get(route('reports.export'))->streamedContent();
    $rows = array_map('str_getcsv', array_filter(explode("\n", trim($csv))));

    $budgetColumn = array_search('Budget (RM)', $rows[0], true);

    expect($rows[1][$budgetColumn])->toBe('');
});

// ---- Admin: reference data --------------------------------------------------

it('adds a reference option with a valid code', function () {
    $this->actingAs($this->admin);

    Livewire::test(ReferenceData::class)
        ->set('kind', 'tier')
        ->set('name', 'Tier 3')
        ->set('code', 'tier_3')
        ->call('add')
        ->assertHasNoErrors();

    $this->assertDatabaseHas('tiers', ['code' => 'tier_3', 'name' => 'Tier 3']);
});

it('refuses a code that is not lowercase-underscore', function () {
    /*
     * The code is what the database stores and what code compares against. A code with
     * a space or a capital only matches if every future comparison spells it
     * identically — which is how the free-text drift this screen prevents comes back.
     */
    $this->actingAs($this->admin);

    Livewire::test(ReferenceData::class)
        ->set('kind', 'tier')
        ->set('name', 'Tier 3')
        ->set('code', 'Tier 3')
        ->call('add')
        ->assertHasErrors(['code']);
});

it('refuses a duplicate code', function () {
    $this->actingAs($this->admin);

    Livewire::test(ReferenceData::class)
        ->set('kind', 'tier')
        ->set('name', 'Another tier')
        ->set('code', 'tier_1')
        ->call('add')
        ->assertHasErrors(['code']);
});

it('deactivates a reference option rather than deleting it', function () {
    /*
     * Every one of these tables is referenced by requests that already exist. Deleting
     * would either orphan them or cascade through them.
     */
    $tier = Tier::orderBy('sort_order')->first();
    $this->actingAs($this->admin);

    Livewire::test(ReferenceData::class)
        ->call('toggleActive', 'tier', $tier->id)
        ->assertHasNoErrors();

    $this->assertDatabaseHas('tiers', ['id' => $tier->id, 'is_active' => false]);
});

it('refuses to deactivate the last active option of a kind', function () {
    // An empty picker makes the wizard unusable with no explanation.
    Tier::query()->update(['is_active' => false]);
    $tier = Tier::orderBy('sort_order')->first();
    $tier->update(['is_active' => true]);

    $this->actingAs($this->admin);

    Livewire::test(ReferenceData::class)
        ->call('toggleActive', 'tier', $tier->id)
        ->assertHasErrors(['toggle']);

    expect($tier->fresh()->is_active)->toBeTrue();
});

it('lets the routing rule be changed without a release', function () {
    // `requires_committee` is stored as data precisely so this is possible.
    $light = GovernanceRoute::where('code', 'light')->first();

    $this->actingAs($this->admin);

    Livewire::test(ReferenceData::class)
        ->set("edits.route:{$light->id}.requires_committee", true)
        ->call('save', 'route', $light->id)
        ->assertHasNoErrors();

    expect($light->fresh()->requires_committee)->toBeTrue();
});

it('refuses reference data changes to a non-administrator', function () {
    $tier = Tier::orderBy('sort_order')->first();
    $this->actingAs($this->requestor);

    Livewire::test(ReferenceData::class)->assertForbidden();
});

// ---- Admin: due dates and the calendar --------------------------------------

it('saves a stage target in business days', function () {
    $stage = WorkflowStageModel::where('code', WorkflowStage::Consolidation->value)->first();
    $this->actingAs($this->admin);

    Livewire::test(Settings::class)
        ->set("targets.{$stage->id}:0", 7)
        ->call('saveTargets')
        ->assertHasNoErrors();

    $this->assertDatabaseHas('stage_due_days', [
        'workflow_stage_id' => $stage->id,
        'tier_id' => null,
        'business_days' => 7,
    ]);
});

it('deletes a target rather than storing zero when cleared', function () {
    /*
     * Clearing means "no target", not "due today".
     *
     * Zero is a real target that `businessDaysFor()` returns, so storing it would make
     * every request due immediately — and the aging report would show them all overdue
     * rather than stating that no target is set.
     */
    $stage = WorkflowStageModel::where('code', WorkflowStage::ProjectOwner->value)->first();

    StageDueDay::updateOrCreate(
        ['workflow_stage_id' => $stage->id, 'tier_id' => null],
        ['business_days' => 3],
    );

    $this->actingAs($this->admin);

    Livewire::test(Settings::class)
        ->set("targets.{$stage->id}:0", null)
        ->call('saveTargets')
        ->assertHasNoErrors();

    expect(StageDueDay::where('workflow_stage_id', $stage->id)->whereNull('tier_id')->exists())->toBeFalse();
});

it('adds a holiday that due dates then skip', function () {
    $this->actingAs($this->admin);

    Livewire::test(Settings::class)
        ->set('newHolidayDate', '2026-12-25')
        ->set('newHolidayName', 'Christmas Day')
        ->call('addHoliday')
        ->assertHasNoErrors();

    $this->assertDatabaseHas('holidays', ['date' => '2026-12-25', 'name' => 'Christmas Day']);

    // The calendar caches holidays per request, so without a forget the new date would
    // not affect due dates computed in the same request.
    app(BusinessCalendar::class)->forgetHolidays();

    expect(app(BusinessCalendar::class)->isWorkingDay(CarbonImmutable::parse('2026-12-25')))->toBeFalse();
});

it('marks a hand-entered holiday as manually overridden', function () {
    // So a later calendar sync does not silently remove it.
    $this->actingAs($this->admin);

    Livewire::test(Settings::class)
        ->set('newHolidayDate', '2026-12-25')
        ->set('newHolidayName', 'Christmas Day')
        ->call('addHoliday');

    expect(Holiday::where('date', '2026-12-25')->first()->isManuallyOverridden())->toBeTrue();
});

it('refuses a duplicate holiday date', function () {
    Holiday::create(['date' => '2026-12-25', 'name' => 'Christmas', 'source' => 'manual']);

    $this->actingAs($this->admin);

    Livewire::test(Settings::class)
        ->set('newHolidayDate', '2026-12-25')
        ->set('newHolidayName', 'Christmas again')
        ->call('addHoliday')
        ->assertHasErrors(['newHolidayDate']);
});

it('refuses calendar changes to a non-administrator', function () {
    $this->actingAs($this->requestor);

    Livewire::test(Settings::class)->assertForbidden();
});

// ---- Admin: users and roles -------------------------------------------------

it('assigns a role to a user', function () {
    $this->actingAs($this->admin);

    Livewire::test(Users::class)
        ->call('edit', $this->requestor->id)
        ->set('roles', [UserRole::Requestor->value, UserRole::GovernanceReviewer->value])
        ->call('save')
        ->assertHasNoErrors();

    expect($this->requestor->fresh()->hasRole(UserRole::GovernanceReviewer))->toBeTrue();
});

it('deactivates an account rather than deleting it', function () {
    /*
     * Every request, approval and history row references a user. Deleting one would
     * orphan them, and a trail that loses the name of the person who approved
     * something is not a trail.
     */
    $this->actingAs($this->admin);

    Livewire::test(Users::class)
        ->call('edit', $this->requestor->id)
        ->set('is_active', false)
        ->call('save')
        ->assertHasNoErrors();

    $this->assertDatabaseHas('users', ['id' => $this->requestor->id, 'is_active' => false]);
});

it('refuses to let an administrator deactivate their own account', function () {
    /*
     * The one change on this screen that can lock everybody out. Removing the last
     * administrator leaves nobody able to administer the system — no roles, no
     * reference data, no way back in except the database.
     */
    $this->actingAs($this->admin);

    Livewire::test(Users::class)
        ->call('edit', $this->admin->id)
        ->set('is_active', false)
        ->call('save')
        ->assertHasErrors(['is_active']);

    expect($this->admin->fresh()->is_active)->toBeTrue();
});

it('refuses to let an administrator remove their own administrator role', function () {
    $this->actingAs($this->admin);

    Livewire::test(Users::class)
        ->call('edit', $this->admin->id)
        ->set('roles', [UserRole::Requestor->value])
        ->call('save')
        ->assertHasErrors(['roles']);

    expect($this->admin->fresh()->hasRole(UserRole::Administrator))->toBeTrue();
});

it('refuses to let a user be their own manager', function () {
    $this->actingAs($this->admin);

    Livewire::test(Users::class)
        ->call('edit', $this->requestor->id)
        ->set('manager_id', $this->requestor->id)
        ->call('save')
        ->assertHasErrors(['manager_id']);
});

it('clears the division when the department changes', function () {
    // Otherwise an account can hold a division that belongs to another department.
    $this->actingAs($this->admin);

    Livewire::test(Users::class)
        ->call('edit', $this->requestor->id)
        ->set('division_id', 1)
        ->set('department_id', $this->department->id)
        ->assertSet('division_id', null);
});

it('tells an administrator when there is only one administrator', function () {
    // A lockout risk worth making visible before somebody acts on it.
    $this->actingAs($this->admin);

    Livewire::test(Users::class)->assertSee('active administrator');
});

it('flags a user who holds no role', function () {
    /*
     * They can sign in and reach almost nothing, which is otherwise diagnosed only by
     * an administrator noticing. A user with no role is created explicitly: every
     * seeded demo account HAS a role, so without this the assertion would have passed
     * or failed for a reason unrelated to the flag.
     */
    User::factory()->create(['name' => 'Roleless Person', 'is_active' => true]);

    $this->actingAs($this->admin);

    Livewire::test(Users::class)
        ->assertSee('Roleless Person')
        ->assertSee('No role');
});

it('refuses user administration to a non-administrator', function () {
    $this->actingAs($this->requestor);

    Livewire::test(Users::class)->assertForbidden();
});

it('filters users by role', function () {
    $this->actingAs($this->admin);

    $component = Livewire::test(Users::class)
        ->set('role', UserRole::Administrator->value);

    expect($component->viewData('users')->total())->toBe(1);
});
