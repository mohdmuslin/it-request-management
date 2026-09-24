# Process Improvement — IT Request Management System

Proposals for improving the **current** process, independent of the rebuild. Each is grounded in
a specific artefact — the process flow diagram, the SharePoint schema, the field CSV, the vendor
brief — rather than general good practice.

Some can be applied to the existing process immediately, before the new system exists. Those are
marked **⚡ actionable now**.

Ranked by value.

---

## 1. ⚡ Approvals cannot proceed when someone is away

**Evidence.** The process flow is strictly sequential: `Submit → Project Owner → Project Sponsor`.
There is no delegation branch and no fallback. The vendor brief provides for delegation (FR-014),
but the current process has nothing.

**Problem.** If the Project Owner is on leave, **every request waiting on them stops.** Nobody is
notified, nothing escalates, and the requestor sees only "still pending". This is the single
point of failure in the whole process, and it is invisible until someone asks why their request
has not moved in a week.

**Proposal.**
1. Nominate a delegate for each approval role, with an end date.
2. Show the delegate in the approval queue with a clear "acting for" marker.
3. Escalate to IT Governance when an approval passes its due date.

**Benefit.** A request cannot silently stall on one person's calendar.

> **This is the highest-value change in this document.** It costs least to fix and prevents the
> most delay.

---

## 2. ⚡ Returns restart the entire approval chain

**Evidence.** The flow diagram loops return-for-amendment back to **"SUBMIT FOR APPROVAL"** —
the beginning. But the vendor brief's BR-007 states a returned request *"shall resume at the
configured stage after resubmission."* The diagram and the brief disagree, and the brief is right.

**Problem.** A request returned by the Sponsor goes back to the Owner for re-approval, even
though the Owner's decision was never the problem. Every return costs two approvers' time
instead of one, and the requestor waits longer for a fix that concerns a single stage.

**Proposal.** Resume at the stage that returned it. The Sponsor's return goes back to the
Sponsor after amendment.

**Benefit.** Fewer approval cycles per return, and approvers stop re-reading decisions they have
already made.

---

## 3. ⚡ Nothing has a due date, so nothing ages

**Evidence.** Neither the process flow nor the SharePoint schema contains a due date, SLA or
target anywhere. All 40 columns of `IT_Request.csv` are descriptive: no deadline field exists.

**Problem.** A request can sit in any stage indefinitely. No report says how long anything has
waited, nothing escalates, and the only way to find a stalled request is for someone to
complain. Management cannot answer "how long do requests take?" except by manual spreadsheet
archaeology.

**Proposal.** A per-stage target in business days, with reminders before and escalation after.
Recommended starting values, all editable:

| Stage | Business days |
|---|---|
| Project Owner | 3 |
| Project Sponsor | 3 |
| Completeness review | 2 |
| Technical recommendation | 5 |
| Consolidation | 3 |
| Committee decision | 10 |

**Benefit.** Aging becomes visible, escalation becomes automatic, and turnaround becomes
measurable — which is also what makes the process improvable over time.

> The committee gets 10 days because it meets periodically. A 3-day target there would generate
> escalation notices for something nobody can act on faster.

---

## 4. ⚡ Tier and classification accept free text

**Evidence.** `IT_Request_Schema.csv` shows both fields as `Type="Choice"` with
**`FillInChoice="TRUE"`**. Users can type a value that is not on the list.

**Problem.** "Tier 2", "tier2", "T2" and "Tier II" are four different values to a report and one
value to a human. Routing that depends on the value silently misses the ones that did not match.
Reporting by tier becomes unreliable without anyone noticing.

**Proposal.** Turn free-text entry off. Where a genuine gap exists, add an explicit
"Other (specify)" option so the exception is visible rather than hidden in a typo.

**Benefit.** Routing and reporting become dependable.

---

## 5. ⚡ Request numbers are typed by hand

**Evidence.** `RequestID` is `Type="Text"` — a plain text field, not an auto-numbered column.
The brief's FR-005 requires a unique, configurable, system-generated number.

**Problem.** Hand-typed identifiers duplicate and mistype. Duplicates are the worse failure:
two requests sharing a number break referencing, reporting and any future migration.

**Proposal.** Generate the number on submission from a configurable pattern — for example
`REQ-2026-0042` — and make it immutable.

**Benefit.** Unique references, and a number that can be quoted in an email without ambiguity.

---

## 6. Three IT units are chased by email, with no visibility

