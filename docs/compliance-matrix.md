# Compliance Matrix — IT Request Management System (POC)

Requirement-by-requirement traceability against the vendor brief, plus the **declared deviations**
the brief requires (§11.1).

**Status key:**

| Mark | Meaning |
|---|---|
| ✅ | **Comply.** Implemented and demonstrated |
| 🟡 | **Partial.** Part of the requirement is met, and the missing part is named |
| 🔵 | **Unproven.** Implemented, but there is no evidence it works — the claim is an assertion |
| ⚪ | **Deferred.** Out of POC scope by decision, with a reason and a production plan |
| ⛔ | **Not built.** A stated requirement that is absent |

The brief is a sourcing document, so a deviation is not a failure — it is a statement that must
be made and justified. Every ⚪ below has a reason and a production plan.

> **🔵 and ⛔ are different from ⚪, and the difference matters.** A deferral is a decision. 🔵 and
> ⛔ are things that were not done — and every one of them was found by reading this table against
> the source, not by reading the table. `D-7` to `D-10` record them.

> **Every row below was verified against the code, not against the design documents.** That is a
> correction in method as well as in content: seven rows previously cited classes, columns and
> checks that exist only as prose in `architecture.md` and `interface.md`. A design document
> describes what should be built, and reading one as though it described what *was* built is how
> this table came to be wrong.

### At a glance

| Group | ✅ | 🟡 | 🔵 | ⚪ | ⛔ | Total |
|---|---|---|---|---|---|---|
| Functional (FR-001…015) | 9 | 5 | — | — | 1 | **15** |
| Non-functional (NFR-001…010) | 4 | 3 | 1 | 2 | — | **10** |
| Business rules (BR-001…010) | 7 | 3 | — | — | — | **10** |
| Acceptance (UAT-001…015) | 12 | 2 | — | 1 | — | **15** |
| **Total** | **32** | **13** | **1** | **3** | **1** | **50** |

**Read the 🟡 count as the headline.** Thirteen of fifty rows are partial, and every one of them
names what is missing rather than saying "mostly done" — because the useful question at handover
is not *how many requirements are green* but *which specific things still have to happen*.

---

## Functional Requirements

| ID | Requirement | Status | Where / note |
|---|---|---|---|
| FR-001 | Sign in using approved enterprise identity and SSO | 🟡 | Local email + password only. **No `IdentityProvider` interface exists** — the config array and the `entra_object_id` column do, and nothing reads either. See D-10 |
| FR-002 | Retrieve or maintain requestor department, division and reporting data | 🟡 | Department and division default from the signed-in user's own record and are editable. **Not from an identity provider** — there is no profile retrieval. Names are resolved live, so renaming a department changes how historical requests read |
| FR-003 | Save incomplete requests as drafts | ✅ | `Draft` state and an explicit **Save draft** at every step. **Autosave is not implemented** — see D-9. `interface.md` §4 |
| FR-004 | Validate mandatory and conditional fields before submission | ✅ | FormRequest + Livewire rules; the business-plan conditional block. `interface.md` §4 |
| FR-005 | Unique configurable request number | ✅ | Generated on submit, immutable. `database-design.md` §5 |
| FR-006 | Upload, categorise, preview and download authorised documents | ⛔ | **Not built.** See D-7 |
| FR-007 | Support approve, reject and return-for-amendment | ✅ | The transition table. `design.md` §1.3 |
| FR-008 | Multiple units submit independent recommendations without overwriting | ✅ | `recommendations` with `version_no`. `database-design.md` §5 |
| FR-009 | Route by tier, classification, decision and governance route | ✅ | Route set at consolidation; `requires_committee` drives Full only |
| FR-010 | Notify users of assignments, decisions, reminders and escalations | 🟡 | Designed and built, **but email is unproven on this host**. `architecture.md` §9 |
| FR-011 | Every material action recorded with actor and timestamp | ✅ | `workflow_histories` + `audit_logs`, in-transaction |
| FR-012 | Filters, dashboards and exports | ✅ | Reports module |
| FR-013 | Administrators manage reference data and templates | 🟡 | Reference data, calendar and users are built. **Notification templates are fixed in code, not editable** — see D-9 |
| FR-014 | Temporary delegation preserving original and acting approvers | ✅ | `approval_tasks.delegated_from_id`. `design.md` §2.2 |
| FR-015 | Search by number, title, status, owner, unit, tier, date | 🟡 | Number and title searched together; status, owner, tier, classification, route, department and date are filters. **No unit filter exists.** Full-text search deferred; `LIKE` at POC volumes |

