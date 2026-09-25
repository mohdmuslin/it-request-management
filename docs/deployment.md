# Deployment runbook

Live at **`https://itrequest.mwstay.com/`** · app root `/home/mwstayco/itrequest.mwstay.com/`
· document root `/home/mwstayco/itrequest.mwstay.com/public`

> **This host has no SSH and no cPanel Terminal.** Every command in this document runs
> through cPanel → **Cron Jobs**, usually as a one-off job that is deleted afterwards.
> Anything that cannot be done that way has to be prepared locally and uploaded.

---

## 1. What is assumed before you start

| | |
|---|---|
| PHP on the host | **8.3 or newer.** Check cPanel → Select PHP Version. The application is pinned to `^8.3` |
| MySQL | 8.0 or newer, with a database and user created in cPanel |
| Document root | `.../itrequest.mwstay.com/public` — **not** the application root |
| FTP account | Scoped to the application directory |
| Cron | Available. This is the only way to run anything |

### Required PHP extensions

`ctype`, `dom`, `fileinfo`, `filter`, `hash`, `iconv`, `json`, `libxml`, `mbstring`,
`openssl`, `pcre`, `session`, `tokenizer`, `pdo_mysql`, `curl`, `zip`

`gd` is **not** required — no image is generated or resized by this application.

---

## 2. Verify the PHP binary path first

**This is the step that silently ruins everything if you skip it.**

cPanel cron jobs run with whatever PHP binary you name, and a wrong path fails with **no
output at all** — the job runs, produces nothing, and writes nothing to the log. You then
conclude the command did not work, or worse, that it succeeded.

Get the path from cPanel → **Select PHP Version**, then confirm it works by running
something that prints:

```
* * * * *  /usr/local/bin/php -v >> /home/mwstayco/phpcheck.log 2>&1
```

Wait a minute, read `phpcheck.log`, then **delete the job**. Any readable output proves
the path, the cron daemon and the write permission are all correct.

> If the log is empty, the path is wrong. Nothing else is worth trying until this works.

---

## 3. First-time install

### 3.1 Upload the files

Push to `main`. The deploy workflow builds, verifies and uploads.

**The deploy is armed, not automatic.** Until the repository variable
`FTP_DEPLOY_ENABLED` is exactly `true`, every run is a **dry run**: it connects, compares
and reports what it would change, without changing anything. That is deliberate — the
first run against a new host should be a rehearsal.

To arm it: GitHub → Settings → Secrets and variables → Actions → **Variables** →
`FTP_DEPLOY_ENABLED` = `true`.

**Required secrets:** `FTP_SERVER`, `FTP_USERNAME`, `FTP_PASSWORD`.

> **The deploy deletes files that are not in the repository tree.** That is how it stays
> a mirror rather than an accumulation. The `exclude:` list in `.github/workflows/deploy.yml`
> is what protects `.env`, `storage/**` and the vendor directory — **read it before
> changing it**, because removing a line from it deletes that thing from the server.

### 3.2 Create `.env`

`.env` is never uploaded — it holds the database password and `APP_KEY`, and it exists
nowhere else. Create it through cPanel's **File Manager** in the **application root**
(not `public/`):

```ini
APP_NAME="IT Request Management"
APP_ENV=production
APP_KEY=
APP_DEBUG=false
APP_URL=https://itrequest.mwstay.com

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=<from cPanel>
DB_USERNAME=<from cPanel>
DB_PASSWORD=<from cPanel>

SESSION_DRIVER=database
QUEUE_CONNECTION=database
CACHE_STORE=database

MAIL_MAILER=smtp
MAIL_HOST=<smtp host>
MAIL_PORT=587
MAIL_USERNAME=<address>
MAIL_PASSWORD=<password>
MAIL_FROM_ADDRESS="itrequest@mwstay.com"
MAIL_FROM_NAME="IT Request Management"

# Leave false until a real message has been received. See §7.
ITREQUEST_MAIL_ENABLED=false
```

> **`APP_ENV=production` matters beyond convention.** It disables the demo-account seeder,
> which creates nine accounts whose password is published in the repository.

> **A stray `.env` in the wrong place is worse than a missing one.** Laravel reads the
> application root. An `.env` one directory up is ignored, and it is also a secrets file
> sitting in a folder that may later become a document root.

