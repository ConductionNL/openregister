# Object Write Performance

## ADDED Requirements

### Requirement: Single-object writes complete within a 500 ms budget

A single-object create, update or delete through the object API MUST cost less
than **500 ms at p95** on a warm instance, and MUST NOT degrade as the number
of registers, schemas or magic tables on the instance grows.

The budget is measured as **wall time minus the instance floor**: the cost of
an authenticated request to the same instance that performs no object work.
Nextcloud boots every enabled app on every request, so that floor is a
property of how many apps are installed, not of the write path. It was
measured at 864-1,099 ms on the development instance (92 enabled apps) —
larger than this entire budget. A measurement that does not subtract it is
reporting the app count, not the write path, and cannot show whether a change
to the write path helped or hurt.

The floor MUST be measured in the same run as the writes it is subtracted
from, never carried over from an earlier one: it moves with instance load.

The budget is a property of the write path, not of the dataset: an instance
with 2,728 magic tables and 1,917 schemas MUST meet it. Growth in schema or
table count MAY increase memory use; it MUST NOT increase the number of
database round-trips a single write performs.

Three counters bound the implementation, each measured as a delta across one
write:

| Counter | Bound |
|---|---|
| Sequential scans of `oc_openregister_schemas` | ≤ 5 |
| Committed transactions | 1 |
| Statements whose branch count scales with the magic-table count | 0 |

#### Scenario: A create with no relations stays inside the budget

- **GIVEN** a warm instance with 2,728 magic tables and 1,917 schemas
- **WHEN** a client creates one object with no relation-typed properties
- **THEN** the measured cost, wall time minus the instance floor taken in the
  same run, MUST be under 500 ms at p95 over 20 runs
- **AND** the `oc_openregister_schemas` sequential-scan delta MUST be ≤ 5
- **AND** the committed-transaction delta MUST be exactly 1

#### Scenario: A create with typed relations stays inside the budget

- **GIVEN** an object whose schema declares relation properties with a target
  schema (`$ref` or a schema id)
- **WHEN** the object is created with those relations populated
- **THEN** each relation MUST be resolved against its declared schema's magic
  table only
- **AND** no statement issued during the write may contain a `UNION` branch
  per magic table
- **AND** the response MUST be returned within 500 ms at p95

#### Scenario: Adding schemas does not slow an unrelated write

- **GIVEN** a baseline measurement of a create on register R
- **WHEN** 500 additional schemas and their magic tables are created on
  unrelated registers
- **THEN** the same create on register R MUST show no increase in its
  sequential-scan or transaction deltas
- **AND** its p95 MUST remain within 500 ms

### Requirement: Schema resolution is cached per request and across requests

Resolving a schema by id, uuid or slug MUST hit a single request-scoped
identity map keyed on the schema's primary key. The map MUST be shared by
every read path that returns a `Schema` — including bulk and slug-scoped
lookups, not only the single-identifier one.

RBAC and multi-tenancy flags MUST NOT participate in the cache key. They
govern whether a caller may see a result, not which row is loaded; including
them in the key multiplies the miss rate by the number of flag combinations
while changing nothing about the row that is fetched. Visibility MUST be
applied to the value returned from the map.

A cross-request cache MUST be keyed on the schema's id together with its
`updated` timestamp, so a schema mutation invalidates it without an explicit
purge, and MUST be dropped by the same hook that clears the request-scoped map
on mutation.

#### Scenario: The same schema is read from the database once per request

- **GIVEN** a write whose validation path resolves schema S many times
- **WHEN** the write runs
- **THEN** exactly one database read for S MUST be issued
- **AND** every subsequent resolution MUST be served from the identity map

#### Scenario: Differing RBAC flags do not cause a second read

- **GIVEN** schema S is resolved once with RBAC enabled
- **WHEN** it is resolved again with RBAC disabled within the same request
- **THEN** no second database read MUST be issued
- **AND** the RBAC decision MUST still be applied to the returned value

#### Scenario: A mutated schema is not served stale

- **GIVEN** schema S is cached in both the request map and the cross-request
  cache
- **WHEN** S is updated
- **THEN** the next resolution of S MUST return the updated definition
- **AND** MUST NOT be served from either cache layer

### Requirement: Object references resolve without scanning every magic table

Resolving an object reference by `_id`, `_uuid`, `_slug` or `_uri` MUST NOT
emit a statement containing one branch per magic table.

When the reference's target schema is known — declared on the property as a
`$ref` or schema id — resolution MUST query that schema's magic table only.

