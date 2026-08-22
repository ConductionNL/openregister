# Tasks: flow-business-timers

## 1. Storage

- [ ] 1.1 Migration creating `openregister_flow_timers`,
      `openregister_flow_timer_fires` and `openregister_flow_timer_events`
      with the columns in design.md — Data model. Indexes
      `or_flowtimer_due_idx (state, fire_at)`,
      `or_flowtimer_rung_idx (state, next_rung_at)`,
      `or_flowtimer_subj_idx (subject_type, subject_uuid, state)`,
      `or_flowtimer_run_idx (run_uuid)`, unique `or_flowtimer_uuid_idx (uuid)`,
      unique `or_flowtimfire_uq (timer_uuid, rung_key)`,
      `or_flowtimev_timer_idx (timer_uuid, created)`. All names ≤ 30 chars,
      matching `or_flowtrig_match_idx`
      (`lib/Migration/Version1Date20260810140000.php:98`). Additive only.
      Verify NO `overdue`, `is_overdue` or `days_overdue` column exists.
- [ ] 1.2 `lib/Db/FlowTimer.php` + `FlowTimerMapper`, `FlowTimerFire.php` +
      `FlowTimerFireMapper`, `FlowTimerEvent.php` + `FlowTimerEventMapper`.
      Mapper finders: due-by-`fire_at`, due-by-`next_rung_at`, by subject,
      by run, by lineage. The fire and event mappers expose insert and read
      only — no update, no delete path.
- [ ] 1.3 Seed descriptor under `lib/Settings/` + a `lib/Repair/SeedFlowTimerRegister`
      step registered in `appinfo/info.xml` beside the existing `Seed*` steps
      (`:159-170`), decoding the JSON and calling
      `ConfigurationService::importFromApp(force: false)` — NOT
      `importFromFilePath()`, per `lib/Repair/ImportCredentialBrokerRegister.php:104-118`.
      Ships `working-calendar` `nl-national` + one organisation override and
      `escalation-ladder` `nl-termijn-default` (14/7/2/0, matching
      `../procest/lib/Service/DeadlineEscalationService.php:46-51` value for
      value). Re-running the step changes nothing.

## 2. Working calendar and SLA arithmetic

- [ ] 2.1 `lib/Service/Flow/Timer/WorkingCalendarService.php` — resolution
      order timer → organisation → seeded default, throwing at arm time on an
      unknown name with the name in the message, and NO weekday-only fallback
      on any path. Rule kinds `fixed`, `easter` (computed, as
      `../procest/lib/Service/Kcc/SlaCalculator.php:266` does) and
      `observedShift`. Validation REFUSES a calendar that is only enumerated
      dates — the failure mode live at
      `../shillinq/lib/Lifecycle/SubmissionWindowGuard.php:74-104`, which ends
      at `2027-12-26` and then degrades silently. `hoursPerWorkingDay`
      required.
- [ ] 2.2 `lib/Service/Flow/Timer/SlaCalculator.php` — `measure(from, to, unit)`,
      `add(from, value, unit)`, `sub(from, value, unit)` over a resolved
      calendar for `hours`, `businessDays` and `calendarDays`; `{value, unit}`
      accepted only for integer `value` 1..10000. Non-working dates memoised
      per (calendar, year) for the life of a sweep pass.

## 3. Timer lifecycle

- [ ] 3.1 `FlowTimerService::arm()` — resolves `anchor_event` +
      `anchor_offset` to `anchor_at`, stores the anchor alongside the computed
      moment, validates the SLA and escalation rules, and REFUSES an
      `on_expiry` outcome on any timer whose `legal_effect` is not
      `wettelijk`. One private `recompute()` derives `fire_at` and
      `next_rung_at`; every mutating operation calls it and nothing else
      writes those two columns.
- [ ] 3.2 `suspend(reason, until?)` / `resume()` — `consumed_value +=
      calendar.measure(running_since, now, budget_unit)`, `running_since` and
      `fire_at` to NULL, `suspended_since` set; resume re-projects from the
      resume instant. Both write an event row with actor, moment, reason and
      `basis`. A suspended timer neither fires nor escalates nor reports
      overdue. Do NOT copy procest's pre-extension model
      (`../procest/lib/Service/DeadlinePauseService.php:145-153`) — its
      `min($durationDays, $diff)` at `:148` silently eats an over-run pause.
