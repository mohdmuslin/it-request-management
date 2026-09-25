# Project Plan — IT Request Management System (POC)

Companion documents: `blueprint.md` (scope), `compliance-matrix.md` (traceability),
`architecture.md` (technical constraints).

**Purpose:** a POC built to win management buy-in. Every phase is judged by whether it makes the
workflow more *demonstrable*, not by how production-ready it is.

---

## 1. Approach

| Principle | Consequence |
|---|---|
| Prototype before code | Field names are unconfirmed. Correcting a prototype costs minutes; correcting a migrated schema costs a day |
| Every role walkable | A demonstration that shows only the requestor's view does not convince a committee |
| Prove the risky thing first | Email is unproven on this host and four features depend on it |
| Depth traded for breadth | Where a choice arises, prefer showing the whole journey over completing one screen |
| Declare, do not hide | Six deviations are stated with justification and a production plan |

**Sequence is aligned to the brief's own §8 phases**, so the plan maps onto the procurement
document rather than sitting beside it.

---

## 2. Phases

### Phase A — Design ✅ Complete

| Deliverable | Status |
|---|---|
| `blueprint.md` | ✅ |
| `database-design.md` | ✅ |
| `design.md` | ✅ |
| `architecture.md` | ✅ |
| `interface.md` | ✅ |
| `process-improvement.md` | ✅ |
| `compliance-matrix.md` | ✅ |
| `project-plan.md` | ✅ |

**Done when:** the documents are reviewed and the field list in `interface.md` §4 is corrected.

---

### Phase B — Clickable prototype

| | |
|---|---|
| **Delivers** | `prototype/index.html` — one self-contained file, Tailwind via CDN, no build, no database |
| **Covers** | All 13 screens, all 9 roles switchable, all 14 states reachable |
| **Exit criteria** | You can walk the full journey from submission to closure; the flow reads correctly; incorrect fields are identified |

**Done when:** you have clicked through it and said the flow is right.

Runs before any PHP, deliberately. The request form's field labels are inferred, and this is how
they get corrected cheaply.

---

### Phase C — Foundation

| | |
|---|---|
| **Delivers** | Laravel scaffold, Livewire, Tailwind, layout shell, 9-menu navigation, roles and permissions, departments and divisions, reference-data seeders, local authentication, CI |
| **Exit criteria** | A real user logs in and lands on the correct dashboard for their role |
| **Gate** | **One real email is received from this host.** Not "MAIL_MAILER is set" — an actual message in an actual inbox |

**Done when:** login works per role, and the email gate passes. If email cannot be made to work,
stop and redesign notifications before Phase E.

> **This is the riskiest phase, not because it is complex but because it is where the email
> unknown is resolved.** Four required features depend on it: assignment notification, decision
> notification, reminders and escalation.

#### Progress

| Item | Status |
|---|---|
| Laravel 13.17 scaffold, PHP ^8.3 | ✅ Done |
| Livewire 4.4 (class-based), Tailwind 4 | ✅ Done |
| Pest 4.7 with PHPUnit 12.5.24 pinned | ✅ Done |
| Migrations — 17 tables | ✅ Done |
| Models — 20, with relationships and scopes | ✅ Done |
| `config/itrequest.php` | ✅ Done |
| Reference data seeder (9 roles, 3 tiers, 4 classifications, 3 routes, 3 units, 8 stages) | ✅ Done |
| `BusinessCalendar` — business-time arithmetic | ✅ Done |
| Layout shell, role-filtered navigation, dashboard | ✅ Done |
| Local sign-in with per-role landing | ✅ Done |
| Routes for all nine menus, with named placeholders | ✅ Done |
| Demo accounts for all nine roles (local environment only) | ✅ Done |
| 26 tests passing, Pint clean | ✅ Done |
| **Email proven from the host** | ⬜ Deferred to 25 Sep — the gate for Phase E |
| `ci.yml` | ⬜ Next |

**Six defects were found by testing, all of which would have been silent in production:**

1. **`addBusinessDays` returned the same day.** It multiplied hours rather than advancing days, so
   one business day from Thursday completed *on Thursday*.
2. **No stage received a due date.** The config keys did not match the `WorkflowStage` enum
   values, and the seeder skipped unresolvable keys silently. Every request would have had no
   deadline.
