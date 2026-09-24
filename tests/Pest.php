<?php

use App\Enums\UserRole;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Testing\Fakes\NotificationFake;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Pest configuration
|--------------------------------------------------------------------------
| Without this file Pest has no base TestCase, so the Laravel application is
| never bootstrapped and every test fails with "Target class [config] does not
| exist". That message points at the symptom rather than the cause, so it is
| worth remembering: a missing Pest.php looks like a broken container.
*/

uses(
    TestCase::class,
    RefreshDatabase::class,
)->in('Feature');

/*
 * Unit tests get the application but NOT the database. Anything needing a schema
 * belongs in Feature, where RefreshDatabase keeps each test isolated.
 */
uses(TestCase::class)->in('Unit');

/*
|--------------------------------------------------------------------------
| Shared helpers
|--------------------------------------------------------------------------
| Defined once here rather than per file. The sibling projects learned this the
| expensive way: a helper duplicated into three test files drifts, and then the
| tests disagree about what they are asserting.
*/

/**
 * A signed-in user holding the given role.
 */
function asUser(UserRole ...$roles): User
{
    $user = User::factory()->create(['is_active' => true]);

    foreach ($roles as $role) {
        $roleModel = Role::firstOrCreate(
            ['name' => $role->value],
            ['label' => $role->label()],
        );

        $user->roles()->syncWithoutDetaching([$roleModel->id => ['granted_at' => now()]]);
    }

    // Reload so `hasRole()` sees the pivot rows it was just given. Without this the
    // relationship is cached as empty and every role check fails for the wrong
    // reason.
    return $user->fresh('roles');
}

/**
 * A user with no roles at all.
 *
 * Used to assert that a permission is actually enforced rather than assumed —
 * every "can" test needs a matching "cannot".
 */
function plainUser(): User
{
    return User::factory()->create(['is_active' => true]);
}

/**
 * Prevent real notifications from being sent during a test.
 *
 * Returns the fake so a test can assert what was sent rather than only that the
 * action succeeded.
 */
function fakeNotifications(): NotificationFake
{
    Notification::fake();

    return Notification::getFacadeRoot();
}