---

## Non-Functional Requirements

| ID | Requirement | Status | Note |
|---|---|---|---|
| NFR-001 | Least privilege, secure coding, encryption, secrets, vulnerability remediation | 🟡 | Policies, validation, private storage, `.env` secrets. **`vendor/` is not patchable on this host without a manual re-extract** — documented |
| NFR-002 | Measurable response-time and throughput targets | ⚪ | Deferred. Needs validated usage volumes, which are an open discovery question |
| NFR-003 | Availability, maintenance and restoration commitments | ⚪ | Deferred. A host-level commitment, not an application feature |
| NFR-004 | Scale users, requests, documents and workflow volume without redesign | 🔵 | Indexed single-table queries and no design element assumes POC volumes — but **no load test, no measured throughput, and no stated target**. The claim is a design property, not a demonstrated one |
| NFR-005 | Keyboard use, readable contrast, labels, validation feedback | ✅ | `interface.md` §8 |
| NFR-006 | Critical records immutable to ordinary users; retention per policy | ✅ | No update or delete path on `audit_logs` or `workflow_histories`. Retention policy deferred |
| NFR-007 | Documented standards, automated tests, modular code, controlled configuration | ✅ | Pest feature tests, Pint, services layer, config-driven behaviour |
| NFR-008 | Reproducible across development, test and production | ✅ | Migrations, seeders, installer command, CI |
| NFR-009 | Application logs, security logs, health checks, queue monitoring, alerting | 🟡 | Logs, `/up`, failed-jobs table. **Alerting deferred** — no external monitoring service |
| NFR-010 | Data protection per Client requirements and applicable obligations | 🟡 | Access-controlled storage and audit. **No documents are uploaded, so there is no document protection to test** — D-7. Retention and disposal policy deferred |

---

## Business Rules

| ID | Rule | Status | Enforced by |
|---|---|---|---|
| BR-001 | Submitted requests not deletable by ordinary users | ✅ | Policy; `Withdrawn` is a status, not a deletion |
| BR-002 | A rejection or return requires comments | ✅ | Enforced in `WorkflowDecisionService::assertDecisionIsAllowed()`, **not at the database** — `approval_tasks.comments` is nullable. The service is the only way a decision is recorded, so the rule holds, but a direct insert would not be refused |
| BR-003 | Conditional documents and fields driven by tier and classification | 🟡 | A conditional block exists, but it is driven by **business-plan status** and **urgency** — neither is a tier or a classification. `classification_review_units` decides *who reviews*, which is routing, not field conditionalism. **No field is conditional on tier or classification** |
| BR-004 | The approver cannot modify the requestor's justification | ✅ | The approval action writes `approval_tasks` only |
| BR-005 | Recommendations versioned or preserved, never overwritten | ✅ | `version_no` inserts a new row |
| BR-006 | Closure requires all mandatory decisions and documentation | 🟡 | **Decisions enforced; documentation not.** See D-7 |
| BR-007 | A returned request resumes at the configured stage | ✅ | Returning stage recorded on the transition |
| BR-008 | Delegated decisions capture delegated-from and acting users | ✅ | `delegated_from_id` separate from `approver_id` |
| BR-009 | System-managed fields not editable through ordinary screens | 🟡 | `status`, `current_stage`, `tier_id`, `classification_id` and `governance_route_id` **are mass-assignable**. Protected because every form maps fields explicitly and the wizard documents why — so it holds by discipline rather than by the model refusing. A future `fill($request->all())` would open it silently |
| BR-010 | Timestamps stored consistently, displayed in the configured timezone | ✅ | UTC storage, `Asia/Kuala_Lumpur` display |

---

## Acceptance Scenarios (from brief §9.2)