3. **Seven of nine roles 500'd on sign-in.** `landingRoute()` returned bare route names against
   dotted ones. The login form stayed on screen with the button showing "Signing in…", so it read
   as a slow login rather than a server error.
4. **Every request relationship guessed the wrong foreign key.** Laravel derives it from the model
   name, so `ItRequest` produced `it_request_id` against a `request_id` column — a "no such column"
   error at query time.
5. **Every full-page component 500'd on the Livewire default layout**, which expects a view
   namespace this project does not register.
6. **The administrator's dashboard told them they had no role.** No branch matched, so the empty
   state fired and blamed a missing role — for the most privileged user in the system.

Defects 3, 5 and 6 share a shape worth noting: **each looked like something other than a failure.**
A slow login, a missing layout, an empty screen. None said "error", and all three were found only
by signing in as every role in turn — which is why that is now a test rather than a manual step.



---

### Phase D — Request module

| | |
|---|---|
| **Delivers** | Five-step wizard, Sections A and B, the business-plan conditional block, draft save and resume, attachments to private storage, system-generated request numbers, my-requests list, request detail with status timeline and audit tab |
| **Exit criteria** | A requestor submits a complete request; governance can see it |

**Done when:** UAT-001, UAT-002 and UAT-003 pass.

---

### Phase E — Workflow module

| | |
|---|---|
| **Delivers** | Approval chain with stage and sequence, Owner and Sponsor decisions, return-to-returning-stage, mandatory comments on reject and return, delegation, notifications for assignment and decision |
| **Exit criteria** | A request clears Owner then Sponsor; a return goes back only to the returning stage and resumes correctly |

**Done when:** UAT-004, UAT-005, UAT-007 and UAT-012 pass, and the tests prove prohibited
transitions are refused.

#### Progress

| Item | Status |
|---|---|
| `WorkflowDecisionService` — approve, conditions, return, reject | ✅ Done |
| Mandatory comment on return and reject (BR-002) | ✅ Done |
| Conditions required for a conditional approval | ✅ Done |
| Owner → Sponsor chain, then handover to governance | ✅ Done |
| Return resumes at the returning stage (BR-007) | ✅ Done |
| Delegation — table, model, screen, `delegated_from_id` capture (FR-014, BR-008) | ✅ Done |
| Approvals queue, scoped by the decision service rather than a where clause | ✅ Done |
| Decision panel with conditions and mandatory comments | ✅ Done |
| Edit and resubmit an amended request | ✅ Done |
| Notifications — assignment, decision, reminder, escalation | ✅ Done (mail gated off) |
| `itrequest:notify-approvals` scheduled hourly, idempotent, `--dry-run` | ✅ Done |
| Queue worker on cron | ✅ Done |
| **Email proven from the host** | ⬜ **Still the gate — see below** |
| 153 tests passing, Pint clean | ✅ Done |

**Four defects were found by walking the application, none of them by the 140 tests that passed
afterwards.** Every one was a feature that worked in the service and was unreachable or wrong in
the interface:

1. **"Edit draft" opened a blank form.** It linked to `requests.create` and passed no request id;
   the `requests.edit` route did not exist at all.
2. **A returned request had no way back into the chain.** BR-007's resume behaviour was correct in
   the service and proved by tests — and a requestor whose request came back could only look at it.
3. **Editing a returned request and submitting restarted the chain.** `persist()` called `submit()`
   unconditionally, which resets the stage to Project Owner, so a request the Sponsor had returned
   went back to the Owner and the returning stage was silently discarded.
4. **The queue mislabelled delegated work.** It read `delegated_from_id`, which is written *after* a
   decision, so a delegate saw no "acting for" marker on the tasks they were about to decide —
   only on ones somebody else had already decided.

**Two service-level issues were caught while building, before they shipped:**

- `completeApprovals()` would have called `close()`, marking a request Closed the moment its
  Sponsor approved — skipping the entire governance phase while looking like a successful approval.
- The history trail mixed vocabularies, writing `approved` from the decision but `return` from an
  internal label, so a reader saw "approved, return, approved" and could not tell whether `return`
  and `returned` were different events.

