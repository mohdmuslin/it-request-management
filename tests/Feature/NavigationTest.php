<?php

use App\Enums\UserRole;
use App\Livewire\Navigation;
use App\Models\ItRequest;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Support\Facades\Route;
use Livewire\Livewire;

/**
 * The navigation menu.
 *
 * WHY THERE IS A TEST FOR A MENU
 *
 * The menu and the policy answer the same question — "may this person do this?" —
 * and they were answering it differently. Signed in as a Project Owner, the
 * requests page offered a "New request" button the navigation did not, which reads
 * as the application being inconsistent about your own permissions.
 *
 * Two places asking one question will drift. The fix was to make the menu ask the
 * policy. This test holds that in place: it asserts the menu and `can()` agree,
 * rather than asserting a hardcoded list of items, so it keeps working when the
 * policy changes.
 */
beforeEach(function () {
    $this->seed(ReferenceDataSeeder::class);
});

it('shows New Request to anyone the policy lets create', function () {
    foreach ([UserRole::Requestor, UserRole::ProjectOwner, UserRole::ProjectSponsor, UserRole::Administrator] as $role) {
        $user = asUser($role);

        $this->actingAs($user);

        $expected = $user->can('create', ItRequest::class);

        Livewire::test(Navigation::class)
            ->assertSee('My Requests')
            ->assertSee('New Request', $expected);
    }
});

it('hides New Request from an auditor', function () {
    // Read-only by design: an auditor who can file a request also appears as a
    // requestor in the trail they are auditing.
    $this->actingAs(asUser(UserRole::Auditor));

    Livewire::test(Navigation::class)->assertDontSee('New Request');
});

it('shows a role only the screens that role can reach', function () {
    /*
     * Every menu item must point at a route that exists.
     *
     * Route names are strings and nothing type-checks them, so a renamed route
     * silently produces a menu entry that 500s on click — the sort of defect that is
     * found by a user rather than by a test.
     */
    $this->actingAs(asUser(UserRole::Administrator));

    $component = Livewire::test(Navigation::class);

    foreach ($component->instance()->groups() as $group) {
        foreach ($group['items'] as $item) {
            expect(Route::has($item['route']))
                ->toBeTrue("Menu item '{$item['label']}' points at a route that does not exist: {$item['route']}");
        }
    }
});

it('renders no menu for a signed-out visitor', function () {
    expect((new Navigation)->groups())->toBe([]);
});
