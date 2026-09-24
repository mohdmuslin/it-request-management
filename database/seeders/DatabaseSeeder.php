<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * The application seeder.
 *
 * NOTE: no `WithoutModelEvents` trait here, unlike the scaffolder's default and
 * unlike a naive copy of it. The workflow depends on model events — a transition
 * writes its history row, an approval assignment computes its due date — so
 * suppressing events during seeding would silently produce requests with no
 * history and tasks with no due date. That is exactly the kind of defect that
 * looks like a code bug later.
 */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // Safe anywhere: reference values only, no accounts.
        $this->call(ReferenceDataSeeder::class);

        /*
         * Demonstration accounts, in local development only.
         *
         * The environment check lives inside the seeder rather than here, so it
         * cannot be bypassed by invoking DemoUserSeeder directly — which is the
         * whole point of a guard.
         */
        $this->call(DemoUserSeeder::class);
    }
}
