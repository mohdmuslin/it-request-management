<?php

use App\Livewire\Admin\AuditLog;
use App\Livewire\Admin\ReferenceData;
use App\Livewire\Admin\Settings;
use App\Livewire\Admin\Users;
use App\Livewire\Auth\Login;
use App\Livewire\Dashboard;
use App\Livewire\Requests\Create;
use App\Livewire\Requests\Index;
use App\Livewire\Requests\Show;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Web routes
|--------------------------------------------------------------------------
|
| A server-rendered application, so every screen is a route. The names matter:
| the navigation component resolves menus by route name, and the role landing
| routes are referenced by the UserRole enum, so a rename here must be matched
| there.
|
| EVERY ROUTE BEYOND AUTH REQUIRES `auth`. Authorisation beyond that — who may see
| which request, and who may act on it — belongs in policies, not here. A
| route-level role check duplicates the policy and then drifts from it.
|
*/

Route::redirect('/', '/dashboard');

/*
|--------------------------------------------------------------------------
| Guest
|--------------------------------------------------------------------------
*/
Route::middleware('guest')->group(function () {
    Route::get('/login', Login::class)->name('login');
});

Route::post('/logout', function () {
    Auth::logout();

    // Regenerate on the way out as well as the way in, so a session id captured
    // before sign-out cannot be replayed after it.
    request()->session()->invalidate();
    request()->session()->regenerateToken();

    return redirect()->route('login');
})->middleware('auth')->name('logout');

/*
|--------------------------------------------------------------------------
| Authenticated
|--------------------------------------------------------------------------
*/
Route::middleware('auth')->group(function () {
    Route::get('/dashboard', Dashboard::class)->name('dashboard');

    /*
     * Requests.
     *
     * `create` is declared before `{request}` deliberately: a wildcard segment
     * would otherwise swallow "create" and try to resolve it as a request id.
     */
    Route::get('/requests', Index::class)->name('requests.index');
    Route::get('/requests/create', Create::class)->name('requests.create');
    Route::get('/requests/{request}', Show::class)->name('requests.show');

    Route::get('/approvals', App\Livewire\Approvals\Index::class)->name('approvals.index');
    Route::get('/recommendations', App\Livewire\Recommendations\Index::class)->name('recommendations.index');
    Route::get('/governance', App\Livewire\Governance\Index::class)->name('governance.index');
    Route::get('/committee', App\Livewire\Committee\Index::class)->name('committee.index');
    Route::get('/reports', App\Livewire\Reports\Index::class)->name('reports.index');

    /*
     * Administration.
     *
     * Prefixed and named as a group so the navigation's route names line up, and so
     * the whole area can be protected in one place rather than fifteen times.
     */
    Route::prefix('admin')->name('admin.')->group(function () {
        Route::get('/reference', ReferenceData::class)->name('reference.index');
        Route::get('/settings', Settings::class)->name('settings.index');
        Route::get('/users', Users::class)->name('users.index');
        Route::get('/audit', AuditLog::class)->name('audit.index');
    });
});
