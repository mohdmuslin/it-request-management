<?php

use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

/**
 * The pre-boot storage repair.
 *
 * WHY THIS IS TESTED AT ALL, AND WHY IT IS TESTED THIS WAY
 *
 * `storage/**` is excluded from the deploy, deliberately — that tree holds the
 * sessions, the log, and the tender documents attached to a request, and those
 * documents exist nowhere else. Exclusions are honoured in BOTH directions and Git
 * cannot carry an empty directory, so a release unpacked on the server has NO
 * `storage/framework/views`.
 *
 * Laravel resolves its compiled-view path with `realpath()`, which returns FALSE for a
 * directory that is not there, and `Compiler::__construct()` then throws:
 *
 *     InvalidArgumentException: Please provide a valid cache path.
 *
 * That message names VIEWS rather than a missing folder, and the log is empty because
 * `storage/logs` went with it. On the server it surfaced as a 500 with a ZERO-LENGTH
 * body, with every step of the deploy green.
 *
 * The test runs the REAL file against a SCRATCH root, because the real file hard-codes
 * `dirname(__DIR__)` and the alternative is deleting the live storage tree inside the
 * suite. A version that created the directory in the wrong place, or that silently did
 * nothing, would still satisfy a test that only asked whether the function had been
 * called.
 */
function scratchRoot(): string
{
    $root = sys_get_temp_dir().'/itrequest-storage-'.Str::random(10);

    File::makeDirectory($root, 0755, true);

    return $root;
}

/**
 * The real repair file, retargeted at a scratch root.
 *
 * The substitution is asserted rather than assumed — if the file is ever edited so
 * that this replacement no longer matches, the test must fail loudly instead of
 * quietly repairing the live tree and passing.
 */
function runStorageRepair(string $root): void
{
    $source = File::get(base_path('bootstrap/ensure-storage.php'));

    expect($source)
        ->toContain('$ensureStorageTree(dirname(__DIR__));');

    $retargeted = str_replace(
        '$ensureStorageTree(dirname(__DIR__));',
        '$ensureStorageTree('.var_export($root, true).');',
        $source,
    );

    $file = $root.'/repair.php';
    File::put($file, $retargeted);

    require $file;
}

it('rebuilds the whole storage tree from nothing', function () {
    $root = scratchRoot();

    runStorageRepair($root);

    /*
     * Each of these is here for a reason, not for completeness.
     *
     *   framework/views     required to BOOT. Absent, the framework throws before any
     *                       command can create it.
     *   logs                without it a failure leaves no trace, which is why the
     *                       original fault was invisible.
     *   framework/sessions  a missing session store loses every sign-in.
     *   framework/cache/data  the file cache store.
     *   app/private/attachments  FR-006 tender documents.
     *   app/public          the target of `php artisan storage:link`.
     */
    foreach ([
        'storage/framework/views',
        'storage/framework/sessions',
        'storage/framework/cache/data',
        'storage/logs',
        'storage/app/private/attachments',
        'storage/app/public',
    ] as $directory) {
        expect(is_dir($root.'/'.$directory))
            ->toBeTrue("Expected the repair to create {$directory}.");
    }

    File::deleteDirectory($root);
});

it('repairs bootstrap/cache, which fails in the same way for the same reason', function () {
    $root = scratchRoot();

    runStorageRepair($root);

    /*
     * This directory does travel — it is not excluded and it carries a `keep` sentinel —
     * so in practice it is present. It is repaired anyway because Composer's
     * `package:discover` hook refuses to run without it and reports
     * "The <path>/bootstrap/cache directory must be present and writable", which is the
     * same empty-failure shape as the view path. One `is_dir()` is cheap insurance.
     */
    expect(is_dir($root.'/bootstrap/cache'))->toBeTrue();

    File::deleteDirectory($root);
});

it('is safe to run when the tree already exists', function () {
    $root = scratchRoot();

    File::makeDirectory($root.'/storage/framework/views', 0755, true);

    // A sentinel inside a directory that already exists must survive. If the repair
    // ever grew a delete-and-recreate, this is what would catch it — and that version
    // would destroy the uploaded documents.
    File::put($root.'/storage/framework/views/keep', 'sentinel');

    runStorageRepair($root);
    runStorageRepair($root); // twice, because it runs on every single request

    expect(File::get($root.'/storage/framework/views/keep'))->toBe('sentinel');
    expect(is_dir($root.'/storage/framework/views'))->toBeTrue();

    File::deleteDirectory($root);
});

it('is required by both entry points', function () {
    /*
     * BOTH, and this is the point of the whole file. `itrequest:install` repairs the
     * tree, but it is an artisan command — so artisan must already be able to boot
     * without the tree it creates. If only `public/index.php` required the repair, the
     * installer could never run; if only `artisan` did, every page would 500.
     *
     * This assertion is textual, and that is a genuine limitation: it proves the
     * `require` is present, not that it runs early enough. The behavioural proof is the
     * CI step that deletes the tree and boots, which is where the ordering is checked.
     */
    expect(File::get(base_path('public/index.php')))->toContain('bootstrap/ensure-storage.php');
    expect(File::get(base_path('artisan')))->toContain('bootstrap/ensure-storage.php');
});
