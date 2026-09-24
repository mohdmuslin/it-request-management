# IT Request Management System

A web application for capturing, reviewing, approving, recommending and tracking IT investment
requests — new systems, enhancements, subscriptions and licences, partnerships and other IT
initiatives. It replaces a mix of Microsoft Forms, a SharePoint list, email and spreadsheets with
one controlled workflow, structured data, document management, notifications, dashboards and a
complete audit trail.

**Status:** POC in design. Documents complete; prototype in progress; application not yet built.

---

## Documentation

| Document | What it covers |
|---|---|
| **`docs/blueprint.md`** | **Start here.** Scope, users, workflow, screens, phases, what is out of scope |
| `docs/design.md` | The workflow engine: states, transitions, approvals, due dates, notifications |
| `docs/database-design.md` | Schema, indexes, invariants, migration order — the source of truth for data rules |
| `docs/interface.md` | UI specification: screens, wizard, responsive behaviour, accessibility |
| `docs/architecture.md` | Components, layers, security, deployment, hosting constraints |
| `docs/process-improvement.md` | Ten evidence-based improvements to the current process |
| `docs/project-plan.md` | Phases, milestones, risks, definition of done |
| `docs/compliance-matrix.md` | Every brief requirement traced, plus six declared deviations |

Source material (as supplied): `docs/IT_Request_Management_System_Vendor_Brief.md` (the vendor
brief), `docs/IT Request Form_drawio.xml` (the process flow), `docs/IT_Request.csv` (field
catalogue), `docs/IT_Request_Schema.csv` (SharePoint field schema).

> **Read `docs/` before changing anything involving the workflow, decisions or due dates.**
> Some of it records decisions that are not obvious from the code.

---

## What this is not

**This is not a helpdesk.** An "IT Request" here is a formal request for an initiative, not a
fault to be fixed. There is no ticket, no technician, no triage queue and no priority field.

It is closer to **IT investment governance**: proposals are justified, approved at two levels,
assessed by IT Governance, reviewed by three technical units in parallel, consolidated, and —
where the governance route is Full — decided by an investment committee.

If a change makes it behave more like a ticket queue, it is drifting from the requirement.

---

## The three reference sets

The most important modelling decision, and the easiest to get wrong. These are **three separate
concepts**, decided by different people at different points:

| Set | Values | Decided by | When |
|---|---|---|---|
| **Tier** | Tier 1 · Tier 2 · Tier P (Partnership) | IT Governance | Completeness review |
| **Classification** | New System · Enhancement · Subscription/License · Others | IT Governance | Completeness review |
| **Governance Route** | Light · Moderate · Full | IT HOU | Consolidation |

The requestor **proposes** tier and classification; governance **assigns** them, and both values
are stored. The governance route is an **output of consolidation**, not an input — it is decided
after the technical recommendations are in.

> Collapsing these into one "type" field causes rerouting bugs that are hard to trace.

---

## Stack

- **PHP 8.3+ / Laravel 13** — the brief prefers 8.4+; the host version must be confirmed
- **Blade + Livewire 3 + Tailwind 4** — the brief's named frontend stack
- **MySQL 8.4**
- **Pest 4** for tests, **Pint** for formatting
- **Database queue + cron worker** — the host has no Redis and no worker processes
- **cPanel/LiteSpeed** — no SSH, no cPanel terminal; only cron, File Manager, FTP and the GUI

---

## Local setup

```bash
# PHP, Composer, Node and MySQL come from Laragon and are not on PATH.
# Prepend them per session:
#   $env:Path = 'C:\laragon\bin\php\php-8.3.33-Win32-vs16-x64;C:\laragon\bin\composer;' + $env:Path

composer install
npm install
cp .env.example .env
php artisan key:generate
php artisan migrate --seed
npm run build
php artisan serve
```

### Tests

```bash
php vendor/bin/pest          # feature and unit suites
php vendor/bin/pint --test   # formatting
php vendor/bin/pint          # fix
```

---

## Deployment

Target: `https://itrequest.mwstay.com/`

| Item | Value |
|---|---|
| App root | `/home/mwstayco/itrequest.mwstay.com/` |
| Document root | `/home/mwstayco/itrequest.mwstay.com/public` |

**Verified working (2026-09-24):** the document root points at `public/`, directory indexing is
disabled, and `/cgi-bin/` and `/php.ini` are outside the webroot and return 404.

Four things that cost real time on a sibling project on this host, all documented in
`docs/architecture.md` §8:

1. **FTPS does not work from GitHub runners to this host.** Use plain FTP.
2. **The FTP action reconciles and deletes.** `.env` and `storage/**` must stay excluded, or a
   deploy wipes the database password and every uploaded document.
3. **`vendor.zip` must be re-extracted after any dependency change.** The deploy uploads it but
   cannot unzip it, so the application keeps using the old `vendor/`.
4. **A 500 with an empty log means the `storage/` tree is missing**, not that the code is broken.
   The deploy excludes `storage/**` deliberately, so a fresh server has nowhere to write a log.

---

## Decisions settled

| Question | Decision |
|---|---|
| Tenancy | Single instance per organisation |
| Identity | Configurable: local for the POC, Entra ID for production |
| Business hours | Mon–Fri 09:00–18:00, break 13:00–14:00 → 8 working hours per day |
| Due dates | Per stage, in business days, administrator-editable |
| Urgency | Captured and shown to approvers; does **not** alter due dates |
| Returns | Resume at the stage that returned them, not from the beginning |
| Purpose | A demonstration to win management buy-in |

Six deviations from the brief's preferred stack are declared with justification and a production
plan in `docs/compliance-matrix.md`.

---

## The three things most likely to go wrong

1. **Email has never been proven to work from this host.** Four required features depend on it —
   assignment notification, decision notification, reminders and escalation. If it does not work,
   they all fail silently. This is a Phase C gate, not a late discovery.

2. **The MS Form's field labels are inferred.** The export could not be read, so the wizard's
   field names come from the brief and the field CSV. Every inferred field is marked `EDIT ME`
   in the prototype and in `docs/interface.md`. Correct them there, before the schema is migrated.

3. **The post-consolidation process is undefined.** The process flow ends at an off-page
   connector labelled "PROCEED NEXT STAGE", and its scope is the request form only. The POC
   deliberately ends at `Closed`. If a delivery-tracking process exists, it is a separate phase.

---

## Plan

| Phase | Delivers |
|---|---|
| A. Design | Ten documents |
| B. Prototype | Clickable HTML, all roles and states |
| C. Foundation | Scaffold, auth, roles, layout, CI, email proven |
| D. Request module | Wizard, attachments, request detail |
| E. Workflow module | Approval chain, decisions, returns, delegation |
| F. Governance module | Tier, classification, recommendations, consolidation, committee |
| G. Reporting and admin | Dashboards, exports, configuration |
| H. Testing and handover | Tests, deploy, runbook, guides |

See `docs/project-plan.md` for milestones, risks and exit criteria.