| ID | Scenario | Status | Note |
|---|---|---|---|
| UAT-001 | Requestor saves and resumes a draft | ✅ | |
| UAT-002 | Mandatory and conditional validation prevents incomplete submission | 🟡 | Conditional **fields** enforced; conditional **documents** cannot be — D-7 |
| UAT-003 | Unique request number generated without duplication | ✅ | |
| UAT-004 | Project Owner approves and the workflow advances | ✅ | |
| UAT-005 | Project Sponsor rejects with mandatory comments | ✅ | |
| UAT-006 | Governance returns an incomplete request for amendment | ✅ | The **return** works and is enforced; the "missing attachment" case cannot arise, because there are no attachments — D-7 |
| UAT-007 | Resubmitted request resumes at the correct stage | ✅ | |
| UAT-008 | Multiple technical units submit separate recommendations | ✅ | |
| UAT-009 | Consolidator records conditions and the governance route | ✅ | |
| UAT-010 | A Full-route request reaches the committee decision stage | ✅ | |
| UAT-011 | An unauthorised user cannot view or decide a restricted request | ✅ | |
| UAT-012 | The audit trail contains actor, timestamp, transition and comments | ✅ | The trail is complete; the **screen** that displays it globally is a placeholder — D-8 |
| UAT-013 | Reminder and escalation triggered according to configuration | 🟡 | Scheduled command and idempotency work; delivery depends on email being configured on this host |
| UAT-014 | Dashboard totals reconcile with transactional records | ✅ | |
| UAT-015 | Backup restored and validated in a controlled test | ⚪ | Deferred to production; a host-level operation |

---

## Declared Deviations

The brief permits alternatives with justification and requires material deviations to be stated
(§2.1, §11.1). Six are declared as deviations, and four more — D-7 to D-10 — as
**incomplete requirements**, recorded because a matrix that reports a missing requirement as
satisfied is worse than one that reports nothing.

**D-7 to D-10 are not deferrals.** A deferral is a decision that something is out of scope for
the POC, made deliberately and stated. These four are requirements that this matrix reported as
met and were not. Three are absent features; **D-10 is worse — a described architecture that was
never written.** They are listed so the gap is visible and can be scheduled rather than
discovered.

> **All four were found by reading this table against the source.** Not by reading the table, and
> not by reading the design documents — which is what produced the error. `architecture.md` and
> `interface.md` describe the intended system accurately and in the present tense, and a status
> document that cites them as evidence inherits their optimism. The rule this establishes: **a
> compliance row may only cite a file that exists.**

| ID | What | Severity |
|---|---|---|
| **D-10** | The identity provider interface was never written — **and is documented as if it were** | **Highest** |
| D-7 | Document upload not built; closure does not check for documentation | High |
| D-8 | Two role landing pages are placeholders | Medium |
| D-9 | Autosave and editable templates not built | Low |

D-10 is listed first because it is the only one where the documentation asserts something false
rather than omitting something true. An absence is found by looking; a false description is found
only by checking.

### D-1 — Entra ID SSO deferred

| | |
|---|---|
| **Brief prefers** | Microsoft Entra ID via OIDC/OAuth 2.0 |
| **POC delivers** | Local email + password — **with no provider abstraction at all**. See D-10 |
| **Justification** | The POC's purpose is to demonstrate the workflow to management. An app registration, tenant admin consent and token refresh are provisioning steps outside the build, and none of them demonstrates a business process |
| **Mitigation** | `users.entra_object_id` is in the first migration, so no data migration is needed later. **The interface described below was not built — see D-10** |
| **Production plan** | Build the `IdentityProvider` interface and its local binding (D-10), then `EntraProvider`. No schema change, no data migration |

### D-2 — Database queue instead of Redis

| | |
|---|---|
| **Brief prefers** | Redis recommended |
| **POC delivers** | Laravel database queue + cron-driven `queue:work --stop-when-empty` |
| **Justification** | The host provides no Redis and no worker process, and there is no SSH to start one. Redis cannot be installed |
| **Mitigation** | Notifications are queued, so a slow SMTP server never blocks a user action. The failed-jobs table surfaces failures |
| **Production plan** | Add Redis and change the queue driver. No application code changes |

### D-3 — No malware scanning

| | |
|---|---|
| **Brief prefers** | File type, size and malware controls for uploads |
| **Status** | **Moot while D-7 stands.** There is no upload, so there is nothing to scan, validate or quarantine. The controls below are configured but exercised by nothing |
| **Configured, ready for D-7** | A narrow MIME allow-list and a 20 MB cap in `config/itrequest.attachments`, and a private storage path outside the document root created by both the installer and the deploy |
| **Justification for the deviation itself** | No scanning service is available on this host, and none can be installed without shell access. When the upload is built, this deviation becomes real rather than moot |
| **Production plan** | Integrate an AV scanning service at upload; quarantine until cleared. **Do this at the same time as D-7, not after** — an upload that accepts arbitrary files with no scanning is a worse position than no upload at all, and the gap between the two is exactly when nobody is looking |