When the target is genuinely unknown, resolution MUST go through a lookup
index mapping identifier to `(register_id, schema_id)`, maintained on write,
so the cost is one index probe regardless of how many magic tables exist.

The rationale is not query efficiency but statement size: the 2,728-branch
form measured 690 KB of SQL at 3,404 ms planning against 546 ms execution.
Because 86 % of the cost is parsing and planning a statement that returns no
rows, no index on the underlying tables can reduce it.

#### Scenario: A typed relation queries one table

- **GIVEN** a property declaring a target schema
- **WHEN** its value is resolved during a write
- **THEN** exactly one magic table MUST be queried

#### Scenario: An untyped reference resolves through the index

- **GIVEN** a reference whose target schema cannot be determined statically
- **WHEN** it is resolved
- **THEN** resolution MUST use the identifier index
- **AND** MUST issue no statement whose branch count depends on the number of
  magic tables

#### Scenario: The index stays consistent with the objects it points at

- **GIVEN** an object is created, then renamed, then deleted
- **WHEN** each write commits
- **THEN** the index entry MUST be created, updated and removed in the same
  transaction as the object itself
- **AND** a rolled-back write MUST leave no index entry behind

### Requirement: Post-write side effects run after the commit, outside the request

CloudEvent fan-out, audit-trail hash sealing, notification history and
activity-stream writes MUST NOT run inside the request that performs the
write. They MUST be dispatched to a background job after the write's
transaction commits.

Dispatch MUST happen after commit, never from inside the transaction: a
rolled-back write MUST NOT leave a queued job for a write that did not happen.
Failure of the background job MUST NOT fail the write, MUST be logged, and
MUST be retryable. Per-object ordering MUST be preserved.

The deferred dispatch MUST carry the acting user and restore it before
dispatching. A background job has no session, and OpenRegister reads are
organisation-filtered against the session user, so listeners that consult the
register are told the instance is empty and skip. This failure is completely
silent — no exception, no log, every listener simply does nothing — and was
observed in practice: a deferred dispatch without the acting user produced
zero CloudEvents where the inline path produced one, while reporting success.
The impersonation MUST be released after dispatch so it cannot leak into the
next job run by the same worker process, and a job that cannot resolve its
acting user MUST log at a level the default configuration does not filter.

A `2xx` response therefore means the object is persisted — it does not mean
its side effects have been applied. This is a caller-visible contract change
and MUST be documented as such, together with a supported way for a caller
that needs the stronger guarantee to wait for it.

#### Scenario: A create returns before its CloudEvent is fanned out

- **GIVEN** an instance with at least one active event subscription
- **WHEN** a client creates an object
- **THEN** the response MUST be returned without waiting for the fan-out
- **AND** the CloudEvent MUST subsequently be produced by the background job

#### Scenario: A rolled-back write dispatches nothing

- **GIVEN** a create that fails after partial work and rolls back
- **WHEN** the transaction rolls back
- **THEN** no background job MUST be dispatched
- **AND** no CloudEvent, audit row or activity entry MUST exist for the write

#### Scenario: A deferred dispatch produces the same events as an inline one

- **GIVEN** an instance with at least one active event subscription and
  deferral enabled
- **WHEN** a client creates an object and the queued job subsequently runs
- **THEN** the events produced MUST be identical in number and content to
  those an inline dispatch produces for the same write
- **AND** a job whose acting user cannot be restored MUST NOT be treated as a
  successful dispatch

#### Scenario: A failing side-effect job does not fail the write

- **GIVEN** a committed write whose fan-out job raises
- **WHEN** the job runs and fails
- **THEN** the written object MUST remain persisted and readable
- **AND** the failure MUST be logged and the job retryable

### Requirement: The write budget is enforced by a gate

The performance budget MUST be enforced by an automated check that runs on
every change to the object write path, and MUST fail the build when p95
exceeds 500 ms.

The check MUST report the three bounding counters alongside wall time, so a
regression is attributable rather than merely visible. It MUST report min,
median and p95 over multiple runs: the pre-change baseline varied from 13.6 s
to 99.1 s on identical payloads, so a single timing is not evidence.

#### Scenario: A regression in schema-read count fails the build

- **GIVEN** the budget gate is green on the base branch
- **WHEN** a change raises the per-write schema sequential-scan delta above 5
- **THEN** the gate MUST fail
- **AND** MUST report the observed delta against its bound

#### Scenario: The gate reports a distribution, not one sample

- **WHEN** the gate runs
- **THEN** it MUST report min, median and p95 wall time over its run count
- **AND** MUST fail on p95, not on the best observed run
