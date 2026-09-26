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
| **What each tier demands** | **Tier field rules** | A Tier 2 request is filed with no budget, because nothing required one |
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

### Where you sign in depends on the configuration

Two things can appear on the sign-in screen, and which one appears is set by whoever configured the
application, not by you:

| What you see | What it means |
|---|---|
| **Email and password** | The application holds the accounts. This is the default |
| **Sign in with your organisation account** | Sign-in goes through Microsoft. You are taken there and returned |

If you see a **warning that single sign-on is selected but not configured**, sign-in is still
working by password while somebody finishes the setup. It is shown deliberately rather than hidden:
a system that quietly falls back looks like it has SSO when it does not, and nobody finishes the
job. The warning names the missing setting.

> **Nobody can reset a password by email.** There is no mail server configured to send a reset
> link, and accounts are created by an administrator rather than self-registered. The section
> below covers what to do instead.

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

### Adding an account when the screen is unreachable

**Administration → Users and roles** is behind a sign-in, so it cannot help when nobody can sign in.
For that there is a command, run the same way as the one above:

```
php /home/{account}/itrequest.mwstay.com/artisan itrequest:make-user someone@example.com --role=requestor
```

It prints a generated password once and writes it to the log. `--role` is repeatable. A role name
that is misspelled **fails** rather than creating an account with no role — the failure you would
otherwise find days later, from the person who cannot do their job.

---

## 3. Users and roles

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
| **Tier** | How large the investment is. **Decided by the budget band**, and drives due-date targets |
| **Classification** | What kind of change it is — new capability, enhancement, replacement, compliance |
| **Governance route** | How much scrutiny. Light, Moderate or Full |
| **Review unit** | Who must file a recommendation — IT Operations, IT Platforms, IT Delivery & Governance |

Each option has a **name**, a **code** and a description. Tiers additionally carry a **budget band**
and a **decider** — see §4.1.


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

### 4.1 Budget bands — what decides a tier

Each tier carries two bounds and a **Chosen by** setting:

| Tier | From | Up to | Chosen by | Meaning |
|---|---|---|---|---|
| **Tier 1** | 0.00 | 50,000.00 | Requestor | RM50,000 and below |
| **Tier 2** | 50,000.01 | *(no limit)* | Requestor | RM50,001 and above |
| **Tier P** | *(none)* | *(none)* | IT Governance | A partnership — not decided by cost |

**Both bounds are inclusive**, so **RM50,000.00 is Tier 1 and RM50,000.01 is Tier 2**. That is why
the second band starts at 50,000**.01** and not at 50,000: two bands that both contain 50,000 would
give that amount two answers, and "which tier is this?" having two answers is worse than either
answer being wrong.

The screen **refuses to save an overlapping band.** The message names the amount that would be in
both and the tier it collides with.

> **Moving a boundary takes two saves, and the order is not a preference.**
>
> Save the **higher tier first** — narrow it so its floor moves above the new boundary — then widen
> the lower tier up to it. The reverse order has a moment where both bands contain the new
> boundary, and there is no single save that avoids it, so the first one is refused. The refusal
> says so.
>
> That is a real constraint rather than a quirk to work around: allowing the overlap would put an
> invalid configuration live, and the first symptom would be a message on somebody else's request
> form.

**"Chosen by" is per tier, not one global setting**, because the two coexist. Tier 1 and Tier 2 are
arithmetic — the requestor picks one and the application refuses a choice that contradicts the
amount. Tier P is a judgement, so `IT Governance` keeps it out of the requestor's dropdown entirely
and governance sets it during completeness review.

A tier with **no bounds at all** is not decided by budget. Its amount fields are left empty, which
means "no limit" — never zero. Recording a ceiling as 0 would describe a band covering nothing.

> **Clearing a bound means "no limit", not "zero".** Empty is how you express an open-ended top
> tier like Tier 2.

**The requestor cannot pick a tier that contradicts the amount.** Choosing Tier 1 and entering
RM80,000 is refused, with the message shown beside the **amount** — not beside the tier. The tier is
chosen on step 1 and the amount entered on step 3, so a message against the invisible field would
leave the requestor refused with nothing on screen explaining why.

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

## 6. Tier field rules

**Administration → Tier field rules**

This is where the system's answer to *"what does this tier require?"* lives. For every field and
every tier you set one of three states:

| State | What it means |
|---|---|
| **Required** | The request cannot be submitted without it |
| **Optional** | Accepted, and may be left blank. **This is the default** — a field with no rule is optional |
| **Hidden** | Does not apply to this tier. The field is not shown, and **any value already in it is cleared** when the tier is chosen |

