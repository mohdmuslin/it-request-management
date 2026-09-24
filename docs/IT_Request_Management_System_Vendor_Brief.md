# IT Request Management System

## Vendor Sourcing Brief, Requirements and Solution Blueprint

| Item | Details |
|---|---|
| Prepared for | Client Organisation |
| Document status | Draft for vendor participation |
| Version | 1.0 |
| Date | 24 September 2026 |

> **Purpose**  
> This document provides a technology-neutral sourcing brief for vendors to propose, design, build, test, deploy and support a secure web-based IT Request Management System using PHP, Laravel and MySQL. The client organisation name has intentionally been omitted.

**CONFIDENTIAL**

## Document Control

| Item | Details |
|---|---|
| Document title | IT Request Management System - Vendor Sourcing Brief, Requirements and Solution Blueprint |
| Intended audience | Prospective vendors, solution architects, developers, project managers, security reviewers and procurement evaluators |
| Primary purpose | Support vendor briefing, proposal preparation, technical evaluation and implementation planning |
| Organisation naming | Generic terms such as Client, Client Organisation and Business Unit are used throughout |
| Commercial status | This brief is not a contract, purchase order or notice of award. Final scope is subject to procurement and contractual approval. |

### How Vendors Should Use This Document

- Review the stated business process, functional scope and non-functional requirements.
- Identify assumptions, dependencies, exclusions and proposed alternatives.
- Provide a compliant solution design and explain material deviations.
- Propose an implementation plan, resource model, testing approach and support model.
- Complete a requirements traceability and compliance matrix as part of the proposal.

## 1. Executive Summary

The proposed solution is a centralized web application for capturing, reviewing, approving, recommending and tracking IT requests. It replaces fragmented form and email handling with a controlled workflow, structured data, document management, notifications, dashboards and a complete audit trail.

> **Recommended delivery model**  
> A modular monolith is preferred for the initial release. The application should have clear functional modules and service boundaries while remaining one manageable deployment. Vendors may propose alternatives with justification.

### 1.1 Target Outcomes

- One authoritative register for IT requests and decisions.
- Standardized submission and validation of mandatory information.
- Transparent approval and recommendation routing.
- Reduced manual follow-up through reminders and escalations.
- Audit-ready records of decisions, comments, documents and timestamps.
- Operational dashboards for workload, aging, bottlenecks and outcomes.

### 1.2 In-Scope Workflow

| Stage | Primary actor | Principal outcome |
|---|---|---|
| Draft and submit | Requestor / Project Owner | Complete request with justification, scope, budget, timeline and documents |
| Owner approval | Project Owner | Approve, reject or return for clarification |
| Sponsor approval | Project Sponsor | Approve, reject or return for clarification |
| Completeness review | IT Governance | Validate mandatory information and supporting evidence |
| Assessment | IT Governance | Assign tier, classification and governance route |
| Technical recommendations | Relevant IT units | Submit recommendation, conditions and comments |
| Consolidation | IT HOU / authorized reviewer | Consolidate recommendations and determine route |
| Committee decision | ITIC or equivalent committee | Decide applicable Full-route requests |
| Closure | System / Governance | Notify stakeholders, preserve record and close workflow |

## 2. System Requirements

### 2.1 Proposed Technology Baseline

| Layer | Preferred technology | Vendor response expectation |
|---|---|---|
| Backend | PHP 8.4+ and current supported Laravel release | Confirm version, support lifecycle and upgrade approach |
| Database | MySQL 8.4 LTS or approved equivalent | Confirm HA, backup, encryption and recovery design |
| Frontend | Laravel Blade + Livewire and Tailwind CSS | Demonstrate responsive and accessible user experience |
| Identity | Microsoft Entra ID using OIDC/OAuth 2.0 | Explain SSO, role mapping and account lifecycle |
| Web tier | Nginx or approved equivalent | Provide secure configuration and hardening |
| Queue/cache | Redis recommended | Explain deployment, monitoring and failure handling |
| Source control | Git repository | Define branching, pull request and release controls |
| Development | Visual Studio Code compatible | Provide reproducible setup and documentation |

### 2.2 User Roles

