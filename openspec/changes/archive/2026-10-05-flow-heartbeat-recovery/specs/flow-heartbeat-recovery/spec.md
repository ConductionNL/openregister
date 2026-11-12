## Purpose

A run suspended on a task must recover a missed completion signal on its
next heartbeat wake: the awaited task is re-read, a terminal outcome is
applied exactly as the signal path would have applied it, and the recovery
is recorded. A missed wake costs latency, never the run.

## ADDED Requirements

### Requirement: A live run keeps every parked node's resume slot

The engine SHALL persist every node's resume slot across any pass end from
which the run can still advance — `suspended`, `queued` and `running`
alike. A pass that ends `queued` (an in-request advance of one branch while
a sibling has enabled work, a claim refused on contention) MUST NOT cost a
node parked in an earlier pass its stored progress; for a task-waiting node
that progress includes the uuid of the task it is waiting on, and losing it
forces a duplicate task and strands the original's completion.

The engine SHALL drop the slots only when the run reaches a terminal
status: a finished run has nowhere to continue from, and the dispatcher has
already cleared every node that returned.

#### Scenario: An in-request advance of one branch keeps the sibling's slot

- **GIVEN** a run suspended on two parallel user-task nodes, each holding
  its task's uuid in its own resume slot
- **WHEN** one task's completion advances its branch in-request and the pass
  ends `queued` because the sibling branch still has enabled work
- **THEN** the sibling node's resume slot MUST still hold its task's uuid
- **AND** the sibling's next wake MUST re-park on that same task, never
  create a second one

#### Scenario: A terminal run stores no slots

- **GIVEN** a run whose walk ends in a terminal status
- **THEN** no resume slots are persisted on the run

### Requirement: A heartbeat wake re-reads the awaited task and applies a terminal outcome

On every wake of a suspended run — heartbeat or signal — a task-waiting
node SHALL re-read the task named by its own resume slot. When that task is
terminal (completed, terminated, disabled), the node SHALL apply its
outcome exactly as the signal path would have: the same outcome bag under
`json.<outcomeKey>` on every item, the same advance of the run. When the
task is still open, the node SHALL suspend again on its heartbeat without
touching its slot.

Recovery SHALL respect per-node slot addressing: only a node whose OWN task
is terminal advances; a sibling parked on an open task re-suspends with its
slot intact.

The heartbeat is the recovery bound for the missed-signal cases, and no
second delivery mechanism SHALL be added for them: a completion that raced
the suspension (the run was not yet suspended when the signal was
attempted) and a task concluded by a task sequence both leave the task row
terminal, which the re-read observes within one heartbeat period.

#### Scenario: A refused signal is recovered on the next heartbeat

- **GIVEN** a run suspended on a user task whose completion signal was
  refused, so the run never heard about the completion
- **WHEN** the run's heartbeat (`resume_at`) fires
- **THEN** the run MUST advance with the task's outcome on its items,
  attributed to the task's completer
- **AND** no new task is created

#### Scenario: A still-open task re-suspends unchanged

- **GIVEN** a run suspended on a user task that is still open
- **WHEN** the heartbeat fires
- **THEN** the run suspends again on the same task, with the node's slot
  (task uuid, askedAt) unchanged

#### Scenario: Only the addressed node's slot recovers

- **GIVEN** a run suspended on two user-task nodes, of which only one task
  is terminal
- **WHEN** the heartbeat fires
- **THEN** the node whose task ended applies its outcome and advances its
  branch
- **AND** the sibling re-suspends with its own slot intact

### Requirement: A heartbeat-recovered delivery is recorded on the task's audit

When a node applies a terminal task's outcome on a wake that carried no
signal — the completion's wake was refused or lost, and the heartbeat is
what recovered it — the engine SHALL record a `heartbeat-recovered` entry
on the task's audit trail, attributed to the task's `completedBy`. The
guarded signal seam already records the refusal; this entry is the other
half of that trail, so a recovered answer never reads as one that vanished.

Recording SHALL be best-effort: a failure to write the audit entry MUST NOT
fail the recovery itself.

A completion that arrived on its signal is the ordinary path and SHALL NOT
be recorded as a recovery.

#### Scenario: The recovery is audited to the completer

- **GIVEN** a suspended run whose awaited task was completed by a performer
  while the completion signal never reached the run
- **WHEN** the heartbeat applies the outcome
- **THEN** the task's audit trail holds a `heartbeat-recovered` entry naming
  that performer as actor

#### Scenario: A signal-delivered completion records no recovery

- **GIVEN** a suspended run woken by its task's completion signal
- **WHEN** the node applies the outcome
- **THEN** no `heartbeat-recovered` entry is written