**Every tier** is the fallback column. A tier's own setting beats it, so the usual shape is one
rule in the fallback and an exception in a tier — rather than three identical rules.

### Why hiding clears the field

If a field the tier does not use kept its value, validation would refuse the request with an error
pointing at a field the requestor can no longer see. Invisible *and* blocking is the worst of both,
so the value is cleared and a message names what went.

That message is the only warning somebody gets that their typing has gone. It is worth telling
people about: switching the tier near the end of a long form can discard work.

### What cannot be ruled on

These are always required, and are not on the screen because a rule could break the workflow: the
title, department, project owner, business need, impact if not implemented, and urgency.

**Business plan reference** and **ad-hoc justification** are also not on the screen, for a
different reason: they are governed by whether the request claims alignment with an approved plan,
not by tier. They are a pair — one or the other is required, and which one depends on an answer
earlier in the form. A tier rule that made one optional would let a request through claiming plan
alignment with no plan cited and no reason given.

### The starting position

The brief requires fields to be driven by tier but does not say which field for which tier — that
is a business decision. What ships is a first draft, chosen to be visible rather than neutral so
it is easy to disagree with:

| Tier | What it demands |
|---|---|
| **Tier 1** | **Budget amount.** Nothing else — a small, well-understood request should not need a cost *code* to be filed |
| **Tier 2** | Budget amount, budget source and resources required. A request of this size is approved against a cost |
| **Tier P (Partnership)** | Budget amount and budget code required; **dependencies and constraints hidden**, because a partnership with another organisation is governed by the agreement rather than by an internal dependency list |

**Tier 1 requires the amount even though it asks for nothing else**, and that follows from the
budget bands rather than being a preference: the amount is what *decides* which tier a request is,
so a request with no amount has no valid tier and could never be submitted. See §7.

**Change these to match how the organisation actually works.** They are the system's opinion about
governance, and an administrator's is better.

### It takes effect immediately, and it is not retroactive

A saved rule applies to the **next request saved**, including one already open in a form. It does
not change requests already submitted — those were judged against the rules in force when they
were filed, which is what makes the audit trail meaningful.

### The seeder will not overwrite you

Changing the rules affects future deployments only in the sense that nothing changes: the seeder
creates a rule that is missing and **never updates one that exists**. If it updated, it would
silently revert a change on the next release, and the change would reappear days later with nobody
connecting it to a deploy.

---

## 7. The audit log

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

## 8. The two things that lock everybody out

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

## 9. Routine tasks

| When | Do |
|---|---|
| A new employee starts | Add an account, grant roles and units, hand over the password |
| Somebody leaves | **Deactivate** the account. Do not delete it |
| Somebody changes department | Edit the account and set the new department. **Clear the division if it belongs to the old department** — the screen does this for you when the department changes |
| Roles change | Edit the account. Role changes are timestamped in the audit trail |
| Before a public holiday | Add it to the calendar |
| Targets change | Update the grid. Existing tasks keep their old targets — say so when you announce it |
| **What a tier requires changes** | Update **Tier field rules**. Existing requests are unaffected — say so when you announce it |
| A new tier or classification is needed | Add it in Reference data. Check the routing rule still says what you mean |
| Audit requested | The Audit log screen, filtered to the period in question |

---

## 10. When something looks wrong

| Symptom | Likely cause | Check |
|---|---|---|
| "I signed in and there is nothing there" | The account holds no role | Users and roles — the account shows **No role** |
| "There is nothing in my queue" | Reviewer holds no review unit | Users and roles — check the **Review units** boxes |
| A user cannot sign in | Account deactivated | Users and roles — the row is greyed and marked **Deactivated** |
| A due date looks a day out | A public holiday is missing from the calendar | Due dates and calendar |
| A request will not close | It is waiting on a decision | Open the request. The Close panel **names** the outstanding items rather than saying "cannot close" |
| **A field is missing from the form** | The tier hides it | **Tier field rules**. The form names the hidden fields above the gap, so an empty space is not read as a broken screen |
| **A request will not submit over a field nobody can see** | A rule change hid a field while somebody had it open | Reload the form. A hidden field's value is cleared when the tier changes, but a rule changed mid-session is only picked up on the next save |
| Nobody received an email | Email is not configured on this host | See `deployment.md` §7. Notifications are recorded and will send once it is |

---

## 11. What this guide deliberately does not cover

| Topic | Where it is instead |
|---|---|
| Deploying a new version | `deployment.md` |
| How the workflow is designed, and why | `design.md` |
| Every field on every screen | `interface.md` |
| Server, database and backups | `deployment.md`, `database-design.md` |
| Using the system to raise or decide a request | `user-guide.md` |