| Role | Core permissions |
|---|---|
| Requestor | Create, save draft, submit, amend, withdraw where allowed, and view own requests |
| Project Owner | Review and decide assigned requests; provide comments |
| Project Sponsor | Review and decide assigned requests; provide comments |
| IT Governance Reviewer | Completeness review, tiering, classification, routing and administration |
| Technical Reviewer | Provide unit recommendation, conditions and supporting evidence |
| IT HOU / Consolidator | Consolidate recommendations and determine governance route |
| Committee Secretariat / Reviewer | Manage committee decision and record conditions |
| Administrator | Manage users, roles, reference data, templates and configuration |
| Auditor / Read-only | View authorized records and audit evidence without modification |

### 2.3 Functional Requirements

| ID | Area | Requirement |
|---|---|---|
| FR-001 | Authentication | Users shall sign in using approved enterprise identity and SSO. |
| FR-002 | Profile | The system shall retrieve or maintain requestor department, division and reporting data. |
| FR-003 | Draft | Users shall save incomplete requests as drafts. |
| FR-004 | Submission | The system shall validate mandatory and conditional fields before submission. |
| FR-005 | Identifier | A unique configurable request number shall be generated. |
| FR-006 | Documents | Users shall upload, categorize, preview and download authorized documents. |
| FR-007 | Approval | The system shall support approve, reject and return-for-amendment outcomes. |
| FR-008 | Recommendations | Multiple units shall submit independent recommendations without overwriting prior records. |
| FR-009 | Routing | The workflow shall route by tier, classification, decision and governance route. |
| FR-010 | Notifications | The system shall notify users of assignments, decisions, reminders and escalations. |
| FR-011 | Audit | Every material action and transition shall be recorded with actor and timestamp. |
| FR-012 | Reporting | Authorized users shall access filters, dashboards and exports. |
| FR-013 | Administration | Authorized administrators shall manage configurable reference data and templates. |
| FR-014 | Delegation | Authorized temporary delegation shall preserve original and acting approvers. |
| FR-015 | Search | Users shall search by request number, title, status, owner, unit, tier and date. |

### 2.4 Non-Functional Requirements

| ID | Category | Requirement |
|---|---|---|
| NFR-001 | Security | Apply least privilege, secure coding, encryption, secrets management and vulnerability remediation. |
| NFR-002 | Performance | Vendor shall propose measurable response-time and throughput targets based on validated usage volumes. |
| NFR-003 | Availability | Vendor shall propose availability, maintenance and service restoration commitments. |
| NFR-004 | Scalability | Solution shall scale users, requests, documents and workflow volume without redesign of the core domain. |
| NFR-005 | Accessibility | Interfaces shall support keyboard use, readable contrast, labels and validation feedback. |
| NFR-006 | Auditability | Critical records shall be immutable to ordinary users and retained according to approved policy. |
| NFR-007 | Maintainability | Use documented standards, automated tests, modular code and controlled configuration. |
| NFR-008 | Portability | Deployment shall be reproducible across development, test and production environments. |
| NFR-009 | Observability | Provide application logs, security logs, health checks, queue monitoring and alerting. |
| NFR-010 | Data protection | Data collection, access, retention and disposal shall follow Client requirements and applicable obligations. |

## 3. System Design

### 3.1 Functional Modules

| Module | Capabilities |
|---|---|
| Identity and access | SSO, user profile, roles, permissions, delegation and session handling |
| Organisation structure | Departments, divisions, heads, reporting relationships and reviewer groups |
| Request management | Draft, submit, amend, copy, withdraw, view and track |
| Workflow and approvals | Stage engine, assignments, decisions, due dates and escalation |
| Recommendation management | Unit reviews, conditions, evidence, versions and consolidation |
| Document management | Upload, classification, secure retrieval, metadata and malware-scanning integration |
| Notifications | Email/in-app templates, reminders, escalation and delivery status |
| Reporting | Operational dashboard, aging, turnaround, outcomes and exports |
| Administration | Reference data, workflow settings, notification templates and user access |
| Audit and compliance | Event history, change log, evidence export and retention controls |

### 3.2 Workflow State Model

- Draft
- Submitted
- Pending Project Owner
- Pending Project Sponsor
- Pending Completeness Review
- Returned for Amendment
- Pending Technical Recommendation
- Pending Consolidation
- Pending Committee Decision
- Approved
- Approved with Conditions
- Not Recommended / Rejected
- Withdrawn
- Closed

