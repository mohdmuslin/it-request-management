<?php

namespace Database\Seeders;

use App\Enums\UserRole;
use App\Models\Department;
use App\Models\Division;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * Local demonstration data.
 *
 * WHY THIS EXISTS AS ITS OWN SEEDER
 *
 * `ReferenceDataSeeder` is safe to run anywhere, including production — it only
 * sets up tiers, classifications and roles. This seeder creates PEOPLE with a known
 * password, which is exactly the thing that must never run against a live system.
 *
 * That is why the account below is obviously a demonstration account rather than a
 * plausibly-real one, and why the seeder refuses to run when the application is not
 * in a local environment. A hard-coded password in a seeded account is how the
 * sibling attendance project ended up with published credentials.
 */
class DemoUserSeeder extends Seeder
{
    public function run(): void
    {
        /*
         * Refuse outside local development.
         *
         * Checked against APP_ENV rather than a config flag, because a config flag
         * can be left switched on by accident. This is a hard stop.
         */
        if (! app()->environment('local', 'testing')) {
            $this->command?->warn(
                'DemoUserSeeder skipped: this seeder creates accounts with a known password '
                .'and only runs in the local or testing environment.'
            );

            return;
        }

        $this->call(ReferenceDataSeeder::class);

        $it = Department::firstOrCreate(['code' => 'IT'], ['name' => 'Information Technology']);
        $ops = Department::firstOrCreate(['code' => 'OPS'], ['name' => 'Operations']);
        $infra = Division::firstOrCreate(['code' => 'INFRA'], ['name' => 'Infrastructure']);
        $support = Division::firstOrCreate(['code' => 'SUPPORT'], ['name' => 'Support']);

        /*
         * Every account shares one password, and it is written here in the open.
         *
         * That is deliberate: a demonstration password that is visible is safer than
         * a "realistic" one somebody might reuse, and it is impossible to mistake
         * these for production accounts.
         */
        $password = 'password';

        $people = [
            ['Ahmad bin Ali',            'ahmad@example.com',  $it,  $infra,   [UserRole::Requestor]],
            ['Siti Nurhaliza binti Hassan', 'siti@example.com', $ops, $support, [UserRole::ProjectOwner]],
            ['Tan Wei Ming',             'tan@example.com',    $ops, $support, [UserRole::ProjectSponsor]],
            ['Nurul Izzah',              'nurul@example.com',  $it,  $infra,   [UserRole::GovernanceReviewer]],
            ['Raj Kumar',                'raj@example.com',    $it,  $infra,   [UserRole::TechnicalReviewer]],
            ['Aisyah Rahman',            'aisyah@example.com', $it,  $infra,   [UserRole::Hou]],
            ['Lim Chee Keong',           'lim@example.com',    $it,  $infra,   [UserRole::CommitteeSecretariat]],
            ['Administrator',            'admin@example.com',  $it,  $infra,   [UserRole::Administrator]],
            ['External Auditor',         'auditor@example.com', $it, $infra,   [UserRole::Auditor]],
        ];

        foreach ($people as [$name, $email, $department, $division, $roles]) {
            $user = User::updateOrCreate(
                ['email' => $email],
                [
                    'name' => $name,
                    'password' => Hash::make($password),
                    'department_id' => $department->id,
                    'division_id' => $division->id,
                    'is_active' => true,
                ],
            );

            foreach ($roles as $role) {
                $roleModel = Role::where('name', $role->value)->firstOrFail();

                $user->roles()->syncWithoutDetaching([
                    $roleModel->id => ['granted_at' => now()],
                ]);
            }
        }

        $this->command?->info('Demo accounts created. All use the password: '.$password);
    }
}
