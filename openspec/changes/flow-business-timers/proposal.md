---
kind: code
depends_on: [flow-task-entity]
---

# Proposal: flow-business-timers

## Summary

Give the fleet ONE clock. A durable timer store (`openregister_flow_timers`)
swept by a bounded cron pass, holding **elapsed-versus-suspended time** rather
than a bare target timestamp, so a deadline can be paused (Awb 4:15
opschorting), extended once (Awb 4:14 verdaging), escalated on a threshold
ladder that fires each rung exactly once, and enforced — auto-transitioning
the subject — where the deadline has legal effect. `due_at` advises,
`expires_at` enforces, `overdue` is derived and never stored.

This change decides **WHEN a deadline fires and WHO it escalates to**. It does
not send anything.

## Why

**The engine's only clock cannot survive being asked a business question.**
`WaitNode` resolves its `for`/`until` through `strtotime()`
(`lib/Service/Flow/Nodes/WaitNode.php:212-240`) and suspends the run
(`:184-196`). That is a wall-clock delay held in the run's own `resume_at`.
It cannot be cancelled when the thing it was waiting for happens early, it
cannot be paused, it knows nothing about weekends, and it pauses the RUN — so
"the applicant has eight weeks to respond" and "this branch does the next
thing in ten minutes" are the same mechanism.

**The heartbeat is a liveness net, and says so.** `AwaitSignalNode` suspends
with a `resumeAt` a few minutes out and re-asks whether its answer arrived
(`AwaitSignalNode.php:20-26`), defaulting to 15 minutes
(`:87`) and clamped to a 5-minute floor (`:98`) because the stock system cron
cannot wake anything faster. Its own comment gives the reason: a lost signal
should cost a heartbeat rather than the flow. It is insurance against
delivery failure. It is not an SLA, it has no notion of breach, and nothing
about it escalates.

**Only ONE app in the fleet has an enforcing deadline, and it is the one
about to retire.** openconnector's `approval_request.expiresAt` carries
`onTimeout: error|skip|dead_letter`
(`../openconnector/lib/Settings/register.d/hitl-approval-rule-action.json:90-95`)
and is swept by `ApprovalTimeoutSweepJob` at 300s
(`../openconnector/lib/Cron/ApprovalTimeoutSweepJob.php:53,71`) into
`ApprovalService::sweepExpired()`
(`../openconnector/lib/Service/ApprovalService.php:636-681`). Every other
fleet deadline merely notifies. ADR-065 retires that legacy runner, so the
enforcing semantics have to be harvested **now** or they are lost — and they
must be harvested with their two measured faults visible rather than copied:

- The sweep is **not bounded to expired rows**. It asks for 500 `pending`
  rows (`:638-647`) and filters `expiresAt` in PHP afterwards (`:655-658`).
  Past 500 pending requests, an expired one outside that page is never
  reached — while the method's own docblock promises "bounded to
  already-expired rows only ... no full-table scan" (`:628-631`). The
  instrument reports a clean sweep it did not perform.
- `onTimeout` declares three outcomes and implements two. `error` and `skip`
  both fall through to `status = 'expired'` (`:662`); only `dead_letter`
  branches (`:663-665`). A flow author choosing `skip` gets `error`'s
  behaviour and no warning.

**The fleet has FIVE business-day calculators and they disagree about what a
business day is.**

| implementation | weekends | NL holidays |
|---|---|---|
| `../procest/lib/Service/Kcc/SlaCalculator.php:78,94,108` | yes | yes, computed (Easter algorithm at `:266`) |
| `../procest/lib/Service/ComplaintService.php:433-462` | yes | yes, own fixed table at `:68` |
| `../procest/lib/Service/DsoCaseService.php:470-490` | yes | yes, own fixed table at `:60-79` |
| `../procest/lib/Service/WorkQueueService.php:533-563` | yes | **no** — `$dow < 6` and nothing else |
| `../shillinq/lib/Lifecycle/SubmissionWindowGuard.php:197-204` | yes | yes, literal `Y-m-d` list |

Two of these answer a different number for the same question. And shillinq's
holiday list is 27 literal date strings ending at `2027-12-26`
(`SubmissionWindowGuard.php:74-104`): from 2028 it silently degrades to
weekend-only, computing wrong deadlines while throwing nothing.

**The SLA contract already exists as prose in a JSON description, with an
unsound validator.** procest's `workflowTemplate.steps[].config` is specified
at `../procest/lib/Settings/procest_register.json:3126` as
`{sla: {value: 1-10000, unit: hours|businessDays|calendarDays},
escalationRule: {trigger: preBreach|slaBreached, offset, offsetUnit,
notifyRole, escalateToRole, openIncident}}` with the constraint "requires sla
present; preBreach offset must be <= sla.value". The units are validated
(`StepConfigValidator.php:68`, `StepConfig/EscalationRuleValidator.php:53,60`)
but the constraint is checked as a **raw integer comparison across
incompatible units** (`EscalationRuleValidator.php:176-195`: `$offset >
$sla['value']`). A 24-hour pre-breach warning on a 2-calendar-day SLA is
rejected as too long; a 5-business-day warning on a 48-hour SLA is accepted.
`offsetUnit` also omits `calendarDays`, which `sla.unit` allows — so a
calendar-day SLA has no calendar-day warning.

