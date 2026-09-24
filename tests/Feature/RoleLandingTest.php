<?php

use App\Enums\UserRole;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Support\Facades\Route;

/**
 * Every role can land somewhere real.
 *
 * WHY THIS TEST EXISTS
 *
 * `UserRole::landingRoute()` returned bare route names — 'recommendations',
 * 'governance', 'audit' — while routes/web.php named them 'recommendations.index',
 * 'governance.index' and 'admin.audit.index'. Signing in as a Technical Reviewer,
 * an IT HOU or an Auditor therefore produced a 500.
 *
 * The failure was misleading: the login form stayed on screen with the fields
 * filled and the button disabled, so it read as a slow sign-in rather than a
 * server error. It only surfaced by signing in as each role in turn.
 *
 * A route name is a string, so nothing type-checks it. This test is the type check.
 */
it('has a landing route that exists for every role', function () {
    foreach (UserRole::cases() as $role) {
        $name = $role->landingRoute();

        expect(Route::has($name))->toBeTrue(
            "Role {$role->value} lands on route '{$name}', which is not defined. "
            .'Names are strings and nothing type-checks them, so this is the only guard.'
        );
    }
});

it('lands each role on a screen that is not a dead end', function () {
    /*
     * A landing route must be a real screen, not the login page or a redirect. A
     * role sent to `/login` while already authenticated is bounced by the guest
     * middleware, which presents as an apparent sign-in loop.
     */
    foreach (UserRole::cases() as $role) {
        $route = Route::getRoutes()->getByName($role->landingRoute());

        expect($route)->not->toBeNull();
        expect($route->uri())->not->toBe('login');
    }
});

it('signs each role in without an error', function () {
    /*
     * The end-to-end version of the check above. It exercises the whole path —
     * authenticate, resolve the role, build the landing URL, render the layout and
     * the navigation — because a route can exist and still fail to render.
     */
    $this->seed(ReferenceDataSeeder::class);

    $accounts = [
        [UserRole::Requestor,            'requestor@test.local'],
        [UserRole::ProjectOwner,         'owner@test.local'],
        [UserRole::ProjectSponsor,       'sponsor@test.local'],
        [UserRole::GovernanceReviewer,   'governance@test.local'],
        [UserRole::TechnicalReviewer,    'technical@test.local'],
        [UserRole::Hou,                  'hou@test.local'],
        [UserRole::CommitteeSecretariat, 'secretariat@test.local'],
        [UserRole::Administrator,        'admin@test.local'],
        [UserRole::Auditor,              'auditor@test.local'],
    ];

    foreach ($accounts as [$role, $email]) {
        $user = asUser($role);
        $user->update(['email' => $email]);

        $this->actingAs($user)
            ->get(route($role->landingRoute()))
            ->assertSuccessful();
    }
});