> **Delegation needed a table, not a column.** `approval_tasks.delegated_from_id` records that a
> decision *was* made on someone's behalf. Nothing could say a delegation was *in force*, so a
> delegate could not see what was waiting on them without being asked — which is the whole point of
> the feature. The column was not wrong; it answered a different question than the one the use case
> asked.

---

### Phase F — Governance module

| | |
|---|---|
| **Delivers** | Completeness review, tier and classification assignment, per-unit recommendations that never overwrite, consolidation, governance-route determination, committee step for Full only, committee decision with conditions, closure |
| **Exit criteria** | A Full-route request reaches and clears a committee decision; three units file independent recommendations |

**Done when:** UAT-006, UAT-008, UAT-009 and UAT-010 pass.

#### Progress

| Item | Status |
|---|---|
| `GovernanceService` — assess, recommend, consolidate, committee, close | ✅ Done |
| `CompletenessAssessment` model + table | ✅ Done |
| Reason required when governance changes the requestor's proposal | ✅ Done |
| Per-unit recommendations, versioned, never overwritten (FR-008, BR-005) | ✅ Done |
| Reviewer must belong to the unit they file for | ✅ Done |
| Request advances only when every assigned unit has filed | ✅ Done |
| Consolidation sets the route; stored on request **and** consolidation row | ✅ Done |
| Committee step for Full only, driven by `requires_committee` | ✅ Done |
| Closure blocked until BR-006 is satisfied, with the blockers listed | ✅ Done |
| Committee workspace — the Full-route agenda | ✅ Done |
| `RecommendationOutcome` enum | ✅ Done |
| 215 tests passing, Pint clean | ✅ Done |

**One defect was found by a test failing for the wrong reason, and it was the most serious of the
phase.**

**`$request->governance_route` returned `null`.** The column is `governance_route_id`, but the
relation is `governanceRoute()` — Eloquent resolves relations by method name, and reading the
snake_case form does not raise an error. It returns null, so `?->requires_committee` was null, the
condition was false, and **the BR-006 committee check never fired**. A Full-route request could have
been closed without the IT Investment Committee ever deciding — the single outcome the Full route
exists to prevent.

It failed open silently: a missing blocker produces an empty array, and an empty array means
"closure is permitted". Nothing logged, nothing threw, and the guard reported success. It was
caught only because a test asserting the blocker was *missing* failed, rather than because anything
in the application complained.

**Three more defects, each a screen telling the user something untrue:**

1. **A phantom approval task blocked every closure.** `advance()` created an approval task for
   *every* stage, but the completeness review is an assessment, not a decision — so an undecidable
   task sat at `completeness_review` for the life of the request and BR-006 refused closure. The
   governance screens never showed it, because they filter on `current_stage` rather than on tasks.
   Now driven by the `workflow_stages.is_approval` flag that already existed.
2. **The governance workspace reported "0 requests in the governance process"** while a request was
   with the committee. The tab list did not include the committee stage, so a request that had not
   finished had also left every tab the screen knew about — a queue lying to the person whose job
   is to watch the process.
3. **Three screens rendered raw database values.** The dashboard Stage column showed
   `committee_decision`, the governance record showed `recommended_with_conditions`, and the history
   timeline showed `project_owner → returned_for_amendment`. Every one was accurate and none was
   readable — and in the dashboard's case it was a table a manager reads.

> **All three passed the existing test suite**, because no test asserted what the rendered text
> *said*; they asserted that rows existed. `LabelRenderingTest` now asserts the absence of the raw
> values on each surface, which is the assertion that catches this class.

> **`recommendations.recommendation` had no vocabulary.** The column was a bare string from the
> first migration, so three units could have filed `recommended`, `Recommended` and `yes`, and the
> consolidation would have had to treat them as three different positions. `RecommendationOutcome`
> defines four cases, with conditions required for a conditional recommendation and a reason
> required for advising against.

---

### Phase G — Reporting and administration

| | |
|---|---|
| **Delivers** | Dashboard, workload and aging, turnaround and outcomes, filters and exports, administration of reference data, users and roles, holidays and stage due days |
| **Exit criteria** | Management answers "are we meeting our targets?" from the screen, without a spreadsheet |

**Done when:** UAT-014 passes and export totals reconcile with the transactional records.

#### Progress