> **Workflow control**  
> Vendors should implement controlled transitions rather than allowing direct free-text status updates. Each transition must validate authorization, prerequisites and required comments, then create an audit record within the same database transaction.

### 3.3 Business Rules

| Rule ID | Rule |
|---|---|
| BR-001 | Submitted requests cannot be deleted by ordinary users. |
| BR-002 | A rejection or return decision requires comments. |
| BR-003 | Conditional documents and fields shall be driven by tier and classification. |
| BR-004 | The approver cannot modify the requestor's original business justification during approval. |
| BR-005 | Recommendation records shall be versioned or preserved rather than overwritten. |
| BR-006 | Final closure requires all mandatory decisions and documentation. |
| BR-007 | Requests returned for amendment shall resume at the configured stage after resubmission. |
| BR-008 | Delegated decisions shall capture both delegated-from and acting users. |
| BR-009 | System-managed fields shall not be editable through ordinary request screens. |
| BR-010 | All timestamps shall be stored consistently and displayed in the configured local time zone. |

## 4. System Architecture

### 4.1 Logical Architecture

```text
Browser
  -> HTTPS / Reverse Proxy
  -> Laravel Application
      -> MySQL
      -> Redis Queue / Cache
      -> Private File Storage
      -> Microsoft Entra ID
      -> Email / Notification Service
      -> Monitoring and Log Platform
```

### 4.2 Application Layers

| Layer | Responsibility |
|---|---|
| Presentation | Responsive screens, forms, dashboards, validation feedback and accessibility |
| Application | Use cases, workflow commands, authorization, notifications and reporting orchestration |
| Domain | Request, approval, recommendation and workflow rules |
| Persistence | Eloquent models, repositories where justified, transactions and database migrations |
| Integration | Identity, email, storage, scanning, reporting and future API services |
| Operations | Deployment, configuration, queues, scheduler, logging, monitoring and backup |

### 4.3 Deployment Environments

- **Development:** Local or controlled shared environment with anonymized or non-production data.
- **Test/UAT:** Production-like configuration for integration, security and user acceptance testing.
- **Production:** Hardened environment with controlled release, backup, monitoring and restricted administration.

### 4.4 API and Integration Principles

- Use versioned REST APIs when external integration is required.
- Use service accounts and managed secrets; do not store credentials in source code.
- Apply input validation, authorization and throttling.
- Record correlation IDs for traceability.
- Design integration failures for retry and reconciliation.
- Document interfaces using OpenAPI or an equivalent standard.

## 5. Database Design

### 5.1 Core Data Model

| Table | Purpose | Selected key fields |
|---|---|---|
| `users` | Employee and application identity | `id`, `employee_no`, `name`, `email`, `department_id`, `division_id`, `manager_id`, `entra_object_id`, `is_active` |
| `departments` | Department reference | `id`, `code`, `name`, `head_user_id`, `is_active` |
| `divisions` | Division reference | `id`, `code`, `name`, `head_user_id`, `is_active` |
| `it_requests` | Current request record | `request_no`, `title`, `requestor_id`, `owner_id`, `sponsor_id`, `tier_id`, `classification_id`, `status`, `current_stage` |
| `approval_tasks` | One approval assignment or decision | `request_id`, `stage`, `sequence`, `approver_id`, `due_at`, `decision`, `comments`, `decided_at` |
| `recommendations` | Unit recommendation history | `request_id`, `review_unit_id`, `reviewer_id`, `recommendation`, `conditions`, `version_no`, `submitted_at` |
| `workflow_histories` | Stage transitions | `request_id`, `from_stage`, `to_stage`, `action`, `performed_by`, `remarks`, `created_at` |
| `attachments` | Document metadata | `request_id`, `category`, `original_name`, `storage_path`, `mime_type`, `size`, `uploaded_by` |
| `comments` | User and internal discussion | `request_id`, `user_id`, `comment_type`, `comment`, `is_internal` |
| `audit_logs` | Before/after change evidence | `user_id`, `auditable_type`, `auditable_id`, `event`, `old_values_json`, `new_values_json`, `ip_address` |
| `notifications` | Notification tracking | `user_id`, `request_id`, `template`, `channel`, `status`, `sent_at` |
| Reference tables | Configuration values | Tiers, classifications, stages, routes, decisions, units, roles and permissions |