### D-4 — Committee decision recorded, not voted

| | |
|---|---|
| **Brief prefers** | Committee decision and conditions |
| **POC delivers** | The decision, conditions, recorder and timestamp |
| **Justification** | The brief's own Appendix C lists the committee operating model as an **assumption to validate during discovery** — whether decisions require voting or only recording. That is unresolved |
| **Mitigation** | The decision and its conditions are captured, which is the audit requirement |
| **Production plan** | Add motions, votes and quorum if discovery confirms they are needed |

### D-5 — No historical data migration

| | |
|---|---|
| **Brief prefers** | Historical data migration scope defined |
| **POC delivers** | Starts empty with seeded demonstration data |
| **Justification** | The brief's Appendix C lists migration scope as an assumption to validate. The POC demonstrates process, not data volume |
| **Mitigation** | The SharePoint schema's GUIDs and `StaticName` values give the field-level mapping for a future import |
| **Production plan** | Define scope, map fields, migrate and reconcile (a deliverable in its own right) |

### D-6 — LiteSpeed rather than Nginx

| | |
|---|---|
| **Brief prefers** | Nginx or approved equivalent |
| **POC delivers** | LiteSpeed on cPanel, secured with `.htaccess` |
| **Justification** | The approved hosting is cPanel/LiteSpeed. The brief allows "an approved equivalent" |
| **Mitigation** | Directory listing disabled via `Options -Indexes` **committed in `public/.htaccess`**; the document root points at `public/`, so application files are not web-reachable |
| **Production plan** | Portable by design — no server-specific code. Paths and URL in `.env` only |

### D-7 — Document upload not built

| | |
|---|---|
| **Brief requires** | FR-006 — users upload, categorise, preview and download authorised documents. BR-006 — closure requires all mandatory decisions **and documentation** |
| **POC delivers** | The schema, the model, the configuration, the private storage directory and the authorisation design are in place. **The upload and download actions are not** |
| **Justification** | None. This is an **incomplete requirement**, recorded here rather than described as a deferral. The brief's §5 lists "Documents and Review" as a wizard step and §9.2's UAT-001 through UAT-015 depend on documents at three points — UAT-002 (conditional documents), UAT-006 (returned for a missing attachment) and UAT-006/UAT-009 (conditions evidenced) |
| **What exists** | `attachments` table with checksum and private storage path; `Attachment` model with a human-readable size helper; `config('itrequest.attachments')` with a narrow MIME allow-list, a 20 MB cap and a documented deviation for malware scanning; `storage/app/private/attachments` created by both the installer and the deploy; `deploy-check` warns when the directory is missing |
| **What does not** | Any way to attach a file, and any way to retrieve one. The request detail screen eager-loads `attachments` and never renders them |
| **Consequence** | `GovernanceService::closureBlockers()` states it requires "all mandatory decisions and documentation" and checks only the decisions. A request can be closed with no documents at all. The message is honest about the intent and the check does not implement it — **BR-006 is half-enforced** |
| **Effort to close** | Small and well-contained: a Livewire upload on the wizard's final step, a download action authorised through `ItRequestPolicy`, a document list on the detail screen, and one extra clause in `closureBlockers()`. The schema, storage layout and configuration already accommodate it |
| **Production plan** | Build before go-live. This is a stated requirement, not an enhancement |

### D-8 — Two screens remain placeholders

| | |
|---|---|
| **Brief requires** | FR-012 (dashboards) and NFR-006 (immutable critical records, readable) |
| **POC delivers** | `Admin\AuditLog` and `Recommendations\Index` render a placeholder panel describing what they will contain. **Both are role landing pages** — `UserRole::landingRoute()` sends an Auditor and a Technical Reviewer to them on sign-in |
| **Justification** | None. Recorded rather than deferred |
| **Mitigation** | Neither blocks the underlying work. A Technical Reviewer can file a recommendation from the request detail screen, which is where the action lives. An Auditor's read-only access is enforced by the policies regardless of this screen |
| **Consequence** | An Auditor signs in and is shown a screen that says the screen has not been built. That is a poor first impression and it understates a working audit trail — the data and the append-only guarantees are real |
| **Production plan** | Build both. The audit log is a filtered list of an existing table; the recommendations index is a filtered list of an existing table |

