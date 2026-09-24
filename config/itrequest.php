<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Identity provider
    |--------------------------------------------------------------------------
    |
    | WHY THIS FILE EXISTS AT ALL
    |
    | The vendor brief specifies Microsoft Entra ID via OIDC/OAuth 2.0. The POC
    | ships with local accounts instead, because an app registration, tenant admin
    | consent and a token-refresh cycle are provisioning steps that demonstrate no
    | business process — and the POC exists to demonstrate the process.
    |
    | WHY THE DRIVER IS CONFIGURABLE RATHER THAN HARD-CODED
    |
    | Swapping the driver is the whole reason `users.entra_object_id` is in the
    | first migration. Adding that column later means matching existing rows to
    | Entra identities by email, which fails for anyone whose email has changed
    | and cannot be verified automatically. One column now, no data migration and
    | no matching problem later.
    |
    | Common values: 'local', 'entra'
    |
    | Verify after setting it: sign in, then confirm the dashboard shows the right
    | role. A wrong driver fails at the login screen, not silently.
    |
    */

    'identity' => [
        'driver' => env('ITREQUEST_IDENTITY_DRIVER', 'local'),

        'entra' => [
            'tenant_id' => env('ENTRA_TENANT_ID'),
            'client_id' => env('ENTRA_CLIENT_ID'),
            'client_secret' => env('ENTRA_CLIENT_SECRET'),
            'redirect' => env('ENTRA_REDIRECT_URI'),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Business calendar
    |--------------------------------------------------------------------------
    |
    | WHY THE TIMEZONE IS HERE AND NOT IN config/app.php
    |
    | The application runs in UTC and stores UTC. The business does not. Kuala
    | Lumpur is a fixed UTC+8 with no daylight saving, so there is no transition
    | to handle — but every "what day is this?" question must be asked in local
    | time, not UTC.
    |
    | Worked example: a due date computed against a naive now() lands a day early
    | for anything created after 16:00 UTC, which is midnight in Kuala Lumpur.
    |
    | WHY THE BREAK MATTERS
    |
    | The shift spans 09:00-18:00 with an hour unpaid at 13:00. That leaves
    | 09:00-13:00 and 14:00-18:00 — two four-hour blocks, so eight working hours
    | per day. Eight divides cleanly: a 24-working-hour target is exactly three
    | business days. Without the break, a "day" would be nine hours and every
    | target would be subtly wrong.
    |
    | WHY THE SHIFTS ARE LISTED
    |
    | Coverage runs 07:00-19:00 across four overlapping shifts. That is a roster
    | fact — when somebody is on duty — and NOT what an SLA is measured against.
    | The clock uses the fixed window above. They are recorded here so the
    | distinction is visible rather than rediscovered.
    |
    | Verify after setting it: the due-date unit tests exercise 09:00 + 8 hours,
    | a span across the break, across a weekend, and across a holiday.
    |
    */

    'business_hours' => [
        'timezone' => env('ITREQUEST_TIMEZONE', 'Asia/Kuala_Lumpur'),

        // ISO-8601 day numbers: 1 = Monday ... 7 = Sunday.
        'days' => [1, 2, 3, 4, 5],

        'opens_at' => '09:00',
        'closes_at' => '18:00',

        'breaks' => [
            ['13:00', '14:00'],
        ],

        // Roster coverage only. Not used for due-date arithmetic.
        'coverage' => [
            '07:00-16:00',
            '08:00-17:00',
            '09:00-18:00',
            '10:00-19:00',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Stage due dates
    |--------------------------------------------------------------------------
    |
    | WHY DUE DATES ARE PER STAGE
    |
    | Nothing in the current process has a due date — not the flow diagram, not
    | the forty-column spreadsheet. A request can therefore sit in any stage
    | indefinitely, and no report says how long anything has waited.
    |
    | WHY THE COMMITTEE GETS TEN DAYS
    |
    | The committee meets periodically, so a three-day target there would generate
    | escalation notices for something nobody can act on any faster. Escalating
    | what cannot be fixed is how an alerting system teaches people to ignore it.
    |
    | These are business days, calculated against the calendar above. They are
    | seeded into the `stage_due_days` table and editable by an administrator —
    | this config is the source for the seeder, not the runtime source.
    |
    | THE KEYS MUST MATCH WorkflowStage ENUM VALUES EXACTLY.
    | The seeder looks a stage up by code and skips a key it cannot resolve, so a
    | mismatch is silent: the stage simply ends up with no due date, and every
    | request in it would be treated as having no deadline. An earlier draft used
    | "pending_owner" style keys against "owner" style enum values, and a test
    | caught it. Do not rename these without changing the enum.
    |
    | Urgency deliberately does NOT adjust these. It is captured and shown to
    | approvers as context, and it changes no clock.
    |
    */

    'stage_due_days' => [
        'project_owner' => 3,
        'project_sponsor' => 3,
        'completeness_review' => 2,
        'technical_recommendation' => 5,
        'consolidation' => 3,
        'committee_decision' => 10,
    ],

    /*
    |--------------------------------------------------------------------------
    | Reference numbering
    |--------------------------------------------------------------------------
    |
    | WHY THE NUMBER IS GENERATED AND NOT TYPED
    |
    | In the current SharePoint list, RequestID is a plain text field — typed by
    | hand. Hand-typed identifiers duplicate and mistype, and duplicates are the
    | worse failure: two requests sharing a number break referencing, reporting
    | and any future migration. BR-009 also makes it system-managed, so it is not
    | editable through an ordinary request screen.
    |
    | The {year} token is replaced with the business-year, and {seq} with a
    | zero-padded counter that resets each year.
    |
    */

    'request_number' => [
        'pattern' => env('ITREQUEST_NUMBER_PATTERN', 'REQ-{year}-{seq}'),
        'padding' => 4,
    ],

    /*
    |--------------------------------------------------------------------------
    | Attachments
    |--------------------------------------------------------------------------
    |
    | Files are stored on a private disk outside the document root and streamed
    | through an authorised action. They are never publicly addressable — that is
    | the difference between a document a requestor can share and a data leak.
    |
    | Malware scanning is NOT available on this host and is a declared deviation.
    | MIME type and size validation is what remains, so the allow-list is
    | deliberately narrow rather than permissive.
    |
    */

    'attachments' => [
        'disk' => env('ITREQUEST_ATTACHMENT_DISK', 'private'),
        'max_size_kb' => (int) env('ITREQUEST_ATTACHMENT_MAX_KB', 20480),
        'allowed_mimes' => [
            'application/pdf',
            'application/msword',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'application/vnd.ms-excel',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'image/png',
            'image/jpeg',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Queue
    |--------------------------------------------------------------------------
    |
    | WHY NOT REDIS
    |
    | The brief recommends Redis. This host provides none, no worker process and
    | no SSH to start one, so Redis cannot be installed. The database driver with
    | a cron-driven `queue:work --stop-when-empty` is the cPanel-compatible
    | equivalent, and is a declared deviation.
    |
    | Notifications are queued rather than sent inline. Sending mail inside the
    | request would make a slow SMTP server look like a broken application: the
    | approver clicks Approve, the page hangs, and they click again.
    |
    */

    'queue' => [
        'connection' => env('ITREQUEST_QUEUE_CONNECTION', 'database'),

        // Which notification events are enabled. Reminders and escalations are
        // separate toggles because escalating something nobody can act on is
        // worse than not escalating at all.
        'notify_on_assignment' => true,
        'notify_on_decision' => true,
        'notify_reminders' => true,
        'notify_escalations' => true,

        // How many business days before a due date to send a reminder.
        'remind_days_before' => 1,

        // How many business days overdue before escalating.
        'escalate_days_after' => 1,
    ],

];