**Evidence.** The flow shows IT Operations, IT Platforms and IT Delivery & Governance each
completing a "FILL RECOMMENDATION" step, after which IT HOU consolidates *"during HOU meeting"*.
There is no mechanism described for tracking who has responded.

**Problem.** Consolidation waits on the slowest unit, but nobody can see which one is
outstanding. Chasing happens by email, and the HOU meeting may be reached without all inputs.

**Proposal.** Issue all three review requests **in parallel** with individual due dates, and
provide an outstanding-view per request. FR-008 already requires the recommendations to be
independent and non-overwriting.

**Benefit.** Turnaround shortens from "whenever everyone has replied" to a predictable date, and
HOU meetings are never held with inputs missing.

---

## 7. Closure is undefined

**Evidence.** The process flow ends at an off-page connector labelled **"PROCEED NEXT STAGE"**.
The swimlane covers *"IT Request Form (Section A & B)"* only, and when asked what follows, the
business owner did not know. The brief's BR-006 nevertheless requires closure only after all
mandatory decisions and documents.

**Problem.** There is no recorded outcome. An approved request and a rejected one both simply
stop. Nobody can answer "what happened to that request?" from the record, and the loop is never
formally closed.

**Proposal.** Define an explicit `Closed` state with a recorded outcome (approved / approved with
conditions / not recommended / withdrawn), and decide what, if anything, hands over to delivery.

**Benefit.** A complete audit trail, and the ability to measure outcomes — not just activity.

---

## 8. Reporting is a manual spreadsheet export

**Evidence.** `IT_Request.csv` is an Excel export template with 40 columns and no data rows.
Reporting today means exporting a list and assembling a summary by hand.

**Problem.** Every report is stale the moment it is produced, two people producing "the same"
report get different numbers, and nobody trusts the figure enough to act on it. The brief's
UAT-014 specifically requires dashboard totals to reconcile with transactional records.

**Proposal.** Live dashboards for workload, aging, turnaround and outcomes, with export for
circulation rather than as the primary channel.

**Benefit.** One number, agreed, current. Reporting stops costing an afternoon.

---

## 9. Two entry points

**Evidence.** The flow's first step names two: **"1. Organisation Spaces  2. IT&P Sharepoint"**.

**Problem.** It is not clear which is authoritative, whether both feed the same list, or whether
a requestor should use one or the other. Requests can be raised in the wrong place, or in both.

**Proposal.** A single URL, with the old locations redirecting or clearly marked as retired.

**Benefit.** No ambiguity for the requestor, and one place to audit.

---

## 10. Profile data is auto-filled with no way to correct it

**Evidence.** The schema's `CustomFormatter` on `Department` and `Division` reads
`Office365Users.UserProfileV2(RequestorComboBox.Selected.Mail).Department` — and the same for
division. The values come from the Microsoft 365 profile.

**Problem.** Auto-fill is the right idea — it removes typing and keeps the data consistent. But
a stale or wrong profile value propagates into every request that person raises, with no
override. The error is invisible because nobody typed it.

**Proposal.** Keep the auto-fill, and let IT Governance correct the value on a request, with the
correction recorded.

**Benefit.** Accurate organisational data without retyping, and a way out when the source is
wrong.

> **Note:** this is exactly why the schema stores department and division as a **snapshot on the
> request** rather than a live lookup. If a person transfers department, the historical request
> must still report against the department it was raised in.

---

## Summary

| # | Improvement | Effort | Value | Actionable now |
|---|---|---|---|---|
| 1 | Approvals survive absence | Low | **High** | ⚡ |
| 2 | Returns resume at the returning stage | Low | High | ⚡ |
| 3 | Per-stage due dates and escalation | Low | **High** | ⚡ |
| 4 | Controlled vocabularies | Low | Medium | ⚡ |
| 5 | System-generated request numbers | Low | Medium | ⚡ |
| 6 | Parallel, time-boxed unit reviews | Medium | High | ⚡ |
| 7 | Explicit closure with outcome | Medium | High | With the rebuild |
| 8 | Live dashboards | High | High | With the rebuild |
| 9 | Single entry point | Low | Medium | ⚡ |
| 10 | Profile auto-fill with correction | Low | Medium | With the rebuild |

**Items 1, 2, 3 and 4 could be applied to the existing process today** — a delegation rule, a
changed return path, a target per stage, and turning off free-text entry. They do not require
the new system, and each removes a known failure.

The remaining items are improvements the rebuild delivers by construction, and are recorded in
`design.md` §8 so they are design commitments rather than aspirations.
