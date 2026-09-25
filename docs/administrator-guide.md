# Administrator Guide

**IT Request Management System** — for the person who runs the system day to day.

This guide covers the screens under **Administration**, the activity behind them, and the
things that only come up occasionally: a forgotten password, a new employee, a public
holiday nobody told the system about.

You do not need to know how the application is built to use this guide. Where a decision
has a reason worth stating, the reason is given — because "do not remove the last
administrator" is a rule you can follow, while knowing *why* is what lets you judge the
case it does not cover.

---

## 1. What you are responsible for

| Area | Screen | What goes wrong without it |
|---|---|---|
| People | **Users and roles** | An account with no role can sign in and do nothing |
| Vocabulary | **Reference data** | Tiers, classifications and routes stop matching how the organisation works |
| Time | **Due dates and calendar** | Due dates land on public holidays, and every target drifts |
| Evidence | **Audit log** | Nobody can answer "who changed this?" |

The **Reports** screen is *not* on this list. Reports belong to management, and an
administrator does not need to run them to keep the system working.

---

## 2. Signing in

Sign in at the application address with your email address and password. Your landing
screen depends on your role:

| Your role | Where you land |
|---|---|
| Administrator | Dashboard |
| Requestor | Dashboard |
| Project Owner, Project Sponsor | My Approvals |
| IT Governance Reviewer, IT HOU | Governance Workspace |
| Technical Reviewer | Recommendations |
| Committee Secretariat | Committee Workspace |
| Auditor | Audit log |

**Landing where you expected to be is a useful check.** If you are an administrator and you
land on the Dashboard, that is correct. If you are a Technical Reviewer and you land on the
Dashboard, you are probably missing the Technical Reviewer role — ask another administrator
to check your account.

### If you cannot sign in at all

Nobody can reset a password from the sign-in screen: there is no mail server configured to
send a reset link. On this host there is also no shell access, so a reset is done by a
scheduled one-off job.

Your hosting provider's cPanel has **Cron Jobs**. Create a one-off job running:

```
php /home/{account}/itrequest.mwstay.com/artisan itrequest:set-password you@example.com
```

It prints the new password and writes it to `storage/logs/laravel.log`, then **deletes
itself**. Remove the cron job afterwards. If the command has already deleted itself, restore
it by re-uploading `app/Console/Commands/SetPassword.php` from the repository.

> **Why it deletes itself.** A password-reset script left on a server is a back door that
> outlives its purpose. The step that gets skipped on a busy morning is the one where
> somebody remembers to remove it, so the command does it for you.

---

## 3. Users and roles

**Administration → Users and roles**

### The principle behind this screen

Every request, approval and comment in the system records **who** did it. That is the whole
value of the audit trail, and it means:

- **Accounts are deactivated, never deleted.** Deleting a person would either orphan their
  history or delete it with them, and a trail that loses the name of the person who approved
  something is not a trail. A deactivated account cannot sign in and does not appear in any
  picker, but everything it ever did stays readable.
- **Roles are granted, not assumed.** A new account starts able to do nothing.

### Adding an account

1. Click **Add an account**.
2. Enter the person's **name** and **email address**. The email is the sign-in name and the
   address every notification is sent to, so it must be right — it is checked for duplicates.
3. **Employee number** is optional. If the organisation uses them, enter it; it is also
   checked for duplicates.
4. Click **Create the account**.

A password is generated and displayed **once**. Copy it and pass it to the person; they
should change it after signing in. It is stored hashed, so no screen — including yours — can
show it again.

> **Why you do not choose the password.** An administrator who types a password for somebody
> else knows it. Every account created that way starts with two people holding the same
> credential and no way to tell which of them did something. A generated password is shown to
> you once and changed by them, so the credential is never one person's choice imposed on
> another.

The new account has **no role**. It can sign in and reach almost nothing, which is the safe
default. Grant roles next.

### Granting roles and units

Click **Edit** beside an account, then set:

| Field | Meaning |
|---|---|
| Department / Division | Where the person sits. Used for reporting and for the scope banner |
| Manager | Who they report to. Used for escalation targets |
| Active | Untick to stop them signing in, without touching their history |
| **Roles** | What they may do |
| **Review units** | Which units they review for — Technical Reviewers only |

The nine roles:

| Role | What it allows |
|---|---|
| Requestor | Raise and track their own requests |
| Project Owner | Approve the requests naming them as Owner |
| Project Sponsor | Approve the requests naming them as Sponsor |
| IT Governance Reviewer | Assess completeness, propose tier and classification |
| Technical Reviewer | File a recommendation **for their unit** |
| IT HOU / Consolidator | Consolidate recommendations and set the governance route |
| Committee Secretariat | Record the committee decision |
| Administrator | Manage the system |
| Auditor (read-only) | See everything, change nothing |

> **A role and a unit are different things, and the screen says so.** A Technical Reviewer
> holds the role *and* belongs to a unit. The same role behaves differently depending on the
> unit, and a Technical Reviewer in no unit **cannot file any recommendation at all** — the
> units assigned to a request are what decide who reviews it. If a reviewer says "there is
> nothing in my queue", check their units first.

### Things this screen refuses to do, and why

| Refused | Because |
|---|---|
| Deactivating your own account | It is the single change that can lock everybody out |
| Removing your own administrator role | Same |
| Setting an account as its own manager | It makes the escalation path a loop |
| An account with a duplicate email | A notification would reach the wrong person |

When a second administrator exists, the amber warning about "one active administrator"
disappears. **Grant the administrator role to a second person.** Two administrators is one
mistake away from none.

---

## 4. Reference data

**Administration → Reference data**

This is the vocabulary the whole workflow is built from. There are four kinds:

| Kind | Used for |
|---|---|
| **Tier** | How large the investment is. Drives due-date targets |
| **Classification** | What kind of change it is — new capability, enhancement, replacement, compliance |
| **Governance route** | How much scrutiny. Light, Moderate or Full |
| **Review unit** | Who must file a recommendation — IT Operations, IT Platforms, IT Delivery & Governance |

Each option has a **name**, a **code** and a description.

### The code matters

The code is what configuration and the routing rule refer to. It must be **lowercase with
underscores** — `new_capability` rather than `New Capability`. The screen refuses anything
else, and that is deliberate: a code with a space in it works until it is compared to one
without, and the failure is silent.

Once an option is in use, **do not change its code**. Rename the label freely — that is what
people read.

### Deactivating rather than deleting

Deactivating removes an option from every dropdown. It stays on every request that already
used it, so history still reads correctly. Deleting would leave those requests describing
themselves with a blank.

**The screen refuses to deactivate the last active option of a kind.** A request needs a
tier, so an empty tier list makes the wizard unusable at the final step of a form somebody
has already filled in.

> **The routing rule is reference data, not code.** The table on this screen says which
> combinations of tier, classification and decision type require the committee. Changing it
> changes routing immediately, with no release and no deployment. That should feel like a
> significant action, because it is.

---

## 5. Due dates and the calendar

**Administration → Due dates and calendar**

### Targets

The grid sets, for each **stage** and each **tier**, how many **business days** that stage is
allowed. An empty cell that has no tier-specific value falls back to the default column on
the left.

Business days means Monday to Friday, excluding public holidays, and counted in working
hours rather than calendar days. The screen shows the configured working day at the bottom
(09:00–13:00 and 14:00–18:00, eight hours) so you can see what a target means.

A made-up example: a Tier 1 request in Completeness Review with a target of **5** gets five
business days from the moment the stage was entered. A holiday inside that window does not
consume one.

> **Changing a target is not retroactive, and the screen tells you so.** A due date is
> calculated once, when a stage is entered, and stored. Your change applies to tasks created
> **after** you save. Requests already waiting keep the target they were given.
>
> That is deliberate: moving a target underneath somebody who was already working to it would
> make their performance look different depending on when the report was run.
>
> **Clearing a cell is not the same as entering zero.** Empty means "no target", and the aging
> report says so honestly. Zero is a real target meaning "same day", and a request would show
> as immediately overdue.