### 5.2 Data Design Requirements

- Use foreign keys and indexes for identifiers, user references, status, stage and reporting dates.
- Use decimal data types for financial values and avoid floating-point storage.
- Use soft deletion only where justified; do not permit deletion of audit evidence.
- Keep binary documents outside the relational database unless the hosting standard requires otherwise.
- Record `created_by` and `updated_by` where technical audit logging alone is insufficient.
- Define retention and archival rules before production migration.
- Provide a data dictionary, ERD and migration scripts as controlled deliverables.

## 6. UI/UX Design

### 6.1 Primary Navigation

| Menu | Purpose |
|---|---|
| Dashboard | Summary cards, workload, aging and recent activity |
| New Request | Guided request wizard |
| My Requests | Drafts, submissions and returned items |
| My Approvals | Owner and sponsor decisions |
| Recommendations | Technical review assignments |
| Governance Workspace | Completeness, classification, routing and consolidation |
| Committee Workspace | Committee agenda and decision recording |
| Reports | Filters, dashboard views and exports |
| Administration | Users, roles, reference data and templates |

### 6.2 Request Wizard

| Step | Contents |
|---|---|
| 1. Request Information | Title, requestor, department, division, owner, sponsor, tier and classification |
| 2. Business Justification | Need, objectives, business-plan alignment, value and impact |
| 3. Budget and Timeline | Amount, source, code, funding type, start, completion and resources |
| 4. Risk and Scope | Urgency, risk, mitigation, dependencies, in-scope and out-of-scope |
| 5. Documents and Review | Supporting documents, declaration, preview and submission |

### 6.3 Experience Requirements

- Responsive desktop and mobile layout.
- Autosave draft or clearly visible **Save Draft** action.
- Visible progress indicator and section-completion status.
- Conditional questions based on selected tier, classification and urgency.
- Inline validation with plain-language correction guidance.
- Status timeline showing completed, current and future stages.
- Accessible controls, keyboard navigation and adequate contrast.
- Clear separation between applicant content and internal governance content.
- Confirmation before irreversible actions.
- Consistent status badges and decision terminology.

## 7. Development Blueprint

### 7.1 Suggested Laravel Structure

```text
app/
  Actions/
  Enums/
  Events/
  Http/
    Controllers/
    Requests/
  Jobs/
  Listeners/
  Models/
  Notifications/
  Policies/
  Services/
    RequestService.php
    WorkflowService.php
    ApprovalService.php
    RecommendationService.php
    AuditService.php
  Support/
```

### 7.2 Engineering Principles

- Keep controllers thin and place business logic in services/actions.
- Use Form Request classes for validation.
- Use Policies and Gates for authorization.
- Use enums or controlled reference data for stages and decisions.
- Wrap submission and workflow transitions in database transactions.
- Use queued notifications and idempotent jobs.
- Write feature tests for each allowed and prohibited transition.
- Use migrations and seeders for repeatable deployment.
- Apply dependency and static-analysis checks in CI.
- Maintain technical documentation alongside the source repository.

### 7.3 Minimum Deliverables

| Category | Vendor deliverable |
|---|---|
| Requirements | Validated requirements, assumptions and traceability matrix |
| Design | System design, architecture, ERD, data dictionary, security design and interface design |
| Build | Source code, migrations, configuration, automated tests and build instructions |
| Testing | Test plan, scripts, evidence, defect register and UAT support |
| Deployment | Deployment plan, rollback plan, release notes and environment configuration |
| Operations | Runbook, backup/recovery procedures, monitoring guide and support model |
| Training | Administrator guide, user guide and knowledge-transfer material |
| Handover | Repository, credentials-transfer process, licences, third-party inventory and acceptance pack |

## 8. Project Plan

> **Planning note**  
> Vendors shall propose a detailed schedule with duration, dependencies, milestones, resource loading and critical path. The sequence below defines expected phases but does not prescribe commercial duration.