- [ ] 3.3 `extend(amount, unit, rationale)` — increases `budget_value`,
      requires a non-empty rationale, bounded by `extension_max` (default 1)
      with an error naming the bound, and REFUSED once `state` is `fired`,
      `cancelled` or `superseded`. The override is a separate, separately
      authorized method recorded as an override, mirroring
      `../procest/lib/Service/DeadlineExtensionService.php:126,228` — not a
      flag on `extend()`.
- [ ] 3.4 `supersede()` on a moved anchor — the prior row goes to
      `superseded` and never fires; a successor carries `supersedes_uuid`,
      the recomputed `anchor_at`/`fire_at` and the predecessor's
      `consumed_value`, and inherits a fire row (marked `inherited`) for every
      rung still in the past under the new deadline and none for the rungs
      pushed back into the future.

## 4. Escalation

- [ ] 4.1 `lib/Service/Flow/Timer/EscalationLadderService.php` — resolves the
      ladder, computes each rung's instant, and CLAIMS a rung by inserting
      `(timer_uuid, rung_key)` BEFORE raising the transition; a duplicate key
      means another pass owns it. The fire row records the transition raised
      and its roles/priority, never "notified". A gap fires every passed
      unfired rung in ladder order, each once, and never collapses them into
      the most severe.
- [ ] 4.2 Escalation-rule validation — shape `{trigger, offset, offsetUnit,
      notifyRole, escalateToRole, openIncident}`, `offsetUnit` accepting
      `calendarDays` as well (`../procest/lib/Service/StepConfig/EscalationRuleValidator.php:53`
      is `['hours', 'businessDays']` today), refused without an SLA, and
      `preBreach` validated as
      `calendar.sub(fire_at, offset, offsetUnit) >= anchor_at` — instants, not
      the raw integer comparison at `EscalationRuleValidator.php:176-195`.
      Config-time validation is advisory against a probe anchor; arm-time is
      authoritative and names the anchor in its refusal.

## 5. Sweep and outcomes

- [ ] 5.1 `lib/Cron/FlowTimerWorker.php` extending `TimedJob` at
      `setInterval(seconds: 300)`, matching `lib/Cron/FlowScheduleWorker.php:59`.
      Two bounded range scans — `(state, fire_at)` for expiries and
      `(state, next_rung_at)` for rungs — each `LIMIT` batch (default 200),
      never a page of open rows filtered in PHP as
      `../openconnector/lib/Service/ApprovalService.php:638-658` does under a
      docblock claiming the opposite (`:628-631`). Logged counts report work
      performed; a pass hitting the limit logs `truncated: true`.
- [ ] 5.2 Expiry outcomes applied as NAMED TASK ACTIONS through the task
      service, claimed by a conditional `SET state='fired' WHERE uuid=? AND
      state='armed'` so zero affected rows means another pass owns it. All
      four of `skip`, `error`, `dead_letter`, `transition:<action>` produce
      DISTINCT observable subject states — `skip` continues the process,
      `error` fails it; the collapse at `ApprovalService.php:662` is the
      defect being corrected, not the behaviour being copied. A `wettelijk`
      breach is recorded permanently and survives completion.
- [ ] 5.3 Cancellation inside the transaction that makes the subject terminal,
      extending `flow-task-entity`'s run-terminality listener — idempotent,
      recording `cancel_reason` and `cancelled_at`, never deleting. Plus a
      repair check that COUNTS armed timers whose subject is terminal or
      absent and reports them as defects rather than quietly cancelling them.

## 6. Projection and derivation

- [ ] 6.1 `openregister_tasks.due_at` / `expires_at` maintained as a
      projection inside every timer mutation — earliest non-cancelled `due`
      timer and earliest enforcing timer — so the inbox index
      `(assignee, is_terminal, due_at)` stays an index hit. The task write
      surface REFUSES those fields once a timer owns the subject; writing them
      never creates a timer. `suspended_until` is display-only.
- [ ] 6.2 Derived read API — overdue, time-remaining and time-overdue computed
      on read as `state = 'armed' AND fire_at < now`, correct with the sweep
      disabled, with no field anywhere accepting an overdue write.
      `remaining = budget_value - consumed_value - (running_since ?
      calendar.measure(running_since, now, budget_unit) : 0)`, answerable
      while suspended.

## 7. Tests and verification