**The Dutch differentiator is the part no wall-clock timer can express.** A
legal term PAUSES and resumes where it left off: the Awb 4:15 opschorting of
an omgevingsvergunning beslistermijn during a hersteltermijn stops the clock,
and the days already run stay run. procest implements this
(`../procest/lib/Service/DeadlinePauseService.php:68` registerPauze, `:132`
resumeAfterPauze — which consumes elapsed pause days and re-extends by the
**unconsumed** remainder only) and the one-shot Awb 4:14 verlenging beside it
(`../procest/lib/Service/DeadlineExtensionService.php:83,115`, ceiling checked
at `:220-229`). A store that holds only a target timestamp cannot represent
"paused with 19 days left" — on resume there is nothing to add the remainder
to. That is why this change stores elapsed and suspended time, not a moment.

**And a threshold must fire once.** procest's ladder is
`DeadlineEscalationService::DEFAULT_MATRIX` (`:46-51`): 14 days →
`[handler]` low, 7 → `[handler, teamleader]` medium, 2 → `[+manager]` high,
0 → critical. It works because `notifyThreshold()` (`:117-149`) checks a
`notificatiesVerstuurd` ledger on the instance (`:123`) before sending. Take
the ledger away and a daily scan mails the manager every day for a fortnight.
The matrix itself is a `private const` with no configuration path, so every
other app that wants a ladder writes its own.

## What Changes

- **A durable timer store**, `OCA\OpenRegister\Db\FlowTimer` /
  `openregister_flow_timers`, keyed by subject (`subject_type` +
  `subject_uuid`, with `run_uuid`/`node_id` as optional provenance exactly as
  `flow-task-entity` treats them). A subject may carry SEVERAL timers — that
  is the point, see the lattice below. Timers survive restarts, fire weeks
  out, and are **CANCELLED when the subject completes first**; an in-process
  timer is not an option and is not offered.
- **`due_at` and `expires_at` are different columns with different
  consequences.** A `due` timer NOTIFIES and nothing else. An `expiry` timer
  ENFORCES: it applies an `on_expiry` outcome that transitions the subject.
  The outcome vocabulary is openconnector's, harvested and completed —
  `error | skip | dead_letter | transition:<action>` — with `skip` given the
  distinct behaviour its enum always promised and `:662` never gave it.
- **A bounded, index-driven sweep.** A new `FlowTimerWorker` on the existing
  worker cadence (`FlowScheduleWorker.php:59` and openconnector's sweep job
  both run at 300s; `FlowRunWorker.php:172` sets a 60s floor) selects on a
  `(state, fire_at)` index — a range scan over DUE timers, never a page of
  candidates filtered in PHP.
- **SLA `{value: 1..10000, unit: hours|businessDays|calendarDays}`** as a
  first-class timer input, with the procest contract's shape preserved so a
  `workflowTemplate` step config maps across without translation.
- **ONE working-calendar source**, `WorkingCalendarService`, resolving a
  named calendar per organisation with a computed (not tabulated) NL national
  default, and carrying `hoursPerWorkingDay` so `hours` and `businessDays`
  are commensurable. The five existing implementations are not touched by
  this change; they become migration targets for the per-app changes.
- **Suspend and extend as store operations**, not as recomputed timestamps:
  `suspend(reason, until?)` / `resume()` moving elapsed time into a suspended
  accumulator, and `extend()` bounded by `extension_max` (default 1) and
  refused **after expiry** — verdaging is a decision taken while the term
  still runs.
- **Escalation rules** as a typed array on the timer,
  `{trigger: preBreach|slaBreached, offset, offsetUnit, notifyRole,
  escalateToRole, openIncident}`, requiring `sla`, with the preBreach
  constraint enforced **after normalising both sides to seconds against the
  resolved calendar** — the comparison `EscalationRuleValidator.php:176-195`
  gets wrong. `offsetUnit` gains `calendarDays` to match `sla.unit`.
- **A dedup ledger as rows, not as a JSON array**: `openregister_flow_timer_fires`,
  unique on `(timer_uuid, rung_key)`. procest's `notificatiesVerstuurd`
  (`DeadlineEscalationService.php:123`) proves the mechanism is required;
  a unique index proves it under concurrent sweeps, which a read-modify-write
  of a JSON column does not.
- **The procest ladder as a SEEDED, editable preset**, not a `private const`:
  a `working-calendar` and an `escalation-ladder` schema with
  `nl-termijn-default` = 14/7/2/0 matching `DEFAULT_MATRIX:46-51` exactly, so
  the proven behaviour is the default and is still configurable.
