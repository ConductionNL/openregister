## ADDED Requirements

### Requirement: A run outlives the request that started it (REQ-FR-001)

A flow run SHALL be persisted with its Petri-net marking, its item list, its
run-level context and its append-only log, so execution can stop and continue
across requests.

The marking SHALL be stored on the RUN, not on the subject: two runs over one
object hold two independent markings.

#### Scenario: The marking round-trips through the run

- **GIVEN** a marking of two places
- **WHEN** it is written and read back through the run's marking store
- **THEN** both places are returned

### Requirement: A step can suspend a run (REQ-FR-002)

A step SHALL be able to pause the run by throwing `FlowSuspension`, optionally
naming when it becomes eligible to resume. The engine SHALL report status
`suspended`, which is NOT terminal.

Suspension SHALL be handled before the step's `onError` policy. A pause is not
a failure, and `onError: continue` would otherwise skip past a Wait — the
opposite of waiting.

The marking SHALL NOT advance past a suspended step: the run resumes on that
transition and re-enters the step that asked to wait.

#### Scenario: A suspended run stays where it was

- **GIVEN** a two-node flow whose step suspends
- **WHEN** the run executes
- **THEN** its status is `suspended` and `resumeAt` is set
- **AND** its marking still holds the place before the suspended step

### Requirement: Resuming carries the run's own items (REQ-FR-003)

Resuming SHALL continue from the stored item list, not from a fresh seed of the
subject. Re-seeding would discard everything the earlier steps produced.

The run log SHALL accumulate across a suspension, so a resumed run's history is
the whole run.

`resumeAt` SHALL be cleared once the run is no longer suspended, or the
due-runs query would keep waking a finished run.

#### Scenario: Items survive the pause

- **GIVEN** a suspended run carrying items no re-seed could produce
- **WHEN** it resumes
- **THEN** the step receives exactly those items
- **AND** the run completes

### Requirement: A terminal run is never re-executed (REQ-FR-004)

Executing a run already in a terminal status SHALL be a no-op. Re-running it
would repeat every side effect it performed; retry SHALL create a new run.

An engine failure outside the walk SHALL leave the run `failed` with its error,
never `running` — a run left `running` looks claimed by a worker forever.

#### Scenario: A completed run does not run again

- **GIVEN** a run with status `completed`
- **WHEN** it is executed
- **THEN** no step is dispatched

#### Scenario: A malformed flow fails the run

- **GIVEN** a run against a flow with no nodes
- **WHEN** it is executed
- **THEN** the run's status is `failed` and its error is set

### Requirement: Runs execute off the triggering request (REQ-FR-005)

A trigger SHALL queue a run and return without executing it. A background job
SHALL start queued runs, resume due ones, and prune terminal ones.

Only a run with a `resumeAt` SHALL be resumed on a timer. A run waiting on a
signal — a child run, a webhook — has no `resumeAt` and resuming it on a clock
would run it before what it waits for arrives.

Terminal runs SHALL be pruned after a configurable retention, defaulting to 30
days, with `0` disabling it. Runs are operational data and grow without bound.

#### Scenario: Queueing does not execute

- **GIVEN** a queued run
- **WHEN** the queue call returns
- **THEN** its status is `queued` and no step has been dispatched

@e2e exclude run persistence is backend-only — covered by PHPUnit; the run-history surface is covered by #2070
