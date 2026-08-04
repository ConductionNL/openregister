## ADDED Requirements

### Requirement: "Still going" means every non-terminal status

The set of statuses a run will still advance from SHALL be defined on the run
entity as the complement of the terminal set: `queued`, `running`, `suspended`.

A live-runs read SHALL use that whole set. Filtering to `running` alone SHALL
NOT be treated as "what is running": a run holds `running` only for the duration
of a worker pass, so a poll almost always misses it, while `queued` and
`suspended` are where a live run spends its wall-clock.

#### Scenario: A queued and a suspended run are both live

- **GIVEN** one queued run, one suspended run and one completed run
- **WHEN** the live runs are read
- **THEN** the queued and the suspended run are returned
- **AND** the completed run is not

### Requirement: A queued run records the organisation it belongs to

Queuing a run SHALL record the caller's active organisation on the run.

When no organisation can be resolved — a run queued off a request, such as from
cron — the run SHALL be recorded with NO organisation rather than a guessed one.
Resolution SHALL NOT fail the queue: a run that cannot be attributed is still a
run that must start.

The organisation service SHALL be resolved lazily rather than
constructor-injected, so the background worker's per-pass construction of the
run service does not build the organisation/RBAC graph to fill a column it
usually cannot fill.

#### Scenario: A run queued with no session is unattributed, not misattributed

- **GIVEN** no resolvable active organisation
- **WHEN** a run is queued
- **THEN** the run is created
- **AND** its organisation is null

### Requirement: The live-runs read is strictly scoped to one organisation

A live-runs read SHALL return only runs belonging to the given organisation.

A run with no organisation SHALL be returned to nobody. This surface feeds a
widget that every app renders to every user, so attributing an unattributed run
to the reader's tenant would put one tenant's activity on another's dashboard.

A caller whose organisation cannot be resolved SHALL receive an empty result,
and the store SHALL NOT be queried at all for them.

#### Scenario: A caller with no organisation reads nothing

- **GIVEN** a caller whose active organisation does not resolve
- **WHEN** the live runs are requested
- **THEN** the result is empty
- **AND** no run query is issued

#### Scenario: Runs of another organisation are not returned

- **GIVEN** live runs belonging to organisation B
- **WHEN** a caller in organisation A reads the live runs
- **THEN** none of organisation B's runs are returned

### Requirement: The live-runs read returns a bounded list and an honest total

The read SHALL return at most a requested number of rows, capped to a maximum,
alongside the TOTAL count of live runs for that organisation.

The total SHALL be counted independently of the page, so a consumer can state
what it could not fit rather than implying the page is everything.

#### Scenario: The total exceeds the page

- **GIVEN** more live runs than the requested row count
- **WHEN** the live runs are read
- **THEN** the rows are capped to the requested count
- **AND** the reported total is the full count

#### Scenario: An absurd row count is capped, not honoured

- **GIVEN** a request for far more rows than the maximum
- **WHEN** the live runs are read
- **THEN** the effective limit is the maximum

### Requirement: A live run row identifies its flow by name

Each returned row SHALL carry the flow's human name in addition to its id.

When no app claims the flow id — its owning app is disabled, or the flow was
deleted — the row SHALL fall back to the id, so the row still identifies
something rather than rendering blank.

Name resolution SHALL be memoised per flow id, so a list of runs over a handful
of flows costs one resolution per DISTINCT flow rather than one per row.

#### Scenario: An unresolvable flow falls back to its id

- **GIVEN** a live run whose flow id no resolver claims
- **WHEN** the live runs are read
- **THEN** the row's flow name is the flow id

### Requirement: A live run row says where the run currently is

Each returned row SHALL carry the step(s) the run currently sits on, derived
from its marking, or nothing when the run has no marking yet.

A row SHALL NOT carry the run's marking, item list or step log. Those are
kilobytes per run that a list never renders, and the item list can hold the
subject's own record data; the single-run read remains the place to ask for a
run's contents.

#### Scenario: A run with no marking reports no step

- **GIVEN** a queued run that has not started
- **WHEN** the live runs are read
- **THEN** the row's step is null

### Requirement: The run history surface is unchanged

The existing run history read SHALL keep its current filters, shape and
visibility. The live-runs read SHALL be a separate surface.

Widening visibility of run ACTIVITY to every user of every app SHALL NOT be
achieved by widening the history endpoint, so that the tenant boundary can be
strict on the new surface without changing what existing callers see.
