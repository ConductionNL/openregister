## ADDED Requirements

### Requirement: Transfer execution runs as a dispatched background job
OpenRegister SHALL execute approved e-Depot transfers through the queued-job path: initiating a
transfer (`transfer#create`) MUST load the referenced transfer list, verify it is in `approved`
status, dispatch `TransferExecutionJob`, and record the dispatch — never execute the transport
synchronously in the request and never return a success shape without having enqueued the job.

#### Scenario: Initiation dispatches the job
- **WHEN** an archivist initiates a transfer for an approved list
- **THEN** `TransferExecutionJob` is enqueued for that list and the response reflects the real queued state

#### Scenario: Non-approved lists are refused
- **WHEN** initiation is requested for a list that is not in `approved` status
- **THEN** the request is rejected with a client error and no job is enqueued

### Requirement: Failed transfers retry durably with long-horizon backoff
OpenRegister SHALL replace the in-flow `sleep()` retry chain (30 s/120 s/480 s inside one process
run) with durable, cross-request retries: each transport attempt MUST be recorded append-only
(attempt number, timestamp, transport, outcome, error), and a failed attempt MUST schedule the
next attempt via the background-job system with exponential backoff from one minute up to a
capped eight-hour interval — the executing worker is never blocked waiting for a backoff window.
After a configurable number of exhausted attempts the transfer MUST stop retrying, enter a failed
state, and escalate to the archivists through the existing archivist-notification path. A
retry MUST NOT rebuild or resend packages for objects already confirmed transferred in an earlier
partial success.

#### Scenario: Transport failure reschedules instead of blocking
- **WHEN** a transport attempt fails
- **THEN** the attempt is recorded append-only and a new execution is scheduled after the backoff interval
- **AND** no worker process sleeps through the backoff window

#### Scenario: Backoff grows to the cap
- **WHEN** consecutive attempts keep failing
- **THEN** the interval between attempts grows exponentially from ~1 minute and never exceeds ~8 hours

#### Scenario: Exhaustion escalates to archivists
- **WHEN** the configured attempt limit is exhausted
- **THEN** the transfer enters the failed state, archivists are notified via the existing notification path, and no further automatic attempts occur

#### Scenario: Partial success is not re-sent
- **WHEN** a multi-package transfer succeeded for some objects and failed for others
- **THEN** the retry covers only the unconfirmed objects; confirmed objects keep their transferred status and are not re-ingested

@e2e An archivist approves a transfer list against an unreachable e-Depot endpoint, sees the transfer's attempt history grow with scheduled future retries instead of an immediate hard failure, restores the endpoint before exhaustion and sees the next attempt complete — then repeats with the endpoint left down and receives the archivist escalation notification once attempts exhaust.
