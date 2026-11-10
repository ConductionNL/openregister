---
status: done
---

# DSAR Deadline Escalation

## Purpose

The temporal re-evaluation sweep that makes the DSAR register's already-declared deadline reminder/escalation/breach notifications dispatch without an object write (dsar-escalation-and-dpia), plus the write-once `breachedAt` breach stamp and privacy-officer breach visibility. A `now`-dependent materialised calculation (the `escalationTier`) goes stale on untouched cases; the hourly `TemporalCalculationSweepJob` re-materialises it through the normal write path so the declared `calculatedChange` rules fire boundary-guarded. Generic clockwork any future `now`-dependent schema inherits.

**OpenSpec changes**: [dsar-escalation-and-dpia](../../changes/archive/2026-07-06-dsar-escalation-and-dpia/) _(archived 2026-07-06)_

## Requirements

### Requirement: Time-dependent calculated fields re-evaluate without object writes
OpenRegister SHALL provide a scheduled temporal re-evaluation sweep that periodically
re-materialises calculated fields whose expressions reference the evaluation clock (`now`) —
starting with the `dataSubjectRequest.escalationTier` field — for objects in non-terminal
lifecycle states, writing any changed value through the normal object write path. Because the
write path emits the standard object-updated events, the schema's declared
`x-openregister-notifications` rules with `trigger.type=calculatedChange` MUST consequently fire
on tier boundary crossings exactly as if a user had edited the object. The sweep MUST NOT rewrite
objects whose recomputed value is unchanged, and MUST skip schemas with no `now`-dependent
materialised calculations.

#### Scenario: Untouched case crosses the reminder tier
- **WHEN** an open DSAR case's `dueAt` moves inside the active policy pack's reminder window while nobody edits the case
- **THEN** the next sweep run re-materialises `escalationTier` to `reminder` through the write path
- **AND** the declared `deadlineAdvanceReminder` notification is dispatched to the case handler

#### Scenario: Tier crossing notifies exactly once

@e2e exclude unchanged-value skip + boundary dedup are not UI-observable — covered by PHPUnit TemporalCalculationSweepServiceTest (unchanged recompute produces no write) + the dispatcher's previously.ne guard

- **WHEN** consecutive sweep runs recompute a case whose tier remains `reminder`
- **THEN** no write occurs for the unchanged value and no duplicate reminder is dispatched (the `calculatedChange` previously/eq boundary guard fires only on the crossing)

#### Scenario: Terminal cases are left alone

@e2e exclude sweep terminal-state skip — covered by PHPUnit TemporalCalculationSweepServiceTest::testSweepRewritesOnlyChangedNonTerminalObjects

- **WHEN** a case is in a terminal lifecycle state (fulfilled, refused, closed)
- **THEN** the sweep does not recompute or rewrite it

#### Scenario: No resolvable policy pack stays fail-safe

@e2e exclude fail-safe recompute is not UI-observable — covered by PHPUnit TemporalCalculationSweepServiceTest against the real escalationTier expression (null pack ref → on-track)

- **WHEN** no `dsarPolicyPack` resolves for a case's jurisdiction
- **THEN** the recomputed tier remains on-track (existing fail-safe convention) and no escalation notification is produced

### Requirement: Deadline breach is stamped on the case and visible to the privacy officer
When the sweep (or any write) moves a case's `escalationTier` to `breached`, OpenRegister SHALL
stamp the crossing on the case (`breachedAt` timestamp, written once) and the declared
`deadlineBreach` notification SHALL reach, in addition to the case handler, a privacy-officer
recipient resolved from the active `dsarPolicyPack`. The stamp and the recipients MUST be
declared on the register (ADR-031), not hard-coded in the job.

#### Scenario: Breach notifies handler and privacy officer
- **WHEN** an open case passes its (possibly extended) deadline and the sweep re-materialises its tier to `breached`
- **THEN** the `deadlineBreach` notification is dispatched to the case handler AND to the policy pack's privacy-officer recipient
- **AND** `breachedAt` is set on the case through the same audited write

#### Scenario: Breach stamp is written once

@e2e exclude write-once stamp semantics — covered by PHPUnit TemporalCalculationSweepServiceTest::testRecomputeMatrixIncludingBreachStamp (already-breached case keeps its original stamp)

- **WHEN** later sweeps recompute an already-breached case
- **THEN** `breachedAt` keeps its original value and no further breach notification is dispatched

@e2e A privacy officer seeds an open DSAR case whose deadline lies inside the reminder window, triggers the temporal sweep, and sees the reminder notification arrive for the handler; moving the clock past the deadline and re-running the sweep shows the breach notification for both handler and privacy officer and the case's breached timestamp in its detail view.
