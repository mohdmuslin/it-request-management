# Architecture — IT Request Management System

**How the system is put together.** Companion documents: `blueprint.md` (scope), `design.md`
(workflow behaviour), `database-design.md` (data rules), `interface.md` (the UI spec).

---

## 1. Overview

A single Laravel application serving a server-rendered interface, a relational database, private
file storage, and outbound email. No separate frontend build to deploy, no API tier to
synchronise, no microservices.

```
Browser (desktop / tablet / mobile)
   │  HTTPS
   ▼
LiteSpeed  ──►  public/index.php  (the only web-reachable directory)
                     │
                     ▼
                 Laravel application
                     ├── Blade + Livewire + Tailwind   (presentation)
                     ├── Services                      (workflow, approvals, recommendations, audit)
                     ├── Eloquent models                (persistence)
                     ├── MySQL 8                      (data)
                     ├── Private disk                  (documents, outside the webroot)
                     ├── Database queue + cron worker  (notifications)
                     └── SMTP                          (email)
```

### Why a modular monolith and not services

The brief recommends exactly this (§1), and it fits the constraints:

| Reason | Detail |
|---|---|
| One deployment | No SSH on the host, so each additional moving part is another thing that cannot be restarted remotely |
| Transactions span the workflow | A transition and its history row must commit together — trivial in one process, distributed-saga complexity across services |
| Volume is small | Hundreds of requests per year, not thousands per second |
| Service boundaries still exist | `app/Services/` gives the seams without the operational cost |

---

## 2. Goals and Non-Functional Requirements

| Goal | Target | Note |
|---|---|---|
| Responsive | Usable from 375px to 1440px+ | The brief requires it (§6.3); the wizard is the hard part |
| Accessible | Keyboard navigable, labelled controls, readable contrast | NFR-005 |
| Auditable | Every material action attributable to actor + timestamp | NFR-006, the highest-priority NFR |
| Observable | Application, security and queue logs; health endpoint | NFR-009 |
| Portable | Reproducible across development, test and production | NFR-008 |
| Secure | Least privilege, server-side authorisation, private storage | NFR-001 |
| Maintainable | Automated tests, modular code, controlled configuration | NFR-007 |

**Hosting constraint, stated plainly:** cPanel with **no SSH and no cPanel terminal**. Only
cron, File Manager, FTP and the GUI are available. Every operational decision below follows from
that.

---

## 3. Technology

| Layer | Choice | Why |
|---|---|---|
| Language | PHP 8.3+ | Local toolchain is 8.3.33; the brief prefers 8.4+ |
| Framework | Laravel 13 | The brief's requirement; matches the two existing projects |
| Presentation | **Blade + Livewire 3 + Tailwind 4** | The brief's named stack. Livewire handles wizard autosave and conditional fields without hand-written JavaScript |
| Database | MySQL 8.4 | The brief's requirement; matches the host |
| Web server | LiteSpeed (cPanel) | The host. **Brief preferred Nginx — declared deviation** |
| Queue / cache | Database driver | **The host has no Redis — declared deviation** |
| Scheduler | Cron, every minute | The only scheduling mechanism available |
| Identity | Configurable driver | Local now, Entra ID later. See §6 |
| Source control | Git, GitHub | As both existing projects |
| Testing | Pest 4 + PHPUnit 12.5 | Matches the sibling projects, so conventions carry over |

> **PHP version caveat.** The brief prefers 8.4+; the local toolchain is 8.3.33. Confirm the
> host's PHP version before pinning a framework floor — if the host runs 8.1, the framework
> version must drop and Laravel 13 may not be available at all. Verify rather than assume.

---

## 4. Layers

| Layer | Responsibility | Where |
|---|---|---|
| Presentation | Screens, forms, validation feedback, accessibility | `app/Livewire/`, `resources/views/` |
| Application | Use cases, orchestration, authorisation | `app/Services/`, `app/Actions/` |
| Domain | The rules: requests, approvals, recommendations, routing, state machine | `app/Enums/`, `app/Services/WorkflowService.php` |
| Persistence | Models, transactions, migrations | `app/Models/`, `database/migrations/` |
| Integration | Identity, mail, storage | `app/Contracts/`, `app/Services/` |
| Operations | Deployment, configuration, cron, logging, backup | `.github/workflows/`, `routes/console.php` |

### Directory layout

