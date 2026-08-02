## Purpose

How a cron pass decides which queued flow runs to start, and when a run has
waited too long to be worth starting at all.

@e2e exclude queue scheduling is a cron-pass decision with no UI surface — it is covered by PHPUnit at the mapper and worker level, and end-to-end by the live drain evidence on the dev instance recorded in the proposal

## ADDED Requirements

### Requirement: No flow may consume the whole queue while another flow waits

A worker pass SHALL divide its batch between the flows that have queued runs,
rather than claiming runs by arrival order alone.

Each waiting flow SHALL be offered at most `ceil(limit / flowCount)` runs, taken
oldest-first within that flow. A flow with one queued run therefore starts it on
the next pass no matter how many runs any other flow has waiting.

A single global FIFO makes queue position a function of nothing but arrival
order, so one flow that queues in bulk owns the queue until it drains. Measured
2026-08-02: one flow held 9,644 queued runs and anything queued behind them
waited about thirty-two hours to start.

#### Scenario: A single queued run is not starved by a large backlog

- **GIVEN** flow A has 9,000 queued runs and flow B has one
- **WHEN** the worker makes a pass
- **THEN** flow B's run is among the runs claimed by that pass

#### Scenario: The batch is shared, not handed to the oldest flow

- **GIVEN** two flows with more queued runs each than the batch size
- **WHEN** the worker makes a pass
- **THEN** both flows contribute runs to the batch
- **AND** neither flow contributes more than its share

#### Scenario: One waiting flow behaves exactly as before

- **GIVEN** exactly one flow has queued runs
- **WHEN** the worker makes a pass
- **THEN** the pass claims a full batch of that flow's runs, oldest first

#### Scenario: The batch size is never exceeded

- **GIVEN** more waiting flows than the batch size
- **WHEN** the worker makes a pass
- **THEN** no more than `limit` runs are claimed
- **AND** the pass claims at least one run

### Requirement: Flows are served longest-waiting first, and the order rotates

Flows SHALL be ordered by their OLDEST queued run.

Serving a flow advances its oldest queued run, which moves it behind the flows
it just went ahead of, so successive passes rotate between the waiting flows
without any stored cursor.

#### Scenario: The flow that has waited longest is offered its share first

- **GIVEN** flow A's oldest queued run predates flow B's
- **WHEN** the worker makes a pass
- **THEN** flow A's runs are claimed before flow B's

### Requirement: A run that waited past its TTL is abandoned, not executed

A run that has been `queued` for longer than a configured window SHALL be marked
`failed` with an error stating that it expired and can be retried.

A queued run is an intention to act NOW. Executing a schedule tick, a poll or a
reminder a day late does not catch anything up; it replays a decision against a
world that has moved on.

#### Scenario: A stale queued run is failed with a readable reason

- **GIVEN** a run queued three days ago and a TTL of 24 hours
- **WHEN** the worker makes a pass
- **THEN** the run's status is `failed`
- **AND** its error says it expired in the queue and can be retried

#### Scenario: A fresh queued run is not expired

- **GIVEN** a run queued one hour ago and a TTL of 24 hours
- **WHEN** the worker makes a pass
- **THEN** the run is still `queued` or has been executed, but was not expired

### Requirement: Expiry never executes the run it abandons

Expiry SHALL change status only. It SHALL NOT resolve the flow, resolve the
subject, execute any node, or queue a replacement run.

A cron job may record that something did not happen; deciding that it should
happen anyway is what the retry surface is for.

#### Scenario: Expiring a run runs nothing

- **GIVEN** a queued run past its TTL
- **WHEN** the worker expires it
- **THEN** no flow is resolved and no node is executed
- **AND** no new run is queued

### Requirement: Expiry unblocks the scheduler's singleton guard

Expiring a starved queued run SHALL make its flow eligible to be scheduled
again.

`hasActiveRun()` counts `queued`, and the scheduler uses it to stop a flow
overlapping itself. A starved run therefore makes that guard refuse every later
tick of its own flow, so one stuck run silently stops a whole schedule.

#### Scenario: A schedule resumes once its stuck run expires

- **GIVEN** a scheduled flow whose only queued run is past its TTL
- **WHEN** the worker expires that run
- **THEN** the flow has no active run
- **AND** the scheduler may fire its next tick

### Requirement: The TTL is configurable and can be switched off

The window SHALL be read from `flow_run_queued_ttl_hours` with a default of 24
hours, and a value of `0` or less SHALL disable expiry entirely.

An instance whose cron is deliberately intermittent, and which wants every
queued tick eventually run, SHALL be able to opt out.

#### Scenario: Zero disables expiry

- **GIVEN** a configured TTL of `0`
- **WHEN** the worker makes a pass
- **THEN** no expiry query is issued and no queued run is failed

#### Scenario: The configured window reaches the query

- **GIVEN** a configured TTL of 72 hours
- **WHEN** the worker makes a pass
- **THEN** the expiry query is asked for runs queued before ~72 hours ago

### Requirement: Expiry is capped per pass and happens before the queue is drained

One pass SHALL expire at most a bounded number of runs, and SHALL expire them
before it claims any queued run.

A backlog of tens of thousands must not become one enormous transaction on
whichever cron slot it lands in, and a pass must not claim a run it is about to
expire.

#### Scenario: A very large stale backlog is expired over several passes

- **GIVEN** more stale queued runs than the per-pass cap
- **WHEN** the worker makes a pass
- **THEN** at most the cap are expired
- **AND** the remainder are expired by later passes

#### Scenario: A failing expiry pass still drains the queue

- **GIVEN** the expiry query throws
- **WHEN** the worker makes a pass
- **THEN** the error is logged
- **AND** the pass still claims and advances queued runs
