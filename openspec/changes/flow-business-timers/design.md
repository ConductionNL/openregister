# Design: flow-business-timers

## Context

See proposal.md — Why. The design-relevant state of the code today:

- **There is no business clock in OpenRegister.** `grep -rli
  "workingcalendar\|working-calendar\|businessDays" lib/` returns nothing.
  Every fleet business-day calculator lives in a leaf app; this change adds
  the first one to the platform.
- **The engine's two clocks are both wall-clock and both in-run.**
  `WaitNode` resolves `for`/`until` through `strtotime()`
  (`lib/Service/Flow/Nodes/WaitNode.php:212-240`) and suspends the run
  (`:184-196`); `AwaitSignalNode` re-asks on a 15-minute default
  (`lib/Service/Flow/Nodes/AwaitSignalNode.php:87`) clamped to a 5-minute
  floor (`:98`). Neither is touched here.
- **The worker cadence is already 300s.** `FlowScheduleWorker` sets
  `setInterval(seconds: 300)` (`lib/Cron/FlowScheduleWorker.php:59`);
  `FlowRunWorker` sets a 60s FLOOR and says so in its own comment
  (`lib/Cron/FlowRunWorker.php:163-172`). A new sweep does not need a new
  cadence.
- **The declarative scheduled-notification path exists and works.**
  `lib/BackgroundJob/ScheduledNotificationJob.php` runs at 60s (`:105`),
  reads `trigger.type === 'scheduled'` (`:205`), enforces a 60s minimum
  interval (`:210`) and evaluates `trigger.filter` through
  `ScheduledFilterEvaluator::matches()` (`:392`). Its dedup fingerprint is
  built from `dedupeFields` or, failing that, from the filter's own field
  keys (`ScheduledNotificationJob.php:477-505`).
- **`flow-task-entity` already ships the columns this change interprets.**
  `openregister_tasks` carries `due_at`, `expires_at`, `sla_value`,
  `sla_unit`, `suspended_until` and `recurrence` as stored-but-uninterpreted
  fields, by its own D-4, with the inbox index `(assignee, is_terminal,
  due_at)`. Its cancellation listener on run terminality (D-8) is the hook
  this change extends.
- **Seeding OR's own schemas is an established repair-step pattern.**
  `lib/Repair/ImportCredentialBrokerRegister.php:104-118` decodes a
  descriptor from `lib/Settings/` and calls
  `ConfigurationService::importFromApp()` — explicitly NOT
  `importFromFilePath()`, which rejects an absolute path (`:104-106`).
  Repair steps are registered in `appinfo/info.xml:159-170`.
  `lib/Settings/register.d/README.md:9-25` records that OR does not
  deep-merge its own fragments; the repair step is the wiring that exists.

## Goals / Non-Goals

**Goals:**

- One store that can answer "how much of this term remains" at any moment,
  including while the term is suspended, without consulting a job log.
- Firing decided entirely from persisted state, so an instance that was down
  for a week fires the right things once when it comes back.
- Business-day arithmetic that is correct in 2035, resolved from a named
  calendar, and refused rather than downgraded when the name is unknown.
- A rung that fires once under two concurrent sweeps, decided by the
  database rather than by a read-then-write.
- Enough of openconnector's enforcing semantics captured, with its two
  measured faults corrected, that ADR-065 can retire its runner without
  losing the only enforcing deadline the fleet has.

**Non-Goals:**

- **Delivery.** No channel, no template, no recipient uid resolution, no
  notification dialect. A fire raises a named transition; the message is
  `flow-task-inbox-projections`' problem.
- **Being right about a specific app's deadline.** The NL national calendar
  and the 14/7/2/0 ladder are DEFAULTS, not law encoded in PHP. Nothing in
  this change knows what a bezwaartermijn is.
- **Replacing the five leaf calculators.** Introducing an authoritative
  sixth is the goal; deleting the other five is a per-app change.
- **Sub-minute precision.** The sweep is 300s; a timer that must fire
  within seconds is a `WaitNode`, not a business timer.
- **Recurrence.** Deferred to the task (see Open Questions).

## Decisions

### D-1 — Declarative-vs-imperative decision (ADR-031)

**The WHEN is imperative; the WHO-gets-told stays declarative. The
declarative boundary is not a preference here — it was measured, and the
measurement found a live rule that has never fired.**

ADR-031's default for a scheduled notification is
`x-openregister-notifications` with `trigger.type: scheduled` plus a
`filter`, swept by `ScheduledNotificationJob`. That path is real and it
works. Applying it to a deadline shows exactly where it stops.

