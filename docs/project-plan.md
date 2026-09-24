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
| Livewire 4.4, Tailwind 4 (already in the skeleton) | ✅ Done |
| Pest 4.7 with PHPUnit 12.5.24 pinned | ✅ Done |
| Migrations — 17 tables | ✅ Done |
| Models — 20, with relationships and scopes | ✅ Done |
| `config/itrequest.php` | ✅ Done |
| Reference data seeder (9 roles, 3 tiers, 4 classifications, 3 routes, 3 units, 8 stages) | ✅ Done |
| `BusinessCalendar` — business-time arithmetic | ✅ Done |
| 22 tests passing, Pint clean | ✅ Done |
| Layout shell, navigation, authentication | ⬜ Not started |
| **Email proven from the host** | ⬜ Not started — the gate |

**Two defects were found by the tests, both of which would have been silent in production:**

1. **`addBusinessDays` returned the same day.** It was implemented as "N × hours in a day", so
   one business day from Thursday completed *on Thursday*. A business user would say that is due
   Friday. Fixed to advance whole working days while preserving the time of day.
2. **No stage received a due date.** The config keys (`pending_project_owner`) did not match the
   `WorkflowStage` enum values (`project_owner`), and the seeder skipped unresolvable keys
   silently. Every request in the system would have had no deadline, with nothing reporting a
   problem. Fixed, and the seeder now throws instead of skipping.

Neither would have produced an error message. Both would have surfaced much later as "the due
dates are wrong".


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

---

### Phase F — Governance module

| | |
|---|---|
| **Delivers** | Completeness review, tier and classification assignment, per-unit recommendations that never overwrite, consolidation, governance-route determination, committee step for Full only, committee decision with conditions, closure |
| **Exit criteria** | A Full-route request reaches and clears a committee decision; three units file independent recommendations |

**Done when:** UAT-006, UAT-008, UAT-009 and UAT-010 pass.

---

### Phase G — Reporting and administration

| | |
|---|---|
| **Delivers** | Dashboard, workload and aging, turnaround and outcomes, filters and exports, administration of reference data, users and roles, holidays and stage due days |
| **Exit criteria** | Management answers "are we meeting our targets?" from the screen, without a spreadsheet |

**Done when:** UAT-014 passes and export totals reconcile with the transactional records.

---

### Phase H — Testing, deployment and handover

| | |
|---|---|
| **Delivers** | Feature tests for every permitted **and prohibited** transition, UAT run-through, installer command, deploy workflow, runbook, administrator and user guides |
| **Exit criteria** | Live at `https://itrequest.mwstay.com/` with a real request submitted end to end |

**Done when:** a request raised through the live system is approved, consolidated and closed, and
the audit trail is complete.

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