```
app/
  Actions/            single-purpose write operations
  Console/Commands/   InstallApplication, SendDueReminders, RecomputeDueDates
  Contracts/          IdentityProvider, HolidaySource
  Enums/              RequestStatus, WorkflowStage, Tier, Classification, GovernanceRoute, Decision
  Http/
    Controllers/      thin; most screens are Livewire components
    Middleware/       EnsureUserIsActive, ForceJsonResponse
    Requests/         FormRequests, one per write action
  Livewire/           page components and forms
  Models/             one per table
  Policies/           RequestPolicy, ApprovalPolicy, RecommendationPolicy
  Services/           WorkflowService, ApprovalService, RecommendationService,
                      ConsolidationService, AuditService, BusinessCalendar,
                      NotificationService, ReferenceDataService
  Support/            ApiResponse (for any future API)
```

---

## 5. Key Data Flows

### 5.1 Submitting a request

```
Requestor fills wizard step 1..5
   → Livewire validates conditionally (business plan aligned? → reference : justification)
   → Save Draft (any time) writes it_requests only
   → Submit: one transaction
        validate all mandatory + conditional fields
        request_no generated
        status = Submitted → PendingProjectOwner
        approval_tasks row created (stage, sequence, due_at from stage_due_days)
        workflow_histories row written
        notification queued for the Owner
```

The whole submit path is **one transaction**. A request that reaches the Owner without a history
row, or without a due date, would be an unauditable request.

### 5.2 A decision

```
Approver opens My Approvals
   → approves / rejects / returns
   → authorisation checked against the approval_tasks row, not the form
   → reject or return requires a comment (BR-002)
   → one transaction:
        approval_tasks.decision, comments, decided_at
        it_requests.status + current_stage
        next approval_tasks row created (if advancing)
        workflow_histories row
        audit_logs row
        notifications queued
```

### 5.3 Governance assessment to consolidation

```
Governance: completeness review
   → assign tier + classification  (audit records any change from the proposal)
   → recommendation rows created for every unit in classification_review_units
   → units file recommendations in parallel (versioned, never overwriting)
   → when all assigned units have a current recommendation:
        status = PendingConsolidation
   → IT HOU consolidates, records summary, sets governance_route_id
        Light / Moderate → Approved
        Full             → PendingCommitteeDecision
```

### 5.4 The scheduled job

```
cron (every minute)
   → queue:work --stop-when-empty     send queued notifications
   → schedule:run
        itrequest:send-due-reminders       hourly
        itrequest:escalate-overdue         hourly
        itrequest:recompute-due-dates      on demand after a calendar change
```

Both jobs are **idempotent**: each notification records what it sent, so re-running does not
re-send. Without that, an hourly reminder job becomes an hourly spam job.

---

## 6. Identity Architecture

The brief requires Entra ID via OIDC/OAuth 2.0. The organisation demonstrably has it — the
existing SharePoint forms read department and division directly from Microsoft profiles.

The POC uses **local login**, behind an interface:

```php
interface IdentityProvider
{
    public function authenticate(string $email, string $secret): ?Authenticatable;
    public function profileFor(string $identifier): UserProfile;   // department, division, manager
}
```

| Driver | Status |
|---|---|
| `LocalProvider` | POC. Email + password |
| `EntraProvider` | Production. OIDC redirect, then the same profile mapping |

> **`users.entra_object_id` is in the first migration.** Adding it after users exist means
> matching rows to Entra identities by email — which fails for anyone whose email has changed,
> and cannot be verified automatically. It costs one column now and a data-migration project
> later.

**Profile auto-fill.** Department and division are populated from the identity provider when a
request is created, and governance may correct them. Correcting a *request* snapshot is not the
same as correcting a *user* record — see `database-design.md` §5.

---

## 7. Security Model

| Concern | Approach |
|---|---|
| Authentication | Identity provider; session-based. Livewire actions re-check authorisation on every call |
| Authorisation | Policies on every model; permission checked server-side, never inferred from the UI |
| Route protection | Auth middleware on all application routes; only assets and the health endpoint are public |
| Document access | Private disk outside the webroot; streamed through an authorised controller action |
| Mass assignment | `$fillable` on every model; system-managed fields excluded (BR-009) |
| CSRF | Laravel's token on all session-authenticated forms |
| Input validation | FormRequests and Livewire rules; no raw request values reach a query |
| Secrets | `.env` only, never committed. Deploy excludes `.env` and `storage/**` |
| Directory listing | `Options -Indexes`, **committed in `public/.htaccess`** so a deploy cannot remove it |
| Audit integrity | `audit_logs` and `workflow_histories` have no update or delete path |

### Threats considered