**The canonical filter grammar.** `ScheduledFilterEvaluator` accepts a MAP
of `field => spec`, ANDed, where `spec` is a scalar (equality shortcut) or
`{operator, value}` with `operator` in `equals`, `notEquals`, `withinNext`,
`olderThan` (`ScheduledFilterEvaluator.php:43-48`). An unknown operator
fails closed with a debug log (`:164-168`). So "past its due date" IS
declarative — `{"dueDate": {"operator": "olderThan", "value": "PT0S"}}`
resolves through `:149-161` and needs no PHP.

**The example that was supposed to prove otherwise does not run.**
shillinq's `ContractObligation.x-openregister-notifications.obligationDeadline`
(`../shillinq/lib/Settings/register.d/contract-lifecycle-management.json:698-720`)
declares:

```json
{"all": [
  {"field": "status",  "operator": "notIn", "values": ["done", "waived"]},
  {"field": "dueDate", "operator": "before", "value": "now"}
]}
```

`matches()` iterates the filter as `field => spec` (`:89-95`), so the only
field it sees is `all`. `entryMatches()` finds no `operator` key on that
array and takes the scalar-equality shortcut (`:117-119`), comparing
`$objectData['all']` — absent, therefore `null` — against the array.
`null === [...]` is false for every object, so the rule matches nothing and
has never sent anything. `notIn` does exist in the codebase, in
`createdFilterMatches()` (`AnnotationNotificationDispatcher.php:1686-1699`),
reachable only from a `created` trigger. `before` exists nowhere.