### 3.3 Run the installer

One-off cron job. Replace the path with the one verified in §2:

```
* * * * *  cd /home/mwstayco/itrequest.mwstay.com && /usr/local/bin/php artisan itrequest:install --admin-email=you@mwstay.com >> /home/mwstayco/install.log 2>&1
```

Wait a minute, read `install.log`, then **delete the job**.

The installer:

1. Creates the storage tree — **first**, because everything else assumes it exists.
   A missing `storage/framework/views` produces *"View path not found"*, which names views
   rather than the missing folder, and a missing `storage/logs` means the error cannot be
   logged, so the log looks empty and innocent.
2. Generates `APP_KEY` **only if it is absent**. It never replaces an existing key:
   regenerating one invalidates every session and makes any encrypted value permanently
   unreadable.
3. Runs migrations.
4. Seeds reference data — roles, tiers, classifications, routes, review units, stages.
5. **Creates the first administrator with a generated password.**

> **The password is printed once and written to `storage/logs/laravel.log`.** Cron output
> usually goes to `/dev/null`, so read it from the log if it scrolled past. Then change it
> and remove the log entry.

**The installer refuses to run a second time** unless you pass `--force`. That is a safety
feature: re-running it would regenerate `APP_KEY`.

### 3.4 Set the document root

cPanel → **Domains** → the subdomain → Document Root:

```
/home/mwstayco/itrequest.mwstay.com/public
```

cPanel will not allow a document root outside the subdomain's own directory, which is why
the application lives *inside* `itrequest.mwstay.com/` rather than beside it.

> **Delete `public/index.html` if it exists.** A placeholder "Hello World" file can win
> precedence over `index.php` depending on `DirectoryIndex` order, and the application then
> appears not to load at all while every other check passes.

---

## 4. Routine deployment

Push to `main`. That is the whole procedure — the workflow builds, verifies, uploads, and
leaves a `DEPLOY` flag that a cron job acts on.

**The cron job must exist for the post-upload steps to run** (migrations, caches, health
check). Without it, the files arrive and the migrations do not run:

```
0 * * * *  cd /home/mwstayco/itrequest.mwstay.com && [ -f DEPLOY ] && rm -f DEPLOY && /usr/local/bin/php artisan itrequest:deploy >> /home/mwstayco/deploy.log 2>&1
```

> **Redirect the output.** Cron sends it to `/dev/null` otherwise, and a failed deployment
> that produced no output is indistinguishable from one that never ran.

### Why the flag is created before the upload, not after

A flag created after the transfer would need a second transfer to deliver it — and that
second transfer can fail *after the code has already landed*. The release ships and the
migrations never run, with nothing to say so. Created before the upload, it travels in the
same pass: either both arrive or neither does.

### What `itrequest:deploy` does

| Step | Why that order |
|---|---|
| Ensure the storage tree | Everything after it assumes the directories exist |
| Clear caches | A stale `config.php` is loaded *instead of* `.env`, so `migrate` would connect with the **old** database credentials — and migrate the wrong database, successfully and silently |
| Migrate | `--force`, because otherwise Laravel prompts and a cron job hangs until it times out with the migration half-applied |
| Rebuild caches | **After** migrating: caching before a migration that adds a config key bakes in the old shape |
| Verify | A deploy that reports success while the site is broken is worse than one that fails — nobody looks |

`route:cache` is deliberately **not** run. Every route in this application is registered
from a Livewire component, and route caching has a long history of failing on that. The
saving on a handful of routes is not worth a release that 500s on every page.

---

## 5. The deploy check

Run this after any configuration change, and before go-live:

```
* * * * *  cd /home/mwstayco/itrequest.mwstay.com && /usr/local/bin/php artisan itrequest:deploy-check >> /home/mwstayco/check.log 2>&1
```

It reads and changes nothing, so it is safe to run against the live site.

Every finding goes to **stdout**, so the whole report survives redirection. That is
deliberate: on a host with no shell the log file *is* the report, and a checker that lost
half its output when redirected would defeat its own purpose.

The exit code is non-zero when something needs attention, so the job can be read without a
human deciding what counts as a problem.

### What it checks, and why each one matters

