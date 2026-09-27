<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default Filesystem Disk
    |--------------------------------------------------------------------------
    |
    | Here you may specify the default filesystem disk that should be used
    | by the framework. The "local" disk, as well as a variety of cloud
    | based disks are available to your application for file storage.
    |
    */

    'default' => env('FILESYSTEM_DISK', 'local'),

    /*
    |--------------------------------------------------------------------------
    | Filesystem Disks
    |--------------------------------------------------------------------------
    |
    | Below you may configure as many filesystem disks as necessary, and you
    | may even configure multiple disks for the same driver. Examples for
    | most supported storage drivers are configured here for reference.
    |
    | Supported drivers: "local", "ftp", "sftp", "s3"
    |
    */

    'disks' => [

        'local' => [
            'driver' => 'local',
            'root' => storage_path('app/private'),
            'serve' => true,
            'throw' => false,
            'report' => false,
        ],

        /*
         * Uploaded request documents (FR-006).
         *
         * WHY ITS OWN DISK RATHER THAN `local`
         *
         * `local` points at `storage/app/private`, which is also where Laravel keeps its own
         * scratch state. Giving attachments a subdirectory of their own means a `storage:clear`
         * habit, or anything else that empties a framework directory, cannot reach them — and
         * that an operator can see at a glance which part of the tree is the organisation's data
         * and which part is regenerable.
         *
         * `visibility` is private and `throw` is true, both deliberately:
         *
         *   - **private** because a document here is a budget, a quotation or a risk assessment.
         *     Nothing in this disk is ever served by a web server; `AttachmentService` streams it
         *     through a route that authorises the request first, which is the difference between
         *     a file a requestor can share and a data leak.
         *   - **throw true** because a failed write must be an exception, not a `false` return.
         *     A silently failed upload would leave an `attachments` row pointing at nothing, and
         *     the first evidence would be an approver unable to open a document they were told
         *     existed.
         *
         * The root is created by `itrequest:install` and by `itrequest:deploy`, and
         * `itrequest:deploy-check` warns when it is missing — because a missing directory turns
         * every upload into an exception, and the message would otherwise name a path rather than
         * the deploy step that skipped it.
         */
        'attachments' => [
            'driver' => 'local',
            'root' => storage_path('app/private/attachments'),
            'serve' => false,
            'throw' => true,
            'report' => false,
            'visibility' => 'private',
        ],

        'public' => [
            'driver' => 'local',
            'root' => storage_path('app/public'),
            'url' => rtrim(env('APP_URL', 'http://localhost'), '/').'/storage',
            'visibility' => 'public',
            'throw' => false,
            'report' => false,
        ],

        's3' => [
            'driver' => 's3',
            'key' => env('AWS_ACCESS_KEY_ID'),
            'secret' => env('AWS_SECRET_ACCESS_KEY'),
            'region' => env('AWS_DEFAULT_REGION'),
            'bucket' => env('AWS_BUCKET'),
            'url' => env('AWS_URL'),
            'endpoint' => env('AWS_ENDPOINT'),
            'use_path_style_endpoint' => env('AWS_USE_PATH_STYLE_ENDPOINT', false),
            'throw' => false,
            'report' => false,
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Symbolic Links
    |--------------------------------------------------------------------------
    |
    | Here you may configure the symbolic links that will be created when the
    | `storage:link` Artisan command is executed. The array keys should be
    | the locations of the links and the values should be their targets.
    |
    */

    'links' => [
        public_path('storage') => storage_path('app/public'),
    ],

];
