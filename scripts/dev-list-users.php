<?php

use App\Models\User;
use Illuminate\Contracts\Console\Kernel;

/**
 * List the demo accounts and their roles, for a manual browser walk.
 *
 * A dev helper, not part of the application. It reads the database directly so it
 * can be run without booting the whole framework.
 */

require __DIR__.'/../vendor/autoload.php';

$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$users = User::with('roles')->orderBy('id')->get();

foreach ($users as $user) {
    $roles = $user->roles->pluck('name')->join(',') ?: '(no role)';
    printf("%-38s %-24s %s\n", $user->email, $roles, $user->is_active ? 'active' : 'INACTIVE');
}
