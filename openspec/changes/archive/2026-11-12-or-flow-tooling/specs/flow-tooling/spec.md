## ADDED Requirements

### Requirement: Run history is listable and inspectable (REQ-FTOOL-001)

`GET /api/flow-runs` SHALL return runs newest first, filterable by `flowId` and
`status`, paged by `limit` (capped) and `offset`. `GET /api/flow-runs/{uuid}`
SHALL return one run in full — its status, log, items, context and error — or
404.

#### Scenario: Listing returns runs newest first

- **GIVEN** stored runs
- **WHEN** the list is requested
- **THEN** it returns them with the paging window

#### Scenario: An unknown run is 404

- **GIVEN** a uuid no run has
- **WHEN** it is requested
- **THEN** the response is 404

### Requirement: A finished run can be retried (REQ-FTOOL-002)

`POST /api/flow-runs/{uuid}/retry` SHALL queue a NEW run against the same flow,
subject and trigger, and return it (201). The original SHALL be left exactly as
it ended. Retry SHALL NOT re-execute the original — that would repeat its side
effects.

Only a terminal run SHALL be retriable; retrying a queued, running or suspended
run SHALL be refused (409).

#### Scenario: Retry queues a fresh run

- **GIVEN** a failed run
- **WHEN** it is retried
- **THEN** a new queued run against the same flow is returned
- **AND** the original is still failed

#### Scenario: A non-terminal run cannot be retried

- **GIVEN** a running run
- **WHEN** retry is requested
- **THEN** the response is 409

@e2e exclude the run-history API is backend-only — covered by PHPUnit and a
live list/show/retry check; the history UI is a separate change
