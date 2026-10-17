---
kind: code
depends_on: []
---

# Proposal: admin-operations-console

## Summary

A background job monitor with logs, failures and a run now button is the
second strongest capability in the whole sweep: nine driven passers, and
dossiq shows one poller's last run. Fifteen background jobs run unobserved
today, and a stuck termijn job is invisible until a statutory term is
missed. This change gives an administrator one console: every run with its
outcome, a schedule they can edit, an alert when runs start failing,
maintenance actions on demand, a data consistency check, a support bundle
and the instance's own facts.

## Candidates and cluster

Cluster 1 of `procest/_round4/discovery/build-plan.md`
(ConductionNL/market-intelligence, 2026-09-14), "The administrator's own
screens: jobs, logs, health and maintenance". Owner openregister, size M,
nineteen candidates: C-configuration-1, -36, -41, -42, -48, -57, -64, -74,
-81, -86, -87, -89, -90, -91, -97, -98, -100, -101, and C-integrations-10.
Three are `must` and one is a matrix hole: C-configuration-57. Passers: 25,
twenty-two driven and three documented, the widest of any cluster. dossiq
rates `yes` on one, `partial` on three and `no` on fifteen.

## The decisions this rests on

**D6, relevance-led promotion.** C-configuration-57 is a `must` with nine
driven passers and no row in the corpus to hold it, which is what a matrix
hole means. Under the old bar it would still be a hole; under D6 it enters.

**D21, documented candidates admitted and labelled.** C-configuration-101
(jira-data-center) has no driven passer and is recorded rather than built.

## Why

The proving system is forgejo, cited by the configuration lane at
`configuration.tsv:58`: "/admin/monitor, processes, cron tasks, queues,
stacktrace, plus /admin/cron and /admin/cron/{task} (menu-tree.md,
api.go)". Every background run listed with its outcome and startable
again. The full driven set for C-configuration-57 is forgejo, freescout,
gitea, gitlab, glpi, kanboard, osticket, otobo and znuny: nine systems,
all driven, and dossiq's own lane reads "zero hits for a failed-job
surface; 15 background jobs run unobserved".

The other members and their driven passers:

- **A recurring job is defined, scheduled and its last run read**
  (C-configuration-41, `must`): request-tracker, "Admin, Tools, Scheduled
  Processes (5.0.8, share/html/Admin/Tools/ScheduledProcesses/,
  bin/rt-run-scheduled-processes.in)".
- **An administrator is alerted when the product starts failing**
  (C-configuration-42, `must`): freescout, "Settings, Alerts alert_logs,
  alert_logs_fetch_min_occurrences, alert_logs_period". The silent failure
  that costs a statutory term.
- **Maintenance actions on demand** (C-configuration-64): dimpact-zac and
  freescout, "Manage, System, Tools, ajax clear_cache, migrate_db,
  logout_users". A search index that cannot be rebuilt is a permanent
  inconsistency.
- **The product checks its own stored data and offers to repair it**
  (C-configuration-86): forgejo, gitea, gitlab and request-tracker,
  "forgejo doctor, 27 checks (code-census.md), /admin/unadopted".
- **A support bundle** (C-configuration-87): otobo, zammad and znuny,
  "Support data collector (AdminSupportDataCollector.pm,
  PublicSupportDataCollector.pm, about forty graded checks)".
- **The product is closed to users with its own message**
  (C-configuration-90): glpi, osticket, otobo and znuny, "Maintenance mode
  (src/Glpi/Controller/MaintenanceController.php)".
- **The application log is read in the product, filtered by level**
  (C-configuration-74): valtimo, "Admin > Logs (Admin-Logs.md)".
- **The instance names its own version and build** (C-configuration-100):
  dimpact-zac, plane and valtimo, "Instance admin,
  license/models/instance.py:86 ChangeLog".
- **A backup taken and restored from inside the product**
  (C-configuration-1): huly, itop and openproject, "Administration Backups,
  resource :backups with request_backup, reset_token_dialog, delete_token".

**What exists here and does not close it.** `production-observability`
specifies a Prometheus metrics endpoint, structured logging, health and
readiness endpoints and BIO audit logging, which is why C-integrations-10
is the one member dossiq rates `yes`. `admin-settings` renders an
OpenRegister section in the Nextcloud admin panel with operational status
sections. `settings-management` orchestrates cache statistics, clearing
and warmup, and mass validation over all objects. What none of them has is
a run history: no screen lists the background runs, no run can be started
again, nothing alerts when they start failing, and no maintenance action
beyond the cache is reachable. Nextcloud's own `occ` runs the jobs and
tells nobody.

## What changes

- **Every background run is listed with its outcome.** Job, start, end,
  duration, outcome, and the failure with its message when it failed. The
  list filters by job, by outcome and by period.
- **A run is started again from the console.** Starting one records who
  started it, and a job already running is not started twice.
- **A recurring job's schedule is administered.** The interval, the window
  it may run in, and whether it is enabled at all. The last run and the
  next due time are on the row.
- **Failures raise an alert.** An administered threshold over an
  administered period, delivered as a notification and readable on the
  console. It names the job and the first failure.
- **Maintenance actions run on demand.** Rebuild the search index, clear
  and warm the cache, run the data consistency check. Each one is a job
  with its own run row, so a maintenance action is as observable as
  anything else.
- **The product checks its own stored data and offers to repair it.** A
  read-only check that names each inconsistency and the objects it
  touches, and a repair that is a separate, authorised act.
- **Maintenance mode closes the instance with its own message.** Reads and
  writes are refused with the administered message, the administrators are
  not locked out, and the mode is on the audit trail.
- **A support bundle is produced.** Version, build, active configuration
  with secrets redacted, the check results and recent failures, as one
  downloadable file.
- **The instance names its own facts.** Version, build, the apps it
  depends on and their versions, and the licence it ships under.

## Consumers

- **dossiq**: a job monitor page over its fifteen background jobs, with no
  monitoring code of its own.
- **every fleet app**: a job registered through the platform appears on the
  console with no work per app, which is the ownership rule doing its job.
- **integriq**: its connectors' scheduled runs and their failures land in
  the same list as everything else.

## ADRs

- ADR-022: one operations console in the platform layer, consumed, not
  rebuilt per app.
- ADR-005: maintenance mode and the repair fail closed. A repair that
  cannot establish what it would change does not run.
- ADR-003: starting a run, entering maintenance mode and running a repair
  are audit facts on the chain.
- ADR-009: the run history is queried with an index, never by scanning the
  job table.

## Impact

- Extends: a new `operations-console` capability, beside
  `production-observability` which keeps the metrics and health endpoints.
- Affected code: the background job registration and its wrapper, a run
  history table and its mapper, the settings surface, the repair steps
  already in `lib/Repair/`.
- Backwards compatible: a job that is not registered through the wrapper
  keeps running and is simply absent from the list, which the console says
  out loud.
- Size: M.

## Out of scope

- Installing and updating the app itself (C-configuration-91) and finding
  an extension (C-configuration-48). The Nextcloud appstore owns both, and
  the console links to it.
- Profiling a slow screen (C-configuration-36). An engineer's tool, and
  `production-observability` already exposes the metrics it would read.
- The product's own documentation inside the product (C-configuration-97).
- Published configuration and scale limits (C-configuration-101,
  documented, jira-data-center). That is a page on the website, not a
  screen in the app.
- Administering from the command line (C-configuration-89). The `occ`
  commands exist; this change makes their runs visible.
