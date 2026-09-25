# Compliance Matrix — IT Request Management System (POC)

Requirement-by-requirement traceability against the vendor brief, plus the **declared deviations**
the brief requires (§11.1).

**Status key:** ✅ Comply · 🟡 Partial · ⚪ Deferred (POC) · ⛔ Not applicable

The brief is a sourcing document, so a deviation is not a failure — it is a statement that must
be made and justified. Every ⚪ below has a reason and a production plan.

---

## Functional Requirements

| ID | Requirement | Status | Where / note |
|---|---|---|---|
| FR-001 | Sign in using approved enterprise identity and SSO | 🟡 | `IdentityProvider` contract with `LocalProvider` (POC) and `EntraProvider` (production). **Entra deferred**, seam built. `architecture.md` §6 |
| FR-002 | Retrieve or maintain requestor department, division and reporting data | ✅ | Auto-filled from the identity provider; snapshotted on the request. `design.md` §3.1 |
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
| FR-015 | Search by number, title, status, owner, unit, tier, date | 🟡 | Filters on all listed fields. **Full-text search deferred**; `LIKE` at POC volumes |

---

## Non-Functional Requirements

| ID | Requirement | Status | Note |
|---|---|---|---|
| NFR-001 | Least privilege, secure coding, encryption, secrets, vulnerability remediation | 🟡 | Policies, validation, private storage, `.env` secrets. **`vendor/` is not patchable on this host without a manual re-extract** — documented |
| NFR-002 | Measurable response-time and throughput targets | ⚪ | Deferred. Needs validated usage volumes, which are an open discovery question |
| NFR-003 | Availability, maintenance and restoration commitments | ⚪ | Deferred. A host-level commitment, not an application feature |
| NFR-004 | Scale users, requests, documents and workflow volume without redesign | ✅ | Single-table indexed queries; no design element assumes POC volumes |
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
| BR-002 | A rejection or return requires comments | ✅ | FormRequest **and** a database check |
| BR-003 | Conditional documents and fields driven by tier and classification | ✅ | Business-plan conditional block; `classification_review_units` |
| BR-004 | The approver cannot modify the requestor's justification | ✅ | The approval action writes `approval_tasks` only |
| BR-005 | Recommendations versioned or preserved, never overwritten | ✅ | `version_no` inserts a new row |
| BR-006 | Closure requires all mandatory decisions and documentation | 🟡 | **Decisions enforced; documentation not.** See D-7 |
| BR-007 | A returned request resumes at the configured stage | ✅ | Returning stage recorded on the transition |
| BR-008 | Delegated decisions capture delegated-from and acting users | ✅ | `delegated_from_id` separate from `approver_id` |
| BR-009 | System-managed fields not editable through ordinary screens | ✅ | Not mass-assignable; not rendered; changes audited |
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
(§2.1, §11.1). Six are declared as deviations, and three more — D-7, D-8 and D-9 — as
**incomplete requirements**, recorded because a matrix that reports a missing requirement as
satisfied is worse than one that reports nothing.

**D-7 to D-9 are not deferrals.** A deferral is a decision that something is out of scope for
the POC. These are requirements claimed as met that are not, or additions in the interface
specification presented as though they were built. They are listed so the gap is visible and
can be scheduled rather than discovered.

### D-1 — Entra ID SSO deferred

| | |
|---|---|
| **Brief prefers** | Microsoft Entra ID via OIDC/OAuth 2.0 |
| **POC delivers** | Local email + password behind an `IdentityProvider` contract |
| **Justification** | The POC's purpose is to demonstrate the workflow to management. An app registration, tenant admin consent and token refresh are provisioning steps outside the build, and none of them demonstrates a business process |
| **Mitigation** | The interface exists; `users.entra_object_id` is in the first migration; the profile-mapping shape matches what the organisation already does in Power Apps |
| **Production plan** | Implement `EntraProvider`; swap the config binding. No schema change, no data migration |

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
| **POC delivers** | MIME type and size validation; private storage outside the webroot; access controlled and audited |
| **Justification** | No scanning service is available on this host, and none can be installed without shell access |
| **Mitigation** | Files are never publicly addressable and never executed. Access requires authorisation per request |
| **Production plan** | Integrate an AV scanning service at upload; quarantine until cleared |

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