| Check | Why it is not cosmetic |
|---|---|
| `APP_KEY` set | Without it every session is invalid. The application appears to work until somebody signs in |
| Debug off in production | Error pages expose file paths and configuration to anyone who can trigger one |
| Storage writable | A missing `views` directory is *"View path not found"* — a message that names views rather than the folder |
| Migrations current | Compared against the files on disk, not parsed from `migrate:status`, which changes format between versions |
| Reference data present | An empty tier list makes the wizard unusable, and it fails at the **last** step of a form already filled in |
| **Departments exist** | A required field in wizard step 1. Empty means the wizard cannot be completed at all |
| **Public holidays recorded** | Not a failure, but every due date landing on a holiday is wrong, and the aging report shows delays nobody could have avoided |
| Stage targets set | A stage with no target can never be reported overdue — which is the position the current process is in for every stage |
| An administrator exists | Otherwise nobody can manage users, reference data or the calendar |
| Technical reviewers have units | A reviewer in no unit cannot file a recommendation. They report *"there is nothing for me to do"*, which points at the application |
| Email state | Reported every run, because four required features depend on it |

---

## 6. Scheduled jobs

| Schedule | Command | Purpose |
|---|---|---|
| Hourly | `itrequest:notify-approvals` | Reminders for due tasks, escalations for overdue ones |
| Every minute | `queue:work --stop-when-empty --max-time=55` | Processes queued notifications |
| Hourly | `itrequest:deploy` **guarded by the `DEPLOY` flag** | Post-upload deployment steps |

Both are registered in `routes/console.php` and run from a single cron entry:

```
* * * * *  cd /home/mwstayco/itrequest.mwstay.com && /usr/local/bin/php artisan schedule:run >> /home/mwstayco/schedule.log 2>&1
```

> **`--stop-when-empty` rather than a daemon.** A daemon that dies is not restarted by
> anything on this host, and nothing would report that it had stopped. `--stop-when-empty`
> exits and is started again by the scheduler a minute later, which is self-healing.

> **`withoutOverlapping` on the notification job but not the queue worker.** A queue worker
> still running when the next minute arrives is normal on a busy morning; skipping a minute
> is better than skipping the queue entirely.

---

## 7. Email — the open gate

**Email delivery is currently OFF.** `ITREQUEST_MAIL_ENABLED=false`.

Every notification is still **recorded** in the `notifications` table and written to the log,
but nothing is delivered — rows stay `pending` rather than being marked `sent`. That is
deliberate: marking an undelivered notification sent would make the log assert a delivery
that never happened, and the first real send would then be invisible.

Four required features depend on delivery: assignment notification, decision notification,
reminders and escalation.

### 7.1 What you need to supply

Everything comes from **cPanel → Email Accounts → Connect Devices** for the mailbox you
intend to send as. Nothing needs to be invented.

| Setting | Where it comes from | Notes |
|---|---|---|
| `MAIL_HOST` | cPanel → Connect Devices | Usually `mail.<yourdomain>` |
| `MAIL_PORT` | cPanel → Connect Devices | **Not always the default.** Some accounts are 587, some 465 |
| `MAIL_SCHEME` | Matches the port | `tls` for 587, `ssl` for 465. **Mixing them fails** |
| `MAIL_USERNAME` | The mailbox address | The **full** address, not just the part before the `@` |
| `MAIL_PASSWORD` | The mailbox password | The **mailbox** password, not the cPanel login |
| `MAIL_FROM_ADDRESS` | An address your server may send as | A mismatch with the authenticated account is the commonest cause of a message that is accepted and then silently dropped |

> **A separate mailbox is worth creating for this.** Sending as a person's own address means
> their sent-folder and reputation carry the application's notifications, and a change of
> staff breaks the mail without anybody connecting the two.

### 7.2 Prove it before switching it on

**Do not enable delivery because the settings look right.** The three ways this fails all
look identical to a working configuration from the settings screen:

- `MAIL_MAILER=log` is a *real mailer*. It writes to a file, reports success and delivers
  nothing. It is also the default in `.env.example`, so it is what a copy-paste deployment
  ends up with.
- A wrong `MAIL_FROM_ADDRESS` is frequently **accepted** and then dropped by the receiving
  server as a spoofing attempt.
