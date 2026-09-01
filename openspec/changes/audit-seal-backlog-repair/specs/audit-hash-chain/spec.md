# audit-hash-chain — delta

Requirements added or tightened by the `audit-seal-backlog-repair` change.

## ADDED Requirements

### Requirement: A bounded, resumable seal pass SHALL drain unsealed audit rows in chain order

The app SHALL provide a single seal-backlog driver, exposed both as an `occ`
command and as a scheduled background job, that finds audit-trail rows with a
null or empty `hash` and seals them through `AuditHashService::sealRows()`.

The driver SHALL process rows in **ascending id order**, SHALL cut work into
windows bounded by **both** a maximum unsealed-row count and a maximum id span,
and SHALL commit each window independently so an interrupted pass loses at most
one window. The driver SHALL NOT run two windows concurrently.

#### Scenario: A backlog is drained in ascending id order

- **GIVEN** audit rows with null `hash` exist across a wide id range
- **WHEN** the seal pass runs to completion
- **THEN** every previously unsealed row has a non-null `hash`
- **AND** each sealed row's `previousHash` equals the `hash` of the nearest
  preceding sealed row (or the genesis seed)
- **AND** `verifyChain()` returns `valid: true` over the drained range

#### Scenario: An interrupted pass resumes without gaps or rework

- **GIVEN** a seal pass is interrupted after committing some windows
- **WHEN** the pass is started again
- **THEN** it resumes from the first still-unsealed row
- **AND** rows sealed by the earlier run are not read for hashing again
- **AND** the resulting chain verifies identically to an uninterrupted run

#### Scenario: A window is bounded by span as well as by row count

- **GIVEN** unsealed rows are sparsely interleaved among sealed rows
- **WHEN** the driver cuts a window
- **THEN** the window covers at most the configured maximum id span
- **AND** the number of full rows loaded into memory is bounded by the number of
  unsealed rows in the window, not by the window's id span

#### Scenario: Re-running a fully drained backlog is a no-op

- **GIVEN** no audit rows have a null or empty `hash`
- **WHEN** the seal pass runs
- **THEN** it seals zero rows
- **AND** it issues no `UPDATE` against the audit-trail table

### Requirement: The seal pass SHALL NEVER re-hash an already-sealed row

A row whose `hash` is non-null and non-empty SHALL contribute its stored `hash`
as the next chain link and SHALL NOT be recomputed, rewritten, or included in any
`UPDATE` issued by the seal pass — even when it falls inside a window's id range.

Recomputing a sealed row would overwrite a valid link and produce a false tamper
alarm on the next verification, which is the exact failure the chain exists to
detect.

#### Scenario: Sealed rows interleaved in a window are left untouched

- **GIVEN** a window's id range contains both sealed and unsealed rows
- **WHEN** the seal pass processes that window
- **THEN** the `UPDATE` affects only the unsealed rows
- **AND** every sealed row's `hash` and `previousHash` are byte-identical before
  and after the pass

### Requirement: The scheduled seal job SHALL be hard-bounded per invocation

The background job SHALL cap the work it performs in a single invocation by both
a maximum number of rows and a maximum wall-clock duration, and SHALL exit
cleanly when either cap is reached, leaving the remaining backlog for the next
run.

The job SHALL NOT hold the seal lock across windows, and SHALL abandon the
current window — not the whole pass — when the lock cannot be acquired, so that
foreground writes are never starved.

#### Scenario: A large backlog does not produce a long-running job

- **GIVEN** a backlog far larger than one invocation's cap
- **WHEN** the job runs
- **THEN** it stops at the cap and exits cleanly
- **AND** the next invocation continues from where it stopped

#### Scenario: Lock contention yields instead of blocking writers

- **GIVEN** the seal lock is held by a foreground write
- **WHEN** the job attempts a window
- **THEN** it abandons that window without error
- **AND** the pass continues at the next invocation
- **AND** no foreground insert is blocked waiting on the job

## MODIFIED Requirements

### Requirement: Verification SHALL distinguish legacy unsealed rows from unsealed rows written after the cutover

`verifyChain()` SHALL classify a row with a null or empty `hash` using a
persisted cutover marker recorded when the hash-chain migration ran.

Rows created **before** the marker SHALL continue to be counted as
`skippedNullHashes` without failing the chain. Rows created **after** the marker
SHALL fail verification: `verifyChain()` SHALL return `valid: false` and SHALL
NOT count them as skipped.

The result SHALL additionally report the number of post-cutover unsealed rows so
a backlog is visible to callers rather than hidden behind a passing boolean.

This replaces the current behaviour, in which every null hash is skipped
unconditionally and the chain reports `valid: true` regardless of how many rows
were never sealed.

#### Scenario: A post-cutover unsealed row breaks the chain

- **GIVEN** an audit row created after the cutover marker has a null `hash`
- **WHEN** `verifyChain()` runs
- **THEN** it returns `valid: false`
- **AND** the row is not counted in `skippedNullHashes`
- **AND** the post-cutover unsealed count is non-zero in the result

#### Scenario: Legacy pre-cutover rows still do not break the chain

- **GIVEN** audit rows created before the cutover marker have null hashes
- **AND** every post-cutover row is sealed
- **WHEN** `verifyChain()` runs
- **THEN** it returns `valid: true`
- **AND** the legacy rows are counted in `skippedNullHashes`

#### Scenario: A drained backlog restores a passing chain

- **GIVEN** `verifyChain()` reports `valid: false` because of post-cutover
  unsealed rows
- **WHEN** the seal pass drains the backlog
- **AND** `verifyChain()` is run again
- **THEN** it returns `valid: true`
- **AND** the post-cutover unsealed count is zero
