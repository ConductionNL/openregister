## ADDED Requirements

### Requirement: Harvest HTTP calls are time-bounded

Every outbound HTTP request in the harvest/sync pipeline SHALL set a connect
timeout and a read timeout. A slow or unresponsive upstream SHALL fail the fetch
within the timeout and SHALL NOT stall the harvest job indefinitely.

#### Scenario: Dead upstream fails fast

- **WHEN** a harvest source does not respond
- **THEN** the request fails within the configured timeout and the job continues
  or ends cleanly

### Requirement: No per-record refetch when the collection carries bodies

The harvest pipeline SHALL use full item bodies directly when the collection
endpoint already returns them, and SHALL NOT issue a per-id fetch for each such
record.

#### Scenario: Full-body collection avoids N+1

- **WHEN** the collection endpoint returns complete item bodies
- **THEN** no additional per-id request is made for those items

### Requirement: Records are fetched with bounded concurrency

The harvest pipeline SHALL fetch records with bounded concurrency rather than one
strictly serial request at a time, while respecting upstream rate limits.

#### Scenario: Many records fetched concurrently within a window

- **WHEN** a source has many records requiring per-id fetches
- **THEN** requests run with bounded concurrency
- **AND** upstream rate-limit signals (e.g. 429/Retry-After) are honoured