| Item | Status |
|---|---|
| `ReportingService` — workload, aging, turnaround, outcomes, stage durations, overdue | ✅ Done |
| Reports screen with ten filters, all linkable via the URL | ✅ Done |
| CSV export carrying the same filters as the screen | ✅ Done |
| Administration — reference data (4 kinds) | ✅ Done |
| Administration — due dates and holiday calendar | ✅ Done |
| Administration — users, roles and review-unit membership | ✅ Done |
| 270 tests passing, Pint clean | ✅ Done |

**Reconciliation is structural, not maintained.** `ReportingService::query()` is the single
entry point and every figure is derived from the builder it returns, so a total cannot disagree
with the list it summarises. The export takes the same querystring the report publishes and
applies it through the same `applyFilters()`, so the file and the screen contain the same set.

**Aging walks the business calendar.** Dividing elapsed hours by eight gives a wrong answer either
side of a weekend — Monday 09:00 back to Friday 17:00 is 64 hours, which reads as eight business
days and is actually one — and subtracting dates and scaling by five-sevenths is wrong again
across a public holiday. Counting the working days that have actually elapsed cannot be wrong in
either case.

**Turnaround headlines the median.** One request returned three times and left open for two months
drags the average away from every other request, so the average describes a turnaround nobody
experienced. With nothing closed the figure is `null`, not `0` — "0 hours" reads as "we are
instant", which is the opposite of "we have not finished anything".

**Three defects were found by testing:**

1. **`Holiday` stored `2026-12-25 00:00:00`.** Laravel's `date` cast serialises through
   `fromDateTime()`, and MySQL's DATE column truncates that back — so it works on MySQL and breaks
   on SQLite, where the whole string is stored. The `unique:holidays,date` validation then found no
   match, passed, and the database rejected the insert: the user saw a database error instead of
   "that date is already in the calendar". A mutator now writes the date portion on both drivers.
2. **The export controller called `$this->authorize()`** and the base controller in Laravel's slim
   skeleton does not include `AuthorizesRequests` — a 500 where a 403 belongs.
3. **`scripts/dev-advance-request.php` walked a request backwards.** It looped "while not at
   completeness review, decide whatever task is pending", so run against a request already at the
   committee it decided the *committee's* task and produced a state no real sequence creates. A
   pending task is not evidence that its stage is the next one due.

> **Deactivation replaces deletion throughout.** Every reference table and the user table are
> referenced by requests that already exist; deleting a tier would orphan them and deleting an
> account would lose the name of the person who approved something. Options are deactivated instead,
> which removes them from the pickers and leaves every existing record intact. The last active
> option of a kind cannot be deactivated, and an administrator cannot deactivate themselves or drop
> their own administrator role — the one change that can lock everybody out.

---

### Phase H — Testing, deployment and handover

| | |
|---|---|
| **Delivers** | Feature tests for every permitted **and prohibited** transition, UAT run-through, installer command, deploy workflow, runbook, administrator and user guides |
| **Exit criteria** | Live at `https://itrequest.mwstay.com/` with a real request submitted end to end |

**Done when:** a request raised through the live system is approved, consolidated and closed, and
the audit trail is complete.

#### Progress

| Item | Status |
|---|---|
| `itrequest:install` — one-shot, refuses to re-run | ✅ Done |
| `itrequest:deploy` — routine post-upload steps, safe to repeat | ✅ Done |
| `itrequest:deploy-check` — read-only pre-flight validator | ✅ Done |
| `itrequest:set-password` — the only password reset without a shell | ✅ Done |
| `itrequest:make-user` — create an account when nobody can sign in | ✅ Done |
| `.github/workflows/deploy.yml` — build, verify, upload, arm the hook | ✅ Done |
| `docs/deployment.md` — runbook | ✅ Done |
| `docs/administrator-guide.md` | ✅ Done |
| `docs/user-guide.md` | ✅ Done |
| `docs/compliance-matrix.md` — final status per requirement | ✅ Done |
| `itrequest:uat` — the 15 acceptance scenarios, executable | ✅ Done |
| 310 tests passing, Pint clean | ✅ Done |
| UAT run-through against the live site | ⬜ Blocked on the email gate |
| **Document upload (FR-006)** | ⬜ **Not built** — see below |
| **Recommendations and Audit log screens** | ⬜ **Placeholders** — see below |