- **The deadline LATTICE.** `legal_effect: none | servicenorm | wettelijk`.
  Escalate on the servicenorm, alarm on the wettelijke termijn, and know
  which expiry has legal effect: only a `wettelijk` timer may carry an
  enforcing `on_expiry`, and its breach is recorded permanently rather than
  cleared on completion.
- **Clock ANCHORS are stored, not just their resolved instant.**
  `anchor_event` + `anchor_offset`/`anchor_offset_unit`, because the bezwaar
  decision clock starts the day AFTER the objection window closes, not at
  receipt — and if the anchoring event moves, the timer must re-arm
  (superseding the old row) rather than keep a stale instant.
- **`overdue` is DERIVED, never stored.** No column, no state value. The
  sweep does not write it and the inbox computes it. Three fleet schemas
  store it today and are therefore only as correct as the last job that
  remembered to write it.

## What does NOT change

- **`WaitNode` and `AwaitSignalNode` stay exactly as they are.** A wall-clock
  in-run pause is a legitimate, different thing, and the heartbeat's liveness
  job is not an SLA's job. Neither node is reimplemented on the timer store.
- **Delivery.** `flow-messaging-nodes` sends and
  `flow-task-inbox-projections` projects. This change fires a named timer
  transition and names the recipient roles; it owns no channel, no template
  and no notification dialect. The seam is deliberate: an escalation firing
  is a TRANSITION, so `x-openregister-notifications`' existing
  `transition(action)` trigger carries the message declaratively (design.md
  records why the WHEN cannot be declarative and the WHO-gets-told can).
- **Migration.** procest's termijnbewaking services
  (`DeadlineEscalationService`, `DeadlinePauseService`,
  `DeadlineExtensionService`, `DeadlineDailyScanService`,
  `TermijnService`, `WOODeadlineService`), openconnector's
  `ApprovalService`/`ApprovalTimeoutSweepJob`, and shillinq's
  `ObligationTaskBridge` are NOT retired here. That is
  `flow-approval-consolidation` and the per-app changes. This change builds
  the target; nothing is pointed at it yet.
- **The five business-day calculators** keep working untouched. Introducing
  a sixth that is authoritative is the point; deleting the other five is not
  this change's risk to take.
- **`recurrence`.** See DEFERRED_QUESTIONS in design.md — provisionally the
  TASK's, not the timer's, on the measured ground that shillinq declares
  `recurrence: none|monthly|quarterly|annually` on the durable business
  object (`../shillinq/lib/Settings/register.d/contract-lifecycle-management.json:617-629`)
  and no PHP anywhere in shillinq reads it.

## Capabilities

### New Capabilities
- `flow-business-timers`: the durable timer store, its suspend/extend
  arithmetic, working-calendar resolution, SLA and escalation-ladder
  evaluation, the bounded sweep, and the advisory-versus-enforcing expiry
  distinction.

### Modified Capabilities
<!-- None. flow-engine is untouched: no node is added, no run semantics
     change, and neither WaitNode nor AwaitSignalNode is modified. The timer
     store is swept by its own job and acts on subjects through the task
     service, not through the engine's transition loop. -->

## Impact

- **Affected specs**: new `flow-business-timers`. `flow-engine` untouched.
- **Affected code**: new `lib/Db/FlowTimer.php` + `FlowTimerMapper.php`,
  `FlowTimerFire.php` + `FlowTimerFireMapper.php`; new
  `lib/Service/Flow/Timer/FlowTimerService.php`,
  `WorkingCalendarService.php`, `SlaCalculator.php`,
  `EscalationLadderService.php`; new `lib/Cron/FlowTimerWorker.php`; one
  migration; seed data for `working-calendar` (`nl-national`) and
  `escalation-ladder` (`nl-termijn-default`).
- **Affected apps**: none yet, by design. procest, openconnector, shillinq
  and decidesk become consumers in `flow-approval-consolidation`.
- **Depends on**: `flow-task-entity` — `due_at` and `expires_at` are stored
  on the task there and acted on here; a timer with no subject to cancel it
  when the work finishes is a mail flood, and the cancellation hook lives on
  the task lifecycle.
- **Defects found while writing this proposal** (to be filed as issues, NOT
  fixed inside this change): the unbounded `ApprovalService::sweepExpired()`
  page (`:638-658`), `onTimeout: 'skip'` behaving as `'error'` (`:662`), the
  cross-unit `preBreach` comparison
  (`EscalationRuleValidator.php:176-195`), the `offsetUnit` enum missing
  `calendarDays` (`:53`), and shillinq's holiday table expiring at
  `2027-12-26` (`SubmissionWindowGuard.php:74-104`).
- **ADRs**: ADR-098 D1 (one engine — one clock with it), D2 (the task is a
  native entity, so a timer's subject is a row, not an object); ADR-031
  (declarative-versus-imperative — design.md carries the required section);
  ADR-001 (seed data); ADR-065 (openconnector's legacy runner retires, so
  its enforcing semantics are harvested here first).