| Threat | Mitigation |
|---|---|
| A requestor reads another department's request | Scoped queries; cross-record access answers 404, not 403, so probing reveals nothing |
| An approver approves a request they are not assigned | Authorisation against the `approval_tasks` row |
| A requestor edits system-managed fields | Not mass-assignable; the form does not offer them; the audit would record it |
| An internal governance comment leaks to the requestor | `is_internal` filtered in the query |
| A document is fetched by guessing its path | Files are outside the webroot; access goes through an authorised action |
| A stale `.env` exposes credentials | Excluded from deploy; a public request to `.env` returns 403 on this host |
| A malicious upload | MIME and size validation. **No malware scanning — declared deviation** |

---

## 8. Deployment (cPanel, no SSH)

```
/home/mwstayco/itrequest.mwstay.com/
  public/            ← DOCROOT: index.php, .htaccess, build/
  app/  bootstrap/  config/  database/  resources/  routes/  storage/  vendor/
  artisan            ← application root; .env lives here
  vendor.zip         ← re-extract after any dependency change
```

| Item | Value |
|---|---|
| App root | `/home/mwstayco/itrequest.mwstay.com/` |
| Document root | `/home/mwstayco/itrequest.mwstay.com/public` |
| URL | `https://itrequest.mwstay.com/` |

**Verified 2026-09-24:** the document root was repointed at `public/`, directory indexing was
disabled, and `/cgi-bin/` and `/php.ini` moved out of reach (both now 404). `.env` returns 403.

### Cron

```
* * * * * cd /home/mwstayco/itrequest.mwstay.com && /usr/local/bin/php artisan schedule:run >> /dev/null 2>&1
* * * * * cd /home/mwstayco/itrequest.mwstay.com && /usr/local/bin/php artisan queue:work --stop-when-empty >> /dev/null 2>&1
```

> Both redirect to `/dev/null`, so a wrong PHP path or directory fails **completely silently**.
> To verify, log instead of discarding — any readable output proves cron fired, and the path and
> directory are right. Then switch back.

### Deploy hazards

| Hazard | Handling |
|---|---|
| FTPS is impossible from GitHub runners to this host | Plain FTP, verified permitted |
| The FTP action reconciles and **deletes** remote files not present locally | `.env` and `storage/**` must stay excluded, or a deploy wipes the database password and every document |
| `vendor.zip` is uploaded but never extracted | Manual re-extract after any dependency change |
| The FTP sync-state file travels with a moved folder and lies | `state-name` forced a full re-check on the sibling project |
| Changing the FTP account's directory invalidated its password once | Set the deploy destination in the workflow's `server-dir`, never in cPanel |
| A fresh server has no `storage/` tree | A 500 **with an empty log** means the tree is missing, not that the code is broken. The installer creates it first |

---

## 9. Email — the largest single unknown

FR-010 requires notifications, reminders and escalations. All are email. The sibling project on
this host has `MAIL_MAILER=log`, meaning **no email has been proven to leave this server**.

Until one real message arrives in a real inbox, four required features are unverified.

| If email works | If email does not |
|---|---|
| Proceed as designed | Redesign as in-app notifications the user polls, and declare the deviation |

**This is a Phase C gate, not a Phase H discovery.** Proving it early is cheap; discovering it
after the workflow module is built is not.

---

## 10. Observability

| Concern | Approach |
|---|---|
| Application log | `storage/logs/laravel.log`; daily rotation |
| Security events | Authentication failures, permission denials, configuration changes |
| Queue | Failed jobs table; a failed notification is visible rather than lost |
| Health | `/up` endpoint |
| Cron | Verify by logging, since the normal redirect hides all output |
| Audit | `audit_logs` is itself an observability surface for data changes |

On a host with no shell, **logging is the only diagnostic channel**. A feature that fails
silently is worse than one that fails loudly, because nobody investigates a silent failure.

---

## 11. Future Evolution

| Change | Cost |
|---|---|
| Entra ID SSO | Implement `EntraProvider`; swap the config binding. No schema change |
| Redis | Add the service; change the queue driver. No code change |
| The organisation's holiday API | Implement `HolidaySource`. The holidays table is unchanged |
| Move to Nginx | Standard Laravel config; keep paths in `.env` |
| Historical data import | The SharePoint GUIDs and StaticNames in `IT_Request_Schema.csv` give the migration mapping |
| Delivery / project tracking | A new module beyond `Closed`; the source material does not yet define it |
| Multi-tenancy | A rewrite. Deliberately not attempted — see `blueprint.md` §10 |