#### What the matrix review found

Writing the compliance matrix's final status meant reading every requirement against what
actually exists, and three things it had been reporting as met were not.

**1. Documents were never built (FR-006).** The `attachments` table, the `Attachment` model,
the MIME allow-list, the size cap and the private storage directory all exist — and there is
no upload action and no download action anywhere in the application. The request detail screen
eager-loads `attachments` and the view never renders them. The wizard's document step, listed
in `interface.md` §4 as step 5 of 5, is not one of the four steps that exist.

The consequence is worse than a missing feature. `GovernanceService::closureBlockers()` returns
a blocker reading *"All mandatory decisions and documentation are recorded"* and checks only
the decisions — so **BR-006's documentation half is unenforced** and a request closes with no
supporting evidence at all. The check is honest about its intent and does not implement it,
which is the failure mode that survives review: the code says the right thing.

**2. Two role landing pages are placeholders.** `UserRole::landingRoute()` sends an Auditor to
`admin.audit.index` and a Technical Reviewer to `recommendations.index`. Both render a panel
saying the screen arrives in a later phase. **So an Auditor signs in and is told the screen
does not exist yet** — while the audit trail it would show is complete and append-only.

**3. Autosave and editable templates were never built.** `interface.md` §4 specifies autosave
every 30 seconds; there is only the explicit **Save draft** button. FR-013 covers templates as
well as reference data; templates are fixed strings.

Each is now declared in `compliance-matrix.md` as D-7, D-8 and D-9 — **incomplete
requirements, not deferrals**, because a deferral is a decision and these were oversights.

> **Why this is recorded rather than quietly fixed.** The three gaps are small and could have
> been closed without anyone noticing they had been open. A status table that reported them as
> done is what a handover would have carried forward, and the next person to read it would have
> assumed the documents were somewhere. The value of the matrix is that it can be wrong out
> loud.

**The installer closes a gap that would have made a production install unusable.** `DemoUserSeeder`
refuses outside `local`/`testing` — deliberately, because it creates accounts with a known password
and publishing credentials is how the sibling project ended up with live accounts anybody could sign
into. But that left a fresh production install seeded by `db:seed` with **zero users**, and every
screen sits behind `auth`. Nobody could sign in, and there was no way out except SQL.

The installer creates the first administrator with a **generated** password rather than a published
default, and prints it once — written to the log as well, because cron output usually goes to
`/dev/null` and the printed value is frequently lost.

**Two defects were found while building the commands:**

1. **`warn()` and `error()` write to STDERR**, so a redirected `itrequest:deploy-check` report
   contained the summary — "14 warning(s)" — and none of the 14. On this host the log file *is* the
   report, so a checker that loses half its content when redirected defeats its own purpose. Every
   finding now goes to stdout, with the severity in the mark.