| Phase | Key activities | Exit criteria |
|---|---|---|
| 1. Mobilisation | Kick-off, governance, access, plan and risk register | Approved project management plan |
| 2. Discovery | Workshops, process validation, data and integration assessment | Requirements baseline approved |
| 3. Solution Design | Architecture, UX, ERD, security, interfaces and test strategy | Design approval |
| 4. Foundation Build | Repository, environments, SSO, roles, CI/CD and organisation data | Foundation demonstrated |
| 5. Request Module | Form, drafts, validation, attachments and submission | Request submission accepted |
| 6. Workflow Module | Approvals, amendment, routing, decisions and notifications | Core workflow accepted |
| 7. Governance Module | Completeness, recommendations, consolidation and committee decision | End-to-end process accepted |
| 8. Reporting and Admin | Dashboards, exports, configuration and audit views | Operational reporting accepted |
| 9. Testing | Functional, integration, security, performance and UAT | Acceptance criteria met |
| 10. Deployment | Cutover, rollback readiness, go-live and handover | Production acceptance |
| 11. Stabilisation | Monitoring, defect correction and knowledge transfer | Operational handover completed |

### 8.1 Governance and Reporting

- Weekly progress report covering achievements, plan, risks, issues, decisions and changes.
- Maintained RAID log and decision log.
- Formal design, test, deployment and acceptance checkpoints.
- Change control for scope, schedule, cost and technical baseline.
- Documented action owners and due dates.

### 8.2 Vendor Team

| Role | Expected responsibility |
|---|---|
| Project Manager | Plan, governance, reporting, risk and stakeholder coordination |
| Business Analyst | Requirements, process mapping and traceability |
| Solution Architect | Architecture, integration, security and design assurance |
| UI/UX Designer | Research, wireframes, prototypes and accessibility |
| Laravel Developers | Application development, integration and automated tests |
| Database Engineer | Schema, performance, backup and migration |
| QA/Test Lead | Strategy, scripts, evidence and defect management |
| DevOps Engineer | Environments, CI/CD, deployment, monitoring and rollback |
| Security Specialist | Threat modelling, secure configuration and remediation |
| Trainer/Support Lead | Guides, training, handover and early-life support |

## 9. Testing and Acceptance

### 9.1 Testing Scope

| Test type | Minimum coverage |
|---|---|
| Unit testing | Domain rules, services, validation and utility components |
| Feature testing | Routes, permissions, form submission and workflow transitions |
| Integration testing | Identity, email, storage, queues and approved external services |
| Security testing | Access control, session handling, input handling, dependency and vulnerability checks |
| Performance testing | Agreed transaction volumes, document upload, reporting and concurrent use |
| Backup/recovery testing | Database and document recovery with evidence |
| UAT | Role-based end-to-end scenarios and reporting reconciliation |
| Regression testing | Automated and manual verification following material fixes |

### 9.2 Illustrative Acceptance Scenarios

| Test ID | Scenario |
|---|---|
| UAT-001 | Requestor saves and resumes a draft. |
| UAT-002 | Mandatory and conditional validation prevents incomplete submission. |
| UAT-003 | Unique request number is generated without duplication. |
| UAT-004 | Project Owner approves and workflow advances. |
| UAT-005 | Project Sponsor rejects with mandatory comments. |
| UAT-006 | Governance returns an incomplete request for amendment. |
| UAT-007 | Resubmitted request resumes at the correct stage. |
| UAT-008 | Multiple technical units submit separate recommendations. |
| UAT-009 | Consolidator records conditions and governance route. |
| UAT-010 | Full-route request reaches committee decision stage. |
| UAT-011 | Unauthorized user cannot view or decide a restricted request. |
| UAT-012 | Audit trail contains actor, timestamp, transition and comments. |
| UAT-013 | Reminder and escalation are triggered according to configuration. |
| UAT-014 | Dashboard totals reconcile with transactional records. |
| UAT-015 | Backup is restored and validated in a controlled test. |

## 10. Security, Operations and Support

### 10.1 Security Requirements

- Enterprise SSO and role-based access control.
- Least-privilege administration and separation of duties.
- Server-side authorization for every sensitive action.
- Encryption in transit and at rest where supported by the approved platform.
- Secure secret storage and rotation.
- File type, size and malware controls for uploads.
- Protection against common web application vulnerabilities.
- Dependency inventory and patching process.
- Security and audit log integration.
- Documented vulnerability remediation before production acceptance.

### 10.2 Operational Requirements