### D-9 — Autosave and editable templates not built

| | |
|---|---|
| **Brief requires** | FR-003 (save as draft) and FR-013 (administrators manage reference data **and templates**) |
| **POC delivers** | Draft saving works through an explicit **Save draft** button on every step — the requirement as written is met. **The interface specification's autosave-every-30-seconds is not implemented**, and notification templates are fixed strings in `NotificationService`, not editable |
| **Justification** | The brief's FR-003 requires drafts, not autosave; autosave is an addition in `interface.md` §4. Editable templates cost a screen, a table and a templating engine for four messages whose wording has not yet been agreed with the business — writing them before the wording exists would be premature |
| **Consequence** | A requestor who closes the tab without pressing **Save draft** loses what they typed. Nothing else depends on either gap |
| **Production plan** | Autosave is a small addition to a component that already has the save path. Templates need the final wording first, which is a business input |

### D-10 — The identity provider interface was never written

This is the most serious entry here, because unlike the others it is **documented as existing in
four places** and none of them is code.

| | |
|---|---|
| **Brief requires** | FR-001 — sign in using approved enterprise identity and SSO |
| **The documentation claims** | `architecture.md` §4 lists `app/Contracts/` containing `IdentityProvider`. §6 shows the interface as a PHP code block and a driver table naming `LocalProvider` for the POC and `EntraProvider` for production. D-1 of this matrix, and `docs/architecture.md` §6 again, both list the production plan as *"implement `EntraProvider`; swap the config binding"* |
| **What actually exists** | **No `app/Contracts/` directory. No `IdentityProvider`, `LocalProvider` or `EntraProvider` — not as an interface, a stub, or a comment.** `Auth\Login` calls `Auth::attempt(['email' => …, 'password' => …])` directly |
| **The only real artefact** | An `identity` array in `config/itrequest.php` with a `driver` key and four `entra.*` keys. **Nothing reads any of it.** `ITREQUEST_IDENTITY_DRIVER` appears in no PHP file in the application |
| **Why this is worse than the other gaps** | D-7 to D-9 are absences. This is a **false description of the architecture**, written in the present tense, in the document a new developer reads first. Someone implementing Entra would search for `LocalProvider`, find nothing, and reasonably conclude the repository was incomplete — or would wire OIDC into `Login.php` and leave the config array still unread |
| **Consequence for the brief** | D-1 says Entra is *deferred*. More precisely: **neither the SSO nor the seam for it was built.** The POC authenticates locally and that is the whole of it |
| **What is genuinely reusable** | `users.entra_object_id` (char 36, nullable, unique) is in the first migration. That part of D-1's mitigation is true |
| **Production plan** | Write the interface, `LocalProvider` binding the existing `Auth::attempt` call, and the config binding to select between drivers. Then `EntraProvider`. The estimate in `project-plan.md` §7 *includes* this work, so no estimate changes — the plan was always to build it, and the documentation simply described it as already built |

> **The correction matters more than the code.** The code is a small, well-understood addition
> that the work estimate already covers. The documentation was asserting an architecture that
> does not exist, and that is the kind of error that survives every test suite because no test
> reads a design document.

---

## Items Requiring Confirmation

These are not deviations; they are unknowns that need a business answer.

| Item | Why it matters | Blocking? |
|---|---|---|
| **Email deliverability from this host** | FR-010, UAT-013 and four notification triggers depend on it | Yes — Phase C gate |
| **Field labels and option lists** | The MS Form export could not be read; the CSV gave names without types or options | No — prototype first, marked `EDIT ME` |
| **Which units review which classification** | Seeded as all three against every classification; the real rule may be narrower | No — it is a data change |
| **What `FundingType` contains** | No option list available | No — `@EDIT ME` |
| **What follows "PROCEED NEXT STAGE"** | Determines whether closure is the true end, or a handover begins | No — POC scoped to `Closed` |
| **Host PHP version** | The brief prefers 8.4+; the local toolchain is 8.3.33 | Yes before pinning a framework floor |
| **Committee operating model** | Voting vs recording only (D-4) | No — D-4 declares the assumption |
| **Retention and disposal policy** | NFR-006, NFR-010 | No for the POC; yes before production |
