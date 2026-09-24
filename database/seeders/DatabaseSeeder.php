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
        $this->call(ReferenceDataSeeder::class);

        // Demo users and requests arrive with the request module, once the models
        // they depend on are settled. Seeding them now would mean rewriting this
        // seeder twice.
    }
}