- A shared host often refuses outbound SMTP on one port while allowing the other.

So test it, with a real inbox you can open:

```
* * * * *  cd /home/mwstayco/itrequest.mwstay.com && /usr/local/bin/php artisan itrequest:test-mail you@mwstay.com >> /home/mwstayco/mailtest.log 2>&1
```

Read `mailtest.log`, **then delete the cron job.**

The command reports what it knows and states plainly what it cannot: that the mail server
**accepted** the message, which is not the same as delivered. Check the inbox — **including
the spam folder**, because a missing SPF record sends a legitimate message to spam and the
sender is the last person to find out.

It refuses outright to test the `log` or `array` mailers rather than reporting a success
that could never happen.

### 7.3 Switch it on

Only once a message has arrived:

```ini
ITREQUEST_MAIL_ENABLED=true
```

Then confirm, and check the queue worker is processing:

```
php artisan itrequest:deploy-check
```

`itrequest:deploy-check` **fails** if delivery is enabled while the mailer is `log`, because
that combination means the application believes it is notifying people and nobody is being
told anything.

### 7.4 If it does not work

| Symptom | Cause |
|---|---|
| `535 authentication failed` | `MAIL_USERNAME` is not the full address, or the password is the cPanel login rather than the mailbox password |
| `Connection refused` | Wrong port. Try the other one, and change `MAIL_SCHEME` to match |
| `certificate verify failed` | `MAIL_SCHEME` is `ssl` on the `tls` port, or the reverse |
| Accepted but never arrives | Check spam. Then check `MAIL_FROM_ADDRESS` matches an address the server may send as |
| Accepted, no error, no message anywhere | The mailer is `log`. Check `MAIL_MAILER` |

---

## 8. Operating without a shell

| Task | How |
|---|---|
| Run a command | One-off cron job, read the log, **delete the job** |
| Read a log | cPanel → File Manager → `storage/logs/laravel.log` |
| Reset a password | Upload `app/Console/Commands/SetPassword.php`, run `itrequest:set-password` by cron — it **deletes itself** after a successful run |
| Check configuration | `itrequest:deploy-check` |
| Change reference data | The application's own Administration screens |

> **A reset script left on a server is a back door that outlives its purpose.**
> `itrequest:set-password` removes itself for that reason. If it reports that it could not,
> it prints the full path — delete it by hand.

> **Every cron job you add is one you must remember to remove.** A one-off job left in place
> runs forever. When it re-runs a destructive command, the damage is done before anybody
> looks.

---

## 9. When something is wrong

| Symptom | First thing to check |
|---|---|
| **500 on every page** | The storage tree. A fresh FTP-deployed Laravel has no `storage/framework/views` |
| **500, and the log is empty** | `storage/logs` may not exist, so the error cannot be written. An empty log is **not** "nothing is wrong" |
| **403 on every page** | `public/index.php` is missing, or the document root is wrong |
| **Blank page** | `public/build/manifest.json` is missing — the frontend did not build, and a missing manifest is not a build error |
| **"Index of /"** | The document root points at an empty directory |
| **A cron job produces nothing** | The PHP binary path is wrong. Nothing else — a wrong path fails silently |
| **Signed in, but almost nothing is available** | The account holds no role. Run `itrequest:set-password --show` |
| **A due date is a day out** | A public holiday is missing from the calendar, or the business-hours config is wrong |
| **Duplicate-looking calendar entries** | `holidays.date` is unique. A validation message rather than a database error means the date handling is correct |

---

## 10. Go-live checklist

- [ ] `itrequest:deploy-check` reports **0 failures**
- [ ] Every warning read, and each one either fixed or knowingly accepted
- [ ] The first administrator's password changed, and the log entry removed
- [ ] Real departments and divisions added
- [ ] This year's public holidays added
- [ ] Stage targets agreed with the business
- [ ] Review units assigned to each classification
- [ ] Scheduled jobs registered, and each one **confirmed to have fired** by reading its log
- [ ] The one-off install and check cron jobs **deleted**
- [ ] `APP_DEBUG=false`
- [ ] One real request raised, approved, consolidated and closed end to end
- [ ] The audit trail for that request is complete — every transition, with actor and timestamp
- [ ] Email proven, then `ITREQUEST_MAIL_ENABLED=true`