shillinq's own repo already contains the diagnosis: the description at
`../shillinq/lib/Settings/register.d/shillinq-notifications.json:7` names
`{all:[{field,operator,…}]}` as "a non-canonical filter grammar with
operators (notIn, before) the canonical scheduled-filter grammar does not
know", and records rewriting the sibling ARInvoice rules. `obligationDeadline`
was not rewritten and is still live. This is the same class as decidesk's
`actionOverdue` filtering `taskStatus: 'overdue'` (ConductionNL/decidiq#845):
one filters a field nothing writes, the other uses a grammar nothing reads.
Both are enabled, both look configured, both fire nothing.

**Three measured limits, and they are why the WHEN is imperative:**

1. **The filter is a map keyed by field, so a field carries exactly one
   predicate.** `status notIn [done, waived]` is two exclusions on one
   field. `notEquals` takes one value, and a second `status` key cannot
   exist in a JSON object. The canonical rewrite shillinq performed on its
   siblings had to change the QUESTION — filter `lifecycleState equals
   overdue` — not the spelling.
2. **The sweep has no memory of having answered.** Dedup is a fingerprint
   over the watched fields (`ScheduledNotificationJob.php:477-505`), which
   suppresses a repeat of the same STATE. A ladder is per-rung state: "the
   14-day rung has fired, the 7-day rung has not" is a fact about the
   timer, and a schema annotation has nowhere to put it. procest needed a
   `notificatiesVerstuurd` array on the instance
   (`../procest/lib/Service/DeadlineEscalationService.php:123`) for exactly
   this reason.
3. **A filter cannot subtract suspended time.** `olderThan` compares a
   stored field to `now`. Under opschorting the answer depends on an
   accumulator (D-2), not on any stored date. Making the declarative form
   correct would mean materialising an "effective deadline" back onto the
   object on every suspend and resume — a stored clock-derived field, which
   is the defect this change and `flow-task-entity` both forbid.

Plus the categorical one: an enforcing expiry TRANSITIONS its subject. A
notification annotation sends and returns.

**So the imperative half is `FlowTimerService`, `WorkingCalendarService`,
`SlaCalculator`, `EscalationLadderService` and `FlowTimerWorker`** — calendar
arithmetic, concurrency control and sweep mechanics, which is the category
ADR-031 preserves for PHP, not a business rule a schema could have carried.

**The declarative half is everything downstream of a fire.** Every fire —
rung or expiry — raises a NAMED transition carrying the rung's roles and
priority. `x-openregister-notifications`' `transition(action)` trigger
addresses it, the same seam `flow-task-entity` D-1 uses for task actions.
This change registers no channel and resolves no uid. The ladder itself is
DATA (seeded objects, D-10), not a `private const` — which is the second
half of the ADR-031 test and the one procest's `DEFAULT_MATRIX`
(`DeadlineEscalationService.php:46-51`) fails.

**The fence:** `FlowTimerService` may not contain a business rule about what
a particular app's deadline MEANS. Every branch in it must be about time
arithmetic, calendar resolution, timer state or concurrency. A rung's
recipients, priority and message identity are data; a branch on
`if ($subjectType === 'bezwaar')` is a review failure.

**Defect to file, not fix here:** shillinq `obligationDeadline` uses a dead
filter grammar and has never fired (`contract-lifecycle-management.json:701-720`).

### D-2 — The store holds a budget and a suspension ledger, not a target instant

Three models were considered.

**A — a target timestamp, cancelled or overwritten.** openconnector's
`approval_request.expiresAt`. Rejected: there is no moment to add a
remainder to while a term is suspended, so "paused with 19 days left" is
unrepresentable. The spec makes this explicit.

**B — procest's optimistic pre-extension.** On pause, push `endDateCurrent`
forward by the full requested pause duration; on resume, pull back the
unused part (`../procest/lib/Service/DeadlinePauseService.php:145-153`).
It works for the happy path and has three measured faults:
`$consumed = max(0, min($durationDays, $diff))` (`:148`) caps consumption at
the REQUESTED duration, so a suspension that over-runs its window yields
`$unused = 0` and the applicant silently loses the extra days; between pause
and resume `endDateCurrent` states a deadline that is not true and every
reader believes it; and `Y-m-d` strings with `->days` cannot express an
hours SLA at all.

**C — a budget plus a suspension ledger (chosen).** The timer stores what
the term IS, and derives when it lands:

- `anchor_at` — the resolved instant the term runs from (D-4);
- `budget_value` + `budget_unit` — the SLA, in its own unit;
- `consumed_value` — DECIMAL, in `budget_unit`, the sum of all COMPLETED
  running segments;
- `running_since` — the instant the current running segment began; NULL
  while suspended;
- `fire_at` — the projected fire instant; NULL while suspended.

The arithmetic is two operations:

```
suspend(): consumed_value += calendar.measure(running_since, now, budget_unit)
           running_since = NULL;  fire_at = NULL;  suspended_since = now
resume():  running_since = now
           fire_at = calendar.add(now, budget_value - consumed_value, budget_unit)
```

Remaining, answerable at any moment including while suspended, is
`budget_value - consumed_value - (running_since ? calendar.measure(running_since,
now, budget_unit) : 0)`.

The spec's weekend requirement falls out rather than being special-cased:
`calendar.measure()` in `businessDays` counts business days, so a suspension
spanning a weekend adds nothing to `consumed_value`, and `resume()`
re-projects forward across the calendar from the resume instant.

**`fire_at` is a materialised derivation, and that is not the `overdue`
defect.** It is a pure function of `anchor_at`, `budget`, `consumed_value`,
`running_since` and the calendar — all of which change only on writes this
service controls. `overdue` is a function of the CLOCK, which changes
constantly and between writes. This is the same distinction
`flow-task-entity` D-1 draws for `is_terminal`. `fire_at` exists because the
sweep must be an index range scan (D-8); a repair check asserts the identity
holds for every armed timer, and the recomputation lives in one private
method that every mutating operation calls.

`suspended_since` and `suspended_total_seconds` are evidence and reporting.
They are deliberately NOT inputs to the arithmetic — `consumed_value` already
excludes suspended time by construction, and giving suspension two
representations is how they drift.

### D-3 — Four expiry outcomes, all expressed as task actions

`purpose` is `due` or `expiry`. A `due` timer raises escalations and never
touches subject state. An `expiry` timer applies `on_expiry`, permitted only
when `legal_effect = 'wettelijk'` (D-5) and refused at arm time otherwise.

openconnector declares `error | skip | dead_letter`
(`../openconnector/lib/Settings/register.d/hitl-approval-rule-action.json:90-95`)
and implements two: `error` and `skip` both fall through to
`status = 'expired'` (`../openconnector/lib/Service/ApprovalService.php:662`)
and only `dead_letter` branches (`:663-665`). The vocabulary is harvested
and completed:

| `on_expiry` | subject | process |
|---|---|---|
| `skip` | terminal, outcome `skipped` | CONTINUES past the step |
| `error` | terminal, outcome `failed` | run fails |
| `dead_letter` | terminal, outcome `dead_letter` | parked for an operator |
| `transition:<action>` | whatever that action's target state is | per the action |

All four are applied as NAMED TASK ACTIONS through the task service, not as
direct writes from the timer. `skip`, `error` and `dead_letter` are three
RESERVED action names with fixed target states; `transition:<action>` names
any action in the subject's own action set. One code path, one authorization
check, one audit trail — and the audit records that the actor was the timer,
which is the question "who closed my task" needs answered. Writing subject
state from the sweep was rejected for the same reason
`flow-task-entity` D-1 rejected object-API writes to a task: it would put a
second, unauthorised mutation path on the lifecycle.

### D-4 — The anchor is stored, and a moved anchor supersedes rather than mutates

`anchor_event` (a named event), `anchor_offset` + `anchor_offset_unit`, and
the resolved `anchor_at`. Storing only `fire_at` loses the fact that the
15th was derived from a window that can itself move.

When the anchoring event moves: the current row goes to `superseded`, and a
successor row is inserted carrying `supersedes_uuid`, the recomputed
`anchor_at` and `fire_at`, and the predecessor's `consumed_value`. Mutating
in place was rejected — the history has to show what the deadline used to be
and why it changed, and a mutated row cannot.

**Fired rungs across a supersession.** The spec requires that rungs already
fired are not re-fired unless the new deadline puts them back in the future.
Two shapes: key the fire ledger on a lineage id, or copy rows forward.
Copy-forward chosen — the successor inherits a fire row (marked `inherited`)
for every rung whose distance is still in the past under the NEW deadline,
and inherits nothing for rungs the new deadline pushed back into the future.
The lineage key was rejected because the uniqueness constraint would then
span rows whose deadlines differ, and "put it back in the future" would need
a DELETE against the evidence. Copy-forward keeps the unique index trivially
per-timer and leaves the predecessor's rows intact.

### D-5 — One calendar: named, computed, refused rather than downgraded

`WorkingCalendarService` resolves in a fixed order — the calendar named on
the timer, else the calendar configured for the subject's organisation, else
the seeded national default — and throws at ARM time when a named calendar
does not exist. There is no weekday-only fallback anywhere in the resolution
path, because that fallback is precisely the failure mode already live in
the fleet: `../procest/lib/Service/WorkQueueService.php:533-563` is
`$dow < 6` and nothing else, and it disagrees with the three procest
calculators beside it.

**Non-working dates are computed, not tabulated.** A calendar declares
rules, and a rule is one of: a fixed month/day; an Easter offset (Easter
computed, as `../procest/lib/Service/Kcc/SlaCalculator.php:266` already
does — Goede Vrijdag −2, Paasmaandag +1, Hemelvaart +39, Pinkstermaandag
+50); or a fixed date with an observed-shift rule. The last one is not
theoretical: Koningsdag is 27 April, observed on the 26th when the 27th is a
Sunday. A tabulated list gets that wrong the first time someone extends it
by hand.

A calendar consisting only of enumerated dates is REFUSED at validation,
because such a calendar has an expiry date. The live instance of that is
`../shillinq/lib/Lifecycle/SubmissionWindowGuard.php:74-104` — 27 literal
`Y-m-d` strings ending at `2027-12-26`, after which it silently degrades to
weekends-only and computes wrong deadlines while throwing nothing. Explicit
exception dates remain allowed ALONGSIDE rules, for a genuine one-off
closure; they cannot be the whole calendar.

`hoursPerWorkingDay` is required on every calendar. It is not needed for the
D-6 comparison (which is done on instants) but it is needed for `extend()`
when the extension is expressed in a unit other than `budget_unit`, and for
reporting remaining time in one unit in an inbox that mixes hours-SLA and
business-day-SLA work.

The five leaf calculators are untouched (proposal — What does NOT change).

### D-6 — preBreach is compared on the timeline, not between two integers

`EscalationRuleValidator.php:176-195` compares `$offset > $sla['value']` —
two raw integers whose units may differ. It therefore rejects a 24-hour
warning on a 2-calendar-day SLA and accepts a 5-business-day warning on a
48-hour SLA. Both errors are in the spec as scenarios.

Rather than converting both to seconds — which requires a fudge factor for
`businessDays` and is wrong near a holiday cluster — both sides are RESOLVED
against the calendar onto the timer's own timeline and the INSTANTS are
compared:

```
valid  ⇔  calendar.sub(fire_at, offset, offsetUnit)  >=  anchor_at
```

This is exact, needs no unit conversion, and gives the spec's two scenarios
the right verdicts by construction. `offsetUnit` gains `calendarDays` so it
matches the unit set `sla.unit` allows (`EscalationRuleValidator.php:53`
omits it today, so a calendar-day SLA has no calendar-day warning).

**Config-time versus arm-time.** A `workflowTemplate` step config has no
anchor yet, so config-time validation resolves against a probe anchor and is
ADVISORY. Arm-time validation is authoritative and is the one that refuses,
with the anchor named in the error — because a rule can be valid on one
anchor and invalid on another when a holiday cluster or a DST boundary falls
inside the offset. Saying so in the error is better than pretending the
config-time verdict was final.

An escalation rule without an SLA is refused at both points.

### D-7 — The rung ledger is rows, and the rung is CLAIMED before the transition

`openregister_flow_timer_fires`, UNIQUE on `(timer_uuid, rung_key)`.
procest proves the ledger is required — remove `notificatiesVerstuurd`
(`DeadlineEscalationService.php:123`) and a daily scan mails the manager
every day for a fortnight — and also proves why a JSON array is not enough:
`:123` reads the array and `:145-149` writes it back, so two overlapping
sweeps both read "unfired" and both send. A unique index moves the decision
into the database, where a second INSERT loses.

**Ordering: claim first, then raise the transition.** The insert IS the
claim; a duplicate-key means another pass owns the rung and this one does
nothing. That makes a rung AT-MOST-once. The alternative — dispatch, then
record — is what procest does (`:145` logs, `:147-152` marks sent) and is
at-least-once across a crash between the two. At-most-once is right here
because delivery downstream has its own retry and its own idempotency claim
(`AnnotationNotificationDispatcher.php:1097`), so a lost transition is
recoverable, whereas a duplicated escalation to a manager is not recoverable
at all — it is the mail flood the ledger exists to prevent.

The fire row records the TRANSITION RAISED, not "notified", because this
change does not know whether anything was delivered. procest's row says
notified and the only thing that happened was
`$this->logger->info('Procest termijn escalation dispatched', $payload)`
(`:145`) — a ledger asserting a delivery that never occurred.

**A downtime gap fires the rungs it passed, in ladder order.** The sweep
selects every rung of the timer's ladder whose distance is now in the past
and which has no fire row, and processes them ascending by severity. It does
not collapse them into the most severe: the 14-day and the 7-day rung have
different recipients, and the handler who was never told at 14 days is the
person the ladder exists to reach.

### D-8 — Two bounded range scans on the existing 300s cadence

`FlowTimerWorker extends TimedJob` with `setInterval(seconds: 300)`, matching
`FlowScheduleWorker.php:59` rather than `FlowRunWorker`'s 60s floor
(`FlowRunWorker.php:172`) — a business timer's resolution is days, and 300s
is already finer than anything it measures.

Expiry selection is `WHERE state = 'armed' AND fire_at <= :now ORDER BY
fire_at LIMIT :batch`, served by an index on `(state, fire_at)`. Every row
read is a row acted on.

The shape being avoided is measured. `ApprovalService::sweepExpired()` asks
for 500 `pending` rows (`:638-647`) and filters `expiresAt` in PHP afterwards
(`:655-658`), while its own docblock promises "bounded to already-expired
rows only … no full-table scan" (`:628-631`). Past 500 pending requests an
expired one outside the page is never reached, and the job reports a clean
pass.

**Escalation needs a second selector, and a naive one re-introduces the
scan.** A rung is due before the timer is, so an escalation query bounded on
`fire_at` would have to reach forward by the ladder's longest rung and then
discard. Instead `next_rung_at` — the instant of the next UNFIRED rung — is
materialised on the timer and indexed on `(state, next_rung_at)`. The sweep
does two bounded range scans. `next_rung_at` is recomputed whenever a rung
fires, the deadline moves, or the timer suspends or resumes; like `fire_at`
it is a derivation of stored inputs, maintained in the same private method.

**Re-entrancy.** Each timer is processed in its own transaction. The rung
claim is the unique insert. The terminal claim is a conditional update —
`SET state = 'fired' WHERE uuid = ? AND state = 'armed'` — and zero affected
rows means another pass owns it, so the outcome is not applied twice. No
lock is held across the outcome, so a slow action cannot stall the pass.

Counts logged are work performed. A pass that hits the batch limit logs
`truncated: true`, so a backlog is visible instead of looking like a clean
sweep.

### D-9 — Cancellation rides the subject's terminality, in the same transaction

`flow-task-entity` D-8 already terminates tasks from a listener on run
terminality and from an explicit service call. This change extends the same
path: when a subject becomes terminal, every non-fired timer bound to it is
cancelled with a reason, inside the transaction that made it terminal. A
separate listener firing afterwards was rejected — that is the window in
which an escalation goes out about work that is already done.

Cancelled means `state = 'cancelled'` plus `cancel_reason` and
`cancelled_at`. Never deleted: "why did I stop getting reminders about this"
has to be answerable, and a `wettelijk` breach already recorded survives the
cancellation and the subject's completion (D-5, spec).

Termination is idempotent, because run terminality can be observed more than
once — `flow-task-entity` D-8 names the stale-run reaper race for the same
reason.

An armed timer whose subject is terminal or absent is an ORPHAN. A repair
check counts them and reports; it does not quietly cancel them, because a
non-zero count is a defect in whoever completed the subject.

### D-10 — The task's `due_at`/`expires_at` become a projection of the timers

`flow-task-entity` ships `due_at` and `expires_at` on `openregister_tasks`
with the inbox index `(assignee, is_terminal, due_at)`. The spec here says
the subject's dates are a PROJECTION of the timers that bear on it. Both
hold: the timer is authoritative, and the task columns are a denormalised
copy maintained by `FlowTimerService` inside the arm/suspend/resume/extend/
supersede/cancel transaction.

The alternative — the inbox joins the timer table per row — was rejected
because it undoes the one index the inbox badge depends on, and that badge
renders on every page.

The direction is one-way and enforced: writing `due_at` directly on a task
does not create a timer, and the task write surface refuses those fields
once a timer owns the subject. Otherwise there are two writers and the
projection silently diverges from the arithmetic. `suspended_until` on the
task is display-only; the arithmetic never reads it. `recurrence` stays
uninterpreted (Open Questions).

Where a subject carries several timers (the lattice — `none`, `servicenorm`,
`wettelijk` at three different moments), the projection is the EARLIEST
non-cancelled `due` timer into `due_at` and the earliest enforcing timer
into `expires_at`. The projection is lossy by design; the timer table is
where the lattice is read in full.

## Data model

`openregister_flow_timers` — one row per timer, nullable unless stated:

| Group | Columns |
|---|---|
| Identity | `id` (PK), `uuid` (NOT NULL, unique), `title`, `metadata` (JSON) |
| Subject | `subject_type` (NOT NULL, `task\|object\|run`), `subject_uuid` (NOT NULL), `organisation` |
| Provenance | `run_uuid`, `node_id`, `app_id` |
| Purpose | `purpose` (NOT NULL, `due\|expiry`), `legal_effect` (NOT NULL, `none\|servicenorm\|wettelijk`), `on_expiry` |
| Anchor | `anchor_event`, `anchor_offset`, `anchor_offset_unit`, `anchor_at` (NOT NULL) |
| Budget | `budget_value` (NOT NULL, decimal), `budget_unit` (NOT NULL, `hours\|businessDays\|calendarDays`), `consumed_value` (NOT NULL, decimal, default 0), `running_since` |
| Derived | `fire_at`, `next_rung_at` |
| Calendar | `calendar_slug` |
| Ladder | `ladder_slug`, `escalation_rules` (JSON) |
| Suspension | `suspended_since`, `suspend_reason`, `suspended_total_seconds` (NOT NULL, default 0) |
| Extension | `extension_count` (NOT NULL, default 0), `extension_max` (NOT NULL, default 1) |
| Lifecycle | `state` (NOT NULL, `armed\|suspended\|fired\|cancelled\|superseded`), `supersedes_uuid`, `fired_at`, `breached` (NOT NULL bool, default false), `cancelled_at`, `cancel_reason` |
| Stamps | `created` (NOT NULL), `updated`, `created_by` |

No `overdue`. No `days_overdue`. No `is_overdue`. Overdue is
`fire_at < now AND state = 'armed'`, computed on read, and a suspended timer
cannot satisfy it because `fire_at` is NULL while suspended — the spec's
"a suspended term is not overdue" scenario falls out of the column shape
rather than needing a guard.

Indexes: `or_flowtimer_due_idx` on `(state, fire_at)`;
`or_flowtimer_rung_idx` on `(state, next_rung_at)`;
`or_flowtimer_subj_idx` on `(subject_type, subject_uuid, state)`;
`or_flowtimer_run_idx` on `(run_uuid)`; unique `or_flowtimer_uuid_idx` on
`(uuid)`. All within the 30-character index-name limit, matching
`or_flowtrig_match_idx` (`lib/Migration/Version1Date20260810140000.php:98`).

`openregister_flow_timer_fires` — the dedup ledger: `id`, `timer_uuid`
(NOT NULL), `rung_key` (NOT NULL — the rung's stable identity, e.g.
`preBreach:14:calendarDays`), `fired_at` (NOT NULL), `transition_action`,
`recipient_roles` (JSON), `priority`, `inherited` (bool, D-4), `created`.
UNIQUE `or_flowtimfire_uq` on `(timer_uuid, rung_key)` — the constraint the
whole at-most-once argument rests on.

`openregister_flow_timer_events` — append-only evidence: `id`, `timer_uuid`
(NOT NULL), `type` (`armed\|suspended\|resumed\|extended\|superseded\|fired\|breached\|cancelled`),
`actor`, `reason`, `prior_fire_at`, `new_fire_at`, `days_impact`, `basis`
(the legal ground, e.g. `Awb 4:15`), `created` (NOT NULL). Index
`or_flowtimev_timer_idx` on `(timer_uuid, created)`. No UPDATE and no DELETE
path exists, and the timer's cancellation does not cascade to it — the
suspension of a legal term is a decision that has to stay evidenced.

## Seed Data (ADR-001)

Two schemas and their default objects ship in a register descriptor under
`lib/Settings/`, imported by a repair step following
`lib/Repair/ImportCredentialBrokerRegister.php:104-118` — decode the JSON
and call `ConfigurationService::importFromApp()`, NOT `importFromFilePath()`
(`:104-106` records why), registered in `appinfo/info.xml` beside the
existing `Seed*` steps (`:159-170`). All UUIDs are nil placeholders; all
identifiers are obviously fake.

**1. `working-calendar` — `nl-national`.** Rules, not a table, so it does
not expire (D-5):

```json
{
  "uuid": "00000000-0000-0000-0000-000000000101",
  "slug": "nl-national",
  "title": "Nederland — nationale feestdagen",
  "workingWeekdays": [1, 2, 3, 4, 5],
  "hoursPerWorkingDay": 8,
  "rules": [
    {"kind": "fixed",  "month": 1,  "day": 1,  "name": "Nieuwjaarsdag"},
    {"kind": "easter", "offset": -2,           "name": "Goede Vrijdag"},
    {"kind": "easter", "offset": 1,            "name": "Tweede Paasdag"},
    {"kind": "fixed",  "month": 4,  "day": 27, "name": "Koningsdag",
     "observedShift": {"whenWeekday": "sunday", "days": -1}},
    {"kind": "easter", "offset": 39,           "name": "Hemelvaartsdag"},
    {"kind": "easter", "offset": 50,           "name": "Tweede Pinksterdag"},
    {"kind": "fixed",  "month": 12, "day": 25, "name": "Eerste Kerstdag"},
    {"kind": "fixed",  "month": 12, "day": 26, "name": "Tweede Kerstdag"}
  ],
  "exceptions": []
}
```

**2. `working-calendar` — `example-organisation`.** A per-organisation
override proving the resolution order is exercised by the seed rather than
only by a test: `nl-national`'s rules plus one enumerated `exceptions` entry
for a local closure day, and `hoursPerWorkingDay: 7`.

**3. `escalation-ladder` — `nl-termijn-default`.** 14/7/2/0, matching
`DeadlineEscalationService::DEFAULT_MATRIX:46-51` value for value, so the
behaviour that is already proven in production is the default — and is data
an administrator can edit rather than a `private const`:

```json
{
  "uuid": "00000000-0000-0000-0000-000000000102",
  "slug": "nl-termijn-default",
  "rungs": [
    {"key": "preBreach:14:calendarDays", "offset": 14, "offsetUnit": "calendarDays",
     "notifyRole": ["handler"], "priority": "low", "message": "termijn-14d"},
    {"key": "preBreach:7:calendarDays",  "offset": 7,  "offsetUnit": "calendarDays",
     "notifyRole": ["handler", "teamleader"], "priority": "medium", "message": "termijn-7d"},
    {"key": "preBreach:2:calendarDays",  "offset": 2,  "offsetUnit": "calendarDays",
     "notifyRole": ["handler", "teamleader", "manager"], "priority": "high", "message": "termijn-2d"},
    {"key": "slaBreached:0",             "offset": 0,  "offsetUnit": "calendarDays",
     "notifyRole": ["handler", "teamleader", "manager"], "priority": "critical",
     "message": "termijn-overschreden", "openIncident": true}
  ]
}
```

The `message` values are message IDENTITIES, resolved by the notification
subsystem. Nothing in this change renders them.

## Migration Plan

1. **Schema.** One migration creating `openregister_flow_timers`,
   `openregister_flow_timer_fires` and `openregister_flow_timer_events` with
   the indexes above. Additive only — no existing table is altered, so
   nothing that runs today changes behaviour.
2. **No backfill.** Nothing points at the store yet, by design (proposal —
   Affected apps: none). procest, openconnector and shillinq keep their own
   deadline services running untouched; they migrate in
   `flow-approval-consolidation`. Copying their live termijn instances here
   would create two authorities for the same deadline during the window in
   which both run.
3. **Seeds.** The repair step imports the descriptor idempotently
   (`force: false`, as `ImportCredentialBrokerRegister.php:113-118`), so a
   re-run does not duplicate the calendar or overwrite an administrator's
   edit to the ladder.
4. **Rollback.** Drop the three tables. Because no other table gained a
   column and no other code reads them, reverting the app code restores
   exactly today's behaviour. The seeded objects are left in place on
   rollback — deleting configuration on a downgrade is how a rollback
   becomes a data loss.
5. **Verification after deploy.** Every armed timer satisfies
   `fire_at = calendar.add(running_since, budget - consumed)` within one
   second; no armed timer has a NULL `fire_at`; no suspended timer has a
   non-NULL `fire_at` or `running_since`; no armed timer's subject is
   terminal; `nl-national` resolves a correct 2035 Easter-derived date.

## Risks / Trade-offs

- **`fire_at` and `next_rung_at` are materialised derivations and can
  drift** if a future code path writes a budget field without recomputing
  them. → One private recomputation method, called by every mutating
  operation; the repair check in Migration Plan step 5 asserts the identity;
  a test asserts it after every operation in the state machine.
- **The task's `due_at`/`expires_at` projection can diverge from the
  timers.** → Same mitigation shape `flow-task-entity` uses for
  `is_terminal` and its candidate index: one write path maintains both
  inside the transaction, the task write surface refuses the fields once a
  timer owns the subject, and a test asserts agreement after each operation.
- **At-most-once (D-7) means a rung can be lost** if the process dies
  between the claim insert and the transition being raised. → Accepted and
  argued: downstream delivery has its own retry, a lost escalation is
  visible in the timer's event log, and the alternative is a duplicated
  manager escalation that cannot be un-sent. The event log makes a
  claimed-but-unraised rung findable.
- **`businessDays` arithmetic is O(days) in the naive implementation**, and
  an 8-week term over a 300s sweep with thousands of timers would walk the
  calendar repeatedly. → `calendar.measure`/`calendar.add` memoise
  non-working dates per (calendar, year) for the life of the pass, which is
  the only place the cost concentrates.
- **A wrong seeded calendar is wrong everywhere at once** — that is the
  cost of centralising what five apps did five ways. → Mitigated by the
  computed-rules requirement (no expiry date), by unit tests asserting
  several future years including a Koningsdag-on-Sunday, and by the
  organisation-level override existing from day one so a disagreement does
  not require a platform change.
- **A `wettelijk` timer can enforce, and enforcement closes someone's
  work.** → Fenced three ways: only `wettelijk` may carry `on_expiry`
  (D-5), the outcome is applied as a named task action through the normal
  authorization and audit path (D-3), and the breach record is permanent so
  the transition is never silent.
- **Introducing a sixth business-day calculator while five remain** leaves
  the fleet temporarily MORE inconsistent. → Deliberate (proposal). The
  sixth is the one with a spec, a calendar and tests; the per-app changes
  delete the others, and this change's value is not realised until they do.
- **`extension_max` defaults to 1 and an override path exists.** → The
  override is a distinct, separately authorized operation recorded as an
  override, mirroring procest's supervisor mode
  (`../procest/lib/Service/DeadlineExtensionService.php:126,228`). A single
  `extend()` that takes a "force" flag was rejected: the flag becomes the
  default caller within a release.

## Open Questions

- **Whether `recurrence` belongs on the timer or on the task.**
  Provisionally the task's — shillinq declares
  `recurrence: none|monthly|quarterly|annually` on the durable business
  object (`../shillinq/lib/Settings/register.d/contract-lifecycle-management.json:617-629`)
  and no PHP in shillinq reads it, so there is no observed behaviour to
  copy. Deferrable: a recurring obligation spawns a new subject, and a new
  subject arms a new timer, so nothing in this store changes either way.
- **Whether a calendar should carry working HOURS-of-day** as well as
  working days, so an `hours` SLA armed at 16:00 lands at 09:00 rather than
  overnight. Provisionally no — `hoursPerWorkingDay` covers the commensurable
  arithmetic D-5 needs, and a time-of-day window is additive to the calendar
  object without touching the timer table or the sweep.
- **Whether the ladder should be resolvable per subject TYPE** rather than
  per timer and organisation. Provisionally no: `ladder_slug` on the timer
  plus the organisation default covers the observed cases, and a type-level
  default is a resolution-order change, not a schema change.