- Health endpoint and service monitors.
- Centralized application, queue, scheduler and security logs.
- Database and document backup with tested restoration.
- Documented incident, support and escalation procedures.
- Controlled deployment and rollback.
- Capacity, storage and database-health monitoring.
- Configurable retention and archival.
- Administrative runbook and knowledge transfer.

### 10.3 Warranty and Support

Vendors shall propose warranty, stabilization and ongoing support arrangements, including service coverage, incident-priority definitions, response/restoration targets, patching, monitoring responsibilities, release management, escalation contacts and optional enhancement support. Commercial values and final service levels will be agreed through the procurement process.

## 11. Vendor Response Requirements

The vendor proposal should be structured so that technical and commercial evaluation can be performed consistently.

| Section | Required response |
|---|---|
| Executive response | Understanding of objectives, proposed solution and differentiators |
| Compliance matrix | Comply / partially comply / not comply, explanation and reference |
| Solution architecture | Logical, deployment, security, integration and operations design |
| Implementation methodology | Phases, deliverables, milestones, dependencies and acceptance |
| Project team | Named or proposed roles, relevant experience and allocation |
| Testing and quality | Automation, UAT, security, performance and defect management |
| Data and migration | Reference data setup, migration method, validation and reconciliation |
| Security and privacy | Controls, tooling, certifications where applicable and evidence approach |
| Support model | Warranty, SLA proposal, escalation, maintenance and enhancement options |
| Commercial response | Transparent pricing assumptions, licences, third-party costs and options |
| Risks and assumptions | Constraints, dependencies, exclusions and Client responsibilities |
| Demonstration | Proposed prototype/demo covering key user journeys and audit history |

### 11.1 Mandatory Vendor Declarations

- Identify all third-party and open-source components and applicable licences.
- Declare subcontractors and hosting dependencies.
- Confirm source-code and documentation handover arrangements.
- Declare any material deviation from the preferred technology stack.
- State data-location, access and support-location assumptions.
- Confirm secure development and vulnerability-management practices.
- Identify all information required from the Client to finalize sizing and schedule.

## 12. Appendices

### Appendix A - High-Level Field Catalogue

| Group | Fields |
|---|---|
| Request identity | Request number, title, request date, requestor, department, division |
| Stakeholders | Project Owner, Project Sponsor, reviewers and committee roles |
| Classification | Tier, classification, governance route and current stage |
| Business case | Need, objectives, alignment, reference, value and impact |
| Financial | Budget amount, source, code and funding type |
| Risk | Urgency, justification, risk, mitigation, dependencies and constraints |
| Scope and plan | In scope, out of scope, start, completion and resources |
| Decision | Approval outcomes, recommendations, conditions, dates and comments |
| System control | Status, timestamps, assignment, SLA, version and audit metadata |

### Appendix B - Proposal Compliance Matrix Template

| Req. ID | Requirement summary | Compliance | Vendor response / evidence |
|---|---|---|---|
| FR-___ |  | Comply / Partial / No |  |
| NFR-___ |  | Comply / Partial / No |  |
| SEC-___ |  | Comply / Partial / No |  |
| DEL-___ |  | Comply / Partial / No |  |

### Appendix C - Assumptions to Validate During Discovery

- Expected user population, concurrent usage and annual request volume.
- Hosting platform, network zones and environment standards.
- Identity attributes available from Microsoft Entra ID, including department and division mapping.
- Email and notification services.
- Approved file storage, antivirus scanning and document-size limits.
- Retention schedule and audit-evidence requirements.
- Committee operating model and whether decisions require voting or only recording.
- Required operational dashboards and report export formats.
- Historical data migration scope.
- Required integrations with service management, procurement, finance or project-governance systems.

### Appendix D - Glossary

| Term | Meaning |
|---|---|
| Client | The organisation procuring or sponsoring the solution |
| IT Request | A formal request for a new system, enhancement, subscription/licence, partnership or other IT-related initiative |
| IT Governance | The function responsible for completeness, classification, routing and governance oversight |
| IT HOU | Authorized Head of Unit or equivalent consolidating authority |
| ITIC | IT Investment Committee or equivalent decision body |
| UAT | User Acceptance Testing |
| SSO | Single Sign-On |
| OIDC | OpenID Connect |
| RBAC | Role-Based Access Control |
| RAID | Risks, Assumptions, Issues and Dependencies |

---

**Confidential - For vendor sourcing and solution proposal purposes**
