<?php

/*
|--------------------------------------------------------------------------
| Storage tree repair — runs BEFORE the framework boots
|--------------------------------------------------------------------------
|
| WHY THIS FILE EXISTS
|
| Laravel's default `config/view.php` resolves the compiled-view path with:
|
|     'compiled' => env('VIEW_COMPILED_PATH', realpath(storage_path('framework/views')))
|
| `realpath()` returns FALSE when the directory does not exist, and
| `Illuminate\View\Compilers\Compiler::__construct()` then throws:
|
|     InvalidArgumentException: Please provide a valid cache path.
|
| That is not a view problem. It is a missing folder, reported as something
| else, with an empty log — because `storage/logs` is missing too.
|
| WHY IT CANNOT BE SHIPPED
|
| The deploy excludes the whole `storage/**` tree, because that tree holds the
| sessions, the log, and the tender documents attached to a request — data that
| exists nowhere else and that no database backup contains. Anything NOT
| excluded is DELETED from the server when it is absent locally, so the
| exclusion is what protects it. Exclusions are honoured in BOTH directions,
| and Git cannot carry an empty directory, so a freshly unpacked release has no
| `storage/framework/views` at all.
|
| WHY THE INSTALLER CANNOT FIX IT
|
| `itrequest:install` does create this tree, but it is an artisan command, and
| artisan cannot boot without the tree it creates. The dependency runs the
| wrong way round: the repair needs the thing it repairs. `itrequest:deploy`
| has the same problem for the same reason.
|
| A `keep` sentinel file in each directory would satisfy Git and not the
| upload, because the upload is what drops the whole tree. So the repair
| happens here instead: one `mkdir` on the way in, before anything is loaded.
| It costs an `is_dir()` per directory on a request that is about to read
| dozens of files.
|
| `mkdir` is called with `@` deliberately. If it fails because the parent is
| unwritable, the application cannot run whatever this file does, and it fails
| just as loudly a few lines further on — the suppression only prevents a
| warning from being emitted ahead of the real error.
|
| Deliberately dependency-free: no autoloader, no helpers, no config. This runs
| before any of them are available.
|--------------------------------------------------------------------------
*/

$ensureStorageTree = static function (string $root): void {
    // Must agree with ensureStorageTree() in InstallApplication and RunDeployment.
    foreach ([
        'storage/app/private/attachments', // FR-006 uploaded documents
        'storage/app/public',              // target of `php artisan storage:link`
        'storage/framework/cache/data',    // cache store
        'storage/framework/sessions',      // session store
        'storage/framework/views',         // REQUIRED TO BOOT — see header
        'storage/logs',                    // without this a failure leaves no trace
    ] as $directory) {
        $path = $root.'/'.$directory;

        if (! is_dir($path)) {
            @mkdir($path, 0775, true);
        }
    }

    // `bootstrap/cache` is NOT excluded from the transfer and carries a `keep`
    // sentinel, so it does arrive. It is repaired here anyway because it fails in
    // exactly the same way and for exactly the same reason — Composer's
    // `package:discover` hook refuses to run without it, and the framework reports
    // "The <path>/bootstrap/cache directory must be present and writable." One
    // `is_dir()` on an already-present directory is not worth leaving a second
    // empty-500 to chance.
    if (! is_dir($root.'/bootstrap/cache')) {
        @mkdir($root.'/bootstrap/cache', 0775, true);
    }
};

$ensureStorageTree(dirname(__DIR__));

unset($ensureStorageTree);