2. **`config:cache` replaces the config repository mid-process** and forces the container to
   rebuild. Against an in-memory SQLite database that opens a *new* connection, which is a *new,
   empty* database — so the command's own verification step reported `no such table:
   workflow_stages`, a failure it had caused itself. The cache steps now run only in production,
   where the database is real and persists across connections.

> **The post-deploy flag is created BEFORE the upload, not after.** A flag created afterwards would
> need a second transfer to deliver it, and that transfer can fail after the code has already
> landed — shipping the release with the migrations never run and nothing to say so. Created first,
> it travels in the same pass: either both arrive or neither does.

> **Plain FTP, not FTPS — and the reason is recorded in the workflow rather than left as a puzzle.**
> The control connection works over TLS; the failure is on the data socket. It is not size or speed:
> 8,599 files failed in 62 minutes, and 250 files plus one archive failed in 4 minutes with the
> identical error. `.env` is excluded from the transfer, so the database password, the app key and
> any uploaded document never cross the wire — the only credential exposed is one FTP account
> scoped to this directory.

---

## 3. Milestones

| # | Milestone | Signal |
|---|---|---|
| M1 | Design approved | Documents reviewed; field list corrected |
| M2 | Prototype approved | You walked the journey and the flow is right |
| M3 | Foundation proven | A real user logged in; **a real email received** |
| M4 | Request demonstrated | A request submitted and visible to governance |
| M5 | Workflow demonstrated | Two-tier approval with a return, end to end |
| M6 | Governance demonstrated | Full route reaching a committee decision |
| M7 | Reporting demonstrated | Dashboards reconciling with source records |
| M8 | Handover | Live, with a real request closed |

---

## 4. Dependencies

| Dependency | Needed by | Status |
|---|---|---|
| Host PHP version confirmed | Phase C | **Open** |
| Email working from the host | Phase C gate | **Open — highest risk** |
| Field labels and option lists confirmed | Phase D | Partial — the prototype surfaces them |
| Which units review which classification | Phase F | Defaulted to all three; a data change |
| Committee operating model (voting vs recording) | Phase F | Assumed recording; deviation D-4 |
| MySQL database and user created | Phase C | Open |
| FTP account | Phase H | Open |
| GitHub repository | Phase C | Open — neutral name, no cafe or MW Stay reference |
| Retention and disposal policy | Production | Open; not POC-blocking |

---

## 5. Risks

| # | Risk | Likelihood | Impact | Mitigation |
|---|---|---|---|---|
| 1 | **Email cannot be made to work on this host** | Medium | High | Phase C gate. If it fails, redesign as in-app notifications and declare the deviation |
| 2 | Field labels are wrong | High | Medium | Prototype first; every inferred field marked `EDIT ME` |
| 3 | Host PHP is older than required | Low | High | Verify before pinning the framework version |
| 4 | Approver unavailability stalls requests | High | Medium | Delegation plus escalation — the point of improvement #1 |
| 5 | Post-consolidation process undefined | Certain | Medium | POC scoped to `Closed`; recorded as an assumption |
| 6 | Process knowledge concentrated in one person | Medium | Medium | Assumptions written down for correction, not kept implicit |
| 7 | The POC is judged against production expectations | Medium | High | `compliance-matrix.md` states scope and every deviation |
| 8 | Scope creep toward helpdesk behaviour | Medium | High | `blueprint.md` §1 states the non-goal explicitly |

**Risk 2 is high-likelihood and cheap to absorb** — which is precisely why the prototype comes
before the code.

---

## 6. Definition of Done

A phase is done when its exit criteria are met **and**:

- [ ] `php vendor/bin/pest` passes, including the prohibited-transition tests
- [ ] `php vendor/bin/pint --test` passes
- [ ] No new `@EDIT ME` marker without a corresponding entry in the open-questions table
- [ ] Every new configuration value appears in `config/itrequest.php` with a comment explaining
      why it exists, not merely what it does
- [ ] Documented behaviour matches actual behaviour — where they differ, the document is
      corrected, not quietly left

**The whole POC is done when** a request can be raised, approved at two levels, assessed,
reviewed by three units, consolidated, decided and closed — with a complete audit trail — and
management has seen it demonstrated.

---

## 7. Team and Effort

The brief's §8.2 lists a ten-role vendor team. **That is appropriate for the production build,
not for a POC.** Being explicit about the difference prevents the plan being judged against a
proposal-scale resourcing model.

| Concern | POC | Production build |
|---|---|---|
| Roles | One developer, with the business owner as the process authority | The brief's ten-role team |
| Documentation | Design docs, as delivered | Plus a data dictionary, security design, interface design and traceability matrix |
| Testing | Feature tests for transitions and the business calendar | Unit, feature, integration, security, performance, backup-recovery, UAT and regression |
| Environments | Development and production | Development, test/UAT and production |

---

## 8. Governance

Kept deliberately light for a POC, but not absent:

| Practice | Cadence |
|---|---|
| Progress against this plan | At each milestone |
| Assumption review | When an `@EDIT ME` is resolved |
| Decision log | The "Decisions Settled" table in `blueprint.md` §10 |
| Risk review | At each phase gate |
| Change control | Any change to `blueprint.md` §8 (out of scope) is a scope decision, not an implementation detail |

---

## 9. What "Good" Looks Like

A finished POC is one where a manager who has never seen the system can watch a request move from
submission to closure, ask "who decided that, and when?", and get an answer from the screen.

If they instead ask "so it's like a ticket system?" — the design has drifted, and `blueprint.md`
§1 is the correction.
