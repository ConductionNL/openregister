# Object Write Performance

## MODIFIED Requirements

### Requirement: Single-object writes complete within a 500 ms budget

A single-object create, update or delete through the object API MUST cost less
than **50 ms above the instance floor** — the cost of an authenticated request
to the same instance that performs no object work — and MUST NOT degrade as the
number of registers, schemas or magic tables on the instance grows.

This replaces the previous absolute 500 ms wall-clock budget. That budget was
met (322 ms median / 476 ms p95, 2026-07-30) and is no longer the binding
constraint: the floor on the development instance is 172–213 ms, so an absolute
budget mostly measures how many apps the instance has installed. A
floor-relative budget measures this code.

Both figures MUST still be reported. The wall figure is what a caller
experiences; the floor-relative figure is what a change to this code can move.

Measurement MUST authenticate the way a real client does — a session cookie or
an app token — and MUST NOT use HTTP Basic auth carrying the account password.
Nextcloud bcrypt-verifies a Basic-auth password on every request: measured
1,058 ms median against 456 ms for an app token on the same endpoint,
**~600 ms of pure hashing** that no real client pays.

The floor MUST be measured in the same run as the writes it is subtracted from,
never carried over: it moves with instance load.

Three counters bound the implementation, each measured as a delta across one
write:

| Counter | Bound | Observed 2026-07-30 |
|---|---|---|
| Schema resolution reads | ≤ 5 | 57 |
| Committed transactions | 1 | ~135 |
| Statements whose branch count scales with the magic-table count | 0 | 0 ✅ |

Counter deltas MUST NOT be taken from database-global counters alone.
`pg_stat_*` is instance-wide and this instance's cron does 18 schema sequential
scans and 356 commits per 4 idle seconds. Either subtract an idle sample from
the same run, or scope a statement log to the request's backend and time window.

#### Scenario: A create with no relations stays inside the budget

- **GIVEN** a warm instance with 2,728 magic tables and 1,929 schemas
- **AND** the client authenticates with an app token, not an account password
- **WHEN** a client creates one object with no relation-typed properties
- **THEN** wall time minus the instance floor, taken in the same run, MUST be
  under 50 ms at p95 over 20 runs
- **AND** the schema-resolution read count MUST be ≤ 5
- **AND** the committed-transaction delta MUST be exactly 1

#### Scenario: The measurement rejects global counters as evidence

- **GIVEN** a benchmark reporting per-write counter deltas
- **WHEN** the instance is running its normal cron schedule
- **THEN** the reported deltas MUST either exclude an idle baseline taken in the
  same run, or derive from a statement log scoped to the request
- **AND** a delta taken from `pg_stat_*` without either correction MUST NOT be
  reported as the write's cost

## ADDED Requirements

### Requirement: Schema and register resolution is read once per request

Resolving a schema or a register by id, uuid or slug MUST hit a request-scoped
identity map keyed on the primary key, shared by **every** read path that
returns the entity — including bulk lookups, slug-scoped lookups, and the
per-`$ref` loads performed while resolving schema composition.

RBAC and multi-tenancy flags MUST NOT participate in the cache key. They govern
whether a caller may see a result, not which row is loaded; including them
multiplies the miss rate by the number of flag combinations while changing
nothing about the row fetched. Visibility MUST be applied to the value returned
from the map.

A read that does miss the map MUST NOT force a sequential scan. The stored
query resolves `uuid OR LOWER(slug) OR id` in one disjunction over three
columns, which no index can serve, and selects `*` — hydrating a ~2 KB
`properties` blob. Implementations MUST query the one arm the caller holds, and
MUST NOT select columns the caller does not use.

#### Scenario: The same schema is read from the database once per request

- **GIVEN** a write whose validation and composition paths resolve schema S
  several times
- **WHEN** the write runs
- **THEN** exactly one database read for S MUST be issued
- **AND** every later resolution MUST be served from the identity map

#### Scenario: Differing RBAC flags do not cause a second read

- **GIVEN** schema S is resolved once with RBAC enabled
- **WHEN** it is resolved again with RBAC disabled in the same request
- **THEN** no second database read MUST be issued
- **AND** the RBAC decision MUST still be applied to the returned value

#### Scenario: A slug lookup uses an index

- **WHEN** a schema is resolved by slug
- **THEN** the query plan MUST NOT contain a sequential scan of the schemas
  table

### Requirement: Table existence is not probed per write

A write MUST NOT query `information_schema` to establish whether its magic
table exists. That answer changes only when a register/schema pair is created or
dropped, and was observed being asked 9 times per create.

Existence MUST be resolved from a cache invalidated by table creation and
removal.

#### Scenario: A create issues no information_schema query

- **WHEN** an object is created into an existing register/schema pair
- **THEN** no `information_schema` query MUST be issued

#### Scenario: A newly created magic table is visible immediately

- **GIVEN** a register/schema pair whose magic table is created during a request
- **WHEN** an object is written to it in that same request
- **THEN** the write MUST succeed without a stale "table does not exist" result

### Requirement: Per-request work is bounded and independent of catalogue size

No app MAY perform, on a request that renders no UI, work whose cost scales with
the size of an external catalogue or the number of installed apps.

This is the object write's floor, not its own cost, and it is in scope because
the write is measured against that floor. Two concrete forms are banned:

- Iterating the Nextcloud appstore catalogue during `boot()`. pipelinq's
  `buildAppStoreLookup()` walks a **3.4 MB** `apps.json` via `AppFetcher::get()`
  on every request; it is free on the development instance **only because
  `has_internet_connection=false`** returns an empty set.
- Computing `provideInitialState()` on requests that render no UI. Initial state
  exists for page loads; an API request pays for it and discards it.

See ADR-076 for the general rule on where setup work belongs.

#### Scenario: An API request computes no page initial state

- **GIVEN** an app that provides initial state for its pages
- **WHEN** a client calls an object API endpoint
- **THEN** that app MUST NOT compute or provide initial state during the request

#### Scenario: Dependency status is not recomputed per request

- **GIVEN** an app that reports the install status of its declared dependencies
- **WHEN** consecutive requests arrive within the cache lifetime
- **THEN** the status MUST be served from cache
- **AND** the appstore catalogue MUST NOT be iterated