### Holidays

Enter the **date** and a **name**, and click to add. Due dates from that moment skip the
date.

| Behaviour | Why |
|---|---|
| A holiday you enter is marked **manually overridden** | A future calendar sync replaces synced rows. Without the mark it would delete dates you entered by hand, and the gap would surface only when a due date landed on a public holiday |
| A duplicate date is refused | Two entries for one date make removal ambiguous |
| Removing a holiday is allowed | A date entered by mistake would otherwise persist forever |

**Load the year's public holidays before go-live.** Every date missing from this list pushes
a due date onto a day the office is closed, and the first evidence of it is somebody asking
why a deadline fell on a public holiday.

---

## 6. The audit log

**Administration → Audit log** — also the landing screen for the Auditor role.

Every material change is recorded with the person, the time, what it related to, and the
before-and-after values. There is **no edit and no delete**, anywhere, for anybody. That is
the point: a record that can be changed is not evidence.

Two separate records exist, and they answer different questions:

| Record | Question it answers | Where you see it |
|---|---|---|
| Workflow history | "How did this request move?" | The status timeline on the request |
| **Audit log** | "Who changed this value, and when?" | This screen |

When a dispute arises — "I never approved that", "the budget was different last week" — the
audit log is what settles it, because it holds the values before and after.

---

## 7. The two things that lock everybody out

An administrator's mistakes are different in kind from everybody else's, because they affect
everyone. Two are worth naming.

### 1. Removing or deactivating the last administrator

The screen prevents you from doing this to **your own** account. It cannot prevent you from
doing it to the only *other* administrator, and then neither account has the role.

**Keep two administrators.** Change the warning on the Users screen from an amber note into
an action item.

### 2. Losing the application key

`APP_KEY` in the `.env` file encrypts stored data and signs every session. Regenerating it
invalidates every password reset token and makes anything stored encrypted permanently
unreadable — silently, because the application simply returns a value that can never be
decrypted.

**Never run `key:generate` on the live site.** Not to "fix" anything. If the key is missing
or wrong, restore it from the backup of `.env`, do not generate a new one.

---

## 8. Routine tasks

| When | Do |
|---|---|
| A new employee starts | Add an account, grant roles and units, hand over the password |
| Somebody leaves | **Deactivate** the account. Do not delete it |
| Somebody changes department | Edit the account and set the new department. **Clear the division if it belongs to the old department** — the screen does this for you when the department changes |
| Roles change | Edit the account. Role changes are timestamped in the audit trail |
| Before a public holiday | Add it to the calendar |
| Targets change | Update the grid. Existing tasks keep their old targets — say so when you announce it |
| A new tier or classification is needed | Add it in Reference data. Check the routing rule still says what you mean |
| Audit requested | The Audit log screen, filtered to the period in question |

---

## 9. When something looks wrong

| Symptom | Likely cause | Check |
|---|---|---|
| "I signed in and there is nothing there" | The account holds no role | Users and roles — the account shows **No role** |
| "There is nothing in my queue" | Reviewer holds no review unit | Users and roles — check the **Review units** boxes |
| A user cannot sign in | Account deactivated | Users and roles — the row is greyed and marked **Deactivated** |
| A due date looks a day out | A public holiday is missing from the calendar | Due dates and calendar |
| A request will not close | It is waiting on a decision | Open the request. The Close panel **names** the outstanding items rather than saying "cannot close" |
| Nobody received an email | Email is not configured on this host | See `deployment.md` §7. Notifications are recorded and will send once it is |

---

## 10. What this guide deliberately does not cover

| Topic | Where it is instead |
|---|---|
| Deploying a new version | `deployment.md` |
| How the workflow is designed, and why | `design.md` |
| Every field on every screen | `interface.md` |
| Server, database and backups | `deployment.md`, `database-design.md` |
| Using the system to raise or decide a request | `user-guide.md` |