- [ ] 7.1 Arithmetic and calendar unit tests: the 8-week / 19-days-elapsed /
      6-of-14-day-hersteltermijn case returning the remainder intact; a
      business-day term suspended over a weekend resuming with the same
      business days left; a 3-businessDays SLA armed on a Thursday landing on
      Tuesday; `nl-national` correct for several future years including a
      Koningsdag falling on a Sunday; both cross-unit `preBreach` scenarios;
      one case per `on_expiry` value asserting the four states differ.
- [ ] 7.2 Sweep, concurrency and invariant tests: a due timer beyond the batch
      size still processed; two overlapping passes firing a rung and an expiry
      exactly once each; a downtime gap firing the skipped rungs in order; a
      six-week timer surviving a restart; completion cancelling both timers
      with no escalation raised; and an invariant test asserting
      `fire_at = calendar.add(running_since, budget - consumed)` and the task
      projection after EVERY operation in the state machine.
- [ ] 7.3 Regression pass with opencatalogi and softwarecatalog installed:
      flows still queue, advance and complete; `WaitNode` and
      `AwaitSignalNode` behave identically; the migration applied twice yields
      identical schema and seed state; and `openregister_flow_triggers` and
      its `or_flowtrig_match_idx` are untouched.

## Acceptance criteria

- Firing is decided from persisted state alone. A grep for `sleep`, a
  scheduled callback or an in-memory handle in the timer code returns
  nothing, and the restart test passes with the job disabled between arm and
  due.
- No column, enum value or writable field anywhere records overdue-ness, and
  the overdue query returns the correct set with the sweep job disabled.
- `skip`, `error`, `dead_letter` and `transition:<action>` leave the subject
  in four distinguishable states. A test asserts all four, one per value.
- A rung fires at most once per timer, decided by the unique index rather
  than by a read-then-write, and proven by a concurrency test rather than by
  reading the code.
- `businessDays` is never computed without a resolved named calendar. An
  unknown calendar name fails at arm time; no code path substitutes weekdays.
- `preBreach` validation gives the right verdict on both spec scenarios,
  which the raw-integer comparison it replaces gets wrong in both directions.
- An enforcing `on_expiry` exists only on a `wettelijk` timer, and a
  `servicenorm` timer configured with one is refused at arm time.
- The sweep reads only rows it acts on. Seeding beyond the batch limit with
  one due timer still processes that timer in the same pass.
- Every armed timer satisfies the `fire_at` identity, every suspended timer
  has NULL `fire_at` and NULL `running_since`, and no armed timer's subject
  is terminal.
- No app is pointed at the store by this change: procest, openconnector and
  shillinq keep their own deadline services, and no data is copied.

## Quality checklist

- `composer check:strict` passes (PHPCS, PHPMD, Psalm, PHPStan).
- New PHP files carry `@license EUPL-1.2` and `@copyright 2026 Conduction B.V.`
- `@spec` annotations point at
  `openspec/specs/flow-business-timers/spec.md` anchors.
- References ADR-098 D1 (one engine, one clock) and D2 (the subject is a
  row), ADR-031 (the imperative WHEN is argued in design.md D-1 against the
  measured limits of the scheduled-filter grammar, not assumed), ADR-001
  (the calendar and ladder are seeded data, not a `private const`), ADR-065
  (openconnector's enforcing semantics harvested before its runner retires).
- `WaitNode` and `AwaitSignalNode` are NOT modified, and no node type is
  added to the engine.
- This change sends nothing: no channel, no template, no recipient uid
  resolution, no notification dialect. Grep the new code for a notification
  emit and expect zero hits.
- `FlowTimerService` contains no branch on a specific app's subject type.
  Every branch is time arithmetic, calendar resolution, state or concurrency.
- Five defects found while writing this change are FILED AS ISSUES and not
  fixed here: the unbounded `ApprovalService::sweepExpired()` page
  (`:638-658`); `onTimeout: 'skip'` behaving as `'error'` (`:662`); the
  cross-unit `preBreach` comparison (`EscalationRuleValidator.php:176-195`);
  the `offsetUnit` enum missing `calendarDays` (`:53`); and shillinq's
  holiday table expiring at `2027-12-26`
  (`SubmissionWindowGuard.php:74-104`). Add the sixth found during design:
  shillinq's `ContractObligation.obligationDeadline` scheduled notification
  uses a `{all: [...]}` filter grammar with `notIn`/`before` operators that
  `ScheduledFilterEvaluator` does not implement, so it matches nothing and
  has never fired
  (`../shillinq/lib/Settings/register.d/contract-lifecycle-management.json:701-720`).
